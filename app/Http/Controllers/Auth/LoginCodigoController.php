<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LoginPorCodigoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Login de coordenador (ou reitor) por código enviado ao e-mail (sem senha). Em nenhuma
 * etapa a resposta revela se o usuário existe, se tem e-mail ou se o envio
 * falhou: mesma mensagem sempre (evita enumeração de contas). Administradores
 * não entram por aqui — para eles a senha é obrigatória (LoginController).
 */
class LoginCodigoController extends Controller
{
    private const SESSAO = 'login_codigo_identificador';

    private const SESSAO_ULTIMO_ENVIO = 'login_codigo_ultimo_envio';

    private const MENSAGEM_ENVIO = 'Se houver um coordenador ou reitor com esse usuário ou e-mail, enviamos um código de acesso para o e-mail cadastrado. Ele vale por 10 minutos.';

    public function solicitar(Request $request, LoginPorCodigoService $servico): RedirectResponse
    {
        $dados = $request->validate(['identificador' => ['required', 'string', 'max:255']]);
        $identificador = trim($dados['identificador']);

        if (! $servico->disponivel()) {
            return redirect()->route('login', ['modo' => 'codigo'])
                ->withErrors(['identificador' => 'O acesso por código ainda não está disponível. Fale com um administrador.']);
        }

        // Além do throttle da rota (por IP): limite por identificador, contra e-mail-bomba na caixa de alguém.
        $chave = 'login-codigo:'.Str::lower($identificador);
        if (RateLimiter::tooManyAttempts($chave, 3)) {
            return redirect()->route('login', ['modo' => 'codigo'])
                ->withErrors(['identificador' => 'Muitos pedidos de código. Aguarde alguns minutos e tente de novo.']);
        }
        RateLimiter::hit($chave, 600);

        $coordenador = $servico->localizar($identificador);
        if ($coordenador !== null) {
            try {
                $servico->emitir($coordenador);
            } catch (TransportExceptionInterface $e) {
                report($e); // falha de envio fica no log, nunca na tela
            }
        }

        $request->session()->put(self::SESSAO, $identificador);
        $request->session()->put(self::SESSAO_ULTIMO_ENVIO, now()->timestamp);

        return redirect()->route('login.codigo.form')->with('status', self::MENSAGEM_ENVIO);
    }

    public function formulario(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has(self::SESSAO)) {
            return redirect()->route('login', ['modo' => 'codigo']);
        }

        return view('auth.login-codigo');
    }

    public function verificar(Request $request, LoginPorCodigoService $servico): RedirectResponse
    {
        $dados = $request->validate(['codigo' => ['required', 'string', 'regex:/^\s*\d{6}\s*$/']], [
            'codigo.regex' => 'Digite o código de 6 dígitos.',
            'codigo.required' => 'Digite o código de 6 dígitos.',
        ]);

        $identificador = $request->session()->get(self::SESSAO);
        if ($identificador === null) {
            return redirect()->route('login', ['modo' => 'codigo']);
        }

        $coordenador = $servico->localizar($identificador);
        $resultado = $coordenador === null ? LoginPorCodigoService::INVALIDO : $servico->verificar($coordenador, $dados['codigo']);

        if ($resultado !== LoginPorCodigoService::OK) {
            // Uma mensagem só para código errado, expirado ou bloqueado (e para usuário inexistente).
            return back()->withErrors(['codigo' => 'Código inválido ou expirado. Confira o código ou peça um novo.']);
        }

        $request->session()->forget([self::SESSAO, self::SESSAO_ULTIMO_ENVIO]);
        Auth::guard('admin')->login($coordenador);
        $request->session()->regenerate();

        return redirect()->intended(route($coordenador->rotaInicial()));
    }

    public function reenviar(Request $request, LoginPorCodigoService $servico): RedirectResponse
    {
        $identificador = $request->session()->get(self::SESSAO);
        if ($identificador === null) {
            return redirect()->route('login', ['modo' => 'codigo']);
        }

        // Espera mínima igual para qualquer usuário (existente ou não): não dá pra
        // descobrir contas pela diferença entre "aguarde" e "enviado".
        $ultimo = (int) $request->session()->get(self::SESSAO_ULTIMO_ENVIO, 0);
        if (now()->timestamp - $ultimo < 60) {
            return back()->withErrors(['codigo' => 'Aguarde um minuto para pedir um novo código.']);
        }

        $coordenador = $servico->disponivel() ? $servico->localizar($identificador) : null;
        if ($coordenador !== null) {
            try {
                $servico->reenviar($coordenador); // a espera crescente do serviço também é silenciosa
            } catch (TransportExceptionInterface $e) {
                report($e);
            }
        }

        $request->session()->put(self::SESSAO_ULTIMO_ENVIO, now()->timestamp);

        return back()->with('status', 'Se houver um coordenador ou reitor com esse usuário ou e-mail, enviamos um novo código. Os reenvios têm espera crescente entre eles.');
    }
}
