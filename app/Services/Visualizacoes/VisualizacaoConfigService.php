<?php

namespace App\Services\Visualizacoes;

use App\Models\Avaliacao;
use App\Models\AvaliacaoVisualizacao;

/**
 * Estado efetivo de cada visual pra uma avaliação: cruza a preferência salva
 * (AvaliacaoVisualizacao) com a disponibilidade real dos dados
 * (VisualizacaoDisponibilidadeService) — um visual só fica "visível" se as
 * duas coisas forem verdadeiras. Isso garante que mesmo que uma preferência
 * antiga tenha ficado marcada como visível e os dados tenham sido apagados
 * depois (ex.: período de resultados excluído), o visual some sozinho em vez
 * de renderizar em cima de dado inexistente.
 */
class VisualizacaoConfigService
{
    public function __construct(
        private readonly VisualizacaoDisponibilidadeService $disponibilidadeService,
    ) {}

    /**
     * Um visual sem preferência salva ainda (nenhuma linha em
     * `avaliacao_visualizacoes`) fica visível por padrão sempre que os dados
     * existirem — reflete o comportamento histórico do sistema, onde
     * histograma/radar/Top5/boletim sempre apareciam automaticamente, sem
     * etapa de configuração. A tela de "Configurar visualizações" serve pra
     * quem quer CURAR (desligar um visual específico numa avaliação), não pra
     * exigir que cada avaliação seja configurada manualmente antes de mostrar
     * qualquer coisa. Uma vez que o admin salva o formulário, a preferência
     * explícita (inclusive "desmarcado") passa a valer.
     *
     * @return array<string, array{label: string, grupo: string, admin: bool, aluno: bool, disponivel: bool, pendencia: ?string, visivelAdmin: bool, visivelAluno: bool}>
     */
    public function estadoCompleto(Avaliacao $avaliacao): array
    {
        $disponibilidade = $this->disponibilidadeService->calcular($avaliacao);
        $salvos = $avaliacao->visualizacoes()->get()->keyBy('visual');

        $estado = [];
        foreach (VisualCatalog::todos() as $chave => $definicao) {
            $disp = $disponibilidade[$chave] ?? ['disponivel' => false, 'pendencia' => null];
            $salvo = $salvos->get($chave);

            $estado[$chave] = [
                ...$definicao,
                'disponivel' => $disp['disponivel'],
                'pendencia' => $disp['pendencia'],
                'visivelAdmin' => $disp['disponivel'] && $definicao['admin'] && (bool) ($salvo?->visivel_admin ?? true),
                'visivelAluno' => $disp['disponivel'] && $definicao['aluno'] && (bool) ($salvo?->visivel_aluno ?? true),
            ];
        }

        return $estado;
    }

    /**
     * Salva a preferência marcada pelo admin — mas nunca grava como visível
     * um visual que não está disponível (defesa em profundidade: mesmo que
     * alguém force um POST manual marcando um visual desabilitado na tela).
     *
     * @param  array<string, array{admin?: bool, aluno?: bool}>  $entrada
     */
    public function salvar(Avaliacao $avaliacao, array $entrada): void
    {
        $disponibilidade = $this->disponibilidadeService->calcular($avaliacao);

        foreach (VisualCatalog::todos() as $chave => $definicao) {
            $disponivel = $disponibilidade[$chave]['disponivel'] ?? false;
            $marcado = $entrada[$chave] ?? [];

            AvaliacaoVisualizacao::updateOrCreate(
                ['avaliacao_codigo' => $avaliacao->codigo, 'visual' => $chave],
                [
                    'visivel_admin' => $disponivel && $definicao['admin'] && ! empty($marcado['admin']),
                    'visivel_aluno' => $disponivel && $definicao['aluno'] && ! empty($marcado['aluno']),
                ],
            );
        }
    }
}
