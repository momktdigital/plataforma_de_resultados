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
 * Import de resultados — aceita dois formatos de planilha:
 *
 * - "longo": uma linha por resposta de um respondente a uma questão. Únicos
 *   campos obrigatórios: CPF ou RA, Questão e Resposta (a resposta pode ser
 *   vazia — significa que o aluno deixou em branco — mas a coluna precisa
 *   existir).
 * - "largo": uma linha por respondente, uma coluna por questão (cabeçalho
 *   "Q1", "Q2", "Questão 3"...) — comum em exportações de leitora óptica.
 *   Cada coluna de questão vira, internamente, o mesmo registro que uma
 *   linha do formato longo produziria — ver normalizarLinhasLargo().
 *
 * Período é opcional em qualquer um dos dois formatos — quando ausente numa
 * linha, cai pro período cadastrado no perfil do aluno (ver
 * montarRegistros()); só fica vazio de verdade se nem a planilha nem o
 * cadastro do aluno tiverem essa informação.
 */
class ResultadoImportService
{
    private const FORMATO_LONGO = 'longo';

    private const FORMATO_LARGO = 'largo';

    private const RA_PATTERNS = ['/^(ra|matricula|matriculaaluno)$/'];

    private const CPF_PATTERNS = ['/^cpf$/'];

    private const NUMERO_PATTERNS = ['/^(questao|numero|item|q)$/', '/^quest/', '/^num/'];

    private const RESPOSTA_PATTERNS = ['/^(resposta|alternativa|letra|marcada)$/'];

    private const PERIODO_PATTERNS = ['/^(periodo|periodo letivo|perletivo)$/'];

    /**
     * Cabeçalho de coluna de questão no formato largo: "Q1", "Q 12", "Questão3",
     * "Item 7"... — precisa terminar em número, senão colide com as colunas de
     * identificação/Período (nenhuma delas termina em dígito).
     */
    private const COLUNA_QUESTAO_LARGO_PATTERN = '/^(?:q|questao|quest|item)\s*0*(\d+)$/';

    /** Sinônimos de "célula em branco" usados por algumas leitoras ópticas no formato largo. */
    private const RESPOSTA_LARGO_BRANCO = ['BLANK', 'BRANCO', 'EM BRANCO'];

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
        $formato = $this->detectarFormato($header);

        $linhas = $formato === self::FORMATO_LARGO
            ? $this->normalizarLinhasLargo($rows, $resultado, $this->colunasDeQuestao($header))
            : $this->normalizarLinhasLongo($rows, $resultado);

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
     * Lê só o cabeçalho do arquivo e detecta o formato + quais campos foram
     * reconhecidos — não toca o banco nem lê linha de dado nenhuma. Usado
     * pela pré-visualização exibida antes do usuário confirmar o import de
     * verdade (ver QuestaoImportService::identificarColunas(), mesmo padrão).
     *
     * @return array{formato: string, detalhe: ?string, campos: array<int, array{chave: string, rotulo: string, obrigatorio: bool, identificado: bool}>}
     */
    public function identificarColunas(UploadedFile $file): array
    {
        $header = SpreadsheetReader::readHeader($file);
        $formato = $this->detectarFormato($header);

        $campoIdentificador = [
            'chave' => 'identificador', 'rotulo' => 'RA ou CPF', 'obrigatorio' => true,
            'identificado' => HeaderResolver::hasColumn($header, self::RA_PATTERNS) || HeaderResolver::hasColumn($header, self::CPF_PATTERNS),
        ];
        $campoPeriodo = [
            'chave' => 'periodo', 'rotulo' => 'Período', 'obrigatorio' => false,
            'identificado' => HeaderResolver::hasColumn($header, self::PERIODO_PATTERNS),
        ];

        if ($formato === self::FORMATO_LARGO) {
            $numeros = array_keys($this->colunasDeQuestao($header));

            return [
                'formato' => self::FORMATO_LARGO,
                'detalhe' => count($numeros).' coluna(s) de questão identificada(s)'
                    .($numeros !== [] ? ' (Q'.min($numeros).' a Q'.max($numeros).').' : '.'),
                'campos' => [$campoIdentificador, $campoPeriodo],
            ];
        }

        return [
            'formato' => self::FORMATO_LONGO,
            'detalhe' => null,
            'campos' => [
                $campoIdentificador,
                ['chave' => 'questao', 'rotulo' => 'Questão', 'obrigatorio' => true, 'identificado' => HeaderResolver::hasColumn($header, self::NUMERO_PATTERNS)],
                ['chave' => 'resposta', 'rotulo' => 'Resposta', 'obrigatorio' => true, 'identificado' => HeaderResolver::hasColumn($header, self::RESPOSTA_PATTERNS)],
                $campoPeriodo,
            ],
        ];
    }

