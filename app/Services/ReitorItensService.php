<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Support\CacheDeAnalise;
use App\Support\NomeCurso;
use App\Support\Psicometria;

/**
 * Análise INSTITUCIONAL dos itens (questões): em vez de abrir a psicometria avaliação por avaliação, junta as do
 * recorte e destaca o que merece revisão — acerto muito baixo, item que não discrimina e possível erro de gabarito —
 * e diz, quando dá, se o problema parece ser da QUESTÃO ou da FORMAÇÃO.
 *
 * Não refaz conta: cada avaliação passa por PsicometriaService::analisar() (dificuldade, discriminação, ponto-bisserial,
 * KR-20) e RelatorioAdminService::analiseAlternativas() (alternativa mais marcada), ambos com cache próprio. Quando a
 * avaliação tem estudantes de vários cursos (resultado_resumos.curso), o item também é calculado por curso — "baixo em
 * todos os cursos" aponta a questão ou um conteúdo comum; "baixo só em um" aponta o curso.
 *
 * A questão não tem identidade entre avaliações (cada avaliação cadastra as suas), então o item é sempre
 * (avaliação × número); a leitura institucional vem de somar por área, por avaliação e pelo diagnóstico.
 *
 * Diagnóstico de cada item (o primeiro que vale):
 *  1. gabarito suspeito: discriminação (ou ponto-bisserial) NEGATIVA e a alternativa errada mais marcada supera o
 *     próprio gabarito — quem vai melhor na prova marca outra resposta;
 *  2. problema da questão: acerto muito baixo E não discrimina;
 *  3. lacuna de formação: acerto muito baixo, mas discrimina bem — separa quem sabe de quem não sabe, o conteúdo
 *     não chegou;
 *  4. item fraco: não discrimina;
 *  5. baixo em todos os cursos (só avaliação com 2+ cursos).
 */
class ReitorItensService
{
    /** Acerto (%) abaixo do qual o item é "muito difícil". */
    public const ACERTO_MUITO_BAIXO = 25.0;

    /** Acerto (%) abaixo do qual um curso conta como "baixo" no item. */
    public const ACERTO_BAIXO_NO_CURSO = 35.0;

    /** Respostas mínimas para qualquer classificação (amostra pequena demais não diagnostica nada). */
    public const MINIMO_RESPOSTAS = 30;

    /** Avaliações analisadas por recorte (cada uma é uma análise de itens inteira). */
    public const MAXIMO_AVALIACOES = 40;

    /** Diagnóstico => [rótulo, severidade (maior = mais urgente), tom]. */
    public const DIAGNOSTICOS = [
        'gabarito' => ['Revisar o gabarito', 4, 'ruim'],
        'questao' => ['Provável problema da questão', 3, 'ruim'],
        'formacao' => ['Lacuna de formação', 2, 'atencao'],
        'fraco' => ['Item fraco (não discrimina)', 1, 'atencao'],
        'todos_cursos' => ['Baixo em todos os cursos', 1, 'atencao'],
    ];

    public function __construct(
        private readonly PsicometriaService $psicometria,
        private readonly RelatorioAdminService $relatorio,
    ) {}

    /**
     * @param  array<string, mixed>  $ctx  saída de ReitorDashboardService::contexto()
     * @return array<string, mixed>
     */
    public function gerar(array $ctx): array
    {
        $avaliacoes = $ctx['avaliacoes']
            ->filter(fn ($a) => in_array($a['codigo'], $ctx['avaliacao']['codigos'], true))
            ->sortByDesc('presentes')
            ->values();
        $limitado = $avaliacoes->count() > self::MAXIMO_AVALIACOES;
        $avaliacoes = $avaliacoes->take(self::MAXIMO_AVALIACOES);
        $codigos = $avaliacoes->pluck('codigo')->all();

        $cursosPorAvaliacao = [];
        foreach ($ctx['bruto']['contagens'] as [$codigo, $curso, , , $presentes]) {
            if ($presentes > 0) {
                $cursosPorAvaliacao[$codigo][NomeCurso::chave($curso)][] = $curso;
            }
        }

        $analise = CacheDeAnalise::lembrarVarias('reitor-itens', $codigos, [], function () use ($avaliacoes, $cursosPorAvaliacao) {
            $itens = [];
            $resumos = [];
            foreach ($avaliacoes as $a) {
                [$itensDaAvaliacao, $resumo] = $this->analisarAvaliacao($a, $cursosPorAvaliacao[$a['codigo']] ?? []);
                array_push($itens, ...$itensDaAvaliacao);
                if ($resumo !== null) {
                    $resumos[] = $resumo;
                }
            }

            return ['itens' => $itens, 'avaliacoes' => $resumos];
        });

        return $this->consolidar($analise['itens'], $analise['avaliacoes'], $ctx, $limitado, $avaliacoes->count());
    }

