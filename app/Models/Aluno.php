<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cadastro de alunos, mantido pela aplicação legada.
 */
class Aluno extends Model
{
    protected $table = 'alunos';

    protected $fillable = [
        'ra',
        'cpf',
        'data_nascimento',
        'nome',
        'curso',
        'matriz',
        'campus',
        'email',
        'cod_perfil',
        'status',
        'periodo_letivo',
        'periodo',
        'turma',
        'cor_raca',
        'forma_ingresso',
        'religiao',
        'sexo',
        'estado_civil',
        'cidade',
        'uf',
        'celular',
    ];

    /** Histórico de matrículas (curso × período letivo) vindo das planilhas de matrícula. */
    public function matriculas(): HasMany
    {
        return $this->hasMany(AlunoMatricula::class);
    }

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
        ];
    }

    /**
     * E-mail institucional: sempre {RA}@somos.unifaa.edu.br — não é uma coluna
     * própria, é derivado do RA para nunca ficar dessincronizado dele.
     */
    protected function emailInstitucional(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ra ? "{$this->ra}@somos.unifaa.edu.br" : null,
        );
    }

    /**
     * E-mail para onde vai o código de verificação (2FA) do portal, conforme a
     * configuração do portal (`email_destino_2fa`): o e-mail PESSOAL cadastrado na
     * matrícula (padrão) ou o ACADÊMICO ({RA}@somos.unifaa.edu.br). Null quando o
     * escolhido não existe para este aluno (sem e-mail pessoal / sem RA).
     */
    public function emailParaCodigo(): ?string
    {
        $email = Configuracao::valor('email_destino_2fa', 'pessoal') === 'academico'
            ? $this->email_institucional
            : $this->email;

        return filled($email) ? trim($email) : null;
    }

    /**
     * URL da foto do aluno no sistema acadêmico (Jacad), a partir do Cód.
     * Perfil importado da matrícula. Sem Cód. Perfil, o aluno não tem foto.
     */
    public function fotoUrl(int $tamanho = 150): ?string
    {
        if (! $this->cod_perfil) {
            return null;
        }

        return "https://faa.jacad.com.br/academico/images/perfil-v2/{$this->cod_perfil}/{$tamanho}";
    }
}
