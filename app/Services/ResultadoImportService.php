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

    public function importar(Avaliacao $avaliacao, UploadedFile $file): ImportResult
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

        DB::transaction(function () use ($avaliacao, $linhas, $alunoIds, $chavesExistentes, $resultado) {
            $registros = $this->montarRegistros($avaliacao, $linhas, $alunoIds, $chavesExistentes, $resultado);

            $agora = now();
            foreach (array_chunk(array_values($registros), self::TAMANHO_LOTE) as $lote) {
                DB::table('respostas')->upsert(
                    array_map(fn (array $r) => $r + ['created_at' => $agora, 'updated_at' => $agora], $lote),
                    ['avaliacao_codigo', 'aluno_chave', 'periodo', 'questao_numero'],
                    ['resposta', 'aluno_id', 'deleted_at', 'updated_at']
                );
            }
        });

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
     * @return array<int, array{ra: ?string, cpf: ?string, numero: int, resposta: ?string, periodo: string}>
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

            if ($numeroBruto === null || ! preg_match('/\d+/', $numeroBruto, $matches)) {
                $resultado->ignorarLinha($linha, 'Coluna de Questão ausente ou sem número.');

                continue;
            }

            $linhas[] = [
                'ra' => $ra !== null ? trim($ra) : null,
                'cpf' => $cpf !== null ? preg_replace('/\D/', '', $cpf) : null,
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
     * Monta os registros a upsertar e contabiliza criada(s)/atualizada(s) —
     * seguindo a mesma ordem das linhas do arquivo, então uma chave repetida
     * dentro do próprio arquivo conta como "criada" na primeira ocorrência e
     * "atualizada" nas seguintes (e só a última vale no upsert, igual ao
     * comportamento anterior de salvar linha a linha).
     *
     * @param  array<int, array{ra: ?string, cpf: ?string, numero: int, resposta: ?string, periodo: string}>  $linhas
     * @param  array{porCpf: array<string, int>, porRa: array<string, int>}  $alunoIds
     * @param  array<string, true>  $chavesExistentes
     * @return array<string, array<string, mixed>>
     */
    private function montarRegistros(
        Avaliacao $avaliacao,
        array $linhas,
        array $alunoIds,
        array $chavesExistentes,
        ImportResult $resultado,
    ): array {
        $registros = [];
        $vistas = [];

        foreach ($linhas as $linha) {
            $alunoChave = $linha['cpf'] ?? $linha['ra'];
            $chave = "{$linha['periodo']}|{$linha['numero']}|{$alunoChave}";

            if (isset($chavesExistentes[$chave]) || isset($vistas[$chave])) {
                $resultado->registrarAtualizada();
            } else {
                $resultado->registrarCriada();
            }
            $vistas[$chave] = true;

            $candidatos = array_filter([
                $linha['cpf'] !== null ? ($alunoIds['porCpf'][$linha['cpf']] ?? null) : null,
                $linha['ra'] !== null ? ($alunoIds['porRa'][$linha['ra']] ?? null) : null,
            ], fn ($id) => $id !== null);

            $registros[$chave] = [
                'avaliacao_codigo' => $avaliacao->codigo,
                'questao_numero' => $linha['numero'],
                'periodo' => $linha['periodo'],
                'ra' => $linha['ra'],
                'cpf' => $linha['cpf'],
                'resposta' => $linha['resposta'],
                'aluno_id' => $candidatos === [] ? null : min($candidatos),
                'deleted_at' => null,
            ];
        }

        return $registros;
    }
}
