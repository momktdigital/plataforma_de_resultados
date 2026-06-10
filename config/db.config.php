<?php
// Configurações de conexão com o banco de dados.
// Este arquivo NÃO deve ser acessível via web.
// Em produção, mova este arquivo para fora do docroot (ex: /etc/resultados_di/db.config.php)
// e ajuste o caminho em Database.php.

return [
    'host'     => 'localhost',
    'db_name'  => 'resultados_di',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
];
