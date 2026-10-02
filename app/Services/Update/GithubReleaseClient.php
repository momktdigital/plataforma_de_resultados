<?php

namespace App\Services\Update;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Consulta a última Release publicada no GitHub (repositório público — sem
 * autenticação). Usado pelo atualizador para saber se há uma versão nova e se a
 * tag dela tem assinatura verificada pelo GitHub.
 *
 * O repositório vem só do `.env` (ATUALIZACAO_REPOSITORIO): quem entra no painel
 * como administrador não escolhe de onde o servidor baixa e EXECUTA código.
 */
class GithubReleaseClient
{
    public function __construct(private readonly string $repositorio)
    {
        if (preg_match('#^[\w.-]+/[\w.-]+$#', $repositorio) !== 1) {
            throw new InvalidArgumentException('ATUALIZACAO_REPOSITORIO precisa estar no formato "owner/repositorio".');
        }
    }

    public function repositorio(): string
    {
        return $this->repositorio;
    }

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

        // O pacote só é baixado por HTTPS — nunca por um endereço http:// vindo da resposta.
        if (! str_starts_with((string) $dados['zipball_url'], 'https://')) {
            return null;
        }

        return [
            'tag' => $dados['tag_name'],
            'notas' => $dados['body'] ?? '',
            'zip_url' => $dados['zipball_url'],
        ];
    }

    /**
     * Assinatura do commit da tag, como o GitHub a verificou (GPG/SSH/S-MIME associada a uma conta). Só diz que
     * ALGUÉM com uma chave cadastrada no GitHub assinou — por isso `assinante` (login do autor/committer) existe
     * para ser comparado com uma lista de pessoas confiáveis (ATUALIZACAO_ASSINANTES).
     *
     * @return array{verificada: bool, motivo: string, assinante: ?string}
     */
    public function assinatura(string $tag): array
    {
        $resposta = Http::withHeaders(['Accept' => 'application/vnd.github+json'])
            ->timeout(15)
            ->get("https://api.github.com/repos/{$this->repositorio}/commits/".rawurlencode($tag));

        if ($resposta->failed()) {
            return ['verificada' => false, 'motivo' => 'consulta_falhou', 'assinante' => null];
        }

        $dados = $resposta->json();

        return [
            'verificada' => ($dados['commit']['verification']['verified'] ?? false) === true,
            'motivo' => (string) ($dados['commit']['verification']['reason'] ?? 'desconhecido'),
            'assinante' => $dados['committer']['login'] ?? ($dados['author']['login'] ?? null),
        ];
    }
}
