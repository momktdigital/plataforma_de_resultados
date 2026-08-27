<?php

namespace Tests;

use App\Support\AlunoVinculoResolver;
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
    }
}
