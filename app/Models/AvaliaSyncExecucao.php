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

    /**
     * Se o worker da fila morrer no meio de uma sincronização (kill do
     * processo, queda do container, falha que não chega a lançar uma
     * exceção PHP capturável) — o `catch` de AvaliaSyncService::sincronizar()
     * nunca roda, e a linha fica presa em 'processando' para sempre. Sem
     * isso, a tela de Integração ficava travada com o botão "Forçar
     * sincronização" desabilitado indefinidamente, sem nenhuma forma de
     * destravar pela interface.
     *
     * 32 minutos: um pouco acima do timeout do job
     * (SincronizarAvaliaJob::$timeout = 1800s = 30min) — depois disso não há
     * cenário legítimo em que a linha ainda esteja mesmo em andamento.
     * Chamado tanto ao abrir a tela quanto no início de uma nova
     * sincronização, para nunca depender só de uma visita à tela.
     */
    public static function marcarTravadasComoErro(int $minutos = 32): int
    {
        return static::where('status', self::STATUS_PROCESSANDO)
            ->where('iniciado_em', '<', now()->subMinutes($minutos))
            ->update([
                'status' => self::STATUS_ERRO,
                'concluido_em' => now(),
                'mensagem_erro' => 'Sincronização não finalizou dentro do tempo esperado — o processo provavelmente foi interrompido (worker da fila caiu, timeout ou queda de conexão). Verifique os logs do servidor (storage/logs/laravel.log) e tente novamente.',
            ]);
    }
}
