<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\AlunoMatricula;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\AvaliacaoCursoService;
use App\Services\CoordenadorDashboardService;
use App\Services\MatriculaImportService;
use App\Services\ResumoResultadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Curso é da MATRÍCULA, não do aluno: quem se transfere de curso continua nos
 * números do curso antigo nas provas que fez nele, e passa a contar para o
 * novo nas seguintes. Cenário real: aluna de Medicina (2026/1) transferida
 * para Odontologia em 05/08/2026 — duas linhas no período letivo 2026/2.
 */
class MatriculaHistoricoTest extends TestCase
{
    use RefreshDatabase;

    private const CABECALHO = 'RA,Nome,Status,Dt. Ativação,Per. Letivo,Curso,Período,Dt. Ocorrência';

    private function importar(string ...$linhas): void
    {
        $csv = self::CABECALHO."\n".implode("\n", $linhas)."\n";
        app(MatriculaImportService::class)->importar(UploadedFile::fake()->createWithContent('matricula.csv', $csv));
    }

    // 2026/1: matriculada em Medicina. 2026/2: Medicina (08/07) transferida em 05/08; Odontologia ativa desde 05/08.
    private const MED_2026_1 = '67482,Sofia,ATIVA,05/01/2026,2026/1,Medicina,1,';

    private const MED_2026_2_TRANSFERIDA = '67482,Sofia,TRANSFERIDA,08/07/2026,2026/2,Medicina,2,05/08/2026';

    private const ODO_2026_2_ATIVA = '67482,Sofia,ATIVA,05/08/2026,2026/2,Odontologia,2,';

    private function aluna(): Aluno
    {
        return Aluno::where('ra', '67482')->firstOrFail();
    }

    private function avaliacao(string $nome, ?string $data, ?int $categoriaId = null): Avaliacao
    {
        $avaliacao = Avaliacao::create(['nome' => $nome, 'data_avaliacao' => $data, 'categoria_id' => $categoriaId]);
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
        return \DB::table('resultado_resumos')->where('avaliacao_codigo', $avaliacao->codigo)->value('curso');
    }

    private function coordenador(string $curso): Admin
    {
        $coordenador = Admin::create(['username' => 'coord-'.$curso, 'password_hash' => bcrypt('x'), 'role' => Admin::ROLE_COORDENADOR]);
        $coordenador->sincronizarCursos([$curso]);

        return $coordenador;
    }

    public function test_as_duas_matriculas_ficam_no_historico_e_a_ativa_e_a_atual_em_qualquer_ordem(): void
    {
        $this->importar(self::MED_2026_2_TRANSFERIDA, self::ODO_2026_2_ATIVA);
        $this->assertSame('ODONTOLOGIA', $this->aluna()->curso);
        $this->assertSame('ATIVA', $this->aluna()->status);

        // Ordem inversa no arquivo, mesmo resultado (antes a última linha vencia).
        \DB::table('alunos')->delete();
        $this->importar(self::ODO_2026_2_ATIVA, self::MED_2026_2_TRANSFERIDA);
        $this->assertSame('ODONTOLOGIA', $this->aluna()->curso);
        $this->assertSame('ATIVA', $this->aluna()->status);

        $historico = AlunoMatricula::where('aluno_id', $this->aluna()->id)->orderBy('curso')->get();
        $this->assertSame(['MEDICINA', 'ODONTOLOGIA'], $historico->pluck('curso')->all());

        $medicina = $historico[0];
        $this->assertSame('TRANSFERIDA', $medicina->status);
        $this->assertSame('2026-07-08', $medicina->data_inicio->format('Y-m-d'));
        $this->assertSame('2026-08-05', $medicina->data_fim->format('Y-m-d'));
        $this->assertSame('2026-08-05', $historico[1]->data_inicio->format('Y-m-d'));
        $this->assertNull($historico[1]->data_fim);
    }

    public function test_importar_planilha_antiga_nao_desfaz_o_curso_atual_e_completa_o_historico(): void
    {
        $this->importar(self::MED_2026_2_TRANSFERIDA, self::ODO_2026_2_ATIVA);
        $this->importar(self::MED_2026_1);   // planilha de 2026/1 importada DEPOIS

        $this->assertSame('ODONTOLOGIA', $this->aluna()->curso, 'a matrícula atual não volta pra Medicina');
        $this->assertSame('2026/2', $this->aluna()->periodo_letivo);
        $this->assertSame(3, AlunoMatricula::where('aluno_id', $this->aluna()->id)->count());

        // Reimportar a mesma planilha não duplica matrícula.
        $this->importar(self::MED_2026_1);
        $this->assertSame(3, AlunoMatricula::where('aluno_id', $this->aluna()->id)->count());
    }

