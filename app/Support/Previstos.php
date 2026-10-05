<?php

namespace App\Support;

use App\Models\AlunoMatricula;
use Illuminate\Support\Facades\DB;

/**
 * "Previstos" de um curso numa avaliação: quantos estudantes DEVERIAM ter feito a prova — a base da participação
 * (fizeram ÷ previstos) do painel da reitoria.
 *
 * Vêm das matrículas vigentes no período letivo (`aluno_matriculas`: ativa ou período cumprido; quem saiu —
 * transferido, cancelado, trancado — não é "previsto"), contadas só nos períodos do curso (1º, 2º...) que a
 * avaliação de fato atingiu. Alunos ativos num período que NÃO teve aplicação não puxam a participação para baixo:
 * aparecem à parte, como "ativos sem aplicação" (a prova não foi aplicada naquela turma, o aluno não faltou).
 *
 * Nunca fica abaixo de quem tem resultado (resumo) — um aluno que fez a prova sem estar na matrícula importada não
 * pode gerar participação acima de 100% — e, sem nenhuma matrícula importada para o curso, os previstos são os
 * próprios resultados (participação vira "presença").
 */
final class Previstos
{
    /**
     * Matrículas vigentes do período letivo: `chave do curso => ['nome' => grafia, 'ordinais' => [ordinal => alunos]]`
     * (ordinal 0 = período do curso desconhecido). Uma consulta agregada, sem trazer aluno nenhum.
     *
     * @return array<string, array{nome: string, ordinais: array<int, int>}>
     */
    public static function matriculasVigentes(string $periodoLetivo): array
    {
        if ($periodoLetivo === '') {
            return [];
        }

        $linhas = DB::table('aluno_matriculas')
            ->where('periodo_letivo', $periodoLetivo)
            ->whereNotNull('curso')->where('curso', '!=', '')
            ->groupBy('curso', 'periodo', 'status')
            ->selectRaw('curso, periodo, status, COUNT(DISTINCT aluno_id) as alunos')
            ->get();

        $cursos = [];
        $grafias = [];
        foreach ($linhas as $linha) {
            if (! AlunoMatricula::vigenteNoPeriodo($linha->status)) {
                continue;
            }
            $chave = NomeCurso::chave($linha->curso);
            $ordinal = PeriodoCurso::ordinal($linha->periodo) ?? 0;
            $grafias[$chave][] = (string) $linha->curso;
            $cursos[$chave]['ordinais'][$ordinal] = ($cursos[$chave]['ordinais'][$ordinal] ?? 0) + (int) $linha->alunos;
        }

        foreach ($cursos as $chave => &$curso) {
            // a grafia canônica entre as variantes gravadas (a mais acentuada) — mesma regra do resto do sistema
            $curso['nome'] = NomeCurso::unicos($grafias[$chave])[0] ?? $grafias[$chave][0];
        }
        unset($curso);

        return $cursos;
    }

    /**
     * @param  array<int, int>  $matriculas  [ordinal => alunos ativos] do curso (0 = período desconhecido); [] sem matrícula
     * @param  array<int, int>  $inscritosPorOrdinal  [ordinal => resultados (com ou sem falta)] — só ordinais conhecidos
     * @param  int  $inscritosSemPeriodo  resultados cujo período do curso não é um ordinal
     * @return array{previstos: int, fonte: string, porOrdinal: array<int, int>, semAplicacao: array<int, int>}
     */
    public static function calcular(array $matriculas, array $inscritosPorOrdinal, int $inscritosSemPeriodo): array
    {
        $avaliados = array_keys(array_filter($inscritosPorOrdinal));
        $inscritos = array_sum($inscritosPorOrdinal) + $inscritosSemPeriodo;

        $daMatricula = ($matriculas[0] ?? 0);
        $porOrdinal = [];
        $semAplicacao = [];
        foreach ($matriculas as $ordinal => $alunos) {
            if ($ordinal === 0) {
                continue;
            }
            if (in_array($ordinal, $avaliados, true)) {
                $daMatricula += $alunos;
            } else {
                $semAplicacao[$ordinal] = $alunos;
            }
        }
        ksort($semAplicacao);

        foreach ($inscritosPorOrdinal as $ordinal => $feitos) {
            $porOrdinal[$ordinal] = max($matriculas[$ordinal] ?? 0, $feitos);
        }
        ksort($porOrdinal);

        return [
            'previstos' => max($daMatricula, $inscritos),
            'fonte' => $daMatricula > 0 ? 'matricula' : 'resultados',
            'porOrdinal' => $porOrdinal,
            'semAplicacao' => $semAplicacao,
        ];
    }

    /** [1,2,3,4,5,6,7,8] → "1º–8º"; [1,3,4] → "1º, 3º–4º"; [] → "—". @param array<int, int> $ordinais */
    public static function rotuloDosPeriodos(array $ordinais): string
    {
        $ordinais = array_values(array_unique($ordinais));
        sort($ordinais);
        if ($ordinais === []) {
            return '—';
        }

        $trechos = [];
        $inicio = $anterior = $ordinais[0];
        foreach (array_slice($ordinais, 1) as $ordinal) {
            if ($ordinal === $anterior + 1) {
                $anterior = $ordinal;

                continue;
            }
            $trechos[] = [$inicio, $anterior];
            $inicio = $anterior = $ordinal;
        }
        $trechos[] = [$inicio, $anterior];

        return implode(', ', array_map(fn ($t) => $t[0] === $t[1] ? $t[0].'º' : $t[0].'º'.($t[1] === $t[0] + 1 ? ', ' : '–').$t[1].'º', $trechos));
    }
}
