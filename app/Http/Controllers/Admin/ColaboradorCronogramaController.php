<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarCronogramaItemRequest;
use App\Models\CronogramaItem;
use App\Models\Curso;
use App\Services\CronogramaService;
use App\Support\AtividadeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Gestão do cronograma de atividades pelo COLABORADOR (e pelo administrador): cadastra as atividades, indica a quais
 * cursos se aplicam — o que monta o calendário de cada coordenador — e acompanha a situação de cada curso. As
 * pendências ficam em ColaboradorPendenciaController. O coordenador só lê (CronogramaController).
 */
class ColaboradorCronogramaController extends Controller
{
    public function index(Request $request, CronogramaService $servico): View
    {
        $opcoesCurso = Curso::nomesDisponiveis();
        $filtros = CronogramaService::filtros($request, $opcoesCurso);
        $visao = CronogramaController::visaoDaRequisicao($request);

        return view('colaborador.index', [
            'calendario' => $visao === 'calendario' ? $servico->mes(CronogramaController::mesDaRequisicao($request), null, $filtros) : null,
            'itens' => $visao === 'lista' ? $servico->lista(null, $filtros)->paginate(CronogramaController::ATIVIDADES_POR_PAGINA, pageName: 'p')->withQueryString() : null,
            'resumo' => $servico->resumoPendencias(null),
            'filtros' => $filtros,
            'visao' => $visao,
            'opcoesCurso' => $opcoesCurso,
        ]);
    }

    public function create(Request $request): View
    {
        $data = (string) $request->query('data', '');

        return view('colaborador.form', [
            'item' => new CronogramaItem(['data' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) === 1 ? $data : today()->toDateString()]),
            'opcoesCurso' => Curso::nomesDisponiveis(),
            'cursosSelecionados' => [],
        ]);
    }

    public function store(SalvarCronogramaItemRequest $request, CronogramaService $servico): RedirectResponse
    {
        $item = $servico->salvar(null, $request->safe()->only(['data', 'rotina', 'projeto', 'descricao']), $request->validated('cursos'), Auth::guard('admin')->id());

        AtividadeLogger::registrar('cronograma.atividade_criada', 'CronogramaItem', $item->id, [
            'projeto' => $item->projeto, 'rotina' => $item->rotina, 'data' => $item->data->toDateString(), 'cursos' => $request->validated('cursos'),
        ]);

        return redirect()->route('colaborador.atividades.show', $item)->with('status', 'Atividade cadastrada. Ela já aparece no calendário dos coordenadores dos cursos marcados.');
    }

    public function show(CronogramaItem $item): View
    {
        $item->load('cursos');

        return view('colaborador.show', [
            'item' => $item,
            'pendencias' => $item->pendencias()->with('registradoPor')->get(),
        ]);
    }

    public function edit(CronogramaItem $item): View
    {
        return view('colaborador.form', [
            'item' => $item,
            'opcoesCurso' => Curso::nomesDisponiveis(),
            'cursosSelecionados' => $item->cursos()->pluck('curso')->all(),
        ]);
    }

    public function update(SalvarCronogramaItemRequest $request, CronogramaItem $item, CronogramaService $servico): RedirectResponse
    {
        $antes = $item->only(['projeto', 'rotina']) + ['data' => $item->data->toDateString(), 'cursos' => $item->cursos()->pluck('curso')->all()];

        $servico->salvar($item, $request->safe()->only(['data', 'rotina', 'projeto', 'descricao']), $request->validated('cursos'), Auth::guard('admin')->id());

        AtividadeLogger::registrar('cronograma.atividade_editada', 'CronogramaItem', $item->id, [
            'antes' => $antes,
            'depois' => ['projeto' => $item->projeto, 'rotina' => $item->rotina, 'data' => $item->data->toDateString(), 'cursos' => $request->validated('cursos')],
        ]);

        return redirect()->route('colaborador.atividades.show', $item)->with('status', 'Atividade atualizada.');
    }

    /** Situação de cada curso na atividade (aguardando, em acompanhamento, pendente, resolvido). */
    public function situacao(Request $request, CronogramaItem $item, CronogramaService $servico): RedirectResponse
    {
        $dados = $request->validate([
            'status' => ['required', 'array'],
            'status.*' => ['string', Rule::in(array_keys(CronogramaItem::STATUS))],
        ], ['status.*.in' => 'Situação inválida.']);

        $mudancas = $servico->atualizarSituacao($item, $dados['status']);

        if ($mudancas !== []) {
            AtividadeLogger::registrar('cronograma.situacao_atualizada', 'CronogramaItem', $item->id, ['projeto' => $item->projeto, 'mudancas' => $mudancas]);
        }

        return redirect()->route('colaborador.atividades.show', $item)->with('status', $mudancas === [] ? 'Nenhuma situação foi alterada.' : 'Situação dos cursos atualizada.');
    }

    public function destroy(CronogramaItem $item): RedirectResponse
    {
        // Apagar a atividade levaria as pendências junto (histórico do coordenador): exclua-as antes, de propósito.
        if ($item->pendencias()->exists()) {
            return redirect()->route('colaborador.atividades.show', $item)
                ->withErrors(['item' => 'Esta atividade tem pendências registradas e não pode ser excluída enquanto elas existirem. Exclua as pendências antes ou, se a atividade não se aplica mais, ajuste os cursos.']);
        }

        $dados = ['projeto' => $item->projeto, 'rotina' => $item->rotina, 'data' => $item->data->toDateString()];
        $mes = $item->data->format('Y-m');
        $item->delete();

        AtividadeLogger::registrar('cronograma.atividade_excluida', 'CronogramaItem', $item->id, $dados);

        return redirect()->route('colaborador.index', ['mes' => $mes])->with('status', 'Atividade excluída.');
    }
}
