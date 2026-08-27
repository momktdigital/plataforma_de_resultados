<?php

namespace App\Support;

use App\Models\Aluno;
use Illuminate\Support\Carbon;

/**
 * Filtro opcional por turma/sexo/cor-raça/faixa etária, aplicado sobre os
 * respondentes de uma avaliação (nunca sobre `alunos` como um todo). Cada
 * visual do painel BI decide quais desses campos fazem sentido pra ele —
 * ex.: "distribuição por turma" já agrupa por turma, então usa semTurma()
 * pra não filtrar pela própria dimensão que está exibindo.
 */
final class FiltroDemografico
{
    public function __construct(
        public readonly ?string $turma = null,
        public readonly ?string $sexo = null,
        public readonly ?string $corRaca = null,
        public readonly ?string $faixaEtaria = null,
    ) {}

    /** @param array<string, mixed> $dados normalmente request()->query() */
    public static function deQueryString(array $dados): self
    {
        $limpar = fn (?string $v) => $v !== null && $v !== '' ? $v : null;

        return new self(
            turma: $limpar($dados['turma'] ?? null),
            sexo: $limpar($dados['sexo'] ?? null),
            corRaca: $limpar($dados['cor_raca'] ?? null),
            faixaEtaria: $limpar($dados['faixa_etaria'] ?? null),
        );
    }

    public function vazio(): bool
    {
        return $this->turma === null && $this->sexo === null && $this->corRaca === null && $this->faixaEtaria === null;
    }

    /** Mesmo filtro, mas ignorando turma — pra quando turma já é a própria dimensão de agrupamento do visual. */
    public function semTurma(): self
    {
        return new self(turma: null, sexo: $this->sexo, corRaca: $this->corRaca, faixaEtaria: $this->faixaEtaria);
    }

    public function combina(Aluno $aluno, Carbon $dataReferencia): bool
    {
        if ($this->turma !== null && $aluno->turma !== $this->turma) {
            return false;
        }
        if ($this->sexo !== null && $aluno->sexo !== $this->sexo) {
            return false;
        }
        if ($this->corRaca !== null && $aluno->cor_raca !== $this->corRaca) {
            return false;
        }
        if ($this->faixaEtaria !== null && self::faixaEtariaDoAluno($aluno, $dataReferencia) !== $this->faixaEtaria) {
            return false;
        }

        return true;
    }

    /** @return array<int, string> rótulos das faixas etárias, em ordem — usado tanto pro <select> quanto pra classificar cada aluno. */
    public static function faixasEtarias(): array
    {
        return ['Até 19', '20 a 24', '25 a 29', '30 a 39', '40 ou mais'];
    }

    /**
     * Idade calculada na data da avaliação (não "hoje") — faz diferença em
     * análises de coorte ao longo de vários anos de avaliações.
     */
    public static function faixaEtariaDoAluno(Aluno $aluno, Carbon $dataReferencia): ?string
    {
        if ($aluno->data_nascimento === null) {
            return null;
        }

        $idade = $aluno->data_nascimento->diffInYears($dataReferencia);

        return match (true) {
            $idade < 20 => 'Até 19',
            $idade < 25 => '20 a 24',
            $idade < 30 => '25 a 29',
            $idade < 40 => '30 a 39',
            default => '40 ou mais',
        };
    }
}
