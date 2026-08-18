<?php

namespace App\Services\Portal;

use App\Models\Aluno;
use App\Models\Prova;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use Illuminate\Support\Collection;

/**
 * Busca os resultados de um aluno no schema novo (respostas/
 * resultado_metricas/questoes) para exibição no portal público —
 * equivalente à consulta por RA em api/consulta.php, mas contra
 * `provas`/`questoes` em vez do JSON de `resultados`/`gabaritos`.
 */
class ResultadoConsultaService
{
    /** @return array<int, array{prova: Prova, periodo: string, respostas: Collection, gabaritos: Collection, acertos: int, total: int, percentual: ?float, metricas: Collection}> */
    public function buscarPorAluno(Aluno $aluno): array
    {
        $respostas = Resposta::where(fn ($q) => $this->porAluno($q, $aluno))
            ->orderBy('questao_numero')
            ->get();

        if ($respostas->isEmpty()) {
            return [];
        }

        $grupos = $respostas->groupBy(fn ($r) => $r->prova_codigo.'|'.$r->periodo);

        $resultados = [];
        foreach ($grupos as $grupo) {
            $provaCodigo = $grupo->first()->prova_codigo;
            $periodo = $grupo->first()->periodo;

            $prova = Prova::find($provaCodigo);
            if ($prova === null) {
                continue;
            }

            $gabaritos = $prova->questoes()->whereNotNull('gabarito')->where('gabarito', '!=', '')->pluck('gabarito', 'numero');

            $acertos = $grupo->filter(
                fn ($r) => $gabaritos->has($r->questao_numero) && $r->resposta === $gabaritos[$r->questao_numero]
            )->count();
            $total = $gabaritos->count();

            $metricas = ResultadoMetrica::where('prova_codigo', $provaCodigo)
                ->where('periodo', $periodo)
                ->where(fn ($q) => $this->porAluno($q, $aluno))
                ->get();

            $resultados[] = [
                'prova' => $prova,
                'periodo' => $periodo,
                'respostas' => $grupo->sortBy('questao_numero')->values(),
                'gabaritos' => $gabaritos,
                'acertos' => $acertos,
                'total' => $total,
                'percentual' => $total > 0 ? round($acertos / $total * 100, 1) : null,
                'metricas' => $metricas,
            ];
        }

        usort($resultados, fn ($a, $b) => $b['prova']->codigo <=> $a['prova']->codigo);

        return $resultados;
    }

    private function porAluno($query, Aluno $aluno): void
    {
        $query->where('ra', $aluno->ra);

        if ($aluno->cpf) {
            $query->orWhere('cpf', $aluno->cpf);
        }
    }
}
