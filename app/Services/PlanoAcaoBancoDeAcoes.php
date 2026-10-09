<?php

namespace App\Services;

use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;

/**
 * Banco de ações: ideias para a etapa "Ações" tiradas de planos JÁ CONCLUÍDOS sobre o mesmo tipo de dado (o mesmo visual e,
 * quando há, o mesmo item — uma área, um nível de Bloom...), só de ações que de fato foram concluídas. Quem já enfrentou o
 * mesmo problema deixou o que funcionou; ninguém precisa começar do zero.
 *
 * É institucional e ANÔNIMO: vem de planos de qualquer curso, mas só com o texto da ação (o quê, como, como se verifica) —
 * nunca o curso, o responsável ou o autor. Só sugere; o coordenador edita ou descarta.
 */
class PlanoAcaoBancoDeAcoes
{
    /** Quantas ideias mostrar. */
    private const LIMITE = 8;

    /**
     * @return array<int, array{descricao: string, execucao: string, verificacao: string, vezes: int}>
     */
    public function sugerir(string $visual, ?string $item, ?int $categoriaId = null, ?int $excetoPlanoId = null): array
    {
        $consulta = PlanoAcaoAcao::query()
            ->join('planos_acao as p', 'p.id', '=', 'plano_acao_acoes.plano_id')
            ->where('p.status', PlanoAcao::CONCLUIDO)
            ->where('plano_acao_acoes.status', PlanoAcaoAcao::CONCLUIDA)
            ->where('p.origem_visual', $visual)
            ->when($excetoPlanoId !== null, fn ($q) => $q->where('p.id', '!=', $excetoPlanoId))
            ->when($item !== null && $item !== '', fn ($q) => $q->where('p.origem_item', $item))
            ->orderByDesc('plano_acao_acoes.concluida_em')
            ->select('plano_acao_acoes.descricao', 'plano_acao_acoes.execucao', 'plano_acao_acoes.verificacao', 'p.categoria_id');

        $linhas = $consulta->limit(100)->get();

        // As da mesma categoria primeiro (a mesma prova), depois as demais; ações iguais viram uma só, com a contagem.
        $linhas = $linhas->sortByDesc(fn ($l) => $categoriaId !== null && (int) $l->categoria_id === $categoriaId ? 1 : 0)->values();

        $unicas = [];
        foreach ($linhas as $l) {
            $chave = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $l->descricao) ?? ''));
            if ($chave === '') {
                continue;
            }
            if (isset($unicas[$chave])) {
                $unicas[$chave]['vezes']++;

                continue;
            }
            $unicas[$chave] = [
                'descricao' => (string) $l->descricao,
                'execucao' => (string) $l->execucao,
                'verificacao' => (string) $l->verificacao,
                'vezes' => 1,
            ];
        }

        return array_slice(array_values($unicas), 0, self::LIMITE);
    }
}