    /**
     * Formato "longo" exige colunas próprias de Questão E Resposta; na
     * ausência delas, formato "largo" exige ao menos uma coluna de questão
     * (cabeçalho terminado em número — ver colunasDeQuestao()). Uma coluna
     * de CPF ou RA é obrigatória nos dois formatos.
     *
     * @param  array<int, string>  $header
     */
    private function detectarFormato(array $header): string
    {
        $temIdentificador = HeaderResolver::hasColumn($header, self::RA_PATTERNS)
            || HeaderResolver::hasColumn($header, self::CPF_PATTERNS);

        if (! $temIdentificador) {
            throw new RuntimeException('O arquivo precisa ter uma coluna de CPF ou de RA.');
        }

        $formatoLongo = HeaderResolver::hasColumn($header, self::NUMERO_PATTERNS)
            && HeaderResolver::hasColumn($header, self::RESPOSTA_PATTERNS);

        if ($formatoLongo) {
            return self::FORMATO_LONGO;
        }

        if ($this->colunasDeQuestao($header) !== []) {
            return self::FORMATO_LARGO;
        }

        throw new RuntimeException(
            'Não foi possível identificar o formato do arquivo — ele precisa ter colunas de Questão e '
            .'Resposta (formato longo) ou uma coluna por questão, tipo "Q1", "Q2"... (formato largo).'
        );
    }

    /**
     * Colunas de questão do formato largo — cabeçalho terminado em número
     * ("Q1", "Questão 12"...) — na ordem crescente do número.
     *
     * @param  array<int, string>  $header
     * @return array<int, string> número da questão => nome original da coluna
     */
    private function colunasDeQuestao(array $header): array
    {
        $colunas = [];

        foreach ($header as $coluna) {
            $normalizado = HeaderResolver::normalize((string) $coluna);

            if (preg_match(self::COLUNA_QUESTAO_LARGO_PATTERN, $normalizado, $matches) === 1) {
                $colunas[(int) $matches[1]] = $coluna;
            }
        }

        ksort($colunas);

        return $colunas;
    }

    /**
     * RA/CPF de uma linha, comuns aos dois formatos — retorna null (já
     * registrando o motivo em $resultado) quando a linha não tem
     * identificador válido nenhum.
     *
     * @return array{ra: ?string, cpf: ?string}|null
     */
    private function resolverIdentificador(array $row, int $linha, ImportResult $resultado): ?array
    {
        $ra = HeaderResolver::findValue($row, self::RA_PATTERNS);
        $cpf = HeaderResolver::findValue($row, self::CPF_PATTERNS);

        if ($ra === null && $cpf === null) {
            $resultado->ignorarLinha($linha, 'CPF e RA ausentes — ao menos um é obrigatório.');

            return null;
        }

        $cpfLimpo = $cpf !== null ? preg_replace('/\D/', '', $cpf) : null;

        // Igual AlunoRequest/ConsultaResultadoRequest (digits:11) — um CPF
        // malformado aqui não é só descartado com fallback pro RA: como
        // respostas.aluno_chave prioriza CPF (COALESCE(cpf, ra)), gravá-lo
        // do jeito que veio criaria um agrupamento que nunca casa com
        // nenhum aluno real, mesmo com um RA válido na mesma linha.
        if ($cpf !== null && strlen($cpfLimpo) !== 11) {
            $resultado->ignorarLinha($linha, "CPF inválido: '{$cpf}' — precisa ter 11 dígitos.");

            return null;
        }

        return [
            'ra' => $ra !== null ? trim($ra) : null,
            'cpf' => $cpfLimpo,
        ];
    }

