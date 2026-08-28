<?php

namespace App\Support;

use App\Models\ConfiguracaoSistema;
use Throwable;

/**
 * Registra o estado de um import (resultados/questões/matrícula) disparado
 * como job de fila — mesma ideia de App\Services\Backup\BackupStatusTracker
 * (chave/valor em `configuracoes_sistema`, pra a tela mostrar o andamento sem
 * precisar de infraestrutura extra), mas parametrizado por tipo de import +
 * escopo (o código da avaliação, quando aplicável), já que — ao contrário do
 * backup, que é um recurso único — vários imports podem estar em andamento
 * ao mesmo tempo (avaliações diferentes).
 */
class ImportStatusTracker
{
    public static function iniciar(string $tipo, string $escopo = '', bool $dryRun = false): void
    {
        $prefixo = self::prefixo($tipo, $escopo);

        ConfiguracaoSistema::definir("{$prefixo}_status", 'processando');
        ConfiguracaoSistema::definir("{$prefixo}_erro", null);
        ConfiguracaoSistema::definir("{$prefixo}_resumo", null);
        ConfiguracaoSistema::definir("{$prefixo}_ignoradas", null);
        ConfiguracaoSistema::definir("{$prefixo}_iniciado_em", now()->toIso8601String());
        ConfiguracaoSistema::definir("{$prefixo}_dry_run", $dryRun ? '1' : '0');
    }

    public static function concluir(string $tipo, string $escopo, ImportResult $resultado): void
    {
        $prefixo = self::prefixo($tipo, $escopo);

        ConfiguracaoSistema::definir("{$prefixo}_status", 'concluido');
        ConfiguracaoSistema::definir("{$prefixo}_resumo", $resultado->resumo());
        ConfiguracaoSistema::definir("{$prefixo}_ignoradas", json_encode($resultado->ignoradas()));
    }

    public static function falhar(string $tipo, string $escopo, Throwable $e): void
    {
        $prefixo = self::prefixo($tipo, $escopo);

        ConfiguracaoSistema::definir("{$prefixo}_status", 'erro');
        ConfiguracaoSistema::definir("{$prefixo}_erro", $e->getMessage());
    }

    /** @return array{status: string, erro: ?string, resumo: ?string, ignoradas: array<int, array{linha: int, motivo: string}>, iniciadoEm: ?string, dryRun: bool} */
    public static function status(string $tipo, string $escopo = ''): array
    {
        $prefixo = self::prefixo($tipo, $escopo);

        return [
            'status' => ConfiguracaoSistema::valor("{$prefixo}_status", 'concluido'),
            'erro' => ConfiguracaoSistema::valor("{$prefixo}_erro"),
            'resumo' => ConfiguracaoSistema::valor("{$prefixo}_resumo"),
            'ignoradas' => json_decode(ConfiguracaoSistema::valor("{$prefixo}_ignoradas", '[]') ?? '[]', true) ?: [],
            'iniciadoEm' => ConfiguracaoSistema::valor("{$prefixo}_iniciado_em"),
            'dryRun' => ConfiguracaoSistema::valor("{$prefixo}_dry_run", '0') === '1',
        ];
    }

    private static function prefixo(string $tipo, string $escopo): string
    {
        return $escopo !== '' ? "import_{$tipo}_{$escopo}" : "import_{$tipo}";
    }
}
