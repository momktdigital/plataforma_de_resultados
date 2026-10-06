<?php

namespace Tests\Feature;

use App\Models\Acompanhamento;
use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Atividade;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\AcompanhamentoService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Acompanhamento de alunos em risco pelo coordenador: contatado / em acompanhamento / resolvido, com observação e data,
 * histórico que só se acrescenta e visível só para quem coordena o curso.
 */
class AcompanhamentoTest extends TestCase
{
    use RefreshDatabase;

    private int $ra = 7000;

    private function coordenador(string $nome, string $curso): Admin
    {
        $coordenador = Admin::create(['username' => $nome, 'email' => "$nome@example.test", 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos([$curso]);

        return $coordenador;
    }

    /** Aluno com resultado de $acertos (0–10) numa avaliação do curso — baixo o bastante para entrar "em atenção". */
    private function aluno(string $curso, int $acertos = 2, ?Avaliacao $avaliacao = null): Aluno
    {
        $this->ra++;
        $avaliacao ??= $this->avaliacao();
        $aluno = Aluno::create(['ra' => (string) $this->ra, 'nome' => 'Aluno Sigiloso '.$this->ra, 'curso' => $curso, 'periodo' => '3º']);
        foreach (range(1, 10) as $n) {
            Resposta::create([
                'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => $aluno->ra, 'periodo' => '3º',
                'questao_numero' => $n, 'resposta' => $n <= $acertos ? 'A' : 'B',
            ]);
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $aluno;
    }

    private function avaliacao(): Avaliacao
    {
        static $nome = 0;
        $avaliacao = Avaliacao::create(['nome' => 'Prova '.++$nome, 'data_avaliacao' => '2026-03-10']);
        foreach (range(1, 10) as $n) {
            Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A']);
        }

        return $avaliacao;
    }

    public function test_coordenador_registra_acompanhamento_e_ele_aparece_na_ficha_na_lista_e_no_painel(): void
    {
        $coordenador = $this->coordenador('coord', 'DIREITO');
        $aluno = $this->aluno('DIREITO');

        $this->actingAs($coordenador, 'admin')
            ->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => 'contatado', 'observacao' => 'Liguei para a família.'])
            ->assertRedirect(route('coordenador.alunos.show', $aluno))
            ->assertSessionHas('status', 'Acompanhamento registrado.');

        $registro = Acompanhamento::first();
        $this->assertSame($aluno->id, $registro->aluno_id);
        $this->assertSame($coordenador->id, $registro->admin_id);
        $this->assertSame('DIREITO', $registro->curso);
        $this->assertSame('Liguei para a família.', $registro->observacao);

        $this->actingAs($coordenador, 'admin')->get(route('coordenador.alunos.show', $aluno))
            ->assertOk()->assertSee('Liguei para a família.')->assertSee('Contatado')->assertSee($coordenador->username);
        $this->actingAs($coordenador, 'admin')->get(route('coordenador.alunos'))->assertOk()->assertSee('Contatado');
        $this->actingAs($coordenador, 'admin')->get(route('coordenador.painel'))->assertOk()->assertSee('Contatado');
    }

    public function test_historico_so_se_acrescenta_e_o_estado_atual_e_o_ultimo_registro(): void
    {
        $coordenador = $this->coordenador('coord', 'DIREITO');
        $aluno = $this->aluno('DIREITO');

        foreach (['contatado', 'em_acompanhamento', 'resolvido'] as $status) {
            $this->actingAs($coordenador, 'admin')->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => $status, 'observacao' => "obs $status"]);
        }

        $this->assertSame(3, Acompanhamento::count());
        $ultimos = app(AcompanhamentoService::class)->ultimos([$aluno->id], ['DIREITO']);
        $this->assertSame('resolvido', $ultimos[$aluno->id]['status']);
        $this->assertSame('Resolvido', $ultimos[$aluno->id]['rotulo']);

