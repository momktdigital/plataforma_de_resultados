<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportarBackupLegadoRequest;
use App\Services\Legado\BackupSqlParser;
use App\Services\Legado\LegadoImportador;
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
        ]);
    }

    /** Lê `gabaritos`/`resultados` direto da conexão configurada (mesmo banco compartilhado). */
    public function importarDoBanco(LegadoImportador $importador): RedirectResponse
    {
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
}
