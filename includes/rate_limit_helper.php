<?php
/**
 * Rate limiting por IP para o 2FA.
 * Bloqueia IPs com excesso de tentativas falhas, independente do CPF.
 */

define('RATE_LIMIT_MAX_TENTATIVAS', 10);  // tentativas falhas permitidas
define('RATE_LIMIT_JANELA_MINUTOS', 60);  // janela de tempo em minutos
define('RATE_LIMIT_BLOQUEIO_MINUTOS', 60); // duração do bloqueio em minutos

function rate_limit_get_ip(): string {
    // Prioriza o IP real quando atrás de proxy/load balancer
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Verifica se o IP está bloqueado.
 * Retorna true se bloqueado, false se pode prosseguir.
 */
function rate_limit_check(PDO $conn): bool {
    $ip = rate_limit_get_ip();

    $stmt = $conn->prepare(
        "SELECT tentativas, bloqueado_ate FROM rate_limit_2fa WHERE ip_address = ? LIMIT 1"
    );
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false; // IP sem histórico, pode prosseguir
    }

    // Bloqueio ativo?
    if ($row['bloqueado_ate'] !== null && strtotime($row['bloqueado_ate']) > time()) {
        return true;
    }

    return false;
}

/**
 * Registra uma tentativa falha para o IP.
 * Aplica bloqueio se o limite for atingido.
 */
function rate_limit_record_failure(PDO $conn): void {
    $ip = rate_limit_get_ip();

    $stmt = $conn->prepare(
        "INSERT INTO rate_limit_2fa (ip_address, tentativas, ultima_tentativa)
         VALUES (?, 1, NOW())
         ON DUPLICATE KEY UPDATE
             tentativas = tentativas + 1,
             ultima_tentativa = NOW(),
             bloqueado_ate = IF(
                 tentativas + 1 >= :max,
                 DATE_ADD(NOW(), INTERVAL :bloqueio MINUTE),
                 bloqueado_ate
             )"
    );
    $stmt->bindValue(':max', RATE_LIMIT_MAX_TENTATIVAS, PDO::PARAM_INT);
    $stmt->bindValue(':bloqueio', RATE_LIMIT_BLOQUEIO_MINUTOS, PDO::PARAM_INT);
    $stmt->execute([$ip]);
}

/**
 * Remove o histórico de tentativas do IP após autenticação bem-sucedida.
 */
function rate_limit_reset(PDO $conn): void {
    $ip = rate_limit_get_ip();
    $conn->prepare("DELETE FROM rate_limit_2fa WHERE ip_address = ?")->execute([$ip]);
}