    /**
     * @param  array<string, array<int, string>>  $cursos  chave => grafias gravadas em resultado_resumos.curso
     * @return array{0: array<int, array<string, mixed>>, 1: ?array<string, mixed>}
     */
    private function analisarAvaliacao(array $a, array $cursos): array
    {
        $avaliacao = Avaliacao::find($a['codigo']);
        $geral = $avaliacao === null ? null : $this->psicometria->analisar($avaliacao);
        if ($geral === null) {
            return [[], null];
        }

        $alternativas = collect($this->relatorio->analiseAlternativas($avaliacao))->keyBy('numero');

        // Por curso só quando a avaliação tem mais de um com respondentes suficientes.
        $porCurso = [];
        if (count($cursos) >= 2) {
            foreach ($cursos as $chave => $grafias) {
                $analiseDoCurso = $this->psicometria->paraCursos([$grafias[0]])->analisar($avaliacao);
                if ($analiseDoCurso !== null) {
                    $porCurso[$chave] = collect($analiseDoCurso['itens'])->keyBy('numero')->map(fn ($i) => $i['dificuldade'])->all();
                }
            }
        }

        $itens = [];
        foreach ($geral['itens'] as $item) {
            $alt = $alternativas->get($item['numero']);
            $distrator = collect($alt['alternativas'] ?? [])->firstWhere('ehDistrator', true);
            $naoGabarito = collect($alt['alternativas'] ?? [])->firstWhere('ehGabarito', true);
            $porCursoDoItem = array_filter(array_map(fn ($mapa) => $mapa[$item['numero']] ?? null, $porCurso), fn ($v) => $v !== null);

            [$diagnostico, $dados] = $this->diagnosticar($item, $distrator, $naoGabarito, $porCursoDoItem);

            $itens[] = [
                'avaliacao' => $a['codigo'],
                'avaliacaoNome' => $a['nome'],
                // curso (chave) para abrir o Dashboard da avaliação na visão do coordenador: o primeiro dela
                'cursoChave' => array_key_first($cursos),
                'numero' => $item['numero'],
                'area' => $item['area'],
                'tema' => $item['tema'],
                'respostas' => $item['respostas'],
                'dificuldade' => $item['dificuldade'],
                'discriminacao' => $item['discriminacao'],
                'pontoBisserial' => $item['pontoBisserial'],
                'gabarito' => $item['gabarito'],
                'gabaritoPct' => $naoGabarito['percentual'] ?? null,
                'distrator' => $distrator['letra'] ?? null,
                'distratorPct' => $distrator['percentual'] ?? null,
                'porCurso' => $porCursoDoItem,
                'diagnostico' => $diagnostico,
                ...$dados,
            ];
        }

        $comDiagnostico = count(array_filter($itens, fn ($i) => $i['diagnostico'] !== null));

        return [$itens, [
            'avaliacao' => $a['codigo'],
            'nome' => $a['nome'],
            'cursos' => array_values(array_map(fn ($g) => $g[0], $cursos)),
            'respondentes' => $geral['respondentes'],
            'itens' => count($itens),
            'kr20' => $geral['kr20'],
            'aRevisar' => $comDiagnostico,
        ]];
    }