    public function test_cada_prova_fica_no_curso_em_que_a_aluna_estava_na_data_dela(): void
    {
        $this->importar(self::MED_2026_1, self::MED_2026_2_TRANSFERIDA, self::ODO_2026_2_ATIVA);

        $marco = $this->avaliacao('Março', '2026-03-16');            // Medicina (2026/1)
        $julho = $this->avaliacao('Julho', '2026-07-20');            // Medicina (08/07 → 05/08)
        $dia = $this->avaliacao('Dia da transferência', '2026-08-05'); // Odontologia (começa em 05/08)
        $agosto = $this->avaliacao('Agosto', '2026-08-20');          // Odontologia
        $semData = $this->avaliacao('Sem data', null);               // sem pista: curso atual

        $this->assertSame('MEDICINA', $this->cursoDoResultado($marco));
        $this->assertSame('MEDICINA', $this->cursoDoResultado($julho));
        $this->assertSame('ODONTOLOGIA', $this->cursoDoResultado($dia));
        $this->assertSame('ODONTOLOGIA', $this->cursoDoResultado($agosto));
        $this->assertSame('ODONTOLOGIA', $this->cursoDoResultado($semData));

        // E cada avaliação só "pertence" ao curso do resultado — sem resíduo do outro.
        $this->assertSame(['MEDICINA'], $marco->cursos());
        $this->assertSame(['ODONTOLOGIA'], $agosto->cursos());
        $this->assertSame(['ODONTOLOGIA'], $semData->cursos());
    }

    public function test_importar_a_planilha_antiga_depois_corrige_resultados_ja_gravados(): void
    {
        // Só a planilha de 2026/2 foi importada: a prova de março ainda não tem como saber que era de Medicina.
        $this->importar(self::MED_2026_2_TRANSFERIDA, self::ODO_2026_2_ATIVA);
        $marco = $this->avaliacao('Março', '2026-03-16');
        $this->assertSame('ODONTOLOGIA', $this->cursoDoResultado($marco));
        $this->assertSame(['ODONTOLOGIA'], $marco->cursos());

        // Chega a planilha de 2026/1: o resultado e os cursos da avaliação são corrigidos sozinhos.
        $this->importar(self::MED_2026_1);

        $this->assertSame('MEDICINA', $this->cursoDoResultado($marco));
        $this->assertSame(['MEDICINA'], $marco->fresh()->cursos());
    }

    public function test_coordenadores_veem_so_as_provas_do_proprio_curso_da_aluna(): void
    {
        $this->importar(self::MED_2026_1, self::MED_2026_2_TRANSFERIDA, self::ODO_2026_2_ATIVA);
        $categoria = Categoria::create(['nome' => 'DI']);
        $provaMedicina = $this->avaliacao('Prova de Medicina', '2026-03-16', $categoria->id);
        $provaOdonto = $this->avaliacao('Prova de Odontologia', '2026-08-20', $categoria->id);

        $medicina = $this->coordenador('MEDICINA');
        $odonto = $this->coordenador('ODONTOLOGIA');

        // A avaliação de Odontologia NÃO aparece pro coordenador de Medicina (e vice-versa).
        $this->actingAs($medicina, 'admin')->get('/avaliacoes')->assertSee('Prova de Medicina')->assertDontSee('Prova de Odontologia');
        $this->actingAs($medicina, 'admin')->get("/avaliacoes/{$provaOdonto->codigo}/bi")->assertNotFound();
        $this->actingAs($odonto, 'admin')->get('/avaliacoes')->assertSee('Prova de Odontologia')->assertDontSee('Prova de Medicina');
        $this->actingAs($odonto, 'admin')->get("/avaliacoes/{$provaMedicina->codigo}/bi")->assertNotFound();

        // Na lista do BI de Medicina ela aparece com o curso DA ÉPOCA, não o atual.
        $this->actingAs($medicina, 'admin')->get("/avaliacoes/{$provaMedicina->codigo}/bi")
            ->assertOk()
            ->assertSee('Sofia')
            ->assertViewHas('rankingCompleto', fn ($lista) => count($lista) === 1 && $lista[0]['curso'] === 'MEDICINA');

        // Dashboard: cada coordenador conta a aluna só nas provas do seu curso.
        $painelMed = app(CoordenadorDashboardService::class)->gerar($medicina, '', '');
        $painelOdo = app(CoordenadorDashboardService::class)->gerar($odonto, '', '');
        $this->assertSame(1, $painelMed['geral']['inscritos']);
        $this->assertSame(1, $painelOdo['geral']['inscritos']);
        $this->assertSame(['Prova de Medicina'], array_column($painelMed['categorias'][0]['avaliacoes'], 'nome'));
        $this->assertSame(['Prova de Odontologia'], array_column($painelOdo['categorias'][0]['avaliacoes'], 'nome'));
    }

