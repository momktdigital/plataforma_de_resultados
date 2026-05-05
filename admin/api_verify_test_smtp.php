<?php
session_start();
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

// Ensure admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Não autorizado.']);
    die();
}

header('Content-Type: application/json; charset=utf-8');

$codigo_digitado = $_POST['codigo'] ?? '';

if (empty($codigo_digitado)) {
    http_response_code(400);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Código não informado.']);
    die();
}

if (!isset($_SESSION['smtp_test_code'])) {
    http_response_code(400);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Nenhum teste pendente. Feche e tente novamente.']);
    die();
}

if ($_SESSION['smtp_test_code'] === strtoupper(trim($codigo_digitado))) {
    // Sucesso, limpa a sessao
    unset($_SESSION['smtp_test_code']);
    unset($_SESSION['smtp_test_email']);
    http_response_code(200);
    ob_end_clean();
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(400);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Código incorreto.']);
}
