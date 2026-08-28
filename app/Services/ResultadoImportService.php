<?php

namespace App\Services;

use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Support\HeaderResolver;
use App\Support\ImportResult;
use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Import de resultados no formato "longo": uma linha por resposta de um
 * respondente a uma questão. Únicos campos obrigatórios: CPF ou RA, Questão
 * e Resposta (a resposta pode ser vazia — significa que o aluno deixou em
 * branco — mas a coluna precisa existir). Período é opcional: só existe para
 * diferenciar tentativas do mesmo aluno na mesma avaliação em períodos diferentes.
 */
class ResultadoImportService
{
    private const RA_PATTERNS = ['/^(ra|matricula|matriculaaluno)$/'];

    private const CPF_PATTERNS = ['/^cpf$/'];

    private const NUMERO_PATTERNS = ['/^(questao|numero|item|q)$/', '/^quest/', '/^num/'];

    private const RESPOSTA_PATTERNS = ['/^(resposta|alternativa|letra|marcada)$/'];

    private const PERIODO_PATTERNS = ['/^(periodo|periodo letivo|perletivo)$/'];

    /**
     * Tamanho dos lotes do upsert() — grande o bastante para poucas idas ao
     * banco num import de 100 mil+ linhas, pequeno o bastante para não
     * arriscar o max_allowed_packet do MySQL numa única instrução.
     */
    private const TAMANHO_LOTE = 1000;

