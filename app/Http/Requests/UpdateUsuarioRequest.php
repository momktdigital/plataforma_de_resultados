<?php

namespace App\Http\Requests;

use App\Models\Curso;
use App\Support\NomeCurso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $admin = $this->route('admin');
        $coordenador = $admin->ehCoordenador();

        return [
            'username' => ['required', 'string', 'max:50', Rule::unique('admins', 'username')->ignore($admin->id)],
            // Coordenador entra por código enviado ao e-mail: ele precisa ter um.
            'email' => [$coordenador ? 'required' : 'nullable', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($admin->id)],
            // Só reseta a senha se o admin realmente preencher este campo —
            // deixar em branco mantém a senha atual (evita ter que redigitar
            // pra só corrigir o nome de usuário ou o e-mail).
            'password' => ['nullable', 'string', Password::min(10)],
            // O papel não muda depois de criado; só coordenador tem cursos.
            'cursos' => [$coordenador ? 'required' : 'prohibited', 'array', 'min:1'],
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
            'password.min' => 'A senha precisa ter ao menos 10 caracteres.',
            'cursos.required' => 'Selecione ao menos um curso para o coordenador.',
            'cursos.min' => 'Selecione ao menos um curso para o coordenador.',
            'cursos.*.in' => 'Curso inválido.',
        ];
    }
}
