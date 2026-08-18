<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Repositório de atualização
    |--------------------------------------------------------------------------
    |
    | Repositório GitHub público (owner/repo) de onde o atualizador busca a
    | última Release. O sistema vive na subpasta `subpasta` desse repositório.
    |
    */

    'repositorio' => env('ATUALIZACAO_REPOSITORIO', 'momktdigital/resultados_di'),

    'subpasta' => 'avaliacoes',

];
