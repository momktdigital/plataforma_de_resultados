<?php

namespace App\Services\Backup;

use App\Models\ConfiguracaoSistema;
use Throwable;

/**
 * Registra o estado da última geração de backup (disparada pelo botão, via
 * fila, ou pelo comando `sistema:backup` num agendamento) — chave/valor em
 * `configuracoes_sistema`, para a tela em admin/sistema/backups mostrar o
 * andamento sem precisar de nenhuma infraestrutura além da própria página.
 */
class BackupStatusTracker
{
    public static function iniciar(): void
    {
        ConfiguracaoSistema::definir('backup_status', 'processando');
        ConfiguracaoSistema::definir('backup_erro', null);
        ConfiguracaoSistema::definir('backup_iniciado_em', now()->toIso8601String());
    }

    public static function concluir(): void
    {
        ConfiguracaoSistema::definir('backup_status', 'concluido');
    }

    public static function falhar(Throwable $e): void
    {
        ConfiguracaoSistema::definir('backup_status', 'erro');
        ConfiguracaoSistema::definir('backup_erro', $e->getMessage());
    }
}
