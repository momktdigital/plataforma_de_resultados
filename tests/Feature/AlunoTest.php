<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlunoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_cria_aluno_com_dados_obrigatorios(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/alunos', [
            'ra' => '2026001',
            'cpf' => '123.456.789-09',
            'data_nascimento' => '15/03/2000',
            'nome' => 'Fulano de Tal',
        ]);

        $response->assertRedirect(route('alunos.index'));
        $aluno = Aluno::where('ra', '2026001')->firstOrFail();
        $this->assertSame('12345678909', $aluno->cpf);
        $this->assertSame('2000-03-15', $aluno->data_nascimento->format('Y-m-d'));
        $this->assertSame('Fulano de Tal', $aluno->nome);
    }

    public function test_ra_e_cpf_duplicados_sao_rejeitados(): void
    {
        Aluno::create([
            'ra' => '2026001',
            'cpf' => '12345678909',
            'data_nascimento' => '2000-01-01',
        ]);

        $response = $this->actingAs($this->admin(), 'admin')->post('/alunos', [
            'ra' => '2026001',
            'cpf' => '98765432100',
            'data_nascimento' => '01/01/2000',
        ]);

        $response->assertSessionHasErrors('ra');
        $this->assertDatabaseCount('alunos', 1);
    }

    public function test_atualiza_aluno_existente(): void
    {
        $aluno = Aluno::create([
            'ra' => '2026001',
            'cpf' => '12345678909',
            'data_nascimento' => '2000-01-01',
        ]);

        $response = $this->actingAs($this->admin(), 'admin')->put("/alunos/{$aluno->id}", [
            'ra' => '2026001',
            'cpf' => '12345678909',
            'data_nascimento' => '02/02/2002',
            'nome' => 'Novo Nome',
        ]);

        $response->assertRedirect(route('alunos.index'));
        $aluno->refresh();
        $this->assertSame('Novo Nome', $aluno->nome);
        $this->assertSame('2002-02-02', $aluno->data_nascimento->format('Y-m-d'));
    }

    public function test_exclui_aluno(): void
    {
        $aluno = Aluno::create([
            'ra' => '2026001',
            'cpf' => '12345678909',
            'data_nascimento' => '2000-01-01',
        ]);

        $response = $this->actingAs($this->admin(), 'admin')->delete("/alunos/{$aluno->id}");

        $response->assertRedirect(route('alunos.index'));
        $this->assertDatabaseMissing('alunos', ['id' => $aluno->id]);
    }

    public function test_busca_filtra_por_ra_cpf_ou_nome(): void
    {
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'data_nascimento' => '2000-01-01', 'nome' => 'Ana Silva']);
        Aluno::create(['ra' => '2026002', 'cpf' => '98765432100', 'data_nascimento' => '2000-01-01', 'nome' => 'Bruno Souza']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/alunos?search=Ana');

        $response->assertSee('Ana Silva');
        $response->assertDontSee('Bruno Souza');
    }

    public function test_guest_nao_acessa_alunos(): void
    {
        $this->admin();

        $this->get('/alunos')->assertRedirect(route('login'));
    }
}
