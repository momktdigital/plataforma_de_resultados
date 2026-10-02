<?php

namespace App\Providers;

use App\Models\Aluno;
use App\Services\Update\GithubReleaseClient;
use App\Support\AlunoVinculoResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // O repositório de onde o servidor baixa e executa código vem SÓ do .env (config/sistema.php): um
        // administrador (ou uma sessão de administrador comprometida) não pode apontá-lo para outro lugar.
        $this->app->bind(GithubReleaseClient::class, fn () => new GithubReleaseClient((string) config('sistema.repositorio')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // AlunoVinculoResolver::resolver() memoiza por (avaliação, período)
        // durante a requisição — mas o mesmo Aluno pode ser criado/editado
        // (import de matrícula, cadastro manual) enquanto essa memoização
        // ainda está "quente", então qualquer mudança em `alunos` invalida
        // o cache inteiro para não servir turma/sexo/cor_raça desatualizados.
        Aluno::saved(fn () => AlunoVinculoResolver::limparCache());
        Aluno::deleted(fn () => AlunoVinculoResolver::limparCache());
    }
}
