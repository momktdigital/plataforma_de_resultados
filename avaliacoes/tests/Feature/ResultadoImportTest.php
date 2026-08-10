<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Prova;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ResultadoImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_importa_resultados_por_ra(): void
    {
        $prova = Prova::create([]);

        $csv = "RA,Questão,Resposta\n12345,1,B\n12345,2,C\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $response->assertRedirect(route('provas.show', $prova));
        $this->assertDatabaseCount('resultados', 2);
        $this->assertDatabaseHas('resultados', ['ra' => '12345', 'questao_numero' => 1, 'resposta' => 'B']);
    }

    public function test_importa_resultados_por_cpf_e_resolve_aluno_existente(): void
    {
        $aluno = Aluno::create([
            'ra' => '999',
            'cpf' => '11122233344',
            'data_nascimento' => '2000-01-01',
        ]);
        $prova = Prova::create([]);

        $csv = "CPF,Questão,Resposta\n111.222.333-44,1,A\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('resultados', [
            'cpf' => '11122233344',
            'aluno_id' => $aluno->id,
        ]);
    }

    public function test_linha_sem_cpf_e_sem_ra_e_ignorada(): void
    {
        $prova = Prova::create([]);

        $csv = "RA,Questão,Resposta\n123,1,B\n,2,C\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseCount('resultados', 1);
    }

    public function test_arquivo_sem_coluna_de_questao_e_rejeitado(): void
    {
        $prova = Prova::create([]);

        $csv = "RA,Resposta\n123,B\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/provas/{$prova->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $response->assertSessionHasErrors('arquivo');
        $this->assertDatabaseCount('resultados', 0);
    }

    public function test_reimportar_atualiza_em_vez_de_duplicar(): void
    {
        $prova = Prova::create([]);
        $admin = $this->admin();

        $primeiro = UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n123,1,B\n");
        $this->actingAs($admin, 'admin')
            ->post("/provas/{$prova->codigo}/resultados/import", ['arquivo' => $primeiro]);

        $segundo = UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n123,1,D\n");
        $this->actingAs($admin, 'admin')
            ->post("/provas/{$prova->codigo}/resultados/import", ['arquivo' => $segundo]);

        $this->assertDatabaseCount('resultados', 1);
        $this->assertDatabaseHas('resultados', ['ra' => '123', 'questao_numero' => 1, 'resposta' => 'D']);
    }
}
