<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SistemaNavegacaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_menu_principal_tem_apenas_um_link_de_configuracoes(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/provas')->getContent();

        $this->assertSame(1, substr_count($html, route('sistema.configuracoes.index')));
    }

    #[DataProvider('paginasSistema')]
    public function test_cada_pagina_de_sistema_mostra_as_quatro_abas(string $rota): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->get($rota);

        $response->assertOk();
        $response->assertSee('Geral');
        $response->assertSee('Backups');
        $response->assertSee('Dados legados');
        $response->assertSee('Atualizações');
    }

    public static function paginasSistema(): array
    {
        return [
            'configurações' => ['/sistema/configuracoes'],
            'backups' => ['/sistema/backups'],
            'dados legados' => ['/sistema/legado'],
            'atualizações' => ['/sistema/atualizacao'],
        ];
    }
}
