<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RespondenteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function provaComRespostas(): Avaliacao
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '111', 'periodo' => '2026/1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '111', 'periodo' => '2026/1', 'questao_numero' => 2, 'resposta' => 'C']);
        ResultadoMetrica::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '111', 'periodo' => '2026/1', 'nome_metrica' => 'Total', 'valor' => '1']);

        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '222', 'periodo' => '2026/1', 'questao_numero' => 1, 'resposta' => 'A']);

        return $avaliacao;
    }

    public function test_lista_respondentes_agrupados(): void
    {
        $avaliacao = $this->provaComRespostas();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/respondentes");

        $response->assertOk();
        $response->assertSee('111');
        $response->assertSee('222');
    }

    public function test_filtra_por_busca_de_ra(): void
    {
        $avaliacao = $this->provaComRespostas();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/respondentes?search=111");

        $response->assertSee('111');
        $response->assertDontSee('222');
    }

    public function test_filtra_por_busca_de_nome_do_aluno(): void
    {
        // Bug relatado: a busca só olhava RA/CPF/aluno_chave em `respostas` —
        // buscar pelo nome (o que aparece na tela pra cada linha) não achava
        // ninguém, mesmo com o respondente listado quando o filtro é limpo.
        Aluno::create(['ra' => '111', 'cpf' => '11122233344', 'data_nascimento' => '2000-01-01', 'nome' => 'Alexandre André Dalesse']);
        Aluno::create(['ra' => '222', 'cpf' => '22233344455', 'data_nascimento' => '2000-01-01', 'nome' => 'Beatriz Souza']);
        $avaliacao = $this->provaComRespostas();

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/respondentes?search=alexandre");

        $response->assertOk();
        $response->assertSee('111');
        $response->assertDontSee('222');
    }

    public function test_lista_mostra_nome_do_aluno_e_acertos(): void
    {
        Aluno::create(['ra' => '111', 'cpf' => '11122233344', 'data_nascimento' => '2000-01-01', 'nome' => 'Ana Respondente']);
        $avaliacao = $this->provaComRespostas();
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}/respondentes");

        $response->assertOk();
        $response->assertSee('Ana Respondente');
        $response->assertSee('1/2');
    }

    public function test_detalhe_mostra_nome_e_acertos_do_aluno(): void
    {
        Aluno::create(['ra' => '111', 'cpf' => '11122233344', 'data_nascimento' => '2000-01-01', 'nome' => 'Ana Respondente']);
        $avaliacao = $this->provaComRespostas();
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get("/avaliacoes/{$avaliacao->codigo}/respondentes/show?chave=111&periodo=".urlencode('2026/1'));

        $response->assertOk();
        $response->assertSee('Ana Respondente');
        $response->assertSee('1/2');
    }

    public function test_mostra_respostas_do_respondente_com_comparacao_ao_gabarito(): void
    {
        $avaliacao = $this->provaComRespostas();

        $response = $this->actingAs($this->admin(), 'admin')
            ->get("/avaliacoes/{$avaliacao->codigo}/respondentes/show?chave=111&periodo=".urlencode('2026/1'));

        $response->assertOk();
        $response->assertSee('Total');
    }

    public function test_admin_corrige_a_resposta_de_um_respondente(): void
    {
        $avaliacao = $this->provaComRespostas();
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '111', 'periodo' => '2026/1', 'acertos' => 1]);

        $resposta = Resposta::where('ra', '111')->where('questao_numero', 2)->firstOrFail();
        $this->assertSame('C', $resposta->resposta);

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'admin')
            ->put(route('avaliacoes.respondentes.respostas.update', ['avaliacao' => $avaliacao, 'resposta' => $resposta]), [
                'resposta' => 'b',
            ]);

        $response->assertRedirect(route('avaliacoes.respondentes.show', ['avaliacao' => $avaliacao, 'chave' => '111', 'periodo' => '2026/1']));
        $response->assertSessionHas('status');
        $this->assertSame('B', $resposta->fresh()->resposta);

        // Corrigir a bolha muda o acerto — o resumo do boletim reflete na hora.
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '111', 'periodo' => '2026/1', 'acertos' => 2]);

        $this->assertDatabaseHas('atividades', [
            'admin_username' => $admin->username,
            'acao' => 'resposta.editada',
            'alvo_tipo' => 'Resposta',
            'alvo_id' => (string) $resposta->id,
        ]);
    }

    public function test_nao_corrige_resposta_de_outra_avaliacao(): void
    {
        $avaliacao1 = $this->provaComRespostas();
        $avaliacao2 = Avaliacao::create([]);
        $resposta = Resposta::where('avaliacao_codigo', $avaliacao1->codigo)->where('ra', '111')->first();

        $this->actingAs($this->admin(), 'admin')
            ->put(route('avaliacoes.respondentes.respostas.update', ['avaliacao' => $avaliacao2, 'resposta' => $resposta]), [
                'resposta' => 'Z',
            ])
            ->assertNotFound();

        $this->assertNotSame('Z', $resposta->fresh()->resposta);
    }

    public function test_exclui_e_restaura_resultados_de_um_periodo(): void
    {
        $avaliacao = $this->provaComRespostas();
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '111', 'periodo' => '2026/1']);
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')
            ->delete("/avaliacoes/{$avaliacao->codigo}/periodos", ['periodo' => '2026/1']);

        $response->assertRedirect(route('avaliacoes.respondentes.index', $avaliacao));
        $this->assertSoftDeleted('respostas', ['ra' => '111', 'periodo' => '2026/1']);
        $this->assertSoftDeleted('resultado_metricas', ['ra' => '111', 'periodo' => '2026/1']);
        // O resumo do boletim também é recalculado — período excluído some dele.
        $this->assertDatabaseMissing('resultado_resumos', ['ra' => '111', 'periodo' => '2026/1']);

        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/periodos/restaurar", ['periodo' => '2026/1']);

        $this->assertNotSoftDeleted('respostas', ['ra' => '111', 'periodo' => '2026/1']);
        $this->assertNotSoftDeleted('resultado_metricas', ['ra' => '111', 'periodo' => '2026/1']);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '111', 'periodo' => '2026/1']);

        // Trilha de auditoria: quem excluiu e quem restaurou o período.
        $this->assertDatabaseHas('atividades', [
            'admin_username' => $admin->username,
            'acao' => 'periodo.excluido',
            'alvo_tipo' => 'Avaliacao',
            'alvo_id' => (string) $avaliacao->codigo,
        ]);
        $this->assertDatabaseHas('atividades', [
            'admin_username' => $admin->username,
            'acao' => 'periodo.restaurado',
            'alvo_tipo' => 'Avaliacao',
            'alvo_id' => (string) $avaliacao->codigo,
        ]);
    }

    public function test_estado_vazio_sugere_importar_resultados(): void
    {
        $avaliacao = Avaliacao::create([]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('avaliacoes.respondentes.index', $avaliacao));

        $response->assertOk();
        $response->assertSee('Nenhum resultado encontrado.');
        $response->assertSee(route('avaliacoes.resultados.import', $avaliacao), false);
    }

    public function test_guest_nao_acessa_respondentes(): void
    {
        $this->admin();
        $avaliacao = $this->provaComRespostas();

        $this->get("/avaliacoes/{$avaliacao->codigo}/respondentes")->assertRedirect(route('login'));
    }
}
