<?php

namespace Tests\Feature;

use App\Jobs\SincronizarAvaliaJob;
use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\ConfiguracaoSistema;
use App\Models\Questao;
use App\Models\Resposta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegracaoAvaliaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_guest_nao_acessa_integracao_avalia(): void
    {
        $this->admin();

        $this->get('/sistema/integracao-avalia')->assertRedirect(route('login'));
    }

    public function test_tela_mostra_nunca_sincronizado_quando_nao_ha_execucoes(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->get('/sistema/integracao-avalia');

        $response->assertOk();
        $response->assertSee('Nunca sincronizado.');
    }

    public function test_forcar_sincronizacao_enfileira_o_job_por_produto(): void
    {
        Queue::fake();

        $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/integracao-avalia', ['produto' => 'avalia_pro'])
            ->assertRedirect(route('sistema.integracao-avalia.index'));

        Queue::assertPushed(SincronizarAvaliaJob::class, fn ($job) => $job->produto === 'avalia_pro');
    }

    public function test_produto_invalido_e_rejeitado(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/integracao-avalia', ['produto' => 'algo-invalido'])
            ->assertSessionHasErrors('produto');
    }

    public function test_testar_conexao_retorna_erro_quando_tenant_nao_configurado(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->postJson('/sistema/integracao-avalia/testar-conexao');

        $response->assertStatus(500);
        $response->assertJsonPath('status', 'error');
        $this->assertStringContainsString('tenant', $response->json('message'));
    }

    public function test_salvar_configuracoes_grava_tenant_e_environment_sk(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put('/sistema/integracao-avalia/configuracoes', [
                'avalia_tenant_sk' => '123',
                'avalia_environment_sk' => '456',
            ])
            ->assertRedirect(route('sistema.integracao-avalia.index'));

        $this->assertSame('123', ConfiguracaoSistema::valor('avalia_tenant_sk'));
        $this->assertSame('456', ConfiguracaoSistema::valor('avalia_environment_sk'));
    }

    public function test_avaliacao_sincronizada_do_avalia_nao_pode_ser_editada(): void
    {
        $avaliacao = Avaliacao::create(['origem' => 'avalia_pro', 'id_externo' => '1:1', 'nome' => 'Prova X']);

        $response = $this->actingAs($this->admin(), 'admin')
            ->put("/avaliacoes/{$avaliacao->codigo}", ['nome' => 'Nome novo']);

        $response->assertSessionHasErrors('origem');
        $this->assertSame('Prova X', $avaliacao->fresh()->nome);
    }

    public function test_avaliacao_sincronizada_do_avalia_nao_pode_ser_excluida(): void
    {
        $avaliacao = Avaliacao::create(['origem' => 'avalia_online', 'id_externo' => '9']);

        $this->actingAs($this->admin(), 'admin')
            ->delete("/avaliacoes/{$avaliacao->codigo}")
            ->assertSessionHasErrors('origem');

        $this->assertDatabaseHas('avaliacoes', ['codigo' => $avaliacao->codigo, 'deleted_at' => null]);
    }

    public function test_questao_de_avaliacao_sincronizada_nao_pode_ser_criada_manualmente(): void
    {
        $avaliacao = Avaliacao::create(['origem' => 'avalia_pro', 'id_externo' => '1:1']);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes", ['numero' => 1, 'gabarito' => 'A'])
            ->assertSessionHasErrors('origem');

        $this->assertDatabaseCount('questoes', 0);
    }

    public function test_import_manual_de_resultados_bloqueado_para_avaliacao_do_avalia(): void
    {
        $avaliacao = Avaliacao::create(['origem' => 'avalia_pro', 'id_externo' => '1:1']);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", [
                'arquivo' => UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n123,1,B\n"),
            ])
            ->assertSessionHasErrors('origem');
    }

    public function test_resposta_de_avaliacao_do_avalia_nao_pode_ser_editada_manualmente(): void
    {
        $avaliacao = Avaliacao::create(['origem' => 'avalia_pro', 'id_externo' => '1:1']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => '-', 'origem' => 'avalia_pro', 'id_externo' => 'q1']);
        $resposta = Resposta::create([
            'avaliacao_codigo' => $avaliacao->codigo,
            'cpf' => '12345678901',
            'periodo' => '',
            'questao_numero' => 1,
            'resposta' => 'A',
            'origem' => 'avalia_pro',
            'id_externo' => 'q1',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put("/avaliacoes/{$avaliacao->codigo}/respondentes/respostas/{$resposta->id}", ['resposta' => 'B'])
            ->assertSessionHasErrors('origem');

        $this->assertSame('A', $resposta->fresh()->resposta);
    }
}
