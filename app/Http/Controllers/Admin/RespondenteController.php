<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Resposta;
use App\Models\ResultadoResumo;
use App\Services\ResumoResultadoService;
use App\Support\AtividadeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Gestão de resultados por respondente de uma Avaliação — equivalente a
 * admin/resultados.php, adaptado ao novo schema: cada "linha" ali (um aluno +
 * período + avaliação) agora é um grupo de linhas em `respostas`/
 * `resultado_metricas` (uma por questão/métrica).
 */
class RespondenteController extends Controller
{
    public function index(Request $request, Avaliacao $avaliacao): View
    {
        $search = trim((string) $request->query('search', ''));
        $periodo = trim((string) $request->query('periodo', ''));

        // Busca por nome não dá pra filtrar direto na query de `respostas`
        // (que pode ter milhões de linhas — CLAUDE.md): resolve RA/CPF pelo
        // nome primeiro, numa tabela pequena (`alunos`), e usa esses valores
        // pra filtrar a query grande — mesmo princípio de
        // RelatorioAlunoService::comparativoTurma() (resolver identidade fora
        // da tabela grande, nunca dentro dela).
        $rasECpfsPorNome = $search !== ''
            ? Aluno::where('nome', 'like', "%{$search}%")->get(['ra', 'cpf'])
            : collect();
        $rasPorNome = $rasECpfsPorNome->pluck('ra')->filter()->values();
        $cpfsPorNome = $rasECpfsPorNome->pluck('cpf')->filter()->values();

        $respondentes = $avaliacao->resultados()
            ->select('aluno_chave', 'periodo')
            ->selectRaw('MAX(ra) as ra, MAX(cpf) as cpf, COUNT(*) as total_respostas, MAX(updated_at) as updated_at')
            ->when($search !== '', function ($query) use ($search, $rasPorNome, $cpfsPorNome) {
                $query->where(function ($query) use ($search, $rasPorNome, $cpfsPorNome) {
                    $query->where('ra', 'like', "%{$search}%")
                        ->orWhere('cpf', 'like', "%{$search}%")
                        ->orWhere('aluno_chave', 'like', "%{$search}%")
                        ->when($rasPorNome->isNotEmpty(), fn ($query) => $query->orWhereIn('ra', $rasPorNome))
                        ->when($cpfsPorNome->isNotEmpty(), fn ($query) => $query->orWhereIn('cpf', $cpfsPorNome));
                });
            })
            ->when($periodo !== '', fn ($query) => $query->where('periodo', $periodo))
            ->groupBy('aluno_chave', 'periodo')
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        $this->anexarAlunoEAcertos($avaliacao, $respondentes->getCollection());

        $periodosDisponiveis = $avaliacao->resultados()->select('periodo')->distinct()->pluck('periodo');

        $trashedNoPeriodo = $periodo !== ''
            ? $avaliacao->resultados()->onlyTrashed()->where('periodo', $periodo)->count()
            : 0;

        return view('admin.avaliacoes.respondentes.index', [
            'avaliacao' => $avaliacao,
            'respondentes' => $respondentes,
            'search' => $search,
            'periodo' => $periodo,
            'periodosDisponiveis' => $periodosDisponiveis,
            'trashedNoPeriodo' => $trashedNoPeriodo,
        ]);
    }

    public function show(Request $request, Avaliacao $avaliacao): View
    {
        $chave = (string) $request->query('chave', '');
        $periodo = (string) $request->query('periodo', '');

        abort_if($chave === '', 404);

        $respostas = $avaliacao->resultados()
            ->where('aluno_chave', $chave)
            ->where('periodo', $periodo)
            ->orderBy('questao_numero')
            ->get();

        abort_if($respostas->isEmpty(), 404);

        $gabaritos = $avaliacao->questoes()->whereNotNull('gabarito')->pluck('gabarito', 'numero');
        $metricas = $avaliacao->metricas()->where('aluno_chave', $chave)->where('periodo', $periodo)->get();

        $aluno = Aluno::where('ra', $chave)->orWhere('cpf', $chave)->first();
        $resumo = ResultadoResumo::where('avaliacao_codigo', $avaliacao->codigo)
            ->where('aluno_chave', $chave)
            ->where('periodo', $periodo)
            ->first();

        return view('admin.avaliacoes.respondentes.show', [
            'avaliacao' => $avaliacao,
            'respostas' => $respostas,
            'gabaritos' => $gabaritos,
            'metricas' => $metricas,
            'chave' => $chave,
            'periodo' => $periodo,
            'aluno' => $aluno,
            'acertos' => $resumo?->acertos,
            'total' => $resumo?->total,
        ]);
    }

