<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RedefinirSenhaAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_redefine_a_senha_quando_as_duas_digitacoes_coincidem(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('senha-antiga')]);

        $this->artisan('admin:redefinir-senha coordenador')
            ->expectsQuestion('Nova senha (mínimo 10 caracteres)', 'senha-nova-123')
            ->expectsQuestion('Confirme a nova senha', 'senha-nova-123')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('senha-nova-123', $admin->fresh()->password_hash));
        $this->assertDatabaseHas('atividades', [
            'acao' => 'administrador.senha_redefinida_via_cli',
            'admin_username' => 'CLI: admin:redefinir-senha',
        ]);
    }

    public function test_rejeita_quando_as_senhas_nao_coincidem(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('senha-antiga')]);

        $this->artisan('admin:redefinir-senha coordenador')
            ->expectsQuestion('Nova senha (mínimo 10 caracteres)', 'senha-a')
            ->expectsQuestion('Confirme a nova senha', 'senha-b')
            ->assertExitCode(1);

        $this->assertTrue(Hash::check('senha-antiga', $admin->fresh()->password_hash));
    }

    public function test_rejeita_senha_curta_demais(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('senha-antiga')]);

        $this->artisan('admin:redefinir-senha coordenador')
            ->expectsQuestion('Nova senha (mínimo 10 caracteres)', 'curta123')
            ->expectsQuestion('Confirme a nova senha', 'curta123')
            ->assertExitCode(1);

        $this->assertTrue(Hash::check('senha-antiga', $admin->fresh()->password_hash));
    }

    public function test_falha_para_usuario_inexistente(): void
    {
        $this->artisan('admin:redefinir-senha nao-existe')->assertExitCode(1);
    }
}
