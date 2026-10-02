<?php

namespace App\Support\Concerns;

use App\Support\EscopoCurso;
use Illuminate\Support\Collection;

/**
 * Para serviços de análise que podem ser restritos aos alunos de certos
 * cursos (coordenador). `paraCursos()` devolve uma CÓPIA — o serviço original,
 * injetado pelo container, continua sem restrição.
 *
 * Quem usa o trait precisa chamar `escopar()` em toda consulta a tabelas de
 * resultado e `alunosDoEscopo()` onde usaria o resolver de alunos.
 */
trait ComEscopoDeCurso
{
    private ?EscopoCurso $escopo = null;

    /** @param array<int, string>|null $cursos null = sem restrição (administrador) */
    public function paraCursos(?array $cursos): static
    {
        $copia = clone $this;
        $copia->escopo = $cursos === null ? null : new EscopoCurso($cursos);

        return $copia;
    }

    /**
     * Para consultas sobre `respostas`/`resultado_metricas` de uma avaliação. $prefixo é o alias
     * (ou o nome) da tabela com ponto ("r.", "respostas."), sempre qualificado.
     *
     * @template T
     *
     * @param  T  $query
     * @return T
     */
    private function escopar($query, string $prefixo, int $avaliacaoCodigo)
    {
        return $this->escopo === null ? $query : $this->escopo->restringir($query, $prefixo, $avaliacaoCodigo);
    }

    /**
     * Para consultas sobre `resultado_resumos`; $coluna é a coluna de curso (com alias, se houver).
     *
     * @template T
     *
     * @param  T  $query
     * @return T
     */
    private function escoparResumos($query, string $coluna = 'curso')
    {
        return $this->escopo === null ? $query : $this->escopo->restringirResumos($query, $coluna);
    }

    /**
     * @param  Collection<string, \App\Models\Aluno>  $alunos
     * @return Collection<string, \App\Models\Aluno>
     */
    private function dentroDoEscopo(Collection $alunos, int $avaliacaoCodigo): Collection
    {
        return $this->escopo === null ? $alunos : $this->escopo->filtrarAlunos($alunos, $avaliacaoCodigo);
    }
}
