<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Notificacao;

/**
 * Avisos para os coordenadores quando chegam resultados de uma avaliação do curso deles: o que chegou (média e
 * presença), se a média caiu, se a presença foi baixa e QUEM entrou em atenção por causa dela.
 *
 * Quem recebe: todo coordenador que enxerga a avaliação (Avaliacao::visivelPara — curso em comum ou acesso
 * excepcional), e só com números do curso DELE (mesmo recorte do painel).
 *
 * Idempotente: cada aviso tem uma chave ("resultados:123"), então reimportar a mesma avaliação atualiza o aviso — e o
 * volta a "não lido" só se o conteúdo mudou — em vez de empilhar duplicados.
 *
 * "Entrou em atenção" é a diferença entre quem está em atenção COM a avaliação e SEM ela, no mesmo semestre.
 */
class NotificacaoCoordenadorService
{
    /** Presença abaixo disto numa avaliação gera aviso (mesmo corte do insight de presença do painel). */
    public const PRESENCA_BAIXA = 85.0;

    /** Queda da média (pontos) frente à avaliação anterior da mesma categoria que gera aviso. */
    public const QUEDA_MEDIA = 5.0;

    public function __construct(
        private readonly CoordenadorDashboardService $dashboard,
        private readonly CoordenadorAlunosService $alunos,
    ) {}

    /** @return int quantos avisos foram criados ou atualizados */
    public function gerarParaAvaliacao(int $avaliacaoCodigo): int
    {
        $avaliacao = Avaliacao::find($avaliacaoCodigo);
        if ($avaliacao === null || $avaliacao->estaAnulada()) {
            return 0;
        }

        $total = 0;
        foreach (Admin::coordenadores()->get() as $coordenador) {
            if (Avaliacao::visivelPara($coordenador)->whereKey($avaliacaoCodigo)->exists()) {
                $total += $this->gerarParaCoordenador($coordenador, $avaliacao);
            }
        }

        return $total;
    }

