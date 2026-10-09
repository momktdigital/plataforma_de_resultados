<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidência anexada ao acompanhamento de um plano de ação (ver a migration `create_plano_acao_anexos_table`): um link ou um
 * arquivo guardado em disco privado. Só se acrescenta, como o histórico a que pertence.
 */
class PlanoAcaoAnexo extends Model
{
    protected $table = 'plano_acao_anexos';

    public const UPDATED_AT = null;

    public const LINK = 'link';

    public const ARQUIVO = 'arquivo';

    /** Disco e pasta dos arquivos (privados: só saem pela rota autenticada). */
    public const DISCO = 'local';

    public const PASTA = 'planos';

    /** Extensões aceitas e tamanho máximo (KB). */
    public const EXTENSOES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'txt', 'csv'];

    public const MAXIMO_KB = 10240;

    protected $fillable = ['plano_id', 'acao_id', 'evento_id', 'admin_id', 'tipo', 'titulo', 'url', 'caminho', 'nome_original', 'mime', 'tamanho'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
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

    public function ehArquivo(): bool
    {
        return $this->tipo === self::ARQUIVO;
    }

    /** "1,2 MB" / "340 KB" */
    public function tamanhoLegivel(): string
    {
        $bytes = (int) $this->tamanho;

        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.').' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
