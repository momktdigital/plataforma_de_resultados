<?php

namespace App\Services;

use App\Models\Aluno;
use App\Models\AlunoMatricula;
use App\Models\Curso;
use App\Support\HeaderResolver;
use App\Support\ImportResult;
use App\Support\NomeCurso;
use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import de matrícula de alunos por planilha — reconstrói, no lado do
 * servidor, o parseMatriculas() que existia em admin/js/di_parser.js (parsing
 * client-side) mais o upsert de admin/alunos_di_process.php. Único
 * identificador de cada aluno é o RA (coluna UNIQUE em `alunos`); Per.
 * Letivo, Curso e Período são obrigatórios na planilha para a linha ser
 * aceita, o resto é opcional.
 *
 * HISTÓRICO DE MATRÍCULAS: a planilha tem uma linha por MATRÍCULA (aluno ×
 * curso × período letivo) — um aluno transferido de curso aparece duas vezes.
 * `alunos` guarda só a matrícula ATUAL (um curso por RA); cada linha vai
 * também para `aluno_matriculas` (nunca apagada por uma nova importação), e a
 * atual é ESCOLHIDA do histórico (ativa > mais recente), não pela ordem das
 * linhas no arquivo. Depois, o curso dos resultados desses alunos é
 * reavaliado (CursoDoResultadoService) — é como uma planilha antiga
 * reimportada corrige o curso de provas já gravadas. A numeração de linha
 * que a planilha traz na primeira coluna é irrelevante e nunca é gravada.
 */
class MatriculaImportService
{
    private const RA_PATTERNS = ['/^(ra|matricula|matriculaaluno)$/'];

    private const NOME_PATTERNS = ['/^(nome|aluno|estudante|nome do aluno)$/'];

    private const STATUS_PATTERNS = ['/^(status|situacao)$/'];

    private const PERIODO_LETIVO_PATTERNS = ['/^(per\s*letivo|periodo\s*letivo|periodoletivo)$/'];

    private const CURSO_PATTERNS = ['/^curso$/'];

    private const TURMA_PATTERNS = ['/^turma$/'];

    private const PERIODO_PATTERNS = ['/^periodo$/'];

    private const DATA_NASCIMENTO_PATTERNS = ['/^(dt\s*nascimento|data\s*(de\s*)?nascimento|datanascimento)$/'];

    private const CPF_PATTERNS = ['/^cpf$/'];

    private const EMAIL_PATTERNS = ['/^(e\s*mail|email)$/'];

    private const COD_PERFIL_PATTERNS = ['/^(cod\s*perfil|codigoperfil)$/'];

    private const MATRIZ_PATTERNS = ['/^matriz$/'];

    private const COR_RACA_PATTERNS = ['/^cor\s*raca$/'];

    private const RELIGIAO_PATTERNS = ['/^religiao$/'];

    private const SEXO_PATTERNS = ['/^sexo$/'];

    private const ESTADO_CIVIL_PATTERNS = ['/^estado\s*civil$/'];

    private const CIDADE_PATTERNS = ['/^cidade$/'];

    private const UF_PATTERNS = ['/^uf$/'];

    private const CELULAR_PATTERNS = ['/^celular$/'];

    /**
     * Dt. Ativação: quando esta matrícula foi ativada no período letivo. SÓ esse
     * cabeçalho: a planilha tem outras datas parecidas (ingresso, status...) que
     * não são o início da matrícula e leriam a data errada.
     */
    private const DATA_INICIO_PATTERNS = ['/^(dt|data) (da |de )?ativacao$/'];

    /** Dt. Ocorrência: quando a matrícula deixou de valer (transferência, cancelamento...). */
    private const DATA_FIM_PATTERNS = ['/^(dt|data) (da |de )?ocorrencia$/'];

