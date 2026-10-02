<?php

namespace App\Services;

use App\Models\Resposta;
use App\Services\Visualizacoes\VisualizacaoDisponibilidadeService;
use App\Support\CacheDeAnalise;
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
        $total = Anulacao::excluirDistribuidas(
            DB::table('questoes')
                ->where('avaliacao_codigo', $avaliacaoCodigo)
                ->whereNull('deleted_at')
                ->whereNotNull('gabarito')
                ->where('gabarito', '!=', '')
        )->count();

        $semResposta = Resposta::semRespostaSql('r.resposta');
        // Item da análise psicométrica: questão com gabarito e NÃO anulada (em nenhum modo) — o mesmo recorte de
        // PsicometriaService::baseItens(). Guardar o escore dele aqui evita recalculá-lo, a cada visita ao Dashboard,
        // varrendo `respostas`.
        $itemDaAnalise = "q.id is not null and q.gabarito is not null and q.gabarito != '' and q.anulada_modo is null";

        $linhas = DB::table('respostas as r')
            // LEFT JOIN só para que `respondidas` (ausente) enxergue TODAS as respostas do aluno, inclusive a
            // questão anulada com distribuição de pontuação; o HAVING abaixo mantém o conjunto de linhas igual ao
            // do INNER JOIN de antes (aluno só aparece se respondeu alguma questão que conta).
            ->leftJoin('questoes as q', function ($join) {
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
                .' then 1 else 0 end) as acertos, '
                ."sum(case when {$itemDaAnalise} then 1 else 0 end) as itens_considerados, "
                ."sum(case when {$itemDaAnalise} and "
                .Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo')
                .' then 1 else 0 end) as acertos_itens, '
                // Nenhuma resposta de verdade na prova inteira = AUSENTE (não "errou tudo"); mesma definição de
                // PsicometriaService::presenca(), sobre todas as respostas do aluno.
                ."sum(case when not {$semResposta} then 1 else 0 end) as respondidas"
            )
            ->havingRaw('sum(case when q.id is not null then 1 else 0 end) > 0')
            ->get();

        DB::transaction(function () use ($avaliacaoCodigo, $total, $linhas) {
            // O curso de cada resultado vem do histórico de matrículas (CursoDoResultadoService, ao fim). Para um
            // aluno que já não está no cadastro (excluído) ou que ainda não foi importado não há histórico de onde
            // recalcular — então o curso já gravado é levado para a linha nova em vez de virar NULL (e o
            // coordenador perder a prova). Quando o aluno é conhecido, o CursoDoResultadoService sobrescreve.
            $cursoAnterior = DB::table('resultado_resumos')
                ->where('avaliacao_codigo', $avaliacaoCodigo)
                ->whereNotNull('curso')
                ->get(['aluno_chave', 'periodo', 'curso'])
                ->mapWithKeys(fn ($r) => [$r->aluno_chave.'|'.$r->periodo => $r->curso])
                ->all();

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
            CacheDeAnalise::invalidar($avaliacaoCodigo);

            if ($linhas->isEmpty()) {
                return;
            }

            $agora = now();

            // Em blocos, não tudo de uma vez: uma avaliação com muitos
            // milhares de respondentes num único INSERT arrisca estourar o
            // max_allowed_packet do MySQL.
            $linhas->chunk(500)->each(function ($lote) use ($avaliacaoCodigo, $total, $agora, $cursoAnterior) {
                DB::table('resultado_resumos')->insert($lote->map(fn ($linha) => [
                    'avaliacao_codigo' => $avaliacaoCodigo,
                    'aluno_chave' => $linha->aluno_chave,
                    'periodo' => $linha->periodo,
                    'ra' => $linha->ra,
                    'cpf' => $linha->cpf,
                    'aluno_id' => $linha->aluno_id,
                    'curso' => $cursoAnterior[$linha->aluno_chave.'|'.$linha->periodo] ?? null,
                    'ausente' => (int) $linha->respondidas === 0,
                    'acertos_itens' => (int) $linha->acertos_itens,
                    'itens_considerados' => (int) $linha->itens_considerados,
                    'acertos' => (int) $linha->acertos,
                    'total' => $total,
                    'percentual' => $total > 0 ? round($linha->acertos / $total * 100, 1) : null,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ])->all());
            });
        });

        // Em que curso cada aluno estava na prova → quem (coordenador) enxerga
        // cada resultado e a avaliação (CursoDoResultadoService / AvaliacaoCursoService).
        (new CursoDoResultadoService)->atualizarAvaliacao($avaliacaoCodigo);
        (new AvaliacaoCursoService)->sincronizarDosRespondentes($avaliacaoCodigo);
    }
}
