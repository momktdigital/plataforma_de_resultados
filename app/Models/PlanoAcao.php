<?php

namespace App\Models;

use App\Support\NomeCurso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plano de ação de um curso, iniciado pelo coordenador a partir de um dado do painel (ver a migration
 * `create_planos_acao_tables`). Percorre: rascunho → em análise (enviado ao colaborador) → aprovado (em execução) →
 * concluído; o colaborador pode pedir ajustes (volta ao coordenador) ou recusar (fim).
 *
 * A mudança de estado passa SEMPRE por PlanoAcaoService (que grava o evento e avisa quem precisa saber); este modelo só
 * descreve o plano e responde "o que posso fazer agora?".
 */
class PlanoAcao extends Model
{
    protected $table = 'planos_acao';

    public const RASCUNHO = 'rascunho';

    public const EM_ANALISE = 'em_analise';

    public const AJUSTES = 'ajustes';

    public const APROVADO = 'aprovado';

    public const RECUSADO = 'recusado';

    public const CONCLUIDO = 'concluido';

    public const CANCELADO = 'cancelado';

    /** @var array<string, string> */
    public const STATUS = [
        self::RASCUNHO => 'Rascunho',
        self::EM_ANALISE => 'Em análise',
        self::AJUSTES => 'Ajustes solicitados',
        self::APROVADO => 'Em execução',
        self::RECUSADO => 'Recusado',
        self::CONCLUIDO => 'Concluído',
        self::CANCELADO => 'Cancelado',
    ];

    /**
     * Os visuais do painel de onde um plano pode nascer: rótulo e ícone (Phosphor). A chave vai na URL do botão "iniciar
     * plano de ação" e fica gravada em `origem_visual`.
     *
     * @var array<string, array{rotulo: string, icone: string}>
     */
    public const VISUAIS = [
        'geral' => ['rotulo' => 'Visão geral do curso', 'icone' => 'ph-squares-four'],
        'participacao' => ['rotulo' => 'Participação', 'icone' => 'ph-user-check'],
        'proficiencia' => ['rotulo' => 'Proficiência', 'icone' => 'ph-target'],
        'evolucao' => ['rotulo' => 'Evolução entre as avaliações', 'icone' => 'ph-chart-line-up'],
        'area' => ['rotulo' => 'Desempenho por área', 'icone' => 'ph-chart-polar'],
        'bloom' => ['rotulo' => 'Desempenho por nível de Bloom', 'icone' => 'ph-brain'],
        'tema' => ['rotulo' => 'Desempenho por tema', 'icone' => 'ph-bookmarks'],
        'periodo_curso' => ['rotulo' => 'Desempenho por período do curso', 'icone' => 'ph-graduation-cap'],
        'curso' => ['rotulo' => 'Comparativo entre cursos', 'icone' => 'ph-books'],
        'avaliacao' => ['rotulo' => 'Avaliação', 'icone' => 'ph-exam'],
        'destaque' => ['rotulo' => 'Destaque do painel', 'icone' => 'ph-lightbulb'],
    ];

    /**
     * As dimensões do diagrama de Ishikawa (espinha de peixe) sugeridas ao NDE: onde procurar a causa de um resultado.
     *
     * @var array<string, string>
     */
    public const DIMENSOES = [
        'curriculo' => 'Currículo em execução',
        'ensino' => 'Práticas de ensino',
        'avaliacao' => 'Avaliação',
        'aprendizagem' => 'Aprendizagem e engajamento dos estudantes',
        'docentes' => 'Docentes',
        'gestao' => 'Condições de gestão e infraestrutura',
    ];

