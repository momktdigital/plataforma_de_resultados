<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CronogramaItem;
use App\Services\CronogramaService;
use App\Support\NomeCurso;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Cronograma de atividades na visão do COORDENADOR: o calendário com as atividades que se aplicam ao curso dele e as
 * pendências que o colaborador registrou. Somente leitura — quem cadastra é o colaborador (ver
 * ColaboradorCronogramaController). Administrador e colaborador não têm curso: vão para a tela de gestão.
 */
class CronogramaController extends Controller
{
    private const PENDENCIAS_POR_PAGINA = 10;

    public const ATIVIDADES_POR_PAGINA = 20;

    public function index(Request $request, CronogramaService $servico): View|RedirectResponse
    {
        $usuario = Auth::guard('admin')->user();
        if (! $usuario->ehCoordenador()) {
            return redirect()->route('colaborador.index');
        }

        $meus = $usuario->cursos();
        $filtros = CronogramaService::filtros($request, $meus);
        $cursos = $filtros['curso'] !== '' ? [$filtros['curso']] : $meus;
        $visao = self::visaoDaRequisicao($request);
        $todas = $request->query('pendencias') === 'todas';

        return view('cronograma.index', [
            'usuario' => $usuario,
            'meusCursos' => $meus,
            'cursos' => $cursos,
            'filtros' => $filtros,
            'visao' => $visao,
            'calendario' => $visao === 'calendario' ? $servico->mes(self::mesDaRequisicao($request), $cursos, $filtros) : null,
            'itens' => $visao === 'lista' ? $servico->lista($cursos, $filtros)->paginate(self::ATIVIDADES_POR_PAGINA, pageName: 'p')->withQueryString() : null,
            'resumo' => $servico->resumoPendencias($cursos),
            'pendencias' => $servico->pendencias($cursos, $todas ? '' : 'abertas')->paginate(self::PENDENCIAS_POR_PAGINA, pageName: 'pagina')->withQueryString(),
            'todasAsPendencias' => $todas,
        ]);
    }

    public function show(CronogramaItem $item, CronogramaService $servico): View|RedirectResponse
    {
        $usuario = Auth::guard('admin')->user();
        if (! $usuario->ehCoordenador()) {
            return redirect()->route('colaborador.index');
        }

        $meus = $usuario->cursos();
        // Atividade que não é do curso dele responde 404 (não 403): não revela que ela existe.
        abort_if($servico->itemDoCoordenador($item, $meus) === null, 404);

        return view('cronograma.show', [
            'usuario' => $usuario,
            'item' => $item,
            'cursosDoItem' => $item->cursosDe($meus),
            'pendencias' => $item->pendencias()->with('registradoPor')->get()
                ->filter(fn ($p) => NomeCurso::estaEm($p->curso, $meus))->values(),
        ]);
    }

    /** `?mes=2026-10`; sem o parâmetro (ou inválido), o mês atual. */
    public static function mesDaRequisicao(Request $request): CarbonImmutable
    {
        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', (string) $request->query('mes', ''), $m) === 1) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], 1)->startOfMonth();
        }

        return CarbonImmutable::today()->startOfMonth();
    }

    /** `?visao=lista` ou, por padrão, o calendário. */
    public static function visaoDaRequisicao(Request $request): string
    {
        return $request->query('visao') === 'lista' ? 'lista' : 'calendario';
    }

    /** `?rotina=ROD`; qualquer outra coisa = todas ('' ). */
    public static function rotinaDaRequisicao(Request $request): string
    {
        $rotina = (string) $request->query('rotina', '');

        return isset(CronogramaItem::ROTINAS[$rotina]) ? $rotina : '';
    }
}
