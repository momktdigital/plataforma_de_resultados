<?php

namespace App\Services;

use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;
use App\Models\PlanoAcaoEvento;

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

    /** Dias que um rascunho pode ficar parado antes de o coordenador ser lembrado. */
    public const DIAS_RASCUNHO = 14;

    /** Dias que um plano devolvido para ajustes pode esperar o coordenador. */
    public const DIAS_AJUSTES = 7;

    /** Prazo para o colaborador decidir um plano enviado: passado isso, ele recebe um resumo por e-mail (e a fila marca em vermelho). */
    public const PRAZO_ANALISE_DIAS = 7;

    public function __construct(
        private readonly PlanoAcaoService $planos,
        private readonly PlanoAcaoEmailService $email,
    ) {}

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

        return $total + $this->rascunhosParados() + $this->ajustesParados() + $this->analisesAtrasadas();
    }

    /** Rascunho sem mexer há DIAS_RASCUNHO dias: lembra o coordenador de terminar ou excluir. */
    private function rascunhosParados(): int
    {
        $total = 0;
        foreach (PlanoAcao::where('status', PlanoAcao::RASCUNHO)->where('updated_at', '<=', now()->subDays(self::DIAS_RASCUNHO))->get() as $plano) {
            $total += $this->planos->avisar(
                $plano, 'plano_prazo', 'Rascunho de plano parado',
                "O rascunho “{$plano->origem_rotulo}” está parado há ".self::DIAS_RASCUNHO.' dias ou mais. Termine e envie, ou exclua se não for mais necessário.',
                "plano:{$plano->id}:rascunho:".now()->format('Y-m'), false,
            );
        }

        return $total;
    }

    /** Plano devolvido para ajustes que o coordenador ainda não reenviou. */
    private function ajustesParados(): int
    {
        $total = 0;
        foreach (PlanoAcao::where('status', PlanoAcao::AJUSTES)->where('decidido_em', '<=', now()->subDays(self::DIAS_AJUSTES))->get() as $plano) {
            $total += $this->planos->avisar(
                $plano, 'plano_prazo', 'Plano aguardando os seus ajustes',
                "O plano “{$plano->origem_rotulo}” foi devolvido há mais de ".self::DIAS_AJUSTES.' dias e espera os ajustes pedidos pelo colaborador.',
                "plano:{$plano->id}:ajustes:".now()->format('Y-m'), false,
            );
        }

        return $total;
    }

    /**
     * Planos esperando análise além do prazo: um resumo por e-mail para os colaboradores. Cada plano é lembrado no máximo uma vez
     * a cada PRAZO_ANALISE_DIAS dias (o lembrete fica no histórico do plano).
     */
    private function analisesAtrasadas(): int
    {
        $atrasados = PlanoAcao::where('status', PlanoAcao::EM_ANALISE)->where('enviado_em', '<=', now()->subDays(self::PRAZO_ANALISE_DIAS))->with('eventos')->get()
            ->filter(function (PlanoAcao $p) {
                $ultimo = $p->eventos->first(fn ($e) => $e->tipo === PlanoAcaoEvento::LEMBRETE);

                return $ultimo === null || $ultimo->created_at->lte(now()->subDays(self::PRAZO_ANALISE_DIAS));
            })->values();

        if ($atrasados->isEmpty()) {
            return 0;
        }

        $this->email->analiseAtrasada($atrasados, self::PRAZO_ANALISE_DIAS);
        foreach ($atrasados as $plano) {
            PlanoAcaoEvento::create(['plano_id' => $plano->id, 'admin_id' => null, 'tipo' => PlanoAcaoEvento::LEMBRETE, 'texto' => 'Aguardando análise além do prazo de '.self::PRAZO_ANALISE_DIAS.' dias.']);
        }

        return $atrasados->count();
    }

    private function texto(PlanoAcao $plano, PlanoAcaoAcao $acao, string $quando): string
    {
        return '“'.str($acao->descricao)->limit(110).'” '.$quando.' — plano “'.$plano->origem_rotulo.'”.';
    }
}
