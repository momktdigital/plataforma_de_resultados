<?php

namespace Tests\Feature;

use App\Jobs\SincronizarAvaliaJob;
use App\Models\Admin;
use App\Models\AvaliaAvaliacaoDisponivel;
use App\Models\Avaliacao;
use App\Models\AvaliaSyncExecucao;
use App\Models\ConfiguracaoSistema;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\Avalia\AvaliaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
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

    public function test_execucao_travada_e_marcada_como_erro_ao_abrir_a_tela(): void
    {
        // Regressão: worker da fila morreu no meio de uma sincronização
        // (sem passar pelo catch de AvaliaSyncService) — sem a autocorreção,
        // a linha ficaria em 'processando' pra sempre e o botão "Forçar
        // sincronização" desabilitado indefinidamente, sem forma de destravar.
        AvaliaSyncExecucao::create([
            'produto' => 'avalia_pro',
            'status' => AvaliaSyncExecucao::STATUS_PROCESSANDO,
            'disparado_por' => AvaliaSyncExecucao::DISPARADO_MANUAL,
            'iniciado_em' => now()->subHours(2),
        ]);

        $response = $this->actingAs($this->admin(), 'admin')->get('/sistema/integracao-avalia');

        $response->assertOk();
        $response->assertDontSee('Sincronizando');
        $this->assertSame(AvaliaSyncExecucao::STATUS_ERRO, AvaliaSyncExecucao::first()->status);
    }

    public function test_execucao_processando_recente_nao_e_mexida(): void
    {
        AvaliaSyncExecucao::create([
            'produto' => 'avalia_pro',
            'status' => AvaliaSyncExecucao::STATUS_PROCESSANDO,
            'disparado_por' => AvaliaSyncExecucao::DISPARADO_MANUAL,
            'iniciado_em' => now()->subMinutes(2),
        ]);

        $this->actingAs($this->admin(), 'admin')->get('/sistema/integracao-avalia')->assertOk();

        $this->assertSame(AvaliaSyncExecucao::STATUS_PROCESSANDO, AvaliaSyncExecucao::first()->status);
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

    public function test_atualizar_catalogo_lista_provas_do_avalia(): void
    {
        $this->mock(AvaliaSyncService::class)
            ->shouldReceive('atualizarCatalogo')
            ->once()
            ->with('avalia_pro')
            ->andReturn(5);

        $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/integracao-avalia/catalogo', ['produto' => 'avalia_pro'])
            ->assertRedirect(route('sistema.integracao-avalia.index'))
            ->assertSessionHas('status');
    }

    public function test_atualizar_catalogo_mostra_erro_em_vez_de_500(): void
    {
        $this->mock(AvaliaSyncService::class)
            ->shouldReceive('atualizarCatalogo')
            ->andThrow(new RuntimeException('Redshift indisponível'));

        $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/integracao-avalia/catalogo', ['produto' => 'avalia_pro'])
            ->assertSessionHasErrors('catalogo');
    }

    public function test_salvar_selecao_atualiza_modo_e_provas_marcadas(): void
    {
        $p1 = AvaliaAvaliacaoDisponivel::create(['produto' => 'avalia_pro', 'id_externo' => '1', 'nome' => 'Prova 1']);
        $p2 = AvaliaAvaliacaoDisponivel::create(['produto' => 'avalia_pro', 'id_externo' => '2', 'nome' => 'Prova 2', 'selecionada' => true]);

        $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/integracao-avalia/selecao', [
                '_method' => 'PUT',
                'produto' => 'avalia_pro',
                'modo' => 'selecionadas',
                'selecionadas' => [$p1->id],
            ])
            ->assertRedirect(route('sistema.integracao-avalia.index'));

        $this->assertSame('selecionadas', ConfiguracaoSistema::valor('avalia_modo_avalia_pro'));
        $this->assertTrue($p1->fresh()->selecionada);
        $this->assertFalse($p2->fresh()->selecionada);
    }

    public function test_tela_usa_post_com_spoofing_nos_formularios_de_catalogo_e_selecao(): void
    {
        // Mesma regressão do 405 do form de configurações — todo <form> pra
        // uma rota PUT precisa ser method="POST" + @method('PUT'). O form de
        // seleção só existe quando há pelo menos uma prova no catálogo.
        AvaliaAvaliacaoDisponivel::create(['produto' => 'avalia_pro', 'id_externo' => '1', 'nome' => 'Prova 1']);

        $html = $this->actingAs($this->admin(), 'admin')->get('/sistema/integracao-avalia')->getContent();

        $this->assertMatchesRegularExpression('/<form method="POST" action="[^"]*integracao-avalia\/catalogo"/', $html);
        $this->assertMatchesRegularExpression('/<form method="POST" action="[^"]*integracao-avalia\/selecao"/', $html);
    }

    public function test_formulario_de_configuracoes_usa_post_com_spoofing_de_put(): void
    {
        // Regressão: <form method="PUT"> não existe em HTML — o navegador
        // ignora e envia como GET, batendo numa rota que só aceita PUT (405).
        // O form precisa ser method="POST" com @method('PUT') fazendo o
        // spoofing; $this->put() no teste abaixo não pegaria essa quebra
        // porque manda o verbo PUT direto, sem passar pelo HTML do form.
        $html = $this->actingAs($this->admin(), 'admin')->get('/sistema/integracao-avalia')->getContent();

        $this->assertMatchesRegularExpression(
            '/<form method="POST" action="[^"]*integracao-avalia\/configuracoes"/',
            $html
        );
    }

    public function test_salvar_configuracoes_via_post_com_spoofing_grava_tenant_e_environment_sk(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/integracao-avalia/configuracoes', [
                '_method' => 'PUT',
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
