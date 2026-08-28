<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UpdateSenhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // O sistema legado (admin/perfil.php) aceitava min:4 — não
            // seguimos essa política aqui, ver StoreAdministradorRequest.
            'new_password' => ['required', 'string', Password::min(10), 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Informe a senha atual.',
            'new_password.required' => 'Informe a nova senha.',
            'new_password.min' => 'A nova senha precisa ter ao menos 10 caracteres.',
            'new_password.confirmed' => 'A confirmação da nova senha não confere.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $atual = $this->input('current_password');

            if ($atual && ! Hash::check($atual, Auth::guard('admin')->user()->password_hash)) {
                $validator->errors()->add('current_password', 'A senha atual informada está incorreta.');
            }
        });
    }
}
