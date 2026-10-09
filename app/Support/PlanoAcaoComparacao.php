<?php

namespace App\Support;

use App\Models\PlanoAcao;
use App\Models\PlanoAcaoEvento;

/**
 * "O que mudou no reenvio": compara o conteúdo do plano em dois envios seguidos. A cada envio o PlanoAcaoService guarda uma
 * foto do conteúdo (PlanoAcao::conteudo()) no evento do histórico; aqui as duas últimas são postas lado a lado, para o
 * colaborador reavaliar só o que mudou depois de pedir ajustes, em vez de reler o plano inteiro.
 */
final class PlanoAcaoComparacao
{
    /** @var array<string, string> campo simples → rótulo */
    private const CAMPOS = [
        'meta_proficiencia' => 'Meta de proficiência',
        'data_proxima_avaliacao' => 'Data da próxima avaliação',
        'recorte' => 'Recorte que merece atenção',
        'resultado' => 'Resultado a enfrentar',
        'fragilidades' => 'Fragilidades (competências, objetivos, níveis cognitivos)',
        'evidencias' => 'Dados que sustentam a escolha',
        'causa_priorizada' => 'Causa priorizada',
        'causa_raiz' => 'Causa-raiz',
    ];

    /** @var array<string, string> */
    private const CAMPOS_DA_ACAO = [
        'descricao' => 'Ação',
        'execucao' => 'Como será executada',
        'responsavel' => 'Responsável',
        'prazo' => 'Prazo',
        'verificacao' => 'Como será verificada',
    ];

    /**
     * O que mudou do penúltimo para o último envio, ou null se não há dois envios com foto do conteúdo.
     *
     * @return ?array{de: string, para: string, mudancas: array<int, array{rotulo: string, antes: string, depois: string}>, acoes: array<int, array{titulo: string, situacao: string, mudancas: array<int, array{rotulo: string, antes: string, depois: string}>}>}
     */
    public static function doUltimoEnvio(PlanoAcao $plano): ?array
    {
        $envios = $plano->eventos
            ->filter(fn (PlanoAcaoEvento $e) => in_array($e->tipo, [PlanoAcaoEvento::ENVIADO, PlanoAcaoEvento::REENVIADO], true) && ! empty($e->dados['conteudo']))
            ->values();

        if ($envios->count() < 2) {
            return null;
        }

        [$depois, $antes] = [$envios[0], $envios[1]];
        $comparado = self::comparar($antes->dados['conteudo'], $depois->dados['conteudo']);

        return [
            'de' => $antes->created_at?->format('d/m/Y') ?? '',
            'para' => $depois->created_at?->format('d/m/Y') ?? '',
            ...$comparado,
        ];
    }

    /**
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $depois
     * @return array{mudancas: array<int, array{rotulo: string, antes: string, depois: string}>, acoes: array<int, array{titulo: string, situacao: string, mudancas: array<int, array{rotulo: string, antes: string, depois: string}>}>}
     */
    public static function comparar(array $antes, array $depois): array
    {
        $mudancas = [];

        foreach (self::CAMPOS as $campo => $rotulo) {
            self::registrar($mudancas, $rotulo, $antes[$campo] ?? null, $depois[$campo] ?? null);
        }

        foreach (PlanoAcao::DIMENSOES as $chave => $rotulo) {
            self::registrar($mudancas, "Causa possível · {$rotulo}", $antes['causas'][$chave] ?? null, $depois['causas'][$chave] ?? null);
        }

        $nota = fn (array $c) => isset($c['nota_impacto'], $c['nota_evidencia'], $c['nota_governabilidade'])
            ? "{$c['nota_impacto']} × {$c['nota_evidencia']} × {$c['nota_governabilidade']} = ".($c['nota_impacto'] * $c['nota_evidencia'] * $c['nota_governabilidade']) : null;
        self::registrar($mudancas, 'Priorização (impacto × evidência × governabilidade)', $nota($antes), $nota($depois));

        $lista = fn (array $c) => ! empty($c['porques']) ? implode("\n", array_map(fn ($t, $i) => ($i + 1).'. '.$t, $c['porques'], array_keys($c['porques']))) : null;
        self::registrar($mudancas, '5 Porquês', $lista($antes), $lista($depois));

        return ['mudancas' => $mudancas, 'acoes' => self::compararAcoes($antes['acoes'] ?? [], $depois['acoes'] ?? [])];
    }

    /**
     * @param  array<int, array<string, mixed>>  $antes
     * @param  array<int, array<string, mixed>>  $depois
     * @return array<int, array{titulo: string, situacao: string, mudancas: array<int, array{rotulo: string, antes: string, depois: string}>}>
     */
    private static function compararAcoes(array $antes, array $depois): array
    {
        $antesPorId = collect($antes)->keyBy('id');
        $depoisPorId = collect($depois)->keyBy('id');
        $resultado = [];

        foreach ($depois as $acao) {
            $anterior = $antesPorId->get($acao['id']);
            if ($anterior === null) {
                $resultado[] = ['titulo' => (string) ($acao['descricao'] ?? 'Ação'), 'situacao' => 'nova', 'mudancas' => []];

                continue;
            }

            $mudancas = [];
            foreach (self::CAMPOS_DA_ACAO as $campo => $rotulo) {
                self::registrar($mudancas, $rotulo, $anterior[$campo] ?? null, $acao[$campo] ?? null);
            }
            if ($mudancas !== []) {
                $resultado[] = ['titulo' => (string) ($acao['descricao'] ?? 'Ação'), 'situacao' => 'alterada', 'mudancas' => $mudancas];
            }
        }

        foreach ($antes as $acao) {
            if (! $depoisPorId->has($acao['id'])) {
                $resultado[] = ['titulo' => (string) ($acao['descricao'] ?? 'Ação'), 'situacao' => 'removida', 'mudancas' => []];
            }
        }

        return $resultado;
    }

    /** @param array<int, array{rotulo: string, antes: string, depois: string}> $mudancas */
    private static function registrar(array &$mudancas, string $rotulo, mixed $antes, mixed $depois): void
    {
        $a = self::texto($antes);
        $d = self::texto($depois);
        if ($a !== $d) {
            $mudancas[] = ['rotulo' => $rotulo, 'antes' => $a === '' ? '—' : $a, 'depois' => $d === '' ? '—' : $d];
        }
    }

    private static function texto(mixed $valor): string
    {
        return $valor === null ? '' : trim(is_float($valor) ? rtrim(rtrim(number_format($valor, 1, ',', ''), '0'), ',').'%' : (string) $valor);
    }
}
