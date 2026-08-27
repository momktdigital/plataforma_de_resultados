<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdministradorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $adminId = $this->route('admin')->id;

        return [
            'username' => ['required', 'string', 'max:50', Rule::unique('admins', 'username')->ignore($adminId)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($adminId)],
            // Só reseta a senha se o admin realmente preencher este campo —
            // deixar em branco mantém a senha atual (evita ter que redigitar
            // pra só corrigir o nome de usuário ou o e-mail).
            'password' => ['nullable', 'string', 'min:4'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Informe o nome de usuário.',
            'username.unique' => 'Já existe um administrador com este nome de usuário.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Já existe um administrador com este e-mail.',
            'password.min' => 'A senha precisa ter ao menos 4 caracteres.',
        ];
    }
}
