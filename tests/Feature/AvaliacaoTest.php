<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('uploads/gabaritos-comentados'));

        parent::tearDown();
    }

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    /** Conteúdo mínimo reconhecido como application/pdf de verdade pelo fileinfo — precisa passar no mime sniffing real do GabaritoComentadoUploader. */
    private function pdfFalso(): UploadedFile
    {
        $conteudo = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n"
            ."xref\n0 4\ntrailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n0\n%%EOF";

        return UploadedFile::fake()->createWithContent('gabarito.pdf', $conteudo);
    }

    public function test_criar_prova_nao_exige_nenhum_campo(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', []);

        $this->assertDatabaseCount('avaliacoes', 1);

        $avaliacao = Avaliacao::first();
        $response->assertRedirect(route('avaliacoes.show', $avaliacao));
        $this->assertNull($avaliacao->nome);
        $this->assertNotNull($avaliacao->codigo);
    }

    public function test_criar_prova_com_nome_e_tipo(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', [
            'nome' => 'ENADE 2026',
            'tipo' => 'Institucional',
        ]);

        $this->assertDatabaseHas('avaliacoes', [
            'nome' => 'ENADE 2026',
            'tipo' => 'Institucional',
        ]);
    }

    public function test_criar_prova_com_categoria_e_data(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);

        $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', [
            'categoria_id' => $categoria->id,
            'data_avaliacao' => '15/03/2026',
        ]);

        $avaliacao = Avaliacao::firstOrFail();
        $this->assertSame($categoria->id, $avaliacao->categoria_id);
        $this->assertSame('2026-03-15', $avaliacao->data_avaliacao->format('Y-m-d'));
    }

    public function test_atualiza_categoria_e_data_de_uma_prova_existente(): void
    {
        $avaliacao = Avaliacao::create([]);
        $categoria = Categoria::create(['nome' => 'Institucional']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->put("/avaliacoes/{$avaliacao->codigo}", [
            'categoria_id' => $categoria->id,
            'data_avaliacao' => '01/06/2026',
        ]);

        $avaliacao->refresh();
        $this->assertSame($categoria->id, $avaliacao->categoria_id);
        $this->assertSame('2026-06-01', $avaliacao->data_avaliacao->format('Y-m-d'));
    }

    public function test_categoria_inexistente_e_rejeitada(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', [
            'categoria_id' => 999,
        ]);

        $response->assertSessionHasErrors('categoria_id');
    }

    public function test_busca_por_nome(): void
    {
        Avaliacao::create(['nome' => 'ENADE 2026']);
        Avaliacao::create(['nome' => 'Simulado Interno']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes?search=ENADE');

        $response->assertOk();
        $response->assertSee('ENADE 2026');
        $response->assertDontSee('Simulado Interno');
    }

    public function test_busca_por_tipo(): void
    {
        Avaliacao::create(['nome' => 'Prova 1', 'tipo' => 'Institucional']);
        Avaliacao::create(['nome' => 'Prova 2', 'tipo' => 'Simulado']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes?search=Institucional');

        $response->assertSee('Prova 1');
        $response->assertDontSee('Prova 2');
    }

    public function test_busca_por_codigo(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'Prova Alvo']);
        Avaliacao::create(['nome' => 'Outra prova']);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes?search={$avaliacao->codigo}");

        $response->assertSee('Prova Alvo');
        $response->assertDontSee('Outra prova');
    }

    public function test_filtra_por_categoria(): void
    {
        $categoriaA = Categoria::create(['nome' => 'Institucional']);
        $categoriaB = Categoria::create(['nome' => 'Simulados']);
        Avaliacao::create(['nome' => 'ENADE 2026', 'categoria_id' => $categoriaA->id]);
        Avaliacao::create(['nome' => 'Simulado 1', 'categoria_id' => $categoriaB->id]);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes?categoria_id={$categoriaA->id}");

        $response->assertOk();
        $response->assertSee('ENADE 2026');
        $response->assertDontSee('Simulado 1');
    }

    public function test_ordena_lista_por_nome_quando_pedido_na_query_string(): void
    {
        Avaliacao::create(['nome' => 'Zebra']);
        Avaliacao::create(['nome' => 'Abacaxi']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes?sort=nome&direction=asc');

        $response->assertOk();
        $response->assertSeeInOrder(['Abacaxi', 'Zebra']);
    }

    public function test_ignora_coluna_de_ordenacao_nao_permitida(): void
    {
        Avaliacao::create(['nome' => 'Legítima']);

        // "id" não está na lista de colunas ordenáveis — cai pro padrão
        // (codigo desc) em vez de estourar erro de SQL com uma coluna
        // arbitrária vinda da query string.
        $response = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes?sort=id&direction=asc');

        $response->assertOk();
        $response->assertSee('Legítima');
    }

    public function test_lista_mostra_numero_de_alunos_distintos_em_vez_de_linhas_de_resposta(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'B']);
        // Um único aluno (RA 111) responde 2 questões — 2 linhas em
        // `respostas`, mas só 1 aluno.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '111', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '111', 'questao_numero' => 2, 'resposta' => 'B']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes');

        $response->assertOk();
        $avaliacaoListada = $response->viewData('avaliacoes')->firstWhere('codigo', $avaliacao->codigo);
        $this->assertSame(1, (int) $avaliacaoListada->alunos_count);
    }

    public function test_envia_gabarito_comentado_como_arquivo_ao_criar(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', [
            'gabarito_comentado_arquivo' => $this->pdfFalso(),
        ]);

        $avaliacao = Avaliacao::firstOrFail();
        $this->assertNotNull($avaliacao->link_comentado);
        $this->assertStringContainsString('uploads/gabaritos-comentados/', $avaliacao->link_comentado);
        $this->assertFileExists(public_path(parse_url($avaliacao->link_comentado, PHP_URL_PATH)));
    }

    public function test_arquivo_do_gabarito_comentado_substitui_o_link_colado(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', [
            'link_comentado' => 'https://exemplo.com/gabarito.pdf',
            'gabarito_comentado_arquivo' => $this->pdfFalso(),
        ]);

        $avaliacao = Avaliacao::firstOrFail();
        $this->assertStringContainsString('uploads/gabaritos-comentados/', $avaliacao->link_comentado);
        $this->assertNotSame('https://exemplo.com/gabarito.pdf', $avaliacao->link_comentado);
    }

    public function test_rejeita_arquivo_invalido_para_gabarito_comentado(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/avaliacoes', [
            'gabarito_comentado_arquivo' => UploadedFile::fake()->create('virus.exe', 10),
        ]);

        $response->assertSessionHasErrors('gabarito_comentado_arquivo');
        $this->assertDatabaseCount('avaliacoes', 0);
    }

    public function test_atualiza_gabarito_comentado_com_arquivo(): void
    {
        $avaliacao = Avaliacao::create(['link_comentado' => 'https://exemplo.com/antigo.pdf']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->put("/avaliacoes/{$avaliacao->codigo}", [
            'gabarito_comentado_arquivo' => $this->pdfFalso(),
        ]);

        $avaliacao->refresh();
        $this->assertStringContainsString('uploads/gabaritos-comentados/', $avaliacao->link_comentado);
    }

    public function test_avaliacao_nasce_ativa_por_padrao(): void
    {
        $avaliacao = Avaliacao::create([]);

        $this->assertSame('ativa', $avaliacao->status);
    }

    public function test_admin_marca_avaliacao_como_anulada(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->put("/avaliacoes/{$avaliacao->codigo}", [
            'status' => 'anulada',
        ]);

        $response->assertRedirect(route('avaliacoes.show', $avaliacao));
        $this->assertSame('anulada', $avaliacao->fresh()->status);
        $this->assertDatabaseHas('atividades', [
            'admin_username' => $admin->username,
            'acao' => 'avaliacao.status_alterado',
            'alvo_tipo' => 'Avaliacao',
            'alvo_id' => (string) $avaliacao->codigo,
        ]);
    }

    public function test_status_invalido_e_rejeitado(): void
    {
        $avaliacao = Avaliacao::create([]);

        $response = $this->actingAs($this->admin(), 'admin')->put("/avaliacoes/{$avaliacao->codigo}", [
            'status' => 'cancelada',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame('ativa', $avaliacao->fresh()->status);
    }

    public function test_tela_de_edicao_mostra_o_status_atual(): void
    {
        $avaliacao = Avaliacao::create(['status' => 'anulada']);

        $response = $this->actingAs($this->admin(), 'admin')->get("/avaliacoes/{$avaliacao->codigo}");

        $response->assertOk();
        $response->assertSee('Anulada');
    }

    public function test_guest_nao_acessa_provas(): void
    {
        $this->admin(); // sistema já instalado, mas o cliente não está autenticado

        $this->get('/avaliacoes')->assertRedirect(route('login'));
        $this->post('/avaliacoes')->assertRedirect(route('login'));
    }
}
