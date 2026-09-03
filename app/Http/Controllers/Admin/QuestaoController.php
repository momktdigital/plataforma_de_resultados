<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestaoRequest;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Services\QuestaoReferenciaService;
use App\Services\ResumoResultadoService;
use App\Support\AtividadeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        'miller_nivel', 'dificuldade_pedagogica', 'dificuldade_tri', 'anulada_modo',
    ];

    /** Tipos de questao_referencias editáveis como "chips" no formulário. */
    private const TIPOS_REFERENCIA = ['matriz_prova', 'dcn', 'portaria_inep', 'ppc'];

    /** Ver Avaliacao::origemBloqueiaEdicao(). */
    private const ERRO_ORIGEM_AVALIA = 'Esta avaliação foi sincronizada do Avalia e suas questões não podem ser editadas por aqui — qualquer alteração seria sobrescrita na próxima sincronização.';

    public function store(
        StoreQuestaoRequest $request,
        Avaliacao $avaliacao,
        ResumoResultadoService $resumos,
        QuestaoReferenciaService $referencias,
    ): RedirectResponse {
        if ($avaliacao->origemBloqueiaEdicao()) {
            return back()->withErrors(['origem' => self::ERRO_ORIGEM_AVALIA]);
        }

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

        $gabaritoAntes = $questao->gabarito;
        $anuladaAntes = $questao->anulada_modo;

        $questao->gabarito = mb_strtoupper($dados['gabarito'], 'UTF-8');
        foreach (self::CAMPOS_SIMPLES as $campo) {
            $questao->{$campo} = $dados[$campo] ?? null;
        }
        $questao->save();

        // Só isso muda a nota de quem já respondeu — os demais metadados
        // (área/tema/bloom/matriz...) são só classificação, não afetam acerto.
        if ($gabaritoAntes !== $questao->gabarito || $anuladaAntes !== $questao->anulada_modo) {
            AtividadeLogger::registrar('questao.gabarito_alterado', 'Questao', $questao->id, [
                'avaliacao_codigo' => $avaliacao->codigo,
                'numero' => $questao->numero,
                'gabarito_antes' => $gabaritoAntes,
                'gabarito_depois' => $questao->gabarito,
                'anulada_modo_antes' => $anuladaAntes,
                'anulada_modo_depois' => $questao->anulada_modo,
            ]);
        }

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
        if ($avaliacao->origemBloqueiaEdicao()) {
            return back()->withErrors(['origem' => self::ERRO_ORIGEM_AVALIA]);
        }

        $questao->delete();

        $resumos->recalcular($avaliacao->codigo);

        AtividadeLogger::registrar('questao.excluida', 'Questao', $questao->id, [
            'avaliacao_codigo' => $avaliacao->codigo,
            'numero' => $questao->numero,
        ]);

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', "Questão {$questao->numero} excluída.");
    }

    public function destroyBulk(Request $request, Avaliacao $avaliacao, ResumoResultadoService $resumos): RedirectResponse
    {
        if ($avaliacao->origemBloqueiaEdicao()) {
            return back()->withErrors(['origem' => self::ERRO_ORIGEM_AVALIA]);
        }

        $ids = array_values(array_unique(array_map('intval', $request->input('ids', []))));

        if ($ids === []) {
            return back()->withErrors(['ids' => 'Selecione ao menos uma questão.']);
        }

        // Escopado à avaliação da URL — um id de outra avaliação (manipulado
        // à mão) simplesmente não é encontrado, nunca excluído.
        $questoes = Questao::where('avaliacao_codigo', $avaliacao->codigo)->whereIn('id', $ids)->get();

        foreach ($questoes as $questao) {
            $questao->delete();

            AtividadeLogger::registrar('questao.excluida', 'Questao', $questao->id, [
                'avaliacao_codigo' => $avaliacao->codigo,
                'numero' => $questao->numero,
            ]);
        }

        $resumos->recalcular($avaliacao->codigo);

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', $questoes->count().' questão(ões) excluída(s).');
    }

    public function restore(Avaliacao $avaliacao, int $questao, ResumoResultadoService $resumos): RedirectResponse
    {
        if ($avaliacao->origemBloqueiaEdicao()) {
            return back()->withErrors(['origem' => self::ERRO_ORIGEM_AVALIA]);
        }

        $questaoModel = Questao::withTrashed()
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->findOrFail($questao);
        $questaoModel->restore();

        $resumos->recalcular($avaliacao->codigo);

        AtividadeLogger::registrar('questao.restaurada', 'Questao', $questaoModel->id, [
            'avaliacao_codigo' => $avaliacao->codigo,
            'numero' => $questaoModel->numero,
        ]);

        return redirect()
            ->route('avaliacoes.show', $avaliacao)
            ->with('status', "Questão {$questaoModel->numero} restaurada.");
    }
}
