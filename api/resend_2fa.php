<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../includes/PHPMailer/Exception.php';
require_once '../includes/PHPMailer/PHPMailer.php';
require_once '../includes/PHPMailer/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once '../includes/Database.php';
require_once '../admin/includes/config_helper.php';

$db = new Database();
$conn = $db->getConnection();

$cpf = preg_replace('/[^0-9]/', '', $_POST['cpf'] ?? '');

if (empty($cpf)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'CPF inválido.']);
    die();
}

try {
    $stmt = $conn->prepare("SELECT v.*, a.email FROM verificacoes_email v JOIN alunos a ON v.cpf = a.cpf WHERE v.cpf = ? ORDER BY v.id DESC LIMIT 1");
    $stmt->execute([$cpf]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Nenhuma verificação pendente para este CPF. Tente fazer a consulta novamente.']);
        die();
    }

    $verificacao = $stmt->fetch(PDO::FETCH_ASSOC);
    $email = $verificacao['email'];

    // Calcular tempo de espera baseado nas vezes reenviado
    // 0 = 1 min, 1 = 2 min, 2 = 5 min, 3 = 10 min
    $esperas = [1, 2, 5, 10];
    $indiceReenvio = min($verificacao['vezes_reenviado'], 3);
    $minutosEspera = $esperas[$indiceReenvio];
    // O próximo índice
    $nextMinutosEspera = $esperas[min($verificacao['vezes_reenviado'] + 1, 3)];

    if ($verificacao['ultimo_reenvio']) {
        $ultimoReenvio = strtotime($verificacao['ultimo_reenvio']);
        $agora = strtotime('now');
        $tempoPassado = $agora - $ultimoReenvio;
        $tempoFaltando = ($minutosEspera * 60) - $tempoPassado;

        if ($tempoFaltando > 0) {
            $minutosRestantes = ceil($tempoFaltando / 60);
            http_response_code(429); // Too Many Requests
            echo json_encode([
                'status' => 'error',
                'message' => "Aguarde $minutosRestantes minuto(s) para solicitar um novo código."
            ]);
            die();
        }
    } else {
         // primeira vez - foi enviado a menos de 1 minuto (já foi enviado ao logar)
         $criadoEm = strtotime($verificacao['criado_em']);
         $agora = strtotime('now');
         $tempoPassado = $agora - $criadoEm;
         $tempoFaltando = (1 * 60) - $tempoPassado;
         if ($tempoFaltando > 0) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => "Aguarde 1 minuto para solicitar um novo código."
            ]);
            die();
        }
    }

    $codigo = $verificacao['codigo'];
    $novaExpiração = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Atualiza reenvio
    $novaVez = $verificacao['vezes_reenviado'] + 1;
    $conn->prepare("UPDATE verificacoes_email SET vezes_reenviado = ?, ultimo_reenvio = CURRENT_TIMESTAMP, expira_em = ? WHERE id = ?")->execute([$novaVez, $novaExpiração, $verificacao['id']]);

    // Enviar E-mail via SMTP usando PHPMailer
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
        $mail->Subject = 'Seu código de acesso aos resultados (Reenvio)';
        $mail->Body    = "Olá,<br><br>Seu código de verificação é: <b>$codigo</b><br><br>Este código expira em 10 minutos.<br><br>Se você não solicitou este acesso, por favor ignore este e-mail.";
        $mail->AltBody = "Seu código de verificação é: $codigo. Este código expira em 10 minutos.";

        $mail->send();

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Código reenviado com sucesso.',
            'espera_minutos' => $nextMinutosEspera
        ]);
        die();

    } catch (Exception $e) {
        error_log("Erro PHPMailer (Reenvio): {$mail->ErrorInfo}");
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Erro ao enviar o e-mail. Tente novamente.'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno.']);
}
