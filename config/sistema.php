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

    /*
    |--------------------------------------------------------------------------
    | Diretórios gravados pela aplicação
    |--------------------------------------------------------------------------
    |
    | Onde ficam os backups (.zip) e os uploads públicos (logos, gabaritos
    | comentados). Configuráveis para que os TESTES apontem para um diretório
    | descartável (ver phpunit.xml) e nunca apaguem os backups/uploads reais.
    | Caminho relativo é lido a partir da raiz da aplicação.
    |
    */

    'backup_dir' => (function () {
        $dir = env('BACKUP_DIR');

        return $dir ? (preg_match('#^([A-Za-z]:)?[\\/]#', $dir) === 1 ? $dir : base_path($dir)) : storage_path('app/backups');
    })(),

    'uploads_dir' => (function () {
        $dir = env('UPLOADS_DIR');

        return $dir ? (preg_match('#^([A-Za-z]:)?[\\/]#', $dir) === 1 ? $dir : base_path($dir)) : public_path('uploads');
    })(),

];