    /**
     * Valida e normaliza cada linha da planilha no formato longo, sem tocar
     * o banco — as linhas inválidas já são registradas como ignoradas aqui.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{linha: int, ra: ?string, cpf: ?string, numero: int, resposta: ?string, periodo: string}>
     */
    private function normalizarLinhasLongo(array $rows, ImportResult $resultado): array
    {
        $linhas = [];

        foreach ($rows as $index => $row) {
            $resultado->registrarLinha();
            $linha = $index + 2;

            $identificador = $this->resolverIdentificador($row, $linha, $resultado);
            if ($identificador === null) {
                continue;
            }

            $numeroBruto = HeaderResolver::findValue($row, self::NUMERO_PATTERNS);
            if ($numeroBruto === null || ! preg_match('/\d+/', $numeroBruto, $matches)) {
                $resultado->ignorarLinha($linha, 'Coluna de Questão ausente ou sem número.');

                continue;
            }

            $resposta = HeaderResolver::findValue($row, self::RESPOSTA_PATTERNS);
            $periodo = HeaderResolver::findValue($row, self::PERIODO_PATTERNS) ?? '';

            $linhas[] = [
                'linha' => $linha,
                'ra' => $identificador['ra'],
                'cpf' => $identificador['cpf'],
                'numero' => (int) $matches[0],
                'resposta' => $resposta !== null ? mb_strtoupper($resposta, 'UTF-8') : null,
                'periodo' => $periodo,
            ];
        }

        return $linhas;
    }

    /**
     * Mesma ideia de normalizarLinhasLongo(), mas no formato largo: cada
     * linha do arquivo (um respondente) vira N entradas — uma por coluna de
     * questão — reaproveitando o resto do pipeline (resolverAlunoIds(),
     * montarRegistros(), salvarLote()) sem nenhuma mudança, já que o formato
     * intermediário é o mesmo dos dois parsers.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $colunasDeQuestao  número da questão => nome da coluna, ver colunasDeQuestao()
     * @return array<int, array{linha: int, ra: ?string, cpf: ?string, numero: int, resposta: ?string, periodo: string}>
     */
    private function normalizarLinhasLargo(array $rows, ImportResult $resultado, array $colunasDeQuestao): array
    {
        $linhas = [];

        foreach ($rows as $index => $row) {
            $resultado->registrarLinha();
            $linha = $index + 2;

            $identificador = $this->resolverIdentificador($row, $linha, $resultado);
            if ($identificador === null) {
                continue;
            }

            $periodo = HeaderResolver::findValue($row, self::PERIODO_PATTERNS) ?? '';

            foreach ($colunasDeQuestao as $numero => $coluna) {
                $linhas[] = [
                    'linha' => $linha,
                    'ra' => $identificador['ra'],
                    'cpf' => $identificador['cpf'],
                    'numero' => $numero,
                    'resposta' => $this->normalizarRespostaLargo($row[$coluna] ?? null),
                    'periodo' => $periodo,
                ];
            }
        }

        return $linhas;
    }

    /**
     * Normaliza uma célula de resposta do formato largo: célula vazia ou um
     * dos sinônimos de "em branco" usados por leitoras ópticas (ver
     * RESPOSTA_LARGO_BRANCO) viram null — mesmo significado de uma célula de
     * Resposta vazia no formato longo (aluno deixou a questão em branco).
     * Qualquer outro texto (ex.: "MULT" ou "(A,C)" de leitoras que marcam
     * múltiplas respostas) é gravado como veio, maiusculizado — nunca bate
     * com um gabarito de uma letra só, então já conta como erro sozinho.
     */
    private function normalizarRespostaLargo(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }

        $texto = mb_strtoupper($texto, 'UTF-8');

