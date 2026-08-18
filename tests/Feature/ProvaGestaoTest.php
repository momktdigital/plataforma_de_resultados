<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prova;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvaGestaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_atualiza_configuracoes_da_prova(): void
    {
        $prova = Prova::create(['nome' => 'Original']);

        $response = $this->actingAs($this->admin(), 'admin')->put("/provas/{$prova->codigo}", [
            'nome' => 'Renomeada',
            'link_comentado' => 'https://exemplo.com/gabarito',
        ]);

        $response->assertRedirect(route('provas.show', $prova));
        $this->assertSame('Renomeada', $prova->fresh()->nome);
        $this->assertSame('https://exemplo.com/gabarito', $prova->fresh()->link_comentado);
    }

    public function test_excluir_prova_faz_soft_delete_em_cascata(): void
    {
        $prova = Prova::create([]);
        $questao = Questao::create(['prova_codigo' => $prova->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['prova_codigo' => $prova->codigo, 'ra' => '123', 'questao_numero' => 1, 'resposta' => 'A']);
        ResultadoMetrica::create(['prova_codigo' => $prova->codigo, 'ra' => '123', 'nome_metrica' => 'Total', 'valor' => '10']);

        $response = $this->actingAs($this->admin(), 'admin')->delete("/provas/{$prova->codigo}");

        $response->assertRedirect(route('provas.index'));
        $this->assertSoftDeleted('provas', ['codigo' => $prova->codigo]);
        $this->assertSoftDeleted('questoes', ['id' => $questao->id]);
        $this->assertDatabaseCount('respostas', 1);
        $this->assertNotNull(Resposta::withTrashed()->first()->deleted_at);
    }

    public function test_guest_nao_atualiza_nem_exclui_prova(): void
    {
        $this->admin();
        $prova = Prova::create([]);

        $this->put("/provas/{$prova->codigo}", ['nome' => 'X'])->assertRedirect(route('login'));
        $this->delete("/provas/{$prova->codigo}")->assertRedirect(route('login'));
    }
}
