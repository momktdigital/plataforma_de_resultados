<?php

namespace App\Console\Commands;

use App\Services\Legado\LegadoImportador;
use App\Services\ResumoResultadoService;
use App\Support\AtividadeLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Migra os dados das tabelas legadas `gabaritos` e `resultados` (aplicação
 * PHP original, mesmo banco) para o schema novo (`avaliacoes`, `questoes`,
 * `respostas`, `resultado_metricas`) — lendo direto das tabelas, via a
 * conexão de banco já configurada.
 *
 * Só LÊ as tabelas legadas — nunca escreve, apaga ou altera nada nelas.
 * Idempotente: pode ser executado quantas vezes for preciso.
 *
 * Sem acesso direto ao banco legado (ex.: outro servidor)? Use a tela
 * "Importar dados legados" no painel, que aceita um arquivo de backup .sql.
 */
class ImportarDadosLegados extends Command
{
    protected $signature = 'legado:importar {--dry-run : Mostra o que seria migrado sem gravar nada}';

    protected $description = 'Migra gabaritos e resultados da aplicação legada para o schema de Avaliações';

    public function handle(LegadoImportador $importador, ResumoResultadoService $resumos): int
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
            DB::table('gabaritos')->orderBy('id')->cursor()->each(
                fn ($linha) => $importador->importarGabarito($linha)
            );
            DB::table('resultados')->orderBy('id')->cursor()->each(
                fn ($linha) => $importador->importarResultado($linha)
            );
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        // Sem isso, avaliações migradas por este comando (o caminho
        // documentado no README para migração no mesmo servidor) ficam com
        // o boletim zerado em todo lugar até alguma ação não relacionada
        // disparar o recálculo — mesmo passo que LegadoController já faz na
        // tela web. Não roda em --dry-run: nada foi de fato gravado.
        if (! $dryRun) {
            foreach ($importador->avaliacoesTocadas() as $avaliacaoCodigo) {
                $resumos->recalcular($avaliacaoCodigo);
            }

            AtividadeLogger::registrar('import.legado_cli', null, null, $importador->resumo(), origemSemAuth: 'CLI: legado:importar');
        }

        $resumo = $importador->resumo();

        $this->table(
            ['Entidade', 'Processadas'],
            [
                ['Avaliacoes (criadas ou já existentes)', $resumo['avaliacoes']],
                ['Questões (gabarito)', $resumo['questoes']],
                ['Respostas', $resumo['respostas']],
                ['Métricas (notas finais)', $resumo['metricas']],
            ]
        );

        if ($dryRun) {
            $this->warn('Modo --dry-run: nada foi gravado. Os números acima são exatamente o que seria migrado.');
        }

        return self::SUCCESS;
    }
}