    private function gerarParaCoordenador(Admin $coordenador, Avaliacao $avaliacao): int
    {
        $codigo = (int) $avaliacao->codigo;

        $amplo = $this->dashboard->escopo($coordenador);
        $daAvaliacao = ($amplo['avaliacoes'] ?? collect())->firstWhere('codigo', $codigo);
        if ($daAvaliacao === null) {
            return 0; // nenhum aluno dos cursos dele fez esta avaliação
        }

        $periodo = $daAvaliacao['periodoLetivo'];
        $escopo = $this->dashboard->escopo($coordenador, '', $periodo);
        $painel = $this->dashboard->gerar($coordenador, '', $periodo, $escopo);

        $linha = collect($painel['categorias'] ?? [])->flatMap(fn ($c) => $c['avaliacoes'])->firstWhere('codigo', $codigo);
        if ($linha === null) {
            return 0;
        }

        $pct = CoordenadorDashboardService::pct(...);
        $nome = $linha['nome'];
        $criados = 0;

        $desempenho = route('coordenador.desempenho', ['periodo_letivo' => $periodo]);

        $texto = 'Presença de '.$pct($linha['presenca'])."% ({$linha['presentes']} de {$linha['inscritos']})";
        if ($linha['media'] !== null) {
            $texto .= ' e média de '.$pct($linha['media']).'%';
            if ($linha['delta'] !== null) {
                $texto .= ' ('.($linha['delta'] >= 0 ? '+' : '−').$pct(abs($linha['delta'])).' pontos frente a '.$linha['anterior']['nome'].')';
            }
        }
        $criados += $this->gravar($coordenador, "resultados:{$codigo}", 'resultados', "Novos resultados: {$nome}", $texto.'.', $desempenho);

        if ($linha['delta'] !== null && $linha['delta'] <= -self::QUEDA_MEDIA) {
            $criados += $this->gravar(
                $coordenador, "queda:{$codigo}", 'queda', "Média caiu em {$nome}",
                'A média foi de '.$pct($linha['anterior']['media']).'% para '.$pct($linha['media']).'% ('.'−'.$pct(abs($linha['delta']))." pontos) frente à avaliação anterior da mesma categoria ({$linha['anterior']['nome']}).",
                $desempenho,
            );
        }

        if ($linha['inscritos'] > 0 && $linha['presenca'] !== null && $linha['presenca'] < self::PRESENCA_BAIXA) {
            $criados += $this->gravar(
                $coordenador, "presenca:{$codigo}", 'presenca', "Presença baixa em {$nome}",
                'Só '.$pct($linha['presenca'])."% dos alunos compareceram: {$linha['inscritos']} esperados e ".($linha['inscritos'] - $linha['presentes']).' ausente(s).',
                $desempenho,
            );
        }

        $novos = $this->novosEmAtencao($escopo, $codigo);
        if ($novos !== []) {
            $quantos = count($novos);
            $nomes = array_map(fn ($a) => $a['nome'] ? mb_convert_case(mb_strtolower($a['nome'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : 'RA '.$a['ra'], array_slice($novos, 0, 3));
            $criados += $this->gravar(
                $coordenador, "atencao:{$codigo}", 'atencao',
                $quantos === 1 ? '1 aluno passou a precisar de atenção' : "{$quantos} alunos passaram a precisar de atenção",
                "Depois de {$nome}: ".implode(', ', $nomes).($quantos > 3 ? ' e mais '.($quantos - 3) : '').'.',
                route('coordenador.alunos', ['periodo_letivo' => $periodo, 'situacao' => 'atencao', 'ordem' => 'prioridade']),
            );
        }

        return $criados;
    }

    /**
     * Alunos que estão em atenção (ou ausentes em tudo) no semestre COM a avaliação e não estavam SEM ela.
     *
     * @param  array<string, mixed>  $escopo  semestre da avaliação
     * @return array<int, array<string, mixed>>
     */
    private function novosEmAtencao(array $escopo, int $codigo): array
    {
        $chave = fn (array $a) => $a['id'] !== null ? 'a'.$a['id'] : 'ra'.$a['ra'];
        $emAtencao = fn (array $lista) => collect($lista)->filter(fn ($a) => in_array($a['situacao'], ['atencao', 'ausente'], true))->keyBy($chave);

        // "Depois desta avaliação": só entram as do semestre feitas até a data dela (importar uma prova antiga, ou
        // gerar os avisos de dados que já existiam, não pode contar o que veio depois).
        $data = $escopo['doPeriodo']->firstWhere('codigo', $codigo)['data'] ?? null;
        $ateAqui = $escopo['doPeriodo']->filter(fn ($a) => $a['codigo'] === $codigo || $data === null || $a['data'] === null || $a['data'] <= $data)->values();
        $escopo = [...$escopo, 'doPeriodo' => $ateAqui];

        $agora = $emAtencao($this->alunos->alunos($escopo));

        $semEla = $escopo['doPeriodo']->reject(fn ($a) => $a['codigo'] === $codigo)->values();
        $antes = $semEla->isEmpty() ? collect() : $emAtencao($this->alunos->alunos([...$escopo, 'doPeriodo' => $semEla]));

        return $agora->diffKeys($antes)->values()->all();
    }

    /** Cria o aviso ou atualiza o existente; só reabre como "não lido" se o conteúdo mudou. 1 = criou/atualizou. */
    private function gravar(Admin $coordenador, string $chave, string $tipo, string $titulo, string $texto, string $url): int
    {
        $aviso = Notificacao::firstOrNew(['admin_id' => $coordenador->id, 'chave' => $chave]);

        if ($aviso->exists && $aviso->titulo === $titulo && $aviso->texto === $texto) {
            return 0;
        }

        $aviso->fill(['tipo' => $tipo, 'titulo' => $titulo, 'texto' => $texto, 'url' => $url, 'lida_em' => null]);
        if ($aviso->exists) {
            $aviso->created_at = now(); // volta para o topo da lista
        }
        $aviso->save();

        return 1;
    }
}
