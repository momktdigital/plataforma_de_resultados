<?php

namespace App\Providers;

use App\Services\Update\GithubReleaseClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GithubReleaseClient::class, fn () => new GithubReleaseClient(
            config('sistema.repositorio'),
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
