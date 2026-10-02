<?php

namespace App\Services;

use App\Models\Aluno;
use App\Support\AtividadeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Atende um pedido de exclusão/anonimização LGPD: apaga o cadastro de acesso
 * (`alunos`) — o que `AlunoController::destroy()` já faz sozinho — e, além
 * disso, o que `destroy()` NÃO faz: substitui RA/CPF por um token anônimo em
 * todo o histórico de respostas/métricas dessa pessoa, pra que ela deixe de
 * ser identificável mesmo nos dados que o sistema precisa manter (a
 * estatística agregada da avaliação continua íntegra — só troca de dono).
 *
 * QUEM É A PESSOA. O pedido traz RA e/ou CPF, mas o histórico guarda os dois de
 * jeitos diferentes: nos dados reais a maioria dos resultados foi importada só
 * com CPF (RA nulo). Por isso o cadastro (`alunos`) é consultado primeiro e os
 * identificadores são ampliados — RA informado ⇒ também o CPF do cadastro, e
 * vice-versa —, além do vínculo por `aluno_id`. O CPF informado pode vir
 * mascarado ("123.456.789-09"): só os dígitos contam, e as linhas são achadas
 * nas duas grafias.
 *
 * `respostas`/`resultado_metricas` são atualizadas direto (`aluno_chave` é
 * coluna gerada — COALESCE(cpf, ra) — recalculada pelo próprio banco quando
 * ra/cpf mudam). `resultado_resumos` é puro cache de leitura — em vez de
 * editar na mão, é só chamar ResumoResultadoService::recalcular() de novo
 * pra cada avaliação afetada, do mesmo jeito que qualquer outra mudança
 * nessas tabelas já dispara (ver README "resultado_resumos como cache"). O
 * CURSO de cada resultado (de onde o coordenador o enxerga) é guardado antes e
 * devolvido depois — sem isso a pessoa sumiria dos números do curso.
 *
 * TRILHA DE AUDITORIA. O registro `aluno.anonimizado` guarda só o token (nunca
 * RA/CPF), e os registros ANTIGOS de `atividades` que citam a pessoa (ex.:
 * `aluno_chave` em "respondente.excluido") têm o identificador trocado pelo
 * token. É a única edição permitida em `atividades`.
 */
class AnonimizacaoAlunoService
{
    public function __construct(private ResumoResultadoService $resumos) {}

