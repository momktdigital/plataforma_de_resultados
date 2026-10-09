<?php

namespace App\Models;

use App\Support\NomeCurso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Usuário do sistema (administrador ou coordenador). Mapeia a tabela `admins`
 * já existente na aplicação legada — mesmas credenciais, sem duplicar
 * cadastro de usuários.
 *
 * `role` é a coluna legada: 'superadmin' (acesso total; também é o que vale
 * para linhas sem role), 'coordinator' (vê só avaliações dos cursos a que
 * está vinculado — ver cursos()) ou 'rector' (reitor: só leitura, enxerga os
 * indicadores agregados de TODOS os cursos — nunca dado nominal de aluno) ou 'collaborator' (colaborador: monta o
 * cronograma de atividades dos coordenadores, registra as pendências e analisa os planos de ação que eles enviam; não enxerga
 * resultados nem alunos — o plano só traz números agregados do curso).
 */
class Admin extends Authenticatable
{
    protected $table = 'admins';

    public $timestamps = false;

    public const ROLE_ADMIN = 'superadmin';

    public const ROLE_COORDENADOR = 'coordinator';

    public const ROLE_REITOR = 'rector';

    public const ROLE_COLABORADOR = 'collaborator';

    protected $fillable = [
        'username',
        'email',
        'password_hash',
        'role',
        'curso',
    ];

    /** @var array<int, string>|null */
    private ?array $cursosCache = null;

    /** true só na cópia criada por comoCoordenadorDe(): o reitor olhando UM curso como o coordenador dele vê. */
    public bool $emVisaoDeCurso = false;

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
     * ROLE_COORDENADOR, ROLE_REITOR, ROLE_COLABORADOR, ou null quando o valor gravado não é nenhum deles. Null significa SEM acesso —
     * antes, qualquer valor diferente de "coordinator" (ex.: "Coordinator", "coord", um erro de digitação)
     * caía no ramo "não é coordenador" e virava administrador completo (falha aberta).
     */
    public function papel(): ?string
    {
        return match (strtolower(trim((string) $this->role))) {
            '', self::ROLE_ADMIN => self::ROLE_ADMIN,
            self::ROLE_COORDENADOR => self::ROLE_COORDENADOR,
            self::ROLE_REITOR => self::ROLE_REITOR,
            self::ROLE_COLABORADOR => self::ROLE_COLABORADOR,
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

    public function ehReitor(): bool
    {
        return $this->papel() === self::ROLE_REITOR;
    }

    public function ehColaborador(): bool
    {
        return $this->papel() === self::ROLE_COLABORADOR;
    }

    /** Nome da rota em que este usuário começa (depois do login e na raiz do sistema). */
    public function rotaInicial(): string
    {
        return match ($this->papel()) {
            self::ROLE_COORDENADOR => 'coordenador.painel',
            self::ROLE_REITOR => 'reitor.visao',
            self::ROLE_COLABORADOR => 'colaborador.index',
            default => 'avaliacoes.index',
        };
    }

    /**
     * Primeiro nome para a saudação do painel ("Bom dia, Matheus"). Não há coluna de nome na tabela legada: sai do
     * usuário (`matheus.oliveira`, `maria_souza`, `joao-silva@faa.edu.br` → Matheus, Maria, Joao). Usuário sem
     * letras devolve o usuário como está.
     */
    public function nomeParaSaudacao(): string
    {
        $usuario = trim((string) $this->username);
        $primeiro = preg_split('/[\s._\-@+]+/u', $usuario, -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';

        return preg_match('/\p{L}/u', $primeiro) === 1
            ? mb_convert_case(mb_strtolower($primeiro, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')
            : $usuario;
    }

    public function scopeCoordenadores(Builder $query): Builder
    {
        return $query->whereRaw('LOWER(TRIM(role)) = ?', [self::ROLE_COORDENADOR]);
    }

    /**
     * Cópia (só em memória, nunca salva) deste usuário com o perfil de coordenador dos cursos informados — é como o
     * reitor abre a visão de um curso: todas as telas e consultas do coordenador (painel, alunos, BI das avaliações do
     * curso) passam a valer, sem duplicar nada, e continuam SOMENTE LEITURA como para o coordenador. O id é o do
     * próprio reitor (a auditoria registra quem foi). Ver App\Http\Middleware\VisaoDeCursoDoReitor.
     *
     * @param  array<int, string>  $cursos
     */
    public function comoCoordenadorDe(array $cursos): static
    {
        $copia = new static;
        $copia->setRawAttributes($this->getAttributes(), true);
        $copia->exists = true;
        $copia->setAttribute('role', self::ROLE_COORDENADOR);
        $copia->setAttribute('curso', $cursos[0] ?? null);
        $copia->cursosCache = array_values($cursos);
        $copia->emVisaoDeCurso = true;

        return $copia;
    }

    public function scopeReitores(Builder $query): Builder
    {
        return $query->whereRaw('LOWER(TRIM(role)) = ?', [self::ROLE_REITOR]);
    }

    public function scopeColaboradores(Builder $query): Builder
    {
        return $query->whereRaw('LOWER(TRIM(role)) = ?', [self::ROLE_COLABORADOR]);
    }

    /** Quem entra por código enviado ao e-mail, sem senha obrigatória: coordenadores, reitores e colaboradores. */
    public function scopeEntramPorCodigo(Builder $query): Builder
    {
        return $query->whereRaw('LOWER(TRIM(role)) IN (?, ?, ?)', [self::ROLE_COORDENADOR, self::ROLE_REITOR, self::ROLE_COLABORADOR]);
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

    /**
     * Quantas notificações este usuário ainda não leu (o número do sino). Zero se a tabela ainda não existe
     * (migração pendente) — o menu nunca deve quebrar por causa disso.
     */
    public function notificacoesNaoLidas(): int
    {
        try {
            return Notificacao::doUsuario($this->id)->naoLidas()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Notificações não lidas mais recentes (para a visão geral). Vazio se a tabela ainda não existe (migração
     * pendente) — o painel não pode cair por causa dos avisos.
     *
     * @return Collection<int, Notificacao>
     */
    public function notificacoesRecentesNaoLidas(int $limite = 3): Collection
    {
        try {
            return Notificacao::doUsuario($this->id)->naoLidas()->orderByDesc('created_at')->orderByDesc('id')->limit($limite)->get();
        } catch (\Throwable) {
            return collect();
        }
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
