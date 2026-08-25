<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Repositório de atualização
    |--------------------------------------------------------------------------
    |
    | Repositório GitHub público (owner/repo) de onde o atualizador busca a
    | última Release. O sistema vive na raiz desse repositório (ver
    | `subpasta` abaixo, que existe só para instalações antigas onde o app
    | ficava numa subpasta do zip da Release).
    |
    */

    'repositorio' => env('ATUALIZACAO_REPOSITORIO', 'momktdigital/resultados_di'),

    'subpasta' => '',

];
