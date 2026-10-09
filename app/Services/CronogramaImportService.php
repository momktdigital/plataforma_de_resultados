<?php

namespace App\Services;

use App\Models\CronogramaItem;
use App\Models\CronogramaPendencia;
use App\Models\Curso;
use App\Support\AtividadeLogger;
use App\Support\NomeCurso;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DataExcel;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Carrega o cronograma a partir da "Tabela-base da Auditoria ROC/ROD": a aba "Checklist de Auditoria" (uma atividade por
 * linha, uma coluna por curso com a situação) e a aba "Registro de Pendências".
 *
 * Funciona em duas etapas: `analisar()` só lê e devolve o PLANO (o que seria criado e os avisos) e `gravar()` o aplica —
 * quem chama decide. Reexecutar não duplica: atividade (data + rotina + projeto + descrição) e pendência (atividade + curso +
 * data + texto) que já existem são puladas, e o que o colaborador já alterou no sistema nunca é sobrescrito.
 *
 * Regras de leitura do checklist: célula "Não se aplica" = o curso não entra na atividade; célula em branco = a atividade se
 * aplica e ainda não começou ("aguardando"); atividade em que nenhum curso se aplica é ignorada. As colunas de curso da
 * planilha são mapeadas para os cursos do sistema (mesma grafia sem acento/caixa, um erro de digitação óbvio, ou o mapa
 * explícito); várias colunas podem apontar para o mesmo curso (vale a situação mais urgente) e uma coluna pode ser ignorada.
 */
class CronogramaImportService
{
    private const ABA_CHECKLIST = 0;

    private const ABA_PENDENCIAS = 1;

    private const NAO_SE_APLICA = 'nao se aplica';

    /**
     * @param  array<string, array<int, string>>  $mapa  coluna da planilha => cursos do sistema (pela grafia da coluna, sem acento/caixa)
     * @param  array<int, string>  $ignorar  colunas da planilha a descartar
     * @return array{itens: array<int, array<string, mixed>>, pendencias: array<int, array<string, mixed>>, colunas: array<string, array{coluna: string, cursos: array<int, string>, como: string}>, avisos: array<int, string>, erros: array<int, string>}
     */
    public function analisar(string $caminho, array $mapa = [], array $ignorar = []): array
    {
        $planilha = IOFactory::load($caminho);
        if ($planilha->getSheetCount() < 1) {
            throw new RuntimeException('A planilha não tem abas.');
        }

        $checklist = $planilha->getSheet(self::ABA_CHECKLIST);
        $plano = ['itens' => [], 'pendencias' => [], 'colunas' => [], 'avisos' => [], 'erros' => []];

        [$cabecalho, $linhas] = $this->linhas($checklist);
        $colunas = $this->mapearColunas(array_slice($cabecalho, 4, null, true), $mapa, $ignorar);
        $plano['colunas'] = $colunas['mapa'];
        $plano['erros'] = $colunas['erros'];
        if ($plano['erros'] !== []) {
            return $plano;
        }

        foreach ($linhas as $numero => $linha) {
            $item = $this->item($numero, $linha, $cabecalho, $colunas['mapa'], $plano['avisos']);
            if ($item !== null) {
                $plano['itens'][] = $item;
            }
        }

        if ($planilha->getSheetCount() > self::ABA_PENDENCIAS) {
            $this->pendencias($planilha->getSheet(self::ABA_PENDENCIAS), $colunas['mapa'], $plano);
        }

        return $plano;
    }

