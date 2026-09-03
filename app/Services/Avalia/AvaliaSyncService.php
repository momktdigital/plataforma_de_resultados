<?php

namespace App\Services\Avalia;

use App\Models\Aluno;
use App\Models\AvaliaAvaliacaoDisponivel;
use App\Models\Avaliacao;
use App\Models\AvaliaSyncExecucao;
use App\Models\ConfiguracaoSistema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orquestra uma sincronização com o Avalia: extrai (AvaliaExtractorContract),
 * transforma para o schema desta aplicação e grava. Segue a mesma divisão
 * que o resto do projeto usa entre Eloquent e DB::table (ver CLAUDE.md):
 * `avaliacoes`/`questoes` (baixa cardinalidade — uma linha por
 * avaliação/questão, não por respondente) via Eloquent updateOrCreate();
 * `respostas`/`resultado_metricas` (cresce aluno × avaliação × questão) via
 * DB::table()->upsert() em lote, igual a ResultadoImportService/QuestaoImportService.
 *
 * Cada avaliação sincronizada daqui é uma (prova, disciplina) do Avalia Pro
 * ou um questionário do Avalia Online — não a prova inteira "globalizada"
 * (decisão registrada na conversa de planejamento da integração).
 */
class AvaliaSyncService
{
    private const TAMANHO_LOTE = 1000;

    private const NOME_METRICA_NOTA_FINAL = 'Nota Final';

    public function __construct(private readonly AvaliaExtractorContract $extractor = new RedshiftAvaliaExtractor) {}

    public function sincronizar(string $produto, string $disparadoPor, ?int $adminId = null): AvaliaSyncExecucao
    {
        // Autocorrige uma sincronização travada de uma tentativa anterior
        // antes de começar uma nova — ver AvaliaSyncExecucao::marcarTravadasComoErro().
        AvaliaSyncExecucao::marcarTravadasComoErro();

        $execucao = AvaliaSyncExecucao::create([
            'produto' => $produto,
            'status' => AvaliaSyncExecucao::STATUS_PROCESSANDO,
            'disparado_por' => $disparadoPor,
            'admin_id' => $adminId,
            'iniciado_em' => now(),
        ]);

        try {
            $mapaAvaliacoes = $this->carregarMapaAvaliacoes($produto);
            $idsPermitidos = $this->idsPermitidos($produto);

            $notas = $this->extractor->notas($produto, $this->watermark($produto, 'notas'), $idsPermitidos);
            $novasAvaliacoes = $this->upsertAvaliacoes($produto, $notas, $mapaAvaliacoes);
            ['gravadas' => $metricasGravadas, 'sem_identificador' => $metricasSemId] = $this->upsertMetricas($produto, $notas, $mapaAvaliacoes);
            $this->atualizarWatermark($produto, 'notas', $notas);

            $respostas = $this->extractor->respostas($produto, $this->watermark($produto, 'respostas'), $idsPermitidos);
            $questoesGravadas = $this->upsertQuestoes($produto, $respostas, $mapaAvaliacoes);
            ['gravadas' => $respostasGravadas, 'sem_identificador' => $respostasSemId] = $this->upsertRespostas($produto, $respostas, $mapaAvaliacoes);
            $this->atualizarWatermark($produto, 'respostas', $respostas);

            $execucao->update([
                'status' => AvaliaSyncExecucao::STATUS_SUCESSO,
                'concluido_em' => now(),
                'linhas_lidas' => $notas->count() + $respostas->count(),
                'linhas_gravadas' => $novasAvaliacoes + $metricasGravadas + $questoesGravadas + $respostasGravadas,
                // Linhas que vieram do Avalia sem CPF (obrigatório em
                // respostas/resultado_metricas) e por isso foram descartadas
                // — ver migration 2026_09_05_100000. Um número alto aqui
                // costuma indicar que o CPF não está vindo populado do lado
                // do Avalia pra esse produto/ambiente, não um bug daqui.
                'linhas_sem_identificador' => $metricasSemId + $respostasSemId,
            ]);
        } catch (Throwable $e) {
            // Incidente real: se essa própria atualização falhar (ex.: banco
            // desatualizado sem uma coluna que o código já espera — como
            // aconteceu em homologação), a exceção secundária mascarava a
            // original e a linha ficava presa em 'processando' pra sempre,
            // sem NENHUMA mensagem de erro registrada. Isolado aqui — o pior
            // caso agora é "sem detalhe no log", nunca "trava tudo sem
            // rastro" (AvaliaSyncExecucao::marcarTravadasComoErro() ainda
            // destrava a tela mais tarde de qualquer forma).
            try {
                $execucao->update([
                    'status' => AvaliaSyncExecucao::STATUS_ERRO,
                    'concluido_em' => now(),
                    'mensagem_erro' => $e->getMessage(),
                ]);
            } catch (Throwable $erroAoRegistrar) {
                report($erroAoRegistrar);
            }

            throw $e;
        }

        return $execucao;
    }