    public function importar(Avaliacao $avaliacao, UploadedFile $file, bool $dryRun = false): ImportResult
    {
        $rows = SpreadsheetReader::readRows($file);
        $resultado = new ImportResult;

        if (empty($rows)) {
            return $resultado;
        }

        $header = array_keys($rows[0]);
        $this->validarCabecalho($header);

        $linhas = $this->normalizarLinhas($rows, $resultado);

        if ($linhas === []) {
            return $resultado;
        }

        $alunoIds = $this->resolverAlunoIds($linhas);
        $chavesExistentes = $this->buscarChavesExistentes($avaliacao->codigo);

        DB::beginTransaction();

        try {
            $registros = $this->montarRegistros($avaliacao, $linhas, $alunoIds);
            $vistas = [];

            foreach (array_chunk($registros, self::TAMANHO_LOTE, true) as $lote) {
                $this->salvarLote($lote, $chavesExistentes, $vistas, $resultado);
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // dry-run: os contadores/linhas-ignoradas em $resultado refletem
        // exatamente o que teria acontecido — só que nada fica gravado.
        $dryRun ? DB::rollBack() : DB::commit();

        return $resultado;
    }

    /**
     * @param  array<int, string>  $header
     */
    private function validarCabecalho(array $header): void
    {
        $temIdentificador = HeaderResolver::hasColumn($header, self::RA_PATTERNS)
            || HeaderResolver::hasColumn($header, self::CPF_PATTERNS);

        if (! $temIdentificador) {
            throw new RuntimeException('O arquivo precisa ter uma coluna de CPF ou de RA.');
        }

        if (! HeaderResolver::hasColumn($header, self::NUMERO_PATTERNS)) {
            throw new RuntimeException('O arquivo precisa ter uma coluna de Questão.');
        }

        if (! HeaderResolver::hasColumn($header, self::RESPOSTA_PATTERNS)) {
            throw new RuntimeException('O arquivo precisa ter uma coluna de Resposta.');
        }
    }

    /**
     * Valida e normaliza cada linha da planilha, sem tocar o banco — as
     * linhas inválidas já são registradas como ignoradas aqui.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{linha: int, ra: ?string, cpf: ?string, numero: int, resposta: ?string, periodo: string}>
     */
    private function normalizarLinhas(array $rows, ImportResult $resultado): array
    {
        $linhas = [];

        foreach ($rows as $index => $row) {
            $resultado->registrarLinha();
            $linha = $index + 2;

            $ra = HeaderResolver::findValue($row, self::RA_PATTERNS);
            $cpf = HeaderResolver::findValue($row, self::CPF_PATTERNS);
            $numeroBruto = HeaderResolver::findValue($row, self::NUMERO_PATTERNS);
            $resposta = HeaderResolver::findValue($row, self::RESPOSTA_PATTERNS);
            $periodo = HeaderResolver::findValue($row, self::PERIODO_PATTERNS) ?? '';

            if ($ra === null && $cpf === null) {
                $resultado->ignorarLinha($linha, 'CPF e RA ausentes — ao menos um é obrigatório.');

                continue;
            }

            $cpfLimpo = $cpf !== null ? preg_replace('/\D/', '', $cpf) : null;

            // Igual AlunoRequest/ConsultaResultadoRequest (digits:11) — um CPF
            // malformado aqui não é só descartado com fallback pro RA: como
            // respostas.aluno_chave prioriza CPF (COALESCE(cpf, ra)), gravá-lo
            // do jeito que veio criaria um agrupamento que nunca casa com
            // nenhum aluno real, mesmo com um RA válido na mesma linha.
            if ($cpf !== null && strlen($cpfLimpo) !== 11) {
                $resultado->ignorarLinha($linha, "CPF inválido: '{$cpf}' — precisa ter 11 dígitos.");

                continue;
            }

            if ($numeroBruto === null || ! preg_match('/\d+/', $numeroBruto, $matches)) {
                $resultado->ignorarLinha($linha, 'Coluna de Questão ausente ou sem número.');

                continue;
            }

            $linhas[] = [
                'linha' => $linha,
                'ra' => $ra !== null ? trim($ra) : null,
                'cpf' => $cpfLimpo,
                'numero' => (int) $matches[0],
                'resposta' => $resposta !== null ? mb_strtoupper($resposta, 'UTF-8') : null,
                'periodo' => $periodo,
            ];
        }

        return $linhas;
    }

    /**
     * Resolve todos os aluno_id de uma vez (duas consultas indexadas, em vez
     * de uma por linha) — mesmo critério do lookup original (CPF OU RA),
     * mantendo o menor id em caso de ambiguidade entre dois alunos diferentes.
     *
     * @param  array<int, array{ra: ?string, cpf: ?string}>  $linhas
     * @return array{porCpf: array<string, int>, porRa: array<string, int>}
     */
    private function resolverAlunoIds(array $linhas): array
    {
        $cpfs = array_values(array_unique(array_filter(array_column($linhas, 'cpf'))));
        $ras = array_values(array_unique(array_filter(array_column($linhas, 'ra'))));

        if ($cpfs === [] && $ras === []) {
            return ['porCpf' => [], 'porRa' => []];
        }

        $alunos = Aluno::query()
            ->when($cpfs, fn ($query) => $query->orWhereIn('cpf', $cpfs))
            ->when($ras, fn ($query) => $query->orWhereIn('ra', $ras))
            ->orderBy('id')
            ->get(['id', 'cpf', 'ra']);

        $porCpf = [];
        $porRa = [];

        foreach ($alunos as $aluno) {
            if ($aluno->cpf !== null && ! isset($porCpf[$aluno->cpf])) {
                $porCpf[$aluno->cpf] = $aluno->id;
            }
            if ($aluno->ra !== null && ! isset($porRa[$aluno->ra])) {
                $porRa[$aluno->ra] = $aluno->id;
            }
        }

        return ['porCpf' => $porCpf, 'porRa' => $porRa];
    }

    /**
     * Chaves (periodo|questao_numero|aluno_chave) das respostas já existentes
     * para a avaliação — inclui as soft-deletadas, já que elas também ocupam
     * o índice único e por isso contam como "restauração", não "criação".
     * Uma única leitura, em vez de um SELECT por linha do arquivo.
     *
     * @return array<string, true>
     */
    private function buscarChavesExistentes(int $avaliacaoCodigo): array
    {
        return DB::table('respostas')
            ->where('avaliacao_codigo', $avaliacaoCodigo)
            ->select('periodo', 'questao_numero', DB::raw('COALESCE(cpf, ra) as identificador'))
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->periodo}|{$r->questao_numero}|{$r->identificador}" => true])
            ->all();
    }

    /**
     * Monta os registros a upsertar — segue a mesma ordem das linhas do
     * arquivo, então uma chave repetida dentro do próprio arquivo mantém só
     * a última ocorrência (mesmo comportamento de salvar linha a linha).
     * Cada registro carrega 'linha' (número da linha original, pra atribuir
     * o erro corretamente se essa chave falhar ao salvar) — removido antes
     * de virar a linha do upsert.
     *
     * @param  array<int, array{linha: int, ra: ?string, cpf: ?string, numero: int, resposta: ?string, periodo: string}>  $linhas
     * @param  array{porCpf: array<string, int>, porRa: array<string, int>}  $alunoIds
     * @return array<string, array{linha: int, dados: array<string, mixed>}>
     */
    private function montarRegistros(Avaliacao $avaliacao, array $linhas, array $alunoIds): array
    {
        $registros = [];

        foreach ($linhas as $linha) {
            $alunoChave = $linha['cpf'] ?? $linha['ra'];
            $chave = "{$linha['periodo']}|{$linha['numero']}|{$alunoChave}";

            $candidatos = array_filter([
                $linha['cpf'] !== null ? ($alunoIds['porCpf'][$linha['cpf']] ?? null) : null,
                $linha['ra'] !== null ? ($alunoIds['porRa'][$linha['ra']] ?? null) : null,
            ], fn ($id) => $id !== null);

            $registros[$chave] = [
                'linha' => $linha['linha'],
                'dados' => [
                    'avaliacao_codigo' => $avaliacao->codigo,
                    'questao_numero' => $linha['numero'],
                    'periodo' => $linha['periodo'],
                    'ra' => $linha['ra'],
                    'cpf' => $linha['cpf'],
                    'resposta' => $linha['resposta'],
                    'aluno_id' => $candidatos === [] ? null : min($candidatos),
                    'deleted_at' => null,
                ],
            ];
        }

        return $registros;
    }

    /**
     * Salva um lote inteiro num único upsert() (caminho rápido — o normal).
     * Se o lote falhar (ex.: MySQL em modo estrito rejeitando um valor que
     * não cabe numa coluna varchar estreita), reprocessa o MESMO lote linha a
     * linha, isolando só a(s) linha(s) problemática(s) via
     * ImportResult::ignorarLinha() em vez de derrubar o import inteiro —
     * mesma ideia que MatriculaImportService já aplica.
     *
     * @param  array<string, array{linha: int, dados: array<string, mixed>}>  $lote
     * @param  array<string, true>  $chavesExistentes
     * @param  array<string, true>  $vistas
     */
    private function salvarLote(array $lote, array $chavesExistentes, array &$vistas, ImportResult $resultado): void
    {
        $agora = now();

        try {
            DB::table('respostas')->upsert(
                array_map(fn (array $r) => $r['dados'] + ['created_at' => $agora, 'updated_at' => $agora], array_values($lote)),
                ['avaliacao_codigo', 'aluno_chave', 'periodo', 'questao_numero'],
                ['resposta', 'aluno_id', 'deleted_at', 'updated_at']
            );

            foreach ($lote as $chave => $registro) {
                $this->registrarSucesso($chave, $chavesExistentes, $vistas, $resultado);
            }

            return;
        } catch (Throwable) {
            // Segue pro fallback linha a linha abaixo.
        }

        foreach ($lote as $chave => $registro) {
            try {
                DB::table('respostas')->upsert(
                    [$registro['dados'] + ['created_at' => $agora, 'updated_at' => $agora]],
                    ['avaliacao_codigo', 'aluno_chave', 'periodo', 'questao_numero'],
                    ['resposta', 'aluno_id', 'deleted_at', 'updated_at']
                );

                $this->registrarSucesso($chave, $chavesExistentes, $vistas, $resultado);
            } catch (Throwable $e) {
                $resultado->ignorarLinha($registro['linha'], 'Falha ao salvar: '.$e->getMessage());
            }
        }
    }

    /** @param  array<string, true>  $chavesExistentes @param  array<string, true>  $vistas */
    private function registrarSucesso(string $chave, array $chavesExistentes, array &$vistas, ImportResult $resultado): void
    {
        if (isset($chavesExistentes[$chave]) || isset($vistas[$chave])) {
            $resultado->registrarAtualizada();
        } else {
            $resultado->registrarCriada();
        }

        $vistas[$chave] = true;
    }
}