    /**
     * Aplica o plano. Devolve quantas atividades/pendências foram criadas e quantas já existiam.
     *
     * @param  array<string, mixed>  $plano  resultado de analisar() sem erros
     * @return array{itens_criados: int, itens_existentes: int, pendencias_criadas: int, pendencias_existentes: int, pendencias_sem_atividade: int}
     */
    public function gravar(array $plano, bool $criarCursos, string $arquivo): array
    {
        $resumo = ['itens_criados' => 0, 'itens_existentes' => 0, 'pendencias_criadas' => 0, 'pendencias_existentes' => 0, 'pendencias_sem_atividade' => 0];
        $servico = app(CronogramaService::class);

        DB::transaction(function () use ($plano, $criarCursos, $servico, &$resumo) {
            if ($criarCursos) {
                foreach ($plano['colunas'] as $coluna) {
                    foreach ($coluna['cursos'] as $curso) {
                        if (! NomeCurso::estaEm($curso, Curso::nomesDisponiveis())) {
                            // Direto na tabela: a `cursos` do banco legado não tem `updated_at` (só `created_at`/`deleted_at`).
                            DB::table('cursos')->insertOrIgnore(['nome' => $curso]);
                        }
                    }
                }
            }

            $ids = [];
            foreach ($plano['itens'] as $i => $dados) {
                $existente = CronogramaItem::query()
                    ->whereDate('data', $dados['data'])->where('rotina', $dados['rotina'])
                    ->where('projeto', $dados['projeto'])->where('descricao', $dados['descricao'])->first();

                if ($existente !== null) {
                    $ids[$i] = $existente->id;
                    $resumo['itens_existentes']++;

                    continue;
                }

                $item = $servico->salvar(null, ['data' => $dados['data'], 'rotina' => $dados['rotina'], 'projeto' => $dados['projeto'], 'descricao' => $dados['descricao']], array_keys($dados['cursos']), null);
                foreach ($item->cursos()->get() as $curso) {
                    $curso->update(['status' => $dados['cursos'][$curso->curso]]);
                }
                $ids[$i] = $item->id;
                $resumo['itens_criados']++;
            }

            foreach ($plano['pendencias'] as $dados) {
                $itemId = $ids[$dados['item']] ?? null;
                if ($itemId === null) {
                    $resumo['pendencias_sem_atividade']++;

                    continue;
                }

                $ja = CronogramaPendencia::query()->where('item_id', $itemId)->where('curso', $dados['curso'])
                    ->whereDate('data', $dados['data'])->where('pendencia', $dados['pendencia'])->exists();
                if ($ja) {
                    $resumo['pendencias_existentes']++;

                    continue;
                }

                CronogramaPendencia::create([
                    'item_id' => $itemId, 'curso' => $dados['curso'], 'data' => $dados['data'], 'pendencia' => $dados['pendencia'],
                    'encaminhamento' => $dados['encaminhamento'], 'prazo' => $dados['prazo'], 'status' => $dados['status'], 'responsavel' => $dados['responsavel'],
                ]);
                $resumo['pendencias_criadas']++;
            }
        });

        AtividadeLogger::registrar('cronograma.importado', 'CronogramaItem', null, ['arquivo' => basename($arquivo), ...$resumo], 'CLI: cronograma:importar');

        return $resumo;
    }

    /**
     * Cabeçalho (coluna => texto) e as linhas com algum conteúdo (número da linha na planilha => coluna => valor).
     *
     * @return array{0: array<string, string>, 1: array<int, array<string, mixed>>}
     */
    private function linhas(Worksheet $aba): array
    {
        $cabecalho = [];
        $linhas = [];

        foreach ($aba->toArray(null, true, false, true) as $numero => $linha) {
            if ($numero === 1) {
                foreach ($linha as $coluna => $texto) {
                    $cabecalho[$coluna] = trim((string) $texto);
                }

                continue;
            }
            if (array_filter($linha, fn ($v) => $v !== null && trim((string) $v) !== '') !== []) {
                $linhas[$numero] = $linha;
            }
        }

        return [$cabecalho, $linhas];
    }

