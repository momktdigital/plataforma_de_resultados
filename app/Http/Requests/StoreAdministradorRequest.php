<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            // min:4 mirrors the legacy limit (admin/usuarios.php) — not
            // strengthened here to avoid changing existing account policy
            // as a side effect of this port.
            'password' => ['required', 'string', 'min:4'],
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
            'password.min' => 'A senha precisa ter ao menos 4 caracteres.',
        ];
    }
}
