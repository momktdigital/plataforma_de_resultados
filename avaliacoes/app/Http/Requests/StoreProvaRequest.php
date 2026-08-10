<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProvaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Nenhum campo é obrigatório para criar uma Prova: o código é gerado
     * automaticamente pelo banco.
     */
    public function rules(): array
    {
        return [
            'nome' => ['nullable', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:100'],
        ];
    }
}
