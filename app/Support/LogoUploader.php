<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
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

    /**
     * SVG NÃO entra aqui de propósito — é XML validado/sanitizado à parte em
     * sanitizarSvg(), nunca só por MIME sniffing (ver comentário lá).
     */
    private const MIMES_PERMITIDOS = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Elementos removidos inteiros de um SVG — todos capazes de embutir/rodar script. */
    private const TAGS_BLOQUEADAS = ['script', 'foreignobject', 'iframe', 'object', 'embed'];

    /** Atributos que podem carregar uma URI executável (javascript:, vbscript:...). */
    private const ATRIBUTOS_URI = ['href', 'xlink:href', 'src'];

    /** @return string Caminho relativo salvo (ex.: "assets/img/xxx.png"). */
    public static function salvar(UploadedFile $file, string $sufixo): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, self::EXTENSOES_PERMITIDAS, true)) {
            throw new RuntimeException('Tipo de arquivo inválido. Permitido: jpg, png, gif, webp, svg.');
        }

        $uploadDir = public_path('uploads/logos');
        if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0755, true) && ! is_dir($uploadDir)) {
            throw new RuntimeException('Não foi possível criar o diretório de destino (public/uploads/logos/).');
        }

        $nomeSeguro = time().'_'.$sufixo.'_'.bin2hex(random_bytes(6)).'.'.$ext;
        $destino = $uploadDir.'/'.$nomeSeguro;

        if ($ext === 'svg') {
            self::salvarSvg($file, $destino);

            return 'uploads/logos/'.$nomeSeguro;
        }

        $realMime = mime_content_type($file->getRealPath());

        if (! in_array($realMime, self::MIMES_PERMITIDOS, true)) {
            throw new RuntimeException("O conteúdo do arquivo não é uma imagem válida (MIME: {$realMime}).");
        }

        if (@getimagesize($file->getRealPath()) === false) {
            throw new RuntimeException('O arquivo não é uma imagem válida ou está corrompido.');
        }

        if (! $file->move($uploadDir, $nomeSeguro)) {
            throw new RuntimeException('Erro ao salvar o arquivo — verifique as permissões de public/uploads/logos/.');
        }

        return 'uploads/logos/'.$nomeSeguro;
    }

    private static function salvarSvg(UploadedFile $file, string $destino): void
    {
        $conteudo = file_get_contents($file->getRealPath());

        if ($conteudo === false) {
            throw new RuntimeException('Não foi possível ler o arquivo enviado.');
        }

        $sanitizado = self::sanitizarSvg($conteudo);

        if (file_put_contents($destino, $sanitizado) === false) {
            throw new RuntimeException('Erro ao salvar o arquivo — verifique as permissões de public/uploads/logos/.');
        }
    }

    /**
     * SVG é XML — pode carregar <script>, atributos on* (onload, onerror...) e
     * um DOCTYPE com <!ENTITY> customizada (XXE). Servido do mesmo domínio do
     * admin/portal, um SVG malicioso plantado por um admin comum executaria
     * ao ser aberto direto (não só embutido como <img>). Em vez de confiar no
     * MIME sniffed do upload (SVG às vezes sai como text/html, que também
     * deixaria HTML/JS arbitrário passar), valida a estrutura como XML de
     * verdade e remove tudo que puder rodar script antes de salvar.
     */
    private static function sanitizarSvg(string $conteudo): string
    {
        // BOM UTF-8 quebra o parse de XML se não for removido antes.
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo) ?? $conteudo;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        // LIBXML_NONET: nunca busca entidade/DTD externa pela rede. Sem
        // LIBXML_NOENT (não expande entidades) — combinado com a rejeição de
        // DOCTYPE logo abaixo, fecha o vetor de XXE/"billion laughs".
        $ok = @$dom->loadXML($conteudo, LIBXML_NONET);
        libxml_clear_errors();

        if (! $ok || $dom->documentElement === null || strtolower($dom->documentElement->localName ?? '') !== 'svg') {
            throw new RuntimeException('O arquivo não é um SVG válido.');
        }

        foreach (iterator_to_array($dom->childNodes) as $node) {
            if ($node->nodeType === XML_DOCUMENT_TYPE_NODE) {
                throw new RuntimeException('SVG com DOCTYPE não é permitido.');
            }
            // Processing instructions (ex.: xml-stylesheet) também podem
            // referenciar recurso externo — só o elemento <svg> sobrevive.
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                $dom->removeChild($node);
            }
        }

        self::sanitizarElemento($dom->documentElement);

        $xml = $dom->saveXML();

        if ($xml === false || $xml === '') {
            throw new RuntimeException('Falha ao processar o SVG enviado.');
        }

        return $xml;
    }

    private static function sanitizarElemento(DOMElement $elemento): void
    {
        foreach (iterator_to_array($elemento->attributes ?? []) as $atributo) {
            self::sanitizarAtributo($elemento, $atributo);
        }

        foreach (iterator_to_array($elemento->childNodes) as $filho) {
            if ($filho->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($filho->localName ?? $filho->nodeName);

                if (in_array($tag, self::TAGS_BLOQUEADAS, true)) {
                    $elemento->removeChild($filho);

                    continue;
                }

                self::sanitizarElemento($filho);
            } elseif (in_array($filho->nodeType, [XML_COMMENT_NODE, XML_PI_NODE], true)) {
                $elemento->removeChild($filho);
            }
        }
    }

    private static function sanitizarAtributo(DOMElement $elemento, DOMAttr $atributo): void
    {
        $nome = strtolower($atributo->nodeName);
        $valor = trim($atributo->nodeValue ?? '');

        // onload, onclick, onerror, onbegin (SMIL)... qualquer manipulador de evento.
        if (str_starts_with($nome, 'on')) {
            $elemento->removeAttribute($atributo->nodeName);

            return;
        }

        if (in_array($nome, self::ATRIBUTOS_URI, true)
            && preg_match('/^[\s\x00-\x1f]*(javascript|vbscript|data:text\/html)/i', $valor)) {
            $elemento->removeAttribute($atributo->nodeName);

            return;
        }

        if ($nome === 'style' && preg_match('/expression\s*\(|javascript\s*:/i', $valor)) {
            $elemento->removeAttribute($atributo->nodeName);
        }
    }
}
