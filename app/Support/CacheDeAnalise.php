<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cache dos agregados pesados do Dashboard, do painel do coordenador e do boletim (varreduras de `respostas` que
 * levam segundos numa avaliação grande), invalidado pela "impressão digital" dos dados de que dependem — e não por
 * um relógio.
 *
 * A impressão digital de uma avaliação combina:
 *  - `resultado_resumos`: nº de linhas e MAIOR id. ResumoResultadoService::recalcular() apaga e reinsere o resumo da
 *    avaliação, então o maior id MUDA a cada recálculo — e recalcular() roda depois de todo import, edição de
 *    gabarito/anulação, exclusão/restauração de período ou de questão. É o que invalida quando os RESULTADOS mudam.
 *  - `questoes`: nº de linhas, maior id, linhas excluídas e a última alteração. Invalida quando só os METADADOS
 *    mudam (área, tema, bloom...), que não passam por recalcular().
 *  - a "geração" (invalidar()): um carimbo gravado no próprio cache sempre que algo muda sem alterar as duas coisas
 *    acima — o curso de um resultado (nova matrícula importada), a edição de uma questão pelo Eloquent.
 *
 * O valor cacheado precisa ser ARRAY/escalar: o cache desserializa com `serializable_classes = false`, então um
 * objeto (até um stdClass) volta como __PHP_Incomplete_Class e quebra no primeiro acesso a uma propriedade. Os
 * testes rodam o cache em memória COM serialização (tests/TestCase.php) justamente para pegar isso.
 *
 * O TTL (6 h) é só uma rede de segurança. VERSAO entra em toda chave: ao mudar a conta de um agregado cacheado,
 * incremente-a, senão valores calculados pelo código antigo seguiriam valendo até o TTL.
 */
final class CacheDeAnalise
{
    private const VERSAO = 'v3';

    /**
     * @param  array<string, mixed>  $parametros  tudo que, além da avaliação, muda o resultado (período, escopo, filtro...)
     * @template T
     *
     * @param  Closure(): T  $calcular
     * @return T
     */
    public static function lembrar(string $nome, int $avaliacaoCodigo, array $parametros, Closure $calcular): mixed
    {
        return self::lembrarVarias($nome, [$avaliacaoCodigo], $parametros, $calcular);
    }

    /**
     * Igual a lembrar(), para uma análise que soma várias avaliações (painel do coordenador, boletim do aluno).
     *
     * @param  array<int, int>  $avaliacaoCodigos
     * @param  array<string, mixed>  $parametros
     * @template T
     *
     * @param  Closure(): T  $calcular
     * @return T
     */
    public static function lembrarVarias(string $nome, array $avaliacaoCodigos, array $parametros, Closure $calcular): mixed
    {
        $codigos = array_values(array_unique(array_map('intval', $avaliacaoCodigos)));
        sort($codigos);

        $chave = implode(':', [
            'analise', self::VERSAO, $nome,
            self::impressao($codigos),
            md5((string) json_encode([$codigos, $parametros])),
        ]);

        return Cache::remember($chave, now()->addHours(6), $calcular);
    }

    /**
     * Avisa que o que alimenta as análises dessas avaliações mudou sem alterar os resumos nem as questões (ver acima).
     *
     * @param  int|array<int, int>  $avaliacaoCodigos
     */
    public static function invalidar(int|array $avaliacaoCodigos): void
    {
        foreach ((array) $avaliacaoCodigos as $codigo) {
            // hrtime: um valor novo a cada chamada, sem ler antes (e sem corrida) — só precisa ser DIFERENTE.
            Cache::forever('analise:geracao:'.(int) $codigo, hrtime(true));
        }
    }

    /** @param array<int, int> $codigos */
    private static function impressao(array $codigos): string
    {
        if ($codigos === []) {
            return md5('');
        }

        // Poucas consultas indexadas e minúsculas, agrupadas por avaliação: não vale memorizar (os dados podem mudar
        // entre uma chamada e outra).
        $resumos = DB::table('resultado_resumos')->whereIn('avaliacao_codigo', $codigos)
            ->groupBy('avaliacao_codigo')->orderBy('avaliacao_codigo')
            ->selectRaw('avaliacao_codigo, COUNT(*) as n, MAX(id) as ultimo')->get();
        $questoes = DB::table('questoes')->whereIn('avaliacao_codigo', $codigos)
            ->groupBy('avaliacao_codigo')->orderBy('avaliacao_codigo')
            ->selectRaw('avaliacao_codigo, COUNT(*) as n, MAX(id) as ultimo, MAX(updated_at) as alterada, SUM(CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END) as apagadas')->get();
        $geracoes = Cache::many(array_map(fn ($c) => 'analise:geracao:'.$c, $codigos));

        return md5((string) json_encode([$resumos, $questoes, $geracoes]));
    }
}
