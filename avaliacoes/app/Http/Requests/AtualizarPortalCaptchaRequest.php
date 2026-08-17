<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarPortalCaptchaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'captcha_type' => ['required', 'in:none,recaptcha,hcaptcha'],
            'recaptcha_site_key' => ['nullable', 'string', 'max:255'],
            'recaptcha_secret_key' => ['nullable', 'string', 'max:255'],
            'hcaptcha_site_key' => ['nullable', 'string', 'max:255'],
            'hcaptcha_secret_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
