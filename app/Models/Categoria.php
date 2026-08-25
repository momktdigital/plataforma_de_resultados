<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoria de Avaliação em árvore (categoria_pai_id aponta pra outra Categoria,
 * nulo = raiz). Usada para agrupar o boletim do aluno no portal público.
 */
class Categoria extends Model
{
    protected $fillable = [
        'nome',
        'categoria_pai_id',
    ];

    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'categoria_pai_id');
    }

    public function filhos(): HasMany
    {
        return $this->hasMany(self::class, 'categoria_pai_id')->orderBy('nome');
    }

    public function avaliacoes(): HasMany
    {
        return $this->hasMany(Avaliacao::class);
    }

    /** Caminho completo, ex.: "Simulados > 1º ao 4º período". */
    public function caminho(): string
    {
        return $this->pai ? $this->pai->caminho().' > '.$this->nome : $this->nome;
    }

    /**
     * Todas as categorias achatadas para um <select>, indentadas por
     * profundidade na árvore (ex.: "— Subcategoria"). Reaproveitado tanto
     * pelo formulário de categorias (escolher a categoria-mãe) quanto pelo
     * de Avaliações (escolher a categoria da avaliação).
     *
     * @return array<int, array{id: int, label: string}>
     */
    public static function opcoesSelect(): array
    {
        $porPai = self::orderBy('nome')->get()->groupBy('categoria_pai_id');

        $achatar = function (?int $paiId, int $profundidade) use (&$achatar, $porPai): array {
            $opcoes = [];

            foreach ($porPai->get($paiId, collect()) as $categoria) {
                $opcoes[] = ['id' => $categoria->id, 'label' => str_repeat('— ', $profundidade).$categoria->nome];
                $opcoes = array_merge($opcoes, $achatar($categoria->id, $profundidade + 1));
            }

            return $opcoes;
        };

        return $achatar(null, 0);
    }
}
