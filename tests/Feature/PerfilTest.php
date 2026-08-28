<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PerfilTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => Hash::make('senhaAtual')]);
    }

    public function test_altera_senha_com_senha_atual_correta(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->put('/perfil/senha', [
            'current_password' => 'senhaAtual',
            'new_password' => 'senhaNova123',
            'new_password_confirmation' => 'senhaNova123',
        ]);

        $response->assertRedirect(route('perfil.edit'));
        $this->assertTrue(Hash::check('senhaNova123', $admin->fresh()->password_hash));
    }

    public function test_rejeita_senha_atual_incorreta(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->put('/perfil/senha', [
            'current_password' => 'errada',
            'new_password' => 'senhaNova123',
            'new_password_confirmation' => 'senhaNova123',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('senhaAtual', $admin->fresh()->password_hash));
    }

    public function test_rejeita_confirmacao_que_nao_confere(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->put('/perfil/senha', [
            'current_password' => 'senhaAtual',
            'new_password' => 'senhaNova123',
            'new_password_confirmation' => 'outraCoisa',
        ]);

        $response->assertSessionHasErrors('new_password');
        $this->assertTrue(Hash::check('senhaAtual', $admin->fresh()->password_hash));
    }

    public function test_rejeita_senha_nova_curta_demais(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->put('/perfil/senha', [
            'current_password' => 'senhaAtual',
            'new_password' => 'curta123',
            'new_password_confirmation' => 'curta123',
        ]);

        $response->assertSessionHasErrors('new_password');
        $this->assertTrue(Hash::check('senhaAtual', $admin->fresh()->password_hash));
    }

    public function test_guest_nao_acessa_perfil(): void
    {
        $this->admin();

        $this->get('/perfil')->assertRedirect(route('login'));
    }
}
