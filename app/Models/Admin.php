<?php

namespace App\Models;

use App\Support\NomeCurso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Usuário do sistema (administrador ou coordenador). Mapeia a tabela `admins`
 * já existente na aplicação legada — mesmas credenciais, sem duplicar
 * cadastro de usuários.
 *
 * `role` é a coluna legada: 'superadmin' (acesso total; também é o que vale
 * para linhas sem role) ou 'coordinator' (vê só avaliações dos cursos a que
 * está vinculado — ver cursos()).
 */
class Admin extends Authenticatable
{
    protected $table = 'admins';

    public $timestamps = false;

    public const ROLE_ADMIN = 'superadmin';

    public const ROLE_COORDENADOR = 'coordinator';

    protected $fillable = [
        'username',
        'email',
        'password_hash',
        'role',
        'curso',
    ];

    /** @var array<int, string>|null */
    private ?array $cursosCache = null;

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            // $timestamps = false (sem updated_at) já impede o Eloquent de
            // castear created_at automaticamente — sem isto, a tela de
            // Administradores quebra ao chamar ->format() numa string crua.
            'created_at' => 'datetime',
        ];
    }

    /**
     * Perfil normalizado (caixa/espaços não distinguem): ROLE_ADMIN (inclui linhas legadas sem role),
     * ROLE_COORDENADOR, ou null quando o valor gravado não é nenhum dos dois. Null significa SEM acesso —
     * antes, qualquer valor diferente de "coordinator" (ex.: "Coordinator", "coord", um erro de digitação)
     * caía no ramo "não é coordenador" e virava administrador completo (falha aberta).
     */
    public function papel(): ?string
    {
        return match (strtolower(trim((string) $this->role))) {
            '', self::ROLE_ADMIN => self::ROLE_ADMIN,
            self::ROLE_COORDENADOR => self::ROLE_COORDENADOR,
            default => null,
        };
    }

    public function temPapelValido(): bool
    {
        return $this->papel() !== null;
    }

    public function ehAdministrador(): bool
    {
        return $this->papel() === self::ROLE_ADMIN;
    }

    public function ehCoordenador(): bool
    {
        return $this->papel() === self::ROLE_COORDENADOR;
    }

    public function scopeCoordenadores(Builder $query): Builder
    {
        return $query->whereRaw('LOWER(TRIM(role)) = ?', [self::ROLE_COORDENADOR]);
    }

    /** Administradores de fato: `superadmin` ou linha legada sem role. Perfil desconhecido não entra aqui. */
    public function scopeAdministradores(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('role')
            ->orWhereRaw("TRIM(role) = ''")
            ->orWhereRaw('LOWER(TRIM(role)) = ?', [self::ROLE_ADMIN]));
    }

    /**
     * Cursos (nomes, como em alunos.curso) a que o coordenador está vinculado.
     *
     * @return array<int, string>
     */
    public function cursos(): array
    {
        if ($this->cursosCache !== null) {
            return $this->cursosCache;
        }

        $cursos = DB::table('admin_cursos')->where('admin_id', $this->id)->orderBy('curso')->pluck('curso')->all();

        // Conta legada com um único `curso` e sem linhas na tabela nova.
        if ($cursos === [] && $this->curso) {
            $cursos = [$this->curso];
        }

        return $this->cursosCache = $cursos;
    }

    /** @param array<int, string> $cursos */
    public function sincronizarCursos(array $cursos): void
    {
        $cursos = NomeCurso::semRepetidos($cursos);

        DB::transaction(function () use ($cursos) {
            DB::table('admin_cursos')->where('admin_id', $this->id)->delete();
            if ($cursos !== []) {
                DB::table('admin_cursos')->insert(array_map(fn ($c) => ['admin_id' => $this->id, 'curso' => $c], $cursos));
            }
            // Legado só conhece um curso por coordenador: guarda o primeiro.
            DB::table('admins')->where('id', $this->id)->update(['curso' => $cursos[0] ?? null]);
        });

        $this->cursosCache = null;
    }

    /** Avaliações às quais este usuário recebeu acesso excepcional. */
    public function avaliacoesComAcessoExcepcional(): BelongsToMany
    {
        return $this->belongsToMany(Avaliacao::class, 'avaliacao_usuarios', 'admin_id', 'avaliacao_codigo', 'id', 'codigo');
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * Não há coluna `remember_token` na tabela legada — desativa "lembrar-me".
     */
    public function getRememberTokenName(): string
    {
        return '';
    }
}
