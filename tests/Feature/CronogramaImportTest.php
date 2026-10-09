<?php

namespace Tests\Feature;

use App\Models\CronogramaItem;
use App\Models\CronogramaPendencia;
use App\Models\Curso;
use App\Services\CronogramaImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Importação da "Tabela-base da Auditoria ROC/ROD": colunas de curso mapeadas, situação por célula ("Não se aplica" = o curso
 * não entra; em branco = aguardando) e as pendências ligadas à atividade certa — sem duplicar ao repetir.
 */
class CronogramaImportTest extends TestCase
{
    use RefreshDatabase;

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['DIREITO', 'PSICOLOGIA', 'MEDICINA VETERINÁRIA NOTURNO'] as $curso) {
            Curso::create(['nome' => $curso]);
        }
        $this->arquivo = tempnam(sys_get_temp_dir(), 'cron').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
        parent::tearDown();
    }

    private function data(string $iso): int
    {
        return (int) Date::PHPToExcel(strtotime($iso));
    }

    private function planilha(): void
    {
        $livro = new Spreadsheet;
        $checklist = $livro->getActiveSheet()->setTitle('Checklist de Auditoria');
        $checklist->fromArray([
            ['Data', 'Rotina', 'Projeto/Atividade', 'O que vou conferir', 'Direito', 'Psicolgoia', 'Medicina Veterinária Noturno', 'Cursos EAD'],
            [$this->data('2026-08-14'), 'ROD', 'TIN ', 'Docentes postaram as avaliações do TIN ', 'Em acompanhamento', 'Resolvido', 'Não se aplica', 'Não se aplica'],
            [$this->data('2026-08-17'), 'ROC', 'A2 - Módulo A', 'Coordenação conferiu a A2', 'Pendente ', null, 'Não se aplica', 'Em acompanhamento'],
            [$this->data('2026-08-19'), 'Auditoria', 'Só EAD', 'Conferência final', 'Não se aplica', 'Não se aplica', 'Não se aplica', null],
            [$this->data('2026-09-01'), 'ROC', 'Ninguém', 'Nenhum curso se aplica', 'Não se aplica', 'Não se aplica', 'Não se aplica', 'Não se aplica'],
            [null, null, null, null, 'Não se aplica'],
        ]);
        $pendencias = $livro->createSheet()->setTitle('Registro de Pendências');
        $pendencias->fromArray([
            ['Data', 'Curso', 'Rotina', 'Processo', 'Pendência', 'Encaminhamento', 'Prazo', 'Status', 'Resposável'],
            [$this->data('2026-08-27'), 'Cursos EAD', 'ROC', 'A2 - Módulo A', '2 provas pendentes', 'Coordenação acionada', $this->data('2026-08-31'), 'Pendente ', 'Millena'],
            [$this->data('2026-09-30'), null, null, null, null, null, null, null, null],
        ]);
        (new Xlsx($livro))->save($this->arquivo);
    }

    private function servico(): CronogramaImportService
    {
        return app(CronogramaImportService::class);
    }

    public function test_analisa_o_mapa_de_colunas_a_situacao_de_cada_curso_e_as_pendencias(): void
    {
        $this->planilha();

        $plano = $this->servico()->analisar($this->arquivo, ['Cursos EAD' => ['Cursos EAD']]);

        $this->assertSame([], $plano['erros']);
        $this->assertSame('mesmo nome', $plano['colunas']['E']['como']);
        $this->assertSame(['PSICOLOGIA'], $plano['colunas']['F']['cursos'], 'o erro de digitação da planilha é reconhecido');
        $this->assertStringContainsString('parecido', $plano['colunas']['F']['como']);
        $this->assertSame(['MEDICINA VETERINÁRIA NOTURNO'], $plano['colunas']['G']['cursos'], 'acento e caixa não distinguem');

        $this->assertCount(3, $plano['itens'], 'ignora a linha vazia e a que nenhum curso aplica');
        [$tin, $a2, $ead] = $plano['itens'];
        $this->assertSame('TIN', $tin['projeto'], 'espaços sobrando são aparados');
        $this->assertSame('2026-08-14', $tin['data']);
        $this->assertSame(['DIREITO' => 'em_acompanhamento', 'PSICOLOGIA' => 'resolvido'], $tin['cursos'], '"Não se aplica" fica de fora');
        $this->assertSame(['DIREITO' => 'pendente', 'PSICOLOGIA' => 'aguardando', 'Cursos EAD' => 'em_acompanhamento'], $a2['cursos'], 'célula em branco = aguardando');
        $this->assertSame(['Cursos EAD' => 'aguardando'], $ead['cursos']);

        $this->assertCount(1, $plano['pendencias'], 'a linha da pendência sem texto é ignorada');
        $pendencia = $plano['pendencias'][0];
        $this->assertSame(1, $pendencia['item'], 'ligada à atividade ROC · A2 - Módulo A');
        $this->assertSame(['Cursos EAD', 'pendente', 'Millena', '2026-08-31'], [$pendencia['curso'], $pendencia['status'], $pendencia['responsavel'], $pendencia['prazo']]);
    }

    public function test_coluna_sem_curso_correspondente_exige_mapa_ou_ignorar(): void
    {
        $this->planilha();

        $plano = $this->servico()->analisar($this->arquivo);
        $this->assertCount(1, $plano['erros']);
        $this->assertStringContainsString('Cursos EAD', $plano['erros'][0]);
        $this->assertSame([], $plano['itens']);

        $ignorando = $this->servico()->analisar($this->arquivo, [], ['Cursos EAD']);
        $this->assertSame([], $ignorando['erros']);
        $this->assertCount(2, $ignorando['itens'], 'a atividade só do EAD some');
        $this->assertSame([], $ignorando['pendencias'], 'a pendência era do curso ignorado');
    }

    public function test_varias_colunas_para_o_mesmo_curso_valem_a_situacao_mais_urgente(): void
    {
        $this->planilha();

        $plano = $this->servico()->analisar($this->arquivo, ['Cursos EAD' => ['DIREITO'], 'Psicolgoia' => ['DIREITO']]);

        // TIN: Direito "em acompanhamento" + (Psicolgoia→Direito) "resolvido" → em acompanhamento
        $this->assertSame(['DIREITO' => 'em_acompanhamento'], $plano['itens'][0]['cursos']);
        // A2: Direito pendente + Psicolgoia (em branco) + EAD em acompanhamento → pendente
        $this->assertSame(['DIREITO' => 'pendente'], $plano['itens'][1]['cursos']);
    }

    public function test_grava_cria_os_cursos_pedidos_e_nao_duplica_ao_repetir(): void
    {
        $this->planilha();
        $plano = $this->servico()->analisar($this->arquivo, ['Cursos EAD' => ['Cursos EAD']]);

        $resumo = $this->servico()->gravar($plano, true, $this->arquivo);

        $this->assertSame(3, $resumo['itens_criados']);
        $this->assertSame(1, $resumo['pendencias_criadas']);
        $this->assertContains('Cursos EAD', Curso::nomesDisponiveis());
        $a2 = CronogramaItem::where('projeto', 'A2 - Módulo A')->with('cursos', 'pendencias')->firstOrFail();
        $this->assertSame(['Cursos EAD' => 'em_acompanhamento', 'DIREITO' => 'pendente', 'PSICOLOGIA' => 'aguardando'], $a2->cursos->pluck('status', 'curso')->all());
        $this->assertSame('2 provas pendentes', $a2->pendencias->first()->pendencia);
        $this->assertNull($a2->pendencias->first()->registrado_por);

        $segunda = $this->servico()->gravar($plano, true, $this->arquivo);

        $this->assertSame(['itens_criados' => 0, 'itens_existentes' => 3, 'pendencias_criadas' => 0, 'pendencias_existentes' => 1, 'pendencias_sem_atividade' => 0], $segunda);
        $this->assertSame(3, CronogramaItem::count());
        $this->assertSame(1, CronogramaPendencia::count());
    }

    public function test_repetir_nao_sobrescreve_o_que_o_colaborador_ja_alterou(): void
    {
        $this->planilha();
        $plano = $this->servico()->analisar($this->arquivo, ['Cursos EAD' => ['Cursos EAD']]);
        $this->servico()->gravar($plano, true, $this->arquivo);

        $tin = CronogramaItem::where('projeto', 'TIN')->firstOrFail();
        $tin->cursos()->where('curso', 'DIREITO')->update(['status' => 'resolvido']);

        $this->servico()->gravar($plano, true, $this->arquivo);

        $this->assertSame('resolvido', $tin->cursos()->where('curso', 'DIREITO')->value('status'));
    }

    public function test_comando_simula_por_padrao_e_so_grava_com_a_opcao(): void
    {
        $this->planilha();
        $argumentos = ['arquivo' => $this->arquivo, '--mapa' => ['Cursos EAD=Cursos EAD'], '--criar-cursos' => true];

        // Coluna que não casa com nenhum curso e sem mapa: recusa, sem gravar nada (antes de "Cursos EAD" existir como curso).
        $this->artisan('cronograma:importar', ['arquivo' => $this->arquivo, '--gravar' => true])->assertFailed();
        $this->artisan('cronograma:importar', ['arquivo' => $this->arquivo.'.nao-existe'])->assertFailed();
        $this->assertSame(0, CronogramaItem::count());

        $this->artisan('cronograma:importar', $argumentos)
            ->expectsOutputToContain('Atividades: 3')
            ->expectsOutputToContain('Simulação: nada foi gravado')
            ->assertSuccessful();
        $this->assertSame(0, CronogramaItem::count());

        $this->artisan('cronograma:importar', [...$argumentos, '--gravar' => true])->expectsOutputToContain('3 atividade(s) criada(s)')->assertSuccessful();
        $this->assertSame(3, CronogramaItem::count());
    }
}
