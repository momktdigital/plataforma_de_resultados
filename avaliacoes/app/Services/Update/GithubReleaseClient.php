<?php

namespace App\Services\Update;

use Illuminate\Support\Facades\Http;

/**
 * Consulta a última Release publicada no GitHub (repositório público — sem
 * autenticação). Usado pelo atualizador para saber se há uma versão nova.
 */
class GithubReleaseClient
{
    public function __construct(private readonly string $repositorio) {}

    /** @return array{tag: string, notas: string, zip_url: string}|null */
    public function ultimaRelease(): ?array
    {
        $resposta = Http::withHeaders(['Accept' => 'application/vnd.github+json'])
            ->timeout(15)
            ->get("https://api.github.com/repos/{$this->repositorio}/releases/latest");

        if ($resposta->failed()) {
            return null;
        }

        $dados = $resposta->json();

        if (! isset($dados['tag_name'], $dados['zipball_url'])) {
            return null;
        }

        return [
            'tag' => $dados['tag_name'],
            'notas' => $dados['body'] ?? '',
            'zip_url' => $dados['zipball_url'],
        ];
    }
}
