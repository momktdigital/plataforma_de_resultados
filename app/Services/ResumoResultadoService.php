<?php

namespace App\Services;

use App\Support\Anulacao;
use Illuminate\Support\Facades\DB;

/**
 * Mantém `resultado_resumos` — uma linha por aluno+avaliação+período já com
 * acertos/total/percentual calculados, pra não recalcular isso (via JOIN
 * contra `respostas`/`questoes`) toda vez que um aluno abre o boletim no
 * portal. É a diferença entre a consulta mais usada do sistema custar "1
 * leitura indexada" ou "escanear a tabela de respostas inteira", que
 * cresce um pouco a cada aluno×avaliação×questão — com milhões de linhas ali,
 * só a segunda opção já travaria o portal.
 *
 * `recalcular()` sempre é escamado a UMA avaliação (nunca à tabela toda), então
 * o custo é proporcional ao tamanho daquela avaliação — chamado depois de
 * qualquer mudança que afete o resultado dela: import de respostas, import/
 * edição/exclusão de questão (gabarito), ou exclusão de um período inteiro.
 */
class ResumoResultadoService
{
    public function recalcular(int $avaliacaoCodigo): void
    {
        $total = Anulacao::excluirDistribuidas(
            DB::table('questoes')
                ->where('avaliacao_codigo', $avaliacaoCodigo)
                ->whereNull('deleted_at')
                ->whereNotNull('gabarito')
                ->where('gabarito', '!=', '')
        )->count();

        $linhas = DB::table('respostas as r')
            ->join('questoes as q', function ($join) {
                Anulacao::excluirDistribuidas(
                    $join->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                        ->on('q.numero', '=', 'r.questao_numero')
                        ->whereNull('q.deleted_at'),
                    'q.anulada_modo',
                );
            })
            ->where('r.avaliacao_codigo', $avaliacaoCodigo)
            ->whereNull('r.deleted_at')
            ->groupBy('r.aluno_chave', 'r.periodo')
            ->selectRaw(
                'r.aluno_chave as aluno_chave, r.periodo as periodo, '
                .'max(r.ra) as ra, max(r.cpf) as cpf, max(r.aluno_id) as aluno_id, '
                ."sum(case when q.gabarito is not null and q.gabarito != '' and "
                .Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo')
                .' then 1 else 0 end) as acertos'
            )
            ->get();

        DB::transaction(function () use ($avaliacaoCodigo, $total, $linhas) {
            DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacaoCodigo)->delete();

            if ($linhas->isEmpty()) {
                return;
            }

            $agora = now();

            // Em blocos, não tudo de uma vez: uma avaliação com muitos
            // milhares de respondentes num único INSERT arrisca estourar o
            // max_allowed_packet do MySQL.
            $linhas->chunk(500)->each(function ($lote) use ($avaliacaoCodigo, $total, $agora) {
                DB::table('resultado_resumos')->insert($lote->map(fn ($linha) => [
                    'avaliacao_codigo' => $avaliacaoCodigo,
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
        });
    }
}