    public function importar(UploadedFile $file, bool $dryRun = false): ImportResult
    {
        $rows = SpreadsheetReader::readRows($file);
        $resultado = new ImportResult;

        // Cursos já conhecidos, carregados uma vez em vez de um SELECT (+
        // possível INSERT) por linha — numa planilha de milhares de alunos,
        // o mesmo punhado de cursos se repete em quase toda linha.
        $cursosConhecidos = Curso::pluck('nome')->flip()->all();

        // Mesma ideia para o próprio Aluno: o custo dominante do import é o
        // Aluno::where('ra', ...)->first() repetido a cada linha — carregar
        // todos os RAs da planilha de uma vez (whereIn) troca milhares de
        // idas ao banco por uma só.
        $ras = collect($rows)
            ->map(fn ($row) => HeaderResolver::findValue($row, self::RA_PATTERNS))
            ->filter()
            ->unique()
            ->values();
        $alunosPorRa = $ras->isEmpty() ? [] : Aluno::whereIn('ra', $ras)->get()->keyBy('ra')->all();

        // Uma entrada por (RA, curso, período letivo) — a última linha do
        // arquivo para a mesma matrícula vence.
        $matriculas = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $resultado->registrarLinha();
                $linha = $index + 2; // +1 pelo cabeçalho, +1 por índice base 0

                $ra = HeaderResolver::findValue($row, self::RA_PATTERNS);
                if ($ra === null) {
                    $resultado->ignorarLinha($linha, 'Coluna RA ausente ou vazia.');

                    continue;
                }

                $periodoLetivo = $this->normalizarPeriodoLetivo(
                    HeaderResolver::findValue($row, self::PERIODO_LETIVO_PATTERNS) ?? ''
                );
                $curso = HeaderResolver::findValue($row, self::CURSO_PATTERNS);
                $curso = $curso !== null ? mb_strtoupper($curso, 'UTF-8') : null;
                $periodo = $this->normalizarPeriodo(
                    HeaderResolver::findValue($row, self::PERIODO_PATTERNS) ?? ''
                );

                if ($periodoLetivo === '' || $curso === null || $periodo === '') {
                    $resultado->ignorarLinha($linha, 'Per. Letivo, Curso e Período são obrigatórios.');

                    continue;
                }

                // Uma linha ruim (CPF duplicado de outro RA, valor que não
                // cabe numa coluna, etc.) não pode derrubar a importação
                // inteira — registra como ignorada, com o motivo, e segue
                // pras próximas milhares de linhas.
                try {
                    $this->salvarAluno($resultado, $ra, $curso, $periodoLetivo, $periodo, $row, $alunosPorRa);

                    $matriculas[$ra.'|'.NomeCurso::chave($curso).'|'.$periodoLetivo] = [
                        'aluno_id' => $alunosPorRa[$ra]->id,
                        'curso' => $curso,
                        'matriz' => HeaderResolver::findValue($row, self::MATRIZ_PATTERNS),
                        'periodo' => $periodo,
                        'turma' => HeaderResolver::findValue($row, self::TURMA_PATTERNS),
                        'periodo_letivo' => $periodoLetivo,
                        'status' => ($status = HeaderResolver::findValue($row, self::STATUS_PATTERNS)) !== null ? mb_strtoupper($status, 'UTF-8') : null,
                        'data_inicio' => $this->parseData(HeaderResolver::findValue($row, self::DATA_INICIO_PATTERNS)),
                        'data_fim' => $this->parseData(HeaderResolver::findValue($row, self::DATA_FIM_PATTERNS)),
                    ];

                    if (! isset($cursosConhecidos[$curso])) {
                        Curso::firstOrCreate(['nome' => $curso]);
                        $cursosConhecidos[$curso] = true;
                    }
                } catch (Throwable $e) {
                    $resultado->ignorarLinha($linha, 'Falha ao salvar: '.$e->getMessage());
                }
            }

            $this->gravarHistorico(array_values($matriculas));
            $alunoIds = array_values(array_unique(array_column($matriculas, 'aluno_id')));
            $this->escolherMatriculaAtual($alunoIds);

