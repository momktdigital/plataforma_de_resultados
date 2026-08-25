<?php

namespace App\Support;

/**
 * Resultado de um import em lote: quantos registros foram processados e,
 * para cada linha ignorada, o motivo — nunca falha silenciosamente.
 */
class ImportResult
{
    /** @var array<int, array{linha: int, motivo: string}> */
    private array $ignoradas = [];

    private int $totalLinhas = 0;

    private int $criadas = 0;

    private int $atualizadas = 0;

    public function registrarLinha(): void
    {
        $this->totalLinhas++;
    }

    public function registrarCriada(): void
    {
        $this->criadas++;
    }

    public function registrarAtualizada(): void
    {
        $this->atualizadas++;
    }

    public function ignorarLinha(int $linha, string $motivo): void
    {
        $this->ignoradas[] = ['linha' => $linha, 'motivo' => $motivo];
    }

    public function totalLinhas(): int
    {
        return $this->totalLinhas;
    }

    public function criadas(): int
    {
        return $this->criadas;
    }

    public function atualizadas(): int
    {
        return $this->atualizadas;
    }

    /** @return array<int, array{linha: int, motivo: string}> */
    public function ignoradas(): array
    {
        return $this->ignoradas;
    }

    public function totalIgnoradas(): int
    {
        return count($this->ignoradas);
    }

    public function resumo(): string
    {
        $partes = [
            "{$this->criadas} criada(s)",
            "{$this->atualizadas} atualizada(s)",
        ];

        if ($this->totalIgnoradas() > 0) {
            $partes[] = "{$this->totalIgnoradas()} ignorada(s)";
        }

        return implode(', ', $partes)." — de {$this->totalLinhas} linha(s) no arquivo.";
    }
}
