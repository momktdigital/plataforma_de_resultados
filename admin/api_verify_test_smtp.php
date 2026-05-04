<?php
session_start();

// Ensure admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Não autorizado.']);
    die();
}

header('Content-Type: application/json; charset=utf-8');

$codigo_digitado = $_POST['codigo'] ?? '';

if (empty($codigo_digitado)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Código não informado.']);
    die();
}

if (!isset($_SESSION['smtp_test_code'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nenhum teste pendente. Feche e tente novamente.']);
    die();
}

if ($_SESSION['smtp_test_code'] === strtoupper(trim($codigo_digitado))) {
    // Sucesso, limpa a sessao
    unset($_SESSION['smtp_test_code']);
    unset($_SESSION['smtp_test_email']);
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Código incorreto.']);
}
