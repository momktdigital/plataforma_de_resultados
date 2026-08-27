<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Models\Questao;
use App\Support\HeaderResolver;
use App\Support\ImportResult;
use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import de questões/gabarito (+ metadados pedagógicos opcionais) para uma
 * Avaliação já existente. Únicos campos obrigatórios por linha: Questão e
 * Gabarito — todo o resto é preenchido só quando a coluna existir e tiver
 * valor na linha.
 */
class QuestaoImportService
{
    public function __construct(private QuestaoReferenciaService $referencias) {}

    private const NUMERO_PATTERNS = ['/^(questao|numero|item|q)$/', '/^quest/', '/^num/'];

    private const GABARITO_PATTERNS = ['/^(gabarito|resposta|alternativa|letra|correta)$/'];

    /** Letras de coluna aceitas por tipo de referência (ver QuestaoReferencia). */
    private const REFERENCIA_LETRAS = [
        'matriz_prova' => ['a', 'b', 'c'],
        'dcn' => ['a', 'b'],
        'portaria_inep' => ['a', 'b', 'c'],
        'ppc' => ['a', 'b', 'c', 'd'],
    ];

    private const REFERENCIA_LABEL_TOKENS = [
        'matriz_prova' => ['matriz', 'prova'],
        'dcn' => ['dcn'],
        'portaria_inep' => ['portaria', 'inep'],
        'ppc' => ['ppc'],
    ];

    /** Colunas de `questoes` sobrescritas pelo upsert() a cada reimport (fora as chaves avaliacao_codigo/numero). */
    private const CAMPOS_ATUALIZAVEIS = [
        'gabarito', 'area', 'tema', 'habilidade', 'bloom_nivel', 'bloom_verbo',
        'miller_nivel', 'dificuldade_pedagogica', 'dificuldade_tri', 'deleted_at', 'updated_at',
    ];

    /** Tamanho dos lotes do upsert() — ver ResultadoImportService::TAMANHO_LOTE. */
    private const TAMANHO_LOTE = 500;

    public function importar(Avaliacao $avaliacao, UploadedFile $file): ImportResult
    {
        $rows = SpreadsheetReader::readRows($file);
        $resultado = new ImportResult;

        $linhas = $this->normalizarLinhas($rows, $resultado);

        if ($linhas === []) {
            return $resultado;
        }

        $numeros = array_values(array_unique(array_column($linhas, 'numero')));

        // withTrashed(): uma questão soft-deletada ainda ocupa o índice único
        // (avaliacao_codigo, numero) — reimportá-la conta como "atualizada"
        // (restaurada), não "criada".
        $existentes = Questao::withTrashed()
            ->where('avaliacao_codigo', $avaliacao->codigo)
            ->whereIn('numero', $numeros)
            ->pluck('numero')
            ->flip()
            ->all();

        DB::transaction(function () use ($avaliacao, $linhas, $numeros, $existentes, $resultado) {
            $registros = $this->montarRegistros($avaliacao, $linhas);
            $vistos = [];
            $falharam = [];

            foreach (array_chunk($registros, self::TAMANHO_LOTE, true) as $lote) {
                $this->salvarLote($lote, $existentes, $vistos, $falharam, $resultado);
            }

            $questoes = Questao::where('avaliacao_codigo', $avaliacao->codigo)
                ->whereIn('numero', $numeros)
                ->get()
                ->keyBy('numero');

            foreach ($linhas as $linha) {
                if (isset($falharam[$linha['numero']])) {
                    continue;
                }

                $questao = $questoes->get($linha['numero']);
                if ($questao === null) {
                    continue;
                }

                $this->sincronizarMatrizes($questao, $linha['row']);
                $this->sincronizarReferencias($questao, $linha['row']);
            }
        });

        return $resultado;
    }

