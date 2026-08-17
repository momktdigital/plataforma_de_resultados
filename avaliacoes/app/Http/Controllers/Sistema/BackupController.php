<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function index(): View
    {
        $pasta = storage_path('app/backups');
        File::ensureDirectoryExists($pasta);

        $backups = collect(File::files($pasta))
            ->sortByDesc(fn ($arquivo) => $arquivo->getMTime())
            ->map(fn ($arquivo) => [
                'nome' => $arquivo->getFilename(),
                'tamanho' => $arquivo->getSize(),
                'data' => $arquivo->getMTime(),
            ])
            ->values();

        return view('admin.sistema.backups', ['backups' => $backups]);
    }

    public function store(BackupService $service): RedirectResponse
    {
        $caminho = $service->gerar();

        return redirect()->route('sistema.backups.index')
            ->with('status', 'Backup gerado: '.basename($caminho));
    }

    public function download(string $nome): BinaryFileResponse
    {
        abort_unless(preg_match('/^backup-[\d_-]+\.zip$/', $nome) === 1, 404);

        $caminho = storage_path('app/backups/'.$nome);

        abort_unless(File::exists($caminho), 404);

        return response()->download($caminho);
    }
}
