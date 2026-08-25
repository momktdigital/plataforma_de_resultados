<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            'area' => ['nullable', 'string', 'max:255'],
            'tema' => ['nullable', 'string', 'max:255'],
            'habilidade' => ['nullable', 'string', 'max:255'],
            'bloom_nivel' => ['nullable', 'string', 'max:255'],
            'bloom_verbo' => ['nullable', 'string', 'max:255'],
            'miller_nivel' => ['nullable', 'string', 'max:255'],
            'dificuldade_pedagogica' => ['nullable', Rule::in(['facil', 'medio', 'dificil'])],
            'dificuldade_tri' => ['nullable', 'numeric'],

            // Campos de múltiplos valores (editor exibe como "chips") — ver
            // App\Services\QuestaoReferenciaService.
            'matriz_prova' => ['array'],
            'matriz_prova.*' => ['string', 'max:255'],
            'dcn' => ['array'],
            'dcn.*' => ['string', 'max:255'],
            'portaria_inep' => ['array'],
            'portaria_inep.*' => ['string', 'max:255'],
            'ppc' => ['array'],
            'ppc.*' => ['string', 'max:255'],
            'matriz_periodo' => ['array'],
            'matriz_periodo.*' => ['nullable', 'string', 'max:255'],
            'matriz_disciplina' => ['array'],
            'matriz_disciplina.*' => ['nullable', 'string', 'max:255'],
            'matriz_codigo' => ['array'],
            'matriz_codigo.*' => ['nullable', 'string', 'max:255'],
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
