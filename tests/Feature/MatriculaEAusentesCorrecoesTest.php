<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\AlunoMatricula;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\CoordenadorDashboardService;
use App\Services\CursoDoResultadoService;
use App\Services\MatriculaImportService;
use App\Services\RelatorioAdminService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Correções do "Lote B": ausente em prova com questão `dar_ponto` (11), resultado que perde o curso
 * (12), planilha de matrícula antiga desfazendo o estado novo (13), status de período cumprido (14) e
 * avaliação sem data no painel do coordenador (15).
 */
class MatriculaEAusentesCorrecoesTest extends TestCase
{
    use RefreshDatabase;

    private const CABECALHO = 'RA,Nome,Status,Dt. Ativação,Per. Letivo,Curso,Período,Dt. Ocorrência';

    private function importar(string ...$linhas): void
    {
        $csv = self::CABECALHO."\n".implode("\n", $linhas)."\n";
        app(MatriculaImportService::class)->importar(UploadedFile::fake()->createWithContent('matricula.csv', $csv));
    }

    private function aluna(): Aluno
    {
        return Aluno::where('ra', '67482')->firstOrFail();
    }

    private function matricula(string $curso, string $periodoLetivo = '2026/2'): AlunoMatricula
    {
        return AlunoMatricula::where('aluno_id', $this->aluna()->id)->where('curso', $curso)->where('periodo_letivo', $periodoLetivo)->firstOrFail();
    }