    /** @return array<string, int> id_externo da avaliação => avaliacoes.codigo */
    private function carregarMapaAvaliacoes(string $produto): array
    {
        return Avaliacao::where('origem', $produto)
            ->pluck('codigo', 'id_externo')
            ->all();
    }

    /**
     * Consulta leve as provas/questionários existentes no Avalia (não os
     * dados de aluno) e atualiza App\Models\AvaliaAvaliacaoDisponivel — usada
     * pelo botão "Atualizar lista de provas disponíveis" da tela de
     * Integração, para popular o que o admin pode selecionar. Nunca mexe na
     * coluna `selecionada` de uma prova/disciplina já conhecida (só atualiza
     * nome/curso e adiciona o que for novo).
     *
     * Duas passadas: primeiro as provas (pai_id null), depois as disciplinas
     * (pai_id apontando pra prova) — precisa saber o id real da prova (dado
     * pelo banco no upsert) antes de gravar as disciplinas que dependem dele.
     */
    public function atualizarCatalogo(string $produto): int
    {
        $linhas = $this->extractor->listarProvasDisponiveis($produto);

        if ($linhas->isEmpty()) {
            return 0;
        }

        $provas = $linhas->filter(fn ($l) => ($l->pai_externo ?? null) === null);
        $disciplinas = $linhas->filter(fn ($l) => ($l->pai_externo ?? null) !== null);

        $agora = now();

        $registrosProvas = $provas->map(fn ($p) => [
            'produto' => $produto,
            'pai_id' => null,
            'id_externo' => (string) $p->id_externo,
            'nome' => $p->nome ?? null,
            'curso' => null,
            'tipo' => $p->tipo ?? null,
            'data_referencia' => $p->data_referencia ?? null,
            'selecionada' => false,
            'created_at' => $agora,
            'updated_at' => $agora,
        ])->all();

        foreach (array_chunk($registrosProvas, self::TAMANHO_LOTE) as $lote) {
            DB::table('avalia_avaliacoes_disponiveis')->upsert(
                $lote,
                ['produto', 'id_externo'],
                // 'selecionada' de propósito fora daqui — não pode resetar a
                // escolha do admin numa prova que ele já marcou antes.
                ['nome', 'tipo', 'data_referencia', 'updated_at']
            );
        }

        $gravadas = count($registrosProvas);

        if ($disciplinas->isNotEmpty()) {
            $mapaProvas = AvaliaAvaliacaoDisponivel::where('produto', $produto)
                ->whereNull('pai_id')
                ->pluck('id', 'id_externo');

            $registrosDisciplinas = $disciplinas
                ->map(function ($d) use ($produto, $mapaProvas, $agora) {
                    $paiId = $mapaProvas[(string) $d->pai_externo] ?? null;

                    // A prova-mãe deveria sempre existir (a mesma extração
                    // devolve os dois níveis juntos) — pular defensivamente
                    // se algum dia não existir, em vez de quebrar o catálogo
                    // inteiro por uma disciplina órfã.
                    if ($paiId === null) {
                        return null;
                    }

                    return [
                        'produto' => $produto,
                        'pai_id' => $paiId,
                        'id_externo' => (string) $d->id_externo,
                        'nome' => $d->nome ?? null,
                        'curso' => $d->curso ?? null,
                        'tipo' => null,
                        'data_referencia' => null,
                        'selecionada' => false,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ];
                })
                ->filter()
                ->values()
                ->all();

            foreach (array_chunk($registrosDisciplinas, self::TAMANHO_LOTE) as $lote) {
                DB::table('avalia_avaliacoes_disponiveis')->upsert(
                    $lote,
                    ['produto', 'id_externo'],
                    ['nome', 'curso', 'pai_id', 'updated_at']
                );
            }

            $gravadas += count($registrosDisciplinas);
        }

        return $gravadas;
    }

