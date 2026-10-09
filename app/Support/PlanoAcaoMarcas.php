<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\PlanoAcao;
use Illuminate\Support\Collection;

/**
 * Onde JÁ existe plano de ação: o ícone de cada visual do painel ganha um selo quando o coordenador tem plano vivo (rascunho,
 * em análise, ajustes ou em execução) sobre aquele dado, e o menu de itens marca o item que já tem plano.
 *
 * Uma única consulta por requisição (guardada nos atributos da requisição — nunca em propriedade estática, que vazaria entre
 * requisições): o painel desenha dezenas de ícones e não pode fazer uma consulta por ícone.
 */
final class PlanoAcaoMarcas
{
    /** @var array<int, string> */
    private const VIVOS = [PlanoAcao::RASCUNHO, PlanoAcao::EM_ANALISE, PlanoAcao::AJUSTES, PlanoAcao::APROVADO];

    /**
     * Os planos vivos do coordenador que correspondem a este visual (e item) no recorte dado.
     *
     * @param  array<string, mixed>  $ctx  curso, periodo_letivo, categoria, avaliacao (como no ícone)
     * @param  bool  $qualquerItem  true = de qualquer item do visual (o selo do ícone de um gráfico com menu)
     * @return Collection<int, PlanoAcao>
     */
    public static function buscar(Admin $usuario, array $ctx, string $visual, ?string $item = null, bool $qualquerItem = false): Collection
    {
        $curso = (string) ($ctx['curso'] ?? '');
        $periodo = (string) ($ctx['periodo_letivo'] ?? '');
        $categoria = isset($ctx['categoria']) && $ctx['categoria'] !== '' && $ctx['categoria'] !== null ? (int) $ctx['categoria'] : null;

        return self::vivos($usuario)->filter(function (PlanoAcao $p) use ($visual, $item, $qualquerItem, $curso, $periodo, $categoria) {
            if ($p->origem_visual !== $visual || ($curso !== '' && ! NomeCurso::mesmo($p->curso, $curso))) {
                return false;
            }

            // Avaliação: o "item" é o código dela (o recorte de período/categoria vem da própria avaliação).
            if ($visual !== 'avaliacao' && ($p->periodo_letivo !== $periodo || ($p->categoria_id !== null ? (int) $p->categoria_id : null) !== $categoria)) {
                return false;
            }

            return $qualquerItem || ($p->origem_item ?? null) === $item;
        })->values();
    }

    /** @return Collection<int, PlanoAcao> só as colunas que o selo precisa */
    private static function vivos(Admin $usuario): Collection
    {
        $requisicao = request();
        $chave = 'plano_marcas_'.$usuario->id;

        if (! $requisicao->attributes->has($chave)) {
            $requisicao->attributes->set($chave, PlanoAcao::dosCursos($usuario->cursos())
                ->whereIn('status', self::VIVOS)
                ->get(['id', 'curso', 'periodo_letivo', 'categoria_id', 'origem_visual', 'origem_item', 'origem_rotulo', 'status']));
        }

        return $requisicao->attributes->get($chave);
    }
}
