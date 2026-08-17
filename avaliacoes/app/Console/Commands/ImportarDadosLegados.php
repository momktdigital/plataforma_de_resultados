<?php

namespace App\Console\Commands;

use App\Models\Aluno;
use App\Models\Prova;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Migra os dados das tabelas legadas `gabaritos` e `resultados` (aplicação
 * PHP original, mesmo banco) para o schema novo (`provas`, `questoes`,
 * `respostas`, `resultado_metricas`).
 *
 * Só LÊ as tabelas legadas — nunca escreve, apaga ou altera nada nelas.
 * Idempotente: pode ser executado quantas vezes for preciso (usa
 * find-or-create + upsert em tudo), então é seguro rodar de novo depois de
 * um novo upload na aplicação antiga, por exemplo.
 */
class ImportarDadosLegados extends Command
{
    protected $signature = 'legado:importar {--dry-run : Mostra o que seria migrado sem gravar nada}';

    protected $description = 'Migra gabaritos e resultados da aplicação legada para o schema de Avaliações';

    private array $contadores = [
        'provas' => 0,
        'questoes' => 0,
        'respostas' => 0,
        'metricas' => 0,
    ];

    public function handle(): int
    {
        foreach (['gabaritos', 'resultados'] as $tabela) {
            if (! Schema::hasTable($tabela)) {
                $this->error("Tabela legada `{$tabela}` não existe neste banco — nada a migrar.");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            $this->importarGabaritos();
            $this->importarResultados();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->table(
            ['Entidade', 'Processadas'],
            [
                ['Provas (criadas ou já existentes)', $this->contadores['provas']],
                ['Questões (gabarito)', $this->contadores['questoes']],
                ['Respostas', $this->contadores['respostas']],
                ['Métricas (notas finais)', $this->contadores['metricas']],
            ]
        );

        if ($dryRun) {
            $this->warn('Modo --dry-run: nada foi gravado. Os números acima são exatamente o que seria migrado.');
        }

        return self::SUCCESS;
    }

    private function importarGabaritos(): void
    {
        DB::table('gabaritos')->orderBy('id')->cursor()->each(function ($linha) {
            $prova = $this->localizarOuCriarProva($linha->nome_avaliacao, $linha->link_comentado);

            foreach ($this->decodificarJson($linha->respostas) as $chave => $gabarito) {
                if (! preg_match('/\d+/', (string) $chave, $matches)) {
                    continue;
                }

                $numero = (int) $matches[0];

                $questao = Questao::withTrashed()->firstOrNew([
                    'prova_codigo' => $prova->codigo,
                    'numero' => $numero,
                ]);
                $questao->gabarito = mb_strtoupper((string) $gabarito, 'UTF-8');
                $questao->deleted_at = $linha->deleted_at;
                $questao->save();

                $this->contadores['questoes']++;
            }
        });
    }

    private function importarResultados(): void
    {
        DB::table('resultados')->orderBy('id')->cursor()->each(function ($linha) {
            $prova = $this->localizarOuCriarProva($linha->nome_avaliacao, $linha->link_comentado);
            $ra = trim((string) $linha->ra) ?: null;
            $periodo = (string) $linha->periodo;
            $alunoId = $ra !== null ? Aluno::where('ra', $ra)->value('id') : null;

            foreach ($this->decodificarJson($linha->respostas) as $chave => $valor) {
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
                $resposta->deleted_at = $linha->deleted_at;
                $resposta->save();

                $this->contadores['respostas']++;
            }

            foreach ($this->decodificarJson($linha->notas_finais) as $nomeMetrica => $valor) {
                $metrica = ResultadoMetrica::withTrashed()->firstOrNew([
                    'prova_codigo' => $prova->codigo,
                    'ra' => $ra,
                    'cpf' => null,
                    'periodo' => $periodo,
                    'nome_metrica' => $nomeMetrica,
                ]);
                $metrica->valor = $valor === null ? null : (string) $valor;
                $metrica->aluno_id = $alunoId;
                $metrica->deleted_at = $linha->deleted_at;
                $metrica->save();

                $this->contadores['metricas']++;
            }
        });
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
