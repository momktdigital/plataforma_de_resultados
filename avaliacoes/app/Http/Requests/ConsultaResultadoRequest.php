<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsultaResultadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cpf' => preg_replace('/\D/', '', (string) $this->input('cpf')),
        ]);
    }

    public function rules(): array
    {
        return [
            'cpf' => ['required', 'digits:11'],
            'data_nascimento' => ['required', 'date_format:d/m/Y'],
        ];
    }

    public function messages(): array
    {
        return [
            'cpf.required' => 'Informe seu CPF.',
            'cpf.digits' => 'Informe um CPF válido.',
            'data_nascimento.required' => 'Informe sua data de nascimento.',
            'data_nascimento.date_format' => 'Data de nascimento inválida — use o formato DD/MM/AAAA.',
        ];
    }
}
