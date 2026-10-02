<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança básicos, aplicados a todas as respostas do grupo `web`.
 *
 * De propósito NÃO define uma Content-Security-Policy de scripts/estilos: as telas usam scripts inline
 * e bibliotecas por CDN (Tailwind, Chart.js...) e uma CSP restritiva quebraria o sistema. Só a diretiva
 * `frame-ancestors` (anti-clickjacking) é usada.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->definir($response, 'X-Frame-Options', 'SAMEORIGIN');
        $this->definir($response, 'Content-Security-Policy', "frame-ancestors 'self'");
        $this->definir($response, 'X-Content-Type-Options', 'nosniff');
        $this->definir($response, 'Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // A página de redefinição traz o token na URL: nenhum Referer pode levá-la a terceiros (CDNs).
        $this->definir($response, 'Referrer-Policy', $request->is('redefinir-senha/*') ? 'no-referrer' : 'strict-origin-when-cross-origin');

        // HSTS só faz sentido (e só é seguro) quando a requisição JÁ veio por HTTPS.
        if ($request->isSecure()) {
            $this->definir($response, 'Strict-Transport-Security', 'max-age=31536000');
        }

        // Dado de aluno/resultado nunca fica em cache do navegador ou de proxy (botão "voltar" depois do
        // logout, computador compartilhado de laboratório...).
        if ($this->ehConteudoSensivel($request)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

    private function definir(Response $response, string $cabecalho, string $valor): void
    {
        if (! $response->headers->has($cabecalho)) {
            $response->headers->set($cabecalho, $valor);
        }
    }

    private function ehConteudoSensivel(Request $request): bool
    {
        return Auth::guard('admin')->check()
            || $request->is('portal/resultados', 'portal/resultados/*', 'portal/verificar', 'portal/reenviar', 'redefinir-senha/*');
    }
}
