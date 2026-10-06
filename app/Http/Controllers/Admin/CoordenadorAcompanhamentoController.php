<?php

namespace App\Http\Controllers\Admin;

use App\Models\Acompanhamento;
use App\Models\Aluno;
use App\Services\AcompanhamentoService;
use App\Services\CoordenadorAlunosService;
use App\Services\CoordenadorDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * O coordenador registra o acompanhamento de um aluno do curso dele (contatado / em acompanhamento / resolvido, com
 * observação). Só o coordenador DESTE curso: aluno de outro curso responde 404 (como a ficha, e pelo mesmo motivo — não
 * revelar que ele existe). O reitor olhando o curso (VisaoDeCursoDoReitor) nunca chega aqui: a visão dele é só leitura.
 */
class CoordenadorAcompanhamentoController extends PainelController
{
    public function store(
        Request $request,
        Aluno $aluno,
        CoordenadorDashboardService $dashboard,
        CoordenadorAlunosService $alunos,
        AcompanhamentoService $servico,
    ): RedirectResponse {
        if (($usuario = $this->coordenador()) === null || $usuario->emVisaoDeCurso) {
            abort(403, 'Seu perfil não pode registrar acompanhamento.');
        }

        $dados = $request->validate([
            'status' => ['required', Rule::in(array_keys(Acompanhamento::STATUS))],
            'observacao' => ['nullable', 'string', 'max:'.AcompanhamentoService::MAXIMO_OBSERVACAO],
        ], [
            'status.required' => 'Escolha a situação do acompanhamento.',
            'status.in' => 'Situação de acompanhamento inválida.',
            'observacao.max' => 'A observação pode ter no máximo '.AcompanhamentoService::MAXIMO_OBSERVACAO.' caracteres.',
        ]);

        $escopo = $dashboard->escopo($usuario, $this->cursoEscolhido($request));
        abort_if($this->semDados($escopo), 404);
        abort_if($alunos->ficha($escopo, $aluno, null) === null, 404);

        $servico->registrar($aluno, $usuario, $servico->cursoDoRegistro($aluno, $usuario->cursos()), $dados['status'], $dados['observacao'] ?? null);

        $manter = array_filter(['curso' => $this->cursoEscolhido($request)], fn ($v) => $v !== '');

        return redirect()
            ->route('coordenador.alunos.show', [$aluno, ...$manter])
            ->with('status', 'Acompanhamento registrado.');
    }
}