    /**
     * Valida e normaliza cada linha da planilha, sem tocar o banco.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{linha: int, numero: int, gabarito: string, atributos: array<string, string|null>, row: array<string, mixed>}>
     */
    private function normalizarLinhas(array $rows, ImportResult $resultado): array
    {
        $linhas = [];

        foreach ($rows as $index => $row) {
            $resultado->registrarLinha();
            $linha = $index + 2; // +1 pelo cabeçalho, +1 por índice base 0

            $numeroBruto = HeaderResolver::findValue($row, self::NUMERO_PATTERNS);
            $gabarito = HeaderResolver::findValue($row, self::GABARITO_PATTERNS);

            if ($numeroBruto === null || ! preg_match('/\d+/', $numeroBruto, $matches)) {
                $resultado->ignorarLinha($linha, 'Coluna de Questão ausente ou sem número.');

                continue;
            }

            if ($gabarito === null) {
                $resultado->ignorarLinha($linha, 'Coluna de Gabarito ausente ou vazia.');

                continue;
            }

            $linhas[] = [
                'linha' => $linha,
                'numero' => (int) $matches[0],
                'gabarito' => mb_strtoupper($gabarito, 'UTF-8'),
                'atributos' => $this->extrairMetadados($row),
                'row' => $row,
            ];
        }

        return $linhas;
    }

    /**
     * Monta os registros a upsertar — uma chave (numero) repetida dentro do
     * próprio arquivo mantém só a última ocorrência. Cada registro carrega
     * 'linha' (número da linha original), pra atribuir o erro corretamente
     * se esse numero falhar ao salvar — removido antes de virar a linha do
     * upsert.
     *
     * @param  array<int, array{linha: int, numero: int, gabarito: string, atributos: array<string, string|null>}>  $linhas
     * @return array<int, array{linha: int, dados: array<string, mixed>}>
     */
    private function montarRegistros(Avaliacao $avaliacao, array $linhas): array
    {
        $registros = [];

        foreach ($linhas as $linha) {
            $registros[$linha['numero']] = [
                'linha' => $linha['linha'],
                'dados' => array_merge($linha['atributos'], [
                    'avaliacao_codigo' => $avaliacao->codigo,
                    'numero' => $linha['numero'],
                    'gabarito' => $linha['gabarito'],
                    'deleted_at' => null,
                ]),
            ];
        }

        return $registros;
    }

    /**
     * Salva um lote inteiro num único upsert() (caminho rápido — o normal).
     * Se o lote falhar (ex.: MySQL em modo estrito rejeitando um valor que
     * não cabe numa coluna), reprocessa o MESMO lote linha a linha, isolando
     * só o(s) número(s) problemático(s) via ImportResult::ignorarLinha() em
     * vez de derrubar o import inteiro — mesma ideia que
     * MatriculaImportService já aplica.
     *
     * @param  array<int, array{linha: int, dados: array<string, mixed>}>  $lote
     * @param  array<int, true>  $existentes
     * @param  array<int, true>  $vistos
     * @param  array<int, true>  $falharam
     */
    private function salvarLote(array $lote, array $existentes, array &$vistos, array &$falharam, ImportResult $resultado): void
    {
        $agora = now();

        try {
            DB::table('questoes')->upsert(
                array_map(fn (array $r) => $r['dados'] + ['created_at' => $agora, 'updated_at' => $agora], array_values($lote)),
                ['avaliacao_codigo', 'numero'],
                self::CAMPOS_ATUALIZAVEIS
            );

            foreach ($lote as $numero => $registro) {
                $this->registrarSucesso($numero, $existentes, $vistos, $resultado);
            }

            return;
        } catch (Throwable) {
            // Segue pro fallback linha a linha abaixo.
        }

        foreach ($lote as $numero => $registro) {
            try {
                DB::table('questoes')->upsert(
                    [$registro['dados'] + ['created_at' => $agora, 'updated_at' => $agora]],
                    ['avaliacao_codigo', 'numero'],
                    self::CAMPOS_ATUALIZAVEIS
                );

                $this->registrarSucesso($numero, $existentes, $vistos, $resultado);
            } catch (Throwable $e) {
                $falharam[$numero] = true;
                $resultado->ignorarLinha($registro['linha'], 'Falha ao salvar: '.$e->getMessage());
            }
        }
    }

