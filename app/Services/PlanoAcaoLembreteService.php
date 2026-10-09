<?php

namespace App\Services;

use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;

/**
 * Lembretes diários para o coordenador sobre os planos em execução: ação que vence nos próximos dias, ação com o prazo
 * vencido e plano sem nenhuma movimentação há muito tempo. Cada lembrete é criado UMA vez (idempotente pela chave): rodar
 * de novo não volta para "não lido" o que o coordenador já leu.
 */
class PlanoAcaoLembreteService
{
    /** Com quantos dias de antecedência avisar que uma ação vence. */
    public const DIAS_ANTES = 7;

    /** Dias sem nenhuma movimentação para considerar um plano em execução "parado". */
    public const DIAS_PARADO = 30;

    public function __construct(private readonly PlanoAcaoService $planos) {}

    /** @return int quantos avisos foram criados */
    public function gerar(): int
    {
        $total = 0;

        $emExecucao = PlanoAcao::where('status', PlanoAcao::APROVADO)->with(['acoes', 'eventos'])->get();

        foreach ($emExecucao as $plano) {
            foreach ($plano->acoes as $acao) {
                if (! $acao->estaAberta() || $acao->prazo === null) {
                    continue;
                }

                $dias = $acao->diasParaOPrazo();
                if ($dias < 0) {
                    $total += $this->planos->avisar($plano, 'plano_prazo', 'Ação com prazo vencido', $this->texto($plano, $acao, 'venceu em '.$acao->prazo->format('d/m/Y')), "plano:{$plano->id}:acao:{$acao->id}:vencida", false);
                } elseif ($dias <= self::DIAS_ANTES) {
                    $quando = $dias === 0 ? 'vence hoje' : ($dias === 1 ? 'vence amanhã' : "vence em {$dias} dias (".$acao->prazo->format('d/m/Y').')');
                    $total += $this->planos->avisar($plano, 'plano_prazo', 'Ação com prazo próximo', $this->texto($plano, $acao, $quando), "plano:{$plano->id}:acao:{$acao->id}:proxima", false);
                }
            }

            $ultima = $plano->ultimaMovimentacao();
            if ($ultima !== null && $ultima->diffInDays(now()) >= self::DIAS_PARADO && $plano->acoes->contains(fn (PlanoAcaoAcao $a) => $a->estaAberta())) {
                $total += $this->planos->avisar(
                    $plano, 'plano_prazo', 'Plano sem atualização',
                    "O plano “{$plano->origem_rotulo}” não tem nenhum registro há ".self::DIAS_PARADO.' dias ou mais. Registre o andamento das ações.',
                    "plano:{$plano->id}:parado:".now()->format('Y-m'), false,
                );
            }
        }

        return $total;
    }

    private function texto(PlanoAcao $plano, PlanoAcaoAcao $acao, string $quando): string
    {
        return '“'.str($acao->descricao)->limit(110).'” '.$quando.' — plano “'.$plano->origem_rotulo.'”.';
    }
}
