<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        Admin::create(['username' => 'coordenador', 'password_hash' => Hash::make('x')]);

        $this->get('/avaliacoes')->assertRedirect(route('login'));
    }

    public function test_fresh_install_without_admin_redirects_to_installer(): void
    {
        $this->get('/avaliacoes')->assertRedirect(route('instalar.inicio'));
        $this->get('/login')->assertRedirect(route('instalar.inicio'));
    }

    public function test_admin_can_login_with_legacy_credentials(): void
    {
        Admin::create([
            'username' => 'coordenador',
            'password_hash' => Hash::make('senha-secreta'),
        ]);

        $response = $this->post('/login', [
            'username' => 'coordenador',
            'password' => 'senha-secreta',
        ]);

        $response->assertRedirect(route('avaliacoes.index'));
        $this->assertAuthenticated('admin');
    }

    public function test_admin_cannot_login_with_wrong_password(): void
    {
        Admin::create([
            'username' => 'coordenador',
            'password_hash' => Hash::make('senha-secreta'),
        ]);

        $response = $this->post('/login', [
            'username' => 'coordenador',
            'password' => 'errada',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest('admin');
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        Admin::create([
            'username' => 'coordenador',
            'password_hash' => Hash::make('senha-secreta'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'coordenador', 'password' => 'errada']);
        }

        $response = $this->post('/login', ['username' => 'coordenador', 'password' => 'senha-secreta']);

        $response->assertSessionHasErrors('username');
        $this->assertGuest('admin');
    }

    public function test_admin_can_logout(): void
    {
        $admin = Admin::create([
            'username' => 'coordenador',
            'password_hash' => Hash::make('senha-secreta'),
        ]);

        $response = $this->actingAs($admin, 'admin')->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest('admin');
    }
}
