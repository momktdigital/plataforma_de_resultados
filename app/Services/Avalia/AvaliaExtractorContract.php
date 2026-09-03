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
     * @return Collection<int, array<string, mixed>>
     */
    public function notas(string $produto, ?string $desde): Collection;

    /**
     * Respostas por aluno × prova/questionário × questão. Uma linha aqui
     * vira uma `respostas`. Para 'avalia_online' o texto da resposta pode
     * não estar disponível — ver RedshiftAvaliaExtractor::respostasOnline().
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function respostas(string $produto, ?string $desde): Collection;
}
