<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prova;
use App\Models\Questao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Garante que as planilhas de exemplo em public/exemplos/ (linkadas nas
 * telas de import) continuam sendo aceitas pelo import de verdade — se um
 * dia o formato reconhecido mudar (HeaderResolver, QuestaoImportService,
 * ResultadoImportService), este teste quebra e avisa que a planilha
 * também precisa ser atualizada.
 */
class PlanilhaExemploImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_tela_de_import_de_questoes_linka_a_planilha_de_exemplo(): void
    {
        $prova = Prova::create([]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get("/provas/{$prova->codigo}/questoes/import");

        $response->assertOk();
        $response->assertSee('exemplos/questoes-exemplo.xlsx', false);
        $this->assertFileExists(public_path('exemplos/questoes-exemplo.xlsx'));
    }

    public function test_tela_de_import_de_resultados_linka_a_planilha_de_exemplo(): void
    {
        $prova = Prova::create([]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get("/provas/{$prova->codigo}/resultados/import");

        $response->assertOk();
        $response->assertSee('exemplos/resultados-exemplo.xlsx', false);
        $this->assertFileExists(public_path('exemplos/resultados-exemplo.xlsx'));
    }

    public function test_planilha_exemplo_de_questoes_importa_de_verdade(): void
    {
        $prova = Prova::create([]);

        $conteudo = file_get_contents(public_path('exemplos/questoes-exemplo.xlsx'));
        $arquivo = UploadedFile::fake()->createWithContent('questoes-exemplo.xlsx', $conteudo);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('questoes', 3);

        $q1 = Questao::where('numero', 1)->firstOrFail();
        $this->assertSame('B', $q1->gabarito);
        $this->assertSame('Aplicar', $q1->bloom_nivel);
        $this->assertSame('facil', $q1->dificuldade_pedagogica);
        $this->assertCount(2, $q1->matrizes);
        $this->assertSame(['Item 1', 'Item 2'], $q1->referencias()->where('tipo', 'matriz_prova')->pluck('valor')->all());
        $this->assertSame(['Art. 5º'], $q1->referencias()->where('tipo', 'dcn')->pluck('valor')->all());
        $this->assertSame(['P1', 'P2'], $q1->referencias()->where('tipo', 'portaria_inep')->pluck('valor')->all());
        $this->assertSame(['PPC-01', 'PPC-02'], $q1->referencias()->where('tipo', 'ppc')->pluck('valor')->all());
    }

    public function test_planilha_exemplo_de_resultados_importa_de_verdade(): void
    {
        $prova = Prova::create([]);

        $conteudo = file_get_contents(public_path('exemplos/resultados-exemplo.xlsx'));
        $arquivo = UploadedFile::fake()->createWithContent('resultados-exemplo.xlsx', $conteudo);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('respostas', 4);
        $this->assertDatabaseHas('respostas', ['ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'B', 'periodo' => '2026/1']);
        $this->assertDatabaseHas('respostas', ['ra' => '2026001', 'questao_numero' => 3, 'resposta' => null, 'periodo' => '2026/1']);
        $this->assertDatabaseHas('respostas', ['cpf' => '11122233344', 'questao_numero' => 1, 'resposta' => 'A']);
    }
}
