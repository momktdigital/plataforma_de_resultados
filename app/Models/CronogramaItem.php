<?php

namespace App\Models;

use App\Support\NomeCurso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Uma atividade do cronograma (linha do "Checklist de Auditoria"): numa data, de uma rotina (ROD, ROC ou Auditoria),
 * para um projeto, com o que conferir. Os cursos a que se aplica ficam em `cursos` (cada um com a sua situação).
 */
class CronogramaItem extends Model
{
    protected $table = 'cronograma_itens';

    public const ROD = 'ROD';

    public const ROC = 'ROC';

    public const AUDITORIA = 'Auditoria';

    /** @var array<string, string> rotina => de quem é a etapa (como na planilha: docentes postam, coordenação confere, auditoria fecha) */
    public const ROTINAS = [
        self::ROD => 'Docentes',
        self::ROC => 'Coordenação',
        self::AUDITORIA => 'Conferência final',
    ];

    public const AGUARDANDO = 'aguardando';

    public const EM_ACOMPANHAMENTO = 'em_acompanhamento';

    public const PENDENTE = 'pendente';

    public const RESOLVIDO = 'resolvido';

    /** @var array<string, string> situação de um curso na atividade (o curso fora da lista é "não se aplica") */
    public const STATUS = [
        self::AGUARDANDO => 'Aguardando',
        self::EM_ACOMPANHAMENTO => 'Em acompanhamento',
        self::PENDENTE => 'Pendente',
        self::RESOLVIDO => 'Resolvido',
    ];

    /** Da situação que mais exige atenção para a que menos: o resumo de vários cursos mostra a primeira que aparecer. */
    private const PRIORIDADE = [self::PENDENTE, self::EM_ACOMPANHAMENTO, self::AGUARDANDO, self::RESOLVIDO];

    protected $fillable = ['data', 'rotina', 'projeto', 'descricao', 'criado_por'];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    /** @return HasMany<CronogramaCurso, $this> */
    public function cursos(): HasMany
    {
        return $this->hasMany(CronogramaCurso::class, 'item_id')->orderBy('curso');
    }

    /** @return HasMany<CronogramaPendencia, $this> */
    public function pendencias(): HasMany
    {
        return $this->hasMany(CronogramaPendencia::class, 'item_id')->orderByDesc('data')->orderByDesc('id');
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'criado_por');
    }

    /**
     * Os cursos da atividade que estão na lista informada (acento/caixa não distinguem cursos). `null` = todos
     * (visão do colaborador). Usa `cursos` já carregado quando houver — evita uma consulta por atividade no calendário.
     *
     * @param  ?array<int, string>  $cursos
     * @return Collection<int, CronogramaCurso>
     */
    public function cursosDe(?array $cursos): Collection
    {
        $todos = $this->cursos;

        return $cursos === null ? $todos : $todos->filter(fn (CronogramaCurso $c) => NomeCurso::estaEm($c->curso, $cursos))->values();
    }

    /**
     * A situação que resume os cursos considerados (ver PRIORIDADE), ou null se nenhum curso se aplica.
     *
     * @param  ?array<int, string>  $cursos
     */
    public function resumoStatus(?array $cursos = null): ?string
    {
        return self::maisUrgente($this->cursosDe($cursos)->pluck('status')->all());
    }

    /**
     * A situação que mais exige atenção entre as informadas (pendente > em acompanhamento > aguardando > resolvido).
     *
     * @param  array<int, string>  $status
     */
    public static function maisUrgente(array $status): ?string
    {
        foreach (self::PRIORIDADE as $candidata) {
            if (in_array($candidata, $status, true)) {
                return $candidata;
            }
        }

        return null;
    }

    public static function rotuloDoStatus(?string $status): string
    {
        return self::STATUS[$status] ?? (string) $status;
    }
}
