<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prova;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class QuestaoImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_importa_apenas_questao_e_gabarito(): void
    {
        $prova = Prova::create([]);

        $csv = "Questão,Gabarito\n1,B\n2,C\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $response->assertRedirect(route('provas.show', $prova));
        $this->assertDatabaseCount('questoes', 2);
        $this->assertDatabaseHas('questoes', ['numero' => 1, 'gabarito' => 'B', 'bloom_nivel' => null]);
    }

    public function test_linha_sem_gabarito_e_ignorada_sem_derrubar_o_import(): void
    {
        $prova = Prova::create([]);

        $csv = "Questão,Gabarito\n1,B\n2,\n3,A\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseCount('questoes', 2);
        $this->assertDatabaseMissing('questoes', ['numero' => 2]);
    }

    public function test_metadados_opcionais_sao_gravados_quando_presentes(): void
    {
        $prova = Prova::create([]);

        $csv = "Questão,Gabarito,Bloom (nível),Dificuldade Pedagógica,Matriz (período),Matriz (disciplina)\n"
            ."1,B,Aplicar,Fácil,\"1;2\",\"Anatomia;Fisiologia\"\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertSame('Aplicar', $questao->bloom_nivel);
        $this->assertSame('facil', $questao->dificuldade_pedagogica);
        $this->assertCount(2, $questao->matrizes);
        $this->assertSame('Anatomia', $questao->matrizes[0]->disciplina);
        $this->assertSame(1, $questao->matrizes[0]->periodo);
    }

    public function test_reimportar_atualiza_em_vez_de_duplicar(): void
    {
        $prova = Prova::create([]);
        $admin = $this->admin();

        $primeiro = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");
        $this->actingAs($admin, 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $primeiro]);

        $segundo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,C\n");
        $this->actingAs($admin, 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $segundo]);

        $this->assertDatabaseCount('questoes', 1);
        $this->assertDatabaseHas('questoes', ['numero' => 1, 'gabarito' => 'C']);
    }

    public function test_reimportar_apos_exclusao_restaura_em_vez_de_falhar(): void
    {
        $prova = Prova::create([]);
        $admin = $this->admin();

        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");
        $this->actingAs($admin, 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $arquivo]);

        Questao::where('numero', 1)->first()->delete();
        $this->assertSoftDeleted('questoes', ['numero' => 1]);

        $reimportado = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");
        $response = $this->actingAs($admin, 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $reimportado]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('questoes', 1);
        $this->assertNotSoftDeleted('questoes', ['numero' => 1]);
    }

    public function test_importa_area_tema_habilidade_e_taxonomia_como_bloom_verbo(): void
    {
        $prova = Prova::create([]);

        $csv = "Questão,Gabarito,Área,tema,habilidade,taxonomia\n"
            .'1,B,"Clínica Médica","HIV/AIDS","E3 — Avaliação e Julgamento Ético-Profissional","Avaliar"'."\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertSame('Clínica Médica', $questao->area);
        $this->assertSame('HIV/AIDS', $questao->tema);
        $this->assertSame('E3 — Avaliação e Julgamento Ético-Profissional', $questao->habilidade);
        $this->assertSame('Avaliar', $questao->bloom_verbo);
    }

    public function test_coluna_sistema_nao_e_confundida_com_tema(): void
    {
        $prova = Prova::create([]);

        // "Sistema" contém "tema" como substring — a coluna de tema só deve
        // casar com a palavra inteira, não com pedaços de outras palavras.
        $csv = "Questão,Gabarito,Sistema\n1,B,Cardiovascular\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertNull($questao->tema);
    }

    public function test_referencias_a_b_c_viram_linhas_em_questao_referencias(): void
    {
        $prova = Prova::create([]);

        $csv = "Questão,Gabarito,Matriz Prova A,Matriz Prova B,DCN A,PPC A,PPC B,PPC C\n"
            .'1,B,"Item 1","Item 2","DCN X","P1","P2","P3"'."\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $questao = Questao::where('numero', 1)->firstOrFail();

        $matrizProva = $questao->referencias()->where('tipo', 'matriz_prova')->pluck('valor')->all();
        $dcn = $questao->referencias()->where('tipo', 'dcn')->pluck('valor')->all();
        $ppc = $questao->referencias()->where('tipo', 'ppc')->pluck('valor')->all();

        $this->assertSame(['Item 1', 'Item 2'], $matrizProva);
        $this->assertSame(['DCN X'], $dcn);
        $this->assertSame(['P1', 'P2', 'P3'], $ppc);
    }

    public function test_reimportar_sem_coluna_de_referencia_nao_apaga_a_ja_salva(): void
    {
        $prova = Prova::create([]);
        $admin = $this->admin();

        $primeiro = UploadedFile::fake()->createWithContent(
            'gabarito.csv',
            "Questão,Gabarito,DCN A\n1,B,\"DCN X\"\n"
        );
        $this->actingAs($admin, 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $primeiro]);

        // Reimporta só com Gabarito — sem a coluna de DCN.
        $segundo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,C\n");
        $this->actingAs($admin, 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $segundo]);

        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertSame('C', $questao->gabarito);
        $this->assertCount(1, $questao->referencias()->where('tipo', 'dcn')->get());
    }

    public function test_reimportar_gabarito_recalcula_o_resumo_do_boletim(): void
    {
        $prova = Prova::create([]);
        Questao::create(['prova_codigo' => $prova->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['prova_codigo' => $prova->codigo, 'ra' => '123', 'questao_numero' => 1, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($prova->codigo);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '123', 'acertos' => 0, 'total' => 1]);

        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");
        $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('resultado_resumos', ['ra' => '123', 'acertos' => 1, 'total' => 1]);
    }
}
