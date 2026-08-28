<?php

namespace App\Console\Commands;

use App\Services\AnonimizacaoAlunoService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Ponto de entrada operacional pra um pedido de exclusão/anonimização LGPD —
 * ver App\Services\AnonimizacaoAlunoService pro que de fato é apagado/trocado.
 * Deliberadamente só CLI (exige acesso ao servidor): não é uma ação de
 * autoatendimento nem de rotina — um pedido LGPD é raro e formal o bastante
 * pra não precisar de botão na tela, e destrutivo o bastante pra não ganhar
 * um.
 */
class AnonimizarAluno extends Command
{
    protected $signature = 'aluno:anonimizar {--ra=} {--cpf=}';

    protected $description = 'Anonimiza RA/CPF de um aluno em todo o histórico (respostas/métricas) e remove o cadastro de acesso — atende pedido de exclusão LGPD';

    public function handle(AnonimizacaoAlunoService $servico): int
    {
        $ra = $this->option('ra');
        $cpf = $this->option('cpf');

        if ($ra === null && $cpf === null) {
            $this->error('Informe --ra=... e/ou --cpf=... do aluno a anonimizar.');

            return self::FAILURE;
        }

        $identificacao = trim(($ra !== null ? "RA {$ra}" : '').($cpf !== null ? " CPF {$cpf}" : ''));

        if (! $this->confirm(
            "Isto vai substituir {$identificacao} por um token anônimo em todo o histórico de respostas/métricas ".
            'e apagar o cadastro de acesso. Não pode ser desfeito. Continuar?'
        )) {
            $this->info('Cancelado.');

            return self::SUCCESS;
        }

        try {
            $resultado = $servico->anonimizar($ra, $cpf, origemSemAuth: 'CLI: aluno:anonimizar');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(
            "Concluído — token {$resultado['token']}, ".
            "{$resultado['avaliacoes_afetadas']} avaliação(ões) recalculada(s)."
        );

        return self::SUCCESS;
    }
}
