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

    /*
    |--------------------------------------------------------------------------
    | Assinatura das versões
    |--------------------------------------------------------------------------
    |
    | O atualizador baixa uma versão e EXECUTA o código dela (migrations, composer). Com
    | ATUALIZACAO_EXIGIR_ASSINATURA=true ele só aceita uma versão cujo commit o GitHub
    | marcou como "Verified" (commit assinado por uma chave cadastrada na conta de quem
    | assinou). ATUALIZACAO_ASSINANTES (logins do GitHub, separados por vírgula) restringe
    | QUEM pode ter assinado — sem a lista, qualquer assinatura verificada serve. Desligado
    | por padrão: ligue depois que os commits de release passarem a ser assinados.
    |
    */

    'exigir_assinatura' => filter_var(env('ATUALIZACAO_EXIGIR_ASSINATURA', false), FILTER_VALIDATE_BOOLEAN),

    'assinantes' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATUALIZACAO_ASSINANTES', ''))))),

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
