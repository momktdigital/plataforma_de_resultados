<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CronogramaItem;
use App\Models\CronogramaPendencia;
use App\Models\Curso;
use App\Services\CronogramaService;
use App\Support\AtividadeLogger;
use App\Support\NomeCurso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Registro de pendências do cronograma (aba "Registro de Pendências" da planilha): o colaborador registra, para uma
 * atividade e um curso, o que está pendente, o encaminhamento, o prazo e o responsável, e atualiza a situação. O
 * coordenador só lê. O colaborador pode excluir uma pendência (a exclusão fica na auditoria com o conteúdo); o curso e a data de
 * registro de uma pendência existente não mudam.
 */
class ColaboradorPendenciaController extends Controller
{
    private const POR_PAGINA = 25;

    public function index(Request $request, CronogramaService $servico): View
    {
        $status = (string) $request->query('status', 'abertas');
        $status = $status === 'abertas' || $status === 'todas' || isset(CronogramaPendencia::STATUS[$status]) ? $status : 'abertas';
        $curso = trim((string) $request->query('curso', ''));
        $opcoesCurso = Curso::nomesDisponiveis();
        $curso = NomeCurso::estaEm($curso, $opcoesCurso) ? $curso : '';
        $rotina = CronogramaController::rotinaDaRequisicao($request);

        return view('colaborador.pendencias', [
            'pendencias' => $servico->pendencias(null, $status === 'todas' ? '' : $status, $curso, $rotina)->paginate(self::POR_PAGINA)->withQueryString(),
            'resumo' => $servico->resumoPendencias(null),
            'status' => $status,
            'curso' => $curso,
            'rotina' => $rotina,
            'opcoesCurso' => $opcoesCurso,
        ]);
    }

    public function store(Request $request, CronogramaItem $item): RedirectResponse
    {
        $cursosDoItem = $item->cursos()->pluck('curso')->all();

        $dados = $request->validate([
            'curso' => ['required', 'string', fn ($atributo, $valor, $falhar) => NomeCurso::estaEm($valor, $cursosDoItem) || $falhar('Escolha um curso a que esta atividade se aplica.')],
            'data' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            ...self::regras(),
            'prazo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data'],
        ], self::mensagens());

        $pendencia = $item->pendencias()->create([
            ...$dados,
            // Grafia do curso como está na atividade (e não como veio do formulário).
            'curso' => collect($cursosDoItem)->first(fn ($c) => NomeCurso::mesmo($c, $dados['curso'])),
            'registrado_por' => Auth::guard('admin')->id(),
            'resolvida_em' => $dados['status'] === CronogramaItem::RESOLVIDO ? now() : null,
        ]);

        AtividadeLogger::registrar('cronograma.pendencia_registrada', 'CronogramaPendencia', $pendencia->id, [
            'atividade' => $item->projeto, 'curso' => $pendencia->curso, 'status' => $pendencia->status,
        ]);

        return redirect()->route('colaborador.atividades.show', $item)->with('status', 'Pendência registrada.');
    }

    public function update(Request $request, CronogramaPendencia $pendencia): RedirectResponse
    {
        $dados = $request->validate([
            ...self::regras(),
            'prazo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$pendencia->data->toDateString()],
        ], self::mensagens());

        $statusAntes = $pendencia->status;
        $pendencia->fill($dados);
        // Quando foi resolvida: marca ao resolver, limpa se for reaberta, e não mexe se continua resolvida.
        if ($pendencia->status !== $statusAntes) {
            $pendencia->resolvida_em = $pendencia->estaResolvida() ? now() : null;
        }
        $pendencia->save();

        AtividadeLogger::registrar('cronograma.pendencia_atualizada', 'CronogramaPendencia', $pendencia->id, [
            'atividade' => $pendencia->item->projeto, 'curso' => $pendencia->curso, 'status_antes' => $statusAntes, 'status_depois' => $pendencia->status,
        ]);

        return redirect()->route('colaborador.atividades.show', $pendencia->item_id)->with('status', 'Pendência atualizada.');
    }

    /**
     * Exclui a pendência (registro lançado por engano, por exemplo). Como o histórico do coordenador some junto, o conteúdo
     * inteiro vai para a auditoria antes de apagar.
     */
    public function destroy(CronogramaPendencia $pendencia): RedirectResponse
    {
        $item = $pendencia->item;

        AtividadeLogger::registrar('cronograma.pendencia_excluida', 'CronogramaPendencia', $pendencia->id, [
            'atividade' => $item->projeto, 'rotina' => $item->rotina, 'curso' => $pendencia->curso, 'registro' => $pendencia->data->toDateString(),
            'pendencia' => $pendencia->pendencia, 'encaminhamento' => $pendencia->encaminhamento, 'prazo' => $pendencia->prazo?->toDateString(),
            'status' => $pendencia->status, 'responsavel' => $pendencia->responsavel,
        ]);
        $pendencia->delete();

        return redirect()->route('colaborador.atividades.show', $item)->with('status', 'Pendência excluída.');
    }

    /** @return array<string, array<int, mixed>> */
    private static function regras(): array
    {
        return [
            'pendencia' => ['required', 'string', 'max:2000'],
            'encaminhamento' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(array_keys(CronogramaPendencia::STATUS))],
            'responsavel' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    private static function mensagens(): array
    {
        return [
            'curso.required' => 'Escolha o curso da pendência.',
            'data.required' => 'Informe a data do registro.',
            'data.date_format' => 'Informe uma data válida.',
            'pendencia.required' => 'Descreva a pendência.',
            'pendencia.max' => 'A pendência pode ter no máximo 2000 caracteres.',
            'encaminhamento.max' => 'O encaminhamento pode ter no máximo 2000 caracteres.',
            'prazo.date_format' => 'Informe um prazo válido.',
            'prazo.after_or_equal' => 'O prazo não pode ser anterior à data do registro.',
            'status.required' => 'Escolha a situação da pendência.',
            'status.in' => 'Situação inválida.',
            'responsavel.max' => 'O responsável pode ter no máximo 120 caracteres.',
        ];
    }
}
