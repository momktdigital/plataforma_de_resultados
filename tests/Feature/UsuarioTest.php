<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    public function test_lista_administradores(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->get('/usuarios');

        $response->assertOk();
        $response->assertSee('coordenador');
    }

    public function test_cria_administrador(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/usuarios', [
            'username' => 'professor',
            'password' => 'senha123456',
        ]);

        $response->assertRedirect(route('usuarios.index', ['aba' => 'administradores']));
        $novo = Admin::where('username', 'professor')->firstOrFail();
        $this->assertTrue(Hash::check('senha123456', $novo->password_hash));
    }

    public function test_rejeita_senha_curta_demais_ao_criar_administrador(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->post('/usuarios', [
            'username' => 'professor',
            'password' => 'curta123',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('admins', ['username' => 'professor']);
    }

    public function test_nome_de_usuario_duplicado_e_rejeitado(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->post('/usuarios', [
            'username' => 'coordenador',
            'password' => 'senha123456',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertDatabaseCount('admins', 1);
    }

    public function test_edita_nome_de_usuario_e_email_sem_mexer_na_senha(): void
    {
        $admin = $this->admin();
        $hashAntes = $admin->password_hash;

        $response = $this->actingAs($admin, 'admin')->put("/usuarios/{$admin->id}", [
            'username' => 'novo-nome',
            'email' => 'novo@example.com',
        ]);

        $response->assertRedirect(route('usuarios.index', ['aba' => 'administradores']));
        $admin->refresh();
        $this->assertSame('novo-nome', $admin->username);
        $this->assertSame('novo@example.com', $admin->email);
        $this->assertSame($hashAntes, $admin->password_hash);
    }

    public function test_redefine_a_senha_de_outro_administrador(): void
    {
        $admin = $this->admin();
        $outro = Admin::create(['username' => 'professor', 'password_hash' => bcrypt('senha-antiga')]);

        $this->actingAs($admin, 'admin')->put("/usuarios/{$outro->id}", [
            'username' => 'professor',
            'password' => 'senha-nova-123',
        ])->assertRedirect(route('usuarios.index', ['aba' => 'administradores']));

        $this->assertTrue(Hash::check('senha-nova-123', $outro->fresh()->password_hash));
    }

    public function test_nao_permite_renomear_para_usuario_ja_existente(): void
    {
        $admin = $this->admin();
        $outro = Admin::create(['username' => 'professor', 'password_hash' => bcrypt('x')]);

        $response = $this->actingAs($admin, 'admin')->put("/usuarios/{$outro->id}", [
            'username' => 'coordenador',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertSame('professor', $outro->fresh()->username);
    }

    public function test_exclui_outro_administrador(): void
    {
        $admin = $this->admin();
        $outro = Admin::create(['username' => 'professor', 'password_hash' => bcrypt('x')]);

        $response = $this->actingAs($admin, 'admin')->delete("/usuarios/{$outro->id}");

        $response->assertRedirect(route('usuarios.index', ['aba' => 'administradores']));
        $this->assertDatabaseMissing('admins', ['id' => $outro->id]);
    }

    public function test_nao_pode_excluir_a_propria_conta(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->delete("/usuarios/{$admin->id}");

        $response->assertSessionHasErrors('admin');
        $this->assertDatabaseHas('admins', ['id' => $admin->id]);
    }

    public function test_guest_nao_acessa_usuarios(): void
    {
        $this->admin();

        $this->get('/usuarios')->assertRedirect(route('login'));
    }

    public function test_lista_administradores_e_paginada(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 55; $i++) {
            Admin::create(['username' => "professor{$i}", 'password_hash' => bcrypt('x')]);
        }

        $response = $this->actingAs($admin, 'admin')->get('/usuarios');

        $response->assertOk();
        $response->assertViewHas('usuarios', fn ($usuarios) => $usuarios->count() === 50 && $usuarios->hasMorePages());
    }
}
