<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma execução de `avalia:sincronizar` (agendada a cada 12h ou disparada
 * manualmente pelo botão "Forçar sincronização" em
 * admin/sistema/integracao-avalia) — alimenta a tabela de logs daquela tela.
 * Ver App\Services\Avalia\AvaliaSyncService.
 */
class AvaliaSyncExecucao extends Model
{
    protected $table = 'avalia_sync_execucoes';

    public const STATUS_PROCESSANDO = 'processando';

    public const STATUS_SUCESSO = 'sucesso';

    public const STATUS_ERRO = 'erro';

    public const DISPARADO_AGENDADO = 'agendado';

    public const DISPARADO_MANUAL = 'manual';

    protected $fillable = [
        'produto',
        'status',
        'disparado_por',
        'admin_id',
        'iniciado_em',
        'concluido_em',
        'linhas_lidas',
        'linhas_gravadas',
        'linhas_sem_identificador',
        'mensagem_erro',
    ];

    protected function casts(): array
    {
        return [
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
