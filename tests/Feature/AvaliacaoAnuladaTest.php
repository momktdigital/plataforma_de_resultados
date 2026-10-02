<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ComparacaoAvaliacoesService;
use App\Services\Portal\ResultadoConsultaService;
use App\Services\RelatorioAdminService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Avaliação inteira com status "anulada": some do portal do aluno, da evolução, das comparações e de tudo que
 * o coordenador enxerga — só o administrador continua vendo e abrindo.
 */
class AvaliacaoAnuladaTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private Aluno $aluno;

    private Avaliacao $valida;

    private Avaliacao $anulada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create(['nome' => 'Simulados']);
        $this->aluno = Aluno::create(['ra' => '1001', 'nome' => 'Aluna', 'curso' => 'DIREITO', 'periodo' => '2º']);

        $this->valida = $this->avaliacao('Valida', '2026-03-10', 'ativa');
        $this->anulada = $this->avaliacao('Anulada', '2026-04-10', 'anulada');
    }

    private function avaliacao(string $nome, string $data, string $status): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $this->categoria->id, 'status' => $status]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create([
            'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $this->aluno->id, 'ra' => '1001',
            'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A',
        ]);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    private function admin(): Admin
    {
        return Admin::create(['username' => 'adm', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_ADMIN]);
    }

    private function coordenador(): Admin
    {
        $coordenador = Admin::create(['username' => 'coord', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos(['DIREITO']);

        return $coordenador;
    }

    public function test_boletim_do_aluno_nao_lista_prova_anulada(): void
    {
        $resultados = app(ResultadoConsultaService::class)->buscarPorAluno($this->aluno);

        $this->assertSame(['Valida'], array_map(fn ($r) => $r['avaliacao']->nome, $resultados));
    }

    public function test_detalhe_de_prova_anulada_nao_abre_no_portal(): void
    {
        $servico = app(ResultadoConsultaService::class);

        $this->assertNotNull($servico->buscarUmaAvaliacao($this->aluno, $this->valida->codigo, ''));
        $this->assertNull($servico->buscarUmaAvaliacao($this->aluno, $this->anulada->codigo, ''));
    }

    public function test_evolucao_da_categoria_ignora_prova_anulada(): void
    {
        $pontos = app(RelatorioAdminService::class)->evolucaoCategoria($this->valida);

        $this->assertSame(['Valida'], array_column($pontos, 'nome'));
    }

    public function test_comparacao_nao_oferece_prova_anulada(): void
    {
        $outra = $this->avaliacao('Outra', '2026-05-10', 'ativa');

        $opcoes = app(ComparacaoAvaliacoesService::class)->opcoesDisponiveis($this->valida);

        $this->assertSame([$outra->codigo], $opcoes->pluck('codigo')->map(fn ($c) => (int) $c)->all());
    }

    public function test_coordenador_nao_ve_nem_abre_prova_anulada_mas_o_administrador_sim(): void
    {
        $coordenador = $this->coordenador();
        $admin = $this->admin();

        $this->assertSame([$this->valida->codigo], Avaliacao::visivelPara($coordenador)->pluck('codigo')->all());
        $this->assertEqualsCanonicalizing([$this->valida->codigo, $this->anulada->codigo], Avaliacao::visivelPara($admin)->pluck('codigo')->all());
        $this->assertFalse($this->anulada->acessivelPara($coordenador));
        $this->assertTrue($this->anulada->acessivelPara($admin));

        $this->actingAs($coordenador, 'admin')->get(route('avaliacoes.bi', $this->anulada))->assertNotFound();
        $this->actingAs($coordenador, 'admin')->get(route('avaliacoes.bi', $this->valida))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('avaliacoes.bi', $this->anulada))->assertOk();
    }

    public function test_lista_do_administrador_mostra_a_etiqueta_anulada(): void
    {
        $this->actingAs($this->admin(), 'admin')->get(route('avaliacoes.index'))
            ->assertOk()
            ->assertSee('Anulada');
    }

    public function test_avaliacao_criada_sem_informar_status_conta_como_ativa(): void
    {
        $padrao = Avaliacao::create(['nome' => 'Padrao', 'categoria_id' => $this->categoria->id]);

        $this->assertFalse($padrao->fresh()->estaAnulada());
        $this->assertTrue(Avaliacao::naoAnulada()->where('codigo', $padrao->codigo)->exists());
        $this->assertFalse(Avaliacao::naoAnulada()->where('codigo', $this->anulada->codigo)->exists());
    }
}
