<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Mantém `resultado_resumos` — uma linha por aluno+prova+período já com
 * acertos/total/percentual calculados, pra não recalcular isso (via JOIN
 * contra `respostas`/`questoes`) toda vez que um aluno abre o boletim no
 * portal. É a diferença entre a consulta mais usada do sistema custar "1
 * leitura indexada" ou "escanear a tabela de respostas inteira", que
 * cresce um pouco a cada aluno×prova×questão — com milhões de linhas ali,
 * só a segunda opção já travaria o portal.
 *
 * `recalcular()` sempre é escamado a UMA prova (nunca à tabela toda), então
 * o custo é proporcional ao tamanho daquela prova — chamado depois de
 * qualquer mudança que afete o resultado dela: import de respostas, import/
 * edição/exclusão de questão (gabarito), ou exclusão de um período inteiro.
 */
class ResumoResultadoService
{
    public function recalcular(int $provaCodigo): void
    {
        $total = DB::table('questoes')
            ->where('prova_codigo', $provaCodigo)
            ->whereNull('deleted_at')
            ->whereNotNull('gabarito')
            ->where('gabarito', '!=', '')
            ->count();

        $linhas = DB::table('respostas as r')
            ->join('questoes as q', function ($join) {
                $join->on('q.prova_codigo', '=', 'r.prova_codigo')
                    ->on('q.numero', '=', 'r.questao_numero')
                    ->whereNull('q.deleted_at');
            })
            ->where('r.prova_codigo', $provaCodigo)
            ->whereNull('r.deleted_at')
            ->groupBy('r.aluno_chave', 'r.periodo')
            ->selectRaw(
                'r.aluno_chave as aluno_chave, r.periodo as periodo, '
                .'max(r.ra) as ra, max(r.cpf) as cpf, max(r.aluno_id) as aluno_id, '
                ."sum(case when q.gabarito is not null and q.gabarito != '' and r.resposta = q.gabarito then 1 else 0 end) as acertos"
            )
            ->get();

        DB::transaction(function () use ($provaCodigo, $total, $linhas) {
            DB::table('resultado_resumos')->where('prova_codigo', $provaCodigo)->delete();

            if ($linhas->isEmpty()) {
                return;
            }

            $agora = now();

            DB::table('resultado_resumos')->insert($linhas->map(fn ($linha) => [
                'prova_codigo' => $provaCodigo,
                'aluno_chave' => $linha->aluno_chave,
                'periodo' => $linha->periodo,
                'ra' => $linha->ra,
                'cpf' => $linha->cpf,
                'aluno_id' => $linha->aluno_id,
                'acertos' => (int) $linha->acertos,
                'total' => $total,
                'percentual' => $total > 0 ? round($linha->acertos / $total * 100, 1) : null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ])->all());
        });
    }
}