    /**
     * @param  array<string, string>  $colunas  coluna => cabeçalho (só as de curso)
     * @param  array<string, array<int, string>>  $mapa
     * @param  array<int, string>  $ignorar
     * @return array{mapa: array<string, array{coluna: string, cursos: array<int, string>, como: string}>, erros: array<int, string>}
     */
    private function mapearColunas(array $colunas, array $mapa, array $ignorar): array
    {
        $disponiveis = Curso::nomesDisponiveis();
        $explicito = [];
        foreach ($mapa as $coluna => $cursos) {
            $explicito[NomeCurso::chave($coluna)] = $cursos;
        }
        $ignoradas = array_map([NomeCurso::class, 'chave'], $ignorar);

        $resultado = [];
        $erros = [];

        foreach ($colunas as $letra => $cabecalho) {
            if ($cabecalho === '') {
                continue;
            }
            $chave = NomeCurso::chave($cabecalho);

            if (in_array($chave, $ignoradas, true)) {
                $resultado[$letra] = ['coluna' => $cabecalho, 'cursos' => [], 'como' => 'ignorada'];
            } elseif (isset($explicito[$chave])) {
                $resultado[$letra] = ['coluna' => $cabecalho, 'cursos' => array_values($explicito[$chave]), 'como' => 'mapa informado'];
            } elseif (($igual = $this->igual($cabecalho, $disponiveis)) !== null) {
                $resultado[$letra] = ['coluna' => $cabecalho, 'cursos' => [$igual], 'como' => 'mesmo nome'];
            } elseif (($parecido = $this->parecido($cabecalho, $disponiveis)) !== null) {
                $resultado[$letra] = ['coluna' => $cabecalho, 'cursos' => [$parecido], 'como' => 'nome parecido (provável erro de digitação na planilha)'];
            } else {
                $erros[] = "A coluna \"{$cabecalho}\" não corresponde a nenhum curso. Informe --mapa=\"{$cabecalho}=CURSO\" ou --ignorar=\"{$cabecalho}\".";
            }
        }

        return ['mapa' => $resultado, 'erros' => $erros];
    }

    /** @param  array<int, string>  $disponiveis */
    private function igual(string $nome, array $disponiveis): ?string
    {
        foreach ($disponiveis as $curso) {
            if (NomeCurso::mesmo($curso, $nome)) {
                return $curso;
            }
        }

        return null;
    }

    /**
     * Um único curso a no máximo 2 letras de distância (em nomes de 8+ letras): "Psicolgoia" → PSICOLOGIA. Se mais de um
     * curso for igualmente próximo, não adivinha.
     *
     * @param  array<int, string>  $disponiveis
     */
    private function parecido(string $nome, array $disponiveis): ?string
    {
        $chave = NomeCurso::chave($nome);
        $candidatos = [];
        foreach ($disponiveis as $curso) {
            $distancia = levenshtein($chave, NomeCurso::chave($curso));
            if ($distancia <= 2 && strlen($chave) >= 8) {
                $candidatos[$curso] = $distancia;
            }
        }
        asort($candidatos);

        $melhores = array_keys($candidatos, reset($candidatos) ?: null, true);

        return count($melhores) === 1 ? $melhores[0] : null;
    }

