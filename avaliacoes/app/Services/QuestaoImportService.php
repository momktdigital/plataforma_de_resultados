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

                $questao = Questao::updateOrCreate(
                    ['prova_codigo' => $prova->codigo, 'numero' => $numero],
                    array_merge($atributos, ['gabarito' => $gabarito]),
                );

                $questao->wasRecentlyCreated ? $resultado->registrarCriada() : $resultado->registrarAtualizada();

                $this->sincronizarMatrizes($questao, $row);
            }
        });

        return $resultado;
    }

    /** @return array<string, string|null> */
    private function extrairMetadados(array $row): array
    {
        $campos = [];

        foreach (['a' => 'matriz_prova_a', 'b' => 'matriz_prova_b', 'c' => 'matriz_prova_c'] as $letra => $campo) {
            $campos[$campo] = HeaderResolver::findCampoValue($row, ['matriz', 'prova'], $letra);
        }

        foreach (['a' => 'dcn_a', 'b' => 'dcn_b'] as $letra => $campo) {
            $campos[$campo] = HeaderResolver::findCampoValue($row, ['dcn'], $letra);
        }

        foreach (['a' => 'portaria_inep_a', 'b' => 'portaria_inep_b', 'c' => 'portaria_inep_c'] as $letra => $campo) {
            $campos[$campo] = HeaderResolver::findCampoValue($row, ['portaria', 'inep'], $letra);
        }

        foreach (['a' => 'ppc_a', 'b' => 'ppc_b', 'c' => 'ppc_c', 'd' => 'ppc_d'] as $letra => $campo) {
            $campos[$campo] = HeaderResolver::findCampoValue($row, ['ppc'], $letra);
        }

        $campos['bloom_nivel'] = HeaderResolver::findValue($row, ['/(?=.*bloom)(?=.*nivel)/']);
        $campos['bloom_verbo'] = HeaderResolver::findValue($row, ['/(?=.*bloom)(?=.*verbo)/']);
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

    /** @return array<int, string> */
    private function explodirValores(?string $valor): array
    {
        if ($valor === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[;,|]/', $valor))));
    }
}
