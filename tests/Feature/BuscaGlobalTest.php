<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuscaGlobalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_sem_termo_nao_lista_nada(): void
    {
        Aluno::create(['ra' => '1', 'nome' => 'Fulano']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/buscar');

        $response->assertOk();
        $response->assertDontSee('Fulano');
    }

    public function test_encontra_aluno_por_nome(): void
    {
        Aluno::create(['ra' => '2026001', 'nome' => 'Ana Beatriz']);
        Aluno::create(['ra' => '2026002', 'nome' => 'Carlos Souza']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/buscar?q=Beatriz');

        $response->assertOk();
        $response->assertSee('Ana Beatriz');
        $response->assertDontSee('Carlos Souza');
    }

    public function test_encontra_aluno_por_ra(): void
    {
        Aluno::create(['ra' => '2026099', 'nome' => 'Fulano']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/buscar?q=2026099');

        $response->assertSee('Fulano');
    }

    public function test_encontra_avaliacao_por_nome(): void
    {
        Avaliacao::create(['nome' => 'ENADE 2026']);
        Avaliacao::create(['nome' => 'Simulado Interno']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/buscar?q=ENADE');

        $response->assertSee('ENADE 2026');
        $response->assertDontSee('Simulado Interno');
    }

    public function test_encontra_avaliacao_por_codigo(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'Prova X']);

        $response = $this->actingAs($this->admin(), 'admin')->get("/buscar?q={$avaliacao->codigo}");

        $response->assertSee('Prova X');
    }

    public function test_link_para_ver_todos_aparece_quando_ha_mais_resultados_do_que_o_limite(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            Aluno::create(['ra' => "match-{$i}", 'nome' => "Aluno Buscavel {$i}"]);
        }

        $response = $this->actingAs($this->admin(), 'admin')->get('/buscar?q=Buscavel');

        $response->assertSee('Ver todos os 12 resultados de alunos');
    }

    public function test_busca_global_disponivel_no_menu_lateral(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes');

        $response->assertSee(route('busca.index'), false);
    }

    public function test_guest_nao_acessa_busca(): void
    {
        $this->admin();

        $this->get('/buscar')->assertRedirect(route('login'));
    }
}
