<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\AlunoMatricula;
use App\Models\Atividade;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\ConfiguracaoSistema;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\CoordenadorAlunosService;
use App\Services\CoordenadorDashboardService;
use App\Services\ReitorDashboardService;
use App\Services\ReitorRiscoService;
use App\Services\ResumoResultadoService;
use App\Support\RegraDeRisco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "O que é um estudante em risco" definido pela administração: nas Configurações (padrão da instituição) e, se for o
 * caso, em cada avaliação — e usado do mesmo jeito pela lista do coordenador e pelo painel da reitoria.
 */
class RegraDeRiscoTest extends TestCase
{
    use RefreshDatabase;

    private int $ra = 8000;

    private ?Admin $admin = null;

    private ?Admin $coordenador = null;

    private function admin(): Admin
    {
        return $this->admin ??= Admin::create(['username' => 'admin', 'email' => 'admin@example.test', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
    }

    private function coordenador(): Admin
    {
        if ($this->coordenador === null) {
            $this->coordenador = Admin::create(['username' => 'coord', 'email' => 'coord@example.test', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
            $this->coordenador->sincronizarCursos(['DIREITO']);
        }

        return $this->coordenador;
    }

    /** Aluno de DIREITO com $acertos (0–10) em $avaliacao; null = prova em branco. */
    private function aluno(Avaliacao $avaliacao, ?int $acertos, string $nome = 'Aluno'): Aluno
    {
        $this->ra++;
        $aluno = Aluno::create(['ra' => (string) $this->ra, 'nome' => "$nome {$this->ra}", 'curso' => 'DIREITO', 'periodo' => '3º']);
        AlunoMatricula::create(['aluno_id' => $aluno->id, 'curso' => 'DIREITO', 'periodo' => '3º', 'periodo_letivo' => '2026/1', 'status' => 'ATIVA']);
        foreach (range(1, 10) as $n) {
            Resposta::create([
                'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => $aluno->ra, 'periodo' => '3º',
                'questao_numero' => $n, 'resposta' => $acertos === null ? '' : ($n <= $acertos ? 'A' : 'B'),
            ]);
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $aluno;
    }

    private function avaliacao(string $nome = '2026/1 - Prova', ?int $categoriaId = null): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => '2026-03-10', 'categoria_id' => $categoriaId]);
        foreach (range(1, 10) as $n) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A']);
        }

        return $avaliacao;
    }

    /** Situação de cada aluno na lista do coordenador, por RA. @return array<string, string> */
    private function situacoes(): array
    {
        $coordenador = $this->coordenador();
        $escopo = app(CoordenadorDashboardService::class)->escopo($coordenador, '', '2026/1');

        return collect(app(CoordenadorAlunosService::class)->alunos($escopo))->pluck('situacao', 'ra')->all();
    }

    private function enviar(array $campos)
    {
        return $this->actingAs($this->admin(), 'admin')->post('/sistema/configuracoes', ['backup_manter_ultimos' => 5, 'risco_enviado' => 1, ...$campos]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Configurações do sistema
    // ------------------------------------------------------------------------------------------------------------

    public function test_sem_nada_gravado_vale_o_padrao(): void
    {
        $regra = RegraDeRisco::atual();

        $this->assertSame(60.0, $regra->acerto);
        $this->assertSame(2, $regra->faltas);
        $this->assertSame('ou', $regra->operador);
    }

    public function test_admin_grava_a_regra_nas_configuracoes_e_a_mudanca_e_auditada(): void
    {
        $this->enviar(['risco_acerto_ativo' => 1, 'risco_acerto' => '55,5', 'risco_faltas_ativo' => 1, 'risco_faltas' => 3, 'risco_operador' => 'e'])
            ->assertRedirect(route('sistema.configuracoes.index'))
            ->assertSessionHasNoErrors();

        $regra = RegraDeRisco::atual();
        $this->assertSame(55.5, $regra->acerto);
        $this->assertSame(3, $regra->faltas);
        $this->assertSame('e', $regra->operador);
        $this->assertSame(1, Atividade::where('acao', 'configuracao.risco_alterado')->count());

        // salvar a mesma regra de novo não gera outro registro de auditoria
        $this->enviar(['risco_acerto_ativo' => 1, 'risco_acerto' => '55.5', 'risco_faltas_ativo' => 1, 'risco_faltas' => 3, 'risco_operador' => 'e']);
        $this->assertSame(1, Atividade::where('acao', 'configuracao.risco_alterado')->count());
    }

    public function test_admin_pode_usar_so_acerto_ou_so_faltas(): void
    {
        $this->enviar(['risco_acerto_ativo' => 1, 'risco_acerto' => 70, 'risco_operador' => 'ou'])->assertSessionHasNoErrors();
        $this->assertSame(70.0, RegraDeRisco::atual()->acerto);
        $this->assertNull(RegraDeRisco::atual()->faltas);

        $this->enviar(['risco_faltas_ativo' => 1, 'risco_faltas' => 1, 'risco_operador' => 'ou'])->assertSessionHasNoErrors();
        $this->assertNull(RegraDeRisco::atual()->acerto);
        $this->assertSame(1, RegraDeRisco::atual()->faltas);
    }

    public function test_pelo_menos_um_criterio_e_obrigatorio(): void
    {
        $this->enviar(['risco_operador' => 'ou'])->assertSessionHasErrors('risco_acerto_ativo');

        $this->assertSame(60.0, RegraDeRisco::atual()->acerto); // nada mudou
    }

    public function test_criterio_marcado_precisa_do_valor_e_dentro_do_limite(): void
    {
        $this->enviar(['risco_acerto_ativo' => 1, 'risco_acerto' => ''])->assertSessionHasErrors('risco_acerto');
        $this->enviar(['risco_acerto_ativo' => 1, 'risco_acerto' => 150])->assertSessionHasErrors('risco_acerto');
        $this->enviar(['risco_faltas_ativo' => 1, 'risco_faltas' => 0])->assertSessionHasErrors('risco_faltas');
        $this->enviar(['risco_faltas_ativo' => 1, 'risco_faltas' => '2,5'])->assertSessionHasErrors('risco_faltas');
        $this->enviar(['risco_acerto_ativo' => 1, 'risco_acerto' => 60, 'risco_operador' => 'talvez'])->assertSessionHasErrors('risco_operador');
    }

    public function test_formulario_sem_os_campos_de_risco_nao_mexe_na_regra(): void
    {
        RegraDeRisco::gravar(45.0, null, 'ou');

        $this->actingAs($this->admin(), 'admin')->post('/sistema/configuracoes', ['backup_manter_ultimos' => 7])->assertSessionHasNoErrors();

        $this->assertSame(45.0, RegraDeRisco::atual()->acerto);
        $this->assertNull(RegraDeRisco::atual()->faltas);
    }

    public function test_tela_de_configuracoes_mostra_a_regra_em_vigor(): void
    {
        RegraDeRisco::gravar(65.0, 4, 'e');

        $this->actingAs($this->admin(), 'admin')->get('/sistema/configuracoes')
            ->assertOk()
            ->assertSee('Estudante em risco')
            ->assertSee('média de acerto abaixo de 65% e 4 faltas ou mais')
            ->assertSee('name="risco_operador"', false);
    }

    public function test_coordenador_nao_altera_a_regra(): void
    {
        $this->actingAs($this->coordenador(), 'admin')
            ->post('/sistema/configuracoes', ['backup_manter_ultimos' => 5, 'risco_enviado' => 1, 'risco_acerto_ativo' => 1, 'risco_acerto' => 10])
            ->assertForbidden();

        $this->assertSame(60.0, RegraDeRisco::atual()->acerto);
        $this->assertNull(ConfiguracaoSistema::valor('risco_acerto'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Regra por avaliação
    // ------------------------------------------------------------------------------------------------------------

    public function test_admin_define_a_regra_da_avaliacao_e_em_branco_volta_ao_padrao(): void
    {
        $avaliacao = $this->avaliacao();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->put(route('avaliacoes.update', $avaliacao), ['risco_enviado' => 1, 'risco_acerto' => '45,5', 'risco_ignora_falta' => 1])
            ->assertRedirect(route('avaliacoes.show', $avaliacao));
        $avaliacao->refresh();
        $this->assertSame(45.5, $avaliacao->risco_acerto);
        $this->assertTrue($avaliacao->risco_ignora_falta);

        // 0 = a prova sai do critério de acerto (não é "em branco")
        $this->actingAs($admin, 'admin')->put(route('avaliacoes.update', $avaliacao), ['risco_enviado' => 1, 'risco_acerto' => '0']);
        $this->assertSame(0.0, $avaliacao->fresh()->risco_acerto);
        $this->assertFalse($avaliacao->fresh()->risco_ignora_falta); // caixa desmarcada

        $this->actingAs($admin, 'admin')->put(route('avaliacoes.update', $avaliacao), ['risco_enviado' => 1, 'risco_acerto' => '']);
        $this->assertNull($avaliacao->fresh()->risco_acerto);
    }

    public function test_atualizar_a_avaliacao_sem_o_marcador_nao_apaga_a_regra_dela(): void
    {
        $avaliacao = $this->avaliacao();
        $avaliacao->update(['risco_acerto' => 40, 'risco_ignora_falta' => true]);

        $this->actingAs($this->admin(), 'admin')->put(route('avaliacoes.update', $avaliacao), ['nome' => 'Outro nome'])->assertRedirect();

        $this->assertSame('Outro nome', $avaliacao->fresh()->nome);
        $this->assertSame(40.0, $avaliacao->fresh()->risco_acerto);
        $this->assertTrue($avaliacao->fresh()->risco_ignora_falta);
    }

    public function test_acerto_da_avaliacao_fora_de_0_a_100_e_recusado(): void
    {
        $avaliacao = $this->avaliacao();

        $this->actingAs($this->admin(), 'admin')->put(route('avaliacoes.update', $avaliacao), ['risco_enviado' => 1, 'risco_acerto' => 120])
            ->assertSessionHasErrors('risco_acerto');
        $this->assertNull($avaliacao->fresh()->risco_acerto);
    }

    public function test_tela_da_avaliacao_mostra_o_padrao_e_os_campos(): void
    {
        $avaliacao = $this->avaliacao();

        $this->actingAs($this->admin(), 'admin')->get(route('avaliacoes.show', $avaliacao))
            ->assertOk()
            ->assertSee('Estudante em risco nesta avaliação')
            ->assertSee('média de acerto abaixo de 60% ou 2 faltas ou mais')
            ->assertSee('name="risco_ignora_falta"', false);
    }

    // ------------------------------------------------------------------------------------------------------------
    // A regra na lista do coordenador e no painel da reitoria
    // ------------------------------------------------------------------------------------------------------------

    public function test_lista_do_coordenador_segue_o_limite_de_acerto_da_instituicao(): void
    {
        $avaliacao = $this->avaliacao();
        $a = $this->aluno($avaliacao, 5);   // 50%
        $b = $this->aluno($avaliacao, 7);   // 70%

        $this->assertSame(['atencao', 'regular'], [$this->situacoes()[$a->ra], $this->situacoes()[$b->ra]]);

        RegraDeRisco::gravar(40.0, 2, 'ou');
        $this->assertSame('regular', $this->situacoes()[$a->ra]);

        RegraDeRisco::gravar(75.0, 2, 'ou');
        $this->assertSame(['atencao', 'atencao'], [$this->situacoes()[$a->ra], $this->situacoes()[$b->ra]]);
    }

    public function test_lista_do_coordenador_respeita_o_limite_proprio_e_a_dispensa_de_falta_da_avaliacao(): void
    {
        $primeira = $this->avaliacao('2026/1 - Prova 1');
        $segunda = $this->avaliacao('2026/1 - Prova 2');
        $faltoso = $this->aluno($primeira, null);   // faltou à 1ª...
        $this->aluno($segunda, 9, 'Outro');
        // ... e à 2ª
        foreach (range(1, 10) as $n) {
            Resposta::create(['avaliacao_codigo' => $segunda->codigo, 'aluno_id' => $faltoso->id, 'ra' => $faltoso->ra, 'periodo' => '3º', 'questao_numero' => $n, 'resposta' => '']);
        }
        app(ResumoResultadoService::class)->recalcular($segunda->codigo);
        $fraco = $this->aluno($primeira, 5);        // 50% na 1ª (e fora da 2ª)

        // faltou às duas: ausente em tudo
        $this->assertSame('ausente', $this->situacoes()[$faltoso->ra]);
        $this->assertSame('atencao', $this->situacoes()[$fraco->ra]);

        // 50% passa quando a prova tem limite próprio de 40%
        $primeira->update(['risco_acerto' => 40]);
        $this->assertSame('regular', $this->situacoes()[$fraco->ra]);

        // e a prova que sai do critério de acerto (0) também o deixa de fora
        $primeira->update(['risco_acerto' => 0]);
        $this->assertSame('regular', $this->situacoes()[$fraco->ra]);
    }

    public function test_painel_da_reitoria_e_lista_do_coordenador_contam_as_mesmas_pessoas(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnóstico']);
        $avaliacao = $this->avaliacao('2026/1 - Diagnóstico', $categoria->id);
        foreach ([2, 4, 5, 8, 9, 10, 6] as $acertos) {
            $this->aluno($avaliacao, $acertos);
        }

        foreach ([[60.0, null], [45.0, null], [85.0, null]] as [$acerto, $faltas]) {
            RegraDeRisco::gravar($acerto, $faltas, 'ou');
            $risco = app(ReitorRiscoService::class)->gerar(app(ReitorDashboardService::class)->contexto(['categoria' => (string) $categoria->id], []));

            $this->assertSame(
                collect($this->situacoes())->filter(fn ($s) => $s === 'atencao')->count(),
                $risco['cursos']['DIREITO']['risco'],
                "regra de acerto {$acerto}%"
            );
        }
    }

    public function test_painel_da_reitoria_mostra_a_regra_em_vigor(): void
    {
        $categoria = Categoria::create(['nome' => 'Diagnóstico']);
        $avaliacao = $this->avaliacao('2026/1 - Diagnóstico', $categoria->id);
        foreach ([2, 4, 5, 8, 9, 10] as $acertos) {
            $this->aluno($avaliacao, $acertos);
        }
        RegraDeRisco::gravar(50.0, null, 'ou');
        $reitor = Admin::create(['username' => 'reitor', 'email' => 'reitor@example.test', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_REITOR]);

        $this->actingAs($reitor, 'admin')->get(route('reitor.risco', ['categoria' => $categoria->id]))
            ->assertOk()
            ->assertSee('média de acerto abaixo de 50%')
            ->assertSee('Critério de faltas desligado');
    }

    public function test_painel_do_coordenador_descreve_a_regra_da_instituicao(): void
    {
        $avaliacao = $this->avaliacao();
        $this->aluno($avaliacao, 5);
        RegraDeRisco::gravar(55.0, 3, 'ou');

        $this->actingAs($this->coordenador(), 'admin')->get(route('coordenador.painel'))
            ->assertOk()
            ->assertSee('Média de acerto abaixo de 55% ou 3 faltas ou mais');
    }
}
