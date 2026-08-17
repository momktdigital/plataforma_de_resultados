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

    public function test_upload_de_logo_salva_no_diretorio_compartilhado_com_o_legado(): void
    {
        $destino = base_path('../assets/img');

        $arquivo = UploadedFile::fake()->image('logo.png', 40, 40);

        $response = $this->actingAs($this->admin(), 'admin')
            ->put('/sistema/portal/aparencia', ['site_logo' => $arquivo]);

        $response->assertRedirect(route('sistema.portal.index'));

        $caminhoSalvo = Configuracao::valor('site_logo');
        $this->assertNotEmpty($caminhoSalvo);
        $this->assertStringStartsWith('assets/img/', $caminhoSalvo);
        $this->assertFileExists(base_path('../'.$caminhoSalvo));

        // Limpeza — não deixa arquivo de teste no diretório compartilhado.
        @unlink(base_path('../'.$caminhoSalvo));
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
