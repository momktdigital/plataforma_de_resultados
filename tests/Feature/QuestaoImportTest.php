<?php

namespace Tests\Feature;

use App\Jobs\ImportarQuestoesJob;
use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\QuestaoImportService;
use App\Services\ResumoResultadoService;
use App\Support\ImportStatusTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        $avaliacao = Avaliacao::create([]);

        $csv = "Questão,Gabarito\n1,B\n2,C\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $response = $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $response->assertRedirect(route('avaliacoes.questoes.import', $avaliacao));
        $this->assertDatabaseCount('questoes', 2);
        $this->assertDatabaseHas('questoes', ['numero' => 1, 'gabarito' => 'B', 'bloom_nivel' => null]);
    }

    public function test_linha_sem_gabarito_e_ignorada_sem_derrubar_o_import(): void
    {
        $avaliacao = Avaliacao::create([]);

        $csv = "Questão,Gabarito\n1,B\n2,\n3,A\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseCount('questoes', 2);
        $this->assertDatabaseMissing('questoes', ['numero' => 2]);
    }

    public function test_metadados_opcionais_sao_gravados_quando_presentes(): void
    {
        $avaliacao = Avaliacao::create([]);

        $csv = "Questão,Gabarito,Bloom (nível),Dificuldade Pedagógica,Matriz (período),Matriz (disciplina)\n"
            ."1,B,Aplicar,Fácil,\"1;2\",\"Anatomia;Fisiologia\"\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertSame('Aplicar', $questao->bloom_nivel);
        $this->assertSame('facil', $questao->dificuldade_pedagogica);
        $this->assertCount(2, $questao->matrizes);
        $this->assertSame('Anatomia', $questao->matrizes[0]->disciplina);
        $this->assertSame(1, $questao->matrizes[0]->periodo);
    }

    public function test_coluna_dificuldade_pura_sem_qualificar_pedagogica_e_gravada(): void
    {
        // Bug relatado: planilhas de coordenadores costumam ter só uma
        // coluna "Dificuldade" (Fácil/Média/Difícil), sem qualificar
        // "pedagógica" — o padrão de cabeçalho exigia "pedagog" no nome e
        // deixava a coluna passar batido, sem gravar nada.
        $avaliacao = Avaliacao::create([]);

        $csv = "Questão,Gabarito,Área,Tema,Dificuldade\n"
            ."1,A,Pediatria,Infectologia,Média\n"
            ."2,A,GO,Gin Geral,Fácil\n"
            ."3,C,Clínica Médica,Cardiologia,Difícil\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $this->assertSame('medio', Questao::where('numero', 1)->firstOrFail()->dificuldade_pedagogica);
        $this->assertSame('facil', Questao::where('numero', 2)->firstOrFail()->dificuldade_pedagogica);
        $this->assertSame('dificil', Questao::where('numero', 3)->firstOrFail()->dificuldade_pedagogica);
    }

    public function test_coluna_dificuldade_tri_nao_e_confundida_com_a_pedagogica(): void
    {
        $avaliacao = Avaliacao::create([]);

        $csv = "Questão,Gabarito,Dificuldade,Dificuldade TRI\n"
            .'1,A,Fácil,"-1,25"'."\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertSame('facil', $questao->dificuldade_pedagogica);
        $this->assertEquals(-1.25, $questao->dificuldade_tri);
    }

    public function test_reimportar_atualiza_em_vez_de_duplicar(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();

        $primeiro = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");
        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $primeiro]);

        $segundo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,C\n");
        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $segundo]);

        $this->assertDatabaseCount('questoes', 1);
        $this->assertDatabaseHas('questoes', ['numero' => 1, 'gabarito' => 'C']);
    }

    public function test_reimportar_apos_exclusao_restaura_em_vez_de_falhar(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();

        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");
        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        Questao::where('numero', 1)->first()->delete();
        $this->assertSoftDeleted('questoes', ['numero' => 1]);

        $reimportado = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");
        $response = $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $reimportado]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('questoes', 1);
        $this->assertNotSoftDeleted('questoes', ['numero' => 1]);
    }

    public function test_importa_area_tema_habilidade_e_taxonomia_como_bloom_verbo(): void
    {
        $avaliacao = Avaliacao::create([]);

        $csv = "Questão,Gabarito,Área,tema,habilidade,taxonomia\n"
            .'1,B,"Clínica Médica","HIV/AIDS","E3 — Avaliação e Julgamento Ético-Profissional","Avaliar"'."\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertSame('Clínica Médica', $questao->area);
        $this->assertSame('HIV/AIDS', $questao->tema);
        $this->assertSame('E3 — Avaliação e Julgamento Ético-Profissional', $questao->habilidade);
        $this->assertSame('Avaliar', $questao->bloom_verbo);
    }

    public function test_coluna_sistema_nao_e_confundida_com_tema(): void
    {
        $avaliacao = Avaliacao::create([]);

        // "Sistema" contém "tema" como substring — a coluna de tema só deve
        // casar com a palavra inteira, não com pedaços de outras palavras.
        $csv = "Questão,Gabarito,Sistema\n1,B,Cardiovascular\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertNull($questao->tema);
    }

    public function test_referencias_a_b_c_viram_linhas_em_questao_referencias(): void
    {
        $avaliacao = Avaliacao::create([]);

        $csv = "Questão,Gabarito,Matriz Prova A,Matriz Prova B,DCN A,PPC A,PPC B,PPC C\n"
            .'1,B,"Item 1","Item 2","DCN X","P1","P2","P3"'."\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

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
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();

        $primeiro = UploadedFile::fake()->createWithContent(
            'gabarito.csv',
            "Questão,Gabarito,DCN A\n1,B,\"DCN X\"\n"
        );
        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $primeiro]);

        // Reimporta só com Gabarito — sem a coluna de DCN.
        $segundo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,C\n");
        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $segundo]);

        $questao = Questao::where('numero', 1)->firstOrFail();
        $this->assertSame('C', $questao->gabarito);
        $this->assertCount(1, $questao->referencias()->where('tipo', 'dcn')->get());
    }

    public function test_reimportar_gabarito_recalcula_o_resumo_do_boletim(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '123', 'questao_numero' => 1, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        $this->assertDatabaseHas('resultado_resumos', ['ra' => '123', 'acertos' => 0, 'total' => 1]);

        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");
        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('resultado_resumos', ['ra' => '123', 'acertos' => 1, 'total' => 1]);
    }

    public function test_import_enfileira_o_job_em_vez_de_rodar_na_requisicao(): void
    {
        Queue::fake();

        $avaliacao = Avaliacao::create([]);
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo])
            ->assertRedirect(route('avaliacoes.questoes.import', $avaliacao));

        Queue::assertPushed(ImportarQuestoesJob::class);
        $this->assertDatabaseCount('questoes', 0);
    }

    public function test_import_registra_admin_arquivo_e_contagem_na_trilha_de_auditoria(): void
    {
        $avaliacao = Avaliacao::create([]);
        $admin = $this->admin();
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");

        $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('atividades', [
            'admin_id' => $admin->id,
            'acao' => 'import.questoes',
            'alvo_tipo' => 'Avaliacao',
            'alvo_id' => (string) $avaliacao->codigo,
        ]);
    }

    public function test_job_de_import_de_questoes_registra_status_processando_e_concluido(): void
    {
        $avaliacao = Avaliacao::create([]);
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', "Questão,Gabarito\n1,B\n");
        $caminho = $arquivo->store('imports');

        (new ImportarQuestoesJob($avaliacao->codigo, $caminho, $arquivo->getClientOriginalName()))->handle(
            app(QuestaoImportService::class),
            app(ResumoResultadoService::class),
        );

        $status = ImportStatusTracker::status('questoes', (string) $avaliacao->codigo);
        $this->assertSame('concluido', $status['status']);
        $this->assertDatabaseCount('questoes', 1);
    }

    public function test_uma_linha_com_gabarito_invalido_para_o_banco_nao_derruba_o_import_inteiro(): void
    {
        // SQLite (usado nos testes) não aplica limite de varchar nem modo
        // estrito como o MySQL de produção — um trigger reproduz a mesma
        // falha real (gabarito varchar(5) rejeitando um valor mais longo)
        // pra provar que uma célula ruim não derruba o lote inteiro.
        DB::unprepared("
            CREATE TRIGGER limitar_gabarito
            BEFORE INSERT ON questoes
            FOR EACH ROW WHEN length(NEW.gabarito) > 5
            BEGIN
                SELECT RAISE(ABORT, 'gabarito muito longo');
            END
        ");

        $avaliacao = Avaliacao::create([]);
        $csv = "Questão,Gabarito\n1,B\n2,ABCDEFGHIJ\n3,C\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $this->actingAs($this->admin(), 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('questoes', ['numero' => 1, 'gabarito' => 'B']);
        $this->assertDatabaseHas('questoes', ['numero' => 3, 'gabarito' => 'C']);
        $this->assertDatabaseMissing('questoes', ['numero' => 2]);

        $status = ImportStatusTracker::status('questoes', (string) $avaliacao->codigo);
        $this->assertSame('concluido', $status['status']);
        $this->assertNotEmpty($status['ignoradas']);
        $this->assertStringContainsString('gabarito muito longo', $status['ignoradas'][0]['motivo']);

        DB::unprepared('DROP TRIGGER limitar_gabarito');
    }

    public function test_dry_run_mostra_o_resumo_mas_nao_grava_nada(): void
    {
        $avaliacao = Avaliacao::create([]);

        $csv = "Questão,Gabarito\n1,B\n2,C\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'admin')
            ->post("/avaliacoes/{$avaliacao->codigo}/questoes/import", ['arquivo' => $arquivo, 'dry_run' => '1']);

        $response->assertRedirect(route('avaliacoes.questoes.import', $avaliacao));
        $response->assertSessionHas('status');
        $this->assertStringContainsString('Simulação', session('status'));

        $this->assertDatabaseCount('questoes', 0);

        $status = ImportStatusTracker::status('questoes', (string) $avaliacao->codigo);
        $this->assertTrue($status['dryRun']);
        $this->assertSame('concluido', $status['status']);
        $this->assertStringContainsString('2', $status['resumo']);

        $this->assertDatabaseMissing('atividades', ['acao' => 'import.questoes']);
    }

    public function test_job_de_import_de_questoes_registra_status_erro_quando_falha(): void
    {
        $avaliacao = Avaliacao::create([]);
        $job = new ImportarQuestoesJob($avaliacao->codigo, 'imports/inexistente.csv', 'inexistente.csv');

        $job->failed(new \RuntimeException('falha simulada'));

        $status = ImportStatusTracker::status('questoes', (string) $avaliacao->codigo);
        $this->assertSame('erro', $status['status']);
        $this->assertSame('falha simulada', $status['erro']);
    }
}
