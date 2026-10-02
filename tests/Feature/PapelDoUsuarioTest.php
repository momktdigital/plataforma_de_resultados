<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O perfil (`admins.role`) falha FECHADO: valor desconhecido não vira administrador.
 */
class PapelDoUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $username, ?string $role): Admin
    {
        $usuario = Admin::create(['username' => $username, 'password_hash' => Hash::make('senha-secreta')]);
        // `role` fora do $fillable do default do banco: grava direto, exatamente como o legado/um DBA faria.
        DB::table('admins')->where('id', $usuario->id)->update(['role' => $role]);

        return $usuario->fresh();
    }

    public function test_papel_ignora_caixa_e_espacos(): void
    {
        $this->assertSame(Admin::ROLE_COORDENADOR, (new Admin(['role' => ' Coordinator ']))->papel());
        $this->assertSame(Admin::ROLE_ADMIN, (new Admin(['role' => 'SUPERADMIN']))->papel());
        $this->assertTrue((new Admin(['role' => 'Coordinator']))->ehCoordenador());
        $this->assertFalse((new Admin(['role' => 'Coordinator']))->ehAdministrador());
    }

    public function test_linha_legada_sem_role_continua_administradora(): void
    {
        $this->assertSame(Admin::ROLE_ADMIN, (new Admin(['role' => null]))->papel());
        $this->assertTrue((new Admin(['role' => '']))->ehAdministrador());
    }

    public function test_valor_desconhecido_nao_e_administrador_nem_coordenador(): void
    {
        foreach (['admin', 'coord', 'root', 'coordinator2'] as $role) {
            $usuario = new Admin(['role' => $role]);

            $this->assertNull($usuario->papel(), $role);
            $this->assertFalse($usuario->ehAdministrador(), $role);
            $this->assertFalse($usuario->ehCoordenador(), $role);
            $this->assertFalse($usuario->temPapelValido(), $role);
        }
    }

    public function test_escopos_normalizam_e_deixam_o_perfil_desconhecido_fora_das_duas_listas(): void
    {
        $admin = $this->usuario('adm', 'superadmin');
        $legado = $this->usuario('legado', '');
        $coord = $this->usuario('coord', 'Coordinator');
        $estranho = $this->usuario('estranho', 'qualquer-coisa');

        $administradores = Admin::administradores()->pluck('id')->all();
        $coordenadores = Admin::coordenadores()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$admin->id, $legado->id], $administradores);
        $this->assertSame([$coord->id], $coordenadores);
        $this->assertNotContains($estranho->id, $administradores);
        $this->assertNotContains($estranho->id, $coordenadores);
    }

    public function test_login_com_perfil_desconhecido_nao_abre_sessao(): void
    {
        $this->usuario('estranho', 'qualquer-coisa');

        $this->post('/login', ['username' => 'estranho', 'password' => 'senha-secreta'])
            ->assertSessionHasErrors('username');

        $this->assertGuest('admin');
    }

    public function test_sessao_existente_com_perfil_desconhecido_e_encerrada(): void
    {
        $estranho = $this->usuario('estranho', 'qualquer-coisa');

        $this->actingAs($estranho, 'admin')
            ->get('/avaliacoes')
            ->assertRedirect(route('login'));

        $this->assertGuest('admin');
    }

    public function test_perfil_desconhecido_nao_alcanca_area_so_de_administrador(): void
    {
        $estranho = $this->usuario('estranho', 'qualquer-coisa');

        $this->actingAs($estranho, 'admin')->get('/usuarios')->assertRedirect(route('login'));
        $this->assertGuest('admin');
    }

    public function test_coordenador_com_caixa_diferente_continua_barrado_da_area_de_administrador(): void
    {
        $coord = $this->usuario('coord', 'Coordinator');

        $this->actingAs($coord, 'admin')->get('/usuarios')->assertForbidden();
    }

    public function test_avaliacoes_visiveis_para_perfil_desconhecido_sao_nenhuma(): void
    {
        Avaliacao::create(['nome' => 'Prova', 'data_avaliacao' => '2026-03-10']);

        $this->assertSame(0, Avaliacao::visivelPara($this->usuario('estranho', 'qualquer-coisa'))->count());
        $this->assertSame(1, Avaliacao::visivelPara($this->usuario('adm', 'superadmin'))->count());
    }

    public function test_administrador_segue_acessando_a_area_de_administrador(): void
    {
        $adm = $this->usuario('adm', 'superadmin');

        $this->actingAs($adm, 'admin')->get('/usuarios')->assertOk();
    }
}
