<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Prova;
use App\Models\Resposta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportarDadosLegadosTest extends TestCase
{
    use RefreshDatabase;

    public function test_migra_gabarito_e_resultado_legados(): void
    {
        DB::table('gabaritos')->insert([
            'nome_avaliacao' => 'ENADE 2026',
            'respostas' => json_encode(['Q1' => 'B', 'Q2' => 'C']),
            'link_comentado' => 'https://exemplo.org/gabarito',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('resultados')->insert([
            'ra' => '12345',
            'periodo' => '2026/1',
            'nome_avaliacao' => 'ENADE 2026',
            'respostas' => json_encode(['Q1' => 'B', 'Q2' => 'D']),
            'notas_finais' => json_encode(['Nota de Redação' => '8.5', 'Total' => '72']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('legado:importar')->assertExitCode(0);

        $prova = Prova::where('nome', 'ENADE 2026')->firstOrFail();
        $this->assertSame('https://exemplo.org/gabarito', $prova->link_comentado);

        $this->assertDatabaseHas('questoes', ['prova_codigo' => $prova->codigo, 'numero' => 1, 'gabarito' => 'B']);
        $this->assertDatabaseHas('questoes', ['prova_codigo' => $prova->codigo, 'numero' => 2, 'gabarito' => 'C']);

        $this->assertDatabaseHas('respostas', [
            'prova_codigo' => $prova->codigo, 'ra' => '12345', 'periodo' => '2026/1', 'questao_numero' => 1, 'resposta' => 'B',
        ]);
        $this->assertDatabaseHas('respostas', [
            'prova_codigo' => $prova->codigo, 'ra' => '12345', 'periodo' => '2026/1', 'questao_numero' => 2, 'resposta' => 'D',
        ]);

        $this->assertDatabaseHas('resultado_metricas', [
            'prova_codigo' => $prova->codigo, 'ra' => '12345', 'nome_metrica' => 'Nota de Redação', 'valor' => '8.5',
        ]);
        $this->assertDatabaseHas('resultado_metricas', [
            'prova_codigo' => $prova->codigo, 'ra' => '12345', 'nome_metrica' => 'Total', 'valor' => '72',
        ]);
    }

    public function test_vincula_aluno_existente_pelo_ra(): void
    {
        $aluno = Aluno::create(['ra' => '999', 'cpf' => '11122233344', 'data_nascimento' => '2000-01-01']);

        DB::table('gabaritos')->insert([
            'nome_avaliacao' => 'Simulado',
            'respostas' => json_encode(['Q1' => 'A']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('resultados')->insert([
            'ra' => '999', 'periodo' => '2026/1', 'nome_avaliacao' => 'Simulado',
            'respostas' => json_encode(['Q1' => 'A']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('legado:importar')->assertExitCode(0);

        $resposta = Resposta::where('ra', '999')->firstOrFail();
        $this->assertSame($aluno->id, $resposta->aluno_id);
    }

    public function test_preserva_exclusao_lixeira(): void
    {
        DB::table('gabaritos')->insert([
            'nome_avaliacao' => 'Prova Antiga',
            'respostas' => json_encode(['Q1' => 'A']),
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now(),
        ]);
        DB::table('resultados')->insert([
            'ra' => '111', 'periodo' => '2026/1', 'nome_avaliacao' => 'Prova Antiga',
            'respostas' => json_encode(['Q1' => 'A']),
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now(),
        ]);

        $this->artisan('legado:importar')->assertExitCode(0);

        $this->assertSoftDeleted('questoes', ['numero' => 1]);
        $this->assertSoftDeleted('respostas', ['ra' => '111']);
    }

    public function test_dry_run_nao_grava_nada(): void
    {
        DB::table('gabaritos')->insert([
            'nome_avaliacao' => 'ENADE 2026',
            'respostas' => json_encode(['Q1' => 'B']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('legado:importar --dry-run')->assertExitCode(0);

        $this->assertDatabaseCount('provas', 0);
        $this->assertDatabaseCount('questoes', 0);
    }

    public function test_reexecutar_e_idempotente(): void
    {
        DB::table('gabaritos')->insert([
            'nome_avaliacao' => 'ENADE 2026',
            'respostas' => json_encode(['Q1' => 'B']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('resultados')->insert([
            'ra' => '1', 'periodo' => '2026/1', 'nome_avaliacao' => 'ENADE 2026',
            'respostas' => json_encode(['Q1' => 'B']),
            'notas_finais' => json_encode(['Total' => '10']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('legado:importar')->assertExitCode(0);
        $this->artisan('legado:importar')->assertExitCode(0);

        $this->assertDatabaseCount('provas', 1);
        $this->assertDatabaseCount('questoes', 1);
        $this->assertDatabaseCount('respostas', 1);
        $this->assertDatabaseCount('resultado_metricas', 1);
    }
}
