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
        $agora = now();
        $deletedAt = $linha->deleted_at ?? null;

        $questoes = [];
        foreach ($this->decodificarJson($linha->respostas ?? null) as $chave => $gabarito) {
            if (! preg_match('/\d+/', (string) $chave, $matches)) {
                continue;
            }

            $questoes[] = [
                'prova_codigo' => $prova->codigo,
                'numero' => (int) $matches[0],
                'gabarito' => mb_strtoupper((string) $gabarito, 'UTF-8'),
                'deleted_at' => $deletedAt,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        if (empty($questoes)) {
            return;
        }

        // upsert (1 query para todas as questões desta prova) em vez de um
        // find+save por questão — com milhares de linhas legadas, isso é a
        // diferença entre segundos e minutos de importação.
        Questao::upsert($questoes, ['prova_codigo', 'numero'], ['gabarito', 'deleted_at', 'updated_at']);
        $this->contadores['questoes'] += count($questoes);
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
        $agora = now();
        $deletedAt = $linha->deleted_at ?? null;

        $respostas = [];
        foreach ($this->decodificarJson($linha->respostas ?? null) as $chave => $valor) {
            if (! preg_match('/\d+/', (string) $chave, $matches)) {
                continue;
            }

            $respostas[] = [
                'prova_codigo' => $prova->codigo,
                'ra' => $ra,
                'cpf' => null,
                'periodo' => $periodo,
                'questao_numero' => (int) $matches[0],
                'resposta' => $valor !== '' ? mb_strtoupper((string) $valor, 'UTF-8') : null,
                'aluno_id' => $alunoId,
                'deleted_at' => $deletedAt,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        if ($respostas !== []) {
            // `aluno_chave` é gerada pelo banco (COALESCE(cpf, ra)) — não entra
            // nos valores, mas o índice único sobre ela é o que o upsert usa
            // pra decidir se cria ou atualiza cada linha.
            Resposta::upsert(
                $respostas,
                ['prova_codigo', 'aluno_chave', 'periodo', 'questao_numero'],
                ['resposta', 'aluno_id', 'deleted_at', 'updated_at'],
            );
            $this->contadores['respostas'] += count($respostas);
        }

        $metricas = [];
        foreach ($this->decodificarJson($linha->notas_finais ?? null) as $nomeMetrica => $valor) {
            $metricas[] = [
                'prova_codigo' => $prova->codigo,
                'ra' => $ra,
                'cpf' => null,
                'periodo' => $periodo,
                'nome_metrica' => $nomeMetrica,
                'valor' => $valor === null ? null : (string) $valor,
                'aluno_id' => $alunoId,
                'deleted_at' => $deletedAt,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        if ($metricas !== []) {
            ResultadoMetrica::upsert(
                $metricas,
                ['prova_codigo', 'aluno_chave', 'periodo', 'nome_metrica'],
                ['valor', 'aluno_id', 'deleted_at', 'updated_at'],
            );
            $this->contadores['metricas'] += count($metricas);
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
