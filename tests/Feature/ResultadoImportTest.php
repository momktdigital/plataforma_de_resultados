<?php

namespace Tests\Feature;

use App\Jobs\ImportarResultadosJob;
use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Atividade;
use App\Models\Avaliacao;
use App\Models\ConfiguracaoSistema;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ResultadoImportService;
use App\Services\ResumoResultadoService;
use App\Support\ImportStatusTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        $avaliacao = Avaliacao::create([]);

        $csv = "RA,Questão,Resposta\n12345,1,B\n12345,2,C\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $response->assertRedirect(route('avaliacoes.resultados.import', $avaliacao));
        $this->assertDatabaseCount('respostas', 2);
        $this->assertDatabaseHas('respostas', ['ra' => '12345', 'questao_numero' => 1, 'resposta' => 'B']);
    }

    public function test_importa_resultados_por_cpf_e_resolve_aluno_existente(): void
    {
        $aluno = Aluno::create([
            'ra' => '999',
            'cpf' => '11122233344',
            'data_nascimento' => '2000-01-01',
        ]);
        $avaliacao = Avaliacao::create([]);

        $csv = "CPF,Questão,Resposta\n111.222.333-44,1,A\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('respostas', [
            'cpf' => '11122233344',
            'aluno_id' => $aluno->id,
        ]);
    }

    public function test_linha_sem_cpf_e_sem_ra_e_ignorada(): void
    {
        $avaliacao = Avaliacao::create([]);

        $csv = "RA,Questão,Resposta\n123,1,B\n,2,C\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseCount('respostas', 1);
    }

    public function test_dry_run_mostra_o_resumo_mas_nao_grava_nada(): void
    {
        $avaliacao = Avaliacao::create([]);

        $csv = "RA,Questão,Resposta\n123,1,B\n456,1,A\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo, 'dry_run' => '1']);

        $response->assertRedirect(route('avaliacoes.resultados.import', $avaliacao));
        $response->assertSessionHas('status');
        $this->assertStringContainsString('Simulação', session('status'));

        $this->assertDatabaseCount('respostas', 0);
        $this->assertDatabaseCount('resultado_resumos', 0);

        $status = ImportStatusTracker::status('resultados', (string) $avaliacao->codigo);
        $this->assertTrue($status['dryRun']);
        $this->assertSame('concluido', $status['status']);

        $this->assertDatabaseMissing('atividades', ['acao' => 'import.resultados']);
    }

    public function test_linha_com_cpf_malformado_e_ignorada_mesmo_com_ra_valido(): void
    {
        $avaliacao = Avaliacao::create([]);

        // CPF com só 8 dígitos — inválido mesmo com um RA presente na mesma
        // linha, já que aluno_chave (COALESCE(cpf, ra)) priorizaria o CPF
        // malformado e nunca casaria com o aluno real.
        $csv = "RA,CPF,Questão,Resposta\n123,12345678,1,B\n456,11122233344,1,A\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseCount('respostas', 1);
        $this->assertDatabaseMissing('respostas', ['ra' => '123']);
        $this->assertDatabaseHas('respostas', ['ra' => '456', 'cpf' => '11122233344']);

        $status = ImportStatusTracker::status('resultados', (string) $avaliacao->codigo);
        $this->assertNotEmpty($status['ignoradas']);
        $this->assertStringContainsString('CPF inválido', $status['ignoradas'][0]['motivo']);
    }

    public function test_arquivo_sem_coluna_de_questao_e_rejeitado(): void
    {
        $avaliacao = Avaliacao::create([]);

        $csv = "RA,Resposta\n123,B\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $response->assertSessionHasErrors('arquivo');
        $this->assertDatabaseCount('respostas', 0);
    }

    public function test_reimportar_atualiza_em_vez_de_duplicar(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();

        $primeiro = UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n123,1,B\n");
        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $primeiro]);

        $segundo = UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n123,1,D\n");
        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $segundo]);

        $this->assertDatabaseCount('respostas', 1);
        $this->assertDatabaseHas('respostas', ['ra' => '123', 'questao_numero' => 1, 'resposta' => 'D']);
    }

    public function test_mesmo_aluno_em_periodos_diferentes_nao_se_sobrescreve(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();

        $primeiro = UploadedFile::fake()->createWithContent(
            'resultados.csv',
            "RA,Período,Questão,Resposta\n123,2025/1,1,B\n"
        );
        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $primeiro]);

        $segundo = UploadedFile::fake()->createWithContent(
            'resultados.csv',
            "RA,Período,Questão,Resposta\n123,2025/2,1,C\n"
        );
        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $segundo]);

        $this->assertDatabaseCount('respostas', 2);
        $this->assertDatabaseHas('respostas', ['ra' => '123', 'periodo' => '2025/1', 'resposta' => 'B']);
        $this->assertDatabaseHas('respostas', ['ra' => '123', 'periodo' => '2025/2', 'resposta' => 'C']);
    }

    public function test_reimportar_apos_exclusao_restaura_em_vez_de_falhar(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();

        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n123,1,B\n");
        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo]);

        Resposta::where('ra', '123')->first()->delete();
        $this->assertSoftDeleted('respostas', ['ra' => '123']);

        $reimportado = UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n123,1,B\n");
        $response = $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $reimportado]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('respostas', 1);
        $this->assertNotSoftDeleted('respostas', ['ra' => '123']);
    }

    public function test_import_recalcula_o_resumo_do_boletim(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'B']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 2, 'gabarito' => 'C']);

        $csv = "RA,Questão,Resposta\n123,1,B\n123,2,D\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('resultado_resumos', [
            'avaliacao_codigo' => $avaliacao->codigo,
            'ra' => '123',
            'acertos' => 1,
            'total' => 2,
            'percentual' => 50.0,
        ]);
    }

    public function test_uma_linha_com_resposta_invalida_para_o_banco_nao_derruba_o_import_inteiro(): void
    {
        // SQLite (usado nos testes) não aplica limite de varchar nem modo
        // estrito como o MySQL de produção — um trigger reproduz a mesma
        // falha real (resposta varchar(10) rejeitando um valor mais longo)
        // pra provar que uma célula ruim não derruba o lote inteiro.
        DB::unprepared("
            CREATE TRIGGER limitar_resposta
            BEFORE INSERT ON respostas
            FOR EACH ROW WHEN length(NEW.resposta) > 10
            BEGIN
                SELECT RAISE(ABORT, 'resposta muito longa');
            END
        ");

        $avaliacao = Avaliacao::create([]);
        $csv = "RA,Questão,Resposta\n1,1,B\n2,2,TEXTOMUITOLONGODEMAIS\n3,3,C\n";
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('respostas', ['ra' => '1', 'questao_numero' => 1, 'resposta' => 'B']);
        $this->assertDatabaseHas('respostas', ['ra' => '3', 'questao_numero' => 3, 'resposta' => 'C']);
        $this->assertDatabaseMissing('respostas', ['ra' => '2']);

        $status = ImportStatusTracker::status('resultados', (string) $avaliacao->codigo);
        $this->assertSame('concluido', $status['status']);
        $this->assertNotEmpty($status['ignoradas']);
        $this->assertStringContainsString('resposta muito longa', $status['ignoradas'][0]['motivo']);

        DB::unprepared('DROP TRIGGER limitar_resposta');
    }

    public function test_import_enfileira_o_job_em_vez_de_rodar_na_requisicao(): void
    {
        // Uma planilha de 100 mil+ linhas facilmente passa do tempo de
        // execução do PHP/timeout do proxy se processada na própria
        // requisição — este teste garante que o upload só ENFILEIRA o
        // trabalho (Queue::fake() intercepta antes do job realmente rodar).
        Queue::fake();

        $avaliacao = Avaliacao::create([]);
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n123,1,B\n");

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo])
            ->assertRedirect(route('avaliacoes.resultados.import', $avaliacao));

        Queue::assertPushed(ImportarResultadosJob::class);
        $this->assertDatabaseCount('respostas', 0);
    }

    public function test_import_registra_admin_arquivo_e_contagem_na_trilha_de_auditoria(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n123,1,B\n");

        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('atividades', [
            'admin_id' => $admin->id,
            'admin_username' => $admin->username,
            'acao' => 'import.resultados',
            'alvo_tipo' => 'Avaliacao',
            'alvo_id' => (string) $avaliacao->codigo,
        ]);

        $atividade = Atividade::where('acao', 'import.resultados')->firstOrFail();
        $this->assertSame('resultados.csv', $atividade->detalhes['arquivo']);
        $this->assertSame(1, $atividade->detalhes['criadas']);
    }

    public function test_job_de_import_de_resultados_registra_status_processando_e_concluido(): void
    {
        $avaliacao = Avaliacao::create([]);
        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n123,1,B\n");
        $caminho = $arquivo->store('imports');

        (new ImportarResultadosJob($avaliacao->codigo, $caminho, $arquivo->getClientOriginalName()))->handle(
            app(ResultadoImportService::class),
            app(ResumoResultadoService::class),
        );

        $status = ImportStatusTracker::status('resultados', (string) $avaliacao->codigo);
        $this->assertSame('concluido', $status['status']);
        $this->assertStringContainsString('1 criada(s)', $status['resumo']);
        $this->assertDatabaseCount('respostas', 1);
    }

    public function test_job_de_import_de_resultados_registra_status_erro_quando_falha(): void
    {
        $avaliacao = Avaliacao::create([]);
        $job = new ImportarResultadosJob($avaliacao->codigo, 'imports/inexistente.csv', 'inexistente.csv');

        $job->failed(new \RuntimeException('coluna de questão ausente'));

        $status = ImportStatusTracker::status('resultados', (string) $avaliacao->codigo);
        $this->assertSame('erro', $status['status']);
        $this->assertSame('coluna de questão ausente', $status['erro']);
    }

    public function test_tela_de_import_mostra_aviso_enquanto_processa_e_desabilita_o_botao(): void
    {
        $avaliacao = Avaliacao::create([]);
        ConfiguracaoSistema::definir("import_resultados_{$avaliacao->codigo}_status", 'processando');
        ConfiguracaoSistema::definir("import_resultados_{$avaliacao->codigo}_iniciado_em", now()->toIso8601String());

        $response = $this->actingAs($this->admin(), 'admin')
            ->get("/avaliacoes/{$avaliacao->codigo}/resultados/import");

        $response->assertOk();
        $response->assertSee('Import em andamento');
    }
}