    /** Avaliação de 1 questão (gabarito A) com a resposta da aluna 67482. */
    private function avaliacaoDaAluna(string $nome, ?string $data): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data]);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A', 'area' => 'Clínica']);
        Resposta::create([
            'avaliacao_codigo' => $avaliacao->codigo,
            'aluno_id' => $this->aluna()->id,
            'ra' => '67482',
            'periodo' => '2º',
            'questao_numero' => 1,
            'resposta' => 'A',
        ]);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        return $avaliacao;
    }

    private function cursoDoResultado(Avaliacao $avaliacao): ?string
    {
        return DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacao->codigo)->value('curso');
    }

    // ---------------------------------------------------------------- 11: ausente com dar_ponto

    public function test_ausente_e_reconhecido_em_prova_com_questao_dar_ponto(): void
    {
        $categoria = Categoria::create(['nome' => 'Simulados']);
        $avaliacao = Avaliacao::create(['nome' => 'Com anulada', 'data_avaliacao' => '2026-03-10', 'categoria_id' => $categoria->id]);
        for ($n = 1; $n <= 5; $n++) {
            Questao::create([
                'avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A',
                // A questão 5 foi anulada com "dar o ponto a todos": até quem faltou "acerta" essa.
                'anulada_modo' => $n === 5 ? 'dar_ponto' : null,
            ]);
        }

        // Aluno 1 respondeu (acertou 3 + a anulada); aluno 2 faltou (prova em branco).
        foreach (['1' => ['A', 'A', 'A', 'B', 'B'], '2' => ['', '', '', '', '']] as $ra => $respostas) {
            $aluno = Aluno::create(['ra' => $ra, 'nome' => "Aluno {$ra}", 'curso' => 'DIREITO', 'periodo' => '2º', 'turma' => 'Turma A']);
            foreach ($respostas as $i => $resposta) {
                Resposta::create([
                    'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => $ra,
                    'periodo' => '', 'questao_numero' => $i + 1, 'resposta' => $resposta,
                ]);
            }
        }
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        // O ausente tem 1 "acerto" (a anulada) e, antes, o filtro `acertos = 0` o deixava passar por presente.
        $this->assertSame(1, (int) DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacao->codigo)->where('aluno_chave', '2')->value('acertos'));

        $pontos = app(RelatorioAdminService::class)->evolucaoCategoria($avaliacao);

        $this->assertSame([2], array_column($pontos, 'respondentes'));
        $this->assertSame([1], array_column($pontos, 'presentes'), 'quem faltou não é presente, mesmo "acertando" a anulada');
        $this->assertSame([80.0], array_column($pontos, 'mediaPresentes'), 'só o aluno 1: 3 + a anulada = 4 de 5');
    }

    // ---------------------------------------------------------------- 13: planilha antiga

    public function test_planilha_antiga_nao_reverte_o_status_mais_novo_da_matricula(): void
    {
        $nova = '67482,Sofia,CANCELADA,08/07/2026,2026/2,Medicina,2,05/08/2026';
        $antiga = '67482,Sofia,ATIVA,08/07/2026,2026/2,Medicina,2,';

        $this->importar($nova);
        $this->importar($antiga);   // exportada ANTES do cancelamento, importada depois

        $this->assertSame('CANCELADA', $this->matricula('MEDICINA')->status);
        $this->assertSame('2026-08-05', $this->matricula('MEDICINA')->data_fim->format('Y-m-d'));
        $this->assertSame('CANCELADA', $this->aluna()->status, 'o status do aluno vem da matrícula escolhida do histórico');
    }

    public function test_planilha_mais_nova_continua_atualizando_a_matricula(): void
    {
        $this->importar('67482,Sofia,ATIVA,08/07/2026,2026/2,Medicina,2,');
        $this->importar('67482,Sofia,CANCELADA,08/07/2026,2026/2,Medicina,2,05/08/2026');

        $this->assertSame('CANCELADA', $this->matricula('MEDICINA')->status);

        // E uma reativação com nova Dt. Ativação posterior também vale.
        $this->importar('67482,Sofia,ATIVA,01/09/2026,2026/2,Medicina,2,');
        $this->assertSame('ATIVA', $this->matricula('MEDICINA')->status);
    }

    public function test_sem_datas_para_comparar_vale_a_ultima_importacao(): void
    {
        $this->importar('67482,Sofia,ATIVA,,2026/2,Medicina,2,');
        $this->importar('67482,Sofia,TRANCADA,,2026/2,Medicina,2,');

        $this->assertSame('TRANCADA', $this->matricula('MEDICINA')->status);
    }

    // ---------------------------------------------------------------- 14: aprovado / reprovado

    public function test_aprovado_nao_e_saida_e_a_data_de_ocorrencia_dele_nao_encerra_a_matricula(): void
    {
        // Cancelou Medicina em fevereiro e fez o semestre em Odontologia (APROVADO, com Dt. Ocorrência de janeiro,
        // a data de um lançamento — não de uma saída).
        $this->importar(
            '67482,Sofia,CANCELADA,01/02/2026,2026/1,Medicina,1,20/02/2026',
            '67482,Sofia,APROVADO,05/01/2026,2026/1,Odontologia,1,29/01/2026',
        );

        $this->assertSame('ODONTOLOGIA', $this->aluna()->curso, 'APROVADO vale como matrícula do período; CANCELADA, não');

        // Prova de abril: a Odontologia ainda era a matrícula vigente (APROVADO só encerra com o semestre).
        $avaliacao = $this->avaliacaoDaAluna('Abril', '2026-04-10');
        $this->assertSame('ODONTOLOGIA', $this->cursoDoResultado($avaliacao));
    }

    public function test_status_de_periodo_cumprido_sao_reconhecidos(): void
    {
        foreach (['APROVADO', 'aprovado_parcialmente', ' REPROVADO '] as $status) {
            $this->assertTrue(AlunoMatricula::cumpriuPeriodo($status), $status);
            $this->assertTrue(AlunoMatricula::vigenteNoPeriodo($status), $status);
        }
        foreach (['TRANSFERIDA', 'CANCELADA', 'TRANCADA', 'DESISTENTE', 'REMANEJADA'] as $status) {
            $this->assertFalse(AlunoMatricula::vigenteNoPeriodo($status), $status);
        }
        $this->assertTrue(AlunoMatricula::vigenteNoPeriodo(null));
        $this->assertTrue(AlunoMatricula::vigenteNoPeriodo('ATIVA'));
    }

    public function test_sem_data_da_prova_vale_a_matricula_do_periodo_letivo_mais_recente(): void
    {
        // 2026/1 cumprido (APROVADO) e 2026/2 trancado: uma prova sem data não pode cair no semestre antigo só
        // porque o aprovado conta como "vigente".
        $this->importar(
            '67482,Sofia,APROVADO_PARCIALMENTE,27/11/2025,2026/1,Medicina,1,',
            '67482,Sofia,TRANCADA,28/07/2026,2026/2,Medicina,2,22/09/2026',
        );

        $avaliacao = $this->avaliacaoDaAluna('Diagnóstico sem data', null);

        $matricula = DB::table('aluno_matriculas')->where('id', DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacao->codigo)->value('matricula_id'))->first();
        $this->assertSame('2026/2', $matricula->periodo_letivo);
        $this->assertSame('MEDICINA', $this->cursoDoResultado($avaliacao));
    }

    // ---------------------------------------------------------------- 12: curso dos resultados

    public function test_resultado_de_aluno_excluido_continua_no_curso_depois_de_recalcular(): void
    {
        $this->importar('67482,Sofia,ATIVA,05/01/2026,2026/1,Medicina,1,');
        $avaliacao = $this->avaliacaoDaAluna('Março', '2026-03-10');
        $this->assertSame('MEDICINA', $this->cursoDoResultado($avaliacao));

        // Cadastro de acesso excluído (o histórico de matrículas some junto, em cascata).
        $this->aluna()->delete();
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);

        $this->assertSame('MEDICINA', $this->cursoDoResultado($avaliacao), 'a prova não pode sair do coordenador do curso');

        // E reavaliar o curso dos resultados também não zera.
        (new CursoDoResultadoService)->atualizarAvaliacao($avaliacao->codigo);
        $this->assertSame('MEDICINA', $this->cursoDoResultado($avaliacao));
    }

    public function test_resultado_importado_antes_da_matricula_ganha_o_curso_quando_a_matricula_chega(): void
    {
        $avaliacao = Avaliacao::create(['nome' => 'Março', 'data_avaliacao' => '2026-03-10']);
        Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => 1, 'gabarito' => 'A']);
        // Um aluno identificado só pelo RA e outro só pelo CPF — nenhum cadastrado ainda (aluno_id nulo).
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'ra' => '67482', 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'A']);
        Resposta::create(['avaliacao_codigo' => $avaliacao->codigo, 'cpf' => '12345678909', 'periodo' => '', 'questao_numero' => 1, 'resposta' => 'B']);
        app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        $this->assertSame([null, null], DB::table('resultado_resumos')->orderBy('id')->pluck('curso')->all());

        $csv = "RA,Nome,CPF,Status,Dt. Ativação,Per. Letivo,Curso,Período\n"
            ."67482,Sofia,,ATIVA,05/01/2026,2026/1,Medicina,1\n"
            ."99999,Beto,123.456.789-09,ATIVA,05/01/2026,2026/1,Direito,1\n";
        app(MatriculaImportService::class)->importar(UploadedFile::fake()->createWithContent('matricula.csv', $csv));

        $cursos = DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacao->codigo)->pluck('curso', 'aluno_chave')->all();
        $this->assertSame('MEDICINA', $cursos['67482'], 'achado pelo RA');
        $this->assertSame('DIREITO', $cursos['12345678909'], 'achado pelo CPF');
    }

    // ---------------------------------------------------------------- 15: avaliação sem data no painel

    public function test_periodo_letivo_vem_do_nome_da_avaliacao(): void
    {
        $this->assertSame('2026/2', CoordenadorDashboardService::periodoLetivoDoNome('2026/2 - Diagnóstico Institucional - Direito'));
        $this->assertSame('2025/1', CoordenadorDashboardService::periodoLetivoDoNome('  2025.1 Simulado'));
        $this->assertSame('2026/1', CoordenadorDashboardService::periodoLetivoDoNome('2026-1: Prova'));
        $this->assertNull(CoordenadorDashboardService::periodoLetivoDoNome('Diagnóstico 2026/2'), 'só o início do nome vale');
        $this->assertNull(CoordenadorDashboardService::periodoLetivoDoNome('2026/3 - Prova'));
        $this->assertNull(CoordenadorDashboardService::periodoLetivoDoNome('ENADE'));
    }

    private function coordenador(string $curso): Admin
    {
        $coordenador = Admin::create(['username' => 'coord', 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos([$curso]);

        return $coordenador;
    }

    /** @return array<int, int> códigos das avaliações listadas no painel */
    private function codigosNoPainel(array $dados): array
    {
        return collect($dados['categorias'] ?? [])->flatMap(fn ($c) => collect($c['avaliacoes'])->pluck('codigo'))->map(fn ($c) => (int) $c)->all();
    }

    public function test_avaliacao_sem_data_aparece_no_periodo_do_nome(): void
    {
        $this->importar('67482,Sofia,ATIVA,05/01/2026,2026/2,Medicina,2,');
        $comData = $this->avaliacaoDaAluna('2026/1 - Prova de março', '2026-03-10');
        $semData = $this->avaliacaoDaAluna('2026/2 - Diagnóstico Institucional - Medicina', null);

        $servico = app(CoordenadorDashboardService::class);
        $coordenador = $this->coordenador('MEDICINA');

        $painel = $servico->gerar($coordenador);
        $this->assertContains('2026/2', $painel['periodosDisponiveis']);
        $this->assertSame('2026/2', $painel['periodoSelecionado'], 'o mais recente é o do nome da avaliação sem data');
        $this->assertContains($semData->codigo, $this->codigosNoPainel($painel));
        $this->assertNotContains($comData->codigo, $this->codigosNoPainel($painel));

        $painelAntigo = $servico->gerar($coordenador, '', '2026/1');
        $this->assertContains($comData->codigo, $this->codigosNoPainel($painelAntigo));
        $this->assertNotContains($semData->codigo, $this->codigosNoPainel($painelAntigo));
    }

    public function test_avaliacao_sem_data_e_sem_periodo_no_nome_usa_o_periodo_letivo_das_matriculas(): void
    {
        $this->importar('67482,Sofia,ATIVA,05/01/2026,2026/1,Medicina,1,');
        $semData = $this->avaliacaoDaAluna('Diagnóstico Institucional', null);

        $painel = app(CoordenadorDashboardService::class)->gerar($this->coordenador('MEDICINA'));

        $this->assertSame('2026/1', $painel['periodoSelecionado']);
        $this->assertContains($semData->codigo, $this->codigosNoPainel($painel));
    }
}
