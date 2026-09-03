<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\AvaliaSyncExecucao;
use App\Models\Questao;
use App\Models\Resposta;
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

    public function test_execucao_registra_erro_e_relanca_quando_extrator_falha(): void
    {
        $extractor = new class implements AvaliaExtractorContract
        {
            public function testarConexao(): void {}

            public function notas(string $produto, ?string $desde): Collection
            {
                throw new RuntimeException('Redshift indisponível');
            }

            public function respostas(string $produto, ?string $desde): Collection
            {
                return new Collection;
            }
        };

        $service = new AvaliaSyncService($extractor);

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
    ) {}

    public function testarConexao(): void {}

    public function notas(string $produto, ?string $desde): Collection
    {
        return $this->notas;
    }

    public function respostas(string $produto, ?string $desde): Collection
    {
        return $this->respostas;
    }
}
