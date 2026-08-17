<?php

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureNotInstalled;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'instalado' => EnsureInstalled::class,
            'nao-instalado' => EnsureNotInstalled::class,
        ]);

        // Sem isso, o Laravel roda `auth`/`guest` antes do nosso middleware por
        // causa da lista de prioridade padrão — e num deploy sem admin ainda,
        // isso faria `auth:admin` redirecionar pra /login em vez de /instalar.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureInstalled::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
