<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Jobs\SincronizarAvaliaJob;
use App\Models\AvaliaAvaliacaoDisponivel;
use App\Models\AvaliaSyncExecucao;
use App\Models\ConfiguracaoSistema;
use App\Services\Avalia\AvaliaSyncService;
use App\Services\Avalia\RedshiftAvaliaExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        // Autocorrige qualquer sincronização travada (worker caiu no meio,
        // sem chance de rodar o catch de AvaliaSyncService) — ver
        // AvaliaSyncExecucao::marcarTravadasComoErro().
        AvaliaSyncExecucao::marcarTravadasComoErro();

        $ultimaPorProduto = collect(self::PRODUTOS)->mapWithKeys(fn (string $produto) => [
            $produto => AvaliaSyncExecucao::where('produto', $produto)->orderByDesc('iniciado_em')->first(),
        ]);

        $catalogoPorProduto = collect(self::PRODUTOS)->mapWithKeys(fn (string $produto) => [
            $produto => $this->construirCatalogoComArvore($produto),
        ]);

        $modoPorProduto = collect(self::PRODUTOS)->mapWithKeys(fn (string $produto) => [
            $produto => ConfiguracaoSistema::valor("avalia_modo_{$produto}", 'selecionadas'),
        ]);

        // A seleção real vive na folha pro Avalia Pro (disciplina, pai_id
        // preenchido) e no topo pro Avalia Online (sem disciplinas) — ver
        // AvaliaSyncService::idsPermitidos().
        $selecionadasCountPorProduto = collect(self::PRODUTOS)->mapWithKeys(fn (string $produto) => [
            $produto => AvaliaAvaliacaoDisponivel::where('produto', $produto)
                ->where('selecionada', true)
                ->when($produto === 'avalia_pro', fn ($query) => $query->whereNotNull('pai_id'))
                ->count(),
        ]);

        return view('admin.sistema.integracao-avalia', [
            'produtos' => self::PRODUTOS,
            'ultimaPorProduto' => $ultimaPorProduto,
            'execucoes' => AvaliaSyncExecucao::with('admin')->orderByDesc('iniciado_em')->limit(20)->get(),
            'conexaoConfigurada' => filled(config('database.connections.redshift.host')),
            'tenantSk' => ConfiguracaoSistema::valor('avalia_tenant_sk', ''),
            'environmentSk' => ConfiguracaoSistema::valor('avalia_environment_sk', ''),
            'catalogoPorProduto' => $catalogoPorProduto,
            'modoPorProduto' => $modoPorProduto,
            'selecionadasCountPorProduto' => $selecionadasCountPorProduto,
        ]);
    }

    /**
     * Monta a árvore de seleção pra um produto: cada prova (pai_id null)
     * ganha uma propriedade dinâmica `disciplinasPorCurso` — suas
     * disciplinas (pai_id apontando pra ela), agrupadas por curso pra
     * exibição em admin.sistema.integracao-avalia. Pro Avalia Online
     * (sem disciplinas) o grupo sempre vem vazio, e a tela renderiza a
     * prova como item de lista simples em vez de árvore.
     */
    private function construirCatalogoComArvore(string $produto): Collection
    {
        $todas = AvaliaAvaliacaoDisponivel::where('produto', $produto)->get();
        $disciplinasPorPai = $todas->whereNotNull('pai_id')->groupBy('pai_id');

        return $todas->whereNull('pai_id')
            ->sortByDesc('data_referencia')
            ->values()
            ->each(function (AvaliaAvaliacaoDisponivel $prova) use ($disciplinasPorPai) {
                $prova->disciplinasPorCurso = $disciplinasPorPai->get($prova->id, collect())
                    ->sortBy('nome')
                    ->groupBy(fn (AvaliaAvaliacaoDisponivel $d) => $d->curso ?? 'Sem curso informado');
            });
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

    public function atualizarCatalogo(Request $request, AvaliaSyncService $service): RedirectResponse
    {
        $dados = $request->validate([
            'produto' => ['required', 'string', 'in:avalia_pro,avalia_online'],
        ]);

        try {
            $quantidade = $service->atualizarCatalogo($dados['produto']);
        } catch (Throwable $e) {
            return redirect()->route('sistema.integracao-avalia.index')
                ->withErrors(['catalogo' => 'Não foi possível listar as provas do Avalia: '.$e->getMessage()]);
        }

        return redirect()->route('sistema.integracao-avalia.index')
            ->with('status', "{$quantidade} prova(s) encontrada(s) no Avalia.");
    }

    public function atualizarSelecao(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'produto' => ['required', 'string', 'in:avalia_pro,avalia_online'],
            'modo' => ['required', 'string', 'in:todas,selecionadas'],
            'selecionadas' => ['array'],
            'selecionadas.*' => ['integer'],
        ]);

        ConfiguracaoSistema::definir("avalia_modo_{$dados['produto']}", $dados['modo']);

        AvaliaAvaliacaoDisponivel::where('produto', $dados['produto'])->update(['selecionada' => false]);
        AvaliaAvaliacaoDisponivel::where('produto', $dados['produto'])
            ->whereIn('id', $dados['selecionadas'] ?? [])
            ->update(['selecionada' => true]);

        return redirect()->route('sistema.integracao-avalia.index')
            ->with('status', 'Seleção de provas salva com sucesso.');
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
