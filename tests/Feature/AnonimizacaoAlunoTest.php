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