    /**
     * Uma linha do checklist → atividade com os cursos e a situação de cada um (null = linha descartada, com aviso).
     *
     * @param  array<string, mixed>  $linha
     * @param  array<string, string>  $cabecalho
     * @param  array<string, array{coluna: string, cursos: array<int, string>, como: string}>  $mapa
     * @param  array<int, string>  $avisos
     * @return ?array<string, mixed>
     */
    private function item(int $numero, array $linha, array $cabecalho, array $mapa, array &$avisos): ?array
    {
        [$colData, $colRotina, $colProjeto, $colDescricao] = array_slice(array_keys($cabecalho), 0, 4);
        $data = $this->data($linha[$colData] ?? null);
        $rotina = $this->rotina((string) ($linha[$colRotina] ?? ''));
        $projeto = $this->texto($linha[$colProjeto] ?? '');
        $descricao = $this->texto($linha[$colDescricao] ?? '');

        if ($data === null || $rotina === null || $projeto === '' || $descricao === '') {
            $avisos[] = "Linha {$numero} do checklist ignorada: falta data, rotina, projeto ou descrição.";

            return null;
        }

        $cursos = [];
        foreach ($mapa as $letra => $coluna) {
            $celula = $this->texto($linha[$letra] ?? '');
            if (NomeCurso::chave($celula) === NomeCurso::chave('Não se aplica')) {
                continue;
            }
            $status = $this->statusDoChecklist($celula);
            if ($status === null) {
                $avisos[] = "Linha {$numero}, coluna \"{$cabecalho[$letra]}\": situação \"{$celula}\" desconhecida — importada como Aguardando.";
                $status = CronogramaItem::AGUARDANDO;
            }
            foreach ($coluna['cursos'] as $curso) {
                $anterior = $cursos[$curso] ?? null;
                $cursos[$curso] = CronogramaItem::maisUrgente(array_filter([$anterior, $status]));
            }
        }

        if ($cursos === []) {
            $avisos[] = "Linha {$numero} do checklist ignorada: nenhum curso se aplica ({$rotina} · {$projeto}).";

            return null;
        }

        return ['linha' => $numero, 'data' => $data, 'rotina' => $rotina, 'projeto' => $projeto, 'descricao' => $descricao, 'cursos' => $cursos];
    }

    /**
     * A aba de pendências → pendências ligadas à atividade pela rotina + processo (projeto) e pelo curso. Se mais de uma
     * atividade servir, vale a mais recente que não passa da data do registro (senão, a primeira) e isso vira aviso.
     *
     * @param  array<string, array{coluna: string, cursos: array<int, string>, como: string}>  $mapa
     * @param  array<string, mixed>  $plano
     */
    private function pendencias(Worksheet $aba, array $mapa, array &$plano): void
    {
        [$cabecalho, $linhas] = $this->linhas($aba);
        $colunas = [];
        foreach ($cabecalho as $letra => $texto) {
            $colunas[NomeCurso::chave($texto)] = $letra;
        }
        $coluna = fn (string $nome) => $colunas[NomeCurso::chave($nome)] ?? null;
        [$cData, $cCurso, $cRotina, $cProcesso, $cPendencia, $cEncaminhamento, $cPrazo, $cStatus] = [$coluna('Data'), $coluna('Curso'), $coluna('Rotina'), $coluna('Processo'), $coluna('Pendência'), $coluna('Encaminhamento'), $coluna('Prazo'), $coluna('Status')];
        $cResponsavel = $coluna('Responsável') ?? $coluna('Resposável');

        if (in_array(null, [$cData, $cCurso, $cRotina, $cProcesso, $cPendencia], true)) {
            $plano['avisos'][] = 'A aba de pendências não tem as colunas esperadas (Data, Curso, Rotina, Processo, Pendência): nenhuma pendência importada.';

            return;
        }

        // Curso da planilha (qualquer coluna do checklist com esse nome) → cursos do sistema.
        $porNome = [];
        foreach ($plano['colunas'] as $dadosColuna) {
            $porNome[NomeCurso::chave($dadosColuna['coluna'])] = $dadosColuna['cursos'];
        }

        foreach ($linhas as $numero => $linha) {
            $pendencia = $this->texto($linha[$cPendencia] ?? '');
            if ($pendencia === '') {
                $plano['avisos'][] = "Linha {$numero} das pendências ignorada: sem texto de pendência.";

                continue;
            }

            $data = $this->data($linha[$cData] ?? null);
            $rotina = $this->rotina((string) ($linha[$cRotina] ?? ''));
            $processo = NomeCurso::chave($this->texto($linha[$cProcesso] ?? ''));
            $nomeCurso = $this->texto($linha[$cCurso] ?? '');
            $cursos = $porNome[NomeCurso::chave($nomeCurso)] ?? ($this->igual($nomeCurso, Curso::nomesDisponiveis()) !== null ? [$this->igual($nomeCurso, Curso::nomesDisponiveis())] : []);

            if ($data === null || $rotina === null || $processo === '' || $cursos === []) {
                $plano['avisos'][] = "Linha {$numero} das pendências ignorada: não deu para identificar a data, a rotina, o processo ou o curso \"{$nomeCurso}\".";

                continue;
            }

            foreach ($cursos as $curso) {
                $candidatas = array_keys(array_filter($plano['itens'], fn ($i) => $i['rotina'] === $rotina
                    && NomeCurso::chave($i['projeto']) === $processo && isset($i['cursos'][$curso])));

                if ($candidatas === []) {
                    $plano['avisos'][] = "Linha {$numero} das pendências ignorada: nenhuma atividade {$rotina} · {$this->texto($linha[$cProcesso])} se aplica a {$curso}.";

                    continue;
                }

                $escolhida = $candidatas[0];
                if (count($candidatas) > 1) {
                    // A mais recente que não passa da data do registro; sem nenhuma assim, a mais antiga.
                    usort($candidatas, fn ($a, $b) => $plano['itens'][$a]['data'] <=> $plano['itens'][$b]['data']);
                    $ate = array_filter($candidatas, fn ($i) => $plano['itens'][$i]['data'] <= $data);
                    $escolhida = $ate !== [] ? end($ate) : $candidatas[0];
                    $item = $plano['itens'][$escolhida];
                    $plano['avisos'][] = "Linha {$numero} das pendências: havia ".count($candidatas)." atividades {$rotina} · {$item['projeto']} para {$curso}; ligada a \"{$item['descricao']}\" ({$item['data']}).";
                }

                $plano['pendencias'][] = [
                    'linha' => $numero, 'item' => $escolhida, 'curso' => $curso, 'data' => $data, 'pendencia' => $pendencia,
                    'encaminhamento' => $this->texto($linha[$cEncaminhamento] ?? '') ?: null,
                    'prazo' => $this->data($linha[$cPrazo] ?? null),
                    'status' => $this->statusDaPendencia($this->texto($linha[$cStatus] ?? '')),
                    'responsavel' => $this->texto($linha[$cResponsavel] ?? '') ?: null,
                ];
            }
        }
    }

