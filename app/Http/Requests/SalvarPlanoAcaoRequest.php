<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O formulário do plano de ação (as cinco etapas num só envio). Aqui só vale o FORMATO — tamanho, número, data. Se o
 * plano está completo para ser enviado ao colaborador é decidido por PlanoAcaoChecagem (o rascunho pode estar pela
 * metade). Quem pode salvar o quê é do controller.
 */
class SalvarPlanoAcaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** "72,5" é um número válido para quem digita em português. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('meta_proficiencia'))) {
            $this->merge(['meta_proficiencia' => str_replace(',', '.', trim($this->input('meta_proficiencia')))]);
        }
    }

    public function rules(): array
    {
        $texto = ['nullable', 'string', 'max:5000'];
        $data = ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'];

        return [
            'acao' => ['nullable', Rule::in(['salvar', 'enviar'])],
            'etapa_atual' => ['nullable', 'integer', 'between:1,5'],

            'meta_proficiencia' => ['nullable', 'numeric', 'between:0,100'],
            'data_proxima_avaliacao' => $data,

            'recorte' => $texto,
            'resultado' => $texto,
            'fragilidades' => $texto,
            'evidencias' => $texto,

            'causas' => ['nullable', 'array'],
            'causas.*' => ['nullable', 'string', 'max:3000'],
            'causa_priorizada' => $texto,
            'nota_impacto' => ['nullable', 'integer', 'between:1,3'],
            'nota_evidencia' => ['nullable', 'integer', 'between:1,3'],
            'nota_governabilidade' => ['nullable', 'integer', 'between:1,3'],
            'porques' => ['nullable', 'array', 'max:5'],
            'porques.*' => ['nullable', 'string', 'max:1000'],
            'causa_raiz' => $texto,

            'acoes' => ['nullable', 'array', 'max:15'],
            'acoes.*.id' => ['nullable', 'integer'],
            'acoes.*.descricao' => ['nullable', 'string', 'max:2000'],
            'acoes.*.execucao' => $texto,
            'acoes.*.responsavel' => ['nullable', 'string', 'max:150'],
            'acoes.*.prazo' => $data,
            'acoes.*.verificacao' => $texto,
        ];
    }

    public function messages(): array
    {
        return [
            'meta_proficiencia.numeric' => 'A meta de proficiência deve ser um número de 0 a 100.',
            'meta_proficiencia.between' => 'A meta de proficiência deve ficar entre 0% e 100%.',
            'data_proxima_avaliacao.date_format' => 'Informe a data da próxima avaliação no formato dia/mês/ano.',
            'acoes.max' => 'Um plano pode ter no máximo 15 ações.',
            'acoes.*.prazo.date_format' => 'Informe o prazo das ações como uma data válida.',
            '*.max' => 'Um dos textos passou do tamanho máximo permitido.',
            'nota_impacto.between' => 'As notas da priorização vão de 1 a 3.',
            'nota_evidencia.between' => 'As notas da priorização vão de 1 a 3.',
            'nota_governabilidade.between' => 'As notas da priorização vão de 1 a 3.',
        ];
    }
}
