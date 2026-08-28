<?php

namespace App\Services;

use App\Models\Aluno;
use App\Models\Curso;
use App\Support\HeaderResolver;
use App\Support\ImportResult;
use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import de matrícula de alunos por planilha — reconstrói, no lado do
 * servidor, o parseMatriculas() que existia em admin/js/di_parser.js (parsing
 * client-side) mais o upsert de admin/alunos_di_process.php. Único
 * identificador de cada aluno é o RA (coluna UNIQUE em `alunos`); Per.
 * Letivo, Curso e Período são obrigatórios na planilha para a linha ser
 * aceita, o resto é opcional.
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

                    if (! isset($cursosConhecidos[$curso])) {
                        Curso::firstOrCreate(['nome' => $curso]);
                        $cursosConhecidos[$curso] = true;
                    }
                } catch (Throwable $e) {
                    $resultado->ignorarLinha($linha, 'Falha ao salvar: '.$e->getMessage());
                }
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
