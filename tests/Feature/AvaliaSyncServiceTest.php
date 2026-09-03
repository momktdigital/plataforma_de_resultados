<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\AvaliaAvaliacaoDisponivel;
use App\Models\Avaliacao;
use App\Models\AvaliaSyncExecucao;
use App\Models\ConfiguracaoSistema;
use App\Models\ResultadoMetrica;
use App\Services\Avalia\AvaliaExtractorContract;
use App\Services\Avalia\AvaliaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

class AvaliaSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function alunoComCpf(string $cpf): Aluno
    {
        return Aluno::create(['ra' => 'RA'.$cpf, 'cpf' => $cpf, 'nome' => 'Aluno Teste']);
    }

    public function test_sincroniza_avalia_pro_cria_avaliacao_questoes_respostas_e_metrica(): void
    {
        $this->alunoComCpf('11122233344');
        ConfiguracaoSistema::definir('avalia_modo_avalia_pro', 'todas');

        $extractor = new FakeAvaliaExtractor(
            notas: new Collection([
                (object) [
                    'assessment_id_avalia_pro' => 100, 'subject_sk' => 7, 'final_grade' => 8.5,
                    'subject_grade' => 8.5, 'weight' => 1, 'questions_count' => 2,
                    'annulled_questions_count' => 0, 'exempted_questions_count' => 0,
                    'watermark' => '2026-09-01 10:00:00',
                    'assessment_name_avalia_pro' => 'Prova Integrada 1', 'exam_type_name_avalia_pro' => 'Regular',
                    'subject_name_avalia_pro' => 'Matemática', 'subject_external_id_avalia_pro' => 'MAT',
                    'cpf' => '11122233344',
                ],
            ]),
            respostas: new Collection([
                (object) [
                    'exam_sk' => 1, 'subject_sk' => 7, 'question_grade' => 1, 'question_weight' => 1,
                    'question_answer' => 'A', 'answer_status' => 'correta', 'watermark' => '2026-09-01 10:00:00',
                    'assessment_id_avalia_pro' => 100, 'question_id' => 501, 'question_text_avalia_pro' => 'Quanto é 2+2?',
                    'cpf' => '11122233344',
                ],
                (object) [
                    'exam_sk' => 1, 'subject_sk' => 7, 'question_grade' => 0, 'question_weight' => 1,
                    'question_answer' => 'C', 'answer_status' => 'errada', 'watermark' => '2026-09-01 10:00:00',
                    'assessment_id_avalia_pro' => 100, 'question_id' => 502, 'question_text_avalia_pro' => 'Quanto é 3+3?',
                    'cpf' => '11122233344',
                ],
            ]),
        );

        $service = new AvaliaSyncService($extractor);
        $execucao = $service->sincronizar('avalia_pro', AvaliaSyncExecucao::DISPARADO_MANUAL);

        $this->assertSame(AvaliaSyncExecucao::STATUS_SUCESSO, $execucao->status);

        $avaliacao = Avaliacao::where('origem', 'avalia_pro')->where('id_externo', '100:7')->firstOrFail();
        $this->assertSame('Prova Integrada 1 — Matemática', $avaliacao->nome);

        $this->assertDatabaseCount('questoes', 2);
        $this->assertDatabaseHas('questoes', ['avaliacao_codigo' => $avaliacao->codigo, 'id_externo' => '501', 'origem' => 'avalia_pro']);

        $this->assertDatabaseCount('respostas', 2);
        $this->assertDatabaseHas('respostas', ['avaliacao_codigo' => $avaliacao->codigo, 'cpf' => '11122233344', 'resposta' => 'A']);

        $metrica = ResultadoMetrica::where('avaliacao_codigo', $avaliacao->codigo)->firstOrFail();
        $this->assertSame('Nota Final', $metrica->nome_metrica);
        $this->assertSame('8.5', $metrica->valor);
        $this->assertNotNull($metrica->aluno_id);
    }

    public function test_sincronizar_de_novo_atualiza_em_vez_de_duplicar(): void
    {
        $this->alunoComCpf('11122233344');
        ConfiguracaoSistema::definir('avalia_modo_avalia_pro', 'todas');

        $notaInicial = (object) [
            'assessment_id_avalia_pro' => 100, 'subject_sk' => 7, 'final_grade' => 7.0,
            'subject_grade' => 7.0, 'weight' => 1, 'questions_count' => 1,
            'annulled_questions_count' => 0, 'exempted_questions_count' => 0, 'watermark' => '2026-09-01 10:00:00',
            'assessment_name_avalia_pro' => 'Prova Integrada 1', 'exam_type_name_avalia_pro' => 'Regular',
            'subject_name_avalia_pro' => 'Matemática', 'subject_external_id_avalia_pro' => 'MAT',
            'cpf' => '11122233344',
        ];

        $service = new AvaliaSyncService(new FakeAvaliaExtractor(new Collection([$notaInicial]), new Collection));
        $service->sincronizar('avalia_pro', AvaliaSyncExecucao::DISPARADO_MANUAL);

        $notaAtualizada = clone $notaInicial;
        $notaAtualizada->final_grade = 9.0;
        $notaAtualizada->watermark = '2026-09-02 10:00:00';

        $service2 = new AvaliaSyncService(new FakeAvaliaExtractor(new Collection([$notaAtualizada]), new Collection));
        $service2->sincronizar('avalia_pro', AvaliaSyncExecucao::DISPARADO_MANUAL);

        $this->assertDatabaseCount('avaliacoes', 1);
        $this->assertDatabaseCount('resultado_metricas', 1);
        $this->assertDatabaseHas('resultado_metricas', ['valor' => '9']);
    }

    public function test_avalia_online_grava_nota_sem_texto_de_resposta(): void
    {
        $this->alunoComCpf('55566677788');
        ConfiguracaoSistema::definir('avalia_modo_avalia_online', 'todas');

        $extractor = new FakeAvaliaExtractor(
            notas: new Collection([
                (object) [
                    'questionnaire_id_avalia_online' => 200, 'activity_attempt' => 1,
                    'activity_grade' => 6.0, 'activity_final_grade' => 6.0, 'watermark' => '2026-09-01 09:00:00',
                    'questionnaire_name_avalia_online' => 'Quiz de Português', 'cpf' => '55566677788',
                ],
            ]),
            respostas: new Collection([
                (object) [
                    'questionnaire_id_avalia_online' => 200, 'question_user_grade' => 1, 'question_has_answer' => true,
                    'watermark' => '2026-09-01 09:00:00', 'question_id' => 900,
                    'question_text_avalia_online' => 'Questão dissertativa', 'cpf' => '55566677788',
                ],
            ]),
        );

        $service = new AvaliaSyncService($extractor);
        $service->sincronizar('avalia_online', AvaliaSyncExecucao::DISPARADO_AGENDADO);

        $avaliacao = Avaliacao::where('origem', 'avalia_online')->where('id_externo', '200')->firstOrFail();
        $this->assertSame('Quiz de Português', $avaliacao->nome);

        $this->assertDatabaseHas('respostas', ['avaliacao_codigo' => $avaliacao->codigo, 'resposta' => null]);
        $this->assertDatabaseHas('resultado_metricas', ['avaliacao_codigo' => $avaliacao->codigo, 'valor' => '6']);
    }

    public function test_por_padrao_nao_sincroniza_nada_ate_uma_prova_ser_selecionada(): void
    {
        // Sem ConfiguracaoSistema::definir('avalia_modo_avalia_pro', ...) —
        // o padrão precisa ser seguro (nada sincroniza) numa instalação nova.
        $this->alunoComCpf('11122233344');

        $extractor = new FakeAvaliaExtractor(
            notas: new Collection([$this->notaProFake(100, 7)]),
            respostas: new Collection,
        );

        $service = new AvaliaSyncService($extractor);
        $execucao = $service->sincronizar('avalia_pro', AvaliaSyncExecucao::DISPARADO_MANUAL);

        $this->assertSame(AvaliaSyncExecucao::STATUS_SUCESSO, $execucao->status);
        $this->assertDatabaseCount('avaliacoes', 0);
        $this->assertDatabaseCount('resultado_metricas', 0);
    }

    public function test_modo_selecionadas_so_sincroniza_a_disciplina_marcada(): void
    {
        // A seleção real vive na folha (disciplina) — marcar só a prova
        // (pai_id null) não sincroniza nada, porque uma prova pode cobrir
        // dezenas de disciplinas em cursos diferentes (ver migration
        // 2026_09_06_100000). "100:7" é a disciplina marcada; "100:9" é
        // outra disciplina da MESMA prova que não foi marcada.
        $this->alunoComCpf('11122233344');
        ConfiguracaoSistema::definir('avalia_modo_avalia_pro', 'selecionadas');

        $prova = AvaliaAvaliacaoDisponivel::create(['produto' => 'avalia_pro', 'id_externo' => '100', 'nome' => 'Prova 100']);
        AvaliaAvaliacaoDisponivel::create(['produto' => 'avalia_pro', 'pai_id' => $prova->id, 'id_externo' => '100:7', 'nome' => 'Matemática', 'curso' => 'Enfermagem', 'selecionada' => true]);
        AvaliaAvaliacaoDisponivel::create(['produto' => 'avalia_pro', 'pai_id' => $prova->id, 'id_externo' => '100:9', 'nome' => 'Português', 'curso' => 'Enfermagem', 'selecionada' => false]);

        $extractor = new FakeAvaliaExtractor(
            notas: new Collection([$this->notaProFake(100, 7), $this->notaProFake(100, 9)]),
            respostas: new Collection,
        );

        (new AvaliaSyncService($extractor))->sincronizar('avalia_pro', AvaliaSyncExecucao::DISPARADO_MANUAL);

        $this->assertDatabaseCount('avaliacoes', 1);
        $this->assertDatabaseHas('avaliacoes', ['origem' => 'avalia_pro', 'id_externo' => '100:7']);
        $this->assertDatabaseMissing('avaliacoes', ['origem' => 'avalia_pro', 'id_externo' => '100:9']);
    }

    public function test_atualizar_catalogo_grava_provas_sem_sobrescrever_selecao_existente(): void
    {
        AvaliaAvaliacaoDisponivel::create(['produto' => 'avalia_pro', 'id_externo' => '100', 'nome' => 'Nome antigo', 'selecionada' => true]);

        $extractor = new FakeAvaliaExtractor(
            notas: new Collection,
            respostas: new Collection,
            provasDisponiveis: new Collection([
                (object) ['id_externo' => 100, 'nome' => 'Nome atualizado', 'tipo' => 'Regular', 'data_referencia' => '2026-03-01'],
                (object) ['id_externo' => 300, 'nome' => 'Prova nova', 'tipo' => 'Regular', 'data_referencia' => '2026-06-01'],
            ]),
        );

        $quantidade = (new AvaliaSyncService($extractor))->atualizarCatalogo('avalia_pro');

        $this->assertSame(2, $quantidade);

        // A prova já conhecida teve o nome atualizado, mas continua selecionada.
        $this->assertDatabaseHas('avalia_avaliacoes_disponiveis', [
            'produto' => 'avalia_pro', 'id_externo' => '100', 'nome' => 'Nome atualizado', 'selecionada' => true,
        ]);
        // A prova nova entra como não selecionada, por padrão.
        $this->assertDatabaseHas('avalia_avaliacoes_disponiveis', [
            'produto' => 'avalia_pro', 'id_externo' => '300', 'nome' => 'Prova nova', 'selecionada' => false,
        ]);
    }

    public function test_atualizar_catalogo_vincula_disciplinas_a_sua_prova(): void
    {
        // Uma prova real desta IES chegou a ter 89 disciplinas em 8 cursos
        // diferentes — o catálogo precisa refletir essa hierarquia (prova →
        // disciplina, com o curso como rótulo), não só uma lista achatada.
        $extractor = new FakeAvaliaExtractor(
            notas: new Collection,
            respostas: new Collection,
            provasDisponiveis: new Collection([
                (object) ['id_externo' => 100, 'nome' => 'SEMI - A1', 'tipo' => 'Regular', 'data_referencia' => '2026-03-01', 'pai_externo' => null, 'curso' => null],
                (object) ['id_externo' => '100:7', 'nome' => 'Matemática', 'pai_externo' => 100, 'curso' => 'Enfermagem'],
                (object) ['id_externo' => '100:9', 'nome' => 'Anatomia', 'pai_externo' => 100, 'curso' => 'Enfermagem'],
                (object) ['id_externo' => '100:12', 'nome' => 'Farmacologia', 'pai_externo' => 100, 'curso' => 'Farmácia'],
            ]),
        );

        $quantidade = (new AvaliaSyncService($extractor))->atualizarCatalogo('avalia_pro');

        $this->assertSame(4, $quantidade);

        $prova = AvaliaAvaliacaoDisponivel::where('produto', 'avalia_pro')->whereNull('pai_id')->firstOrFail();
        $this->assertSame('SEMI - A1', $prova->nome);

        $this->assertDatabaseCount('avalia_avaliacoes_disponiveis', 4);
        $this->assertDatabaseHas('avalia_avaliacoes_disponiveis', [
            'id_externo' => '100:7', 'pai_id' => $prova->id, 'curso' => 'Enfermagem', 'nome' => 'Matemática',
        ]);
        $this->assertDatabaseHas('avalia_avaliacoes_disponiveis', [
            'id_externo' => '100:12', 'pai_id' => $prova->id, 'curso' => 'Farmácia', 'nome' => 'Farmacologia',
        ]);

        $this->assertSame(3, $prova->disciplinas()->count());
    }

    private function notaProFake(int $assessmentId, int $subjectSk): object
    {
        return (object) [
            'assessment_id_avalia_pro' => $assessmentId, 'subject_sk' => $subjectSk, 'final_grade' => 8.0,
            'subject_grade' => 8.0, 'weight' => 1, 'questions_count' => 1,
            'annulled_questions_count' => 0, 'exempted_questions_count' => 0, 'watermark' => '2026-09-01 10:00:00',
            'assessment_name_avalia_pro' => "Prova {$assessmentId}", 'exam_type_name_avalia_pro' => 'Regular',
            'subject_name_avalia_pro' => 'Matemática', 'subject_external_id_avalia_pro' => 'MAT',
            'cpf' => '11122233344',
        ];
    }

    public function test_resetar_watermark_zera_notas_e_respostas_do_produto(): void
    {
        // Regressão real: um watermark avançado por uma seleção anterior (ou
        // por "todas") bloqueava pra sempre uma disciplina selecionada
        // depois, mesmo com dado real dela no Avalia — confirmado comparando
        // o watermark salvo com o cdc_datetime real da disciplina no
        // Redshift desta IES. Ver AvaliaSyncService::resetarWatermark().
        ConfiguracaoSistema::definir('avalia_watermark_notas_avalia_pro', '2026-09-02 20:37:41.953');
        ConfiguracaoSistema::definir('avalia_watermark_respostas_avalia_pro', '2026-09-02 20:37:41.957');
        ConfiguracaoSistema::definir('avalia_watermark_notas_avalia_online', '2026-09-02 20:00:00');

        (new AvaliaSyncService(new FakeAvaliaExtractor(new Collection, new Collection)))
            ->resetarWatermark('avalia_pro');

        $this->assertNull(ConfiguracaoSistema::valor('avalia_watermark_notas_avalia_pro'));
        $this->assertNull(ConfiguracaoSistema::valor('avalia_watermark_respostas_avalia_pro'));
        // Não mexe no watermark de outro produto.
        $this->assertNotNull(ConfiguracaoSistema::valor('avalia_watermark_notas_avalia_online'));
    }

    public function test_linhas_sem_cpf_sao_contadas_mas_nao_gravadas(): void
    {
        // Regressão do incidente real: dim_users.user_identity_document_integrate_module
        // veio nulo pra todo mundo no Avalia Pro desta IES — as questões
        // foram criadas (não dependem de CPF) mas nenhuma resposta/nota, sem
        // nenhum aviso na tela. Este teste garante que agora o número de
        // linhas descartadas fica visível em linhas_sem_identificador.
        ConfiguracaoSistema::definir('avalia_modo_avalia_pro', 'todas');

        $notaSemCpf = $this->notaProFake(100, 7);
        $notaSemCpf->cpf = null;

        $respostaSemCpf1 = (object) [
            'exam_sk' => 1, 'subject_sk' => 7, 'question_grade' => 1, 'question_weight' => 1,
            'question_answer' => 'A', 'answer_status' => 'correta', 'watermark' => '2026-09-01 10:00:00',
            'assessment_id_avalia_pro' => 100, 'question_id' => 501, 'question_text_avalia_pro' => 'Q1',
            'cpf' => null,
        ];
        $respostaSemCpf2 = (object) [
            'exam_sk' => 1, 'subject_sk' => 7, 'question_grade' => 0, 'question_weight' => 1,
            'question_answer' => 'B', 'answer_status' => 'errada', 'watermark' => '2026-09-01 10:00:00',
            'assessment_id_avalia_pro' => 100, 'question_id' => 502, 'question_text_avalia_pro' => 'Q2',
            'cpf' => null,
        ];

        $extractor = new FakeAvaliaExtractor(
            notas: new Collection([$notaSemCpf]),
            respostas: new Collection([$respostaSemCpf1, $respostaSemCpf2]),
        );

        $execucao = (new AvaliaSyncService($extractor))->sincronizar('avalia_pro', AvaliaSyncExecucao::DISPARADO_MANUAL);

        // A avaliação e as questões existem (não dependem de CPF)...
        $this->assertDatabaseCount('avaliacoes', 1);
        $this->assertDatabaseCount('questoes', 2);
        // ...mas nenhuma resposta/nota foi gravada, e isso fica visível no log.
        $this->assertDatabaseCount('respostas', 0);
        $this->assertDatabaseCount('resultado_metricas', 0);
        $this->assertSame(1 + 2, $execucao->linhas_sem_identificador);
    }

    public function test_nova_sincronizacao_autocorrige_execucao_travada_de_uma_tentativa_anterior(): void
    {
        // Regressão: se o worker morrer no meio de uma sincronização (kill
        // do processo, timeout do sistema operacional), o catch() de
        // sincronizar() nunca roda e a linha anterior fica presa em
        // 'processando'. Uma nova tentativa precisa limpar isso sozinha —
        // ver AvaliaSyncExecucao::marcarTravadasComoErro().
        $travada = AvaliaSyncExecucao::create([
            'produto' => 'avalia_pro',
            'status' => AvaliaSyncExecucao::STATUS_PROCESSANDO,
            'disparado_por' => AvaliaSyncExecucao::DISPARADO_MANUAL,
            'iniciado_em' => now()->subHours(2),
        ]);

        (new AvaliaSyncService(new FakeAvaliaExtractor(new Collection, new Collection)))
            ->sincronizar('avalia_pro', AvaliaSyncExecucao::DISPARADO_MANUAL);

        $this->assertSame(AvaliaSyncExecucao::STATUS_ERRO, $travada->fresh()->status);
        $this->assertNotNull($travada->fresh()->mensagem_erro);
    }

    public function test_execucao_registra_erro_e_relanca_quando_extrator_falha(): void
    {
        $service = new AvaliaSyncService(new FailingAvaliaExtractor);

        $this->expectException(RuntimeException::class);

        try {
            $service->sincronizar('avalia_pro', AvaliaSyncExecucao::DISPARADO_AGENDADO);
        } finally {
            $execucao = AvaliaSyncExecucao::first();
            $this->assertSame(AvaliaSyncExecucao::STATUS_ERRO, $execucao->status);
            $this->assertSame('Redshift indisponível', $execucao->mensagem_erro);
        }
    }
}

