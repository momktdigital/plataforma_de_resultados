<?php

namespace App\Console\Commands;

use App\Services\CronogramaImportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Carrega o cronograma de atividades a partir da planilha "Tabela-base da Auditoria ROC/ROD" (ver
 * App\Services\CronogramaImportService para as regras). Sem `--gravar` só mostra o que seria feito, então dá para conferir o
 * mapeamento das colunas de curso antes de mexer no banco. Pode ser repetido: o que já existe não é duplicado.
 */
class ImportarCronograma extends Command
{
    protected $signature = 'cronograma:importar
        {arquivo : Caminho da planilha .xlsx}
        {--mapa=* : Coluna da planilha => curso(s) do sistema, ex.: --mapa="Cursos EAD=PEDAGOGIA|NUTRIÇÃO" (separe vários cursos com |)}
        {--ignorar=* : Coluna da planilha a descartar}
        {--criar-cursos : Cadastra como curso os nomes de --mapa que ainda não existem}
        {--gravar : Grava de verdade (sem isto é só uma simulação)}';

    protected $description = 'Importa as atividades e as pendências do cronograma a partir da planilha da Auditoria ROC/ROD (simulação por padrão)';

    public function handle(CronogramaImportService $servico): int
    {
        $arquivo = (string) $this->argument('arquivo');
        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $mapa = [];
        foreach ((array) $this->option('mapa') as $par) {
            if (! str_contains($par, '=')) {
                $this->error("--mapa inválido: \"{$par}\" (use \"Coluna=CURSO\").");

                return self::FAILURE;
            }
            [$coluna, $cursos] = explode('=', $par, 2);
            $mapa[trim($coluna)] = array_values(array_filter(array_map('trim', explode('|', $cursos))));
        }

        try {
            $plano = $servico->analisar($arquivo, $mapa, array_map('trim', (array) $this->option('ignorar')));
        } catch (Throwable $e) {
            $this->error('Não consegui ler a planilha: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($plano['erros'] !== []) {
            foreach ($plano['erros'] as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        $this->line('<options=bold>Colunas de curso</>');
        foreach ($plano['colunas'] as $coluna) {
            $this->line(sprintf('  %-34s → %s  (%s)', $coluna['coluna'], $coluna['cursos'] === [] ? '—' : implode(' + ', $coluna['cursos']), $coluna['como']));
        }

        $this->newLine();
        $this->line(sprintf('<options=bold>Atividades:</> %d   <options=bold>Pendências:</> %d', count($plano['itens']), count($plano['pendencias'])));
        foreach ($plano['avisos'] as $aviso) {
            $this->warn($aviso);
        }

        if (! $this->option('gravar')) {
            $this->newLine();
            $this->info('Simulação: nada foi gravado. Confira o mapeamento acima e rode de novo com --gravar.');

            return self::SUCCESS;
        }

        $resumo = $servico->gravar($plano, (bool) $this->option('criar-cursos'), $arquivo);

        $this->newLine();
        $this->info(sprintf(
            'Gravado: %d atividade(s) criada(s) (%d já existiam) e %d pendência(s) criada(s) (%d já existiam).',
            $resumo['itens_criados'], $resumo['itens_existentes'], $resumo['pendencias_criadas'], $resumo['pendencias_existentes'],
        ));

        return self::SUCCESS;
    }
}
