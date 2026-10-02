<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Configuracao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PortalConfiguracaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_salva_titulo_do_site(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->put('/sistema/portal/aparencia', [
            'site_title' => 'Minha Faculdade',
        ]);

        $response->assertRedirect(route('sistema.portal.index'));
        $this->assertSame('Minha Faculdade', Configuracao::valor('site_title'));
    }

    public function test_ativa_recaptcha_e_desativa_hcaptcha(): void
    {
        Configuracao::definir('hcaptcha_ativo', '1');

        $response = $this->actingAs($this->admin(), 'admin')->put('/sistema/portal/captcha', [
            'captcha_type' => 'recaptcha',
            'recaptcha_site_key' => 'site-key',
            'recaptcha_secret_key' => 'secret-key',
        ]);

        $response->assertRedirect(route('sistema.portal.index'));
        $this->assertSame('1', Configuracao::valor('recaptcha_ativo'));
        $this->assertSame('0', Configuracao::valor('hcaptcha_ativo'));
        $this->assertSame('site-key', Configuracao::valor('recaptcha_site_key'));
    }

    public function test_recaptcha_secret_key_em_branco_nao_apaga_a_ja_salva(): void
    {
        Configuracao::definir('recaptcha_secret_key', 'secret-antiga');

        $response = $this->actingAs($this->admin(), 'admin')->put('/sistema/portal/captcha', [
            'captcha_type' => 'recaptcha',
            'recaptcha_site_key' => 'site-key-novo',
        ]);

        $response->assertRedirect(route('sistema.portal.index'));
        $this->assertSame('secret-antiga', Configuracao::valor('recaptcha_secret_key'));
        $this->assertSame('site-key-novo', Configuracao::valor('recaptcha_site_key'));
    }

    public function test_hcaptcha_secret_key_em_branco_nao_apaga_a_ja_salva(): void
    {
        Configuracao::definir('hcaptcha_secret_key', 'secret-antiga');

        $response = $this->actingAs($this->admin(), 'admin')->put('/sistema/portal/captcha', [
            'captcha_type' => 'hcaptcha',
            'hcaptcha_site_key' => 'site-key-novo',
        ]);

        $response->assertRedirect(route('sistema.portal.index'));
        $this->assertSame('secret-antiga', Configuracao::valor('hcaptcha_secret_key'));
    }

    public function test_avisa_quando_o_portal_esta_sem_2fa_e_sem_captcha(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/sistema/portal')->assertOk()->assertSee('Portal sem segunda verificação');

        Configuracao::definir('smtp_ativo', '1');
        $this->actingAs($admin, 'admin')->get('/sistema/portal')->assertDontSee('Portal sem segunda verificação');
    }

    public function test_nao_ativa_captcha_sem_secret_key(): void
    {
        $admin = $this->admin();

        foreach (['recaptcha', 'hcaptcha'] as $tipo) {
            $this->actingAs($admin, 'admin')->put('/sistema/portal/captcha', [
                'captcha_type' => $tipo,
                "{$tipo}_site_key" => 'site-key',
            ])->assertSessionHasErrors("{$tipo}_secret_key");

            $this->assertNotSame('1', Configuracao::valor("{$tipo}_ativo", '0'));
        }
    }

    public function test_nao_ativa_captcha_sem_site_key(): void
    {
        $this->actingAs($this->admin(), 'admin')->put('/sistema/portal/captcha', [
            'captcha_type' => 'recaptcha',
            'recaptcha_secret_key' => 'secret',
        ])->assertSessionHasErrors('recaptcha_site_key');

        $this->assertNotSame('1', Configuracao::valor('recaptcha_ativo', '0'));
    }

    public function test_desativar_captcha_nao_exige_chaves(): void
    {
        $this->actingAs($this->admin(), 'admin')->put('/sistema/portal/captcha', [
            'captcha_type' => 'none',
        ])->assertRedirect(route('sistema.portal.index'));

        $this->assertSame('0', Configuracao::valor('recaptcha_ativo'));
    }

    public function test_secret_keys_do_captcha_nao_voltam_no_html_da_tela(): void
    {
        Configuracao::definir('recaptcha_secret_key', 'segredo-recaptcha-nao-deve-vazar');
        Configuracao::definir('hcaptcha_secret_key', 'segredo-hcaptcha-nao-deve-vazar');

        $response = $this->actingAs($this->admin(), 'admin')->get('/sistema/portal');

        $response->assertOk();
        $response->assertDontSee('segredo-recaptcha-nao-deve-vazar');
        $response->assertDontSee('segredo-hcaptcha-nao-deve-vazar');
    }

    public function test_smtp_pass_em_branco_nao_apaga_senha_ja_salva(): void
    {
        Configuracao::definir('smtp_pass', 'senha-secreta');

        $response = $this->actingAs($this->admin(), 'admin')->put('/sistema/portal/smtp', [
            'smtp_host' => 'smtp.exemplo.com',
            'smtp_port' => '587',
        ]);

        $response->assertRedirect(route('sistema.portal.index'));
        $this->assertSame('senha-secreta', Configuracao::valor('smtp_pass'));
        $this->assertSame('smtp.exemplo.com', Configuracao::valor('smtp_host'));
    }

    public function test_upload_de_logo_salva_em_public_uploads(): void
    {
        $arquivo = UploadedFile::fake()->image('logo.png', 40, 40);

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/sistema/portal/aparencia', ['site_logo' => $arquivo]);

        $response->assertRedirect(route('sistema.portal.index'));

        $caminhoSalvo = Configuracao::valor('site_logo');
        $this->assertNotEmpty($caminhoSalvo);
        $this->assertStringStartsWith('uploads/logos/', $caminhoSalvo);
        $this->assertFileExists($this->caminhoDoUpload($caminhoSalvo));

        // Limpeza — não deixa arquivo de teste em public/.
        @unlink($this->caminhoDoUpload($caminhoSalvo));
    }

    public function test_arquivo_de_logo_invalido_e_rejeitado(): void
    {
        $arquivo = UploadedFile::fake()->create('virus.exe', 10);

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/sistema/portal/aparencia', ['site_logo' => $arquivo]);

        $response->assertSessionHasErrors('site_logo');
    }

    public function test_guest_nao_acessa_configuracoes_do_portal(): void
    {
        $this->admin();

        $this->get('/sistema/portal')->assertRedirect(route('login'));
    }
}