    protected $fillable = [
        'admin_id', 'curso', 'periodo_letivo', 'categoria_id', 'avaliacao_codigo', 'origem_visual', 'origem_item', 'origem_rotulo', 'contexto',
        'participacao_atual', 'meta_participacao', 'proficiencia_atual', 'meta_proficiencia', 'data_proxima_avaliacao',
        'recorte', 'resultado', 'fragilidades', 'evidencias',
        'causas', 'causa_priorizada', 'nota_impacto', 'nota_evidencia', 'nota_governabilidade', 'porques', 'causa_raiz',
        'status', 'envios', 'enviado_em', 'decidido_em', 'decidido_por', 'encerrado_em', 'conclusao',
    ];

    protected function casts(): array
    {
        return [
            'contexto' => 'array',
            'causas' => 'array',
            'porques' => 'array',
            'participacao_atual' => 'float',
            'meta_participacao' => 'float',
            'proficiencia_atual' => 'float',
            'meta_proficiencia' => 'float',
            'data_proxima_avaliacao' => 'date',
            'enviado_em' => 'datetime',
            'decidido_em' => 'datetime',
            'encerrado_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<Admin, $this> */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /** @return BelongsTo<Admin, $this> */
    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'decidido_por');
    }

    /** @return HasMany<PlanoAcaoAcao, $this> */
    public function acoes(): HasMany
    {
        return $this->hasMany(PlanoAcaoAcao::class, 'plano_id')->orderBy('ordem')->orderBy('id');
    }

