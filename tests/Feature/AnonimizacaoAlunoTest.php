<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoMetrica;
use App\Models\VerificacaoEmail;
use App\Services\AnonimizacaoAlunoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class AnonimizacaoAlunoTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonimiza_ra_no_historico_e_apaga_o_cadastro_de_acesso(): void
    {
        $aluno = Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'nome' => 'Fulano de Tal']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'A']);
        ResultadoMetrica::create(['avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => '2026001', 'nome_metrica' => 'Total', 'valor' => '10']);

        $resultado = app(AnonimizacaoAlunoService::class)->anonimizar('2026001', null);

        $this->assertStringStartsWith('ANON-', $resultado['token']);
        $this->assertSame(1, $resultado['avaliacoes_afetadas']);

        $this->assertDatabaseMissing('alunos', ['id' => $aluno->id]);
        $this->assertDatabaseMissing('respostas', ['ra' => '2026001']);
        $this->assertDatabaseMissing('resultado_metricas', ['ra' => '2026001']);

        $resposta = Resposta::first();
        $this->assertSame($resultado['token'], $resposta->ra);
        $this->assertNull($resposta->cpf);
        $this->assertNull($resposta->aluno_id);
        $this->assertSame($resultado['token'], $resposta->aluno_chave);

        $metrica = ResultadoMetrica::first();
        $this->assertSame($resultado['token'], $metrica->ra);
    }

    public function test_preserva_a_estatistica_agregada_da_avaliacao(): void
    {
        $aluno = Aluno::create(['ra' => '2026001']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => 'outro-aluno', 'questao_numero' => 1, 'resposta' => 'B']);

        app(AnonimizacaoAlunoService::class)->anonimizar('2026001', null);

        // O total de respostas à questão continua o mesmo — só o "dono" de
        // uma delas deixou de ser identificável.
        $this->assertSame(2, Resposta::where('avaliacao_codigo', $avaliacao->codigo)->where('questao_numero', 1)->count());
        $this->assertDatabaseHas('resultado_resumos', ['avaliacao_codigo' => $avaliacao->codigo]);
        $this->assertSame(2, DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacao->codigo)->count());
    }

    public function test_anonimiza_por_cpf_e_remove_verificacao_de_email_pendente(): void
    {
        $aluno = Aluno::create(['ra' => '2026002', 'cpf' => '98765432100', 'email' => 'aluno@example.com']);
        VerificacaoEmail::create(['cpf' => '98765432100', 'codigo' => '123456', 'expira_em' => Carbon::now()->addMinutes(10)]);

        app(AnonimizacaoAlunoService::class)->anonimizar(null, '98765432100');

        $this->assertDatabaseMissing('alunos', ['id' => $aluno->id]);
        $this->assertDatabaseMissing('verificacoes_email', ['cpf' => '98765432100']);
    }

    public function test_exige_ra_ou_cpf(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AnonimizacaoAlunoService::class)->anonimizar(null, null);
    }

    public function test_funciona_mesmo_sem_cadastro_de_acesso_existente(): void
    {
        // O aluno já pode ter sido excluído antes (AlunoController::destroy)
        // — o histórico de respostas/métricas continua lá, então o comando
        // ainda precisa funcionar sem uma linha em `alunos`.
        $avaliacao = Avaliacao::create([]);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026003', 'questao_numero' => 1, 'resposta' => 'A']);

        $resultado = app(AnonimizacaoAlunoService::class)->anonimizar('2026003', null);

        $this->assertSame($resultado['token'], Resposta::first()->ra);
    }

    public function test_pelo_ra_acha_tambem_os_resultados_importados_so_com_cpf(): void
    {
        // Nos dados reais a maioria dos resultados tem só CPF (RA nulo): o cadastro liga RA e CPF.
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'nome' => 'Fulano de Tal']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'cpf' => '12345678909', 'questao_numero' => 1, 'resposta' => 'A']);
        ResultadoMetrica::create(['avaliacao_codigo' => $avaliacao->codigo, 'cpf' => '12345678909', 'nome_metrica' => 'Total', 'valor' => '10']);

        $resultado = app(AnonimizacaoAlunoService::class)->anonimizar('2026001', null);

        $this->assertDatabaseMissing('respostas', ['cpf' => '12345678909']);
        $this->assertDatabaseMissing('resultado_metricas', ['cpf' => '12345678909']);
        $this->assertSame($resultado['token'], Resposta::first()->aluno_chave);
        $this->assertSame(0, DB::table('resultado_resumos')->where('cpf', '12345678909')->count());
    }

    public function test_pelo_cpf_acha_tambem_os_resultados_importados_so_com_ra(): void
    {
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'A']);

        $resultado = app(AnonimizacaoAlunoService::class)->anonimizar(null, '12345678909');

        $this->assertDatabaseMissing('respostas', ['ra' => '2026001']);
        $this->assertSame($resultado['token'], Resposta::first()->ra);
    }

    public function test_cpf_com_mascara_funciona_como_o_cpf_so_com_digitos(): void
    {
        $aluno = Aluno::create(['ra' => '2026001', 'cpf' => '12345678909']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'cpf' => '12345678909', 'questao_numero' => 1, 'resposta' => 'A']);
        // Importado por outro caminho, com a máscara gravada.
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'cpf' => '123.456.789-09', 'periodo' => '2º', 'questao_numero' => 1, 'resposta' => 'B']);
        VerificacaoEmail::create(['cpf' => '123.456.789-09', 'codigo' => '123456', 'expira_em' => Carbon::now()->addMinutes(10)]);

        app(AnonimizacaoAlunoService::class)->anonimizar(null, '123.456.789-09');

        $this->assertDatabaseMissing('alunos', ['id' => $aluno->id]);
        $this->assertSame(0, Resposta::where('cpf', 'like', '%456%')->count());
        $this->assertDatabaseCount('verificacoes_email', 0);
    }

    public function test_o_registro_de_auditoria_nao_guarda_ra_nem_cpf(): void
    {
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'A']);

        $resultado = app(AnonimizacaoAlunoService::class)->anonimizar('2026001', '123.456.789-09');

        $registro = DB::table('atividades')->where('acao', 'aluno.anonimizado')->first();
        $this->assertNotNull($registro);
        $this->assertStringContainsString($resultado['token'], $registro->detalhes);
        $this->assertStringNotContainsString('2026001', $registro->detalhes);
        $this->assertStringNotContainsString('12345678909', $registro->detalhes);
        $this->assertStringNotContainsString('123.456.789-09', $registro->detalhes);
    }

    public function test_registros_antigos_da_auditoria_que_citam_a_pessoa_sao_redigidos(): void
    {
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'A']);

        $inserir = fn (string $acao, array $detalhes, ?string $alvoId = null) => DB::table('atividades')->insertGetId([
            'admin_username' => 'adm', 'acao' => $acao, 'alvo_tipo' => 'Avaliacao', 'alvo_id' => $alvoId,
            'detalhes' => json_encode($detalhes), 'created_at' => now(),
        ]);
        $porCpf = $inserir('respondente.excluido', ['aluno_chave' => '12345678909', 'periodo' => '2º']);
        $porRa = $inserir('respondente.vinculo_alterado', ['aluno_chave_antes' => '2026001', 'aluno_chave_depois' => '12345678909']);
        $noAlvo = $inserir('qualquer', ['x' => 1], '2026001');
        // Não pode ser reescrito: o RA só casa por igualdade exata (aqui é parte de outro número).
        $outro = $inserir('import.resultados', ['arquivo' => 'resultados-2026001-final.xlsx', 'aluno_chave' => '99999999999']);

        $resultado = app(AnonimizacaoAlunoService::class)->anonimizar('2026001', null);
        $token = $resultado['token'];

        $detalhes = fn (int $id) => json_decode(DB::table('atividades')->where('id', $id)->value('detalhes'), true);
        $this->assertSame($token, $detalhes($porCpf)['aluno_chave']);
        $this->assertSame('2º', $detalhes($porCpf)['periodo'], 'o resto do registro fica');
        $this->assertSame([$token, $token], array_values($detalhes($porRa)));
        $this->assertSame($token, DB::table('atividades')->where('id', $noAlvo)->value('alvo_id'));
        $this->assertSame('resultados-2026001-final.xlsx', $detalhes($outro)['arquivo']);
        $this->assertSame('99999999999', $detalhes($outro)['aluno_chave']);
    }

    public function test_o_curso_dos_resultados_anonimizados_continua_o_mesmo(): void
    {
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'curso' => 'MEDICINA']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026', 'data_avaliacao' => '2026-03-10']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'A']);
        app(\App\Services\ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        $this->assertSame('MEDICINA', DB::table('resultado_resumos')->value('curso'));

        $resultado = app(AnonimizacaoAlunoService::class)->anonimizar('2026001', null);

        $resumo = DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacao->codigo)->first();
        $this->assertSame($resultado['token'], $resumo->aluno_chave);
        $this->assertSame('MEDICINA', $resumo->curso, 'a pessoa não pode sumir dos números do curso');
    }

    public function test_pessoa_com_ra_e_cpf_respondendo_a_mesma_questao_nao_quebra_o_indice_unico(): void
    {
        // Mesma pessoa, mesma questão, mesma avaliação: uma linha identificada pelo RA e outra pelo CPF
        // (dado duplicado de dois imports). Unificar num token só violaria o índice único.
        Aluno::create(['ra' => '2026001', 'cpf' => '12345678909']);
        $avaliacao = Avaliacao::create(['nome' => 'ENADE 2026']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2026001', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'cpf' => '12345678909', 'questao_numero' => 1, 'resposta' => 'B']);

        $resultado = app(AnonimizacaoAlunoService::class)->anonimizar('2026001', null);

        $this->assertCount(2, $resultado['tokens']);
        $this->assertSame(2, Resposta::where('ra', 'like', 'ANON-%')->count());
        $this->assertSame(0, Resposta::whereNotNull('cpf')->count());
    }

    public function test_comando_cli_cancela_sem_confirmacao(): void
    {
        Aluno::create(['ra' => '2026001']);

        $this->artisan('aluno:anonimizar', ['--ra' => '2026001'])
            ->expectsConfirmation('Isto vai substituir RA 2026001 por um token anônimo em todo o histórico de respostas/métricas e apagar o cadastro de acesso. Não pode ser desfeito. Continuar?', 'no')
            ->assertSuccessful();

        $this->assertDatabaseHas('alunos', ['ra' => '2026001']);
    }

    public function test_comando_cli_anonimiza_apos_confirmacao(): void
    {
        Aluno::create(['ra' => '2026001']);

        $this->artisan('aluno:anonimizar', ['--ra' => '2026001'])
            ->expectsConfirmation('Isto vai substituir RA 2026001 por um token anônimo em todo o histórico de respostas/métricas e apagar o cadastro de acesso. Não pode ser desfeito. Continuar?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('alunos', ['ra' => '2026001']);
    }

    public function test_comando_cli_falha_sem_ra_nem_cpf(): void
    {
        $this->artisan('aluno:anonimizar')->assertFailed();
    }
}
