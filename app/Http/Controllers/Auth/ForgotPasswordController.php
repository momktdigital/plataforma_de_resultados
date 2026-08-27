<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public function create(PasswordResetService $service): View
    {
        return view('auth.esqueci-senha', ['smtpDisponivel' => $service->disponivel()]);
    }

    public function store(Request $request, PasswordResetService $service): RedirectResponse
    {
        $dados = $request->validate(['username' => ['required', 'string', 'max:50']]);

        $service->solicitar($dados['username']);

        // Mensagem genérica sempre igual — nunca revela se o usuário existe
        // ou se tem e-mail cadastrado (evita enumeração de contas).
        return redirect()->route('login')->with(
            'status',
            'Se o usuário existir e tiver e-mail cadastrado, enviamos um link de redefinição de senha.'
        );
    }

    public function edit(string $token, PasswordResetService $service): View|RedirectResponse
    {
        if ($service->validar($token) === null) {
            return redirect()->route('senha.esqueci')
                ->withErrors(['token' => 'Link inválido ou expirado — solicite um novo.']);
        }

        return view('auth.redefinir-senha', ['token' => $token]);
    }

    public function update(Request $request, string $token, PasswordResetService $service): RedirectResponse
    {
        $redefinicao = $service->validar($token);

        if ($redefinicao === null) {
            return redirect()->route('senha.esqueci')
                ->withErrors(['token' => 'Link inválido ou expirado — solicite um novo.']);
        }

        $dados = $request->validate(['password' => ['required', 'string', 'min:4', 'confirmed']]);

        $service->redefinir($redefinicao, $dados['password']);

        return redirect()->route('login')->with('status', 'Senha redefinida com sucesso — faça login com a nova senha.');
    }
}
