<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Http\Requests\AtualizarConfiguracoesRequest;
use App\Models\ConfiguracaoSistema;
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
        ]);
    }

    public function update(AtualizarConfiguracoesRequest $request): RedirectResponse
    {
        $dados = $request->validated();

        ConfiguracaoSistema::definir('backup_manter_ultimos', (string) $dados['backup_manter_ultimos']);

        return redirect()->route('sistema.configuracoes.index')->with('status', 'Configurações salvas.');
    }
}