        $this->actingAs($coordenador, 'admin')->get(route('coordenador.alunos.show', $aluno))
            ->assertSee('obs contatado')->assertSee('obs em_acompanhamento')->assertSee('obs resolvido');
    }

    public function test_filtro_da_lista_por_situacao_do_acompanhamento(): void
    {
        $coordenador = $this->coordenador('coord', 'DIREITO');
        $avaliacao = $this->avaliacao();
        $acompanhado = $this->aluno('DIREITO', 2, $avaliacao);
        $sem = $this->aluno('DIREITO', 3, $avaliacao);
        $this->actingAs($coordenador, 'admin')->post(route('coordenador.alunos.acompanhamento', $acompanhado), ['status' => 'em_acompanhamento']);

        $this->actingAs($coordenador, 'admin')->get(route('coordenador.alunos', ['acompanhamento' => 'em_acompanhamento']))
            ->assertOk()->assertSee($acompanhado->nome)->assertDontSee($sem->nome);
        $this->actingAs($coordenador, 'admin')->get(route('coordenador.alunos', ['acompanhamento' => 'sem_registro']))
            ->assertOk()->assertSee($sem->nome)->assertDontSee($acompanhado->nome);
        $this->actingAs($coordenador, 'admin')->get(route('coordenador.alunos', ['acompanhamento' => 'resolvido']))
            ->assertOk()->assertDontSee($acompanhado->nome)->assertDontSee($sem->nome);
        // valor inválido vira "todos" em vez de quebrar
        $this->actingAs($coordenador, 'admin')->get(route('coordenador.alunos', ['acompanhamento' => 'x']))->assertOk()->assertSee($sem->nome);
    }

    public function test_coordenador_de_outro_curso_nao_registra_nem_ve(): void
    {
        $aluno = $this->aluno('DIREITO');
        $outro = $this->coordenador('medicina', 'MEDICINA');
        $dono = $this->coordenador('direito', 'DIREITO');
        $this->actingAs($dono, 'admin')->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => 'contatado', 'observacao' => 'segredo']);

        $this->actingAs($outro, 'admin')->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => 'contatado'])->assertNotFound();
        $this->actingAs($outro, 'admin')->get(route('coordenador.alunos.show', $aluno))->assertNotFound();
        $this->assertSame(1, Acompanhamento::count());

        // o registro é do curso em que foi feito: não aparece para quem coordena outro curso
        $this->assertSame([], app(AcompanhamentoService::class)->ultimos([$aluno->id], ['MEDICINA']));
        $this->assertCount(0, app(AcompanhamentoService::class)->historico($aluno, ['MEDICINA']));
        $this->assertCount(1, app(AcompanhamentoService::class)->historico($aluno, ['DIREITO']));
    }

    public function test_validacao_do_status_e_da_observacao(): void
    {
        $coordenador = $this->coordenador('coord', 'DIREITO');
        $aluno = $this->aluno('DIREITO');
        $rota = route('coordenador.alunos.acompanhamento', $aluno);

        $this->actingAs($coordenador, 'admin')->post($rota, [])->assertSessionHasErrors('status');
        $this->actingAs($coordenador, 'admin')->post($rota, ['status' => 'qualquer'])->assertSessionHasErrors('status');
        $this->actingAs($coordenador, 'admin')->post($rota, ['status' => 'contatado', 'observacao' => str_repeat('x', 1001)])->assertSessionHasErrors('observacao');
        $this->assertSame(0, Acompanhamento::count());

        // observação em branco vira nula
        $this->actingAs($coordenador, 'admin')->post($rota, ['status' => 'contatado', 'observacao' => '   ']);
        $this->assertNull(Acompanhamento::first()->observacao);
    }

    public function test_reitor_na_visao_do_curso_ve_o_historico_mas_nao_registra(): void
    {
        $aluno = $this->aluno('DIREITO');
        $coordenador = $this->coordenador('coord', 'DIREITO');
        $this->actingAs($coordenador, 'admin')->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => 'contatado', 'observacao' => 'conversa registrada']);

        $reitor = Admin::create(['username' => 'reitor', 'email' => 'r@example.test', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_REITOR]);
        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO']));

        $this->actingAs($reitor, 'admin')->get(route('coordenador.alunos.show', $aluno))
            ->assertOk()->assertSee('conversa registrada')->assertDontSee('Registrar</button>', false);
        $this->actingAs($reitor, 'admin')->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => 'resolvido'])->assertForbidden();
        $this->assertSame(1, Acompanhamento::count());
    }

    public function test_administrador_nao_registra_acompanhamento(): void
    {
        $aluno = $this->aluno('DIREITO');
        $admin = Admin::create(['username' => 'admin', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);

        $this->actingAs($admin, 'admin')->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => 'contatado'])->assertForbidden();
        $this->assertSame(0, Acompanhamento::count());
    }

    public function test_a_auditoria_registra_o_fato_mas_nunca_a_observacao(): void
    {
        $coordenador = $this->coordenador('coord', 'DIREITO');
        $aluno = $this->aluno('DIREITO');

        $this->actingAs($coordenador, 'admin')->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => 'contatado', 'observacao' => 'texto sensivel da conversa']);

        $atividade = Atividade::where('acao', 'acompanhamento.registrado')->first();
        $this->assertNotNull($atividade);
        $this->assertSame($coordenador->id, $atividade->admin_id);
        $this->assertStringNotContainsString('texto sensivel', json_encode($atividade->detalhes));
    }

    public function test_registros_somem_junto_com_o_cadastro_do_aluno_lgpd(): void
    {
        $coordenador = $this->coordenador('coord', 'DIREITO');
        $aluno = $this->aluno('DIREITO');
        $this->actingAs($coordenador, 'admin')->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => 'contatado', 'observacao' => 'obs']);

        $aluno->delete();

        $this->assertSame(0, DB::table('acompanhamentos')->count());
    }

    public function test_planilha_de_alunos_leva_o_acompanhamento(): void
    {
        $coordenador = $this->coordenador('coord', 'DIREITO');
        $aluno = $this->aluno('DIREITO');
        $this->actingAs($coordenador, 'admin')->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => 'em_acompanhamento']);

        $resposta = $this->actingAs($coordenador, 'admin')->get(route('coordenador.alunos.xlsx'));
        $resposta->assertOk();

        $arquivo = tempnam(sys_get_temp_dir(), 'xls');
        file_put_contents($arquivo, $resposta->streamedContent());
        $linhas = IOFactory::load($arquivo)->getActiveSheet()->toArray();
        unlink($arquivo);

        $this->assertSame('Acompanhamento', $linhas[0][14]);
        $this->assertSame('Em acompanhamento', $linhas[1][14]);
    }
}
