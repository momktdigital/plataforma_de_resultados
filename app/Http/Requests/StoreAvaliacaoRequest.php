<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvaliacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Nenhum campo é obrigatório para criar uma Avaliação: o código é gerado
     * automaticamente pelo banco.
     */
    public function rules(): array
    {
        return [
            'nome' => ['nullable', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:100'],
            'link_comentado' => ['nullable', 'url', 'max:255'],
            // Alternativa a colar um link: enviar o arquivo do gabarito
            // comentado direto (ver AvaliacaoController::comDataConvertida()).
            'gabarito_comentado_arquivo' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'data_avaliacao' => ['nullable', 'date_format:d/m/Y'],
            'status' => ['nullable', 'in:ativa,anulada'],
            // % de acerto esperado por nível de dificuldade pedagógica.
            'meta_acerto' => ['nullable', 'array'],
            'meta_acerto.*' => ['nullable', 'numeric', 'between:0,100'],
            // Acesso aos resultados: cursos da avaliação e coordenadores com
            // acesso excepcional (AvaliacaoController::atualizarAcesso()).
            'acesso_enviado' => ['nullable', 'boolean'],
            'cursos' => ['nullable', 'array'],
            'cursos.*' => ['string', 'max:200'],
            'usuarios_acesso' => ['nullable', 'array'],
            'usuarios_acesso.*' => ['integer'],
            // Estudante em risco só nesta avaliação (App\Support\RegraDeRisco): acerto vazio = padrão da instituição,
            // 0 = esta prova não entra no critério de acerto; `risco_ignora_falta` = faltar a ela não conta como falta.
            'risco_enviado' => ['nullable', 'boolean'],
            'risco_acerto' => ['nullable', 'numeric', 'between:0,100'],
            'risco_ignora_falta' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // "62,5" também vale.
        if (is_string($this->input('risco_acerto'))) {
            $this->merge(['risco_acerto' => str_replace(',', '.', trim($this->input('risco_acerto')))]);
        }
    }

    public function messages(): array
    {
        return [
            'risco_acerto.numeric' => 'Informe o percentual de acerto do risco como número (ex.: 50), ou deixe em branco para usar o padrão.',
            'risco_acerto.between' => 'O percentual de acerto do risco deve ficar entre 0 e 100.',
        ];
    }
}
