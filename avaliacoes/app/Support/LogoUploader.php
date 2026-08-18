<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Salva o logo enviado em Configurações → Portal público em
 * `public/uploads/logos/`, servido diretamente pelo Laravel (sem storage
 * link nem controller dedicado). `configuracoes.site_logo` guarda o caminho
 * relativo devolvido aqui, mas só o basename dele é usado pra montar a URL
 * (`asset('uploads/logos/'.basename($caminho))`) — assim uma instalação
 * migrada de uma base com valores antigos (ex.: `assets/img/xxx.png`, do
 * layout usado pela aplicação legada) continua funcionando sem precisar
 * atualizar a tabela `configuracoes`.
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

        $uploadDir = public_path('uploads/logos');
        if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0755, true) && ! is_dir($uploadDir)) {
            throw new RuntimeException('Não foi possível criar o diretório de destino (public/uploads/logos/).');
        }

        $nomeSeguro = time().'_'.$sufixo.'_'.bin2hex(random_bytes(6)).'.'.$ext;

        if (! $file->move($uploadDir, $nomeSeguro)) {
            throw new RuntimeException('Erro ao salvar o arquivo — verifique as permissões de public/uploads/logos/.');
        }

        return 'uploads/logos/'.$nomeSeguro;
    }
}
