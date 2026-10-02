<?php

namespace App\Http\Requests;

use App\Models\Curso;
use App\Support\NomeCurso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $coordenador = $this->input('papel') === 'coordenador';

        return [
            'papel' => ['nullable', Rule::in(['administrador', 'coordenador'])],
            'username' => ['required', 'string', 'max:50', 'unique:admins,username'],
            // Coordenador entra por código enviado ao e-mail: sem e-mail não há como entrar.
            'email' => [$coordenador ? 'required' : 'nullable', 'email', 'max:255', 'unique:admins,email'],
            // O sistema legado aceitava min:4 — não seguimos essa política
            // aqui: uma conta de admin tem acesso total aos dados de todos
            // os alunos, então o mínimo é elevado independente do legado.
            // A senha só é obrigatória para administrador; coordenador pode não ter (entra pelo código).
            'password' => [$coordenador ? 'nullable' : 'required', 'string', Password::min(10)],
            // Coordenador sem curso não enxerga nada — exige ao menos um.
            'cursos' => [$coordenador ? 'required' : 'nullable', 'array', $coordenador ? 'min:1' : 'max:0'],
            'cursos.*' => ['string', fn ($atributo, $valor, $falhar) => NomeCurso::estaEm($valor, Curso::nomesDisponiveis()) || $falhar('Curso inválido.')],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Informe o nome de usuário.',
            'username.unique' => 'Já existe um usuário com este nome de usuário.',
            'email.required' => 'Informe o e-mail do coordenador — é para ele que enviamos o código de acesso.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Já existe um usuário com este e-mail.',
            'password.required' => 'Informe a senha.',
            'password.min' => 'A senha precisa ter ao menos 10 caracteres.',
            'cursos.required' => 'Selecione ao menos um curso para o coordenador.',
            'cursos.min' => 'Selecione ao menos um curso para o coordenador.',
            'cursos.*.in' => 'Curso inválido.',
        ];
    }
}
