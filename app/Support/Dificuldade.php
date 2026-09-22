<?php

namespace App\Support;

/**
 * Escala fixa de dificuldade pedagógica (Questao::dificuldade_pedagogica,
 * nullable) — valor canônico + rótulo, na ordem crescente de dificuldade.
 * Reaproveitada em todo lugar que valida, lista ou exibe essa escala
 * (StoreQuestaoRequest, o <select> do editor de questão,
 * RelatorioAdminService::curvaDificuldade() e
 * Portal\AnaliseConsolidadaService::curvaDificuldadePedagogica()) pra não
 * duplicar — e arriscar divergir — a lista/ordem/rótulo em cada um desses
 * lugares.
 */
final class Dificuldade
{
    public const MUITO_FACIL = 'muito_facil';

    public const FACIL = 'facil';

    public const MEDIO = 'medio';

    public const DIFICIL = 'dificil';

    /** @return array<string, string> valor => rótulo, na ordem crescente de dificuldade. */
    public static function rotulos(): array
    {
        return [
            self::MUITO_FACIL => 'Muito fácil',
            self::FACIL => 'Fácil',
            self::MEDIO => 'Médio',
            self::DIFICIL => 'Difícil',
        ];
    }

    /** @return array<int, string> */
    public static function valores(): array
    {
        return array_keys(self::rotulos());
    }
}