    private function statusDoChecklist(string $celula): ?string
    {
        return match (NomeCurso::chave($celula)) {
            '' => CronogramaItem::AGUARDANDO,
            'PENDENTE' => CronogramaItem::PENDENTE,
            'EM ACOMPANHAMENTO' => CronogramaItem::EM_ACOMPANHAMENTO,
            'RESOLVIDO' => CronogramaItem::RESOLVIDO,
            default => null,
        };
    }

    private function statusDaPendencia(string $celula): string
    {
        return match (NomeCurso::chave($celula)) {
            'EM ACOMPANHAMENTO' => CronogramaItem::EM_ACOMPANHAMENTO,
            'RESOLVIDO' => CronogramaItem::RESOLVIDO,
            default => CronogramaItem::PENDENTE,
        };
    }

    private function rotina(string $texto): ?string
    {
        foreach (array_keys(CronogramaItem::ROTINAS) as $rotina) {
            if (NomeCurso::chave($rotina) === NomeCurso::chave($texto)) {
                return $rotina;
            }
        }

        return null;
    }

    private function texto(mixed $valor): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $valor));
    }

    /** Célula de data do Excel (número de série) ou texto AAAA-MM-DD → "AAAA-MM-DD"; qualquer outra coisa, null. */
    private function data(mixed $valor): ?string
    {
        if (is_numeric($valor) && (float) $valor > 1) {
            return DataExcel::excelToDateTimeObject((float) $valor)->format('Y-m-d');
        }

        return is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($valor)) === 1 ? trim($valor) : null;
    }
}
