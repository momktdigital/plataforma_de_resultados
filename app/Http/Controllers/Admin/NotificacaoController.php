<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notificacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Central de notificações do coordenador: ele vê os avisos, abre, marca como lido (um ou todos). Cada coordenador só
 * enxerga os próprios — qualquer outro id responde 404.
 */
class NotificacaoController extends Controller
{
    private const POR_PAGINA = 20;

    public function index(Request $request): View|RedirectResponse
    {
        $usuario = Auth::guard('admin')->user();
        if (! $usuario->ehCoordenador()) {
            return redirect()->route('avaliacoes.index');
        }

        // Migração ainda não executada: sem tabela não há o que listar.
        if (! Schema::hasTable('notificacoes')) {
            return redirect()->route('coordenador.painel');
        }

        $soNaoLidas = $request->query('filtro') === 'nao-lidas';

        $notificacoes = Notificacao::doUsuario($usuario->id)
            ->when($soNaoLidas, fn ($q) => $q->naoLidas())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return view('coordenador.notificacoes', [
            'usuario' => $usuario,
            'notificacoes' => $notificacoes,
            'soNaoLidas' => $soNaoLidas,
            'naoLidas' => Notificacao::doUsuario($usuario->id)->naoLidas()->count(),
        ]);
    }

    /** Leve, para o sino se atualizar sozinho (e avisar no navegador) sem recarregar a página. */
    public function resumo(): JsonResponse
    {
        $usuario = Auth::guard('admin')->user();
        abort_unless($usuario->ehCoordenador(), 403);

        // O sino consulta isto a cada minuto: com a migração pendente responde "nada", em vez de erro 500 no log.
        if (! Schema::hasTable('notificacoes')) {
            return response()->json(['naoLidas' => 0, 'maiorId' => 0, 'ultimas' => []])->header('Cache-Control', 'no-store');
        }

        $naoLidas = Notificacao::doUsuario($usuario->id)->naoLidas();

        return response()->json([
            'naoLidas' => (clone $naoLidas)->count(),
            'maiorId' => (int) Notificacao::doUsuario($usuario->id)->max('id'),
            'ultimas' => (clone $naoLidas)->orderByDesc('id')->limit(5)->get(['id', 'titulo', 'texto'])
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'titulo' => $n->titulo,
                    'texto' => $n->texto,
                    'url' => route('notificacoes.abrir', $n->id),
                ])->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /** Marca como lida e vai para o assunto (o link da notificação). */
    public function abrir(Notificacao $notificacao): RedirectResponse
    {
        $this->marcar($notificacao);

        return redirect()->to($this->destinoSeguro($notificacao->url));
    }

    public function marcarLida(Notificacao $notificacao): RedirectResponse
    {
        $this->marcar($notificacao);

        return back();
    }

    public function marcarTodasLidas(): RedirectResponse
    {
        $usuario = Auth::guard('admin')->user();
        abort_unless($usuario->ehCoordenador(), 403);

        Notificacao::doUsuario($usuario->id)->naoLidas()->update(['lida_em' => now()]);

        return back()->with('status', 'Todas as notificações foram marcadas como lidas.');
    }

    /** 404 para aviso de outro usuário: não revela que ele existe. */
    private function marcar(Notificacao $notificacao): void
    {
        $usuario = Auth::guard('admin')->user();
        abort_unless($usuario->ehCoordenador() && $notificacao->admin_id === $usuario->id, 404);

        if (! $notificacao->estaLida()) {
            $notificacao->update(['lida_em' => now()]);
        }
    }

    /** Só segue para dentro do próprio sistema (o link é gerado por nós, mas nunca confiamos cegamente em redirecionamento). */
    private function destinoSeguro(?string $url): string
    {
        $raiz = rtrim(url('/'), '/');

        return $url !== null && ($url === $raiz || str_starts_with($url, $raiz.'/') || str_starts_with($url, '/'))
            ? $url
            : route('notificacoes.index');
    }
}
