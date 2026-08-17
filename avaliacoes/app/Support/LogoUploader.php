<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Salva o logo enviado em Configurações → Portal público no MESMO diretório
 * físico usado pela aplicação legada (`assets/img/`, na raiz do
 * repositório) — não em `storage/` do Laravel — porque o valor gravado em
 * `configuracoes.site_logo` é um caminho relativo (`assets/img/xxx.png`)
 * consumido pelo portal público legado a partir do document root dele.
 * Reaproveita a mesma validação de `admin/configuracoes.php`: extensão na
 * whitelist, MIME real via finfo e integridade via getimagesize (exceto
 * SVG, que não é raster).
 */
class LogoUploader
{
    private const EXTENSOES_PERMITIDAS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    private const MIMES_PERMITIDOS = [
        'image/jpeg', 'image/png', 'image/gif',
        'image/webp', 'image/svg+xml', 'text/html', // SVG às vezes é detectado como text/html
    ];

    /** @return string Caminho relativo salvo (ex.: "assets/img/xxx.png"). */
    public static function salvar(UploadedFile $file, string $sufixo): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, self::EXTENSOES_PERMITIDAS, true)) {
            throw new RuntimeException('Tipo de arquivo inválido. Permitido: jpg, png, gif, webp, svg.');
        }

        $realMime = mime_content_type($file->getRealPath());

        if (! in_array($realMime, self::MIMES_PERMITIDOS, true)) {
            throw new RuntimeException("O conteúdo do arquivo não é uma imagem válida (MIME: {$realMime}).");
        }

        if ($ext !== 'svg' && $realMime !== 'image/svg+xml' && @getimagesize($file->getRealPath()) === false) {
            throw new RuntimeException('O arquivo não é uma imagem válida ou está corrompido.');
        }

        $uploadDir = base_path('../assets/img');
        if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0755, true) && ! is_dir($uploadDir)) {
            throw new RuntimeException('Não foi possível criar o diretório de destino (assets/img/).');
        }

        $nomeSeguro = time().'_'.$sufixo.'_'.bin2hex(random_bytes(6)).'.'.$ext;

        if (! $file->move($uploadDir, $nomeSeguro)) {
            throw new RuntimeException('Erro ao salvar o arquivo — verifique as permissões de assets/img/.');
        }

        return 'assets/img/'.$nomeSeguro;
    }
}
