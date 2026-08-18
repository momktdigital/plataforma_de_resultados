<?php

namespace App\Support;

/**
 * Casamento flexível de cabeçalhos de planilha: mesma ideia usada nos imports
 * legados (aceitar "Questão", "Questao", "Número", "Q", "#", etc. como a
 * mesma coluna), agora reaproveitada para os novos imports de questões e
 * resultados.
 */
class HeaderResolver
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        $value = str_replace('#', ' numero ', $value);
        $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
        $value = preg_replace('/\p{Mn}/u', '', $value) ?? $value;
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * Casamento para colunas do tipo "<rótulo> (campo A)" / "<rótulo> A", onde
     * o rótulo tem várias variações de escrita mas a letra do campo é o que
     * distingue A/B/C/D.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $labelTokens  Palavras (já normalizadas) que precisam aparecer no cabeçalho.
     */
    public static function findCampoValue(array $row, array $labelTokens, string $letra): ?string
    {
        $letra = self::normalize($letra);

        foreach ($row as $key => $value) {
            $normalizedKey = self::normalize((string) $key);

            foreach ($labelTokens as $token) {
                if (! str_contains($normalizedKey, $token)) {
                    continue 2;
                }
            }

            if (preg_match('/(?:^|\s)(?:campo\s+)?'.preg_quote($letra, '/').'$/', $normalizedKey) === 1) {
                $value = is_null($value) ? null : trim((string) $value);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $patterns  Expressões regulares (delimitadas com `/`) aplicadas ao cabeçalho normalizado.
     */
    public static function findValue(array $row, array $patterns): ?string
    {
        foreach ($row as $key => $value) {
            $normalizedKey = self::normalize((string) $key);

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalizedKey) === 1) {
                    $value = is_null($value) ? null : trim((string) $value);

                    return $value === '' ? null : $value;
                }
            }
        }

        return null;
    }

    public static function hasColumn(array $header, array $patterns): bool
    {
        foreach ($header as $columnName) {
            $normalizedKey = self::normalize((string) $columnName);

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalizedKey) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
