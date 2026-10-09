<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um acontecimento na vida de um plano (log só de acrescentar: nunca se edita nem se apaga). `admin_id` nulo = o próprio
 * sistema (lembretes). `dados` guarda o que o texto não carrega: de/para de um prazo, os critérios marcados numa decisão.
 */
class PlanoAcaoEvento extends Model
{
    protected $table = 'plano_acao_eventos';

    public const UPDATED_AT = null;

    public const CRIADO = 'criado';

    public const ENVIADO = 'enviado';

    public const REENVIADO = 'reenviado';

    public const RETIRADO = 'retirado';

    public const APROVADO = 'aprovado';

    public const AJUSTES = 'ajustes_solicitados';

    public const RECUSADO = 'recusado';

    public const COMENTARIO = 'comentario';

    public const ANDAMENTO = 'andamento';

    public const ACAO_STATUS = 'acao_status';

    public const PRAZO = 'prazo_reprogramado';

    public const ENCERRADO = 'encerrado';

    public const CANCELADO = 'cancelado';

    /** @var array<string, array{rotulo: string, icone: string}> */
    public const TIPOS = [
        self::CRIADO => ['rotulo' => 'Plano criado', 'icone' => 'ph-file-plus'],
        self::ENVIADO => ['rotulo' => 'Enviado para análise', 'icone' => 'ph-paper-plane-tilt'],
        self::REENVIADO => ['rotulo' => 'Reenviado para análise', 'icone' => 'ph-paper-plane-tilt'],
        self::RETIRADO => ['rotulo' => 'Retirado da análise', 'icone' => 'ph-arrow-u-up-left'],
        self::APROVADO => ['rotulo' => 'Aprovado', 'icone' => 'ph-check-circle'],
        self::AJUSTES => ['rotulo' => 'Ajustes solicitados', 'icone' => 'ph-pencil-line'],
        self::RECUSADO => ['rotulo' => 'Recusado', 'icone' => 'ph-x-circle'],
        self::COMENTARIO => ['rotulo' => 'Comentário', 'icone' => 'ph-chat-text'],
        self::ANDAMENTO => ['rotulo' => 'Andamento da ação', 'icone' => 'ph-note-pencil'],
        self::ACAO_STATUS => ['rotulo' => 'Situação da ação', 'icone' => 'ph-list-checks'],
        self::PRAZO => ['rotulo' => 'Prazo reprogramado', 'icone' => 'ph-calendar-dots'],
        self::ENCERRADO => ['rotulo' => 'Plano encerrado', 'icone' => 'ph-flag-checkered'],
        self::CANCELADO => ['rotulo' => 'Plano cancelado', 'icone' => 'ph-prohibit'],
    ];

    protected $fillable = ['plano_id', 'acao_id', 'admin_id', 'tipo', 'texto', 'dados'];

    protected function casts(): array
    {
        return ['dados' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<PlanoAcao, $this> */
    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoAcao::class, 'plano_id');
    }

    /** @return BelongsTo<Admin, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function rotulo(): string
    {
        return self::TIPOS[$this->tipo]['rotulo'] ?? $this->tipo;
    }

    public function icone(): string
    {
        return self::TIPOS[$this->tipo]['icone'] ?? 'ph-circle';
    }
}
