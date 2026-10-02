<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracaoSistema;
use App\Services\Update\GithubReleaseClient;
use App\Services\Update\UpdateService;
use App\Support\AtividadeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Throwable;

class AtualizacaoController extends Controller
{
    private const SESSAO_PENDENTE = 'atualizacao_pendente';

    /** Tentativas com senha errada toleradas ao confirmar uma atualização (por administrador) antes de bloquear por 15 min. */
    private const MAX_SENHAS_ERRADAS = 5;

    public function index(UpdateService $service, GithubReleaseClient $github): View
    {
        $disponivel = $service->verificarAtualizacao();

        // Repositório salvo pelo painel em versões antigas: agora é ignorado (só vale o .env). Avisa se for diferente.
        $repositorioLegado = ConfiguracaoSistema::valor('atualizacao_repositorio');

        return view('admin.sistema.atualizacao', [
            'versaoAtual' => $service->versaoAtual(),
            'disponivel' => $disponivel,
            'assinatura' => $disponivel !== null ? $service->assinaturaDe($disponivel) : null,
            'exigirAssinatura' => (bool) config('sistema.exigir_assinatura'),
            'repositorio' => $github->repositorio(),
            'repositorioLegadoIgnorado' => $repositorioLegado !== null && $repositorioLegado !== '' && $repositorioLegado !== $github->repositorio() ? $repositorioLegado : null,
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
            $pendente = $service->baixarParaConfirmacao();
            session([self::SESSAO_PENDENTE => $pendente]);

            AtividadeLogger::registrar('sistema.atualizacao_baixada', 'Sistema', null, [
                'versao' => $pendente['versao'],
                'sha256' => $pendente['sha256'],
            ]);
        } catch (Throwable $e) {
            Log::error('Falha ao baixar pacote de atualização.', ['exception' => $e]);

            return redirect()->route('sistema.atualizacao.index')
                ->withErrors(['atualizacao' => 'Não foi possível baixar o pacote: '.$e->getMessage()]);
        }

        return redirect()->route('sistema.atualizacao.index');
    }

    /**
     * Só aplica depois que o admin digita de volta a versão mostrada na
     * confirmação E a própria senha. A versão digitada é uma checagem manual
     * explícita (não só um clique); a senha garante que uma sessão deixada
     * aberta — ou sequestrada — não basta para baixar e executar código no
     * servidor.
     */
    public function store(Request $request, UpdateService $service): View|RedirectResponse
    {
        $pendente = session(self::SESSAO_PENDENTE);

        if ($pendente === null) {
            return redirect()->route('sistema.atualizacao.index')
                ->withErrors(['atualizacao' => 'Baixe o pacote e confira a versão antes de aplicar.']);
        }

        $dados = $request->validate([
            'versao_confirmada' => ['required', 'string'],
            'senha_atual' => ['required', 'string'],
        ], [
            'senha_atual.required' => 'Digite a sua senha para confirmar a atualização.',
        ]);

        $admin = Auth::guard('admin')->user();
        $chave = 'atualizacao-senha:'.$admin->id;

        if (RateLimiter::tooManyAttempts($chave, self::MAX_SENHAS_ERRADAS)) {
            $minutos = (int) ceil(RateLimiter::availableIn($chave) / 60);

            return back()->withErrors(['senha_atual' => "Muitas tentativas com senha errada. Tente de novo em {$minutos} minuto(s)."]);
        }

        if (! Hash::check($dados['senha_atual'], (string) $admin->password_hash)) {
            RateLimiter::hit($chave, 900);
            AtividadeLogger::registrar('sistema.atualizacao_senha_recusada', 'Sistema', null, ['versao' => $pendente['versao']]);

            return back()->withErrors(['senha_atual' => 'Senha incorreta.']);
        }

        RateLimiter::clear($chave);

        if (trim($dados['versao_confirmada']) !== $pendente['versao']) {
            return back()->withErrors([
                'versao_confirmada' => 'A versão digitada não confere com a versão baixada — confira e tente de novo.',
            ]);
        }

        session()->forget(self::SESSAO_PENDENTE);

        $resultado = $service->aplicarConfirmado($pendente['zip_path'], $pendente['sha256'], $pendente['versao']);

        AtividadeLogger::registrar(
            $resultado['status'] === 'atualizado' ? 'sistema.atualizacao_aplicada' : 'sistema.atualizacao_falhou',
            'Sistema',
            null,
            ['versao' => $pendente['versao'], 'sha256' => $pendente['sha256'], 'status' => $resultado['status']],
        );

        return view('admin.sistema.atualizacao-resultado', ['resultado' => $resultado]);
    }
}
