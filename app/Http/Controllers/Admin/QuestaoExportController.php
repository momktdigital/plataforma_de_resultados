<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Services\QuestaoExportService;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuestaoExportController extends Controller
{
    public function xlsx(Avaliacao $avaliacao, QuestaoExportService $service): StreamedResponse
    {
        $writer = new Xlsx($service->planilha($avaliacao));

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            "questoes-avaliacao-{$avaliacao->codigo}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function csv(Avaliacao $avaliacao, QuestaoExportService $service): StreamedResponse
    {
        $writer = new Csv($service->planilha($avaliacao));

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            "questoes-avaliacao-{$avaliacao->codigo}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** Tela enxuta e sem o layout admin, pensada pra virar PDF via "Imprimir" do navegador. */
    public function pdf(Avaliacao $avaliacao): View
    {
        $questoes = $avaliacao->questoes()->orderBy('numero')->with(['matrizes', 'referencias'])->get();

        return view('admin.avaliacoes.questoes-imprimir', ['avaliacao' => $avaliacao, 'questoes' => $questoes]);
    }
}
