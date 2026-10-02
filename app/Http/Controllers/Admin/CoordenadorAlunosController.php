<?php

namespace App\Http\Controllers\Admin;

use App\Models\Aluno;
use App\Services\AlunosDoCursoExportService;
use App\Services\CoordenadorAlunosService;
use App\Services\CoordenadorDashboardService;
use App\Support\AtividadeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Os alunos do curso do coordenador e como cada um está indo no semestre. Dado nominal — por isso só o
 * coordenador DESTE curso enxerga um aluno (a ficha responde 404 para aluno de outro curso).
 */
class CoordenadorAlunosController extends PainelController
{
    private const POR_PAGINA = 25;

    private const ORDENS = ['nome', 'prioridade', 'media_asc', 'media_desc', 'presenca_asc', 'faltas'];

    public function index(
        Request $request,
        CoordenadorDashboardService $dashboard,
        CoordenadorAlunosService $servico,
    ): View|RedirectResponse {
        if (($usuario = $this->coordenador()) === null) {
            return redirect()->route('avaliacoes.index');
        }

        $escopo = $dashboard->escopo($usuario, $this->cursoEscolhido($request), $this->periodoEscolhido($request));
        $dados = ['usuario' => $usuario, 'painel' => $escopo];

        if ($this->semDados($escopo) || $escopo['doPeriodo']->isEmpty()) {
            return view('coordenador.alunos', $dados + ['semAvaliacoes' => ! $this->semDados($escopo)]);
        }

        $categoria = $this->categoria($request, $escopo);
        $alunos = $servico->alunos($escopo, $categoria);
        $filtros = $this->filtros($request);
        $filtrados = $servico->filtrar($alunos, $filtros);

        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $paginador = new LengthAwarePaginator(
            array_slice($filtrados, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA),
            count($filtrados),
            self::POR_PAGINA,
            $pagina,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('coordenador.alunos', $dados + [
            'resumo' => $servico->resumo($alunos),
            'alunosPagina' => $paginador,
            'filtros' => $filtros,
            'categoria' => $categoria,
            'categorias' => $this->opcoesDeCategoria($escopo, $dashboard),
        ]);
    }

    public function xlsx(
        Request $request,
        CoordenadorDashboardService $dashboard,
        CoordenadorAlunosService $servico,
        AlunosDoCursoExportService $exportService,
    ): StreamedResponse|RedirectResponse {
        if (($usuario = $this->coordenador()) === null) {
            return redirect()->route('avaliacoes.index');
        }

        $escopo = $dashboard->escopo($usuario, $this->cursoEscolhido($request), $this->periodoEscolhido($request));
        abort_if($this->semDados($escopo), 404);

        $categoria = $this->categoria($request, $escopo);
        $filtrados = $servico->filtrar($servico->alunos($escopo, $categoria), $this->filtros($request));
        $semestre = $escopo['periodoSelecionado'] !== '' ? $escopo['periodoSelecionado'] : 'Todos os períodos';

        AtividadeLogger::registrar('coordenador.lista_alunos_exportada', 'Admin', $usuario->id, array_filter([
            'periodo_letivo' => $escopo['periodoSelecionado'] !== '' ? $escopo['periodoSelecionado'] : null,
            'cursos' => $escopo['cursosEmFoco'],
            'linhas' => count($filtrados),
        ]));

        $writer = new Xlsx($exportService->planilha($filtrados, $semestre));
        $arquivo = 'alunos-do-curso-'.($escopo['periodoSelecionado'] !== '' ? str_replace('/', '-', $escopo['periodoSelecionado']) : 'todos').'.xlsx';

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            $arquivo,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function show(
        Request $request,
        Aluno $aluno,
        CoordenadorDashboardService $dashboard,
        CoordenadorAlunosService $servico,
    ): View|RedirectResponse {
        if (($usuario = $this->coordenador()) === null) {
            return redirect()->route('avaliacoes.index');
        }

        $escopo = $dashboard->escopo($usuario, $this->cursoEscolhido($request));
        abort_if($this->semDados($escopo), 404);

        // 404 (e não 403) para aluno de outro curso: não revela que ele existe.
        $ficha = $servico->ficha($escopo, $aluno, $this->periodoEscolhido($request));
        abort_if($ficha === null, 404);

        return view('coordenador.aluno', [
            'usuario' => $usuario,
            'painel' => $escopo,
            'ficha' => $ficha,
        ]);
    }

    /**
     * @return array{busca: string, situacao: string, periodo_curso: string, ordem: string}
     */
    private function filtros(Request $request): array
    {
        $situacao = (string) $request->query('situacao', '');
        $ordem = (string) $request->query('ordem', 'nome');
        $periodoCurso = (string) $request->query('periodo_curso', '');

        return [
            'busca' => mb_substr(trim((string) $request->query('busca', '')), 0, 100),
            'situacao' => $situacao === 'atencao' || isset(CoordenadorAlunosService::SITUACOES[$situacao]) ? $situacao : '',
            'periodo_curso' => ctype_digit($periodoCurso) ? $periodoCurso : '',
            'ordem' => in_array($ordem, self::ORDENS, true) ? $ordem : 'nome',
        ];
    }

    /**
     * Categoria escolhida, se existir entre as avaliações do semestre ('' = todas).
     *
     * @param  array<string, mixed>  $escopo
     */
    private function categoria(Request $request, array $escopo): string
    {
        $categoria = (string) $request->query('categoria', '');

        return $categoria !== '' && ctype_digit($categoria)
            && $escopo['doPeriodo']->contains(fn ($a) => (int) ($a['categoriaId'] ?? 0) === (int) $categoria)
            ? $categoria
            : '';
    }

    /**
     * @param  array<string, mixed>  $escopo
     * @return array<int, string> id da categoria (0 = sem categoria) => nome, só as do semestre
     */
    private function opcoesDeCategoria(array $escopo, CoordenadorDashboardService $dashboard): array
    {
        $nomes = $dashboard->nomesDeCategoria();

        return $escopo['doPeriodo']
            ->pluck('categoriaId')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->mapWithKeys(fn ($id) => [$id => $id === 0 ? 'Sem categoria' : ($nomes[$id] ?? 'Categoria #'.$id)])
            ->sortBy(fn ($nome, $id) => $id === 0 ? 'zzz' : mb_strtolower($nome))
            ->all();
    }
}