    /** @return array{token: string, tokens: array<int, string>, avaliacoes_afetadas: int} */
    public function anonimizar(?string $ra, ?string $cpf, ?string $origemSemAuth = null): array
    {
        $ra = $ra !== null && trim($ra) !== '' ? trim($ra) : null;
        $cpf = $cpf !== null && ($digitos = preg_replace('/\D/', '', $cpf)) !== '' ? $digitos : null;

        if ($ra === null && $cpf === null) {
            throw new InvalidArgumentException('Informe RA ou CPF do aluno a anonimizar.');
        }

        return DB::transaction(function () use ($ra, $cpf, $origemSemAuth) {
            // Cadastros que batem com o que foi informado — e, deles, os identificadores que faltavam.
            $cadastros = Aluno::query()
                ->where(function ($q) use ($ra, $cpf) {
                    $q->when($ra !== null, fn ($q) => $q->orWhere('ra', $ra))
                        ->when($cpf !== null, fn ($q) => $q->orWhereIn('cpf', $this->grafiasDoCpf($cpf)));
                })
                ->get();

            $ras = collect([$ra])->merge($cadastros->pluck('ra'))->filter()->unique()->values()->all();
            $cpfs = collect([$cpf])
                ->merge($cadastros->pluck('cpf')->map(fn ($c) => $c !== null ? preg_replace('/\D/', '', $c) : null))
                ->filter()->unique()->values()->all();
            $cpfsEmQualquerGrafia = collect($cpfs)->flatMap(fn ($c) => $this->grafiasDoCpf($c))->unique()->values()->all();
            $alunoIds = $cadastros->pluck('id')->all();

            $filtro = fn ($query) => $query->where(function ($q) use ($ras, $cpfsEmQualquerGrafia, $alunoIds) {
                $q->when($ras !== [], fn ($q) => $q->orWhereIn('ra', $ras))
                    ->when($cpfsEmQualquerGrafia !== [], fn ($q) => $q->orWhereIn('cpf', $cpfsEmQualquerGrafia))
                    ->when($alunoIds !== [], fn ($q) => $q->orWhereIn('aluno_id', $alunoIds));
            });

            $avaliacoesAfetadas = collect();
            foreach (['respostas', 'resultado_metricas'] as $tabela) {
                $avaliacoesAfetadas = $avaliacoesAfetadas->merge($filtro(DB::table($tabela))->distinct()->pluck('avaliacao_codigo'));
            }
            $avaliacoesAfetadas = $avaliacoesAfetadas->unique()->values();

            // Curso de cada resultado da pessoa, para devolver depois do recálculo (chave original → curso).
            $cursos = $filtro(DB::table('resultado_resumos'))->whereNotNull('curso')
                ->get(['avaliacao_codigo', 'periodo', 'aluno_chave', 'curso']);

            // Um token por pessoa. Só se a mesma pessoa tem DUAS identificações (RA e CPF) respondendo a mesma
            // questão da mesma avaliação/período é que unificar violaria o índice único: nesse caso cada
            // identificação original ganha o seu token.
            $chaves = collect(['respostas', 'resultado_metricas'])
                ->flatMap(fn ($t) => $filtro(DB::table($t))->distinct()->pluck('aluno_chave'))
                ->unique()->values();
            $tokens = $this->tokensPorChave($chaves, $filtro);

            foreach (['respostas', 'resultado_metricas'] as $tabela) {
                // Sempre no campo `ra`, sempre com cpf nulo: unifica os dois jeitos de identificar a mesma
                // pessoa (a coluna aluno_chave = COALESCE(cpf, ra) passa a valer o token).
                foreach ($tokens as $chave => $token) {
                    $filtro(DB::table($tabela))
                        ->when((string) $chave !== '', fn ($q) => $q->where('aluno_chave', (string) $chave))
                        ->update(['ra' => $token, 'cpf' => null, 'aluno_id' => null]);
                }
            }

            foreach ($avaliacoesAfetadas as $avaliacaoCodigo) {
                $this->resumos->recalcular((int) $avaliacaoCodigo);
            }

            foreach ($cursos as $linha) {
                DB::table('resultado_resumos')
                    ->where('avaliacao_codigo', $linha->avaliacao_codigo)
                    ->where('periodo', $linha->periodo)
                    ->where('aluno_chave', $tokens[$linha->aluno_chave] ?? reset($tokens))
                    ->whereNull('curso')
                    ->update(['curso' => $linha->curso]);
            }

            if ($cpfsEmQualquerGrafia !== []) {
                DB::table('verificacoes_email')->whereIn('cpf', $cpfsEmQualquerGrafia)->delete();
            }

            Aluno::query()->whereIn('id', $alunoIds)->delete();

            $token = reset($tokens);
            $this->redigirTrilhaDeAuditoria($ras, $cpfs, $cpfsEmQualquerGrafia, $token);

            // Nunca o RA/CPF: a trilha de auditoria não pode reidentificar quem acabou de ser anonimizado.
            AtividadeLogger::registrar('aluno.anonimizado', 'Aluno', null, [
                'token' => $token,
                'avaliacoes_afetadas' => $avaliacoesAfetadas->count(),
                'cadastros_removidos' => count($alunoIds),
            ], $origemSemAuth);

            return [
                'token' => $token,
                'tokens' => array_values(array_unique($tokens)),
                'avaliacoes_afetadas' => $avaliacoesAfetadas->count(),
            ];
        });
    }