    /** @return HasMany<PlanoAcaoEvento, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(PlanoAcaoEvento::class, 'plano_id')->orderByDesc('id');
    }

    /** @return HasMany<PlanoAcaoAnexo, $this> */
    public function anexos(): HasMany
    {
        return $this->hasMany(PlanoAcaoAnexo::class, 'plano_id')->orderBy('id');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Quem enxerga o quê
    // ---------------------------------------------------------------------------------------------------------------

    /** Só os planos dos cursos informados (o recorte do coordenador; acento e caixa não distinguem cursos). */
    public function scopeDosCursos(Builder $query, array $cursos): Builder
    {
        return $cursos === [] ? $query->whereRaw('1 = 0') : $query->whereIn('curso', NomeCurso::variantes($cursos));
    }

    /** Planos que já foram enviados ao menos uma vez: o rascunho é privado do coordenador. */
    public function scopeEnviados(Builder $query): Builder
    {
        return $query->where('status', '!=', self::RASCUNHO);
    }

    /**
     * O que este usuário pode abrir: o coordenador, os planos dos cursos dele (inclusive rascunhos); o colaborador e o
     * administrador, os planos enviados de qualquer curso (analisam e acompanham); qualquer outro perfil, nada. O reitor
     * só chega aqui na visão do curso, onde Admin::comoCoordenadorDe() o torna o coordenador daquele curso.
     */
    public function scopeVisivelPara(Builder $query, Admin $usuario): Builder
    {
        if ($usuario->ehCoordenador()) {
            return $query->dosCursos($usuario->cursos());
        }

        if ($usuario->ehColaborador() || $usuario->ehAdministrador()) {
            return $query->enviados();
        }

        return $query->whereRaw('1 = 0');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Estado
    // ---------------------------------------------------------------------------------------------------------------

    public function rotuloStatus(): string
    {
        return self::STATUS[$this->status] ?? (string) $this->status;
    }

    /** O coordenador ainda pode mexer no conteúdo (rascunho, ou devolvido para ajustes). */
    public function editavel(): bool
    {
        return in_array($this->status, [self::RASCUNHO, self::AJUSTES], true);
    }

    public function aguardandoAnalise(): bool
    {
        return $this->status === self::EM_ANALISE;
    }

    /** Aprovado e em andamento: as ações são acompanhadas. */
    public function emExecucao(): bool
    {
        return $this->status === self::APROVADO;
    }

    /** Já terminou (não muda mais): recusado, concluído ou cancelado. */
    public function finalizado(): bool
    {
        return in_array($this->status, [self::RECUSADO, self::CONCLUIDO, self::CANCELADO], true);
    }

    /** Impacto × Evidência × Governabilidade (1 a 27), ou null se alguma nota falta. */
    public function pontuacao(): ?int
    {
        $notas = [$this->nota_impacto, $this->nota_evidencia, $this->nota_governabilidade];

        return in_array(null, $notas, true) ? null : (int) array_product($notas);
    }

    /**
     * Andamento das ações (as canceladas não contam): quantas, quantas concluídas e o percentual.
     *
     * @return array{total: int, concluidas: int, pct: int, atrasadas: int}
     */
    public function progresso(): array
    {
        $validas = $this->acoes->reject(fn (PlanoAcaoAcao $a) => $a->status === PlanoAcaoAcao::CANCELADA);
        $concluidas = $validas->filter(fn (PlanoAcaoAcao $a) => $a->status === PlanoAcaoAcao::CONCLUIDA)->count();

        return [
            'total' => $validas->count(),
            'concluidas' => $concluidas,
            'pct' => $validas->count() > 0 ? (int) round($concluidas / $validas->count() * 100) : 0,
            'atrasadas' => $validas->filter(fn (PlanoAcaoAcao $a) => $a->estaAtrasada())->count(),
        ];
    }

    /** Data da última movimentação (evento mais recente), para saber se um plano em execução está parado. */
    public function ultimaMovimentacao(): ?\Illuminate\Support\Carbon
    {
        return $this->eventos->first()?->created_at ?? $this->updated_at;
    }

    /**
     * As avaliações a que o plano se refere (a lista guardada na criação; planos antigos sem lista caem na avaliação de
     * origem, se houver), para o link de cada uma.
     *
     * @return array<int, array{codigo: int, nome: string, data: ?string, periodoLetivo: ?string}>
     */
    public function avaliacoesDoRecorte(): array
    {
        $lista = $this->contexto['avaliacoes'] ?? [];
        if ($lista === [] && $this->avaliacao_codigo !== null && ($a = Avaliacao::find($this->avaliacao_codigo)) !== null) {
            $lista = [['codigo' => (int) $a->codigo, 'nome' => $a->nome ?: "Avaliação #{$a->codigo}", 'data' => $a->data_avaliacao?->format('Y-m-d'), 'periodoLetivo' => null]];
        }

        return $lista;
    }

    /**
     * A foto do conteúdo do plano (o que o coordenador preenche, sem os indicadores). O PlanoAcaoService a guarda no evento de
     * cada envio, para mostrar ao colaborador "o que mudou" quando o plano volta de uma devolução.
     *
     * @return array<string, mixed>
     */
    public function conteudo(): array
    {
        return [
            ...$this->only(['meta_proficiencia', 'recorte', 'resultado', 'fragilidades', 'evidencias', 'causas', 'causa_priorizada', 'nota_impacto', 'nota_evidencia', 'nota_governabilidade', 'porques', 'causa_raiz']),
            'data_proxima_avaliacao' => $this->data_proxima_avaliacao?->format('d/m/Y'),
            'acoes' => $this->acoes()->get()->map(fn (PlanoAcaoAcao $a) => [
                'id' => $a->id, 'descricao' => $a->descricao, 'execucao' => $a->execucao, 'responsavel' => $a->responsavel,
                'prazo' => $a->prazo?->format('d/m/Y'), 'verificacao' => $a->verificacao,
            ])->all(),
        ];
    }

    /** Em execução, com ações abertas e sem nenhuma movimentação há PlanoAcaoLembreteService::DIAS_PARADO dias ou mais. */
    public function estaParado(): bool
    {
        $ultima = $this->ultimaMovimentacao();

        return $this->emExecucao()
            && $ultima !== null
            && $ultima->diffInDays(now()) >= \App\Services\PlanoAcaoLembreteService::DIAS_PARADO
            && $this->acoes->contains(fn (PlanoAcaoAcao $a) => $a->estaAberta());
    }
}
