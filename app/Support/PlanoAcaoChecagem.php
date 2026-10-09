<?php

namespace App\Support;

use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;

/**
 * Conferência de um plano de ação: o que está EM BRANCO (lacunas) e o que merece uma segunda olhada (alertas — o teste de
 * coerência do roteiro: "se a ação for executada, ela enfrenta a causa-raiz e pode contribuir para a meta?").
 *
 * NENHUMA etapa do plano é obrigatória: lacunas e alertas só informam — o coordenador os vê na síntese antes de enviar e o
 * colaborador ao analisar, mas nada impede salvar nem enviar. Só olha o que dá para conferir por máquina (campo vazio, verbo, prazo, data da próxima avaliação); a qualidade do raciocínio
 * é do colaborador.
 */
final class PlanoAcaoChecagem
{
    /** Tamanho mínimo de um texto para contar como "preenchido" (uma palavra solta não é leitura nem causa). */
    private const MINIMO = 5;

    /**
     * @return array<int, array{etapa: int, mensagem: string}> o que está em branco, por etapa do roteiro (1 a 4); não impede o envio
     */
    public static function lacunas(PlanoAcao $plano): array
    {
        $faltas = [];
        $falta = function (int $etapa, string $mensagem) use (&$faltas): void {
            $faltas[] = ['etapa' => $etapa, 'mensagem' => $mensagem];
        };
        $vazio = fn (?string $texto) => mb_strlen(trim((string) $texto)) < self::MINIMO;

        if ($plano->meta_proficiencia === null) {
            $falta(1, 'Defina a meta de proficiência para a próxima avaliação.');
        }

        foreach ([
            'recorte' => 'Diga qual recorte merece atenção (período, grupo, faixa de desempenho).',
            'resultado' => 'Descreva o resultado que precisa ser enfrentado.',
            'fragilidades' => 'Registre as competências, objetivos de aprendizagem ou níveis cognitivos com fragilidade.',
            'evidencias' => 'Registre os dados que sustentam a escolha.',
        ] as $campo => $mensagem) {
            if ($vazio($plano->{$campo})) {
                $falta(2, $mensagem);
            }
        }

        if (collect($plano->causas ?? [])->filter(fn ($t) => ! $vazio($t))->isEmpty()) {
            $falta(3, 'Levante ao menos uma possível causa (Ishikawa).');
        }
        if ($vazio($plano->causa_priorizada)) {
            $falta(3, 'Indique a causa a priorizar.');
        }
        if ($plano->pontuacao() === null) {
            $falta(3, 'Dê as três notas da priorização (impacto, evidência e governabilidade).');
        }
        if ($vazio($plano->causa_raiz)) {
            $falta(3, 'Escreva a causa-raiz acionável.');
        }

        $acoes = $plano->acoes->reject(fn (PlanoAcaoAcao $a) => $a->status === PlanoAcaoAcao::CANCELADA)->values();
        if ($acoes->isEmpty()) {
            $falta(4, 'Inclua ao menos uma ação pedagógica.');
        }
        foreach ($acoes as $i => $acao) {
            $n = $i + 1;
            if ($vazio($acao->descricao) || ! self::comecaComVerbo((string) $acao->descricao)) {
                $falta(4, "Ação {$n}: inicie com um verbo no infinitivo (ex.: implementar, revisar, aplicar).");
            }
            if ($vazio($acao->execucao)) {
                $falta(4, "Ação {$n}: explique como ela será executada.");
            }
            if (trim((string) $acao->responsavel) === '') {
                $falta(4, "Ação {$n}: informe o responsável.");
            }
            if ($acao->prazo === null) {
                $falta(4, "Ação {$n}: informe o prazo.");
            } elseif ($acao->prazo->isBefore(today())) {
                $falta(4, "Ação {$n}: o prazo já passou — informe uma data a partir de hoje.");
            }
            if ($vazio($acao->verificacao)) {
                $falta(4, "Ação {$n}: diga como a execução e os sinais de aprendizagem serão verificados antes da próxima avaliação.");
            }
        }

        return $faltas;
    }

    /**
     * Pontos que merecem uma segunda olhada (também não impedem nada).
     *
     * @return array<int, string>
     */
    public static function alertas(PlanoAcao $plano): array
    {
        $alertas = [];
        $acoes = $plano->acoes->reject(fn (PlanoAcaoAcao $a) => $a->status === PlanoAcaoAcao::CANCELADA)->values();

        if ($plano->data_proxima_avaliacao === null) {
            $alertas[] = 'A data da próxima avaliação não foi informada: não dá para conferir se as ações cabem no ciclo.';
        } else {
            $fora = $acoes->filter(fn (PlanoAcaoAcao $a) => $a->prazo !== null && $a->prazo->isAfter($plano->data_proxima_avaliacao))->count();
            if ($fora > 0) {
                $alertas[] = ($fora === 1 ? '1 ação tem' : "{$fora} ações têm").' prazo depois da próxima avaliação ('.$plano->data_proxima_avaliacao->format('d/m/Y').'): o plano precisa produzir efeito ainda neste ciclo.';
            }
        }

        if ($acoes->isNotEmpty() && $acoes->every(fn (PlanoAcaoAcao $a) => preg_match('/\b(reuni[aã]o|reunir|reunir-se|conversar|alinhar)\b/iu', (string) $a->descricao) === 1)) {
            $alertas[] = 'Todas as ações são de articulação (reunião, alinhamento): inclua ao menos uma que mude a experiência de aprendizagem dos estudantes.';
        }

        if (collect($plano->porques ?? [])->filter(fn ($t) => trim((string) $t) !== '')->isEmpty() && trim((string) $plano->causa_raiz) !== '') {
            $alertas[] = 'Não há o aprofundamento da causa (5 Porquês): confirme que a causa-raiz está sustentada por evidências.';
        }

        if (($pontos = $plano->pontuacao()) !== null && $pontos < 6) {
            $alertas[] = "A causa priorizada tem pontuação baixa ({$pontos} de 27): vale confirmar com o NDE se é mesmo a mais promissora.";
        }

        return $alertas;
    }

    /** A primeira palavra é um verbo no infinitivo (termina em -ar, -er, -ir ou -or)? Verificação simples, sem dicionário. */
    public static function comecaComVerbo(string $texto): bool
    {
        if (preg_match('/^[^\p{L}]*(\p{L}+)/u', $texto, $m) !== 1) {
            return false;
        }

        return mb_strlen($m[1]) >= 3 && preg_match('/(ar|er|ir|or|ôr)$/iu', $m[1]) === 1;
    }
}
