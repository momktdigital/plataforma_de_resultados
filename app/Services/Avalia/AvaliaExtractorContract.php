<?php

namespace App\Services\Avalia;

use Illuminate\Support\Collection;

/**
 * Extração de dados do Avalia (+A Data / Redshift) — implementado por
 * RedshiftAvaliaExtractor. A interface existe para que
 * App\Services\Avalia\AvaliaSyncService (upsert/transformação) seja testável
 * sem depender de uma conexão de banco real — ver tests com um extractor
 * fake, mesmo padrão de App\Services\Backup\DatabaseDumperContract.
 */
interface AvaliaExtractorContract
{
    /** @throws \Throwable se a conexão falhar (host inacessível, credenciais inválidas etc.) */
    public function testarConexao(): void;

    /**
     * Notas por aluno × prova × disciplina ('avalia_pro') ou aluno ×
     * questionário × tentativa ('avalia_online'). Uma linha aqui vira uma
     * `avaliacoes` (uma por disciplina/questionário) + a métrica de nota
     * final em `resultado_metricas`.
     *
     * $idsPermitidos filtra pela prova/questionário inteiro (assessment_id ou
     * questionnaire_id — não a chave por disciplina): null = sem filtro
     * (todas as provas), [] = nenhuma (nada sincroniza), lista = só essas.
     * Ver App\Services\Avalia\AvaliaSyncService::idsPermitidos().
     *
     * @param  array<int, string>|null  $idsPermitidos
     * @return Collection<int, array<string, mixed>>
     */
    public function notas(string $produto, ?string $desde, ?array $idsPermitidos): Collection;

    /**
     * Respostas por aluno × prova/questionário × questão. Uma linha aqui
     * vira uma `respostas`. Para 'avalia_online' o texto da resposta pode
     * não estar disponível — ver RedshiftAvaliaExtractor::respostasOnline().
     * $idsPermitidos: ver notas().
     *
     * @param  array<int, string>|null  $idsPermitidos
     * @return Collection<int, array<string, mixed>>
     */
    public function respostas(string $produto, ?string $desde, ?array $idsPermitidos): Collection;

    /**
     * Lista leve (SELECT DISTINCT nas dimensões, não nas fatos) das
     * provas/questionários existentes no Avalia — usada para popular
     * App\Models\AvaliaAvaliacaoDisponivel, não para sincronizar dado de
     * aluno. Cada linha: id_externo, nome, tipo (nullable), data_referencia
     * (nullable).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listarProvasDisponiveis(string $produto): Collection;
}
