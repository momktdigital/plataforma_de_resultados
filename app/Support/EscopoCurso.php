<?php

namespace App\Support;

use App\Models\Aluno;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Restringe uma análise aos resultados de certos cursos — o que um
 * coordenador enxerga no BI. Serviços de análise recebem um EscopoCurso (ver
 * App\Support\Concerns\ComEscopoDeCurso) e o aplicam em TODA consulta sobre
 * `respostas`/`resultado_resumos`/`resultado_metricas`, de modo que nenhum
 * número exibido inclua aluno de outro curso.
 *
 * O que vale é o curso DO RESULTADO (`resultado_resumos.curso`: o curso em que
 * o aluno estava quando fez aquela prova — ver CursoDoResultadoService), NUNCA
 * o curso atual do aluno: quem se transferiu de Medicina para Odontologia
 * continua nos números de Medicina nas provas de quando era de Medicina.
 * `respostas` e `resultado_metricas` não têm curso próprio — herdam o do
 * resumo da mesma avaliação, aluno e período (índice único de `resultado_resumos`).
 */
final class EscopoCurso
{
    /** @var array<int, string> */
    private readonly array $variantes;

    /** @var array<int, array<int, string>> memo por avaliação (vive só durante a requisição) */
    private array $chavesPorAvaliacao = [];

    /** @param array<int, string> $cursos */
    public function __construct(public readonly array $cursos)
    {
        $this->variantes = NomeCurso::variantes($cursos);
    }

    /**
     * Para `respostas`/`resultado_metricas` DE UMA AVALIAÇÃO (colunas
     * avaliacao_codigo e aluno_chave): só as linhas dos alunos cujo resultado
     * naquela avaliação é de um dos cursos. $prefixo é o alias (ou o nome) da
     * tabela com ponto ("r.", "respostas.") — NUNCA vazio.
     *
     * As chaves dos alunos do escopo são buscadas uma vez por avaliação (poucos
     * milhares de valores) e entram como `IN (...)`: bem mais barato que uma
     * subconsulta correlacionada avaliada para cada linha de `respostas`.
     * Basta a chave (sem o período): o curso é decidido por aluno e data da
     * prova, então todos os resultados do mesmo aluno na avaliação têm o mesmo.
     *
     * @template T of Builder|EloquentBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function restringir($query, string $prefixo, int $avaliacaoCodigo)
    {
        return $query->whereIn($prefixo.'aluno_chave', $this->chaves($avaliacaoCodigo));
    }

    /**
     * Para consultas que já são sobre `resultado_resumos` (ou o juntam):
     * $coluna é a coluna de curso, com alias se houver ("rr.curso").
     *
     * @template T of Builder|EloquentBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function restringirResumos($query, string $coluna = 'curso')
    {
        return $query->whereIn($coluna, $this->variantes);
    }

    /**
     * Identifica QUEM está no escopo naquela avaliação (os alunos cujo resultado é de um dos cursos), para compor
     * chave de cache: os cursos sozinhos não bastam, porque o curso de um resultado pode mudar (nova matrícula
     * importada) sem que a avaliação seja recalculada.
     */
    public function assinatura(int $avaliacaoCodigo): string
    {
        $chaves = $this->chaves($avaliacaoCodigo);
        sort($chaves);

        return md5(implode('|', $this->variantes).'#'.implode(',', $chaves));
    }

    /**
     * @param  Collection<string, Aluno>  $alunos  aluno_chave => Aluno (saída do AlunoVinculoResolver)
     * @return Collection<string, Aluno>
     */
    public function filtrarAlunos(Collection $alunos, int $avaliacaoCodigo): Collection
    {
        $chaves = array_flip($this->chaves($avaliacaoCodigo));

        return $alunos->filter(fn (Aluno $aluno, string $chave) => isset($chaves[$chave]));
    }

    /** @return array<int, string> aluno_chave dos resultados da avaliação que são de um dos cursos */
    private function chaves(int $avaliacaoCodigo): array
    {
        return $this->chavesPorAvaliacao[$avaliacaoCodigo] ??= DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacaoCodigo)
            ->whereIn('curso', $this->variantes)
            ->distinct()
            ->pluck('aluno_chave')
            ->all();
    }
}
