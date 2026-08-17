<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve arquivos de assets/img/ (raiz do repositório, fora do document root
 * do Laravel — ver App\Support\LogoUploader) publicamente, sem exigir login.
 * Necessário porque o document root deste app é avaliacoes/public/, então
 * um <img src="assets/img/xxx.png"> normal não alcança esse diretório.
 */
class AssetLegadoController extends Controller
{
    public function logo(string $arquivo): StreamedResponse|Response
    {
        // basename() descarta qualquer tentativa de path traversal (../).
        $caminho = base_path('../assets/img/'.basename($arquivo));

        if (! is_file($caminho)) {
            abort(404);
        }

        return response()->file($caminho);
    }
}
