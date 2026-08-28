<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportarBackupLegadoRequest;
use App\Models\Avaliacao;
use App\Services\Legado\BackupSqlParser;
use App\Services\Legado\LegadoImportador;
use App\Services\ResumoResultadoService;
use App\Support\AtividadeLogger;
use App\Support\Concerns\PermiteImportacaoLonga;
use App\Support\LimitesUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class LegadoController extends Controller
{
    use PermiteImportacaoLonga;

    /** Tabelas do schema antigo, substituídas por `avaliacoes`/`questoes`/`respostas`/`resultado_metricas`. */
    private const TABELAS_LEGADAS = ['gabaritos', 'resultados'];

    public function index(): View
    {
        $tabelasExistentes = array_values(array_filter(self::TABELAS_LEGADAS, fn ($t) => Schema::hasTable($t)));

        return view('admin.sistema.legado', [
            'bancoCompartilhadoDisponivel' => Schema::hasTable('gabaritos') && Schema::hasTable('resultados'),
            'limiteUploadMb' => intdiv(LimitesUpload::limiteEfetivoEmKb(), 1024),
            'tabelasLegadasLinhas' => collect($tabelasExistentes)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(),
            'avaliacoesJaMigradas' => Avaliacao::count(),
        ]);
    }

    /**
     * Exclui as tabelas legadas (`gabaritos`/`resultados`) depois que os dados
     * já foram migrados para o schema novo — ação irreversível, por isso exige
     * confirmação explícita por texto e só é permitida se já existir ao menos
     * uma Avaliação migrada (evita apagar o único lugar onde os dados existiam,
     * caso a importação nunca tenha rodado).
     */
    public function excluirTabelas(Request $request): RedirectResponse
    {
        $request->validate(['confirmacao' => ['required', 'in:EXCLUIR']], [
            'confirmacao.in' => 'Digite EXCLUIR (em maiúsculas) para confirmar.',
        ]);

        $tabelasExistentes = array_values(array_filter(self::TABELAS_LEGADAS, fn ($t) => Schema::hasTable($t)));

        if ($tabelasExistentes === []) {
            return redirect()->route('sistema.legado.index')
                ->with('status', 'Nenhuma tabela legada encontrada neste banco — nada a excluir.');
        }

        if (Avaliacao::count() === 0) {
            return back()->withErrors([
                'confirmacao' => 'Nenhuma Avaliacao encontrada no schema novo ainda. Rode a importação (acima) antes de excluir as tabelas legadas — senão os dados seriam perdidos.',
            ]);
        }

        foreach ($tabelasExistentes as $tabela) {
            Schema::dropIfExists($tabela);
        }

        return redirect()->route('sistema.legado.index')
            ->with('status', 'Tabelas legadas excluídas: '.implode(', ', $tabelasExistentes).'.');
    }

    /** Lê `gabaritos`/`resultados` direto da conexão configurada (mesmo banco compartilhado). */
    public function importarDoBanco(Request $request, LegadoImportador $importador, ResumoResultadoService $resumos): RedirectResponse
    {
        $this->permitirExecucaoLonga();

        if (! Schema::hasTable('gabaritos') || ! Schema::hasTable('resultados')) {
            return back()->withErrors([
                'banco' => 'As tabelas legadas `gabaritos`/`resultados` não existem neste banco. Se o sistema antigo está em outro servidor, use a opção de enviar o arquivo de backup.',
            ]);
        }

        $dryRun = $request->boolean('dry_run');

        $this->protegerContraTransacaoOrfa();
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

            return back()->withErrors(['banco' => 'Falha ao importar do banco: '.$e->getMessage()]);
        }

        $dryRun ? DB::rollBack() : DB::commit();

        if (! $dryRun) {
            foreach ($importador->avaliacoesTocadas() as $avaliacaoCodigo) {
                $resumos->recalcular($avaliacaoCodigo);
            }

            AtividadeLogger::registrar('import.legado_banco', null, null, $importador->resumo());
        }

        $mensagem = $this->resumoTexto($importador->resumo());

        return redirect()->route('sistema.legado.index')
            ->with('status', $dryRun ? "[Simulação — nada foi gravado] {$mensagem}" : $mensagem);
    }

    /** Lê um arquivo de backup .sql (gerado pelo backup manual do sistema legado) enviado pelo admin. */
    public function importarDeArquivo(
        ImportarBackupLegadoRequest $request,
        LegadoImportador $importador,
        BackupSqlParser $parser,
        ResumoResultadoService $resumos,
    ): RedirectResponse {
        $this->permitirExecucaoLonga();

        $dryRun = $request->boolean('dry_run');
        $caminho = $request->file('arquivo')->getRealPath();

        $this->protegerContraTransacaoOrfa();
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

        if (! $dryRun) {
            foreach ($importador->avaliacoesTocadas() as $avaliacaoCodigo) {
                $resumos->recalcular($avaliacaoCodigo);
            }

            AtividadeLogger::registrar('import.legado_arquivo', null, null, [
                ...$importador->resumo(),
                'arquivo' => $request->file('arquivo')->getClientOriginalName(),
            ]);
        }

        $mensagem = $this->resumoTexto($importador->resumo());

        return redirect()->route('sistema.legado.index')
            ->with('status', $dryRun ? "[Simulação — nada foi gravado] {$mensagem}" : $mensagem);
    }

    /** @param  array{avaliacoes: int, questoes: int, respostas: int, metricas: int}  $resumo */
    private function resumoTexto(array $resumo): string
    {
        return "Avaliacoes: {$resumo['avaliacoes']} · Questões: {$resumo['questoes']} · Respostas: {$resumo['respostas']} · Métricas: {$resumo['metricas']}";
    }
}
