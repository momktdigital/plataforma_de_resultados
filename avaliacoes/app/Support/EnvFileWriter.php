<?php

namespace App\Support;

use RuntimeException;

/**
 * Atualiza pares chave=valor no arquivo `.env` sem depender de nenhum pacote
 * externo. Usado pelo wizard de instalação para gravar a conexão do banco
 * antes de o Laravel conseguir usar qualquer config vinda do banco.
 *
 * Cada requisição PHP relê o `.env` do zero (não há config:cache neste
 * fluxo), então gravar aqui e redirecionar para a próxima etapa é suficiente
 * para a mudança valer imediatamente.
 */
class EnvFileWriter
{
    public function __construct(private readonly string $path) {}

    /**
     * @param  array<string, string|null>  $valores
     */
    public function atualizar(array $valores): void
    {
        if (! is_writable(dirname($this->path)) || (file_exists($this->path) && ! is_writable($this->path))) {
            throw new RuntimeException("O arquivo {$this->path} não é gravável pelo servidor web.");
        }

        $conteudo = file_exists($this->path) ? file_get_contents($this->path) : '';
        $linhas = $conteudo === '' ? [] : explode("\n", $conteudo);

        foreach ($valores as $chave => $valor) {
            $linhaFormatada = $chave.'='.$this->formatarValor($valor);
            $encontrada = false;

            foreach ($linhas as $indice => $linha) {
                if (preg_match('/^'.preg_quote($chave, '/').'=/', $linha) === 1) {
                    $linhas[$indice] = $linhaFormatada;
                    $encontrada = true;

                    break;
                }
            }

            if (! $encontrada) {
                $linhas[] = $linhaFormatada;
            }
        }

        file_put_contents($this->path, implode("\n", $linhas));
    }

    private function formatarValor(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.\/:-]+$/', $valor) === 1) {
            return $valor;
        }

        return '"'.str_replace('"', '\\"', $valor).'"';
    }
}
