<?php

namespace App\Support\Concerns;

use App\Support\LimitesUpload;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Extraído de Admin\Sistema\LegadoController (primeiro import que precisou
 * disso): qualquer import de planilha/arquivo processado linha a linha pode
 * levar milhares de queries — sem isso, o `max_execution_time` padrão do PHP
 * (30s em muitas instalações, ex.: XAMPP/hospedagem compartilhada) derruba a
 * requisição no meio de uma transação aberta, com um 500 sem nenhuma
 * mensagem útil (e a transação presa segurando locks).
 *
 * Usada também pelos Jobs de import (Questão/Resultado/Matrícula): mesmo
 * rodando fora do ciclo de vida de uma requisição HTTP, o worker da fila
 * ainda herda o `memory_limit` padrão do php.ini — uma planilha XLSX grande
 * (PhpSpreadsheet carrega tudo na memória, ver SpreadsheetReader) pode
 * estourar isso e deixar a MESMA transação aberta órfã.
 */
trait PermiteImportacaoLonga
{
    private function permitirExecucaoLonga(): void
    {
        set_time_limit(0);

        // 1G, não 512M: medido neste projeto, o teto de
        // SpreadsheetReader::MAX_CELULAS_XLSX (450.000 células) no pior caso
        // realista — 150.000 linhas x 3 colunas, formato "longo" de
        // resultados — chega a ~720MB de pico rodando o import inteiro
        // (leitura + resolução de aluno + upsert em lote). 512M não dava
        // margem nenhuma pra isso.
        if (LimitesUpload::paraBytes(ini_get('memory_limit') ?: '128M') < LimitesUpload::paraBytes('1G')) {
            ini_set('memory_limit', '1G');
        }
    }

    /**
     * Um erro fatal de "tempo máximo de execução excedido" pula direto para o
     * encerramento do script — o try/catch do import nunca roda, e uma
     * transação aberta ficaria presa segurando locks. Isso aqui é a rede de
     * segurança: mesmo nesse cenário, o shutdown do PHP ainda executa, então
     * garantimos o rollback por aqui.
     */
    private function protegerContraTransacaoOrfa(): void
    {
        // Pega a conexão agora (objeto PHP concreto) em vez de resolver via
        // facade dentro do closure: no shutdown, o container da aplicação já
        // pode ter sido derrubado (ex.: fim dos testes), e resolver `DB::`
        // nesse momento lançaria um erro fatal próprio em vez de proteger nada.
        $conexao = DB::connection();

        register_shutdown_function(function () use ($conexao) {
            try {
                if ($conexao->transactionLevel() > 0) {
                    while ($conexao->transactionLevel() > 0) {
                        $conexao->rollBack();
                    }

                    // error_log() nativo, não o facade Log:: — o container da
                    // aplicação pode já ter sido derrubado neste ponto.
                    error_log('[import] Transação aberta no encerramento da requisição — revertida (provável timeout).');
                }
            } catch (Throwable) {
                // Conexão pode já estar inutilizável neste ponto; nada mais a fazer.
            }
        });
    }
}
