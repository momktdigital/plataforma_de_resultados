<?php

namespace App\Http\Controllers\Admin;

use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;
use App\Services\PlanoAcaoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * O plano já aprovado, em execução: o coordenador atualiza as ações (situação, prazo, notas de andamento), comenta,
 * encerra ou cancela o plano. Tudo vira evento no histórico do plano (ver PlanoAcaoService).
 */
class CoordenadorPlanoExecucaoController extends PlanoAcaoPainelController
{
    public function __construct(private readonly PlanoAcaoService $servico) {}

    public function atualizarAcao(Request $request, PlanoAcao $plano, PlanoAcaoAcao $acao): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);
        abort_unless($acao->plano_id === $plano->id, 404);

        $dados = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(PlanoAcaoAcao::STATUS))],
            'prazo' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'nota' => ['nullable', 'string', 'max:3000'],
        ], [
            'status.in' => 'Situação inválida.',
            'prazo.date_format' => 'Informe o novo prazo como uma data válida.',
            'nota.max' => 'A nota pode ter no máximo 3000 caracteres.',
        ]);

        try {
            $this->servico->atualizarAcao($plano, $acao, $usuario, $dados);
        } catch (\DomainException $e) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return redirect()->route('coordenador.planos.show', $plano)->withFragment('acao-'.$acao->id)->with('status', 'Ação atualizada.');
    }

    public function comentar(Request $request, PlanoAcao $plano): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);
        abort_if($plano->status === PlanoAcao::RASCUNHO, 404);

        $dados = $request->validate(['texto' => ['required', 'string', 'min:3', 'max:3000']], [
            'texto.required' => 'Escreva o comentário.',
            'texto.min' => 'O comentário é curto demais.',
            'texto.max' => 'O comentário pode ter no máximo 3000 caracteres.',
        ]);

        $this->servico->comentar($plano, $usuario, $dados['texto']);

        return redirect()->route('coordenador.planos.show', $plano)->with('status', 'Comentário registrado.');
    }

    public function encerrar(Request $request, PlanoAcao $plano): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);

        try {
            $this->servico->encerrar($plano, $usuario, (string) $request->input('conclusao', ''));
        } catch (\DomainException $e) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return redirect()->route('coordenador.planos.show', $plano)->with('status', 'Plano encerrado.');
    }

    public function cancelar(Request $request, PlanoAcao $plano): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);

        try {
            $this->servico->cancelar($plano, $usuario, (string) $request->input('motivo', ''));
        } catch (\DomainException $e) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return redirect()->route('coordenador.planos.show', $plano)->with('status', 'Plano cancelado.');
    }
}