class FakeAvaliaExtractor implements AvaliaExtractorContract
{
    public function __construct(
        private readonly Collection $notas,
        private readonly Collection $respostas,
        private readonly Collection $provasDisponiveis = new Collection,
    ) {}

    public function testarConexao(): void {}

    public function notas(string $produto, ?string $desde, ?array $idsPermitidos): Collection
    {
        return $this->filtrarPorIdPermitido($this->notas, $idsPermitidos);
    }

    public function respostas(string $produto, ?string $desde, ?array $idsPermitidos): Collection
    {
        return $this->filtrarPorIdPermitido($this->respostas, $idsPermitidos);
    }

    public function listarProvasDisponiveis(string $produto): Collection
    {
        return $this->provasDisponiveis;
    }

    /**
     * Reproduz o whereIn(...) que RedshiftAvaliaExtractor aplica de verdade
     * (chave composta "{assessment_id}:{subject_sk}" pro Avalia Pro, já que
     * uma prova pode cobrir várias disciplinas; questionnaire_id sozinho pro
     * Avalia Online) — sem isso, os testes que dependem do filtro de seleção
     * passariam mesmo se AvaliaSyncService parasse de repassar
     * $idsPermitidos pro extractor, ou mesmo se a chave real estivesse errada.
     */
    private function filtrarPorIdPermitido(Collection $linhas, ?array $idsPermitidos): Collection
    {
        if ($idsPermitidos === null) {
            return $linhas;
        }

        return $linhas->filter(function ($linha) use ($idsPermitidos) {
            $id = isset($linha->assessment_id_avalia_pro)
                ? "{$linha->assessment_id_avalia_pro}:{$linha->subject_sk}"
                : ($linha->questionnaire_id_avalia_online ?? null);

            return $id !== null && in_array((string) $id, $idsPermitidos, true);
        })->values();
    }
}

class FailingAvaliaExtractor implements AvaliaExtractorContract
{
    public function testarConexao(): void {}

    public function notas(string $produto, ?string $desde, ?array $idsPermitidos): Collection
    {
        throw new RuntimeException('Redshift indisponível');
    }

    public function respostas(string $produto, ?string $desde, ?array $idsPermitidos): Collection
    {
        return new Collection;
    }

    public function listarProvasDisponiveis(string $produto): Collection
    {
        return new Collection;
    }
}