    /**
     * @param  array<string, mixed>  $item  linha de PsicometriaService::analisar()
     * @param  ?array<string, mixed>  $distrator
     * @param  ?array<string, mixed>  $gabarito
     * @param  array<string, float>  $porCurso  chave do curso => acerto (%)
     * @return array{0: ?string, 1: array{explicacao: ?string}}
     */
    private function diagnosticar(array $item, ?array $distrator, ?array $gabarito, array $porCurso): array
    {
        if ($item['respostas'] < self::MINIMO_RESPOSTAS) {
            return [null, ['explicacao' => null]];
        }

        $d = $item['discriminacao'];
        $pb = $item['pontoBisserial'];
        $muitoDificil = $item['dificuldade'] < self::ACERTO_MUITO_BAIXO;
        $naoDiscrimina = $d !== null && $d < Psicometria::D_MINIMO_ACEITAVEL;
        $negativo = ($d !== null && $d < 0) || ($pb !== null && $pb < 0);

        if ($negativo && $distrator !== null && ($gabarito === null || $distrator['percentual'] > $gabarito['percentual'])) {
            return ['gabarito', ['explicacao' => "A alternativa {$distrator['letra']} (".number_format($distrator['percentual'], 1, ',', '').'%) é mais marcada que o gabarito'
                .($gabarito !== null ? ' ('.number_format($gabarito['percentual'], 1, ',', '').'%)' : '').' e quem vai melhor na prova erra mais este item: confira se o gabarito está certo.']];
        }
        if ($muitoDificil && $naoDiscrimina) {
            return ['questao', ['explicacao' => 'Acerto muito baixo e o item não separa quem sabe de quem não sabe: o problema tende a estar na questão (enunciado, alternativas ou conteúdo fora do que foi ensinado).']];
        }
        if ($muitoDificil) {
            return ['formacao', ['explicacao' => 'Acerto muito baixo, mas o item discrimina bem: quem domina o conteúdo acerta. Tende a ser uma lacuna de formação, não da questão.']];
        }
        if ($naoDiscrimina) {
            return ['fraco', ['explicacao' => 'Acertar ou errar este item pouco tem a ver com o desempenho no resto da prova: vale revisar enunciado e alternativas.']];
        }
        if (count($porCurso) >= 2 && max($porCurso) < self::ACERTO_BAIXO_NO_CURSO) {
            return ['todos_cursos', ['explicacao' => 'O acerto é baixo em todos os cursos que fizeram a prova: aponta a questão ou um conteúdo comum que não chegou a ninguém.']];
        }

        return [null, ['explicacao' => null]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $itens
     * @param  array<int, array<string, mixed>>  $avaliacoes
     * @return array<string, mixed>
     */
    private function consolidar(array $itens, array $avaliacoes, array $ctx, bool $limitado, int $analisadas): array
    {
        $porArea = [];
        foreach ($itens as $i) {
            $area = trim((string) ($i['area'] ?? '')) !== '' ? trim((string) $i['area']) : 'Sem área';
            $g = &$porArea[$area];
            $g ??= ['area' => $area, 'itens' => 0, 'aRevisar' => 0, 'somaDificuldade' => 0.0, 'somaD' => 0.0, 'nD' => 0];
            $g['itens']++;
            $g['aRevisar'] += $i['diagnostico'] !== null ? 1 : 0;
            $g['somaDificuldade'] += $i['dificuldade'];
            if ($i['discriminacao'] !== null) {
                $g['somaD'] += $i['discriminacao'];
                $g['nD']++;
            }
            unset($g);
        }
        $areas = array_map(fn ($g) => [
            'area' => $g['area'],
            'itens' => $g['itens'],
            'aRevisar' => $g['aRevisar'],
            'pctARevisar' => round($g['aRevisar'] / $g['itens'] * 100, 1),
            'dificuldade' => round($g['somaDificuldade'] / $g['itens'], 1),
            'discriminacao' => $g['nD'] > 0 ? round($g['somaD'] / $g['nD'], 2) : null,
        ], array_values($porArea));
        usort($areas, fn ($a, $b) => $b['pctARevisar'] <=> $a['pctARevisar'] ?: $b['itens'] <=> $a['itens']);

        $contagem = array_fill_keys(array_keys(self::DIAGNOSTICOS), 0);
        foreach ($itens as $i) {
            if ($i['diagnostico'] !== null) {
                $contagem[$i['diagnostico']]++;
            }
        }

        $criticos = array_values(array_filter($itens, fn ($i) => $i['diagnostico'] !== null));
        usort($criticos, fn ($a, $b) => [self::DIAGNOSTICOS[$b['diagnostico']][1], $a['dificuldade']] <=> [self::DIAGNOSTICOS[$a['diagnostico']][1], $b['dificuldade']]);

        $kr = array_values(array_filter(array_column($avaliacoes, 'kr20'), fn ($v) => $v !== null));

        return [
            'itens' => $itens,
            'criticos' => $criticos,
            'contagem' => $contagem,
            'areas' => $areas,
            'avaliacoes' => $avaliacoes,
            'total' => [
                'itens' => count($itens),
                'aRevisar' => count($criticos),
                'pctARevisar' => count($itens) > 0 ? round(count($criticos) / count($itens) * 100, 1) : null,
                'kr20Medio' => $kr !== [] ? round(array_sum($kr) / count($kr), 2) : null,
                'avaliacoes' => count($avaliacoes),
            ],
            'limitado' => $limitado,
            'analisadas' => $analisadas,
            'minimoRespostas' => self::MINIMO_RESPOSTAS,
        ];
    }
}
