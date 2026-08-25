<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarPortalSmtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'smtp_ativo' => ['nullable', 'boolean'],
            'smtp_from_name' => ['nullable', 'string', 'max:255'],
            'smtp_from_email' => ['nullable', 'email', 'max:255'],
            'smtp_user' => ['nullable', 'string', 'max:255'],
            // Em branco = não altera a senha já salva (mesmo comportamento do admin/configuracoes.php).
            'smtp_pass' => ['nullable', 'string', 'max:255'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'string', 'max:10'],
            'email_template_subject' => ['nullable', 'string', 'max:255'],
            'email_template_body' => ['nullable', 'string'],
        ];
    }
}
