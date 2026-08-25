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
            'cpf' => $this->filled('cpf') ? preg_replace('/\D/', '', (string) $this->input('cpf')) : null,
            'uf' => $this->filled('uf') ? mb_strtoupper((string) $this->input('uf'), 'UTF-8') : null,
        ]);
    }

    public function rules(): array
    {
        $alunoId = $this->route('aluno')?->id;

        return [
            'ra' => ['required', 'string', 'max:50', Rule::unique('alunos', 'ra')->ignore($alunoId)],
            'cod_perfil' => ['nullable', 'string', 'max:50'],
            'nome' => ['nullable', 'string', 'max:255'],
            'cpf' => ['nullable', 'digits:11', Rule::unique('alunos', 'cpf')->ignore($alunoId)],
            'data_nascimento' => ['nullable', 'date_format:d/m/Y'],
            'sexo' => ['nullable', 'string', 'max:20'],
            'estado_civil' => ['nullable', 'string', 'max:30'],
            'cor_raca' => ['nullable', 'string', 'max:50'],
            'religiao' => ['nullable', 'string', 'max:50'],
            'celular' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'uf' => ['nullable', 'string', 'size:2'],
            'status' => ['nullable', 'string', 'max:50'],
            'curso' => ['nullable', 'string', 'max:255'],
            'matriz' => ['nullable', 'string', 'max:255'],
            'campus' => ['nullable', 'string', 'max:255'],
            'turma' => ['nullable', 'string', 'max:255'],
            'periodo' => ['nullable', 'string', 'max:10'],
            'periodo_letivo' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'ra.required' => 'O RA é obrigatório.',
            'ra.unique' => 'Já existe um aluno cadastrado com este RA.',
            'cpf.digits' => 'CPF inválido — informe os 11 dígitos.',
            'cpf.unique' => 'Já existe um aluno cadastrado com este CPF.',
            'data_nascimento.date_format' => 'Data de nascimento inválida — use o formato DD/MM/AAAA.',
            'email.email' => 'E-mail inválido.',
            'uf.size' => 'UF inválida — use a sigla com 2 letras.',
        ];
    }
}
