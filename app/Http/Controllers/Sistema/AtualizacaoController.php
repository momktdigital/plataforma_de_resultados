<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Services\Update\UpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class AtualizacaoController extends Controller
{
    private const SESSAO_PENDENTE = 'atualizacao_pendente';

    public function index(UpdateService $service): View
    {
        return view('admin.sistema.atualizacao', [
            'versaoAtual' => $service->versaoAtual(),
            'disponivel' => $service->verificarAtualizacao(),
            'pendente' => session(self::SESSAO_PENDENTE),
        ]);
    }

    /**
     * Baixa o pacote e calcula o SHA-256 — só isso, nenhum arquivo da
     * aplicação é tocado aqui. O hash fica na tela pro admin conferir contra
     * uma fonte externa (ex.: a própria página da Release no GitHub) antes
     * de confirmar em store().
     */
    public function verificar(UpdateService $service): RedirectResponse
    {
        try {
            session([self::SESSAO_PENDENTE => $service->baixarParaConfirmacao()]);
        } catch (Throwable $e) {
            Log::error('Falha ao baixar pacote de atualização.', ['exception' => $e]);

            return redirect()->route('sistema.atualizacao.index')
                ->withErrors(['atualizacao' => 'Não foi possível baixar o pacote: '.$e->getMessage()]);
        }

        return redirect()->route('sistema.atualizacao.index');
    }

    /**
     * Só aplica depois que o admin digita de volta a versão mostrada na
     * confirmação — uma checagem manual explícita, não só um clique, já que
     * as releases deste repositório não publicam assinatura/checksum pra
     * verificar isso automaticamente (ver UpdateService::aplicarConfirmado()).
     */
    public function store(Request $request, UpdateService $service): View|RedirectResponse
    {
        $pendente = session(self::SESSAO_PENDENTE);

        if ($pendente === null) {
            return redirect()->route('sistema.atualizacao.index')
                ->withErrors(['atualizacao' => 'Baixe o pacote e confira a versão antes de aplicar.']);
        }

        $dados = $request->validate(['versao_confirmada' => ['required', 'string']]);

        if (trim($dados['versao_confirmada']) !== $pendente['versao']) {
            return back()->withErrors([
                'versao_confirmada' => 'A versão digitada não confere com a versão baixada — confira e tente de novo.',
            ]);
        }

        session()->forget(self::SESSAO_PENDENTE);

        $resultado = $service->aplicarConfirmado($pendente['zip_path'], $pendente['sha256'], $pendente['versao']);

        return view('admin.sistema.atualizacao-resultado', ['resultado' => $resultado]);
    }
}
