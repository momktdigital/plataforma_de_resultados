<?php

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureNotInstalled;
use Illuminate\Console\Scheduling\Schedule;
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
    ->withSchedule(function (Schedule $schedule): void {
        // Precisa do cron do servidor rodando `php artisan schedule:run` a cada
        // minuto (veja a doc do Laravel) — sem isso nada aqui dispara sozinho.
        $schedule->command('sistema:backup')->daily()->onOneServer();

        // Só verifica e avisa (não aplica sozinho): aplicar uma atualização
        // sem supervisão contraria a confirmação manual de tag/hash exigida
        // na tela de atualização (ver AtualizacaoController).
        $schedule->command('sistema:atualizar --check')->daily()->onOneServer();

        // A cada 12h — cai dentro da janela de atualização do próprio +A
        // Data (diariamente entre 06h30 e 00h30, a cada 3h em dias úteis),
        // então nunca sincronizamos mais rápido do que o dado de origem é
        // atualizado. Sem efeito enquanto REDSHIFT_HOST não estiver
        // configurado (ver AvaliaSyncService/RedshiftAvaliaExtractor).
        $schedule->command('avalia:sincronizar')->twiceDaily(6, 18)->onOneServer();
    })
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
