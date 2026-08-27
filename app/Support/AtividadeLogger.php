<?php

namespace App\Support;

use App\Models\Atividade;
use Illuminate\Support\Facades\Auth;

/**
 * Registra "quem fez o quê e quando" pras ações que mexem em nota (editar
 * gabarito, anular questão, excluir/restaurar período, rodar um import) ou
 * em conta de administrador — a única fonte de auditoria do sistema além de
 * Avaliacao.criado_por. Numa plataforma de nota, uma anulação ou edição de
 * gabarito sem essa trilha é uma lacuna real na hora de resolver uma disputa.
 *
 * Captura o admin autenticado automaticamente — quando chamado fora de um
 * request autenticado (ex.: comando de CLI como `legado:importar`), o
 * registro fica sem admin_id, com admin_username identificando a origem.
 */
class AtividadeLogger
{
    /**
     * @param  array<string, mixed>  $detalhes
     * @param  ?string  $origemSemAuth  Rótulo pro admin_username quando não há
     *                                  admin autenticado (ex.: comando de CLI,
     *                                  fluxo de "esqueci minha senha"). Sem
     *                                  isso, cai no genérico "sistema".
     */
    public static function registrar(
        string $acao,
        ?string $alvoTipo = null,
        string|int|null $alvoId = null,
        array $detalhes = [],
        ?string $origemSemAuth = null,
    ): void {
        $admin = Auth::guard('admin')->user();

        self::criar($admin?->id, $admin?->username ?? $origemSemAuth ?? 'sistema', $acao, $alvoTipo, $alvoId, $detalhes);
    }

    /**
     * Pra ações concluídas fora do request que as originou — um job de fila,
     * por exemplo, roda num worker sem sessão autenticada nenhuma, então quem
     * dispara o job precisa capturar o admin atual (Auth::guard('admin'))
     * antes de enfileirar e repassar aqui, em vez de registrar() tentar (e
     * falhar) descobrir isso sozinho dentro do job.
     *
     * @param  array<string, mixed>  $detalhes
     */
    public static function registrarComoAdmin(
        ?int $adminId,
        ?string $adminUsername,
        string $acao,
        ?string $alvoTipo = null,
        string|int|null $alvoId = null,
        array $detalhes = [],
    ): void {
        self::criar($adminId, $adminUsername ?? 'sistema', $acao, $alvoTipo, $alvoId, $detalhes);
    }

    /** @param  array<string, mixed>  $detalhes */
    private static function criar(
        ?int $adminId,
        string $adminUsername,
        string $acao,
        ?string $alvoTipo,
        string|int|null $alvoId,
        array $detalhes,
    ): void {
        Atividade::create([
            'admin_id' => $adminId,
            'admin_username' => $adminUsername,
            'acao' => $acao,
            'alvo_tipo' => $alvoTipo,
            'alvo_id' => $alvoId !== null ? (string) $alvoId : null,
            'detalhes' => $detalhes === [] ? null : $detalhes,
        ]);
    }
}
