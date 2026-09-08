<?php

namespace App\Services\Avalia;

use App\Models\Aluno;
use App\Models\AvaliaAvaliacaoDisponivel;
use App\Models\Avaliacao;
use App\Models\AvaliaSyncExecucao;
use App\Models\ConfiguracaoSistema;
use App\Services\ResumoResultadoService;
use App\Support\Anulacao;
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

    public function __construct(
        private readonly AvaliaExtractorContract $extractor = new RedshiftAvaliaExtractor,
        private readonly ResumoResultadoService $resumos = new ResumoResultadoService,
    ) {}

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

            $notas = $this->normalizarCpfs($this->extractor->notas($produto, $this->watermark($produto, 'notas'), $idsPermitidos));
            $novasAvaliacoes = $this->upsertAvaliacoes($produto, $notas, $mapaAvaliacoes);
            ['gravadas' => $metricasGravadas, 'sem_identificador' => $metricasSemId] = $this->upsertMetricas($produto, $notas, $mapaAvaliacoes);
            $this->atualizarWatermark($produto, 'notas', $notas);

            $respostas = $this->normalizarCpfs($this->extractor->respostas($produto, $this->watermark($produto, 'respostas'), $idsPermitidos));
            $questoesGravadas = $this->upsertQuestoes($produto, $respostas, $mapaAvaliacoes);
            ['gravadas' => $respostasGravadas, 'sem_identificador' => $respostasSemId] = $this->upsertRespostas($produto, $respostas, $mapaAvaliacoes);
            $this->atualizarWatermark($produto, 'respostas', $respostas);

            // Sem isto, resultado_resumos (acertos/total/ausente/percentual
            // pré-calculados — ver ResumoResultadoService) nunca é gerado
            // pra avaliação sincronizada do Avalia: o boletim ficava sem o
            // badge de acertos, sem detecção de ausente e sem refletir um
            // gabarito recém-resolvido, mesmo com respostas/questoes
            // corretas gravadas.
            $this->recalcularAvaliacoesAfetadas($produto, $notas, $respostas, $mapaAvaliacoes);

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

    /**
     * Recalcula resultado_resumos (ResumoResultadoService) pra toda
     * avaliação tocada nesta sincronização — uma por chaveAvaliacao()
     * distinta entre $notas e $respostas, nunca a tabela toda.
     *
     * @param  array<string, int>  $mapaAvaliacoes
     */
    private function recalcularAvaliacoesAfetadas(string $produto, Collection $notas, Collection $respostas, array $mapaAvaliacoes): void
    {
        $notas->map(fn ($linha) => $mapaAvaliacoes[$this->chaveAvaliacao($produto, $linha)] ?? null)
            ->merge($respostas->map(fn ($linha) => $mapaAvaliacoes[$this->chaveAvaliacao($produto, $linha)] ?? null))
            ->filter()
            ->unique()
            ->each(fn (int $avaliacaoCodigo) => $this->resumos->recalcular($avaliacaoCodigo));
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
                    // O Redshift devolve final_grade/activity_final_grade como
                    // NUMERIC (mais casas decimais do que o boletim exibe, ex.
                    // "1.9024390243902439") — number_format normaliza pra 2
                    // casas antes de gravar, evitando repetir esse
                    // arredondamento em cada view que mostra a nota.
                    'valor' => $notaFinal !== null ? number_format((float) $notaFinal, 2, '.', '') : null,
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

            // anulada_modo já gravado (se houver) — uma sincronização
            // incremental só vê as respostas NOVAS desde o último watermark,
            // que podem não incluir nenhuma 'Anulada' pra essa questão nesta
            // leva; sem isso, um resync incremental "esqueceria" uma
            // anulação já detectada por um lote anterior.
            $anuladasExistentes = $produto === 'avalia_pro'
                ? DB::table('questoes')->where('avaliacao_codigo', $avaliacaoCodigo)->whereNotNull('id_externo')->pluck('anulada_modo', 'id_externo')
                : collect();

            $anuladas = $produto === 'avalia_pro' ? $this->derivarAnuladasAvaliaPro($linhasDaAvaliacao) : [];

            $registros = [];
            foreach ($linhasDaAvaliacao->unique('question_id') as $linha) {
                $idExterno = (string) $linha->question_id;

                $registros[] = [
                    'avaliacao_codigo' => $avaliacaoCodigo,
                    'numero' => $numeroPorIdExterno[$idExterno],
                    // Sem gabarito comparável entre respondentes: o Avalia
                    // embaralha a ordem das alternativas por aluno
                    // (confirmado com dado real — a MESMA questão teve as 5
                    // letras diferentes marcadas como corretas por alunos
                    // diferentes), então não existe uma "letra certa" única
                    // pra gravar aqui. '-' só satisfaz a coluna NOT NULL do
                    // schema legado — o veredito de verdade vai em
                    // respostas.correta (ver upsertRespostas()/Anulacao).
                    'gabarito' => '-',
                    'anulada_modo' => $anuladas[$idExterno] ?? $anuladasExistentes->get($idExterno),
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
                    ['gabarito', 'anulada_modo', 'deleted_at', 'updated_at']
                );
                $gravadas += count($lote);
            }
        }

        return $gravadas;
    }

    /**
     * Uma questão com pelo menos uma resposta 'Anulada' é marcada
     * distribuir_pontuacao (sai do total pra todo mundo). Modo conservador
     * escolhido de propósito: o dado bruto não distingue, no nível da
     * questão, "anulada com crédito pra todos" de "isenta" (só há contadores
     * agregados annulled_questions_count/exempted_questions_count por
     * aluno×prova×disciplina, sem apontar QUAL questão é qual) — excluir do
     * total nunca credita nem culpa ninguém indevidamente, ao contrário de
     * assumir dar_ponto errado.
     *
     * @return array<string, string> anulada_modo por id_externo da questão
     */
    private function derivarAnuladasAvaliaPro(Collection $linhasDaAvaliacao): array
    {
        $anuladas = [];

        foreach ($linhasDaAvaliacao->groupBy(fn ($linha) => (string) $linha->question_id) as $idExterno => $linhasDaQuestao) {
            if ($linhasDaQuestao->contains('answer_status', 'Anulada')) {
                $anuladas[$idExterno] = Anulacao::MODO_DISTRIBUIR_PONTUACAO;
            }
        }

        return $anuladas;
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
                        'correta' => $this->corretaVeredito($produto, $linha),
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
                    ['resposta', 'correta', 'aluno_id', 'origem', 'id_externo', 'deleted_at', 'updated_at']
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

    /**
     * Veredito pronto por resposta — grava direto em `respostas.correta`, em
     * vez de depender de comparar `resposta` com um gabarito (ver
     * Anulacao::acertou()). Necessário porque o Avalia embaralha a ordem das
     * alternativas por aluno: a letra que ele marcou não é comparável com a
     * de outro aluno pra mesma questão, então só o `answer_status` que o
     * próprio Avalia já calculou é confiável aqui.
     *
     * null pra 'Errada'/'Correta' ausentes (aluno não respondeu — sem
     * veredito objetivo) e pra 'Anulada' (a questão inteira sai do cálculo
     * via anulada_modo=distribuir_pontuacao, ver derivarAnuladasAvaliaPro();
     * o veredito individual não importa nesse caso). Avalia Online não tem
     * equivalente (nem answer_status nem o texto da resposta — ver aviso na
     * docblock da classe do extractor), então fica sempre null.
     */
    private function corretaVeredito(string $produto, object $linha): ?bool
    {
        if ($produto !== 'avalia_pro') {
            return null;
        }

        return match ($linha->answer_status ?? null) {
            'Correta' => true,
            'Errada' => false,
            default => null,
        };
    }

    /**
     * Sanitiza o CPF vindo do Avalia antes de qualquer uso — a fonte real
     * (dim_users.user_username_safe_a, ver docblock de RedshiftAvaliaExtractor)
     * é o login de outro produto (Safe A), não um CPF validado como tal.
     * Mesma limpeza do import manual (ResultadoImportService::normalizarLinhas):
     * só dígitos, exatamente 11 — qualquer coisa fora disso vira null (linha
     * sem identificador, descartada como se o Avalia não tivesse mandado
     * CPF nenhum) em vez de gravar lixo em `respostas.cpf`/`resultado_metricas.cpf`.
     */
    private function normalizarCpfs(Collection $linhas): Collection
    {
        return $linhas->each(function (object $linha) {
            $cpfLimpo = $linha->cpf !== null ? preg_replace('/\D/', '', $linha->cpf) : null;
            $linha->cpf = ($cpfLimpo !== null && strlen($cpfLimpo) === 11) ? $cpfLimpo : null;
        });
    }

    /** @param  array<int, string>  $cpfs @return array<string, int> */
    private function resolverAlunoIdsPorCpf(array $cpfs): array
    {
        if ($cpfs === []) {
            return [];
        }

        return Aluno::whereIn('cpf', $cpfs)->orderBy('id')->pluck('id', 'cpf')->all();
    }

    /**
     * Zera o watermark de um produto — precisa ser chamado sempre que a
     * seleção de provas/disciplinas mudar (ver
     * IntegracaoAvaliaController::atualizarSelecao()).
     *
     * Incidente real que motivou isto: o watermark avança pra "a data mais
     * recente vista na última sincronização", sem distinguir de QUAL prova/
     * disciplina veio esse dado. Um sync amplo (ex.: modo 'todas', ou uma
     * seleção diferente) pode empurrar o watermark pra depois da última
     * atualização de uma disciplina que só foi selecionada DEPOIS — como
     * essa disciplina nunca mais vai ter `cdc_datetime > watermark`, ela
     * fica bloqueada pra sempre, silenciosamente (sync "com sucesso", 0
     * linhas). Zerar ao salvar a seleção força a próxima sincronização a
     * buscar tudo de novo pro que estiver selecionado agora — mais caro uma
     * vez, mas correto; upsert já é idempotente, então rebuscar dado que já
     * existe não duplica nada.
     */
    public function resetarWatermark(string $produto): void
    {
        ConfiguracaoSistema::definir("avalia_watermark_notas_{$produto}", null);
        ConfiguracaoSistema::definir("avalia_watermark_respostas_{$produto}", null);
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
