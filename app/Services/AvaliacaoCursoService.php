<?php

namespace App\Services;

use App\Models\Curso;
use App\Support\NomeCurso;
use Illuminate\Support\Facades\DB;

/**
 * Mantém `avaliacao_cursos` — os cursos "presentes" numa avaliação, que
 * definem quais coordenadores a enxergam.
 *
 * Duas origens convivem na tabela:
 *  - `auto`: deduzido dos resultados (o curso de cada resultado, ver
 *    CursoDoResultadoService). É REFEITO a cada importação — entra o curso
 *    que passou a ter aluno e SAI o que deixou de ter (ex.: aluno que se
 *    transferiu: a avaliação de Odontologia não fica mais "de Medicina" só
 *    porque ele já foi de Medicina);
 *  - `manual`: marcado por um administrador na tela da avaliação. Nunca é
 *    apagado por uma importação.
 */
class AvaliacaoCursoService
{
    public function sincronizarDosRespondentes(int $avaliacaoCodigo): void
    {
        // Uma grafia só por curso (a canônica de Curso::nomesDisponiveis()) —
        // senão "ADMINISTRACAO" e "ADMINISTRAÇÃO" virariam dois cursos da avaliação.
        $canonicos = collect(Curso::nomesDisponiveis())->keyBy(fn ($nome) => NomeCurso::chave($nome));

        $deduzidos = DB::table('resultado_resumos')
            ->where('avaliacao_codigo', $avaliacaoCodigo)
            ->whereNotNull('curso')->where('curso', '!=', '')
            ->distinct()->pluck('curso')
            ->map(fn ($c) => $canonicos->get(NomeCurso::chave($c)) ?? trim((string) $c))
            ->filter()
            ->unique(fn ($c) => NomeCurso::chave($c))
            ->values();

        $chavesDeduzidas = $deduzidos->map(fn ($c) => NomeCurso::chave($c))->all();

        $existentes = DB::table('avaliacao_cursos')->where('avaliacao_codigo', $avaliacaoCodigo)->get();

        // Automáticos que deixaram de valer saem; manuais ficam sempre.
        $obsoletos = $existentes
            ->filter(fn ($e) => $e->origem === 'auto' && ! in_array(NomeCurso::chave($e->curso), $chavesDeduzidas, true))
            ->pluck('curso')
            ->all();
        if ($obsoletos !== []) {
            DB::table('avaliacao_cursos')->where('avaliacao_codigo', $avaliacaoCodigo)->where('origem', 'auto')->whereIn('curso', $obsoletos)->delete();
        }

        $jaTem = $existentes->map(fn ($e) => NomeCurso::chave($e->curso))->all();
        $novos = $deduzidos->reject(fn ($c) => in_array(NomeCurso::chave($c), $jaTem, true));

        if ($novos->isNotEmpty()) {
            DB::table('avaliacao_cursos')->insertOrIgnore(
                $novos->map(fn ($curso) => ['avaliacao_codigo' => $avaliacaoCodigo, 'curso' => $curso, 'origem' => 'auto'])->all()
            );
        }
    }

    /** @param array<int, int> $avaliacaoCodigos */
    public function sincronizarAvaliacoes(array $avaliacaoCodigos): void
    {
        foreach (array_unique($avaliacaoCodigos) as $codigo) {
            $this->sincronizarDosRespondentes((int) $codigo);
        }
    }
}
