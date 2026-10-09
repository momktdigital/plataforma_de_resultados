<?php

namespace App\Http\Requests;

use App\Models\CronogramaItem;
use App\Models\Curso;
use App\Support\NomeCurso;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Criar/editar uma atividade do cronograma: data, rotina, projeto, o que conferir e os cursos a que se aplica. */
class SalvarCronogramaItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Nomes selecionáveis + os já gravados na atividade (um curso que deixou de aparecer na matrícula continua válido).
        $permitidos = [...Curso::nomesDisponiveis(), ...($this->item()?->cursos->pluck('curso')->all() ?? [])];

        return [
            'data' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'rotina' => ['required', Rule::in(array_keys(CronogramaItem::ROTINAS))],
            'projeto' => ['required', 'string', 'max:120'],
            'descricao' => ['required', 'string', 'max:500'],
            'cursos' => ['required', 'array', 'min:1'],
            'cursos.*' => ['string', fn ($atributo, $valor, $falhar) => NomeCurso::estaEm($valor, $permitidos) || $falhar('Curso inválido.')],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $item = $this->item();
            if ($item === null || $validator->errors()->has('cursos')) {
                return;
            }

            // Tirar da atividade um curso que já tem pendência deixaria o registro sem a atividade na tela do
            // coordenador: a pendência precisa ser resolvida ou excluída antes.
            $removidos = $item->pendencias->pluck('curso')->unique()
                ->reject(fn (string $curso) => NomeCurso::estaEm($curso, (array) $this->input('cursos')))->all();

            if ($removidos !== []) {
                $validator->errors()->add('cursos', 'Não dá para tirar da atividade o curso com pendência registrada ('.implode(', ', $removidos).'). Resolva ou exclua a pendência antes, ou mantenha o curso.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'data.required' => 'Informe a data da atividade.',
            'data.date_format' => 'Informe uma data válida.',
            'rotina.required' => 'Escolha a rotina (ROD, ROC ou Auditoria).',
            'rotina.in' => 'Rotina inválida.',
            'projeto.required' => 'Informe o projeto ou a atividade (por exemplo, TIN ou A2 – Módulo A).',
            'projeto.max' => 'O projeto pode ter no máximo 120 caracteres.',
            'descricao.required' => 'Descreva o que será conferido.',
            'descricao.max' => 'A descrição pode ter no máximo 500 caracteres.',
            'cursos.required' => 'Selecione ao menos um curso a que a atividade se aplica.',
            'cursos.min' => 'Selecione ao menos um curso a que a atividade se aplica.',
        ];
    }

    private function item(): ?CronogramaItem
    {
        $item = $this->route('item');

        return $item instanceof CronogramaItem ? $item->loadMissing(['cursos', 'pendencias']) : null;
    }
}
