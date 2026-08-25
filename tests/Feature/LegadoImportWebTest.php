<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegadoImportWebTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_guest_nao_acessa_import_legado(): void
    {
        $this->admin();

        $this->get('/sistema/legado')->assertRedirect(route('login'));
        $this->post('/sistema/legado/banco')->assertRedirect(route('login'));
    }

    public function test_importa_do_banco_compartilhado_pela_interface(): void
    {
        DB::table('gabaritos')->insert([
            'nome_avaliacao' => 'ENADE 2026',
            'respostas' => json_encode(['Q1' => 'B']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('resultados')->insert([
            'ra' => '12345', 'periodo' => '2026/1', 'nome_avaliacao' => 'ENADE 2026',
            'respostas' => json_encode(['Q1' => 'B']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin(), 'admin')->post('/sistema/legado/banco');

        $response->assertRedirect(route('sistema.legado.index'));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('avaliacoes', ['nome' => 'ENADE 2026']);
        $this->assertDatabaseHas('questoes', ['numero' => 1, 'gabarito' => 'B']);
        $this->assertDatabaseHas('respostas', ['ra' => '12345', 'questao_numero' => 1, 'resposta' => 'B']);
        // O resumo do boletim já é gerado nesta mesma importação, sem esperar
        // um novo import de resultados.
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '12345', 'periodo' => '2026/1', 'acertos' => 1, 'total' => 1]);
    }

    public function test_importar_do_banco_avisa_quando_tabelas_legadas_nao_existem(): void
    {
        Schema::dropIfExists('gabaritos');
        Schema::dropIfExists('resultados');

        $response = $this->actingAs($this->admin(), 'admin')->post('/sistema/legado/banco');

        $response->assertSessionHasErrors('banco');
        $this->assertDatabaseCount('avaliacoes', 0);
    }

    public function test_importa_de_arquivo_de_backup_pela_interface(): void
    {
        $sql = 'INSERT INTO `gabaritos` (`id`, `nome_avaliacao`, `respostas`, `link_comentado`, `created_at`, `updated_at`, `deleted_at`) '
            ."VALUES ('1', 'Simulado', '{\"Q1\":\"A\"}', NULL, NULL, NULL, NULL);\n"
            .'INSERT INTO `resultados` (`id`, `ra`, `periodo`, `nome_avaliacao`, `respostas`, `link_comentado`, `notas_finais`, `created_at`, `updated_at`, `deleted_at`) '
            ."VALUES ('1', '999', '2026/1', 'Simulado', '{\"Q1\":\"A\"}', NULL, '{\"Total\":\"10\"}', NULL, NULL, NULL);\n";

        $arquivo = UploadedFile::fake()->createWithContent('backup.sql', $sql);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/legado/arquivo', ['arquivo' => $arquivo]);

        $response->assertRedirect(route('sistema.legado.index'));
        $this->assertDatabaseHas('avaliacoes', ['nome' => 'Simulado']);
        $this->assertDatabaseHas('questoes', ['numero' => 1, 'gabarito' => 'A']);
        $this->assertDatabaseHas('respostas', ['ra' => '999', 'resposta' => 'A']);
        $this->assertDatabaseHas('resultado_metricas', ['ra' => '999', 'nome_metrica' => 'Total', 'valor' => '10']);
    }

    public function test_dry_run_no_upload_nao_grava_nada(): void
    {
        $sql = 'INSERT INTO `gabaritos` (`id`, `nome_avaliacao`, `respostas`, `link_comentado`, `created_at`, `updated_at`, `deleted_at`) '
            ."VALUES ('1', 'Simulado', '{\"Q1\":\"A\"}', NULL, NULL, NULL, NULL);\n";

        $arquivo = UploadedFile::fake()->createWithContent('backup.sql', $sql);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/legado/arquivo', ['arquivo' => $arquivo, 'dry_run' => '1']);

        $response->assertSessionHas('status');
        $this->assertStringContainsString('Simulação', session('status'));
        $this->assertDatabaseCount('avaliacoes', 0);
        $this->assertDatabaseCount('questoes', 0);
    }

    public function test_rejeita_arquivo_com_extensao_invalida(): void
    {
        $arquivo = UploadedFile::fake()->create('backup.exe', 10);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/sistema/legado/arquivo', ['arquivo' => $arquivo]);

        $response->assertSessionHasErrors('arquivo');
    }

    public function test_guest_nao_exclui_tabelas_legadas(): void
    {
        $this->admin();

        $this->delete('/sistema/legado/tabelas')->assertRedirect(route('login'));
    }

    public function test_exclusao_exige_confirmacao_exata(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->delete('/sistema/legado/tabelas', ['confirmacao' => 'excluir']);

        $response->assertSessionHasErrors('confirmacao');
        $this->assertTrue(Schema::hasTable('gabaritos'));
        $this->assertTrue(Schema::hasTable('resultados'));
    }

    public function test_exclusao_bloqueada_sem_nenhuma_prova_migrada(): void
    {
        DB::table('gabaritos')->insert([
            'nome_avaliacao' => 'ENADE 2026',
            'respostas' => json_encode(['Q1' => 'B']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete('/sistema/legado/tabelas', ['confirmacao' => 'EXCLUIR']);

        $response->assertSessionHasErrors('confirmacao');
        $this->assertTrue(Schema::hasTable('gabaritos'));
    }

    public function test_exclui_tabelas_legadas_apos_confirmar_e_ja_ter_provas_migradas(): void
    {
        DB::table('gabaritos')->insert([
            'nome_avaliacao' => 'ENADE 2026',
            'respostas' => json_encode(['Q1' => 'B']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post('/sistema/legado/banco');

        $response = $this->actingAs($admin, 'admin')
            ->delete('/sistema/legado/tabelas', ['confirmacao' => 'EXCLUIR']);

        $response->assertRedirect(route('sistema.legado.index'));
        $response->assertSessionHas('status');
        $this->assertFalse(Schema::hasTable('gabaritos'));
        $this->assertFalse(Schema::hasTable('resultados'));
        $this->assertDatabaseHas('avaliacoes', ['nome' => 'ENADE 2026']);
    }

    public function test_exclusao_avisa_quando_nao_ha_tabelas_legadas(): void
    {
        Schema::dropIfExists('gabaritos');
        Schema::dropIfExists('resultados');

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete('/sistema/legado/tabelas', ['confirmacao' => 'EXCLUIR']);

        $response->assertRedirect(route('sistema.legado.index'));
        $response->assertSessionHas('status');
    }
}