            // O histórico mudou: refaz o curso dos resultados desses alunos e os
            // cursos das avaliações onde eles aparecem. No dry-run nada disso
            // precisa rodar (tudo seria desfeito logo abaixo).
            if (! $dryRun && $alunoIds !== []) {
                $avaliacoes = (new CursoDoResultadoService)->atualizarAlunos($alunoIds);
                (new AvaliacaoCursoService)->sincronizarAvaliacoes($avaliacoes);
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
     * Grava o histórico. Se a mesma matrícula (aluno × curso × período letivo) já existe com um estado MAIS
     * RECENTE do que o da planilha, ela fica como está: reimportar uma planilha antiga (exportada antes de a
     * matrícula ser cancelada, por exemplo) não pode reverter CANCELADA para ATIVA. "Mais recente" = a data do
     * último fato registrado na linha — Dt. Ocorrência se houver, senão Dt. Ativação (ver marco()). Sem datas
     * para comparar, ou com datas iguais, vale a planilha importada agora.
     *
     * @param  array<int, array<string, mixed>>  $matriculas
     */
    private function gravarHistorico(array $matriculas): void
    {
        $agora = now();

        foreach (array_chunk($matriculas, 500) as $lote) {
            $lote = $this->semRetrocesso($lote);

            if ($lote === []) {
                continue;
            }

            AlunoMatricula::upsert(
                array_map(fn ($m) => [...$m, 'created_at' => $agora, 'updated_at' => $agora], $lote),
                ['aluno_id', 'curso', 'periodo_letivo'],
                ['matriz', 'periodo', 'turma', 'status', 'data_inicio', 'data_fim', 'updated_at'],
            );
        }
    }

    /**
     * Tira do lote as matrículas que a planilha traz com um estado mais antigo do que o já gravado.
     *
     * @param  array<int, array<string, mixed>>  $lote
     * @return array<int, array<string, mixed>>
     */
    private function semRetrocesso(array $lote): array
    {
        $existentes = [];
        AlunoMatricula::whereIn('aluno_id', array_unique(array_column($lote, 'aluno_id')))
            ->get(['aluno_id', 'curso', 'periodo_letivo', 'data_inicio', 'data_fim'])
            ->each(function (AlunoMatricula $m) use (&$existentes) {
                $existentes[$m->aluno_id.'|'.NomeCurso::chave((string) $m->curso).'|'.$m->periodo_letivo] = $this->marco(
                    $m->data_inicio?->format('Y-m-d'),
                    $m->data_fim?->format('Y-m-d'),
                );
            });

        return array_values(array_filter($lote, function (array $m) use ($existentes) {
            $gravado = $existentes[$m['aluno_id'].'|'.NomeCurso::chave((string) $m['curso']).'|'.$m['periodo_letivo']] ?? null;
            $novo = $this->marco($m['data_inicio'], $m['data_fim']);

            return ! ($gravado !== null && $novo !== null && $novo < $gravado);
        }));
    }

    /** Data do último fato registrado na matrícula: a saída (Dt. Ocorrência), ou a ativação (Dt. Ativação). */
    private function marco(?string $inicio, ?string $fim): ?string
    {
        return $fim ?? $inicio;
    }

    /**
     * Define a matrícula ATUAL de cada aluno a partir do histórico: a do
     * período letivo mais recente e, nele, ativa (sem status na planilha também
     * conta como ativa; aprovado/reprovado no período também — ver
     * AlunoMatricula::vigenteNoPeriodo()) > aguardando > as demais (transferida,
     * cancelada, trancada...); depois a data de início mais recente e, por fim, a gravada
     * por último. Independe da ordem das linhas do arquivo.
     *
     * @param  array<int, int>  $alunoIds
     */
    private function escolherMatriculaAtual(array $alunoIds): void
    {
        foreach (array_chunk($alunoIds, 500) as $lote) {
            /** @var Collection<int, Collection<int, AlunoMatricula>> $porAluno */
            $porAluno = AlunoMatricula::whereIn('aluno_id', $lote)->get()->groupBy('aluno_id');

            foreach ($porAluno as $alunoId => $doAluno) {
                $atual = $doAluno->sortBy(fn (AlunoMatricula $m) => [
                    // Período letivo mais recente primeiro (um "ATIVA" de 2026/1 não
                    // vence um "CANCELADA" de 2026/2) e, nele, a ativa. Decrescente
                    // via string complementar (ver invertido()).
                    $this->invertido($m->periodo_letivo),
                    AlunoMatricula::vigenteNoPeriodo($m->status) ? 0 : (mb_strtoupper((string) $m->status, 'UTF-8') === 'AGUARDANDO' ? 1 : 2),
                    $this->invertido($m->data_inicio?->format('Y-m-d') ?? ''),
                    $this->invertido($m->updated_at?->format('Y-m-d H:i:s') ?? '').'|'.$this->invertido(str_pad((string) $m->id, 12, '0', STR_PAD_LEFT)),
                ])->first();

                Aluno::whereKey($alunoId)->update([
                    'curso' => $atual->curso,
                    'matriz' => $atual->matriz,
                    'status' => $atual->status,
                    'periodo_letivo' => $atual->periodo_letivo !== '' ? $atual->periodo_letivo : null,
                    'periodo' => $atual->periodo,
                    'turma' => $atual->turma,
                ]);
            }
        }
    }

    /** Inverte a ordem de uma string numérica/data para usar em sortBy ascendente como "decrescente". */
    private function invertido(string $valor): string
    {
        // Vazio (sem período letivo/data) vai por último: '~' é maior que qualquer dígito.
        return $valor === '' ? '~' : strtr($valor, '0123456789', '9876543210');
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Aluno>  $alunosPorRa
     */
    private function salvarAluno(
        ImportResult $resultado,
        string $ra,
        string $curso,
        string $periodoLetivo,
        string $periodo,
        array $row,
        array &$alunosPorRa,
    ): void {
        $nome = HeaderResolver::findValue($row, self::NOME_PATTERNS);
        $cpf = HeaderResolver::findValue($row, self::CPF_PATTERNS);
        // "123.456.789-09" → "12345678909": é assim que o CPF está nos resultados importados e que o login do
        // portal procura (só dígitos). Com máscara, o aluno nunca seria achado por nenhum dos dois.
        if ($cpf !== null && strlen($digitos = preg_replace('/\D/', '', $cpf)) === 11) {
            $cpf = $digitos;
        }
        $dataNascimento = $this->parseData(HeaderResolver::findValue($row, self::DATA_NASCIMENTO_PATTERNS));
        $email = HeaderResolver::findValue($row, self::EMAIL_PATTERNS);
        $codPerfil = HeaderResolver::findValue($row, self::COD_PERFIL_PATTERNS);
        $status = HeaderResolver::findValue($row, self::STATUS_PATTERNS);
        $turma = HeaderResolver::findValue($row, self::TURMA_PATTERNS);
        $matriz = HeaderResolver::findValue($row, self::MATRIZ_PATTERNS);
        $corRaca = HeaderResolver::findValue($row, self::COR_RACA_PATTERNS);
        $religiao = HeaderResolver::findValue($row, self::RELIGIAO_PATTERNS);
        $sexo = HeaderResolver::findValue($row, self::SEXO_PATTERNS);
        $estadoCivil = HeaderResolver::findValue($row, self::ESTADO_CIVIL_PATTERNS);
        $cidade = HeaderResolver::findValue($row, self::CIDADE_PATTERNS);
        $uf = HeaderResolver::findValue($row, self::UF_PATTERNS);
        $celular = HeaderResolver::findValue($row, self::CELULAR_PATTERNS);

        $aluno = $alunosPorRa[$ra] ?? null;

        if ($aluno === null) {
            $aluno = Aluno::create([
                'ra' => $ra,
                'nome' => $nome,
                'cpf' => $cpf,
                'data_nascimento' => $dataNascimento,
                'email' => $email,
                'curso' => $curso,
                'matriz' => $matriz,
                'cod_perfil' => $codPerfil,
                'status' => $status,
                'periodo_letivo' => $periodoLetivo,
                'periodo' => $periodo,
                'turma' => $turma,
                'cor_raca' => $corRaca,
                'religiao' => $religiao,
                'sexo' => $sexo,
                'estado_civil' => $estadoCivil,
                'cidade' => $cidade,
                'uf' => $uf,
                'celular' => $celular,
            ]);
            // Uma linha seguinte com o mesmo RA (planilha com duplicata) deve
            // ver este aluno como já existente, igual ao comportamento do
            // Aluno::where('ra', ...)->first() original.
            $alunosPorRa[$ra] = $aluno;
            $resultado->registrarCriada();

            return;
        }

        // Espelha o UPSERT de admin/alunos_di_process.php: campos de
        // identidade/dados pessoais (nome/cpf/nascimento/email/cod_perfil/
        // cor-raça/religião/sexo/estado civil/cidade/UF/celular) só são
        // sobrescritos quando a planilha traz um valor novo — não apagam um
        // dado já cadastrado manualmente. Curso/matriz/status/período
        // letivo/período/turma são específicos da matrícula corrente e
        // sempre refletem a planilha mais recente (inclusive para limpar, se
        // a coluna ficar vazia num reimport).
        $aluno->nome = $nome ?? $aluno->nome;
        $aluno->cpf = $cpf ?? $aluno->cpf;
        $aluno->data_nascimento = $dataNascimento ?? $aluno->data_nascimento;
        $aluno->email = $email ?? $aluno->email;
        $aluno->cod_perfil = $codPerfil ?? $aluno->cod_perfil;
        $aluno->cor_raca = $corRaca ?? $aluno->cor_raca;
        $aluno->religiao = $religiao ?? $aluno->religiao;
        $aluno->sexo = $sexo ?? $aluno->sexo;
        $aluno->estado_civil = $estadoCivil ?? $aluno->estado_civil;
        $aluno->cidade = $cidade ?? $aluno->cidade;
        $aluno->uf = $uf ?? $aluno->uf;
        $aluno->celular = $celular ?? $aluno->celular;
        $aluno->curso = $curso;
        $aluno->matriz = $matriz;
        $aluno->status = $status;
        $aluno->periodo_letivo = $periodoLetivo;
        $aluno->periodo = $periodo;
        $aluno->turma = $turma;
        $aluno->save();

        $resultado->registrarAtualizada();
    }

    /** Normaliza período: P1→1º, 1→1º, "12º PERÍODO"→12º, "ESTÁGIO / 9º PERÍODO"→9º */
    private function normalizarPeriodo(string $valor): string
    {
        $s = mb_strtoupper(trim($valor), 'UTF-8');
        if ($s === '') {
            return '';
        }

        if (preg_match('/^P(\d+)$/', $s, $m)) {
            return $m[1].'º';
        }
        if (preg_match('/^(\d+)$/', $s, $m)) {
            return $m[1].'º';
        }
        if (preg_match('/^(\d+)/', $s, $m)) {
            return $m[1].'º';
        }
        if (preg_match('/(\d+)\s*[ºo°]/ui', $s, $m)) {
            return $m[1].'º';
        }

        return $s;
    }

    /** Normaliza período letivo: "2026.1", "2026 1" → "2026/1" */
    private function normalizarPeriodoLetivo(string $valor): string
    {
        $s = trim($valor);
        if ($s === '') {
            return '';
        }

        if (preg_match('/^(\d{4})[.\-\/\s]?(\d)$/', $s, $m)) {
            return $m[1].'/'.$m[2];
        }

        return $s;
    }

    /** Converte serial do Excel ou string (d/m/Y, Y-m-d) → "Y-m-d"; null se vazio/inválido. */
    private function parseData(?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            $timestamp = ((float) $valor - 25569) * 86400;

            return gmdate('Y-m-d', (int) $timestamp);
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $valor, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return $valor;
        }

        return null;
    }
}
