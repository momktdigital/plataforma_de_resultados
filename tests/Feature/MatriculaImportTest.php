<?php

namespace Tests\Feature;

use App\Jobs\ImportarMatriculaJob;
use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Curso;
use App\Services\MatriculaImportService;
use App\Support\ImportStatusTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MatriculaImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_importa_matricula_com_campos_obrigatorios(): void
    {
        $csv = "RA,Nome,Per. Letivo,Curso,Período\n2026001,Ana Silva,2026/1,Medicina,5\n";
        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', $csv);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/alunos/importar', ['arquivo' => $arquivo]);

        $response->assertRedirect(route('alunos.importar'));
        $this->assertDatabaseHas('alunos', [
            'ra' => '2026001',
            'nome' => 'Ana Silva',
            'periodo_letivo' => '2026/1',
            'curso' => 'MEDICINA',
            'periodo' => '5º',
        ]);
        $this->assertDatabaseHas('cursos', ['nome' => 'MEDICINA']);
    }

    public function test_importa_dados_pessoais_da_planilha(): void
    {
        $csv = "RA,Nome,Per. Letivo,Curso,Matriz,Período,Sexo,Cor/Raça,Religião,Estado Civil,Cidade,UF,Celular,CPF,Email,Cód. Perfil\n"
            ."2026001,Ana Silva,2026/1,Medicina,MEDICINA 3390,5,FEMININO,PARDA,CATOLICA,SOLTEIRO,VALENCA,RJ,24999998888,12345678909,ana@example.com,222710\n";
        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', $csv);

        $this->actingAs($this->admin(), 'admin')->post('/alunos/importar', ['arquivo' => $arquivo]);

        $aluno = Aluno::where('ra', '2026001')->firstOrFail();
        $this->assertSame('MEDICINA 3390', $aluno->matriz);
        $this->assertSame('FEMININO', $aluno->sexo);
        $this->assertSame('PARDA', $aluno->cor_raca);
        $this->assertSame('CATOLICA', $aluno->religiao);
        $this->assertSame('SOLTEIRO', $aluno->estado_civil);
        $this->assertSame('VALENCA', $aluno->cidade);
        $this->assertSame('RJ', $aluno->uf);
        $this->assertSame('24999998888', $aluno->celular);
        $this->assertSame('222710', $aluno->cod_perfil);
        $this->assertSame('2026001@somos.unifaa.edu.br', $aluno->email_institucional);
    }

    public function test_linha_com_cpf_duplicado_e_ignorada_sem_derrubar_as_demais(): void
    {
        Aluno::create(['ra' => '999999', 'cpf' => '12345678909', 'data_nascimento' => '2000-01-01']);

        $csv = "RA,Per. Letivo,Curso,Período,CPF\n"
            ."2026001,2026/1,Medicina,1,12345678909\n" // CPF já usado por outro RA
            ."2026002,2026/1,Medicina,2,98765432100\n";
        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', $csv);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/alunos/importar', ['arquivo' => $arquivo]);

        $response->assertRedirect(route('alunos.importar'));
        $status = ImportStatusTracker::status('matricula');
        $this->assertSame('concluido', $status['status']);
        $this->assertNotEmpty($status['ignoradas']);
        $this->assertDatabaseMissing('alunos', ['ra' => '2026001']);
        $this->assertDatabaseHas('alunos', ['ra' => '2026002', 'cpf' => '98765432100']);
    }

    public function test_linha_sem_curso_e_ignorada_sem_derrubar_o_import(): void
    {
        $csv = "RA,Per. Letivo,Curso,Período\n2026001,2026/1,Medicina,5\n2026002,2026/1,,5\n";
        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post('/alunos/importar', ['arquivo' => $arquivo]);

        $this->assertDatabaseCount('alunos', 1);
        $this->assertDatabaseMissing('alunos', ['ra' => '2026002']);
    }

    public function test_cpf_e_nascimento_sao_opcionais(): void
    {
        $csv = "RA,Per. Letivo,Curso,Período\n2026001,2026/1,Medicina,1\n";
        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', $csv);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/alunos/importar', ['arquivo' => $arquivo]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('alunos', ['ra' => '2026001', 'cpf' => null]);
    }

    public function test_reimportar_pelo_ra_atualiza_em_vez_de_duplicar_e_preserva_identidade(): void
    {
        Aluno::create([
            'ra' => '2026001',
            'cpf' => '12345678909',
            'data_nascimento' => '2000-01-01',
            'nome' => 'Nome Cadastrado Manualmente',
        ]);

        $csv = "RA,Per. Letivo,Curso,Período,Turma\n2026001,2026/1,Medicina,6,B\n";
        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post('/alunos/importar', ['arquivo' => $arquivo]);

        $this->assertDatabaseCount('alunos', 1);
        $this->assertDatabaseHas('alunos', [
            'ra' => '2026001',
            'cpf' => '12345678909',
            'nome' => 'Nome Cadastrado Manualmente',
            'periodo' => '6º',
            'turma' => 'B',
        ]);
    }

    public function test_normaliza_periodo_letivo_com_ponto(): void
    {
        $csv = "RA,Per. Letivo,Curso,Período\n2026001,2026.1,Medicina,P3\n";
        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post('/alunos/importar', ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('alunos', [
            'ra' => '2026001',
            'periodo_letivo' => '2026/1',
            'periodo' => '3º',
        ]);
    }

    public function test_nao_duplica_curso_ja_registrado(): void
    {
        Curso::create(['nome' => 'MEDICINA']);

        $csv = "RA,Per. Letivo,Curso,Período\n2026001,2026/1,Medicina,1\n";
        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post('/alunos/importar', ['arquivo' => $arquivo]);

        $this->assertDatabaseCount('cursos', 1);
    }

    public function test_guest_nao_acessa_import_de_matricula(): void
    {
        $this->admin();

        $this->get('/alunos/importar')->assertRedirect(route('login'));
    }

    public function test_import_enfileira_o_job_em_vez_de_rodar_na_requisicao(): void
    {
        Queue::fake();

        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', "RA,Per. Letivo,Curso,Período\n2026001,2026/1,Medicina,5\n");

        $this->actingAs($this->admin(), 'admin')
            ->post('/alunos/importar', ['arquivo' => $arquivo])
            ->assertRedirect(route('alunos.importar'));

        Queue::assertPushed(ImportarMatriculaJob::class);
        $this->assertDatabaseCount('alunos', 0);
    }

    public function test_import_registra_admin_arquivo_e_contagem_na_trilha_de_auditoria(): void
    {
        $admin = $this->admin();
        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', "RA,Per. Letivo,Curso,Período\n2026001,2026/1,Medicina,5\n");

        $this->actingAs($admin, 'admin')->post('/alunos/importar', ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('atividades', [
            'admin_id' => $admin->id,
            'acao' => 'import.matricula',
        ]);
    }

    public function test_job_de_import_de_matricula_registra_status_processando_e_concluido(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('matricula.csv', "RA,Per. Letivo,Curso,Período\n2026001,2026/1,Medicina,5\n");
        $caminho = $arquivo->store('imports');

        (new ImportarMatriculaJob($caminho, $arquivo->getClientOriginalName()))->handle(app(MatriculaImportService::class));

        $status = ImportStatusTracker::status('matricula');
        $this->assertSame('concluido', $status['status']);
        $this->assertDatabaseHas('alunos', ['ra' => '2026001']);
    }

    public function test_job_de_import_de_matricula_registra_status_erro_quando_falha(): void
    {
        $job = new ImportarMatriculaJob('imports/inexistente.csv', 'inexistente.csv');

        $job->failed(new \RuntimeException('falha simulada'));

        $status = ImportStatusTracker::status('matricula');
        $this->assertSame('erro', $status['status']);
        $this->assertSame('falha simulada', $status['erro']);
    }
}
