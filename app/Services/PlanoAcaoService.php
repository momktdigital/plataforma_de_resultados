<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Notificacao;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;
use App\Models\PlanoAcaoEvento;
use App\Support\AtividadeLogger;
use App\Support\NomeCurso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tudo que muda um plano de ação: criar e editar o rascunho, enviar para análise, a decisão do colaborador (aprovar,
 * pedir ajustes, recusar), o acompanhamento das ações, o encerramento. Cada mudança de estado grava um evento (o
 * histórico do plano) e a auditoria do sistema, e avisa o coordenador do curso quando a decisão é de outra pessoa.
 *
 * O fluxo:
 *
 *   rascunho ──enviar──▶ em_analise ──aprovar──▶ aprovado ──encerrar──▶ concluido
 *      ▲  ▲                 │  │                    └──cancelar──▶ cancelado
 *      │  └──retirar────────┘  └─recusar──▶ recusado (fim)
 *      └──── ajustes ◀──pedir ajustes─┘   (o coordenador edita e reenvia)
 *
 * Quem pode o quê é decidido pelos controllers (perfil e curso); aqui só vale a ordem das etapas — uma transição fora do
 * fluxo (decidir um plano que já foi decidido, por exemplo, com duas abas abertas) lança \DomainException.
 */
class PlanoAcaoService
{
    /** Decisões do colaborador → estado em que o plano fica. */
    public const DECISOES = [
        'aprovar' => PlanoAcao::APROVADO,
        'ajustes' => PlanoAcao::AJUSTES,
        'recusar' => PlanoAcao::RECUSADO,
    ];

    /** Critérios que o colaborador marca ao analisar (o que não for marcado aparece para o coordenador numa devolução). */
    public const CRITERIOS = [
        'dados' => 'O resultado a enfrentar está sustentado pelos dados do painel.',
        'causa' => 'A causa-raiz é específica, acionável e tem evidências.',
        'acoes' => 'As ações respondem à causa-raiz e mudam a experiência de aprendizagem.',
        'viabilidade' => 'Responsáveis e prazos são viáveis antes da próxima avaliação.',
        'verificacao' => 'Há como verificar a execução e os sinais de aprendizagem.',
    ];

    // ---------------------------------------------------------------------------------------------------------------
    // Rascunho
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $origem  saída de PlanoAcaoOrigemService::montar() (recalculada no servidor)
     * @param  array<string, mixed>  $dados  o conteúdo preenchido (ver preencher())
     */
    public function criar(Admin $autor, array $origem, array $dados): PlanoAcao
    {
        $ind = $origem['indicadores'] ?? null;

        return DB::transaction(function () use ($autor, $origem, $dados, $ind) {
            $plano = new PlanoAcao([
                'admin_id' => $autor->id,
                'curso' => $origem['curso'],
                'periodo_letivo' => (string) ($origem['periodo_letivo'] ?? ''),
                'categoria_id' => $origem['categoria_id'] ?? null,
                'avaliacao_codigo' => $origem['avaliacao_codigo'] ?? null,
                'origem_visual' => $origem['visual'],
                'origem_item' => $origem['item'] ?? null,
                'origem_rotulo' => $origem['rotulo'],
                'contexto' => [
                    'linhas' => $origem['linhas'] ?? [],
                    'categoria' => $origem['categoria_nome'] ?? null,
                    'categoria_automatica' => (bool) ($origem['categoria_automatica'] ?? false),
                    'recorte_indicadores' => $ind['recorte'] ?? null,
                    'corte' => $ind['corte'] ?? null,
                    'previstos' => $ind['previstos'] ?? null,
                    'fizeram' => $ind['fizeram'] ?? null,
                    'mistura' => (bool) ($ind['mistura'] ?? false),
                ],
                // A foto dos indicadores: a linha de base do plano. Não muda depois (o painel muda; o plano lembra
                // de onde partiu).
                'participacao_atual' => $ind['participacao'] ?? null,
                'meta_participacao' => $ind['meta_participacao'] ?? null,
                'proficiencia_atual' => $ind['proficiencia'] ?? null,
                'status' => PlanoAcao::RASCUNHO,
            ]);
            $this->preencher($plano, $dados);
            $plano->save();
            $this->sincronizarAcoes($plano, $dados['acoes'] ?? []);

            $this->registrar($plano, $autor, PlanoAcaoEvento::CRIADO, null, ['origem' => $plano->origem_rotulo]);
            AtividadeLogger::registrar('plano_acao.criado', 'PlanoAcao', $plano->id, ['curso' => $plano->curso, 'origem' => $plano->origem_rotulo]);

            return $plano;
        });
    }

