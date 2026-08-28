<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use App\Support\AlunoVinculoResolver;
use App\Support\FiltroDemografico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AlunoVinculoResolver::resolver() é o coração do vínculo aluno<->resposta
 * usado em todo o painel BI e no boletim do aluno (ver o comentário da
 * própria classe) — só era exercitado indiretamente através de serviços que
 * o chamam, nunca testado direto.
 */
class AlunoVinculoResolverTest extends TestCase
{
    use RefreshDatabase;

    private function popularRespondentes(Avaliacao $avaliacao): void
    {
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'questao_numero' => 1, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
    }

    public function test_resolve_aluno_por_aluno_id_quando_preenchido(): void
    {
        $avaliacao = Avaliacao::create([]);
        $this->popularRespondentes($avaliacao);
        $aluno1 = Aluno::create(['ra' => '1', 'nome' => 'Aluno Um']);
        Resposta::where('avaliacao_codigo', $avaliacao->codigo)->where('ra', '1')->update(['aluno_id' => $aluno1->id]);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $resolvido = (new AlunoVinculoResolver)->resolver($avaliacao->codigo);

        $this->assertTrue($resolvido->has('1'));
        $this->assertSame($aluno1->id, $resolvido->get('1')->id);
    }

    public function test_resolve_aluno_por_ra_quando_aluno_id_esta_ausente(): void
    {
        $avaliacao = Avaliacao::create([]);
        $this->popularRespondentes($avaliacao);
        $aluno2 = Aluno::create(['ra' => '2', 'nome' => 'Aluno Dois']);

        $resolvido = (new AlunoVinculoResolver)->resolver($avaliacao->codigo);

        $this->assertTrue($resolvido->has('2'));
        $this->assertSame($aluno2->id, $resolvido->get('2')->id);
    }

    public function test_respondente_sem_cadastro_de_aluno_correspondente_fica_de_fora(): void
    {
        $avaliacao = Avaliacao::create([]);
        $this->popularRespondentes($avaliacao);
        // Nenhum Aluno cadastrado pros RAs 1/2.

        $resolvido = (new AlunoVinculoResolver)->resolver($avaliacao->codigo);

        $this->assertTrue($resolvido->isEmpty());
    }

    public function test_resolver_filtra_por_periodo_quando_informado(): void
    {
        $avaliacao = Avaliacao::create([]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '1', 'periodo' => '2025/1', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '2', 'periodo' => '2025/2', 'questao_numero' => 1, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        Aluno::create(['ra' => '1', 'nome' => 'Aluno Um']);
        Aluno::create(['ra' => '2', 'nome' => 'Aluno Dois']);

        $resolvido = (new AlunoVinculoResolver)->resolver($avaliacao->codigo, '2025/1');

        $this->assertCount(1, $resolvido);
        $this->assertTrue($resolvido->has('1'));
    }

    public function test_resolver_sem_respondentes_devolve_colecao_vazia(): void
    {
        $avaliacao = Avaliacao::create([]);

        $resolvido = (new AlunoVinculoResolver)->resolver($avaliacao->codigo);

        $this->assertTrue($resolvido->isEmpty());
    }

    public function test_chaves_filtradas_devolve_null_quando_filtro_vazio(): void
    {
        $avaliacao = Avaliacao::create([]);
        $this->popularRespondentes($avaliacao);
        Aluno::create(['ra' => '1']);
        Aluno::create(['ra' => '2']);

        $chaves = (new AlunoVinculoResolver)->chavesFiltradas($avaliacao->codigo, '', new FiltroDemografico);

        $this->assertNull($chaves);
    }

    public function test_chaves_filtradas_restringe_pela_turma(): void
    {
        $avaliacao = Avaliacao::create([]);
        $this->popularRespondentes($avaliacao);
        Aluno::create(['ra' => '1', 'turma' => 'A']);
        Aluno::create(['ra' => '2', 'turma' => 'B']);

        $filtro = new FiltroDemografico(turma: 'A');
        $chaves = (new AlunoVinculoResolver)->chavesFiltradas($avaliacao->codigo, '', $filtro);

        $this->assertEquals(['1'], $chaves->values()->all());
    }

    public function test_opcoes_disponiveis_lista_valores_distintos_dos_respondentes(): void
    {
        $avaliacao = Avaliacao::create([]);
        $this->popularRespondentes($avaliacao);
        Aluno::create(['ra' => '1', 'turma' => 'A', 'sexo' => 'F', 'cor_raca' => 'Parda']);
        Aluno::create(['ra' => '2', 'turma' => 'B', 'sexo' => 'M', 'cor_raca' => 'Parda']);

        $opcoes = (new AlunoVinculoResolver)->opcoesDisponiveis($avaliacao->codigo);

        $this->assertSame(['A', 'B'], $opcoes['turmas']);
        $this->assertSame(['F', 'M'], $opcoes['sexos']);
        $this->assertSame(['Parda'], $opcoes['corRacas']);
    }
}
