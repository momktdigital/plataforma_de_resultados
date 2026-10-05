<?php

namespace App\Services;

use App\Support\Anulacao;
use App\Support\CacheDeAnalise;
use App\Support\NomeCurso;
use Illuminate\Support\Facades\DB;

/**
 * Desempenho institucional por COMPETÊNCIA: nível cognitivo da taxonomia de Bloom e área de conhecimento das
 * questões, por curso e entre proficientes × não proficientes ("o que distingue quem passou do corte?").
 *
 * É a única parte do painel da reitoria que varre `respostas` — numa ÚNICA consulta agregada no banco (JOIN +
 * GROUP BY + SUM(CASE...)), restrita aos presentes da avaliação e agrupada por curso × proficiente × Bloom × área,
 * então devolve algumas centenas de linhas, não os milhões de respostas. O resultado é cacheado por avaliação
 * (CacheDeAnalise) — só arrays — e a seleção de cursos é aplicada depois, em PHP, sobre o cache.
 *
 * Mesma regra de anulação do resto do sistema (Anulacao): questão anulada com distribuição de pontuação sai do
 * cálculo; a anulada com "dar ponto" credita todos. Só entram questões com gabarito e com o campo preenchido.
 */
class ReitorCompetenciasService
{
    /** Células (curso × nível/área) com menos respostas que isto aparecem como "—": amostra pequena demais. */
    public const MINIMO_RESPOSTAS = 30;

    /** Respostas mínimas de proficientes / não proficientes numa célula do conjunto. */
    private const MINIMO_GRUPO = 10;

    /** Áreas mostradas no mapa de calor (as de mais respostas); a lista completa vai no ranking. */
    private const MAXIMO_AREAS_NO_MAPA = 14;

    /** @var array<int, array{chave: string, rotulo: string, prefixos: array<int, string>}> */
    private const NIVEIS_BLOOM = [
        ['chave' => 'lembrar', 'rotulo' => 'Lembrar', 'prefixos' => ['LEMBR', 'CONHEC', 'RECORD']],
        ['chave' => 'compreender', 'rotulo' => 'Compreender', 'prefixos' => ['COMPREE', 'ENTEND']],
        ['chave' => 'aplicar', 'rotulo' => 'Aplicar', 'prefixos' => ['APLIC']],
        ['chave' => 'analisar', 'rotulo' => 'Analisar', 'prefixos' => ['ANALIS']],
        ['chave' => 'avaliar', 'rotulo' => 'Avaliar', 'prefixos' => ['AVALI', 'JULG']],
        ['chave' => 'criar', 'rotulo' => 'Criar', 'prefixos' => ['CRI', 'SINTET']],
    ];

