<?php

namespace App\Support;

use App\Models\ConfiguracaoSistema;

/**
 * O que a instituição considera um "estudante em risco" — uma definição só, usada pela lista de alunos em atenção do
 * coordenador e pelo painel da reitoria (que conta, em agregado, os mesmos estudantes).
 *
 * Dois critérios, cada um pode estar desligado (null):
 *
 *  - ACERTO: a MÉDIA de acerto do estudante nas provas que fez (só as provas em que esteve presente) fica abaixo de
 *    `acerto` %. Cada avaliação pode trazer o limite dela (`avaliacoes.risco_acerto`); com limites diferentes, compara-se
 *    a média do estudante com a média dos limites das provas que ele fez (com um limite só, é "média < limite").
 *  - FALTAS: o estudante faltou a `faltas` ou mais aplicações (prova inteira em branco). Uma avaliação pode dispensar a
 *    falta (`avaliacoes.risco_ignora_falta`), por exemplo uma prova opcional.
 *
 * `operador` diz como combinar os critérios LIGADOS: 'ou' = basta um deles; 'e' = precisa dos dois.
 * (A queda de nota entre provas da lista do coordenador é um sinal à parte, não faz parte desta regra.)
 *
 * O padrão da instituição vive em `configuracoes_sistema` (risco_acerto, risco_faltas, risco_operador; vazio = critério
 * desligado). Sem nada gravado vale PADRAO_ACERTO / PADRAO_FALTAS / 'ou', que reproduz o que o painel fazia antes.
 */
final class RegraDeRisco
{
    public const PADRAO_ACERTO = 60.0;

    public const PADRAO_FALTAS = 2;

    public const OPERADORES = ['ou' => 'Basta um dos critérios', 'e' => 'Todos os critérios marcados'];

    public function __construct(
        public readonly ?float $acerto,
        public readonly ?int $faltas,
        public readonly string $operador = 'ou',
    ) {}

    public static function padrao(): self
    {
        return new self(self::PADRAO_ACERTO, self::PADRAO_FALTAS, 'ou');
    }

    /** A regra gravada pela administração (ou o padrão, se ainda não houver nada). */
    public static function atual(): self
    {
        $acerto = ConfiguracaoSistema::valor('risco_acerto', (string) self::PADRAO_ACERTO);
        $faltas = ConfiguracaoSistema::valor('risco_faltas', (string) self::PADRAO_FALTAS);
        $operador = ConfiguracaoSistema::valor('risco_operador', 'ou');

        return new self(
            is_numeric($acerto) && (float) $acerto > 0 ? (float) $acerto : null,
            is_numeric($faltas) && (int) $faltas > 0 ? (int) $faltas : null,
            $operador === 'e' ? 'e' : 'ou',
        );
    }

    public static function gravar(?float $acerto, ?int $faltas, string $operador): void
    {
        ConfiguracaoSistema::definir('risco_acerto', $acerto === null ? '' : (string) $acerto);
        ConfiguracaoSistema::definir('risco_faltas', $faltas === null ? '' : (string) $faltas);
        ConfiguracaoSistema::definir('risco_operador', $operador === 'e' ? 'e' : 'ou');
    }

    public function algumCriterio(): bool
    {
        return $this->acerto !== null || $this->faltas !== null;
    }

    /**
     * Limite de acerto que vale numa avaliação: o dela, se tiver (0 = esta prova não entra no critério de acerto), senão
     * o padrão. null = a prova não conta para o acerto.
     */
    public function limiteDaAvaliacao(?float $proprio): ?float
    {
        if ($proprio === null) {
            return $this->acerto;
        }

        return $proprio > 0 ? $proprio : null;
    }

    /** Combina o resultado de cada critério ligado conforme o operador. Sem critério ligado, ninguém está em risco. */
    public function combinar(array $criterios): bool
    {
        if ($criterios === []) {
            return false;
        }

        return $this->operador === 'e' ? ! in_array(false, $criterios, true) : in_array(true, $criterios, true);
    }

    /** Frase curta para as telas: "média de acerto abaixo de 60% ou 2 faltas ou mais". */
    public function descricao(): string
    {
        $partes = [];
        if ($this->acerto !== null) {
            $partes[] = 'média de acerto abaixo de '.self::numero($this->acerto).'%';
        }
        if ($this->faltas !== null) {
            $partes[] = $this->faltas.' '.($this->faltas === 1 ? 'falta' : 'faltas').($this->faltas > 1 ? ' ou mais' : '');
        }
        if ($partes === []) {
            return 'nenhum critério definido';
        }

        return implode($this->operador === 'e' ? ' e ' : ' ou ', $partes);
    }

    /** @return array{acerto: ?float, faltas: ?int, operador: string} (entra na chave do cache dos agregados) */
    public function assinatura(): array
    {
        return ['acerto' => $this->acerto, 'faltas' => $this->faltas, 'operador' => $this->operador];
    }

    public static function numero(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }
}
