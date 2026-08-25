<?php

namespace App\Services;

use App\Models\Questao;

/**
 * Sincroniza os relacionamentos "0..N valores" de uma questão —
 * questao_referencias (matriz_prova/dcn/portaria_inep/ppc) e
 * questao_matrizes (período/disciplina/código) — a partir de listas de
 * valores já extraídas (da planilha ou do editor manual). Sempre substitui
 * tudo que já existia daquele tipo pelos valores informados.
 */
class QuestaoReferenciaService
{
    /** @param  array<int, string|null>  $valores */
    public function sincronizarReferencias(Questao $questao, string $tipo, array $valores): void
    {
        $questao->referencias()->where('tipo', $tipo)->delete();

        $valores = $this->limpar($valores);
        if ($valores !== []) {
            $questao->referencias()->createMany(array_map(fn ($valor) => ['tipo' => $tipo, 'valor' => $valor], $valores));
        }
    }

    /**
     * @param  array<int, string|null>  $periodos
     * @param  array<int, string|null>  $disciplinas
     * @param  array<int, string|null>  $codigos
     */
    public function sincronizarMatrizes(Questao $questao, array $periodos, array $disciplinas, array $codigos): void
    {
        $periodos = $this->limpar($periodos);
        $disciplinas = $this->limpar($disciplinas);
        $codigos = $this->limpar($codigos);

        $questao->matrizes()->delete();

        $total = max(count($periodos), count($disciplinas), count($codigos));
        for ($i = 0; $i < $total; $i++) {
            $questao->matrizes()->create([
                'periodo' => isset($periodos[$i]) && is_numeric($periodos[$i]) ? (int) $periodos[$i] : null,
                'disciplina' => $disciplinas[$i] ?? null,
                'codigo' => $codigos[$i] ?? null,
            ]);
        }
    }

    /** @return array<int, string> */
    private function limpar(array $valores): array
    {
        return array_values(array_filter(array_map('trim', $valores), fn ($v) => $v !== ''));
    }
}
