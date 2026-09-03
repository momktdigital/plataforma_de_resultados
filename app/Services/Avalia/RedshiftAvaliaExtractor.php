<?php

namespace App\Services\Avalia;

use App\Models\ConfiguracaoSistema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lê do +A Data (Redshift do Avalia) via a connection 'redshift' (somente
 * leitura — ver config/database.php). Consultas montadas a partir do catálogo
 * de tabelas documentado pelo Avalia (dbt docs em
 * docs.customer.data.grupoa.education) para o schema `mart_academic`
 * (fatos) e `dimensions` (dimensões).
 *
 * IMPORTANTE — validar contra dado real antes de confiar cegamente:
 * 1. tenant_sk/environment_sk vêm de ConfiguracaoSistema (avalia_tenant_sk /
 *    avalia_environment_sk) — já confirmados via `dim_tenants`/`dim_environments`
 *    no Redshift real desta IES: um único tenant_sk (UNIFAA), com DOIS
 *    environment_sk (um por campus/polo — "unifaa_prod" e "arcoverde").
 *    `avalia_environment_sk` guarda os dois separados por vírgula; ver
 *    environmentSks().
 * 2. Para 'avalia_online', `fct_activities_questions_avalia` não expõe o
 *    texto da resposta (só a nota da questão) — respostasOnline() grava
 *    `resposta` como null nesse caso; só a nota chega em `resultado_metricas`
 *    (ver notasOnline()). Confirmar se existe outra fonte liberada para o
 *    nosso papel de acesso antes de considerar isso definitivo.
 * 3. O identificador do aluno usado aqui é sempre o CPF
 *    (dim_users.user_identity_document_integrate_module) — vem do mesmo
 *    sistema acadêmico que já alimenta `alunos.cpf`. Não usamos RA daqui
 *    porque o join entre dim_users e dim_enrollments (que tem o RA) não está
 *    confirmado; se o CPF sozinho não for suficiente na prática, revisitar.
 */
class RedshiftAvaliaExtractor implements AvaliaExtractorContract
{
    private const CONNECTION = 'redshift';

    public function testarConexao(): void
    {
        $this->tenantSk();
        $this->environmentSks();

        DB::connection(self::CONNECTION)->select('select 1');
    }

    public function notas(string $produto, ?string $desde): Collection
    {
        return match ($produto) {
            'avalia_pro' => $this->notasPro($desde),
            'avalia_online' => $this->notasOnline($desde),
            default => throw new RuntimeException("Produto desconhecido: {$produto}"),
        };
    }

    public function respostas(string $produto, ?string $desde): Collection
    {
        return match ($produto) {
            'avalia_pro' => $this->respostasPro($desde),
            'avalia_online' => $this->respostasOnline($desde),
            default => throw new RuntimeException("Produto desconhecido: {$produto}"),
        };
    }

    /**
     * Grão: aluno × prova × disciplina — cada linha vira uma `avaliacoes`
     * (id_externo = "{assessment_id}:{subject_sk}") + uma métrica de nota
     * final em `resultado_metricas`.
     */
    private function notasPro(?string $desde): Collection
    {
        return DB::connection(self::CONNECTION)
            ->table('mart_academic.fct_student_exam_subject_scores_avalia_pro as s')
            ->join('dimensions.dim_exams as e', 'e.exam_sk', '=', 's.exam_sk')
            ->join('dimensions.dim_subjects as subj', 'subj.subject_sk', '=', 's.subject_sk')
            ->join('dimensions.dim_users as u', 'u.user_sk', '=', 's.user_sk')
            ->where('s.tenant_sk', $this->tenantSk())
            ->whereIn('s.environment_sk', $this->environmentSks())
            ->when($desde !== null, fn ($query) => $query->where('s.cdc_datetime', '>', $desde))
            ->select([
                's.exam_sk', 's.subject_sk', 's.final_grade', 's.subject_grade', 's.weight',
                's.questions_count', 's.annulled_questions_count', 's.exempted_questions_count',
                's.cdc_datetime as watermark',
                'e.assessment_id_avalia_pro', 'e.assessment_name_avalia_pro', 'e.exam_type_name_avalia_pro',
                'subj.subject_name_avalia_pro', 'subj.subject_external_id_avalia_pro',
                'u.user_identity_document_integrate_module as cpf',
            ])
            ->get();
    }