    /**
     * null = sem filtro (sincroniza todas as provas do produto); array = só
     * as provas/disciplinas com esse id_externo. Modo padrão é 'selecionadas'
     * com nada marcado — ou seja, uma instalação nova não sincroniza nada
     * até o admin escolher, de propósito (ver migration de
     * avalia_avaliacoes_disponiveis).
     *
     * Pro Avalia Pro a seleção real vive sempre na folha (disciplina,
     * pai_id preenchido) — uma prova pode cobrir dezenas de disciplinas em
     * cursos diferentes, então marcar só a prova (pai_id null) não diz
     * "sincronize tudo dela"; a tela marca as disciplinas via JS quando o
     * admin marca a prova toda. Pro Avalia Online não há folha (um
     * questionário já é a unidade inteira), então a seleção fica no nível
     * de topo mesmo.
     *
     * @return array<int, string>|null
     */
    private function idsPermitidos(string $produto): ?array
    {
        $modo = ConfiguracaoSistema::valor("avalia_modo_{$produto}", 'selecionadas');

        if ($modo === 'todas') {
            return null;
        }

        return AvaliaAvaliacaoDisponivel::where('produto', $produto)
            ->where('selecionada', true)
            ->when($produto === 'avalia_pro', fn ($query) => $query->whereNotNull('pai_id'))
            ->pluck('id_externo')
            ->all();
    }

    private function chaveAvaliacao(string $produto, object $linha): string
    {
        return $produto === 'avalia_pro'
            ? "{$linha->assessment_id_avalia_pro}:{$linha->subject_sk}"
            : (string) $linha->questionnaire_id_avalia_online;
    }

    /**
     * Cria/atualiza as avaliações referenciadas nas linhas de nota — uma por
     * (prova, disciplina) no Avalia Pro, uma por questionário no Avalia
     * Online. Atualiza $mapaAvaliacoes (por referência) com os códigos novos.
     *
     * @param  array<string, int>  $mapaAvaliacoes
     */
    private function upsertAvaliacoes(string $produto, Collection $notas, array &$mapaAvaliacoes): int
    {
        $criadas = 0;

        foreach ($notas->unique(fn ($linha) => $this->chaveAvaliacao($produto, $linha)) as $linha) {
            $chave = $this->chaveAvaliacao($produto, $linha);

            $dados = $produto === 'avalia_pro'
                ? [
                    'nome' => trim("{$linha->assessment_name_avalia_pro} — {$linha->subject_name_avalia_pro}"),
                    'tipo' => $linha->exam_type_name_avalia_pro,
                ]
                : [
                    'nome' => $linha->questionnaire_name_avalia_online,
                    'tipo' => 'Avalia Online',
                ];

            $avaliacao = Avaliacao::updateOrCreate(
                ['origem' => $produto, 'id_externo' => $chave],
                $dados,
            );

            if ($avaliacao->wasRecentlyCreated) {
                $criadas++;
            }

            $mapaAvaliacoes[$chave] = $avaliacao->codigo;
        }

        return $criadas;
    }

