<?php

namespace App\Services\Visualizacoes;

/**
 * Lista fixa de todos os visuais (gráficos/painéis) que podem ser mostrados
 * para o aluno (boletim do Portal) e/ou para o administrativo (painel BI da
 * avaliação). Cada chave aqui é o `visual` gravado em `avaliacao_visualizacoes`
 * — nunca reordene ou renomeie uma chave existente sem migrar os dados
 * salvos, senão a preferência configurada por qualquer avaliação se perde
 * silenciosamente.
 *
 * `admin`/`aluno` indicam pra qual(is) público(s) aquele visual faz sentido
 * (e portanto se a coluna de toggle correspondente aparece na tela de
 * configuração) — não indicam se ele está disponível agora, isso é sempre
 * responsabilidade de VisualizacaoDisponibilidadeService.
 */
final class VisualCatalog
{
    public const GRUPO_ADMIN = 'Painel administrativo (BI)';

    public const GRUPO_ALUNO = 'Boletim do aluno (Portal)';

    /** @return array<string, array{label: string, grupo: string, admin: bool, aluno: bool}> */
    public static function todos(): array
    {
        return [
            'histograma' => [
                'label' => 'Distribuição de acertos (histograma)',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'top5' => [
                'label' => 'Top 5 melhores desempenhos',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'questoes_criticas' => [
                'label' => 'Questões críticas (maior taxa de erro)',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'ranking_completo' => [
                'label' => 'Ranking completo de respondentes',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'distribuicao_turma' => [
                'label' => 'Distribuição de notas por turma',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'curva_dificuldade' => [
                'label' => 'Dificuldade pedagógica esperada x observada',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'dispersao_tri' => [
                'label' => 'Dispersão TRI x taxa de acerto observada',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'heatmap_habilidade_turma' => [
                'label' => 'Mapa de calor: habilidade x turma',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'perfil_demografico' => [
                'label' => 'Perfil demográfico dos respondentes',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'analise_alternativas' => [
                'label' => 'Análise de alternativas por questão',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'correlacao_metricas' => [
                'label' => 'Correlação entre nota total e métricas nomeadas',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => false,
            ],
            'radar_disciplina' => [
                'label' => 'Desempenho por disciplina (radar)',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => true,
            ],
            'desempenho_bloom' => [
                'label' => 'Desempenho por nível de Bloom',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => true,
            ],
            'desempenho_miller' => [
                'label' => 'Desempenho por nível de Miller',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => true,
            ],
            'evolucao_categoria' => [
                'label' => 'Evolução histórica na categoria',
                'grupo' => self::GRUPO_ADMIN,
                'admin' => true,
                'aluno' => true,
            ],
            'nota_geral' => [
                'label' => 'Nota geral / percentual de acertos',
                'grupo' => self::GRUPO_ALUNO,
                'admin' => false,
                'aluno' => true,
            ],
            'grade_questoes' => [
                'label' => 'Grade de acerto/erro por questão',
                'grupo' => self::GRUPO_ALUNO,
                'admin' => false,
                'aluno' => true,
            ],
            'metricas_nomeadas' => [
                'label' => 'Cartões de métricas nomeadas (ex.: nota de redação)',
                'grupo' => self::GRUPO_ALUNO,
                'admin' => false,
                'aluno' => true,
            ],
            'comparativo_turma' => [
                'label' => 'Comparativo com a média da turma',
                'grupo' => self::GRUPO_ALUNO,
                'admin' => false,
                'aluno' => true,
            ],
            'ranking_percentil' => [
                'label' => 'Percentil / posição relativa',
                'grupo' => self::GRUPO_ALUNO,
                'admin' => false,
                'aluno' => true,
            ],
            'comparativo_questao' => [
                'label' => 'Sua resposta x gabarito x turma, por questão',
                'grupo' => self::GRUPO_ALUNO,
                'admin' => false,
                'aluno' => true,
            ],
        ];
    }

    /** @return array<int, string> */
    public static function chaves(): array
    {
        return array_keys(self::todos());
    }
}