    /** Nível de Bloom normalizado pelo texto da planilha ("Lembrar", "LEMBRAR", "1 - Lembrar"...); null se não reconhecido. */
    public static function nivelDeBloom(?string $valor): ?string
    {
        $chave = NomeCurso::chave($valor);
        if ($chave === '') {
            return null;
        }
        // tira o número/pontuação da frente ("1 - Lembrar", "N1: Lembrar")
        $chave = trim((string) preg_replace('/^(N\s*)?[\d\W_]+/', '', $chave));

        foreach (self::NIVEIS_BLOOM as $nivel) {
            foreach ($nivel['prefixos'] as $prefixo) {
                if (str_starts_with($chave, $prefixo)) {
                    return $nivel['chave'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ctx  saída de ReitorDashboardService::contexto()
     * @return array<string, mixed>
     */
    public function gerar(array $ctx): array
    {
        $linhas = $this->linhas($ctx['avaliacao']['codigos'], $ctx['corte']);
        $selecionados = $ctx['cursosSelecionados'];

        // [escopo => [grupo => [acertos, total]]] para o total da visão, proficientes, não proficientes e por curso.
        $bloom = ['total' => [], 'prof' => [], 'nao' => [], 'cursos' => []];
        $areas = ['total' => [], 'prof' => [], 'nao' => [], 'cursos' => []];

        foreach ($linhas as [$curso, $proficiente, $nivelBruto, $area, $total, $acertos]) {
            $chave = NomeCurso::chave($curso);
            if (! in_array($chave, $selecionados, true)) {
                continue;
            }

            if (($nivel = self::nivelDeBloom($nivelBruto)) !== null) {
                $this->somar($bloom['total'], $nivel, $total, $acertos);
                $this->somar($bloom[$proficiente ? 'prof' : 'nao'], $nivel, $total, $acertos);
                $this->somar($bloom['cursos'][$chave], $nivel, $total, $acertos);
            }
            if ($area !== null && trim($area) !== '') {
                $rotulo = trim((string) preg_replace('/\s+/u', ' ', $area));
                $this->somar($areas['total'], $rotulo, $total, $acertos);
                $this->somar($areas[$proficiente ? 'prof' : 'nao'], $rotulo, $total, $acertos);
                $this->somar($areas['cursos'][$chave], $rotulo, $total, $acertos);
            }
        }

        return ['bloom' => $this->montarBloom($bloom, $selecionados), 'areas' => $this->montarAreas($areas, $selecionados)];
    }

    /**
     * Uma varredura de `respostas` por recorte (as avaliações dele juntas): [curso, proficiente (0/1), bloom_nivel, área, respostas, acertos].
     * Só presentes com nota; cacheada.
     *
     * @param  array<int, int>  $codigos
     * @return array<int, array{0: string, 1: bool, 2: ?string, 3: ?string, 4: int, 5: int}>
     */
    private function linhas(array $codigos, float $corte): array
    {
        return CacheDeAnalise::lembrarVarias('reitor-competencias', $codigos, ['corte' => $corte], function () use ($codigos, $corte) {
            $presentes = DB::table('resultado_resumos as rr')
                ->whereIn('rr.avaliacao_codigo', $codigos)
                ->where('rr.ausente', false)
                ->whereNotNull('rr.percentual')
                ->whereNotNull('rr.curso')->where('rr.curso', '!=', '')
                ->select('rr.avaliacao_codigo', 'rr.aluno_chave', 'rr.periodo', 'rr.curso')
                ->selectRaw('CASE WHEN rr.percentual >= ? THEN 1 ELSE 0 END as proficiente', [$corte]);

            return DB::table('respostas as r')
                ->joinSub($presentes, 'pr', function ($join) {
                    $join->on('pr.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                        ->on('pr.aluno_chave', '=', 'r.aluno_chave')
                        ->on('pr.periodo', '=', 'r.periodo');
                })
                ->join('questoes as q', function ($join) {
                    Anulacao::excluirDistribuidas(
                        $join->on('q.numero', '=', 'r.questao_numero')
                            ->on('q.avaliacao_codigo', '=', 'r.avaliacao_codigo')
                            ->whereNull('q.deleted_at')
                            ->whereNotNull('q.gabarito')
                            ->where('q.gabarito', '!=', ''),
                        'q.anulada_modo',
                    );
                })
                ->whereIn('r.avaliacao_codigo', $codigos)
                ->whereNull('r.deleted_at')
                ->groupBy('pr.curso', 'pr.proficiente', 'q.bloom_nivel', 'q.area')
                ->selectRaw('pr.curso as curso, pr.proficiente as proficiente, q.bloom_nivel as bloom, q.area as area, COUNT(*) as total')
                ->selectRaw('SUM(CASE WHEN '.Anulacao::condicaoAcertoSql('r.resposta', 'q.gabarito', 'q.anulada_modo').' THEN 1 ELSE 0 END) as acertos')
                ->get()
                ->map(fn ($l) => [(string) $l->curso, (bool) $l->proficiente, $l->bloom !== null ? (string) $l->bloom : null, $l->area !== null ? (string) $l->area : null, (int) $l->total, (int) $l->acertos])
                ->all();
        });
    }

    /** @param array<string, array{0: int, 1: int}> $mapa */
    private function somar(?array &$mapa, string $grupo, int $total, int $acertos): void
    {
        $mapa ??= [];
        $mapa[$grupo] ??= [0, 0];
        $mapa[$grupo][0] += $acertos;
        $mapa[$grupo][1] += $total;
    }

    private function pct(?array $celula, int $minimo = 1): ?float
    {
        return $celula !== null && $celula[1] >= $minimo ? round($celula[0] / $celula[1] * 100, 1) : null;
    }

    /**
     * @param  array<string, mixed>  $b
     * @param  array<int, string>  $selecionados
     * @return array<string, mixed>
     */
    private function montarBloom(array $b, array $selecionados): array
    {
        $niveis = array_map(fn ($n) => ['chave' => $n['chave'], 'rotulo' => $n['rotulo']], self::NIVEIS_BLOOM);

        // Proficientes e não proficientes são grupos menores: aceitam menos respostas por célula que curso × nível.
        $linha = fn (array $mapa, int $minimo = self::MINIMO_RESPOSTAS) => collect($niveis)->mapWithKeys(fn ($n) => [$n['chave'] => [
            'pct' => $this->pct($mapa[$n['chave']] ?? null, $minimo),
            'respostas' => $mapa[$n['chave']][1] ?? 0,
        ]])->all();

        $porCurso = [];
        foreach ($selecionados as $chave) {
            if (isset($b['cursos'][$chave])) {
                $porCurso[$chave] = $linha($b['cursos'][$chave]);
            }
        }

        return [
            'niveis' => $niveis,
            'total' => $linha($b['total']),
            'proficientes' => $linha($b['prof'], self::MINIMO_GRUPO),
            'naoProficientes' => $linha($b['nao'], self::MINIMO_GRUPO),
            'porCurso' => $porCurso,
            'temDados' => $b['total'] !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<int, string>  $selecionados
     * @return array<string, mixed>
     */
    private function montarAreas(array $a, array $selecionados): array
    {
        $ranking = [];
        foreach ($a['total'] as $area => $celula) {
            if ($celula[1] < self::MINIMO_RESPOSTAS) {
                continue;
            }
            $pct = $this->pct($celula);
            $prof = $this->pct($a['prof'][$area] ?? null, self::MINIMO_GRUPO);
            $nao = $this->pct($a['nao'][$area] ?? null, self::MINIMO_GRUPO);
            $ranking[] = [
                'area' => $area,
                'pct' => $pct,
                'respostas' => $celula[1],
                'proficientes' => $prof,
                'naoProficientes' => $nao,
                'distancia' => $prof !== null && $nao !== null ? round($prof - $nao, 1) : null,
            ];
        }
        usort($ranking, fn ($x, $y) => $x['pct'] <=> $y['pct']);

        // Mapa de calor: as áreas com mais respostas, na ordem do ranking (da mais fraca para a mais forte).
        $noMapa = collect($ranking)->sortByDesc('respostas')->take(self::MAXIMO_AREAS_NO_MAPA)->pluck('area')->all();
        $mapa = [];
        foreach ($ranking as $linha) {
            if (in_array($linha['area'], $noMapa, true)) {
                $mapa[] = $linha['area'];
            }
        }

        $porCurso = [];
        foreach ($selecionados as $chave) {
            if (! isset($a['cursos'][$chave])) {
                continue;
            }
            foreach ($mapa as $area) {
                $porCurso[$chave][$area] = $this->pct($a['cursos'][$chave][$area] ?? null, self::MINIMO_RESPOSTAS);
            }
        }

        return ['ranking' => $ranking, 'mapa' => $mapa, 'porCurso' => $porCurso, 'omitidas' => max(0, count($ranking) - count($mapa))];
    }
}
