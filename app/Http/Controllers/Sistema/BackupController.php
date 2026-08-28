<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Jobs\GerarBackupJob;
use App\Models\ConfiguracaoSistema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    // Nenhum backup mais recente que isso é considerado desatualizado —
    // um alerta na tela, já que não há agendador rodando sistema:backup
    // sozinho (ver bootstrap/app.php).
    private const DIAS_PARA_DESATUALIZADO = 7;

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

        $ultimoBackupEm = $backups->first()['data'] ?? null;
        $limite = now()->subDays(self::DIAS_PARA_DESATUALIZADO)->timestamp;

        return view('admin.sistema.backups', [
            'backups' => $backups,
            'backupStatus' => ConfiguracaoSistema::valor('backup_status', 'concluido'),
            'backupErro' => ConfiguracaoSistema::valor('backup_erro'),
            'backupIniciadoEm' => ConfiguracaoSistema::valor('backup_iniciado_em'),
            'backupDesatualizado' => $ultimoBackupEm === null || $ultimoBackupEm < $limite,
        ]);
    }

    public function store(): RedirectResponse
    {
        // Em produção (fila assíncrona) o job roda fora desta requisição — se
        // ele falhar, GerarBackupJob::failed() já registra o erro. Este
        // try/catch cobre o caso de QUEUE_CONNECTION=sync (ex.: testes) ou de
        // uma falha ao simplesmente enfileirar o job.
        try {
            GerarBackupJob::dispatch();
        } catch (Throwable $e) {
            Log::error('Falha ao solicitar backup.', ['exception' => $e]);

            return redirect()->route('sistema.backups.index')
                ->withErrors(['backup' => 'Não foi possível gerar o backup: '.$e->getMessage()]);
        }

        return redirect()->route('sistema.backups.index')
            ->with('status', 'Backup solicitado — está sendo gerado em segundo plano.');
    }

    public function download(string $nome): BinaryFileResponse
    {
        abort_unless(preg_match('/^backup-[\d_-]+\.zip$/', $nome) === 1, 404);

        $caminho = storage_path('app/backups/'.$nome);

        abort_unless(File::exists($caminho), 404);

        return response()->download($caminho);
    }
}
