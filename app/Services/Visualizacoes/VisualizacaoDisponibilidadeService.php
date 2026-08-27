<?php

namespace App\Services\Visualizacoes;

use App\Models\Avaliacao;
use App\Support\AlunoVinculoResolver;
use Illuminate\Support\Facades\DB;

/**
 * Verifica, para cada visual do VisualCatalog, se a avaliação TEM os dados
 * necessários para calculá-lo — nunca se calcula o valor do visual aqui, só
 * a existência mínima dos dados (via EXISTS/COUNT, nunca varrendo linhas em
 * PHP, no mesmo espírito de BiDashboardService/EstatisticaErroService).
 *
 * É essa disponibilidade que decide se o toggle "exibir para aluno"/"exibir
 * para administrativo" na tela de configuração fica habilitado ou desabilitado
 * (com o motivo da pendência) — nunca a preferência salva por si só habilita
 * um visual sem que os dados existam.
 */
class VisualizacaoDisponibilidadeService
{
    public function __construct(
        private readonly AlunoVinculoResolver $alunoResolver = new AlunoVinculoResolver,
    ) {}

    /** @return array<string, array{disponivel: bool, pendencia: ?string}> */
    public function calcular(Avaliacao $avaliacao): array
    {
        $codigo = $avaliacao->codigo;

        $temGabarito = DB::table('questoes')
            ->where('avaliacao_codigo', $codigo)
            ->whereNull('deleted_at')
            ->whereNotNull('gabarito')
            ->where('gabarito', '!=', '')
            ->exists();

        $temRespostas = $temGabarito && DB::table('respostas')
            ->where('avaliacao_codigo', $codigo)
            ->whereNull('deleted_at')
            ->exists();

        $qtdResumos = DB::table('resultado_resumos')->where('avaliacao_codigo', $codigo)->count();
        $temResumos = $qtdResumos > 0;

        $temDisciplina = DB::table('questao_matrizes as m')
            ->join('questoes as q', 'q.id', '=', 'm.questao_id')
            ->where('q.avaliacao_codigo', $codigo)
            ->whereNull('q.deleted_at')
            ->whereNotNull('m.disciplina')
            ->where('m.disciplina', '!=', '')
            ->exists();

        $temBloom = $this->questoesComCampoPreenchido($codigo, 'bloom_nivel');
        $temMiller = $this->questoesComCampoPreenchido($codigo, 'miller_nivel');
        $temDificuldadePedagogica = $this->questoesComCampoPreenchido($codigo, 'dificuldade_pedagogica');
        $temDificuldadeTri = DB::table('questoes')
            ->where('avaliacao_codigo', $codigo)
            ->whereNull('deleted_at')
            ->whereNotNull('dificuldade_tri')
            ->exists();
        $temHabilidade = $this->questoesComCampoPreenchido($codigo, 'habilidade');

        $temMetricas = DB::table('resultado_metricas')
            ->where('avaliacao_codigo', $codigo)
            ->whereNull('deleted_at')
            ->exists();

        $alunosVinculados = $this->alunoResolver->resolver($codigo);
        $temTurmaVinculada = $alunosVinculados->contains(fn ($a) => ! empty($a->turma));
        $temDadosDemograficos = $alunosVinculados->contains(
            fn ($a) => ! empty($a->sexo) || ! empty($a->cor_raca) || ! empty($a->cidade) || ! empty($a->uf)
        );

        $temCategoria = $avaliacao->categoria_id !== null;
        $temEvolucaoCategoria = false;
        if ($temCategoria) {
            $codigosNaCategoria = DB::table('avaliacoes')
                ->where('categoria_id', $avaliacao->categoria_id)
                ->whereNull('deleted_at')
                ->pluck('codigo');

            $qtdAvaliacoesComResumo = DB::table('resultado_resumos')
                ->whereIn('avaliacao_codigo', $codigosNaCategoria)
                ->distinct()
                ->count('avaliacao_codigo');

            $temEvolucaoCategoria = $qtdAvaliacoesComResumo >= 2;
        }

        $base = fn (string $semGabaritoMsg = 'Cadastre o gabarito das questões desta avaliação.') => $temGabarito
            ? null
            : $semGabaritoMsg;

        $baseComRespostas = function () use ($temGabarito, $temRespostas) {
            if (! $temGabarito) {
                return 'Cadastre o gabarito das questões desta avaliação.';
            }
            if (! $temRespostas) {
                return 'Importe ao menos um resultado (resposta) para esta avaliação.';
            }

            return null;
        };

        $baseComResumos = fn () => $temResumos ? null : 'Importe resultados para esta avaliação (o resumo por aluno ainda não foi gerado).';

        $definicoes = [
            'histograma' => $baseComRespostas(),
            'questoes_criticas' => $baseComRespostas(),
            'analise_alternativas' => $baseComRespostas(),
            'comparativo_questao' => $baseComRespostas(),

            'ranking_completo' => $baseComResumos(),

            'radar_disciplina' => $baseComRespostas() ?? (
                $temDisciplina ? null : 'Nenhuma questão tem disciplina cadastrada na matriz curricular.'
            ),

            'desempenho_bloom' => $baseComRespostas() ?? (
                $temBloom ? null : 'Nenhuma questão tem nível de Bloom cadastrado.'
            ),

            'desempenho_miller' => $baseComRespostas() ?? (
                $temMiller ? null : 'Nenhuma questão tem nível de Miller cadastrado.'
            ),

            'curva_dificuldade' => $baseComRespostas() ?? (
                $temDificuldadePedagogica ? null : 'Nenhuma questão tem dificuldade pedagógica (fácil/médio/difícil) cadastrada.'
            ),

            'dispersao_tri' => $baseComRespostas() ?? (
                $temDificuldadeTri ? null : 'Nenhuma questão tem dificuldade TRI cadastrada.'
            ),

            'heatmap_habilidade_turma' => $baseComRespostas() ?? (
                ! $temHabilidade ? 'Nenhuma questão tem habilidade cadastrada.' : (
                    ! $temTurmaVinculada ? 'Nenhum aluno vinculado a esta avaliação tem turma cadastrada.' : null
                )
            ),

            'distribuicao_turma' => $baseComResumos() ?? (
                $temTurmaVinculada ? null : 'Nenhum aluno vinculado a esta avaliação tem turma cadastrada.'
            ),

            'comparativo_turma' => $baseComRespostas() ?? (
                $temTurmaVinculada ? null : 'Nenhum aluno vinculado a esta avaliação tem turma cadastrada.'
            ),

            'perfil_demografico' => $baseComResumos() ?? (
                $temDadosDemograficos ? null : 'Nenhum aluno vinculado tem dados pessoais (sexo, cor/raça, cidade ou UF) cadastrados.'
            ),

            'correlacao_metricas' => $baseComResumos() ?? (
                $temMetricas ? null : 'Nenhuma métrica nomeada (ex.: nota de redação) foi importada para esta avaliação.'
            ),

            'evolucao_categoria' => ! $temCategoria
                ? 'Esta avaliação não está associada a uma categoria.'
                : ($temEvolucaoCategoria ? null : 'É necessário pelo menos 2 avaliações da mesma categoria com resultados importados.'),

            'nota_geral' => $base(),
            'grade_questoes' => $base(),

            'metricas_nomeadas' => $temMetricas ? null : 'Nenhuma métrica nomeada foi importada para esta avaliação.',

            'ranking_percentil' => $baseComRespostas() ?? (
                $qtdResumos >= 2 ? null : 'É necessário pelo menos 2 respondentes para calcular percentil.'
            ),
        ];

        $resultado = [];
        foreach (VisualCatalog::chaves() as $chave) {
            $pendencia = $definicoes[$chave] ?? null;
            $resultado[$chave] = ['disponivel' => $pendencia === null, 'pendencia' => $pendencia];
        }

        return $resultado;
    }

    private function questoesComCampoPreenchido(int $avaliacaoCodigo, string $campo): bool
    {
        return DB::table('questoes')
            ->where('avaliacao_codigo', $avaliacaoCodigo)
            ->whereNull('deleted_at')
            ->whereNotNull($campo)
            ->where($campo, '!=', '')
            ->exists();
    }
}
