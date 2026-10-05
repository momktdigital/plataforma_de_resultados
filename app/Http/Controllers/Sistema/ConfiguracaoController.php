<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Http\Requests\AtualizarConfiguracoesRequest;
use App\Models\ConfiguracaoSistema;
use App\Services\ReitorDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ConfiguracaoController extends Controller
{
    public function index(): View
    {
        return view('admin.sistema.configuracoes', [
            // Somente leitura: o repositório de onde o servidor baixa e executa código vem do .env.
            'atualizacaoRepositorio' => config('sistema.repositorio'),
            'backupManterUltimos' => ConfiguracaoSistema::valor('backup_manter_ultimos', '5'),
            'reitorCorte' => (int) ReitorDashboardService::corte(),
            'reitorMeta' => ReitorDashboardService::meta(),
        ]);
    }

    public function update(AtualizarConfiguracoesRequest $request): RedirectResponse
    {
        $dados = $request->validated();

        ConfiguracaoSistema::definir('backup_manter_ultimos', (string) $dados['backup_manter_ultimos']);

        // Painel da reitoria: critério de proficiência e meta de participação (as telas leem na hora; os agregados
        // em cache guardam o corte na chave, então trocar o valor não serve número velho).
        if (isset($dados['reitor_corte_proficiencia'])) {
            ConfiguracaoSistema::definir('reitor_corte_proficiencia', (string) $dados['reitor_corte_proficiencia']);
        }
        if (isset($dados['reitor_meta_participacao'])) {
            ConfiguracaoSistema::definir('reitor_meta_participacao', (string) $dados['reitor_meta_participacao']);
        }

        return redirect()->route('sistema.configuracoes.index')->with('status', 'Configurações salvas.');
    }
}