    /**
     * Enriquece cada linha (aluno_chave/periodo, vindas de `respostas`
     * agrupadas) com o nome do aluno cadastrado e o total de acertos/questões
     * daquela avaliação — batch, sem N+1: uma consulta a `alunos` e uma a
     * `resultado_resumos` para a página inteira.
     */
    private function anexarAlunoEAcertos(Avaliacao $avaliacao, Collection $linhas): void
    {
        $chaves = $linhas->pluck('aluno_chave')->filter()->unique()->values();

        if ($chaves->isEmpty()) {
            return;
        }

        $alunosPorChave = Aluno::where(fn ($q) => $q->whereIn('ra', $chaves)->orWhereIn('cpf', $chaves))
            ->get()
            ->reduce(function (array $carry, Aluno $aluno) {
                if ($aluno->ra) {
                    $carry[$aluno->ra] = $aluno;
                }
                if ($aluno->cpf) {
                    $carry[$aluno->cpf] = $aluno;
                }

                return $carry;
            }, []);

        $resumosPorChavePeriodo = ResultadoResumo::where('avaliacao_codigo', $avaliacao->codigo)
            ->whereIn('aluno_chave', $chaves)
            ->get()
            ->keyBy(fn (ResultadoResumo $resumo) => "{$resumo->aluno_chave}|{$resumo->periodo}");

        foreach ($linhas as $linha) {
            $linha->aluno_nome = $alunosPorChave[$linha->aluno_chave]->nome ?? null;
            $resumo = $resumosPorChavePeriodo->get("{$linha->aluno_chave}|{$linha->periodo}");
            $linha->acertos = $resumo?->acertos;
            $linha->total = $resumo?->total;
        }
    }

    /**
     * Corrige a resposta de UM respondente a UMA questão — sem isso, a única
     * forma de consertar uma bolha mal escaneada era excluir e reimportar o
     * período inteiro. Recalcula o boletim na hora, igual editar um gabarito.
     */
    public function updateResposta(Request $request, Avaliacao $avaliacao, Resposta $resposta, ResumoResultadoService $resumos): RedirectResponse
    {
        abort_if($resposta->avaliacao_codigo !== $avaliacao->codigo, 404);

        $dados = $request->validate([
            'resposta' => ['nullable', 'string', 'max:10'],
        ]);

        $antes = $resposta->resposta;
        $depois = $dados['resposta'] !== null && trim($dados['resposta']) !== ''
            ? mb_strtoupper(trim($dados['resposta']), 'UTF-8')
            : null;

        $resposta->resposta = $depois;
        $resposta->save();

        $resumos->recalcular($avaliacao->codigo);

        AtividadeLogger::registrar('resposta.editada', 'Resposta', $resposta->id, [
            'avaliacao_codigo' => $avaliacao->codigo,
            'aluno_chave' => $resposta->aluno_chave,
            'periodo' => $resposta->periodo,
            'questao_numero' => $resposta->questao_numero,
            'resposta_antes' => $antes,
            'resposta_depois' => $depois,
        ]);

        return redirect()
            ->route('avaliacoes.respondentes.show', ['avaliacao' => $avaliacao, 'chave' => $resposta->aluno_chave, 'periodo' => $resposta->periodo])
            ->with('status', "Resposta da questão {$resposta->questao_numero} atualizada — boletim recalculado.");
    }

    public function destroyPeriodo(Request $request, Avaliacao $avaliacao, ResumoResultadoService $resumos): RedirectResponse
    {
        $periodo = (string) $request->input('periodo', '');

        $excluidas = $avaliacao->resultados()->where('periodo', $periodo)->delete();
        $excluidas += $avaliacao->metricas()->where('periodo', $periodo)->delete();

        $resumos->recalcular($avaliacao->codigo);

        AtividadeLogger::registrar('periodo.excluido', 'Avaliacao', $avaliacao->codigo, [
            'periodo' => $periodo,
            'registros_excluidos' => $excluidas,
        ]);

        return redirect()
            ->route('avaliacoes.respondentes.index', $avaliacao)
            ->with('status', "Resultados do período '{$periodo}' excluídos ({$excluidas} registro(s)).");
    }

    public function restorePeriodo(Request $request, Avaliacao $avaliacao, ResumoResultadoService $resumos): RedirectResponse
    {
        $periodo = (string) $request->input('periodo', '');

        $restauradas = $avaliacao->resultados()->onlyTrashed()->where('periodo', $periodo)->restore();
        $restauradas += $avaliacao->metricas()->onlyTrashed()->where('periodo', $periodo)->restore();

        $resumos->recalcular($avaliacao->codigo);

        AtividadeLogger::registrar('periodo.restaurado', 'Avaliacao', $avaliacao->codigo, [
            'periodo' => $periodo,
            'registros_restaurados' => $restauradas,
        ]);

        return redirect()
            ->route('avaliacoes.respondentes.index', ['avaliacao' => $avaliacao, 'periodo' => $periodo])
            ->with('status', "Resultados do período '{$periodo}' restaurados ({$restauradas} registro(s)).");
    }
}
