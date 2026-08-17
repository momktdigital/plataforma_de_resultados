<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarConfiguracoesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'atualizacao_repositorio' => ['required', 'string', 'regex:/^[\w.-]+\/[\w.-]+$/'],
            'backup_manter_ultimos' => ['required', 'integer', 'between:1,50'],
        ];
    }

    public function messages(): array
    {
        return [
            'atualizacao_repositorio.regex' => 'Use o formato "owner/repositorio" (ex.: momktdigital/resultados_di).',
            'backup_manter_ultimos.between' => 'Escolha um número entre 1 e 50.',
        ];
    }
}
