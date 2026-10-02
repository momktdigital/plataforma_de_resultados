<?php

namespace App\Http\Requests;

use App\Models\Configuracao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AtualizarPortalCaptchaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ativar um CAPTCHA exige site key E secret key (a secret pode já estar salva — campo em branco
     * mantém a atual). Ativo sem secret deixaria a consulta do aluno sem verificação nenhuma.
     */
    public function rules(): array
    {
        $tipo = $this->input('captcha_type');
        $semSecretSalva = fn (string $chave) => (string) Configuracao::valor($chave, '') === '';

        return [
            'captcha_type' => ['required', 'in:none,recaptcha,hcaptcha'],
            'recaptcha_site_key' => [Rule::requiredIf($tipo === 'recaptcha'), 'nullable', 'string', 'max:255'],
            'recaptcha_secret_key' => [Rule::requiredIf($tipo === 'recaptcha' && $semSecretSalva('recaptcha_secret_key')), 'nullable', 'string', 'max:255'],
            'hcaptcha_site_key' => [Rule::requiredIf($tipo === 'hcaptcha'), 'nullable', 'string', 'max:255'],
            'hcaptcha_secret_key' => [Rule::requiredIf($tipo === 'hcaptcha' && $semSecretSalva('hcaptcha_secret_key')), 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'recaptcha_site_key.required' => 'Informe a site key do reCAPTCHA para ativá-lo.',
            'recaptcha_secret_key.required' => 'Informe a secret key do reCAPTCHA para ativá-lo — sem ela a verificação não protege nada.',
            'hcaptcha_site_key.required' => 'Informe a site key do hCaptcha para ativá-lo.',
            'hcaptcha_secret_key.required' => 'Informe a secret key do hCaptcha para ativá-lo — sem ela a verificação não protege nada.',
        ];
    }
}
