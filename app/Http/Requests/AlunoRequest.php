<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AlunoRequest extends FormRequest
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
        $alunoId = $this->route('aluno')?->id;

        return [
            'ra' => ['required', 'string', 'max:50', Rule::unique('alunos', 'ra')->ignore($alunoId)],
            'cpf' => ['required', 'digits:11', Rule::unique('alunos', 'cpf')->ignore($alunoId)],
            'data_nascimento' => ['required', 'date_format:d/m/Y'],
            'nome' => ['nullable', 'string', 'max:255'],
            'curso' => ['nullable', 'string', 'max:255'],
            'campus' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'ra.required' => 'O RA é obrigatório.',
            'ra.unique' => 'Já existe um aluno cadastrado com este RA.',
            'cpf.required' => 'O CPF é obrigatório.',
            'cpf.digits' => 'CPF inválido — informe os 11 dígitos.',
            'cpf.unique' => 'Já existe um aluno cadastrado com este CPF.',
            'data_nascimento.required' => 'A data de nascimento é obrigatória.',
            'data_nascimento.date_format' => 'Data de nascimento inválida — use o formato DD/MM/AAAA.',
            'email.email' => 'E-mail inválido.',
        ];
    }
}
