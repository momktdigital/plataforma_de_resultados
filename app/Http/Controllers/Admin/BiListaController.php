<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Services\ListaAlunosExportService;
use App\Services\RelatorioAdminService;
use App\Services\Visualizacoes\VisualizacaoConfigService;
use App\Support\AtividadeLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Baixa em .xlsx a lista nominal de alunos do painel BI. Mesmas regras do BI:
 * o coordenador só baixa avaliação que pode abrir e só com os alunos dos
 * cursos dele, e o visual precisa estar habilitado para a avaliação (quem
 * desligou a lista nominal na configuração de visualizações não a expõe por
 * esta rota).
 */
class BiListaController extends Controller
{
    public function xlsx(
        Request $request,
        Avaliacao $avaliacao,
        RelatorioAdminService $relatorioService,
        VisualizacaoConfigService $visualizacaoConfig,
        ListaAlunosExportService $exportService,
    ): StreamedResponse {
        $usuario = Auth::guard('admin')->user();
        abort_unless($avaliacao->acessivelPara($usuario), 404);
        abort_unless($visualizacaoConfig->estadoCompleto($avaliacao)['ranking_completo']['visivelAdmin'], 404);

        $periodo = trim((string) $request->query('periodo', ''));
        $relatorioService = $relatorioService->paraCursos($usuario->ehCoordenador() ? $usuario->cursos() : null);

        $alunos = $relatorioService->rankingCompleto($avaliacao, $periodo);

        AtividadeLogger::registrar('avaliacao.lista_alunos_exportada', 'Avaliacao', $avaliacao->codigo, array_filter([
            'periodo' => $periodo !== '' ? $periodo : null,
            'linhas' => count($alunos),
            'escopo_cursos' => $usuario->ehCoordenador() ? $usuario->cursos() : null,
        ]));

        $writer = new Xlsx($exportService->planilha($avaliacao, $alunos));

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            "alunos-avaliacao-{$avaliacao->codigo}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
