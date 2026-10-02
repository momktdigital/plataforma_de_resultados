<?php

namespace App\Models;

use App\Support\NomeCurso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Uma avaliação (simulado, exame institucional...). O código é gerado
 * automaticamente — não existe cadastro manual de identificador.
 */
class Avaliacao extends Model
{
    use SoftDeletes;

    protected $table = 'avaliacoes';

    protected $primaryKey = 'codigo';

    protected $fillable = [
        'nome',
        'tipo',
        'link_comentado',
        'criado_por',
        'categoria_id',
        'data_avaliacao',
        'status',
        'meta_acerto_dificuldade',
    ];

    // Espelha o default da coluna no banco — sem isso, uma instância recém-
    // criada em memória (Avaliacao::create([])) fica com status null até um
    // refresh(), já que Eloquent não lê de volta o DEFAULT do SQL sozinho.
    protected $attributes = [
        'status' => 'ativa',
    ];

    protected function casts(): array
    {
        return [
            'data_avaliacao' => 'date',
            'meta_acerto_dificuldade' => 'array',
        ];
    }

    /** Meta de % de acerto (0–100) para um nível de App\Support\Dificuldade, ou null se não definida. */
    public function metaAcertoDificuldade(string $nivel): ?float
    {
        $valor = $this->meta_acerto_dificuldade[$nivel] ?? null;

        return is_numeric($valor) ? (float) $valor : null;
    }

    /**
     * Restringe a consulta às avaliações que o usuário pode ver: administrador
     * vê todas; coordenador vê as que têm aluno de algum dos seus cursos
     * (avaliacao_cursos) ou em que recebeu acesso excepcional
     * (avaliacao_usuarios).
     */
    public function scopeVisivelPara(Builder $query, Admin $usuario): Builder
    {
        if (! $usuario->ehCoordenador()) {
            return $query;
        }

        // Todas as grafias do mesmo curso (ADMINISTRACAO = ADMINISTRAÇÃO).
        $cursos = NomeCurso::variantes($usuario->cursos());

        return $query->where(function (Builder $q) use ($usuario, $cursos) {
            $q->whereIn('avaliacoes.codigo', DB::table('avaliacao_cursos')->whereIn('curso', $cursos)->select('avaliacao_codigo'))
                ->orWhereIn('avaliacoes.codigo', DB::table('avaliacao_usuarios')->where('admin_id', $usuario->id)->select('avaliacao_codigo'));
        });
    }

    public function acessivelPara(Admin $usuario): bool
    {
        return static::query()->whereKey($this->getKey())->visivelPara($usuario)->exists();
    }

    /** @return array<int, string> nomes dos cursos marcados nesta avaliação */
    public function cursos(): array
    {
        return DB::table('avaliacao_cursos')->where('avaliacao_codigo', $this->codigo)->orderBy('curso')->pluck('curso')->all();
    }

    /** @param array<int, string> $cursos */
    public function sincronizarCursos(array $cursos): void
    {
        $cursos = NomeCurso::semRepetidos($cursos);

        // Tela de edição da avaliação: o que está marcado vale. Curso que já
        // existia mantém a origem (auto continua auto); curso novo é MANUAL —
        // fica protegido de ser apagado pelas importações.
        DB::transaction(function () use ($cursos) {
            $existentes = DB::table('avaliacao_cursos')->where('avaliacao_codigo', $this->codigo)->get();
            $marcadas = array_map([NomeCurso::class, 'chave'], $cursos);

            foreach ($existentes as $existente) {
                if (! in_array(NomeCurso::chave($existente->curso), $marcadas, true)) {
                    DB::table('avaliacao_cursos')->where('avaliacao_codigo', $this->codigo)->where('curso', $existente->curso)->delete();
                }
            }

            $jaTem = $existentes->map(fn ($e) => NomeCurso::chave($e->curso))->all();
            $novos = array_filter($cursos, fn ($c) => ! in_array(NomeCurso::chave($c), $jaTem, true));
            if ($novos !== []) {
                DB::table('avaliacao_cursos')->insert(array_map(
                    fn ($c) => ['avaliacao_codigo' => $this->codigo, 'curso' => $c, 'origem' => 'manual'],
                    array_values($novos),
                ));
            }
        });
    }

    /** Usuários com acesso excepcional aos resultados desta avaliação. */
    public function usuariosComAcesso(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'avaliacao_usuarios', 'avaliacao_codigo', 'admin_id', 'codigo', 'id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function questoes(): HasMany
    {
        return $this->hasMany(Questao::class, 'avaliacao_codigo', 'codigo');
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(Resposta::class, 'avaliacao_codigo', 'codigo');
    }

    public function metricas(): HasMany
    {
        return $this->hasMany(ResultadoMetrica::class, 'avaliacao_codigo', 'codigo');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'criado_por');
    }

    public function visualizacoes(): HasMany
    {
        return $this->hasMany(AvaliacaoVisualizacao::class, 'avaliacao_codigo', 'codigo');
    }
}
