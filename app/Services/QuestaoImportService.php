<?php

namespace App\Services;

use App\Models\Prova;
use App\Models\Questao;
use App\Support\HeaderResolver;
use App\Support\ImportResult;
use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Import de questões/gabarito (+ metadados pedagógicos opcionais) para uma
 * Prova já existente. Únicos campos obrigatórios por linha: Questão e
 * Gabarito — todo o resto é preenchido só quando a coluna existir e tiver
 * valor na linha.
 */
class QuestaoImportService
{
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

    public function importar(Prova $prova, UploadedFile $file): ImportResult
    {
        $rows = SpreadsheetReader::readRows($file);
        $resultado = new ImportResult;

        DB::transaction(function () use ($prova, $rows, $resultado) {
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

                $numero = (int) $matches[0];
                $gabarito = mb_strtoupper($gabarito, 'UTF-8');

                $atributos = $this->extrairMetadados($row);

                $questao = $this->salvarQuestao($prova, $numero, $gabarito, $atributos);

                $questao->wasRecentlyCreated ? $resultado->registrarCriada() : $resultado->registrarAtualizada();

                $this->sincronizarMatrizes($questao, $row);
                $this->sincronizarReferencias($questao, $row);
            }
        });

        return $resultado;
    }

    /**
     * updateOrCreate "manual" que também restaura uma questão excluída
     * (soft-delete) em vez de colidir com o índice único (prova, número).
     *
     * @param  array<string, mixed>  $atributos
     */
    private function salvarQuestao(Prova $prova, int $numero, string $gabarito, array $atributos): Questao
    {
        $questao = Questao::withTrashed()
            ->where('prova_codigo', $prova->codigo)
            ->where('numero', $numero)
            ->first();

        if ($questao === null) {
            $questao = new Questao(['prova_codigo' => $prova->codigo, 'numero' => $numero]);
        } elseif ($questao->trashed()) {
            $questao->restore();
        }

        $questao->fill(array_merge($atributos, ['gabarito' => $gabarito]));
        $questao->save();

        return $questao;
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

        $total = max(count($periodos), count($disciplinas), count($codigos));

        $questao->matrizes()->delete();

        for ($i = 0; $i < $total; $i++) {
            $questao->matrizes()->create([
                'periodo' => isset($periodos[$i]) && is_numeric($periodos[$i]) ? (int) $periodos[$i] : null,
                'disciplina' => $disciplinas[$i] ?? null,
                'codigo' => $codigos[$i] ?? null,
            ]);
        }
    }

    /**
     * Só mexe nos tipos de referência que aparecem nesta linha da planilha —
     * um import que não traz coluna de "PPC", por exemplo, não apaga o que já
     * estava salvo em `ppc` de um import anterior.
     */
    private function sincronizarReferencias(Questao $questao, array $row): void
    {
        $valoresPorTipo = [];

        foreach (self::REFERENCIA_LETRAS as $tipo => $letras) {
            $valores = [];
            foreach ($letras as $letra) {
                $valor = HeaderResolver::findCampoValue($row, self::REFERENCIA_LABEL_TOKENS[$tipo], $letra);
                if ($valor !== null) {
                    $valores[] = $valor;
                }
            }

            if ($valores !== []) {
                $valoresPorTipo[$tipo] = $valores;
            }
        }

        foreach ($valoresPorTipo as $tipo => $valores) {
            $questao->referencias()->where('tipo', $tipo)->delete();
            $questao->referencias()->createMany(array_map(fn ($valor) => ['tipo' => $tipo, 'valor' => $valor], $valores));
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
