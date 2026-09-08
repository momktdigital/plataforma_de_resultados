<?php

namespace App\Services;

use App\Models\Resposta;
use App\Services\Visualizacoes\VisualizacaoDisponibilidadeService;
use App\Support\AlunoVinculoResolver;
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
        $semResposta = Resposta::semRespostaSql('r.resposta');

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
                // Total por ALUNO (não fixo pra avaliação inteira) — uma
                // prova do Avalia Pro com banco de questões aleatório dá uma
                // quantidade de questões diferente por aluno (ex.: banco de
                // 18, 12 sorteadas por aluno); usar um total fixo faria todo
                // mundo ser avaliado sobre o pool inteiro, não só o que ele
                // de fato viu. Pra avaliação de gabarito único e igual pra
                // todo mundo (o caso comum hoje), total-por-aluno dá
                // exatamente o mesmo resultado que o total fixo de antes.
                ."sum(case when q.gabarito is not null and q.gabarito != '' then 1 else 0 end) as total, "
                ."sum(case when q.gabarito is not null and q.gabarito != '' and "
                .Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo')
                .' then 1 else 0 end) as acertos, '
                // Nenhuma resposta real registrada nesta avaliação = aluno
                // ausente, não "aluno errou tudo" — ver migration
                // add_ausente_to_resultado_resumos_table.
                ."sum(case when not {$semResposta} then 1 else 0 end) as respondidas"
            )
            ->get();

        DB::transaction(function () use ($avaliacaoCodigo, $linhas) {
            DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacaoCodigo)->delete();

            // resultado_resumos é justamente o que AlunoVinculoResolver::resolver()
            // lê e memoiza — sem isso, um recalculo no meio da requisição
            // (reimport, exclusão de período) deixaria o cache servindo os
            // respondentes de antes da mudança.
            AlunoVinculoResolver::limparCache();

            // Idem pro cache (cross-requisição) de disponibilidade dos
            // visuais — recalcular() é chamado por todo caminho que muda o
            // que calcular() enxerga (import, edição de gabarito, exclusão
            // de período), então é o ponto certo pra invalidar.
            VisualizacaoDisponibilidadeService::invalidar($avaliacaoCodigo);

            if ($linhas->isEmpty()) {
                return;
            }

            $agora = now();

            // Em blocos, não tudo de uma vez: uma avaliação com muitos
            // milhares de respondentes num único INSERT arrisca estourar o
            // max_allowed_packet do MySQL.
            $linhas->chunk(500)->each(function ($lote) use ($avaliacaoCodigo, $agora) {
                DB::table('resultado_resumos')->insert($lote->map(function ($linha) use ($avaliacaoCodigo, $agora) {
                    $total = (int) $linha->total;

                    return [
                        'avaliacao_codigo' => $avaliacaoCodigo,
                        'aluno_chave' => $linha->aluno_chave,
                        'periodo' => $linha->periodo,
                        'ra' => $linha->ra,
                        'cpf' => $linha->cpf,
                        'aluno_id' => $linha->aluno_id,
                        'acertos' => (int) $linha->acertos,
                        'total' => $total,
                        'percentual' => $total > 0 ? round($linha->acertos / $total * 100, 1) : null,
                        'ausente' => (int) $linha->respondidas === 0,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ];
                })->all());
            });
        });
    }
}
