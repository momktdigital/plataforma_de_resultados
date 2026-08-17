<?php

namespace App\Services\Legado;

use App\Models\Aluno;
use App\Models\Prova;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use RuntimeException;

/**
 * Regra de transformação dos dados legados (`gabaritos`/`resultados`) para o
 * schema novo (`provas`/`questoes`/`respostas`/`resultado_metricas`) — usada
 * tanto lendo direto do banco compartilhado (`legado:importar`) quanto a
 * partir de um arquivo de backup .sql enviado pelo admin. Um único lugar com
 * essa regra evita as duas fontes divergirem com o tempo.
 *
 * Cada linha processada é idempotente (find-or-create / upsert), então o
 * mesmo dado pode ser reimportado quantas vezes for preciso sem duplicar.
 */
class LegadoImportador
{
    private array $contadores = [
        'provas' => 0,
        'questoes' => 0,
        'respostas' => 0,
        'metricas' => 0,
    ];

    /**
     * @param  object  $linha  Precisa ter: nome_avaliacao, respostas, link_comentado, deleted_at.
     */
    public function importarGabarito(object $linha): void
    {
        $prova = $this->localizarOuCriarProva($linha->nome_avaliacao, $linha->link_comentado ?? null);

        foreach ($this->decodificarJson($linha->respostas ?? null) as $chave => $gabarito) {
            if (! preg_match('/\d+/', (string) $chave, $matches)) {
                continue;
            }

            $numero = (int) $matches[0];

            $questao = Questao::withTrashed()->firstOrNew([
                'prova_codigo' => $prova->codigo,
                'numero' => $numero,
            ]);
            $questao->gabarito = mb_strtoupper((string) $gabarito, 'UTF-8');
            $questao->deleted_at = $linha->deleted_at ?? null;
            $questao->save();

            $this->contadores['questoes']++;
        }
    }

    /**
     * @param  object  $linha  Precisa ter: ra, periodo, nome_avaliacao, respostas, notas_finais, deleted_at.
     */
    public function importarResultado(object $linha): void
    {
        $prova = $this->localizarOuCriarProva($linha->nome_avaliacao, $linha->link_comentado ?? null);
        $ra = trim((string) $linha->ra) ?: null;
        $periodo = (string) ($linha->periodo ?? '');
        $alunoId = $ra !== null ? Aluno::where('ra', $ra)->value('id') : null;

        foreach ($this->decodificarJson($linha->respostas ?? null) as $chave => $valor) {
            if (! preg_match('/\d+/', (string) $chave, $matches)) {
                continue;
            }

            $numero = (int) $matches[0];

            $resposta = Resposta::withTrashed()->firstOrNew([
                'prova_codigo' => $prova->codigo,
                'ra' => $ra,
                'cpf' => null,
                'periodo' => $periodo,
                'questao_numero' => $numero,
            ]);
            $resposta->resposta = $valor !== '' ? mb_strtoupper((string) $valor, 'UTF-8') : null;
            $resposta->aluno_id = $alunoId;
            $resposta->deleted_at = $linha->deleted_at ?? null;
            $resposta->save();

            $this->contadores['respostas']++;
        }

        foreach ($this->decodificarJson($linha->notas_finais ?? null) as $nomeMetrica => $valor) {
            $metrica = ResultadoMetrica::withTrashed()->firstOrNew([
                'prova_codigo' => $prova->codigo,
                'ra' => $ra,
                'cpf' => null,
                'periodo' => $periodo,
                'nome_metrica' => $nomeMetrica,
            ]);
            $metrica->valor = $valor === null ? null : (string) $valor;
            $metrica->aluno_id = $alunoId;
            $metrica->deleted_at = $linha->deleted_at ?? null;
            $metrica->save();

            $this->contadores['metricas']++;
        }
    }

    /** @return array{provas: int, questoes: int, respostas: int, metricas: int} */
    public function resumo(): array
    {
        return $this->contadores;
    }

    private function localizarOuCriarProva(string $nomeAvaliacao, ?string $linkComentado): Prova
    {
        $prova = Prova::firstOrCreate(['nome' => $nomeAvaliacao]);

        if ($prova->wasRecentlyCreated) {
            $this->contadores['provas']++;
        }

        if ($linkComentado && ! $prova->link_comentado) {
            $prova->update(['link_comentado' => $linkComentado]);
        }

        return $prova;
    }

    /** @return array<string, mixed> */
    private function decodificarJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decodificado = json_decode($json, true);

        if (! is_array($decodificado)) {
            throw new RuntimeException("JSON inválido encontrado nos dados legados: {$json}");
        }

        return $decodificado;
    }
}
