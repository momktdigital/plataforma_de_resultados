<?php
session_start();

// Ensure admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Não autorizado.']);
    die();
}

header('Content-Type: application/json; charset=utf-8');

require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../includes/Database.php';
require_once 'includes/config_helper.php';

$db = new Database();
$conn = $db->getConnection();

$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);

if (!$email) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'E-mail inválido.']);
    die();
}

// Generate code
$codigo = sprintf("%06d", random_int(0, 999999));
$_SESSION['smtp_test_code'] = $codigo;
$_SESSION['smtp_test_email'] = $email;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = getConfig($conn, 'smtp_host');
    $mail->SMTPAuth   = true;
    $mail->Username   = getConfig($conn, 'smtp_user');
    $mail->Password   = getConfig($conn, 'smtp_pass');
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = getConfig($conn, 'smtp_port');
    $mail->CharSet    = 'UTF-8';

    $fromEmail = getConfig($conn, 'smtp_from_email');
    $fromName = getConfig($conn, 'smtp_from_name');
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = 'Teste de Configuração SMTP';
    $mail->Body    = "Olá,<br><br>Seu código de teste é: <b>$codigo</b><br><br>Insira este código na tela de configuração para validar.";
    $mail->AltBody = "Seu código de teste é: $codigo";

    $mail->send();

    http_response_code(200);
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    error_log("Erro PHPMailer (Teste SMTP): {$mail->ErrorInfo}");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Falha no envio: ' . $mail->ErrorInfo]);
}
