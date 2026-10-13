<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarConfiguracoesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'backup_manter_ultimos' => ['required', 'integer', 'between:1,50'],
            // Painel da reitoria (opcionais: o formulário antigo, sem esses campos, continua válido).
            'reitor_corte_proficiencia' => ['nullable', 'integer', 'between:30,90'],
            'reitor_meta_participacao' => ['nullable', 'numeric', 'between:50,100'],
            // Estudante em risco (RegraDeRisco). `risco_enviado` marca que o formulário trouxe esses campos: uma caixa
            // desmarcada simplesmente não vem na requisição.
            'risco_enviado' => ['nullable', 'boolean'],
            'risco_acerto_ativo' => ['nullable', 'boolean'],
            'risco_acerto' => ['nullable', 'required_if_accepted:risco_acerto_ativo', 'numeric', 'between:1,100'],
            'risco_faltas_ativo' => ['nullable', 'boolean'],
            'risco_faltas' => ['nullable', 'required_if_accepted:risco_faltas_ativo', 'integer', 'between:1,50'],
            'risco_operador' => ['nullable', 'in:ou,e'],
            // Plano de ação: e-mails que recebem o plano publicado (um por linha, ou separados por vírgula ou ponto e vírgula).
            'plano_emails_enviado' => ['nullable', 'boolean'],
            'plano_emails' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // "62,5" também vale.
        if (is_string($this->input('risco_acerto'))) {
            $this->merge(['risco_acerto' => str_replace(',', '.', trim($this->input('risco_acerto')))]);
        }
    }

    public function withValidator($validador): void
    {
        $validador->after(function ($validador) {
            // Plano de ação: cada endereço precisa ser válido (um digitado errado não pode sumir em silêncio) e são no máximo 20.
            if ($this->boolean('plano_emails_enviado')) {
                $partes = preg_split('/[\s,;]+/u', (string) $this->input('plano_emails', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $invalidos = array_values(array_filter($partes, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) === false));
                if ($invalidos !== []) {
                    $validador->errors()->add('plano_emails', 'E-mail inválido: '.implode(', ', array_slice($invalidos, 0, 3)).(count($invalidos) > 3 ? '…' : '').'.');
                } elseif (count(array_unique(array_map('mb_strtolower', $partes))) > 20) {
                    $validador->errors()->add('plano_emails', 'Informe no máximo 20 e-mails.');
                }
            }

            if ($this->boolean('risco_enviado') && ! $this->boolean('risco_acerto_ativo') && ! $this->boolean('risco_faltas_ativo')) {
                $validador->errors()->add('risco_acerto_ativo', 'Marque ao menos um critério para definir o estudante em risco (percentual de acerto e/ou faltas).');
            }
        });
    }

    public function messages(): array
    {
        return [
            'backup_manter_ultimos.between' => 'Escolha um número entre 1 e 50.',
            'reitor_corte_proficiencia.between' => 'O critério de proficiência deve ficar entre 30% e 90%.',
            'reitor_corte_proficiencia.integer' => 'Informe o critério de proficiência como número inteiro (ex.: 60).',
            'reitor_meta_participacao.between' => 'A meta de participação deve ficar entre 50% e 100%.',
            'reitor_meta_participacao.numeric' => 'Informe a meta de participação como número (ex.: 98).',
            'risco_acerto.required_if_accepted' => 'Informe o percentual de acerto abaixo do qual o estudante está em risco (ex.: 60).',
            'risco_acerto.between' => 'O percentual de acerto do risco deve ficar entre 1% e 100%.',
            'risco_acerto.numeric' => 'Informe o percentual de acerto como número (ex.: 60).',
            'risco_faltas.required_if_accepted' => 'Informe a partir de quantas faltas o estudante está em risco (ex.: 2).',
            'risco_faltas.between' => 'O número de faltas deve ficar entre 1 e 50.',
            'risco_faltas.integer' => 'Informe o número de faltas como número inteiro (ex.: 2).',
        ];
    }
}