    /** @param  array<int, true>  $existentes @param  array<int, true>  $vistos */
    private function registrarSucesso(int $numero, array $existentes, array &$vistos, ImportResult $resultado): void
    {
        if (isset($existentes[$numero]) || isset($vistos[$numero])) {
            $resultado->registrarAtualizada();
        } else {
            $resultado->registrarCriada();
        }

        $vistos[$numero] = true;
    }

    /** @return array<string, string|null> */
    private function extrairMetadados(array $row): array
    {
        $campos = [];

        $campos['area'] = HeaderResolver::findValue($row, ['/\barea\b/']);
        $campos['tema'] = HeaderResolver::findValue($row, ['/\btema\b/']);
        $campos['habilidade'] = HeaderResolver::findValue($row, ['/\bhabilidade\b/']);

        $campos['bloom_nivel'] = HeaderResolver::findValue($row, ['/(?=.*bloom)(?=.*nivel)/']);
        // "Taxonomia" sozinho também é aceito: nas planilhas dos coordenadores
        // essa coluna já vem com os verbos de Bloom (Lembrar, Aplicar...), só
        // sem "Bloom" no nome do cabeçalho.
        $campos['bloom_verbo'] = HeaderResolver::findValue($row, ['/(?=.*bloom)(?=.*verbo)/', '/\btaxonomia\b/']);
        $campos['miller_nivel'] = HeaderResolver::findValue($row, ['/miller/']);

        $dificuldadePedagogica = HeaderResolver::findValue($row, ['/(?=.*dificuldade)(?=.*pedagog)/']);
        $campos['dificuldade_pedagogica'] = $this->normalizarDificuldade($dificuldadePedagogica);

        $dificuldadeTri = HeaderResolver::findValue($row, ['/(?=.*dificuldade)(?=.*tri)/']);
        $campos['dificuldade_tri'] = $dificuldadeTri !== null
            ? (float) str_replace(',', '.', $dificuldadeTri)
            : null;

        return $campos;
    }

    private function normalizarDificuldade(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = HeaderResolver::normalize($valor);

        return match (true) {
            str_starts_with($valor, 'facil') => 'facil',
            str_starts_with($valor, 'medi') => 'medio',
            str_starts_with($valor, 'dific') => 'dificil',
            default => null,
        };
    }

    private function sincronizarMatrizes(Questao $questao, array $row): void
    {
        $periodos = $this->explodirValores(HeaderResolver::findValue($row, ['/(?=.*matriz)(?=.*period)/']));
        $disciplinas = $this->explodirValores(HeaderResolver::findValue($row, ['/(?=.*matriz)(?=.*disciplina)/']));
        $codigos = $this->explodirValores(HeaderResolver::findValue($row, ['/(?=.*matriz)(?=.*codigo)/']));

        if (empty($periodos) && empty($disciplinas) && empty($codigos)) {
            return;
        }

        $this->referencias->sincronizarMatrizes($questao, $periodos, $disciplinas, $codigos);
    }

    /**
     * Só mexe nos tipos de referência que aparecem nesta linha da planilha —
     * um import que não traz coluna de "PPC", por exemplo, não apaga o que já
     * estava salvo em `ppc` de um import anterior.
     */
    private function sincronizarReferencias(Questao $questao, array $row): void
    {
        foreach (self::REFERENCIA_LETRAS as $tipo => $letras) {
            $valores = [];
            foreach ($letras as $letra) {
                $valor = HeaderResolver::findCampoValue($row, self::REFERENCIA_LABEL_TOKENS[$tipo], $letra);
                if ($valor !== null) {
                    $valores[] = $valor;
                }
            }

            if ($valores !== []) {
                $this->referencias->sincronizarReferencias($questao, $tipo, $valores);
            }
        }
    }

    /** @return array<int, string> */
    private function explodirValores(?string $valor): array
    {
        if ($valor === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[;,|]/', $valor))));
    }
}
