<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prova;
use App\Models\Questao;
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
}