    /**
     * @param  Collection<int, string>  $chaves  aluno_chave original de cada linha da pessoa
     * @return array<string, string> chave original => token (todas com o mesmo token, salvo colisão)
     */
    private function tokensPorChave(Collection $chaves, \Closure $filtro): array
    {
        // Prefixo reconhecível pra quem olhar o banco depois entender que aquele valor é um token de
        // anonimização, não um RA de verdade.
        $novo = fn () => 'ANON-'.Str::upper(Str::random(10));
        $unico = $novo();

        if ($chaves->count() <= 1) {
            return [(string) ($chaves->first() ?? '') => $unico];
        }

        $colide = $filtro(DB::table('respostas'))
            ->groupBy('avaliacao_codigo', 'periodo', 'questao_numero')
            ->havingRaw('COUNT(DISTINCT aluno_chave) > 1')
            ->exists()
            || $filtro(DB::table('resultado_metricas'))
                ->groupBy('avaliacao_codigo', 'periodo', 'nome_metrica')
                ->havingRaw('COUNT(DISTINCT aluno_chave) > 1')
                ->exists();

        if (! $colide) {
            return $chaves->mapWithKeys(fn ($c) => [(string) $c => $unico])->all();
        }

        return $chaves->mapWithKeys(fn ($c, $i) => [(string) $c => $i === 0 ? $unico : $novo()])->all();
    }

    /** @return array<int, string> o CPF só com dígitos e com a máscara 000.000.000-00 */
    private function grafiasDoCpf(string $digitos): array
    {
        $digitos = preg_replace('/\D/', '', $digitos) ?? '';

        return strlen($digitos) === 11
            ? [$digitos, substr($digitos, 0, 3).'.'.substr($digitos, 3, 3).'.'.substr($digitos, 6, 3).'-'.substr($digitos, 9, 2)]
            : [$digitos];
    }

    /**
     * Troca RA/CPF da pessoa pelo token nos registros ANTIGOS de `atividades` (valor exatamente igual ao RA ou
     * ao CPF, ou CPF dentro de um texto). RA só casa por igualdade exata — um RA curto dentro de outro texto
     * não pode ser reescrito.
     *
     * @param  array<int, string>  $ras
     * @param  array<int, string>  $cpfs  só dígitos
     * @param  array<int, string>  $cpfsEmQualquerGrafia
     */
    private function redigirTrilhaDeAuditoria(array $ras, array $cpfs, array $cpfsEmQualquerGrafia, string $token): void
    {
        $identificadores = array_values(array_unique([...$ras, ...$cpfsEmQualquerGrafia]));
        if ($identificadores === []) {
            return;
        }

        $substituir = function ($valor) use (&$substituir, $ras, $cpfsEmQualquerGrafia, $token) {
            if (is_array($valor)) {
                return array_map($substituir, $valor);
            }
            if (! is_string($valor)) {
                return $valor;
            }
            if (in_array($valor, $ras, true) || in_array($valor, $cpfsEmQualquerGrafia, true)) {
                return $token;
            }

            return str_replace($cpfsEmQualquerGrafia, $token, $valor);
        };

        DB::table('atividades')
            ->where(function ($q) use ($identificadores) {
                foreach ($identificadores as $id) {
                    $q->orWhere('detalhes', 'like', '%'.addcslashes($id, '%_\\').'%')
                        ->orWhere('alvo_id', $id);
                }
            })
            ->eachById(function ($linha) use ($substituir, $ras, $cpfsEmQualquerGrafia, $token) {
                $detalhes = $linha->detalhes !== null ? json_decode($linha->detalhes, true) : null;
                $novoDetalhes = is_array($detalhes) ? $substituir($detalhes) : $detalhes;
                $novoAlvo = $linha->alvo_id !== null && (in_array($linha->alvo_id, $ras, true) || in_array($linha->alvo_id, $cpfsEmQualquerGrafia, true))
                    ? $token
                    : $linha->alvo_id;

                if ($novoDetalhes !== $detalhes || $novoAlvo !== $linha->alvo_id) {
                    DB::table('atividades')->where('id', $linha->id)->update([
                        'detalhes' => $novoDetalhes === null ? null : json_encode($novoDetalhes, JSON_UNESCAPED_UNICODE),
                        'alvo_id' => $novoAlvo,
                    ]);
                }
            });
    }
}
