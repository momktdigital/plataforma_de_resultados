<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Models\PlanoAcao;
use App\Services\PlanoAcaoResultadoService;
use App\Services\PlanoAcaoService;
use App\Support\NomeCurso;
use App\Support\PlanoAcaoChecagem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A análise e o acompanhamento dos planos de ação pelo colaborador (e pelo administrador): a fila de planos enviados, a
 * decisão com justificativa (aprovar, pedir ajustes, recusar) e o quadro dos planos em execução, com o que está atrasado.
 *
 * Só enxerga o que o coordenador ENVIOU: rascunho é privado dele (404). E só dado agregado — o plano carrega os números
 * do curso, nunca aluno (o colaborador não enxerga o painel de resultados).
 */
class ColaboradorPlanoAcaoController extends Controller
{
    private const POR_PAGINA = 25;

    /** Abas da fila → quais estados mostram. */
    private const ABAS = [
        'analise' => ['rotulo' => 'Aguardando análise', 'status' => [PlanoAcao::EM_ANALISE]],
        'execucao' => ['rotulo' => 'Em execução', 'status' => [PlanoAcao::APROVADO]],
        'ajustes' => ['rotulo' => 'Devolvidos para ajustes', 'status' => [PlanoAcao::AJUSTES]],
        'finalizados' => ['rotulo' => 'Encerrados', 'status' => [PlanoAcao::CONCLUIDO, PlanoAcao::RECUSADO, PlanoAcao::CANCELADO]],
        'todos' => ['rotulo' => 'Todos', 'status' => null],
    ];

    public function __construct(private readonly PlanoAcaoService $servico) {}

    public function index(Request $request): View
    {
        $aba = (string) $request->query('aba', 'analise');
        $aba = isset(self::ABAS[$aba]) ? $aba : 'analise';
        $opcoesCurso = Curso::nomesDisponiveis();
        $curso = trim((string) $request->query('curso', ''));
        $curso = NomeCurso::estaEm($curso, $opcoesCurso) ? $curso : '';

        $base = PlanoAcao::enviados()->when($curso !== '', fn ($q) => $q->whereIn('curso', NomeCurso::variantes([$curso])));

        $contagens = [];
        foreach (self::ABAS as $chave => $definicao) {
            $contagens[$chave] = (clone $base)->when($definicao['status'] !== null, fn ($q) => $q->whereIn('status', $definicao['status']))->count();
        }

        $planos = (clone $base)
            ->when(self::ABAS[$aba]['status'] !== null, fn ($q) => $q->whereIn('status', self::ABAS[$aba]['status']))
            ->with(['acoes', 'eventos', 'autor'])
            // Quem espera há mais tempo, primeiro; os demais, pelo movimento mais recente.
            ->when($aba === 'analise', fn ($q) => $q->orderBy('enviado_em'), fn ($q) => $q->orderByDesc('updated_at'))
            ->orderBy('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return view('colaborador.planos', [
            'planos' => $planos,
            'abas' => self::ABAS,
            'aba' => $aba,
            'contagens' => $contagens,
            'curso' => $curso,
            'opcoesCurso' => $opcoesCurso,
            'painel' => $this->painel(),
        ]);
    }

    public function show(PlanoAcao $plano, PlanoAcaoResultadoService $resultados): View
    {
        $this->doRevisor($plano);
        $plano->load(['acoes', 'eventos.admin', 'autor', 'decididoPor']);

        return view('colaborador.plano', [
            'plano' => $plano,
            'lacunas' => PlanoAcaoChecagem::lacunas($plano),
            'avaliacoesAcessiveis' => PlanoAcaoService::avaliacoesAcessiveis(Auth::guard('admin')->user(), array_column($plano->avaliacoesDoRecorte(), 'codigo')),
            'alertas' => PlanoAcaoChecagem::alertas($plano),
            'criterios' => PlanoAcaoService::CRITERIOS,
            'resultado' => in_array($plano->status, [PlanoAcao::APROVADO, PlanoAcao::CONCLUIDO], true) ? $resultados->calcular($plano) : null,
        ]);
    }

    public function decidir(Request $request, PlanoAcao $plano): RedirectResponse
    {
        $this->doRevisor($plano);

        $dados = $request->validate([
            'decisao' => ['required', Rule::in(array_keys(PlanoAcaoService::DECISOES))],
            'justificativa' => ['nullable', 'string', 'max:3000'],
            'criterios' => ['nullable', 'array'],
            'criterios.*' => [Rule::in(array_keys(PlanoAcaoService::CRITERIOS))],
        ], [
            'decisao.required' => 'Escolha a decisão: aprovar, pedir ajustes ou recusar.',
            'decisao.in' => 'Decisão inválida.',
            'justificativa.max' => 'A justificativa pode ter no máximo 3000 caracteres.',
        ]);

        try {
            $this->servico->decidir($plano, Auth::guard('admin')->user(), $dados['decisao'], $dados['justificativa'] ?? null, $dados['criterios'] ?? []);
        } catch (\DomainException $e) {
            return redirect()->route('colaborador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return redirect()->route('colaborador.planos.show', $plano)->with('status', match ($dados['decisao']) {
            'aprovar' => 'Plano aprovado. O coordenador foi avisado.',
            'ajustes' => 'Plano devolvido ao coordenador para ajustes.',
            default => 'Plano recusado. O coordenador foi avisado.',
        });
    }

    public function comentar(Request $request, PlanoAcao $plano): RedirectResponse
    {
        $this->doRevisor($plano);

        $dados = $request->validate(['texto' => ['required', 'string', 'min:3', 'max:3000']], [
            'texto.required' => 'Escreva o comentário.',
            'texto.min' => 'O comentário é curto demais.',
            'texto.max' => 'O comentário pode ter no máximo 3000 caracteres.',
        ]);

        $this->servico->comentar($plano, Auth::guard('admin')->user(), $dados['texto']);

        return redirect()->route('colaborador.planos.show', $plano)->with('status', 'Comentário registrado. O coordenador foi avisado.');
    }

    /** O rascunho é do coordenador: para quem analisa, ele não existe. */
    private function doRevisor(PlanoAcao $plano): void
    {
        abort_if($plano->status === PlanoAcao::RASCUNHO, 404);
    }

    /**
     * Os números de cima da fila: quantos aguardam, quantos em execução, quantas ações atrasadas e quantos planos em
     * execução estão sem movimento (parados).
     *
     * @return array{aguardando: int, emExecucao: int, acoesAtrasadas: int, parados: int}
     */
    private function painel(): array
    {
        $emExecucao = PlanoAcao::where('status', PlanoAcao::APROVADO)->with(['acoes', 'eventos'])->get();

        return [
            'aguardando' => PlanoAcao::where('status', PlanoAcao::EM_ANALISE)->count(),
            'emExecucao' => $emExecucao->count(),
            'acoesAtrasadas' => $emExecucao->sum(fn (PlanoAcao $p) => $p->progresso()['atrasadas']),
            'parados' => $emExecucao->filter(fn (PlanoAcao $p) => $p->estaParado())->count(),
        ];
    }
}
