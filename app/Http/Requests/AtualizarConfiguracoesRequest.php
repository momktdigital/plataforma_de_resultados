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
            'backup_manter_ultimos' => ['required', 'integer', 'between:1,50'],
            // Painel da reitoria (opcionais: o formulário antigo, sem esses campos, continua válido).
            'reitor_corte_proficiencia' => ['nullable', 'integer', 'between:30,90'],
            'reitor_meta_participacao' => ['nullable', 'numeric', 'between:50,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'backup_manter_ultimos.between' => 'Escolha um número entre 1 e 50.',
            'reitor_corte_proficiencia.between' => 'O critério de proficiência deve ficar entre 30% e 90%.',
            'reitor_corte_proficiencia.integer' => 'Informe o critério de proficiência como número inteiro (ex.: 60).',
            'reitor_meta_participacao.between' => 'A meta de participação deve ficar entre 50% e 100%.',
            'reitor_meta_participacao.numeric' => 'Informe a meta de participação como número (ex.: 98).',
        ];
    }
}
