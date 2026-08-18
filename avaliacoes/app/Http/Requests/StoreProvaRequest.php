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
            'link_comentado' => ['nullable', 'url', 'max:255'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'data_prova' => ['nullable', 'date_format:d/m/Y'],
        ];
    }
}
