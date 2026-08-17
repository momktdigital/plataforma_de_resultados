<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportarBackupLegadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'arquivo' => ['required', 'file', 'mimes:sql,txt', 'max:102400'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'arquivo.required' => 'Selecione o arquivo de backup (.sql).',
            'arquivo.mimes' => 'O arquivo precisa ser um .sql (dump gerado pelo backup do sistema legado).',
            'arquivo.max' => 'O arquivo não pode passar de 100 MB.',
        ];
    }
}
