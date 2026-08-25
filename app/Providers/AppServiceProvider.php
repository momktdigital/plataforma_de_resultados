<?php

namespace App\Providers;

use App\Models\ConfiguracaoSistema;
use App\Services\Update\GithubReleaseClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GithubReleaseClient::class, fn () => new GithubReleaseClient(
            ConfiguracaoSistema::valor('atualizacao_repositorio', config('sistema.repositorio')),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
