<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Salva o gabarito comentado enviado como arquivo (alternativa a só colar um
 * link) em `public/uploads/gabaritos-comentados/`, servido direto pelo
 * Laravel — mesmo espírito do LogoUploader. Devolve a URL absoluta já pronta
 * pra gravar em `avaliacoes.link_comentado`, que é sempre renderizado como
 * href puro (`<a href="{{ $avaliacao->link_comentado }}">`) tanto no admin
 * quanto no portal — nenhuma tela precisa saber se veio de upload ou de link.
 */
class GabaritoComentadoUploader
{
    private const EXTENSOES_PERMITIDAS = ['pdf', 'doc', 'docx'];

    private const MIMES_PERMITIDOS = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        // .docx é um .zip por dentro — alguns servidores identificam assim.
        'application/zip',
    ];

    public static function salvar(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, self::EXTENSOES_PERMITIDAS, true)) {
            throw new RuntimeException('Tipo de arquivo inválido. Permitido: pdf, doc, docx.');
        }

        $realMime = mime_content_type($file->getRealPath());

        if (! in_array($realMime, self::MIMES_PERMITIDOS, true)) {
            throw new RuntimeException("O conteúdo do arquivo não corresponde a um documento válido (MIME: {$realMime}).");
        }

        $uploadDir = public_path('uploads/gabaritos-comentados');
        if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0755, true) && ! is_dir($uploadDir)) {
            throw new RuntimeException('Não foi possível criar o diretório de destino (public/uploads/gabaritos-comentados/).');
        }

        $nomeSeguro = time().'_'.bin2hex(random_bytes(6)).'.'.$ext;

        if (! $file->move($uploadDir, $nomeSeguro)) {
            throw new RuntimeException('Erro ao salvar o arquivo — verifique as permissões de public/uploads/gabaritos-comentados/.');
        }

        return asset('uploads/gabaritos-comentados/'.$nomeSeguro);
    }
}