    /** @param array<string, mixed> $dados */
    public function atualizar(PlanoAcao $plano, array $dados): PlanoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::RASCUNHO, PlanoAcao::AJUSTES], 'Este plano não pode mais ser editado.');

        return DB::transaction(function () use ($plano, $dados) {
            $this->preencher($plano, $dados);
            $plano->save();
            $this->sincronizarAcoes($plano, $dados['acoes'] ?? []);

            return $plano->unsetRelation('acoes');
        });
    }

    /** Copia um plano já encerrado (ou recusado) como NOVO rascunho, com indicadores recalculados a partir de `$origem`. */
    public function duplicar(PlanoAcao $modelo, Admin $autor, array $origem): PlanoAcao
    {
        $dados = $modelo->only([
            'recorte', 'resultado', 'fragilidades', 'evidencias', 'causas', 'causa_priorizada', 'nota_impacto', 'nota_evidencia',
            'nota_governabilidade', 'porques', 'causa_raiz', 'meta_proficiencia',
        ]);
        // Prazo e situação não se herdam: o novo plano é de outro ciclo.
        $dados['acoes'] = $modelo->acoes->reject(fn (PlanoAcaoAcao $a) => $a->status === PlanoAcaoAcao::CANCELADA)
            ->map(fn (PlanoAcaoAcao $a) => ['descricao' => $a->descricao, 'execucao' => $a->execucao, 'responsavel' => $a->responsavel, 'verificacao' => $a->verificacao])
            ->values()->all();

        $plano = $this->criar($autor, $origem, $dados);
        $this->registrar($plano, $autor, PlanoAcaoEvento::COMENTARIO, "Criado a partir do plano #{$modelo->id}.");

        return $plano;
    }

    /** Só rascunho some de verdade: o que já foi enviado fica no histórico (cancele em vez de apagar). */
    public function excluir(PlanoAcao $plano): void
    {
        $this->exigirEstado($plano, [PlanoAcao::RASCUNHO], 'Só um rascunho pode ser excluído.');
        AtividadeLogger::registrar('plano_acao.excluido', 'PlanoAcao', $plano->id, ['curso' => $plano->curso, 'origem' => $plano->origem_rotulo]);
        $plano->delete();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Envio e decisão
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Envia ao colaborador. Nenhuma etapa é obrigatória: o que estiver em branco aparece como lacuna
     * (PlanoAcaoChecagem::lacunas) para quem analisa, mas não impede o envio.
     */
    public function enviar(PlanoAcao $plano, Admin $autor): PlanoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::RASCUNHO, PlanoAcao::AJUSTES], 'Este plano não pode ser enviado agora.');

        $reenvio = $plano->envios > 0;
        $plano->forceFill([
            'status' => PlanoAcao::EM_ANALISE,
            'envios' => $plano->envios + 1,
            'enviado_em' => now(),
            'decidido_em' => null,
            'decidido_por' => null,
        ])->save();

        $this->registrar($plano, $autor, $reenvio ? PlanoAcaoEvento::REENVIADO : PlanoAcaoEvento::ENVIADO, null, ['envio' => $plano->envios]);
        AtividadeLogger::registrar('plano_acao.enviado', 'PlanoAcao', $plano->id, ['curso' => $plano->curso, 'envio' => $plano->envios]);

        return $plano;
    }

    /** O coordenador desiste do envio enquanto ninguém decidiu: volta a ser rascunho. */
    public function retirar(PlanoAcao $plano, Admin $autor): PlanoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::EM_ANALISE], 'O plano não está em análise.');

        $plano->forceFill(['status' => PlanoAcao::RASCUNHO])->save();
        $this->registrar($plano, $autor, PlanoAcaoEvento::RETIRADO);
        AtividadeLogger::registrar('plano_acao.retirado', 'PlanoAcao', $plano->id, ['curso' => $plano->curso]);

        return $plano;
    }

    /**
     * A decisão do colaborador. Pedir ajustes e recusar exigem justificativa (o coordenador precisa saber o que mudar ou por
     * quê não); aprovar aceita uma observação opcional.
     *
     * @param  'aprovar'|'ajustes'|'recusar'  $decisao
     * @param  array<int, string>  $criteriosAtendidos  chaves de CRITERIOS marcadas
     */
    public function decidir(PlanoAcao $plano, Admin $revisor, string $decisao, ?string $justificativa, array $criteriosAtendidos = []): PlanoAcao
    {
        if (! isset(self::DECISOES[$decisao])) {
            throw new \InvalidArgumentException("Decisão desconhecida: {$decisao}");
        }
        $this->exigirEstado($plano, [PlanoAcao::EM_ANALISE], 'Este plano não está aguardando análise (talvez já tenha sido decidido).');

        $justificativa = trim((string) $justificativa);
        if ($decisao !== 'aprovar' && mb_strlen($justificativa) < 10) {
            throw ValidationException::withMessages(['justificativa' => [
                $decisao === 'ajustes'
                    ? 'Explique o que o coordenador precisa ajustar (mínimo de 10 caracteres).'
                    : 'Justifique a recusa (mínimo de 10 caracteres).',
            ]]);
        }

        $criterios = [];
        foreach (self::CRITERIOS as $chave => $texto) {
            $criterios[$chave] = in_array($chave, $criteriosAtendidos, true);
        }

        $novo = self::DECISOES[$decisao];
        $plano->forceFill(['status' => $novo, 'decidido_em' => now(), 'decidido_por' => $revisor->id])->save();

        $tipo = ['aprovar' => PlanoAcaoEvento::APROVADO, 'ajustes' => PlanoAcaoEvento::AJUSTES, 'recusar' => PlanoAcaoEvento::RECUSADO][$decisao];
        $evento = $this->registrar($plano, $revisor, $tipo, $justificativa !== '' ? $justificativa : null, ['criterios' => $criterios]);
        AtividadeLogger::registrar('plano_acao.'.$tipo, 'PlanoAcao', $plano->id, ['curso' => $plano->curso, 'decisao' => $decisao]);

        [$titulo, $tipoAviso] = match ($decisao) {
            'aprovar' => ['Plano de ação aprovado', 'plano_aprovado'],
            'ajustes' => ['Plano de ação devolvido para ajustes', 'plano_ajustes'],
            'recusar' => ['Plano de ação recusado', 'plano_recusado'],
        };
        $this->avisar($plano, $tipoAviso, "{$titulo}: {$plano->origem_rotulo}", $justificativa !== '' ? Str::limit($justificativa, 280) : 'O plano foi aprovado e já pode ser executado e acompanhado.', "plano:{$plano->id}:decisao:{$evento->id}");

        return $plano;
    }

    /** Comentário solto no plano (sem decisão). Quando vem do colaborador, o coordenador é avisado. */
    public function comentar(PlanoAcao $plano, Admin $autor, string $texto): PlanoAcaoEvento
    {
        $evento = $this->registrar($plano, $autor, PlanoAcaoEvento::COMENTARIO, trim($texto));

        if (! $autor->ehCoordenador()) {
            $this->avisar($plano, 'plano', "Novo comentário no plano: {$plano->origem_rotulo}", Str::limit(trim($texto), 280), "plano:{$plano->id}:comentario:{$evento->id}");
        }

        return $evento;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Acompanhamento
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Atualiza uma ação de um plano em execução: a situação, o prazo e/ou uma nota de andamento.
     *  - concluir exige a nota (o que foi feito e a evidência);
     *  - mudar o prazo exige a nota (por que foi reprogramado) e não pode ir para o passado;
     *  - sem mudar nada, a nota vira um registro de andamento.
     *
     * @param  array{status?: ?string, prazo?: ?string, nota?: ?string}  $dados
     */
    public function atualizarAcao(PlanoAcao $plano, PlanoAcaoAcao $acao, Admin $autor, array $dados): PlanoAcaoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::APROVADO], 'As ações só são acompanhadas enquanto o plano está em execução.');

        $nota = trim((string) ($dados['nota'] ?? ''));
        $novoStatus = ($dados['status'] ?? '') !== '' ? (string) $dados['status'] : $acao->status;
        $novoPrazo = ($dados['prazo'] ?? '') !== '' ? Carbon::parse((string) $dados['prazo'])->startOfDay() : $acao->prazo;

        if (! isset(PlanoAcaoAcao::STATUS[$novoStatus])) {
            throw ValidationException::withMessages(['status' => ['Situação inválida.']]);
        }
        $mudouStatus = $novoStatus !== $acao->status;
        $mudouPrazo = ($novoPrazo?->toDateString()) !== ($acao->prazo?->toDateString());

        if (! $mudouStatus && ! $mudouPrazo && $nota === '') {
            throw ValidationException::withMessages(['nota' => ['Informe a nova situação, um novo prazo ou escreva uma nota de andamento.']]);
        }
        if ($mudouStatus && $novoStatus === PlanoAcaoAcao::CONCLUIDA && mb_strlen($nota) < 10) {
            throw ValidationException::withMessages(['nota' => ['Para concluir, descreva o que foi feito e a evidência (mínimo de 10 caracteres).']]);
        }
        if ($mudouPrazo && mb_strlen($nota) < 10) {
            throw ValidationException::withMessages(['nota' => ['Explique por que o prazo foi reprogramado (mínimo de 10 caracteres).']]);
        }
        if ($mudouPrazo && $novoPrazo->isBefore(today())) {
            throw ValidationException::withMessages(['prazo' => ['O novo prazo não pode estar no passado.']]);
        }

        DB::transaction(function () use ($plano, $acao, $autor, $nota, $novoStatus, $novoPrazo, $mudouStatus, $mudouPrazo) {
            $de = ['status' => $acao->status, 'prazo' => $acao->prazo?->toDateString()];
            $acao->forceFill([
                'status' => $novoStatus,
                'prazo' => $novoPrazo,
                'concluida_em' => $novoStatus === PlanoAcaoAcao::CONCLUIDA ? ($acao->concluida_em ?? now()) : null,
            ])->save();

            if ($mudouStatus) {
                $this->registrar($plano, $autor, PlanoAcaoEvento::ACAO_STATUS, $nota !== '' ? $nota : null, ['de' => $de['status'], 'para' => $novoStatus], $acao->id);
            }
            if ($mudouPrazo) {
                $this->registrar($plano, $autor, PlanoAcaoEvento::PRAZO, $nota, ['de' => $de['prazo'], 'para' => $novoPrazo->toDateString()], $acao->id);
            }
            if (! $mudouStatus && ! $mudouPrazo) {
                $this->registrar($plano, $autor, PlanoAcaoEvento::ANDAMENTO, $nota, null, $acao->id);
            }
        });

        return $acao;
    }

    /** Fecha o plano: todas as ações concluídas (ou canceladas) e uma síntese do que foi feito e aprendido. */
    public function encerrar(PlanoAcao $plano, Admin $autor, string $conclusao): PlanoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::APROVADO], 'Só um plano em execução pode ser encerrado.');

        $conclusao = trim($conclusao);
        if (mb_strlen($conclusao) < 10) {
            throw ValidationException::withMessages(['conclusao' => ['Escreva a síntese do que foi feito e aprendido (mínimo de 10 caracteres).']]);
        }
        if ($plano->acoes()->whereIn('status', [PlanoAcaoAcao::NAO_INICIADA, PlanoAcaoAcao::EM_ANDAMENTO])->exists()) {
            throw ValidationException::withMessages(['conclusao' => ['Ainda há ações abertas: conclua ou cancele todas antes de encerrar o plano.']]);
        }

        $plano->forceFill(['status' => PlanoAcao::CONCLUIDO, 'encerrado_em' => now(), 'conclusao' => $conclusao])->save();
        $this->registrar($plano, $autor, PlanoAcaoEvento::ENCERRADO, $conclusao);
        AtividadeLogger::registrar('plano_acao.encerrado', 'PlanoAcao', $plano->id, ['curso' => $plano->curso]);

        return $plano;
    }

    /** Cancela um plano em execução ou devolvido para ajustes (com o motivo). */
    public function cancelar(PlanoAcao $plano, Admin $autor, string $motivo): PlanoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::APROVADO, PlanoAcao::AJUSTES], 'Este plano não pode ser cancelado agora.');

        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 10) {
            throw ValidationException::withMessages(['motivo' => ['Explique o motivo do cancelamento (mínimo de 10 caracteres).']]);
        }

        $plano->forceFill(['status' => PlanoAcao::CANCELADO, 'encerrado_em' => now()])->save();
        $this->registrar($plano, $autor, PlanoAcaoEvento::CANCELADO, $motivo);
        AtividadeLogger::registrar('plano_acao.cancelado', 'PlanoAcao', $plano->id, ['curso' => $plano->curso]);

        return $plano;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Contadores do menu
    // ---------------------------------------------------------------------------------------------------------------

    /** Quantos planos aguardam a análise do colaborador (o número do menu). Zero se a tabela ainda não existe. */
    public static function aguardandoAnalise(): int
    {
        try {
            return PlanoAcao::where('status', PlanoAcao::EM_ANALISE)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Quantos planos do coordenador foram devolvidos para ajustes (pedem ação dele). Roda em TODA página do coordenador
     * (o número do menu): por isso lê só os cursos dos planos devolvidos (poucos) e compara em PHP, em vez de resolver as
     * grafias do curso no cadastro de alunos a cada requisição.
     */
    public static function comAjustes(Admin $coordenador): int
    {
        try {
            $cursos = $coordenador->cursos();

            return PlanoAcao::where('status', PlanoAcao::AJUSTES)->pluck('curso')->filter(fn ($curso) => NomeCurso::estaEm($curso, $cursos))->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Internos
    // ---------------------------------------------------------------------------------------------------------------

    /** @param array<int, string> $permitidos */
    private function exigirEstado(PlanoAcao $plano, array $permitidos, string $mensagem): void
    {
        if (! in_array($plano->status, $permitidos, true)) {
            throw new \DomainException($mensagem);
        }
    }

    /**
     * Copia o conteúdo preenchido para o plano (sem salvar). Textos vazios viram null; notas só de 1 a 3; só as dimensões
     * de Ishikawa conhecidas; até 5 porquês.
     *
     * @param  array<string, mixed>  $dados
     */
    private function preencher(PlanoAcao $plano, array $dados): void
    {
        $texto = fn ($v) => ($t = trim((string) $v)) === '' ? null : $t;

        foreach (['recorte', 'resultado', 'fragilidades', 'evidencias', 'causa_priorizada', 'causa_raiz'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $plano->{$campo} = $texto($dados[$campo]);
            }
        }

        foreach (['nota_impacto', 'nota_evidencia', 'nota_governabilidade'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $nota = (int) $dados[$campo];
                $plano->{$campo} = $nota >= 1 && $nota <= 3 ? $nota : null;
            }
        }

        // A meta de participação é a institucional (vem do painel, não se edita); só a de proficiência é pactuada pelo coordenador.
        if (array_key_exists('meta_proficiencia', $dados)) {
            $plano->meta_proficiencia = ($v = str_replace(',', '.', trim((string) $dados['meta_proficiencia']))) === '' ? null : round((float) $v, 1);
        }

        if (array_key_exists('data_proxima_avaliacao', $dados)) {
            $plano->data_proxima_avaliacao = $texto($dados['data_proxima_avaliacao']);
        }

        if (array_key_exists('causas', $dados)) {
            $causas = [];
            foreach (PlanoAcao::DIMENSOES as $chave => $rotulo) {
                if (($t = $texto($dados['causas'][$chave] ?? null)) !== null) {
                    $causas[$chave] = $t;
                }
            }
            $plano->causas = $causas === [] ? null : $causas;
        }

        if (array_key_exists('porques', $dados)) {
            $porques = array_values(array_filter(array_map($texto, array_slice((array) $dados['porques'], 0, 5))));
            $plano->porques = $porques === [] ? null : $porques;
        }
    }

    /**
     * Deixa as ações do plano exatamente como informado: linha com `id` atualiza a existente, sem `id` cria, e a que sumiu
     * da lista é removida (só enquanto o plano é editável: depois de aprovado, as ações não saem).
     *
     * @param  array<int, array<string, mixed>>  $linhas
     */
    private function sincronizarAcoes(PlanoAcao $plano, array $linhas): void
    {
        $existentes = $plano->acoes()->get()->keyBy('id');
        $mantidos = [];
        $ordem = 0;

        foreach ($linhas as $linha) {
            $descricao = trim((string) ($linha['descricao'] ?? ''));
            $vazia = $descricao === '' && trim((string) ($linha['execucao'] ?? '')) === '' && trim((string) ($linha['responsavel'] ?? '')) === '' && trim((string) ($linha['verificacao'] ?? '')) === '';
            if ($vazia) {
                continue;
            }

            $valores = [
                'ordem' => ++$ordem,
                'descricao' => $descricao,
                'execucao' => ($t = trim((string) ($linha['execucao'] ?? ''))) === '' ? null : $t,
                'responsavel' => ($t = trim((string) ($linha['responsavel'] ?? ''))) === '' ? null : mb_substr($t, 0, 150),
                'prazo' => ($t = trim((string) ($linha['prazo'] ?? ''))) === '' ? null : $t,
                'verificacao' => ($t = trim((string) ($linha['verificacao'] ?? ''))) === '' ? null : $t,
            ];

            $id = (int) ($linha['id'] ?? 0);
            if ($id > 0 && $existentes->has($id)) {
                $existentes[$id]->update($valores);
                $mantidos[] = $id;
            } else {
                $mantidos[] = $plano->acoes()->create($valores)->id;
            }
        }

        $existentes->keys()->diff($mantidos)->each(fn ($id) => $existentes[$id]->delete());
    }

    /** @param array<string, mixed>|null $dados */
    private function registrar(PlanoAcao $plano, ?Admin $quem, string $tipo, ?string $texto = null, ?array $dados = null, ?int $acaoId = null): PlanoAcaoEvento
    {
        return PlanoAcaoEvento::create([
            'plano_id' => $plano->id,
            'acao_id' => $acaoId,
            'admin_id' => $quem?->id,
            'tipo' => $tipo,
            'texto' => $texto,
            'dados' => $dados,
        ]);
    }

    /**
     * Aviso (o sino) para todo coordenador do curso do plano; idempotente pela chave. `$reabrir = false` (lembretes
     * diários) só cria o aviso na primeira vez: rodar de novo não volta para "não lido" o que ele já leu.
     *
     * @return int quantos avisos foram criados ou atualizados
     */
    public function avisar(PlanoAcao $plano, string $tipo, string $titulo, string $texto, string $chave, bool $reabrir = true): int
    {
        $url = route('coordenador.planos.show', $plano);
        $total = 0;

        foreach (Admin::coordenadores()->get() as $coordenador) {
            if (! NomeCurso::estaEm($plano->curso, $coordenador->cursos())) {
                continue;
            }

            $chaves = ['admin_id' => $coordenador->id, 'chave' => $chave];
            $valores = ['tipo' => $tipo, 'titulo' => Str::limit($titulo, 190, '…'), 'texto' => $texto, 'url' => $url, 'lida_em' => null];
            $aviso = $reabrir ? Notificacao::updateOrCreate($chaves, $valores) : Notificacao::firstOrCreate($chaves, $valores);
            $total += $reabrir || $aviso->wasRecentlyCreated ? 1 : 0;
        }

        return $total;
    }
}
