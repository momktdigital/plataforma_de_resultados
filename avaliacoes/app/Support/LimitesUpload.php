<?php

namespace App\Support;

/**
 * O limite de upload de um formulário não é só a regra `max:` da validação
 * — o PHP recusa a requisição antes mesmo dela chegar ao Laravel se
 * `post_max_size`/`upload_max_filesize` (php.ini) forem menores. Isso lê os
 * dois e devolve o menor, para a interface avisar o admin *antes* dele
 * escolher um arquivo grande demais, em vez de só falhar depois com um 413.
 */
class LimitesUpload
{
    public static function limiteEfetivoEmBytes(): int
    {
        return min(
            self::paraBytes(ini_get('post_max_size') ?: '0'),
            self::paraBytes(ini_get('upload_max_filesize') ?: '0'),
        );
    }

    public static function limiteEfetivoEmKb(): int
    {
        return intdiv(self::limiteEfetivoEmBytes(), 1024);
    }

    public static function paraBytes(string $valorIni): int
    {
        $valorIni = trim($valorIni);

        if ($valorIni === '' || $valorIni === '-1') {
            return PHP_INT_MAX;
        }

        $unidade = strtolower(substr($valorIni, -1));
        $numero = (float) $valorIni;

        return (int) match ($unidade) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }
}
