<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuestaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'numero' => ['required', 'integer', 'min:1'],
            // required: a coluna `questoes.gabarito` é NOT NULL (mesma regra
            // já aplicada por QuestaoImportService — uma linha sem gabarito
            // é ignorada no import em vez de gravar uma questão "anulada").
            'gabarito' => ['required', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'numero.required' => 'Informe o número da questão.',
            'numero.integer' => 'O número da questão precisa ser inteiro.',
            'gabarito.required' => 'Informe o gabarito da questão.',
        ];
    }
}
