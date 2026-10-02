<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Notificacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\NotificacaoCoordenadorService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Notificações do coordenador: geração a partir dos resultados importados e a central (ler, marcar como lida).
 */
class NotificacaoCoordenadorTest extends TestCase
{
    use RefreshDatabase;

    private function coordenador(string $username = 'coord-direito', array $cursos = ['DIREITO']): Admin
    {
        $coordenador = Admin::create(['username' => $username, 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos($cursos);

        return $coordenador;
    }

    private function aluno(string $nome, string $curso = 'DIREITO'): Aluno
    {
        static $ra = 9000;
        $ra++;

        return Aluno::create(['ra' => (string) $ra, 'nome' => $nome, 'curso' => $curso, 'periodo' => '3º']);
    }

    /** @param array<int, array{0: Aluno, 1: string}> $resultados 'A' acerta as 3 questões, 'B' erra, '' = ausente */
    private function avaliacao(string $nome, string $data, array $resultados, ?int $categoriaId = null): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $categoriaId]);
        foreach ([1, 2, 3] as $numero) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $numero, 'gabarito' => 'A', 'area' => 'Clínica']);
        }
        foreach ($resultados as [$aluno, $resposta]) {
            foreach ([1, 2, 3] as $numero) {
                Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => $aluno->ra, 'periodo' => '3º', 'questao_numero' => $numero, 'resposta' => $resposta]);
            }
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    private function servico(): NotificacaoCoordenadorService
    {
        return app(NotificacaoCoordenadorService::class);
    }

    public function test_novos_resultados_avisam_so_o_coordenador_do_curso(): void
    {
        $direito = $this->coordenador('coord-direito', ['DIREITO']);
        $medicina = $this->coordenador('coord-medicina', ['MEDICINA']);
        $ana = $this->aluno('Ana');
        $bia = $this->aluno('Bia');
        $avaliacao = $this->avaliacao('Simulado 1', '2026-03-10', [[$ana, 'A'], [$bia, 'B']]);

        $this->assertGreaterThan(0, $this->servico()->gerarParaAvaliacao($avaliacao->codigo));

        $aviso = Notificacao::where('admin_id', $direito->id)->where('tipo', 'resultados')->firstOrFail();
        $this->assertSame('Novos resultados: Simulado 1', $aviso->titulo);
        $this->assertStringContainsString('Presença de 100% (2 de 2) e média de 50%', $aviso->texto);
        $this->assertNull($aviso->lida_em);
        $this->assertStringContainsString('/painel/desempenho?periodo_letivo=2026%2F1', $aviso->url);

        $this->assertSame(0, Notificacao::where('admin_id', $medicina->id)->count(), 'coordenador de outro curso não recebe');
    }

    public function test_avisa_queda_de_media_presenca_baixa_e_quem_entrou_em_atencao(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $coordenador = $this->coordenador();
        $ana = $this->aluno('Ana');
        $bia = $this->aluno('Bia');
        $cris = $this->aluno('Cris');
        $davi = $this->aluno('Davi');

        $primeira = $this->avaliacao('Simulado 1', '2026-03-10', [[$ana, 'A'], [$bia, 'A'], [$cris, 'A'], [$davi, 'A']], $categoria->id);
        $this->servico()->gerarParaAvaliacao($primeira->codigo);
        $this->assertSame(0, Notificacao::where('tipo', 'queda')->count(), 'a primeira avaliação não tem anterior');
        $this->assertSame(0, Notificacao::where('tipo', 'atencao')->count(), 'todos com 100%');

        // Bia erra tudo e Davi falta: média 100 → 66,7; presença 75%.
        $segunda = $this->avaliacao('Simulado 2', '2026-04-10', [[$ana, 'A'], [$bia, 'B'], [$cris, 'A'], [$davi, '']], $categoria->id);
        $this->servico()->gerarParaAvaliacao($segunda->codigo);

        $queda = Notificacao::where('tipo', 'queda')->firstOrFail();
        $this->assertSame('Média caiu em Simulado 2', $queda->titulo);
        $this->assertStringContainsString('100% para 66,7%', $queda->texto);
        $this->assertStringContainsString('Simulado 1', $queda->texto);

        $presenca = Notificacao::where('tipo', 'presenca')->firstOrFail();
        $this->assertStringContainsString('Só 75%', $presenca->texto);
        $this->assertStringContainsString('1 ausente', $presenca->texto);

        $atencao = Notificacao::where('tipo', 'atencao')->firstOrFail();
        $this->assertSame('1 aluno passou a precisar de atenção', $atencao->titulo);
        $this->assertStringContainsString('Bia', $atencao->texto);
        $this->assertStringNotContainsString('Ana', $atencao->texto);
        $this->assertStringContainsString('situacao=atencao', $atencao->url);
        $this->assertSame($coordenador->id, $atencao->admin_id);
    }

    public function test_gerar_de_novo_nao_duplica_e_so_reabre_se_mudou(): void
    {
        $coordenador = $this->coordenador();
        $ana = $this->aluno('Ana');
        $avaliacao = $this->avaliacao('Simulado 1', '2026-03-10', [[$ana, 'A']]);

        $this->servico()->gerarParaAvaliacao($avaliacao->codigo);
        $total = Notificacao::count();
        Notificacao::query()->update(['lida_em' => now()]);

        // Mesmo conteúdo: nada muda, continua lida.
        $this->assertSame(0, $this->servico()->gerarParaAvaliacao($avaliacao->codigo));
        $this->assertSame($total, Notificacao::count());
        $this->assertSame(0, Notificacao::whereNull('lida_em')->count());

        // Reimportou com outro aluno: o aviso é atualizado e volta a "não lido".
        $bia = $this->aluno('Bia');
        foreach ([1, 2, 3] as $n) {
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $bia->id, 'ra' => $bia->ra, 'periodo' => '3º', 'questao_numero' => $n, 'resposta' => 'B']);
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->assertGreaterThan(0, $this->servico()->gerarParaAvaliacao($avaliacao->codigo));
        $aviso = Notificacao::where('chave', "resultados:{$avaliacao->codigo}")->firstOrFail();
        $this->assertNull($aviso->lida_em);
        $this->assertStringContainsString('média de 50%', $aviso->texto);
        $this->assertSame($coordenador->id, $aviso->admin_id);
    }

    public function test_importar_resultados_pela_tela_avisa_o_coordenador(): void
    {
        $coordenador = $this->coordenador();
        $admin = Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
        Aluno::create(['ra' => '777', 'nome' => 'Zé', 'curso' => 'DIREITO', 'periodo' => '3º']);
        $avaliacao = Avaliacao::create(['nome' => 'Importada', 'data_avaliacao' => '2026-03-10']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        $arquivo = UploadedFile::fake()->createWithContent('resultados.csv', "RA,Questão,Resposta\n777,1,A\n");
        $this->actingAs($admin, 'admin')->post("/avaliacoes/{$avaliacao->codigo}/resultados/import", ['arquivo' => $arquivo]);

        $this->assertDatabaseHas('notificacoes', ['admin_id' => $coordenador->id, 'tipo' => 'resultados', 'chave' => "resultados:{$avaliacao->codigo}"]);
        $this->assertDatabaseMissing('notificacoes', ['admin_id' => $admin->id]);
    }

    public function test_avaliacao_anulada_nao_gera_aviso(): void
    {
        $this->coordenador();
        $ana = $this->aluno('Ana');
        $avaliacao = $this->avaliacao('Anulada', '2026-03-10', [[$ana, 'A']]);
        $avaliacao->update(['status' => Avaliacao::STATUS_ANULADA]);

        $this->assertSame(0, $this->servico()->gerarParaAvaliacao($avaliacao->codigo));
        $this->assertSame(0, Notificacao::count());
    }

    public function test_comando_gera_para_o_que_ja_estava_importado(): void
    {
        $this->coordenador();
        $ana = $this->aluno('Ana');
        $this->avaliacao('Prova antiga', '2026-03-10', [[$ana, 'A']]);

        $this->artisan('notificacoes:gerar')->assertSuccessful();

        $this->assertGreaterThan(0, Notificacao::count());
    }

    public function test_central_lista_so_as_proprias_e_marca_como_lida(): void
    {
        $eu = $this->coordenador('coord-eu');
        $outro = $this->coordenador('coord-outro');
        $minha = Notificacao::create(['admin_id' => $eu->id, 'tipo' => 'resultados', 'titulo' => 'Aviso meu', 'texto' => 't', 'url' => '/painel', 'chave' => 'a']);
        $alheia = Notificacao::create(['admin_id' => $outro->id, 'tipo' => 'resultados', 'titulo' => 'Aviso alheio', 'texto' => 't', 'url' => '/painel', 'chave' => 'b']);

        $this->actingAs($eu, 'admin')->get('/notificacoes')
            ->assertOk()->assertSee('Aviso meu')->assertDontSee('Aviso alheio')
            ->assertSee('Marcar todas como lidas')->assertSee('nova');

        $this->actingAs($eu, 'admin')->post("/notificacoes/{$minha->id}/lida")->assertRedirect();
        $this->assertNotNull($minha->fresh()->lida_em);

        // Aviso de outra pessoa: 404, e continua sem ler.
        $this->actingAs($eu, 'admin')->post("/notificacoes/{$alheia->id}/lida")->assertNotFound();
        $this->actingAs($eu, 'admin')->get("/notificacoes/{$alheia->id}/abrir")->assertNotFound();
        $this->assertNull($alheia->fresh()->lida_em);
    }

    public function test_abrir_marca_como_lida_e_vai_para_o_assunto(): void
    {
        $eu = $this->coordenador();
        $aviso = Notificacao::create(['admin_id' => $eu->id, 'tipo' => 'atencao', 'titulo' => 'x', 'texto' => 't', 'url' => route('coordenador.alunos', ['situacao' => 'atencao']), 'chave' => 'a']);

        $this->actingAs($eu, 'admin')->get("/notificacoes/{$aviso->id}/abrir")->assertRedirect(route('coordenador.alunos', ['situacao' => 'atencao']));
        $this->assertNotNull($aviso->fresh()->lida_em);

        // Link para fora do sistema nunca é seguido.
        $externo = Notificacao::create(['admin_id' => $eu->id, 'tipo' => 'atencao', 'titulo' => 'y', 'texto' => 't', 'url' => 'https://mal.example/phishing', 'chave' => 'b']);
        $this->actingAs($eu, 'admin')->get("/notificacoes/{$externo->id}/abrir")->assertRedirect(route('notificacoes.index'));
    }

    public function test_marcar_todas_como_lidas_e_filtro_de_nao_lidas(): void
    {
        $eu = $this->coordenador('coord-eu');
        $outro = $this->coordenador('coord-outro');
        foreach (['a', 'b', 'c'] as $chave) {
            Notificacao::create(['admin_id' => $eu->id, 'tipo' => 'resultados', 'titulo' => "Aviso {$chave}", 'texto' => 't', 'chave' => $chave]);
        }
        $alheia = Notificacao::create(['admin_id' => $outro->id, 'tipo' => 'resultados', 'titulo' => 'x', 'texto' => 't', 'chave' => 'z']);

        $this->actingAs($eu, 'admin')->get('/notificacoes?filtro=nao-lidas')->assertSee('Aviso a')->assertSee('Aviso c');

        $this->actingAs($eu, 'admin')->post('/notificacoes/lidas')->assertRedirect();

        $this->assertSame(0, Notificacao::where('admin_id', $eu->id)->whereNull('lida_em')->count());
        $this->assertNull($alheia->fresh()->lida_em, 'não mexe nas dos outros');
        $this->actingAs($eu, 'admin')->get('/notificacoes?filtro=nao-lidas')->assertSee('Você não tem notificações não lidas');
    }

    public function test_resumo_json_para_o_sino(): void
    {
        $eu = $this->coordenador();
        $lida = Notificacao::create(['admin_id' => $eu->id, 'tipo' => 'resultados', 'titulo' => 'Antiga', 'texto' => 't', 'chave' => 'a', 'lida_em' => now()]);
        $nova = Notificacao::create(['admin_id' => $eu->id, 'tipo' => 'atencao', 'titulo' => 'Nova', 'texto' => 'corpo', 'chave' => 'b']);

        $this->actingAs($eu, 'admin')->getJson('/notificacoes/resumo')
            ->assertOk()
            ->assertJsonPath('naoLidas', 1)
            ->assertJsonPath('maiorId', $nova->id)
            ->assertJsonPath('ultimas.0.titulo', 'Nova')
            ->assertJsonCount(1, 'ultimas')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotNull($lida->fresh()->lida_em);
    }

    public function test_sino_aparece_para_coordenador_com_a_contagem_e_nao_para_administrador(): void
    {
        $eu = $this->coordenador();
        Notificacao::create(['admin_id' => $eu->id, 'tipo' => 'resultados', 'titulo' => 'x', 'texto' => 't', 'chave' => 'a']);
        Notificacao::create(['admin_id' => $eu->id, 'tipo' => 'resultados', 'titulo' => 'y', 'texto' => 't', 'chave' => 'b']);

        $html = $this->actingAs($eu, 'admin')->get('/avaliacoes')->assertOk()->getContent();
        $this->assertStringContainsString('data-notificacoes-contagem', $html);
        $this->assertStringContainsString('2 não lida(s)', $html);
        $this->assertStringContainsString('notificacoes-ultimo-id-', $html, 'script que atualiza o sino');
        $this->assertSame(2, $eu->notificacoesNaoLidas());

        $admin = Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
        $html = $this->actingAs($admin, 'admin')->get('/avaliacoes')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-notificacoes-contagem', $html);
        $this->actingAs($admin, 'admin')->get('/notificacoes')->assertRedirect(route('avaliacoes.index'));
        $this->actingAs($admin, 'admin')->getJson('/notificacoes/resumo')->assertForbidden();
    }

    public function test_visao_geral_mostra_os_avisos_nao_lidos(): void
    {
        $eu = $this->coordenador();
        $ana = $this->aluno('Ana');
        $this->avaliacao('Prova', '2026-03-10', [[$ana, 'A']]);
        Notificacao::create(['admin_id' => $eu->id, 'tipo' => 'resultados', 'titulo' => 'Novos resultados: Prova', 'texto' => 'Presença de 100%', 'chave' => 'a']);

        $this->actingAs($eu, 'admin')->get('/painel')
            ->assertOk()->assertSee('1 notificação nova')->assertSee('Novos resultados: Prova');
    }

    public function test_barra_superior_tem_menu_do_usuario_com_perfil_e_sair(): void
    {
        $eu = $this->coordenador('maria.souza');
        $eu->update(['email' => 'maria@exemplo.com']);

        $html = $this->actingAs($eu, 'admin')->get('/avaliacoes')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*id="menu-usuario-botao"[^>]*aria-haspopup="menu"[^>]*aria-expanded="false"/', $html);
        $this->assertStringContainsString('maria@exemplo.com', $html);
        $this->assertStringContainsString('Coordenador', $html);
        $this->assertStringContainsString('Meu perfil', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*\/logout"[^>]*>.*?Sair/s', $html);

        $admin = Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
        $this->actingAs($admin, 'admin')->get('/avaliacoes')->assertOk()->assertSee('Administrador')->assertSee('menu-usuario-botao', false);
    }

    public function test_pagina_de_notificacoes_traz_os_botoes_de_avisos_do_navegador(): void
    {
        $eu = $this->coordenador();

        $this->actingAs($eu, 'admin')->get('/notificacoes')->assertOk()
            ->assertSee('id="ativar-avisos-navegador"', false)
            ->assertSee('id="testar-aviso-navegador"', false)
            ->assertSee('window.isSecureContext', false);
    }

    /** Regressão: com a migração ainda não executada (sem a tabela), o painel não pode dar erro 500. */
    public function test_painel_funciona_mesmo_sem_a_tabela_de_notificacoes(): void
    {
        $eu = $this->coordenador();
        $ana = $this->aluno('Ana');
        $this->avaliacao('Prova', '2026-03-10', [[$ana, 'A']]);
        \Illuminate\Support\Facades\Schema::drop('notificacoes');

        foreach (['/painel', '/painel/alunos', '/painel/desempenho', '/painel/comparativo', '/avaliacoes'] as $url) {
            $this->actingAs($eu, 'admin')->get($url)->assertOk();
        }
        $this->actingAs($eu, 'admin')->get('/notificacoes')->assertRedirect(route('coordenador.painel'));
        $this->actingAs($eu, 'admin')->getJson('/notificacoes/resumo')->assertOk()->assertJsonPath('naoLidas', 0);
        $this->assertSame(0, $eu->notificacoesNaoLidas());
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->coordenador(); // sem nenhum usuário o sistema se considera "não instalado"
        $this->get('/notificacoes')->assertRedirect(route('login'));
    }
}
