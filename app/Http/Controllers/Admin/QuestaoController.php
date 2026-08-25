<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestaoRequest;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Services\QuestaoReferenciaService;
use App\Services\ResumoResultadoService;
use Illuminate\Http\RedirectResponse;

/**
 * Editor manual de uma questão por vez — equivalente ao "Editor de Gabarito
 * Manual" de admin/avaliacao_editar.php, adaptado ao novo schema (número de
 * questão livre, não uma grade fixa Q1..Q100) e ampliado para cobrir todos os
 * metadados pedagógicos, não só número/gabarito.
 */
class QuestaoController extends Controller
{
    /** Campos de valor único — os demais (numero/gabarito) são tratados à parte. */
    private const CAMPOS_SIMPLES = [
        'area', 'tema', 'habilidade', 'bloom_nivel', 'bloom_verbo',
        'miller_nivel', 'dificuldade_pedagogica', 'dificuldade_tri',
    ];

    /** Tipos de questao_referencias editáveis como "chips" no formulário. */
    private const TIPOS_REFERENCIA = ['matriz_prova', 'dcn', 'portaria_inep', 'ppc'];

    public function store(
        StoreQuestaoRequest $request,
        Avaliacao $avaliacao,
        ResumoResultadoService $resumos,
        QuestaoReferenciaService $referencias,
    ): RedirectResponse {
        $dados = $request->validated();

        $questao = Questao::withTrashed()
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->where('numero', $dados['numero'])
            ->first();

        if ($questao === null) {
            $questao = new Questao(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $dados['numero']]);
        } elseif ($questao->trashed()) {
            $questao->restore();
        }

        $questao->gabarito = mb_strtoupper($dados['gabarito'], 'UTF-8');
        foreach (self::CAMPOS_SIMPLES as $campo) {
            $questao->{$campo} = $dados[$campo] ?? null;
        }
        $questao->save();

        foreach (self::TIPOS_REFERENCIA as $tipo) {
            $referencias->sincronizarReferencias($questao, $tipo, $dados[$tipo] ?? []);
        }
        $referencias->sincronizarMatrizes(
            $questao,
            $dados['matriz_periodo'] ?? [],
            $dados['matriz_disciplina'] ?? [],
            $dados['matriz_codigo'] ?? [],
        );

        $resumos->recalcular($avaliacao->codigo);

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', "Questão {$dados['numero']} salva com sucesso.");
    }

    public function destroy(Avaliacao $avaliacao, Questao $questao, ResumoResultadoService $resumos): RedirectResponse
    {
        $questao->delete();

        $resumos->recalcular($avaliacao->codigo);

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', "Questão {$questao->numero} excluída.");
    }

    public function restore(Avaliacao $avaliacao, int $questao, ResumoResultadoService $resumos): RedirectResponse
    {
        $questaoModel = Questao::withTrashed()
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->findOrFail($questao);
        $questaoModel->restore();

        $resumos->recalcular($avaliacao->codigo);

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', "Questão {$questaoModel->numero} restaurada.");
    }
}
