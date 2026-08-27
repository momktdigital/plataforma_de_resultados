<?php

namespace App\Support;

use App\Models\Aluno;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolve aluno_chave -> Aluno (turma, sexo, cor_raça...) para os
 * respondentes de uma avaliação, sem nunca dar JOIN entre `alunos` e uma
 * tabela do tamanho de `respostas`/`resultado_resumos`.
 *
 * O vínculo aluno<->resposta é por `aluno_id` OU por `ra` (nem toda linha
 * tem `aluno_id` preenchido) — um JOIN com OR entre colunas diferentes não
 * usa índice no MySQL, e testado contra um `respostas` de 167 mil linhas
 * isso deixava o painel BI carregando indefinidamente até estourar timeout
 * (500). Em vez de JOIN, lê os respondentes (uma linha por aluno — tabela
 * pequena, seja qual for o tamanho de `respostas`) e resolve o Aluno de cada
 * um via duas buscas indexadas (`WHERE id IN (...)`, `WHERE ra IN (...)`):
 * o custo fica proporcional ao Nº DE RESPONDENTES da avaliação, nunca ao
 * número de respostas.
 */
class AlunoVinculoResolver
{
    /** @return Collection<string, Aluno> chaveada por aluno_chave */
    public function resolver(int $avaliacaoCodigo, string $periodo = ''): Collection
    {
        $respondentes = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacaoCodigo)
            ->when($periodo !== '', fn ($q) => $q->where('periodo', $periodo))
            ->select('aluno_chave', 'aluno_id', 'ra')
            ->distinct()
            ->get();

        if ($respondentes->isEmpty()) {
            return collect();
        }

        $idsParaBuscar = $respondentes->pluck('aluno_id')->filter()->unique()->values();
        $porId = $idsParaBuscar->isEmpty()
            ? collect()
            : Aluno::whereIn('id', $idsParaBuscar)->get()->keyBy('id');

        $rasParaBuscar = $respondentes
            ->reject(fn ($r) => $r->aluno_id !== null && $porId->has($r->aluno_id))
            ->pluck('ra')->filter()->unique()->values();
        $porRa = $rasParaBuscar->isEmpty()
            ? collect()
            : Aluno::whereIn('ra', $rasParaBuscar)->get()->keyBy('ra');

        $resolvido = collect();
        foreach ($respondentes as $r) {
            $aluno = ($r->aluno_id !== null ? $porId->get($r->aluno_id) : null) ?? $porRa->get($r->ra);
            if ($aluno !== null) {
                $resolvido->put($r->aluno_chave, $aluno);
            }
        }

        return $resolvido;
    }

    /**
     * aluno_chave dos respondentes que batem com o filtro demográfico, ou
     * null quando o filtro está vazio (sinal de "não restringe nada" pros
     * chamadores, em vez de devolver a lista completa de chaves à toa).
     *
     * @return ?Collection<int, string>
     */
    public function chavesFiltradas(int $avaliacaoCodigo, string $periodo, FiltroDemografico $filtro, ?Carbon $dataReferencia = null): ?Collection
    {
        if ($filtro->vazio()) {
            return null;
        }

        $referencia = $dataReferencia ?? now();

        return $this->resolver($avaliacaoCodigo, $periodo)
            ->filter(fn (Aluno $aluno) => $filtro->combina($aluno, $referencia))
            ->keys();
    }

    /** @return array{turmas: array<int,string>, sexos: array<int,string>, corRacas: array<int,string>} opções distintas entre os respondentes, pros <select> do filtro. */
    public function opcoesDisponiveis(int $avaliacaoCodigo): array
    {
        $alunos = $this->resolver($avaliacaoCodigo, '');

        $distintos = fn (string $campo) => $alunos
            ->map(fn (Aluno $a) => $a->{$campo})
            ->filter(fn ($v) => ! empty($v))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'turmas' => $distintos('turma'),
            'sexos' => $distintos('sexo'),
            'corRacas' => $distintos('cor_raca'),
        ];
    }
}
