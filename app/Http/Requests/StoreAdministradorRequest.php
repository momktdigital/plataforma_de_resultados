<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreAdministradorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'unique:admins,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:admins,email'],
            // O sistema legado aceitava min:4 — não seguimos essa política
            // aqui: uma conta de admin tem acesso total aos dados de todos
            // os alunos, então o mínimo é elevado independente do legado.
            'password' => ['required', 'string', Password::min(10)],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Informe o nome de usuário.',
            'username.unique' => 'Já existe um administrador com este nome de usuário.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Já existe um administrador com este e-mail.',
            'password.required' => 'Informe a senha.',
            'password.min' => 'A senha precisa ter ao menos 10 caracteres.',
        ];
    }
}
