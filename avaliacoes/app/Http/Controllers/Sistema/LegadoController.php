<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportarBackupLegadoRequest;
use App\Services\Legado\BackupSqlParser;
use App\Services\Legado\LegadoImportador;
use App\Support\LimitesUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class LegadoController extends Controller
{
    public function index(): View
    {
        return view('admin.sistema.legado', [
            'bancoCompartilhadoDisponivel' => Schema::hasTable('gabaritos') && Schema::hasTable('resultados'),
            'limiteUploadMb' => intdiv(LimitesUpload::limiteEfetivoEmKb(), 1024),
        ]);
    }

    /** Lê `gabaritos`/`resultados` direto da conexão configurada (mesmo banco compartilhado). */
    public function importarDoBanco(LegadoImportador $importador): RedirectResponse
    {
        $this->permitirExecucaoLonga();

        if (! Schema::hasTable('gabaritos') || ! Schema::hasTable('resultados')) {
            return back()->withErrors([
                'banco' => 'As tabelas legadas `gabaritos`/`resultados` não existem neste banco. Se o sistema antigo está em outro servidor, use a opção de enviar o arquivo de backup.',
            ]);
        }

        DB::transaction(function () use ($importador) {
            DB::table('gabaritos')->orderBy('id')->cursor()->each(
                fn ($linha) => $importador->importarGabarito($linha)
            );
            DB::table('resultados')->orderBy('id')->cursor()->each(
                fn ($linha) => $importador->importarResultado($linha)
            );
        });

        return redirect()->route('sistema.legado.index')
            ->with('status', $this->resumoTexto($importador->resumo()));
    }

    /** Lê um arquivo de backup .sql (gerado pelo backup manual do sistema legado) enviado pelo admin. */
    public function importarDeArquivo(
        ImportarBackupLegadoRequest $request,
        LegadoImportador $importador,
        BackupSqlParser $parser,
    ): RedirectResponse {
        $this->permitirExecucaoLonga();

        $dryRun = $request->boolean('dry_run');
        $caminho = $request->file('arquivo')->getRealPath();

        DB::beginTransaction();

        try {
            foreach ($parser->linhas($caminho) as $registro) {
                match ($registro['tabela']) {
                    'gabaritos' => $importador->importarGabarito($registro['linha']),
                    'resultados' => $importador->importarResultado($registro['linha']),
                };
            }
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->withErrors(['arquivo' => 'Falha ao importar o backup: '.$e->getMessage()]);
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $mensagem = $this->resumoTexto($importador->resumo());

        return redirect()->route('sistema.legado.index')
            ->with('status', $dryRun ? "[Simulação — nada foi gravado] {$mensagem}" : $mensagem);
    }

    /** @param  array{provas: int, questoes: int, respostas: int, metricas: int}  $resumo */
    private function resumoTexto(array $resumo): string
    {
        return "Provas: {$resumo['provas']} · Questões: {$resumo['questoes']} · Respostas: {$resumo['respostas']} · Métricas: {$resumo['metricas']}";
    }

    /**
     * Importar um backup inteiro pode significar milhares de linhas
     * processadas uma a uma — sem isso, o limite padrão de `max_execution_time`
     * do PHP (30s em muitas instalações, ex.: XAMPP) derruba a requisição no
     * meio da importação com um 500 sem nenhuma mensagem útil.
     */
    private function permitirExecucaoLonga(): void
    {
        set_time_limit(0);

        if (LimitesUpload::paraBytes(ini_get('memory_limit') ?: '128M') < LimitesUpload::paraBytes('512M')) {
            ini_set('memory_limit', '512M');
        }
    }
}
