<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportArquivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'arquivo' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'arquivo.required' => 'Selecione um arquivo para importar.',
            'arquivo.mimes' => 'O arquivo precisa ser .csv, .xlsx ou .xls.',
            'arquivo.max' => 'O arquivo não pode passar de 10 MB.',
        ];
    }
}
