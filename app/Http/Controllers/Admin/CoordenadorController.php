<?php

namespace App\Http\Controllers\Admin;

use App\Models\Avaliacao;
use App\Services\CoordenadorAlunosService;
use App\Services\CoordenadorDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Painel do coordenador: visão geral do(s) curso(s) dele no semestre e o desempenho detalhado por categoria.
 * (Os alunos do curso ficam em CoordenadorAlunosController.)
 */
class CoordenadorController extends PainelController
{
    /** Visão geral: o que importa agora — quantos alunos, quem precisa de atenção, como foram as últimas provas. */
    public function painel(Request $request, CoordenadorDashboardService $servico, CoordenadorAlunosService $alunosServico): View|RedirectResponse
    {
        if (($usuario = $this->coordenador()) === null) {
            return redirect()->route('avaliacoes.index');
        }

        $curso = $this->cursoEscolhido($request);
        $escopo = $servico->escopo($usuario, $curso, $this->periodoEscolhido($request));
        $painel = $servico->gerar($usuario, $curso, null, $escopo);

        $dados = [
            'usuario' => $usuario,
            'painel' => $painel,
            'codigosAcessiveis' => $this->codigosAcessiveis($usuario, $painel),
            // Os avisos ainda não lidos aparecem no topo da visão geral.
            'avisos' => $usuario->notificacoesRecentesNaoLidas(3),
            'totalAvisos' => $usuario->notificacoesNaoLidas(),
        ];

        if (! $this->semDados($painel) && $painel['geral']['avaliacoes'] > 0) {
            $alunos = $alunosServico->alunos($escopo);

            $dados += [
                'resumoAlunos' => $alunosServico->resumo($alunos),
                'emAtencao' => $alunosServico->emAtencao($alunos, 6),
                'recentes' => collect($painel['categorias'])
                    ->flatMap(fn ($c) => array_map(fn ($a) => [...$a, 'categoria' => $c['nome']], $c['avaliacoes']))
                    ->sortByDesc(fn ($a) => ($a['data'] ?? '0000-00-00').'|'.$a['codigo'])
                    ->take(6)
                    ->values()
                    ->all(),
                // Os destaques, SEMPRE separados por categoria: provas de categorias diferentes não são comparáveis, e
                // "área com menor desempenho" de uma categoria não pode parecer contradizer a de outra.
                'destaques' => collect([['titulo' => 'Geral do curso', 'insights' => $painel['insights']]])
                    ->concat(collect($painel['categorias'])->map(fn ($c) => ['titulo' => $c['nome'], 'insights' => $c['insights']]))
                    ->filter(fn ($grupo) => $grupo['insights'] !== [])
                    ->values()
                    ->all(),
            ];
        }

        return view('coordenador.painel', $dados);
    }

    /** Desempenho detalhado: uma seção por categoria de avaliação (médias, evolução, áreas, períodos do curso). */
    public function desempenho(Request $request, CoordenadorDashboardService $servico): View|RedirectResponse
    {
        if (($usuario = $this->coordenador()) === null) {
            return redirect()->route('avaliacoes.index');
        }

        $painel = $servico->gerar($usuario, $this->cursoEscolhido($request), $this->periodoEscolhido($request));

        return view('coordenador.desempenho', [
            'usuario' => $usuario,
            'painel' => $painel,
            'codigosAcessiveis' => $this->codigosAcessiveis($usuario, $painel),
        ]);
    }

    /**
     * Só linka pro Dashboard as avaliações que ele de fato pode abrir (a curadoria manual dos cursos da avaliação
     * pode ter removido o acesso).
     *
     * @param  array<string, mixed>  $painel
     * @return array<int, int>
     */
    private function codigosAcessiveis($usuario, array $painel): array
    {
        return Avaliacao::visivelPara($usuario)
            ->whereIn('codigo', collect($painel['categorias'] ?? [])->flatMap(fn ($c) => $c['avaliacoes'])->pluck('codigo'))
            ->pluck('codigo')
            ->all();
    }
}