    public function test_aluno_cancelado_ou_trancado_continua_no_curso_nas_provas_de_antes(): void
    {
        $this->importar('67482,Sofia,ATIVA,05/01/2026,2026/1,Medicina,1,', '67482,Sofia,CANCELADA,05/01/2026,2026/2,Medicina,2,15/09/2026');
        $antes = $this->avaliacao('Antes', '2026-03-16');
        $depois = $this->avaliacao('Depois do cancelamento', '2026-10-20');

        $this->assertSame('MEDICINA', $this->cursoDoResultado($antes));
        // Sem nenhuma matrícula válida na data (já cancelada), cai no curso atual/último — que segue sendo o dela.
        $this->assertSame('MEDICINA', $this->cursoDoResultado($depois));
        $this->assertSame('CANCELADA', $this->aluna()->status);
    }

    public function test_dois_cursos_ativos_ao_mesmo_tempo_o_curso_marcado_na_avaliacao_desempata(): void
    {
        $this->importar(
            '67482,Sofia,ATIVA,05/01/2026,2026/1,Medicina,1,',
            '67482,Sofia,ATIVA,05/02/2026,2026/1,Direito,1,',
        );
        $provaDireito = $this->avaliacao('Prova de Direito', '2026-03-16');
        $provaDireito->sincronizarCursos(['DIREITO']);      // marcado à mão (manual)
        app(ResumoResultadoService::class)->recalcular($provaDireito->codigo);

        $outra = $this->avaliacao('Prova qualquer', '2026-03-17');

        $this->assertSame('DIREITO', $this->cursoDoResultado($provaDireito), 'o curso marcado à mão na avaliação desempata');
        // Sem marca manual, vence a de início mais recente (aqui, Direito: 05/02).
        $this->assertSame('DIREITO', $this->cursoDoResultado($outra));
        $this->assertSame(['DIREITO'], $provaDireito->cursos());
    }

    public function test_curso_automatico_obsoleto_sai_mas_o_manual_fica(): void
    {
        $this->importar(self::ODO_2026_2_ATIVA);
        $agosto = $this->avaliacao('Agosto', '2026-08-20');

        // Resíduo de quando a aluna ainda era de Medicina + uma marca manual.
        \DB::table('avaliacao_cursos')->insert([
            ['avaliacao_codigo' => $agosto->codigo, 'curso' => 'MEDICINA', 'origem' => 'auto'],
            ['avaliacao_codigo' => $agosto->codigo, 'curso' => 'ENFERMAGEM', 'origem' => 'manual'],
        ]);

        (new AvaliacaoCursoService)->sincronizarDosRespondentes($agosto->codigo);

        $this->assertSame(['ENFERMAGEM', 'ODONTOLOGIA'], $agosto->cursos());
    }

    public function test_numero_de_linha_da_planilha_nunca_e_gravado(): void
    {
        $csv = "Linha,RA,Nome,Status,Dt. Ativação,Per. Letivo,Curso,Período,Dt. Ocorrência\n4339,67482,Sofia,ATIVA,05/08/2026,2026/2,Odontologia,2,\n";
        app(MatriculaImportService::class)->importar(UploadedFile::fake()->createWithContent('m.csv', $csv));

        $this->assertSame('67482', $this->aluna()->ra);
        $this->assertStringNotContainsString('4339', json_encode(AlunoMatricula::first()->toArray()));
        $this->assertStringNotContainsString('4339', json_encode($this->aluna()->toArray()));
    }

    public function test_so_a_coluna_dt_ativacao_define_o_inicio_da_matricula(): void
    {
        // Outras colunas de data parecidas (ingresso, status) não podem ser lidas como início.
        $csv = "RA,Nome,Dt. Status,Dt. Matrícula,Dt. Ativação,Per. Letivo,Curso,Período\n"
            ."67482,Sofia,09/09/2020,07/11/2025,05/08/2026,2026/2,Odontologia,2\n";
        app(MatriculaImportService::class)->importar(UploadedFile::fake()->createWithContent('m.csv', $csv));

        $this->assertSame('2026-08-05', AlunoMatricula::firstOrFail()->data_inicio->format('Y-m-d'));
    }

    public function test_dry_run_nao_grava_historico(): void
    {
        $csv = self::CABECALHO."\n".self::ODO_2026_2_ATIVA."\n";
        app(MatriculaImportService::class)->importar(UploadedFile::fake()->createWithContent('m.csv', $csv), true);

        $this->assertSame(0, AlunoMatricula::count());
        $this->assertSame(0, Aluno::count());
    }
}
