<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Acessibilidade que o SERVIDOR entrega (landmarks, atributos ARIA, ordem de foco). O comportamento do JavaScript —
 * menu de temas por teclado, texto alternativo dos gráficos — é conferido no navegador, não aqui.
 */
class AcessibilidadeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => Hash::make('senha-secreta')]);
    }

    public function test_layout_do_admin_tem_link_de_pular_e_regiao_principal(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes')->assertOk()->getContent();

        $this->assertStringContainsString('href="#conteudo-principal"', $html);
        $this->assertMatchesRegularExpression('/<main[^>]*id="conteudo-principal"[^>]*tabindex="-1"/', $html);
    }

    public function test_menu_marca_a_pagina_atual_e_o_botao_do_menu_declara_seu_estado(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes')->assertOk()->getContent();

        // o item da página atual — e só ele — é anunciado como "página atual"
        $this->assertSame(1, preg_match_all('/<a [^>]*aria-current="page"/', $html));
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*href="[^"]*\/avaliacoes"|href="[^"]*\/avaliacoes"[^>]*aria-current="page"/', $html);

        // o botão do menu (celular) diz o que controla e se está aberto
        $this->assertMatchesRegularExpression('/<button[^>]*id="botao-menu"[^>]*aria-controls="sidebar"[^>]*aria-expanded="false"/', $html);
        $this->assertStringContainsString('aria-label="Abrir o menu"', $html);
        $this->assertStringContainsString('aria-label="Menu principal"', $html);
    }

    public function test_barra_lateral_so_some_abaixo_de_768px_onde_o_botao_do_menu_aparece(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes')->getContent();

        // `md:` do Tailwind começa EM 768px: um `max-width: 768px` esconderia a barra justo onde o botão some.
        $this->assertStringContainsString('@media (max-width: 767.98px)', $html);
        $this->assertStringNotContainsString('@media (max-width: 768px)', $html);
        // escondida fora da tela, a barra também sai da ordem de tabulação
        $this->assertMatchesRegularExpression('/\.sidebar \{[^}]*visibility: hidden/', $html);
    }

    public function test_acessibilidade_carrega_o_texto_alternativo_dos_graficos(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/avaliacoes')->getContent();

        $this->assertStringContainsString('assets/js/graficos-acessiveis.js', $html);
        $this->assertFileExists(public_path('assets/js/graficos-acessiveis.js'));
    }

    public function test_login_tem_regiao_principal_e_link_de_pular(): void
    {
        $this->admin();

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('href="#conteudo-principal"', $html);
        $this->assertMatchesRegularExpression('/<main[^>]*id="conteudo-principal"/', $html);
    }

    public function test_erro_de_login_e_anunciado_e_o_campo_marcado_como_invalido(): void
    {
        $this->admin();

        $this->post('/login', ['username' => 'coordenador', 'password' => 'errada']);
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div[^>]*id="erros-do-formulario"[^>]*role="alert"|<div[^>]*role="alert"[^>]*id="erros-do-formulario"/', $html);
        // o script que marca os campos recebe os nomes dos campos com erro
        $this->assertStringContainsString('aria-invalid', $html);
        $this->assertStringContainsString('\u0022username\u0022', $html);
    }

    public function test_sem_erros_o_script_de_campos_invalidos_nao_e_emitido(): void
    {
        $this->admin();

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString("setAttribute('aria-invalid'", $html);
        $this->assertStringNotContainsString('id="erros-do-formulario"', $html);
    }

    public function test_erro_de_validacao_numa_tela_do_admin_e_anunciado(): void
    {
        // senha atual errada no perfil → erro de validação exibido pelo partials/flash
        $html = $this->actingAs($this->admin(), 'admin')->followingRedirects()->from('/perfil')
            ->put('/perfil/senha', ['current_password' => 'errada', 'new_password' => 'curta', 'new_password_confirmation' => 'x'])
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div[^>]*role="alert"/', $html);
        $this->assertStringContainsString('id="erros-do-formulario"', $html);
    }

    public function test_portal_tem_regiao_principal_link_de_pular_e_rodape_legivel(): void
    {
        $this->admin(); // sem nenhum administrador o sistema ainda se considera "não instalado"

        $html = $this->get(route('portal.consulta'))->assertOk()->getContent();

        $this->assertStringContainsString('href="#conteudo-principal"', $html);
        $this->assertMatchesRegularExpression('/<main[^>]*id="conteudo-principal"/', $html);
        // o rodapé era text-slate-300 (1,5:1 sobre o fundo claro)
        $this->assertStringNotContainsString('text-slate-300 hover:text-slate-500', $html);
    }

    /**
     * Texto pequeno em fundo claro precisa de 4,5:1 (WCAG 1.4.3): as cores abaixo ficam entre 2,5:1 e 3,8:1. Telas
     * de fundo escuro (login, instalador, barra lateral) usam outra paleta e ficam de fora; ícones são decorativos.
     */
    public function test_telas_claras_nao_usam_cores_de_texto_sem_contraste(): void
    {
        $proibidas = ['text-slate-400', 'text-emerald-600', 'text-amber-600', 'text-red-500', 'text-primary'];
        $achados = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $arquivo) {
            $caminho = str_replace('\\', '/', $arquivo->getPathname());
            if (! str_ends_with($caminho, '.blade.php')
                || str_contains($caminho, '/views/auth/') || str_contains($caminho, '/views/instalar/')
                || str_ends_with($caminho, 'layouts/auth.blade.php') || str_ends_with($caminho, 'layouts/app.blade.php')) {
                continue;
            }

            preg_match_all('/<(?!i[\s>])[a-zA-Z][\w-]*\s[^>]*?class="([^"]*)"/s', file_get_contents($caminho), $tags);
            foreach ($tags[1] as $classes) {
                foreach ($proibidas as $classe) {
                    if (preg_match('/(?<![\w:\/\[-])'.preg_quote($classe, '/').'(?![\w\/-])/', $classes)) {
                        $achados[] = basename($caminho).': '.$classe;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($achados)), 'Cor de texto com contraste insuficiente em fundo claro.');
    }
}
