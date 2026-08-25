<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Questao;
use App\Services\QuestaoImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class QuestaoExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['username' => 'coordenador', 'password_hash' => bcrypt('x')]);
    }

    private function questaoCompleta(Avaliacao $avaliacao): Questao
    {
        $csv = "Questão,Gabarito,Área,Tema,Habilidade,Matriz Prova A,Matriz Prova B,DCN A,PPC A,PPC B,Matriz (período),Matriz (disciplina),Matriz (código)\n"
            .'1,B,"Clínica Médica","HIV/AIDS","E3 — Avaliação","Item 1","Item 2","Art. 5º","P1","P2","1;2","Anatomia;Fisiologia","AN01;FI02"'."\n";
        $arquivo = UploadedFile::fake()->createWithContent('gabarito.csv', $csv);
        app(QuestaoImportService::class)->importar($avaliacao, $arquivo);

        return Questao::where('numero', 1)->firstOrFail();
    }

    public function test_exporta_xlsx_com_todas_as_colunas(): void
    {
        $avaliacao = Avaliacao::create([]);
        $this->questaoCompleta($avaliacao);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('avaliacoes.questoes.export.xlsx', $avaliacao));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $temporario = tempnam(sys_get_temp_dir(), 'xlsx_export_');
        file_put_contents($temporario, $response->streamedContent());

        $sheet = IOFactory::load($temporario)->getActiveSheet();
        @unlink($temporario);

        $this->assertSame('Questão', $sheet->getCell('A1')->getValue());
        $this->assertSame('Matriz Prova A', $sheet->getCell('N1')->getValue());
        $this->assertSame(1, $sheet->getCell('A2')->getValue());
        $this->assertSame('Clínica Médica', $sheet->getCell('C2')->getValue());
        $this->assertSame('Item 1', $sheet->getCell('N2')->getValue());
        $this->assertSame('Item 2', $sheet->getCell('O2')->getValue());
    }

    public function test_planilha_exportada_e_reimportavel_sem_perder_dados(): void
    {
        $avaliacao = Avaliacao::create([]);
        $this->questaoCompleta($avaliacao);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('avaliacoes.questoes.export.xlsx', $avaliacao));
        $conteudo = $response->streamedContent();

        $novaAvaliacao = Avaliacao::create([]);
        $arquivo = UploadedFile::fake()->createWithContent('export.xlsx', $conteudo);
        app(QuestaoImportService::class)->importar($novaAvaliacao, $arquivo);

        $reimportada = Questao::where('avaliacao_codigo', $novaAvaliacao->codigo)->where('numero', 1)->firstOrFail();
        $this->assertSame('B', $reimportada->gabarito);
        $this->assertSame('Clínica Médica', $reimportada->area);
        $this->assertSame('HIV/AIDS', $reimportada->tema);
        $this->assertSame(
            ['Item 1', 'Item 2'],
            $reimportada->referencias()->where('tipo', 'matriz_prova')->pluck('valor')->all(),
        );
        $this->assertCount(2, $reimportada->matrizes);
    }

    public function test_exporta_csv(): void
    {
        $avaliacao = Avaliacao::create([]);
        $this->questaoCompleta($avaliacao);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('avaliacoes.questoes.export.csv', $avaliacao));

        $response->assertOk();
        $conteudo = $response->streamedContent();
        $this->assertStringContainsString('"Questão","Gabarito"', $conteudo);
        $this->assertStringContainsString('Clínica Médica', $conteudo);
    }

    public function test_tela_de_impressao_pdf_mostra_todas_as_colunas(): void
    {
        $avaliacao = Avaliacao::create([]);
        $this->questaoCompleta($avaliacao);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('avaliacoes.questoes.export.pdf', $avaliacao));

        $response->assertOk();
        $response->assertSee('Clínica Médica');
        $response->assertSee('Item 1; Item 2');
        $response->assertSee('1 · Anatomia · AN01', false);
    }

    public function test_guest_nao_acessa_exportacao(): void
    {
        $this->admin();
        $avaliacao = Avaliacao::create([]);

        $this->get(route('avaliacoes.questoes.export.xlsx', $avaliacao))
            ->assertRedirect(route('login'));
    }
}