    /** Grão: aluno × prova × questão — cada linha vira uma `respostas`. */
    private function respostasPro(?string $desde): Collection
    {
        return DB::connection(self::CONNECTION)
            ->table('mart_academic.fct_student_exam_questions_avalia_pro as q')
            ->join('dimensions.dim_exams as e', 'e.exam_sk', '=', 'q.exam_sk')
            ->join('dimensions.dim_questions as dq', 'dq.question_sk', '=', 'q.question_sk')
            ->join('dimensions.dim_users as u', 'u.user_sk', '=', 'q.user_sk')
            ->where('q.tenant_sk', $this->tenantSk())
            ->whereIn('q.environment_sk', $this->environmentSks())
            ->when($desde !== null, fn ($query) => $query->where('q.cdc_datetime', '>', $desde))
            ->select([
                'q.exam_sk', 'q.subject_sk', 'q.question_grade', 'q.question_weight',
                'q.question_answer', 'q.answer_status', 'q.cdc_datetime as watermark',
                'e.assessment_id_avalia_pro',
                'dq.question_id_avalia_pro as question_id', 'dq.question_text_avalia_pro',
                'u.user_identity_document_integrate_module as cpf',
            ])
            ->get();
    }

    /**
     * Grão: aluno × questionário × tentativa. `questionnaire_type_avalia_online`
     * filtra para trazer só avaliações (não pesquisas/formulários).
     */
    private function notasOnline(?string $desde): Collection
    {
        return DB::connection(self::CONNECTION)
            ->table('mart_academic.fct_activities_avalia as a')
            ->join('dimensions.dim_questionnaires as qn', 'qn.questionnaire_sk', '=', 'a.questionnaire_sk')
            ->join('dimensions.dim_users as u', 'u.user_sk', '=', 'a.user_sk')
            ->where('a.tenant_sk', $this->tenantSk())
            ->whereIn('a.environment_sk', $this->environmentSks())
            ->where('a.activity_is_deleted', false)
            ->where('qn.questionnaire_type_avalia_online', 'avaliacao')
            ->when($desde !== null, fn ($query) => $query->where('a.activity_finished_at', '>', $desde))
            ->select([
                'a.questionnaire_sk', 'a.activity_attempt', 'a.activity_grade', 'a.activity_final_grade',
                'a.activity_finished_at as watermark',
                'qn.questionnaire_id_avalia_online', 'qn.questionnaire_name_avalia_online',
                'u.user_identity_document_integrate_module as cpf',
            ])
            ->get();
    }

    /**
     * Grão: aluno × questionário × questão. Não traz `question_answer` — a
     * fato do Avalia Online não expõe o texto da resposta, só a nota (ver
     * aviso na docblock da classe).
     */
    private function respostasOnline(?string $desde): Collection
    {
        return DB::connection(self::CONNECTION)
            ->table('mart_academic.fct_activities_questions_avalia as aq')
            ->join('dimensions.dim_questionnaires as qn', 'qn.questionnaire_sk', '=', 'aq.questionnaire_sk')
            ->join('dimensions.dim_questions as dq', 'dq.question_sk', '=', 'aq.question_sk')
            ->join('dimensions.dim_users as u', 'u.user_sk', '=', 'aq.user_sk')
            ->where('aq.tenant_sk', $this->tenantSk())
            ->whereIn('aq.environment_sk', $this->environmentSks())
            ->where('aq.activity_is_deleted', false)
            ->where('aq.question_is_deleted', false)
            ->when($desde !== null, fn ($query) => $query->where('aq.question_corrected_at', '>', $desde))
            ->select([
                'aq.questionnaire_sk', 'aq.question_user_grade', 'aq.question_has_answer',
                'aq.question_corrected_at as watermark',
                'qn.questionnaire_id_avalia_online',
                'dq.question_id_avalia_online as question_id', 'dq.question_text_avalia_online',
                'u.user_identity_document_integrate_module as cpf',
            ])
            ->get();
    }

    private function tenantSk(): string
    {
        return ConfiguracaoSistema::valor('avalia_tenant_sk')
            ?? throw new RuntimeException('Configure o "tenant" do Avalia na tela de Integração antes de sincronizar.');
    }

    /**
     * Um tenant pode ter mais de um campus/polo (cada um com seu próprio
     * environment_sk) — `avalia_environment_sk` guarda todos separados por
     * vírgula (ex.: "5558077742612549577,6023501672592045297") e todos são
     * sincronizados juntos.
     *
     * @return array<int, string>
     */
    private function environmentSks(): array
    {
        $valor = ConfiguracaoSistema::valor('avalia_environment_sk')
            ?? throw new RuntimeException('Configure o(s) "ambiente(s)" do Avalia na tela de Integração antes de sincronizar.');

        $sks = array_values(array_filter(array_map('trim', explode(',', $valor))));

        if ($sks === []) {
            throw new RuntimeException('Configure o(s) "ambiente(s)" do Avalia na tela de Integração antes de sincronizar.');
        }

        return $sks;
    }
}
