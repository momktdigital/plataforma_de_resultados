<?php

namespace App\Services;

use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Planilha dos planos de ação (uma aba de planos e uma de ações) para reunião do NDE, acompanhamento ou prestação de contas.
 * Só dado agregado e o texto do plano — nunca aluno.
 *
 * Todo texto é gravado como STRING explícita: causa-raiz, ação e responsável são digitados à mão e um valor começando em "="
 * seria avaliado como fórmula pelo Excel (injeção de fórmula).
 */
class PlanoAcaoExportService
{
    private const PLANOS = [
        'Nº', 'Curso', 'Período letivo', 'Categoria', 'Origem', 'Situação', 'Autor', 'Enviado em', 'Decidido em', 'Dias de análise',
        'Participação na criação (%)', 'Meta de participação (%)', 'Proficiência na criação (%)', 'Meta de proficiência (%)', 'Próxima avaliação',
        'Causa priorizada', 'Pontuação', 'Causa-raiz', 'Ações', 'Concluídas', 'Atrasadas', 'Encerrado em', 'Síntese do encerramento',
    ];

    private const ACOES = ['Plano nº', 'Curso', 'Origem do plano', 'Situação do plano', 'Ação', 'Como será executada', 'Responsável', 'Prazo', 'Situação da ação', 'Concluída em', 'Como será verificada'];

    /** @param Collection<int, PlanoAcao> $planos (com `acoes`, `autor`) */
    public function planilha(Collection $planos): Spreadsheet
    {
        $planilha = new Spreadsheet;
        $abaPlanos = $planilha->getActiveSheet();
        $abaPlanos->setTitle('Planos');

        $texto = fn ($aba, int $coluna, int $linha, mixed $valor) => $aba->getCell([$coluna, $linha])->setValueExplicit($valor === null ? '' : (string) $valor, DataType::TYPE_STRING);
        $numero = function ($aba, int $coluna, int $linha, mixed $valor): void {
            if ($valor !== null) {
                $aba->setCellValue([$coluna, $linha], $valor);
            }
        };

        foreach (self::PLANOS as $i => $titulo) {
            $texto($abaPlanos, $i + 1, 1, $titulo);
        }
        $abaPlanos->getStyle([1, 1, count(self::PLANOS), 1])->getFont()->setBold(true);

        $linha = 2;
        foreach ($planos as $p) {
            $progresso = $p->progresso();
            $c = 1;
            $numero($abaPlanos, $c++, $linha, $p->id);
            $texto($abaPlanos, $c++, $linha, $p->curso);
            $texto($abaPlanos, $c++, $linha, $p->periodo_letivo !== '' ? $p->periodo_letivo : 'Todos');
            $texto($abaPlanos, $c++, $linha, $p->contexto['categoria'] ?? '');
            $texto($abaPlanos, $c++, $linha, $p->origem_rotulo);
            $texto($abaPlanos, $c++, $linha, $p->rotuloStatus());
            $texto($abaPlanos, $c++, $linha, $p->autor?->username);
            $texto($abaPlanos, $c++, $linha, $p->enviado_em?->format('d/m/Y'));
            $texto($abaPlanos, $c++, $linha, $p->decidido_em?->format('d/m/Y'));
            $numero($abaPlanos, $c++, $linha, $p->enviado_em && $p->decidido_em ? (int) $p->enviado_em->diffInDays($p->decidido_em) : null);
            $numero($abaPlanos, $c++, $linha, $p->participacao_atual);
            $numero($abaPlanos, $c++, $linha, $p->meta_participacao);
            $numero($abaPlanos, $c++, $linha, $p->proficiencia_atual);
            $numero($abaPlanos, $c++, $linha, $p->meta_proficiencia);
            $texto($abaPlanos, $c++, $linha, $p->data_proxima_avaliacao?->format('d/m/Y'));
            $texto($abaPlanos, $c++, $linha, $p->causa_priorizada);
            $numero($abaPlanos, $c++, $linha, $p->pontuacao());
            $texto($abaPlanos, $c++, $linha, $p->causa_raiz);
            $numero($abaPlanos, $c++, $linha, $progresso['total']);
            $numero($abaPlanos, $c++, $linha, $progresso['concluidas']);
            $numero($abaPlanos, $c++, $linha, $progresso['atrasadas']);
            $texto($abaPlanos, $c++, $linha, $p->encerrado_em?->format('d/m/Y'));
            $texto($abaPlanos, $c++, $linha, $p->conclusao);
            $linha++;
        }

        $abaAcoes = $planilha->createSheet();
        $abaAcoes->setTitle('Ações');
        foreach (self::ACOES as $i => $titulo) {
            $texto($abaAcoes, $i + 1, 1, $titulo);
        }
        $abaAcoes->getStyle([1, 1, count(self::ACOES), 1])->getFont()->setBold(true);

        $linha = 2;
        foreach ($planos as $p) {
            foreach ($p->acoes as $a) {
                $c = 1;
                $numero($abaAcoes, $c++, $linha, $p->id);
                $texto($abaAcoes, $c++, $linha, $p->curso);
                $texto($abaAcoes, $c++, $linha, $p->origem_rotulo);
                $texto($abaAcoes, $c++, $linha, $p->rotuloStatus());
                $texto($abaAcoes, $c++, $linha, $a->descricao);
                $texto($abaAcoes, $c++, $linha, $a->execucao);
                $texto($abaAcoes, $c++, $linha, $a->responsavel);
                $texto($abaAcoes, $c++, $linha, $a->prazo?->format('d/m/Y'));
                $texto($abaAcoes, $c++, $linha, $a->estaAtrasada() ? $a->rotuloStatus().' (atrasada)' : $a->rotuloStatus());
                $texto($abaAcoes, $c++, $linha, $a->concluida_em?->format('d/m/Y'));
                $texto($abaAcoes, $c++, $linha, $a->verificacao);
                $linha++;
            }
        }

        foreach ([$abaPlanos, $abaAcoes] as $aba) {
            foreach (range(1, max(count(self::PLANOS), count(self::ACOES))) as $coluna) {
                $aba->getColumnDimensionByColumn($coluna)->setWidth(22);
            }
            $aba->freezePane('A2');
        }
        $planilha->setActiveSheetIndex(0);

        return $planilha;
    }
}
