<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarPortalAparenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_title' => ['nullable', 'string', 'max:255'],
            'site_logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:2048'],
            'site_logo_dark' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'site_logo.mimes' => 'A logo (fundo claro) precisa ser jpg, png, gif, webp ou svg.',
            'site_logo_dark.mimes' => 'A logo (fundo escuro) precisa ser jpg, png, gif, webp ou svg.',
        ];
    }
}
