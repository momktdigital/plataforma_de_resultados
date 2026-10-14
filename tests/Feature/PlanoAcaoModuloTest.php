<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ConfiguracaoSistema;
use App\Models\PlanoAcao;
use App\Services\PlanoAcaoLembreteService;
use App\Support\PlanoAcaoModulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chave liga/desliga do módulo Plano de ação: ativo (padrão), só para usuários escolhidos (teste) ou desativado. Mexe em
 * menu, aba do coordenador, ícone dos gráficos e rotas (404), sem apagar nada do que já foi gravado.
 */
class PlanoAcaoModuloTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $nome, string $role, ?string $curso = null): Admin
    {
        $u = Admin::create(['username' => $nome, 'email' => "{$nome}@example.test", 'password_hash' => bcrypt('x'), 'role' => $role]);
        if ($curso !== null) {
            $u->sincronizarCursos([$curso]);
        }

        return $u;
    }

    private function modo(string $modo, array $ids = []): void
    {
        ConfiguracaoSistema::definir(PlanoAcaoModulo::CHAVE_MODO, $modo);
        ConfiguracaoSistema::definir(PlanoAcaoModulo::CHAVE_USUARIOS, implode(',', $ids));
        request()->attributes->remove('plano_acao_modulo');
    }

    public function test_por_padrao_o_modulo_esta_ativo_para_todos(): void
    {
        $coord = $this->usuario('coord', Admin::ROLE_COORDENADOR, 'MEDICINA');

        $this->assertSame(PlanoAcaoModulo::ATIVO, PlanoAcaoModulo::modo());
        $this->assertTrue(PlanoAcaoModulo::liberadoPara($coord));
        $this->actingAs($coord, 'admin')->get(route('coordenador.planos.index'))->assertOk();
    }

    public function test_desativado_esconde_menu_aba_e_barra_as_rotas_para_todos_inclusive_o_administrador(): void
    {
        $coord = $this->usuario('coord', Admin::ROLE_COORDENADOR, 'MEDICINA');
        $colab = $this->usuario('colab', Admin::ROLE_COLABORADOR);
        $reitor = $this->usuario('reitor', Admin::ROLE_REITOR);
        $admin = $this->usuario('admin', Admin::ROLE_ADMIN);
        $this->modo(PlanoAcaoModulo::DESATIVADO);

        $this->actingAs($coord, 'admin')->get(route('coordenador.planos.index'))->assertNotFound();
        $this->actingAs($coord, 'admin')->get(route('coordenador.planos.novo', ['visual' => 'participacao']))->assertNotFound();
        $this->actingAs($coord, 'admin')->get(route('coordenador.painel'))->assertOk()->assertDontSee('Planos de ação');
        $this->actingAs($colab, 'admin')->get(route('colaborador.planos.index'))->assertNotFound();
        $this->actingAs($colab, 'admin')->get(route('colaborador.index'))->assertOk()->assertDontSee('Planos de ação');
        $this->actingAs($reitor, 'admin')->get(route('reitor.planos'))->assertNotFound();
        $this->actingAs($reitor, 'admin')->get(route('reitor.visao'))->assertOk()->assertDontSee('Planos de ação');
        $this->actingAs($admin, 'admin')->get(route('colaborador.planos.index'))->assertNotFound();
        $this->actingAs($admin, 'admin')->get(route('avaliacoes.index'))->assertOk()->assertDontSee('Planos de ação');

        // a configuração, onde se religa, continua acessível
        $this->actingAs($admin, 'admin')->get('/sistema/configuracoes')->assertOk()->assertSee('Módulo de planos de ação');
    }

    public function test_em_teste_so_os_usuarios_escolhidos_enxergam_o_modulo(): void
    {
        $liberado = $this->usuario('liberado', Admin::ROLE_COORDENADOR, 'MEDICINA');
        $outro = $this->usuario('outro', Admin::ROLE_COORDENADOR, 'DIREITO');
        $admin = $this->usuario('admin', Admin::ROLE_ADMIN);
        $this->modo(PlanoAcaoModulo::TESTE, [$liberado->id]);

        $this->actingAs($liberado, 'admin')->get(route('coordenador.planos.index'))->assertOk();
        $this->actingAs($liberado, 'admin')->get(route('coordenador.painel'))->assertOk()->assertSee('Planos de ação');

        $this->actingAs($outro, 'admin')->get(route('coordenador.planos.index'))->assertNotFound();
        $this->actingAs($outro, 'admin')->get(route('coordenador.painel'))->assertOk()->assertDontSee('Planos de ação');

        // o administrador não entra sozinho: precisa estar na lista
        $this->actingAs($admin, 'admin')->get(route('colaborador.planos.index'))->assertNotFound();
        $this->modo(PlanoAcaoModulo::TESTE, [$liberado->id, $admin->id]);
        $this->actingAs($admin, 'admin')->get(route('colaborador.planos.index'))->assertOk();
    }

    public function test_modo_teste_sem_ninguem_marcado_equivale_a_desativado(): void
    {
        $coord = $this->usuario('coord', Admin::ROLE_COORDENADOR, 'MEDICINA');
        $this->modo(PlanoAcaoModulo::TESTE, []);

        $this->assertFalse(PlanoAcaoModulo::liberadoPara($coord));
        $this->actingAs($coord, 'admin')->get(route('coordenador.planos.index'))->assertNotFound();
    }

    public function test_valor_invalido_gravado_cai_no_padrao_ativo(): void
    {
        $coord = $this->usuario('coord', Admin::ROLE_COORDENADOR, 'MEDICINA');
        $this->modo('qualquer-coisa');

        $this->assertSame(PlanoAcaoModulo::ATIVO, PlanoAcaoModulo::modo());
        $this->assertTrue(PlanoAcaoModulo::liberadoPara($coord));
    }

    public function test_administrador_muda_o_modulo_nas_configuracoes_e_fica_na_auditoria(): void
    {
        $admin = $this->usuario('admin', Admin::ROLE_ADMIN);
        $coord = $this->usuario('coord', Admin::ROLE_COORDENADOR, 'MEDICINA');
        $this->actingAs($admin, 'admin');

        $this->post('/sistema/configuracoes', ['backup_manter_ultimos' => 5, 'plano_modulo_enviado' => 1, 'plano_modo' => 'teste', 'plano_usuarios' => [$coord->id, $admin->id, $coord->id]])
            ->assertSessionHasNoErrors();
        request()->attributes->remove('plano_acao_modulo');

        $this->assertSame('teste', ConfiguracaoSistema::valor(PlanoAcaoModulo::CHAVE_MODO));
        $this->assertSame([$admin->id, $coord->id], PlanoAcaoModulo::usuarios());
        $this->assertDatabaseHas('atividades', ['acao' => 'configuracao.plano_modulo_alterado']);

        $this->get('/sistema/configuracoes')->assertOk()->assertSee('coord');

        $this->post('/sistema/configuracoes', ['backup_manter_ultimos' => 5, 'plano_modulo_enviado' => 1, 'plano_modo' => 'desativado'])->assertSessionHasNoErrors();
        request()->attributes->remove('plano_acao_modulo');
        $this->assertSame('desativado', PlanoAcaoModulo::modo());
        $this->assertSame([], PlanoAcaoModulo::usuarios());
    }

    public function test_configuracao_rejeita_modo_invalido_e_usuario_inexistente_e_o_formulario_antigo_nao_mexe_no_modulo(): void
    {
        $admin = $this->usuario('admin', Admin::ROLE_ADMIN);
        $this->actingAs($admin, 'admin');
        $this->modo(PlanoAcaoModulo::DESATIVADO);

        $this->post('/sistema/configuracoes', ['backup_manter_ultimos' => 5, 'plano_modulo_enviado' => 1, 'plano_modo' => 'talvez'])->assertSessionHasErrors('plano_modo');
        $this->post('/sistema/configuracoes', ['backup_manter_ultimos' => 5, 'plano_modulo_enviado' => 1, 'plano_modo' => 'teste', 'plano_usuarios' => [99999]])->assertSessionHasErrors('plano_usuarios.0');

        // formulário sem os campos do módulo: nada muda
        $this->post('/sistema/configuracoes', ['backup_manter_ultimos' => 7])->assertSessionHasNoErrors();
        request()->attributes->remove('plano_acao_modulo');
        $this->assertSame('desativado', PlanoAcaoModulo::modo());
    }

    public function test_so_o_administrador_altera_a_configuracao_do_modulo(): void
    {
        $coord = $this->usuario('coord', Admin::ROLE_COORDENADOR, 'MEDICINA');

        $this->actingAs($coord, 'admin')->post('/sistema/configuracoes', ['backup_manter_ultimos' => 5, 'plano_modulo_enviado' => 1, 'plano_modo' => 'desativado'])->assertForbidden();
        $this->assertSame(PlanoAcaoModulo::ATIVO, PlanoAcaoModulo::modo());
    }

    public function test_icone_de_iniciar_plano_some_dos_visuais_quando_o_modulo_nao_esta_liberado(): void
    {
        $coord = $this->usuario('coord', Admin::ROLE_COORDENADOR, 'MEDICINA');
        $html = fn () => view('plano._botao', ['visual' => 'participacao', 'titulo' => 'Participação', 'ctx' => ['curso' => 'MEDICINA']])->render();

        $this->actingAs($coord, 'admin');
        $this->assertStringContainsString('Iniciar plano de ação', $html());

        $this->modo(PlanoAcaoModulo::DESATIVADO);
        $this->assertStringNotContainsString('Iniciar plano de ação', $html());

        $this->modo(PlanoAcaoModulo::TESTE, [$coord->id]);
        $this->assertStringContainsString('Iniciar plano de ação', $html());
    }

    public function test_lembretes_nao_sao_gerados_com_o_modulo_desativado_e_em_teste_so_para_os_liberados(): void
    {
        $liberado = $this->usuario('liberado', Admin::ROLE_COORDENADOR, 'MEDICINA');
        $outro = $this->usuario('outro', Admin::ROLE_COORDENADOR, 'DIREITO');
        foreach ([[$liberado, 'MEDICINA'], [$outro, 'DIREITO']] as [$c, $curso]) {
            $plano = new PlanoAcao(['admin_id' => $c->id, 'curso' => $curso, 'periodo_letivo' => '2026/1', 'origem_visual' => 'participacao', 'origem_rotulo' => 'Participação '.$c->username, 'status' => PlanoAcao::RASCUNHO]);
            $plano->save();
            $plano->forceFill(['updated_at' => now()->subDays(60)])->saveQuietly();
        }

        $this->modo(PlanoAcaoModulo::DESATIVADO);
        $this->assertSame(0, app(PlanoAcaoLembreteService::class)->gerar());

        $this->modo(PlanoAcaoModulo::TESTE, [$liberado->id]);
        $this->assertSame(1, app(PlanoAcaoLembreteService::class)->gerar());

        $this->modo(PlanoAcaoModulo::ATIVO);
        $this->assertGreaterThanOrEqual(1, app(PlanoAcaoLembreteService::class)->gerar());
    }
}