    /**
     * @param  array<string, int>  $mapaAvaliacoes
     * @return array{gravadas: int, sem_identificador: int}
     */
    private function upsertMetricas(string $produto, Collection $notas, array $mapaAvaliacoes): array
    {
        $cpfs = $notas->pluck('cpf')->filter()->unique()->values()->all();
        $alunoIdPorCpf = $this->resolverAlunoIdsPorCpf($cpfs);

        $agora = now();
        $gravadas = 0;
        $semIdentificador = 0;

        foreach ($notas->chunk(self::TAMANHO_LOTE) as $lote) {
            $registros = [];

            foreach ($lote as $linha) {
                if ($linha->cpf === null) {
                    $semIdentificador++;

                    continue;
                }

                $chave = $this->chaveAvaliacao($produto, $linha);
                $avaliacaoCodigo = $mapaAvaliacoes[$chave] ?? null;
                if ($avaliacaoCodigo === null) {
                    continue;
                }

                $notaFinal = $produto === 'avalia_pro' ? $linha->final_grade : $linha->activity_final_grade;

                $registros[] = [
                    'avaliacao_codigo' => $avaliacaoCodigo,
                    'cpf' => $linha->cpf,
                    'ra' => null,
                    'periodo' => '',
                    'nome_metrica' => self::NOME_METRICA_NOTA_FINAL,
                    'valor' => $notaFinal !== null ? (string) $notaFinal : null,
                    'aluno_id' => $alunoIdPorCpf[$linha->cpf] ?? null,
                    'origem' => $produto,
                    'id_externo' => $chave,
                    'deleted_at' => null,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }

            if ($registros === []) {
                continue;
            }

            DB::table('resultado_metricas')->upsert(
                $registros,
                ['avaliacao_codigo', 'aluno_chave', 'periodo', 'nome_metrica'],
                ['valor', 'aluno_id', 'origem', 'id_externo', 'deleted_at', 'updated_at']
            );

            $gravadas += count($registros);
        }

        return ['gravadas' => $gravadas, 'sem_identificador' => $semIdentificador];
    }

    /** @param  array<string, int>  $mapaAvaliacoes */
    private function upsertQuestoes(string $produto, Collection $respostas, array $mapaAvaliacoes): int
    {
        $agora = now();
        $gravadas = 0;

        $porAvaliacao = $respostas->groupBy(fn ($linha) => $mapaAvaliacoes[$this->chaveAvaliacao($produto, $linha)] ?? null);

        foreach ($porAvaliacao as $avaliacaoCodigo => $linhasDaAvaliacao) {
            if ($avaliacaoCodigo === null) {
                continue;
            }

            $numeroPorIdExterno = $this->resolverNumerosQuestao($avaliacaoCodigo, $produto, $linhasDaAvaliacao);

            $registros = [];
            foreach ($linhasDaAvaliacao->unique('question_id') as $linha) {
                $idExterno = (string) $linha->question_id;

                $registros[] = [
                    'avaliacao_codigo' => $avaliacaoCodigo,
                    'numero' => $numeroPorIdExterno[$idExterno],
                    // Sem gabarito próprio: o Avalia já manda o veredito
                    // pronto por resposta (answer_status/question_user_grade)
                    // — ver AvaliaSyncService::upsertRespostas(). '-' é só
                    // para satisfazer a coluna NOT NULL do schema legado.
                    'gabarito' => '-',
                    'origem' => $produto,
                    'id_externo' => $idExterno,
                    'deleted_at' => null,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }

            foreach (array_chunk($registros, self::TAMANHO_LOTE) as $lote) {
                DB::table('questoes')->upsert(
                    $lote,
                    ['avaliacao_codigo', 'id_externo'],
                    ['deleted_at', 'updated_at']
                );
                $gravadas += count($lote);
            }
        }

        return $gravadas;
    }

    /**
     * O Avalia não expõe uma posição/ordinal da questão dentro da prova —
     * só o id dela. `questoes.numero` é obrigatório e único por avaliação,
     * então atribuímos um número sequencial determinístico (ordenado pelo
     * id externo) na primeira vez que a questão aparece, e preservamos o
     * número já atribuído nas sincronizações seguintes.
     *
     * @return array<string, int> id_externo da questão => numero
     */
    private function resolverNumerosQuestao(int $avaliacaoCodigo, string $produto, Collection $linhasDaAvaliacao): array
    {
        $existentes = DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacaoCodigo)
            ->whereNotNull('id_externo')
            ->pluck('numero', 'id_externo')
            ->all();

        $proximoNumero = $existentes === [] ? 1 : max($existentes) + 1;

        $idsExternos = $linhasDaAvaliacao->pluck('question_id')->unique()->sort()->values();

        foreach ($idsExternos as $idExterno) {
            $idExterno = (string) $idExterno;
            if (! isset($existentes[$idExterno])) {
                $existentes[$idExterno] = $proximoNumero++;
            }
        }

        return $existentes;
    }

    /**
     * @param  array<string, int>  $mapaAvaliacoes
     * @return array{gravadas: int, sem_identificador: int}
     */
    private function upsertRespostas(string $produto, Collection $respostas, array $mapaAvaliacoes): array
    {
        $cpfs = $respostas->pluck('cpf')->filter()->unique()->values()->all();
        $alunoIdPorCpf = $this->resolverAlunoIdsPorCpf($cpfs);

        $agora = now();
        $gravadas = 0;
        $semIdentificador = 0;

        $porAvaliacao = $respostas->groupBy(fn ($linha) => $mapaAvaliacoes[$this->chaveAvaliacao($produto, $linha)] ?? null);

        foreach ($porAvaliacao as $avaliacaoCodigo => $linhasDaAvaliacao) {
            if ($avaliacaoCodigo === null) {
                continue;
            }

            // Um SELECT por avaliação (não por linha) — os números já foram
            // atribuídos por upsertQuestoes() logo antes, no mesmo ciclo.
            $numeroPorIdExterno = DB::table('questoes')
                ->where('avaliacao_codigo', $avaliacaoCodigo)
                ->pluck('numero', 'id_externo');

            foreach ($linhasDaAvaliacao->chunk(self::TAMANHO_LOTE) as $lote) {
                $registros = [];

                foreach ($lote as $linha) {
                    if ($linha->cpf === null) {
                        $semIdentificador++;

                        continue;
                    }

                    $questaoNumero = $numeroPorIdExterno[(string) $linha->question_id] ?? null;
                    if ($questaoNumero === null) {
                        continue;
                    }

                    $registros[] = [
                        'avaliacao_codigo' => $avaliacaoCodigo,
                        'cpf' => $linha->cpf,
                        'ra' => null,
                        'periodo' => '',
                        'questao_numero' => $questaoNumero,
                        'resposta' => $this->respostaTexto($produto, $linha),
                        'aluno_id' => $alunoIdPorCpf[$linha->cpf] ?? null,
                        'origem' => $produto,
                        'id_externo' => (string) $linha->question_id,
                        'deleted_at' => null,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ];
                }

                if ($registros === []) {
                    continue;
                }

                DB::table('respostas')->upsert(
                    $registros,
                    ['avaliacao_codigo', 'aluno_chave', 'periodo', 'questao_numero'],
                    ['resposta', 'aluno_id', 'origem', 'id_externo', 'deleted_at', 'updated_at']
                );

                $gravadas += count($registros);
            }
        }

        return ['gravadas' => $gravadas, 'sem_identificador' => $semIdentificador];
    }

    /**
     * Avalia Pro manda a resposta escolhida; Avalia Online não expõe esse
     * texto (ver aviso em RedshiftAvaliaExtractor) — fica null, só a nota
     * (question_user_grade, gravada como métrica separada) fica disponível.
     */
    private function respostaTexto(string $produto, object $linha): ?string
    {
        return $produto === 'avalia_pro' ? $linha->question_answer : null;
    }

    /** @param  array<int, string>  $cpfs @return array<string, int> */
    private function resolverAlunoIdsPorCpf(array $cpfs): array
    {
        if ($cpfs === []) {
            return [];
        }

        return Aluno::whereIn('cpf', $cpfs)->orderBy('id')->pluck('id', 'cpf')->all();
    }

    private function watermark(string $produto, string $fonte): ?string
    {
        return ConfiguracaoSistema::valor("avalia_watermark_{$fonte}_{$produto}");
    }

    /**
     * Cada consulta do extractor já normaliza sua coluna de "última
     * atualização" (cdc_datetime, activity_finished_at ou
     * question_corrected_at, dependendo do produto/fonte) para `watermark`
     * — ver RedshiftAvaliaExtractor.
     */
    private function atualizarWatermark(string $produto, string $fonte, Collection $linhas): void
    {
        $maisRecente = $linhas->pluck('watermark')->filter()->max();

        if ($maisRecente !== null) {
            ConfiguracaoSistema::definir("avalia_watermark_{$fonte}_{$produto}", (string) $maisRecente);
        }
    }
}
