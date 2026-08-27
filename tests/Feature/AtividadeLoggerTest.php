<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Atividade;
use App\Support\AtividadeLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AtividadeLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_o_admin_autenticado(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
        $this->actingAs($admin, 'admin');

        AtividadeLogger::registrar('questao.gabarito_alterado', 'Questao', 42, ['gabarito_antes' => 'A', 'gabarito_depois' => 'B']);

        $this->assertDatabaseHas('atividades', [
            'admin_id' => $admin->id,
            'admin_username' => 'coordenador',
            'acao' => 'questao.gabarito_alterado',
            'alvo_tipo' => 'Questao',
            'alvo_id' => '42',
        ]);

        $atividade = Atividade::firstOrFail();
        $this->assertSame('A', $atividade->detalhes['gabarito_antes']);
        $this->assertSame('B', $atividade->detalhes['gabarito_depois']);
    }

    public function test_registra_origem_cli_quando_nao_ha_admin_autenticado(): void
    {
        AtividadeLogger::registrar('import.legado', 'Avaliacao', 7);

        $this->assertDatabaseHas('atividades', [
            'admin_id' => null,
            'admin_username' => 'sistema',
            'acao' => 'import.legado',
            'alvo_tipo' => 'Avaliacao',
            'alvo_id' => '7',
        ]);
    }

    public function test_sobrevive_a_exclusao_do_admin(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
        $this->actingAs($admin, 'admin');

        AtividadeLogger::registrar('questao.excluida', 'Questao', 1);

        $admin->delete();

        $atividade = Atividade::firstOrFail();
        $this->assertNull($atividade->fresh()->admin_id);
        $this->assertSame('coordenador', $atividade->admin_username);
    }

    public function test_tela_de_auditoria_lista_as_atividades_mais_recentes_primeiro(): void
    {
        $admin = Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
        $this->actingAs($admin, 'admin');

        AtividadeLogger::registrar('questao.excluida', 'Questao', 1);
        AtividadeLogger::registrar('periodo.excluido', 'Avaliacao', 2);

        $response = $this->get('/sistema/atividades');

        $response->assertOk();
        $response->assertSeeInOrder(['periodo.excluido', 'questao.excluida']);
    }

    public function test_guest_nao_acessa_tela_de_auditoria(): void
    {
        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);

        $this->get('/sistema/atividades')->assertRedirect(route('login'));
    }
}
