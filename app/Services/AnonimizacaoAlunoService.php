<?php

namespace App\Services;

use App\Models\Aluno;
use App\Support\AtividadeLogger;
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
 * `respostas`/`resultado_metricas` são atualizadas direto (`aluno_chave` é
 * coluna gerada — COALESCE(cpf, ra) — recalculada pelo próprio banco quando
 * ra/cpf mudam). `resultado_resumos` é puro cache de leitura — em vez de
 * editar na mão, é só chamar ResumoResultadoService::recalcular() de novo
 * pra cada avaliação afetada, do mesmo jeito que qualquer outra mudança
 * nessas tabelas já dispara (ver README "resultado_resumos como cache").
 */
class AnonimizacaoAlunoService
{
    public function __construct(private ResumoResultadoService $resumos) {}

    /** @return array{token: string, avaliacoes_afetadas: int} */
    public function anonimizar(?string $ra, ?string $cpf, ?string $origemSemAuth = null): array
    {
        $ra = $ra !== '' ? $ra : null;
        $cpf = $cpf !== '' ? $cpf : null;

        if ($ra === null && $cpf === null) {
            throw new InvalidArgumentException('Informe RA ou CPF do aluno a anonimizar.');
        }

        // Prefixo reconhecível pra quem olhar o banco depois entender que
        // aquele valor é um token de anonimização, não um RA de verdade.
        $token = 'ANON-'.Str::upper(Str::random(10));

        return DB::transaction(function () use ($ra, $cpf, $token, $origemSemAuth) {
            $avaliacoesAfetadas = collect();

            foreach (['respostas', 'resultado_metricas'] as $tabela) {
                $filtro = fn ($query) => $query->where(function ($query) use ($ra, $cpf) {
                    $query->when($ra !== null, fn ($q) => $q->orWhere('ra', $ra))
                        ->when($cpf !== null, fn ($q) => $q->orWhere('cpf', $cpf));
                });

                $avaliacoesAfetadas = $avaliacoesAfetadas->merge(
                    $filtro(DB::table($tabela))->distinct()->pluck('avaliacao_codigo')
                );

                // Sempre no campo `ra`, sempre com cpf nulo: unifica os dois
                // jeitos de identificar a mesma pessoa num token só, mesmo
                // que o histórico tenha linhas inconsistentes (algumas com
                // ra, outras com cpf) de imports diferentes ao longo do tempo.
                $filtro(DB::table($tabela))->update(['ra' => $token, 'cpf' => null, 'aluno_id' => null]);
            }

            foreach ($avaliacoesAfetadas->unique() as $avaliacaoCodigo) {
                $this->resumos->recalcular((int) $avaliacaoCodigo);
            }

            if ($cpf !== null) {
                DB::table('verificacoes_email')->where('cpf', $cpf)->delete();
            }

            $aluno = Aluno::query()
                ->where(function ($query) use ($ra, $cpf) {
                    $query->when($ra !== null, fn ($q) => $q->orWhere('ra', $ra))
                        ->when($cpf !== null, fn ($q) => $q->orWhere('cpf', $cpf));
                })
                ->first();

            if ($aluno !== null) {
                $aluno->delete();
            }

            AtividadeLogger::registrar('aluno.anonimizado', 'Aluno', $aluno?->id, [
                'ra' => $ra,
                'cpf' => $cpf,
                'token' => $token,
                'avaliacoes_afetadas' => $avaliacoesAfetadas->unique()->count(),
            ], $origemSemAuth);

            return ['token' => $token, 'avaliacoes_afetadas' => $avaliacoesAfetadas->unique()->count()];
        });
    }
}
