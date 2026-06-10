<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csrf_generate(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_generate(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_validate(): void {
    $token = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';

    if (empty($token) || empty($stored) || !hash_equals($stored, $token)) {
        http_response_code(403);
        die('<p>Ação inválida: token de segurança ausente ou expirado.</p><p><a href="javascript:history.back()">Voltar</a></p>');
    }

    // Regenera após cada uso válido
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
