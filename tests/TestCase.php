<?php

namespace Tests;

use App\Support\AlunoVinculoResolver;
use App\Support\InstallStatus;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // AlunoVinculoResolver::resolver() memoiza num cache estático (ver a
        // classe) que, ao contrário de uma requisição HTTP real, sobrevive
        // entre métodos de teste dentro do mesmo processo do PHPUnit —
        // limpa aqui para um teste nunca ver o cache preenchido por outro.
        AlunoVinculoResolver::limparCache();

        // InstallStatus memoiza "instalado" por processo; cada teste começa do zero.
        InstallStatus::limpar();
    }

    /**
     * Caminho no disco de um upload cujo caminho público é `uploads/...` (ou `/uploads/...`): nos testes os
     * uploads vão para `config('sistema.uploads_dir')` (descartável), não para `public/uploads`.
     */
    protected function caminhoDoUpload(string $caminhoPublico): string
    {
        $relativo = preg_replace('#^/?uploads/#', '', ltrim($caminhoPublico, '/'));

        return rtrim((string) config('sistema.uploads_dir'), '/\\').'/'.$relativo;
    }
}
