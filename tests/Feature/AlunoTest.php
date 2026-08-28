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

    public function test_email_institucional_e_derivado_do_ra(): void
    {
        $aluno = Aluno::create(['ra' => '67926', 'cpf' => '12345678909', 'data_nascimento' => '2000-01-01']);

        $this->assertSame('67926@somos.unifaa.edu.br', $aluno->email_institucional);
    }

    public function test_foto_url_usa_cod_perfil_e_tamanho(): void
    {
        $comFoto = Aluno::create(['ra' => '1', 'cod_perfil' => '222710']);
        $semFoto = Aluno::create(['ra' => '2']);

        $this->assertSame('https://faa.jacad.com.br/academico/images/perfil-v2/222710/300', $comFoto->fotoUrl(300));
        $this->assertNull($semFoto->fotoUrl());
    }

    public function test_cria_aluno_sem_cpf_e_nascimento(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/alunos', [
            'ra' => '2026001',
            'nome' => 'Sem CPF Ainda',
        ]);

        $response->assertRedirect(route('alunos.index'));
        $aluno = Aluno::where('ra', '2026001')->firstOrFail();
        $this->assertNull($aluno->cpf);
        $this->assertNull($aluno->data_nascimento);
    }

    public function test_atualiza_dados_pessoais_e_de_matricula_do_aluno(): void
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
            'sexo' => 'FEMININO',
            'cor_raca' => 'PARDA',
            'religiao' => 'CATOLICA',
            'estado_civil' => 'SOLTEIRO',
            'cidade' => 'VALENCA',
            'uf' => 'rj',
            'celular' => '24999998888',
            'matriz' => 'MEDICINA 3390',
            'cod_perfil' => '222710',
        ]);

        $response->assertRedirect(route('alunos.index'));
        $aluno->refresh();
        $this->assertSame('FEMININO', $aluno->sexo);
        $this->assertSame('PARDA', $aluno->cor_raca);
        $this->assertSame('CATOLICA', $aluno->religiao);
        $this->assertSame('SOLTEIRO', $aluno->estado_civil);
        $this->assertSame('VALENCA', $aluno->cidade);
        $this->assertSame('RJ', $aluno->uf);
        $this->assertSame('24999998888', $aluno->celular);
        $this->assertSame('MEDICINA 3390', $aluno->matriz);
        $this->assertSame('222710', $aluno->cod_perfil);
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

    public function test_ordena_lista_por_ra_quando_pedido_na_query_string(): void
    {
        Aluno::create(['ra' => '2026002', 'nome' => 'Bruno']);
        Aluno::create(['ra' => '2026001', 'nome' => 'Ana']);

        $response = $this->actingAs($this->admin(), 'admin')->get('/alunos?sort=ra&direction=desc');

        $response->assertOk();
        $response->assertSeeInOrder(['2026002', '2026001']);
    }

    public function test_ignora_coluna_de_ordenacao_nao_permitida(): void
    {
        Aluno::create(['ra' => '2026001', 'nome' => 'Ana']);

        // "id" não está na lista de colunas ordenáveis — cai pro padrão
        // (nome) em vez de estourar erro de SQL com uma coluna arbitrária
        // vinda da query string.
        $response = $this->actingAs($this->admin(), 'admin')->get('/alunos?sort=id&direction=asc');

        $response->assertOk();
        $response->assertSee('Ana');
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

    public function test_tela_de_novo_aluno_renderiza_formulario_vazio(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->get('/alunos/novo');

        $response->assertOk();
        $response->assertViewIs('admin.alunos.form');
        $response->assertSee('action="'.route('alunos.store').'"', false);
    }

    public function test_tela_de_editar_aluno_renderiza_formulario_preenchido(): void
    {
        $aluno = Aluno::create([
            'ra' => '2026001',
            'cpf' => '12345678909',
            'data_nascimento' => '2000-01-01',
            'nome' => 'Fulano de Tal',
        ]);

        $response = $this->actingAs($this->admin(), 'admin')->get("/alunos/{$aluno->id}/editar");

        $response->assertOk();
        $response->assertViewIs('admin.alunos.form');
        $response->assertSee('Fulano de Tal');
        $response->assertSee('action="'.route('alunos.update', $aluno).'"', false);
    }
}
