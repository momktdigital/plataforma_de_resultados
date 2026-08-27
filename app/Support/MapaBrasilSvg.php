<?php

namespace App\Support;

/**
 * Caminhos SVG dos 27 estados brasileiros (projeção Mercator, simplificados
 * por topologia) para o mapa coroplético de "respondentes por UF" — dados
 * gerados uma única vez a partir do IBGE (via codeforamerica/click_that_hood)
 * e versionados em resources/data/brasil_estados.php; não requer nenhuma
 * biblioteca de mapas externa (JS ou CDN) em tempo de execução.
 */
class MapaBrasilSvg
{
    private static ?array $dados = null;

    public static function viewBox(): string
    {
        return self::dados()['viewBox'];
    }

    /** @return array<string, string> UF => atributo "d" do <path> */
    public static function caminhos(): array
    {
        return self::dados()['caminhos'];
    }

    /** @return array<string, string> UF => "x,y" do centróide (posição do rótulo) */
    public static function centros(): array
    {
        return self::dados()['centros'];
    }

    /**
     * Cor de preenchimento em escala sequencial mono (claro→escuro, mesma
     * família do teal primário do app) proporcional ao valor em relação ao
     * máximo do conjunto — nunca um arco-íris, só intensidade. Sem dado (ou
     * máximo zerado) cai no cinza neutro de "sem informação".
     */
    public static function corPorValor(?int $valor, int $maximo): string
    {
        if ($valor === null || $valor <= 0 || $maximo <= 0) {
            return '#e2e8f0';
        }

        $fracao = min(1, $valor / $maximo);
        $inicio = [209, 250, 235];
        $fim = [4, 120, 87];
        [$r, $g, $b] = array_map(fn ($c0, $c1) => (int) round($c0 + ($c1 - $c0) * $fracao), $inicio, $fim);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private static function dados(): array
    {
        return self::$dados ??= require resource_path('data/brasil_estados.php');
    }
}
