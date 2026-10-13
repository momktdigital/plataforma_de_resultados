<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Models\ResultadoResumo;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Estrelas de participação ao lado do nome do estudante no boletim do portal. */
class PortalEstrelasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function entrar(): Aluno
    {
        $aluno = Aluno::create(['ra' => '2026001', 'cpf' => '12345678909', 'data_nascimento' => '2000-03-15', 'nome' => 'Fulano de Tal']);
        $this->followingRedirects()->post('/portal/consultar', ['cpf' => '123.456.789-09', 'data_nascimento' => '15/03/2000']);

        return $aluno;
    }

    /** Uma prova em que o aluno tem resumo; `$fez = false` grava a falta (resumo ausente, sem nenhuma resposta). */
    private function prova(Aluno $aluno, bool $fez, string $data): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'Prova '.$data, 'data_avaliacao' => $data]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);

        if ($fez) {
            Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => $aluno->ra, 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A']);
            app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

            return;
        }

        ResultadoResumo::create([
            'avaliacao_codigo' => $avaliacao->codigo, 'aluno_chave' => $aluno->ra, 'periodo' => '', 'ra' => $aluno->ra,
            'aluno_id' => $aluno->id, 'acertos' => 0, 'total' => 1, 'percentual' => 0, 'ausente' => true,
        ]);
    }

    public function test_mostra_as_estrelas_perto_do_nome_com_meia_estrela(): void
    {
        $aluno = $this->entrar();
        // 3 de 4 provas previstas = 75% → 3,5 estrelas
        $this->prova($aluno, true, '2026-03-01');
        $this->prova($aluno, true, '2026-03-02');
        $this->prova($aluno, true, '2026-03-03');
        $this->prova($aluno, false, '2026-03-04');

        $this->get(route('portal.resultados'))
            ->assertOk()
            ->assertSee('Participação: 3,5 de 5 estrelas', false)
            ->assertSee('Você fez 3 de 4 provas previstas', false);
    }

    public function test_todas_as_provas_feitas_dao_cinco_estrelas(): void
    {
        $aluno = $this->entrar();
        $this->prova($aluno, true, '2026-03-01');
        $this->prova($aluno, true, '2026-03-02');

        $this->get(route('portal.resultados'))
            ->assertOk()
            ->assertSee('Participação: 5 de 5 estrelas', false);
    }

    public function test_sem_nenhuma_prova_nao_mostra_estrelas(): void
    {
        $this->entrar();

        $this->get(route('portal.resultados'))
            ->assertOk()
            ->assertDontSee('de 5 estrelas', false);
    }
}
