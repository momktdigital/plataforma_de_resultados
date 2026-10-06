<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VisaoDeCursoDoReitor;
use App\Models\Curso;
use App\Support\AtividadeLogger;
use App\Support\NomeCurso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * O reitor abre a visão do COORDENADOR de um curso (para analisar um curso a fundo) e volta para o painel da reitoria.
 * Escolher o curso só grava o nome na sessão; quem aplica a visão é o middleware VisaoDeCursoDoReitor. A abertura fica na
 * auditoria — essa visão traz dado de alunos do curso (nome, RA, notas), ao contrário do painel agregado.
 */
class ReitorCursoController extends Controller
{
    public function abrir(Request $request): RedirectResponse
    {
        $chave = NomeCurso::chave((string) $request->query('curso', ''));
        $nome = collect(Curso::nomesDisponiveis())->first(fn ($n) => NomeCurso::chave($n) === $chave);
        abort_if($chave === '' || $nome === null, 404);

        $request->session()->put(VisaoDeCursoDoReitor::SESSAO, $nome);
        AtividadeLogger::registrar('reitor.visao_de_curso', 'Admin', Auth::guard('admin')->id(), ['curso' => $nome]);

        return redirect($this->destino($request));
    }

    /**
     * Para onde a análise abre: o painel do curso (padrão) ou, vindo de um gráfico/tabela do painel da reitoria
     * (drill-down), direto na tela e no recorte clicado — período letivo, período do curso ou a avaliação. Só
     * destinos e valores conhecidos: nada do que vem na URL é repassado como está.
     */
    private function destino(Request $request): string
    {
        $periodoLetivo = (string) $request->query('periodo_letivo', '');
        $filtros = array_filter([
            'periodo_letivo' => preg_match('#^\d{4}/[12]$#', $periodoLetivo) === 1 ? $periodoLetivo : null,
            'periodo_curso' => ctype_digit((string) $request->query('periodo_curso', '')) ? (string) $request->query('periodo_curso') : null,
            'situacao' => in_array($request->query('situacao'), ['atencao', 'ausente'], true) ? $request->query('situacao') : null,
        ], fn ($v) => $v !== null);

        return match ((string) $request->query('destino', 'painel')) {
            'alunos' => route('coordenador.alunos', $filtros + ($filtros['situacao'] ?? null ? ['ordem' => 'prioridade'] : [])),
            'desempenho' => route('coordenador.desempenho', array_intersect_key($filtros, ['periodo_letivo' => 1])),
            'comparativo' => route('coordenador.comparativo'),
            'bi' => ctype_digit((string) $request->query('avaliacao', '')) ? route('avaliacoes.bi', (int) $request->query('avaliacao')) : route('coordenador.painel', $filtros),
            default => route('coordenador.painel', array_intersect_key($filtros, ['periodo_letivo' => 1])),
        };
    }

    public function sair(Request $request): RedirectResponse
    {
        $request->session()->forget(VisaoDeCursoDoReitor::SESSAO);

        return redirect()->route('reitor.visao');
    }
}
