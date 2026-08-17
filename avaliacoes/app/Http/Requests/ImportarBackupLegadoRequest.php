<?php

namespace App\Http\Requests;

use App\Support\LimitesUpload;
use Illuminate\Foundation\Http\FormRequest;

class ImportarBackupLegadoRequest extends FormRequest
{
    private const LIMITE_MAXIMO_KB = 102400; // 100 MB — teto da aplicação.

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'arquivo' => ['required', 'file', 'mimes:sql,txt', 'max:'.$this->limiteEmKb()],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'arquivo.required' => 'Selecione o arquivo de backup (.sql).',
            'arquivo.mimes' => 'O arquivo precisa ser um .sql (dump gerado pelo backup do sistema legado).',
            'arquivo.max' => 'O arquivo não pode passar de '.intdiv($this->limiteEmKb(), 1024).' MB neste servidor '
                .'(definido por post_max_size/upload_max_filesize no php.ini).',
        ];
    }

    /**
     * Nunca maior que o que o PHP do próprio servidor aceita — se
     * `post_max_size`/`upload_max_filesize` forem menores que os 100 MB que
     * a aplicação permitiria, é melhor a validação do Laravel barrar com uma
     * mensagem clara do que deixar a requisição estourar antes disso com um
     * 413 genérico.
     */
    private function limiteEmKb(): int
    {
        return min(self::LIMITE_MAXIMO_KB, LimitesUpload::limiteEfetivoEmKb());
    }
}
