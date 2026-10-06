<?php

namespace App\Services;

use App\Models\Acompanhamento;
use App\Models\Admin;
use App\Models\Aluno;
use App\Support\AtividadeLogger;
use App\Support\NomeCurso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Acompanhamento de alunos em risco: o coordenador registra "contatado", "em acompanhamento" ou "resolvido", com
 * observação e data. Cada registro é um evento (nunca se edita); o estado atual do aluno é o último.
 *
 * Um registro pertence ao CURSO em que foi feito e só é visível a quem coordena esse curso: a observação é dado
 * sensível, e um aluno que mudou de curso não leva as anotações do coordenador anterior. Comparação de curso sempre por
 * NomeCurso (acento/caixa não distinguem). A observação nunca vai para a trilha de auditoria — só o fato do registro.
 */
class AcompanhamentoService
{
    public const MAXIMO_OBSERVACAO = 1000;

    /**
     * Grava um registro. Quem chama já garantiu que o aluno é do curso do coordenador.
     */
    public function registrar(Aluno $aluno, Admin $coordenador, string $curso, string $status, ?string $observacao): Acompanhamento
    {
        $observacao = $observacao === null ? null : trim($observacao);

        $registro = Acompanhamento::create([
            'aluno_id' => $aluno->id,
            'admin_id' => $coordenador->id,
            'curso' => $curso,
            'status' => $status,
            'observacao' => $observacao === '' ? null : $observacao,
        ]);

        AtividadeLogger::registrar('acompanhamento.registrado', 'Aluno', $aluno->id, ['status' => $status, 'curso' => $curso]);

        return $registro;
    }

    /**
     * O registro mais recente de cada aluno, só dos cursos informados (todas as grafias).
     *
     * @param  array<int, int>  $alunoIds
     * @param  array<int, string>  $variantesDoCurso
     * @return array<int, array{status: string, rotulo: string, em: string}> aluno_id => dados
     */
    public function ultimos(array $alunoIds, array $variantesDoCurso): array
    {
        if ($alunoIds === [] || $variantesDoCurso === []) {
            return [];
        }

        $resultado = [];
        foreach (array_chunk($alunoIds, 500) as $lote) {
            $ultimos = DB::table('acompanhamentos')
                ->whereIn('aluno_id', $lote)
                ->whereIn('curso', $variantesDoCurso)
                ->groupBy('aluno_id')
                ->selectRaw('aluno_id, MAX(id) as ultimo')
                ->pluck('ultimo', 'aluno_id');

            if ($ultimos->isEmpty()) {
                continue;
            }

            foreach (DB::table('acompanhamentos')->whereIn('id', $ultimos->values()->all())->get(['aluno_id', 'status', 'created_at']) as $linha) {
                $resultado[(int) $linha->aluno_id] = [
                    'status' => $linha->status,
                    'rotulo' => Acompanhamento::STATUS[$linha->status] ?? $linha->status,
                    'em' => (string) $linha->created_at,
                ];
            }
        }

        return $resultado;
    }

    /**
     * Histórico do aluno nos cursos do coordenador, do mais novo ao mais antigo.
     *
     * @param  array<int, string>  $variantesDoCurso
     * @return Collection<int, Acompanhamento>
     */
    public function historico(Aluno $aluno, array $variantesDoCurso): Collection
    {
        if ($variantesDoCurso === []) {
            return collect();
        }

        return Acompanhamento::with('admin:id,username')
            ->where('aluno_id', $aluno->id)
            ->whereIn('curso', $variantesDoCurso)
            ->orderByDesc('id')
            ->get();
    }

    /** O curso (dos do coordenador) a que o registro de um aluno pertence: o do cadastro dele, se for um dos do coordenador. */
    public function cursoDoRegistro(Aluno $aluno, array $cursosDoCoordenador): string
    {
        foreach ($cursosDoCoordenador as $curso) {
            if (NomeCurso::mesmo($curso, $aluno->curso)) {
                return $curso;
            }
        }

        return $cursosDoCoordenador[0] ?? (string) $aluno->curso;
    }
}
