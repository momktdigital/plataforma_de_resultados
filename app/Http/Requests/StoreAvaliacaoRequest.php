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
        ];
    }
}
