<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Jobs\SincronizarAvaliaJob;
use App\Models\AvaliaSyncExecucao;
use App\Models\ConfiguracaoSistema;
use App\Services\Avalia\RedshiftAvaliaExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Tela "Integração Avalia" — status da conexão, botão de forçar
 * sincronização por produto e histórico de execuções. Ver
 * App\Services\Avalia\AvaliaSyncService pela sincronização em si.
 */
class IntegracaoAvaliaController extends Controller
{
    private const PRODUTOS = ['avalia_pro', 'avalia_online'];

    public function index(): View
    {
        $ultimaPorProduto = collect(self::PRODUTOS)->mapWithKeys(fn (string $produto) => [
            $produto => AvaliaSyncExecucao::where('produto', $produto)->orderByDesc('iniciado_em')->first(),
        ]);

        return view('admin.sistema.integracao-avalia', [
            'produtos' => self::PRODUTOS,
            'ultimaPorProduto' => $ultimaPorProduto,
            'execucoes' => AvaliaSyncExecucao::with('admin')->orderByDesc('iniciado_em')->limit(20)->get(),
            'conexaoConfigurada' => filled(config('database.connections.redshift.host')),
            'tenantSk' => ConfiguracaoSistema::valor('avalia_tenant_sk', ''),
            'environmentSk' => ConfiguracaoSistema::valor('avalia_environment_sk', ''),
        ]);
    }

    public function atualizarConfiguracoes(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'avalia_tenant_sk' => ['nullable', 'string', 'max:100'],
            'avalia_environment_sk' => ['nullable', 'string', 'max:100'],
        ]);

        ConfiguracaoSistema::definir('avalia_tenant_sk', $dados['avalia_tenant_sk'] ?: null);
        ConfiguracaoSistema::definir('avalia_environment_sk', $dados['avalia_environment_sk'] ?: null);

        return redirect()->route('sistema.integracao-avalia.index')
            ->with('status', 'Configurações da integração salvas com sucesso.');
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'produto' => ['required', 'string', 'in:avalia_pro,avalia_online'],
        ]);

        // Em produção (fila assíncrona) o job roda fora desta requisição —
        // se ele falhar, AvaliaSyncService já registra o erro em
        // avalia_sync_execucoes antes de relançar. Este try/catch cobre o
        // caso de QUEUE_CONNECTION=sync (ex.: testes) ou de uma falha ao
        // simplesmente enfileirar o job — ver BackupController::store().
        try {
            SincronizarAvaliaJob::dispatch($dados['produto'], Auth::guard('admin')->id());
        } catch (Throwable $e) {
            Log::error('Falha ao solicitar sincronização com o Avalia.', ['exception' => $e]);

            return redirect()->route('sistema.integracao-avalia.index')
                ->withErrors(['sincronizacao' => 'Não foi possível iniciar a sincronização: '.$e->getMessage()]);
        }

        return redirect()->route('sistema.integracao-avalia.index')
            ->with('status', 'Sincronização solicitada — está sendo processada em segundo plano.');
    }

    public function testarConexao(): JsonResponse
    {
        try {
            (new RedshiftAvaliaExtractor)->testarConexao();

            return response()->json(['status' => 'success']);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