        return in_array($texto, self::RESPOSTA_LARGO_BRANCO, true) ? null : $texto;
    }

    /**
     * Resolve todos os aluno_id de uma vez (duas consultas indexadas, em vez
     * de uma por linha) — mesmo critério do lookup original (CPF OU RA),
     * mantendo o menor id em caso de ambiguidade entre dois alunos diferentes.
     * Também traz o `periodo` cadastrado no perfil de cada aluno — usado como
     * fallback em montarRegistros() quando a planilha de resultados não tem
     * coluna de Período (comum: período aqui é o período do CURSO do aluno,
     * ex.: "5º", não o período letivo do exame — ver CLAUDE.md).
     *
     * @param  array<int, array{ra: ?string, cpf: ?string}>  $linhas
     * @return array{porCpf: array<string, int>, porRa: array<string, int>, periodoPorId: array<int, string>}
     */
    private function resolverAlunoIds(array $linhas): array
    {
        $cpfs = array_values(array_unique(array_filter(array_column($linhas, 'cpf'))));
        $ras = array_values(array_unique(array_filter(array_column($linhas, 'ra'))));

        if ($cpfs === [] && $ras === []) {
            return ['porCpf' => [], 'porRa' => [], 'periodoPorId' => []];
        }

        $alunos = Aluno::query()
            ->when($cpfs, fn ($query) => $query->orWhereIn('cpf', $cpfs))
            ->when($ras, fn ($query) => $query->orWhereIn('ra', $ras))
            ->orderBy('id')
            ->get(['id', 'cpf', 'ra', 'periodo']);

        $porCpf = [];
        $porRa = [];
        $periodoPorId = [];

        foreach ($alunos as $aluno) {
            if ($aluno->cpf !== null && ! isset($porCpf[$aluno->cpf])) {
                $porCpf[$aluno->cpf] = $aluno->id;
            }
            if ($aluno->ra !== null && ! isset($porRa[$aluno->ra])) {
                $porRa[$aluno->ra] = $aluno->id;
            }
            if (! empty($aluno->periodo)) {
                $periodoPorId[$aluno->id] = $aluno->periodo;
            }
        }

        return ['porCpf' => $porCpf, 'porRa' => $porRa, 'periodoPorId' => $periodoPorId];
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
     * Quando a planilha não traz Período pra uma linha, cai pro período
     * cadastrado no perfil do aluno (resolverAlunoIds()) em vez de gravar
     * vazio — só fica vazio mesmo se o aluno não for encontrado ou também
     * não tiver período cadastrado.
     *
     * @param  array<int, array{linha: int, ra: ?string, cpf: ?string, numero: int, resposta: ?string, periodo: string}>  $linhas
     * @param  array{porCpf: array<string, int>, porRa: array<string, int>, periodoPorId: array<int, string>}  $alunoIds
     * @return array<string, array{linha: int, dados: array<string, mixed>}>
     */
    private function montarRegistros(Avaliacao $avaliacao, array $linhas, array $alunoIds): array
    {
        $registros = [];

        foreach ($linhas as $linha) {
            $alunoChave = $linha['cpf'] ?? $linha['ra'];

            $candidatos = array_filter([
                $linha['cpf'] !== null ? ($alunoIds['porCpf'][$linha['cpf']] ?? null) : null,
                $linha['ra'] !== null ? ($alunoIds['porRa'][$linha['ra']] ?? null) : null,
            ], fn ($id) => $id !== null);

            $alunoId = $candidatos === [] ? null : min($candidatos);
            $periodo = $linha['periodo'] !== ''
                ? $linha['periodo']
                : ($alunoId !== null ? ($alunoIds['periodoPorId'][$alunoId] ?? '') : '');

            $chave = "{$periodo}|{$linha['numero']}|{$alunoChave}";

            $registros[$chave] = [
                'linha' => $linha['linha'],
                'dados' => [
                    'avaliacao_codigo' => $avaliacao->codigo,
                    'questao_numero' => $linha['numero'],
                    'periodo' => $periodo,
                    'ra' => $linha['ra'],
                    'cpf' => $linha['cpf'],
                    'resposta' => $linha['resposta'],
                    'aluno_id' => $alunoId,
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
