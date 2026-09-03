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
     * $idsPermitidos: null = sem filtro (todas as provas); [] = nenhuma
     * (nada sincroniza); lista = só essas. Pro Avalia Pro a chave é composta
     * ("{assessment_id}:{subject_sk}" — o mesmo id_externo de uma linha de
     * disciplina em listarProvasDisponiveis()), porque uma prova pode cobrir
     * várias disciplinas em cursos diferentes. Pro Avalia Online é só o
     * questionnaire_id. Ver App\Services\Avalia\AvaliaSyncService::idsPermitidos().
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
     * Lista leve das provas/questionários existentes no Avalia — usada para
     * popular App\Models\AvaliaAvaliacaoDisponivel, não para sincronizar
     * dado de aluno. Duas "linhagens" na mesma coleção, distinguidas por
     * `pai_externo`:
     * - `pai_externo` null: uma prova/questionário (id_externo, nome, tipo,
     *   data_referencia).
     * - `pai_externo` preenchido (só Avalia Pro): uma disciplina dentro da
     *   prova cujo id_externo é esse `pai_externo` — id_externo aqui é
     *   "{assessment_id}:{subject_sk}", nome é o nome da disciplina, `curso`
     *   é o rótulo de agrupamento. Avalia Online nunca tem essas linhas
     *   (um questionário já é uma unidade só, sem quebra por disciplina).
     *
     * @return Collection<int, object{id_externo: string, nome: ?string, tipo: ?string, data_referencia: ?string, pai_externo: ?string, curso: ?string}>
     */
    public function listarProvasDisponiveis(string $produto): Collection;
}
