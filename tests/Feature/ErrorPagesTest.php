<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_rota_inexistente_mostra_a_pagina_404_da_aplicacao(): void
    {
        $response = $this->get('/esta-rota-nao-existe');

        $response->assertStatus(404);
        $response->assertSee('Página não encontrada');
    }

    public function test_pagina_403_renderiza_com_a_marca_da_aplicacao(): void
    {
        $response = $this->view('errors.403');

        $response->assertSee('Acesso negado');
        $response->assertSee('Avaliações');
    }

    public function test_pagina_500_renderiza_com_a_marca_da_aplicacao(): void
    {
        $response = $this->view('errors.500');

        $response->assertSee('Algo deu errado');
        $response->assertSee('Avaliações');
    }
}
