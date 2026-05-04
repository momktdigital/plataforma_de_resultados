<?php
// Configurações do cabeçalho para retornar JSON e aceitar requisições
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once '../includes/Database.php';
require_once '../admin/includes/config_helper.php';

$db = new Database();
$conn = $db->getConnection();

// --- 1. Validar reCAPTCHA se ativo ---
$recaptchaAtivo = getConfig($conn, 'recaptcha_ativo') === '1';
if ($recaptchaAtivo) {
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
    if (empty($recaptchaResponse)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Por favor, confirme que você não é um robô.']);
        die();
    }

    $secretKey = getConfig($conn, 'recaptcha_secret_key');
    if (!empty($secretKey)) {
        $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret' => $secretKey,
            'response' => $recaptchaResponse
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data)
            ]
        ];
        $context  = stream_context_create($options);
        $result = file_get_contents($verifyUrl, false, $context);
        $recaptchaData = json_decode($result, true);

        if (!$recaptchaData || !$recaptchaData['success']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Falha na validação do reCAPTCHA. Tente novamente.']);
            die();
        }
    }
}

// --- 2. Coletar e Validar Dados (CPF e Nascimento) ---
$cpf = $_POST['cpf'] ?? '';
$data_nascimento_br = $_POST['data_nascimento'] ?? ''; // Vem como DD/MM/AAAA

// Remove pontuação do CPF (caso frontend tenha falhado)
$cpf = preg_replace('/[^0-9]/', '', $cpf);

if (empty($cpf) || empty($data_nascimento_br) || strlen($cpf) !== 11) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'status' => 'error',
        'message' => 'Por favor, informe um CPF e Data de Nascimento válidos.'
    ]);
    die();
}

// Converter DD/MM/AAAA para YYYY-MM-DD
$data_nascimento = null;
if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $data_nascimento_br, $matches)) {
    $data_nascimento = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Formato de data inválido. Use DD/MM/AAAA.']);
    die();
}


try {
    // --- 3. Buscar o Aluno pelo CPF e Data de Nascimento ---
    $queryAluno = "SELECT ra, nome, email FROM alunos WHERE cpf = :cpf AND data_nascimento = :data_nascimento LIMIT 1";
    $stmtAluno = $conn->prepare($queryAluno);
    $stmtAluno->bindParam(':cpf', $cpf, PDO::PARAM_STR);
    $stmtAluno->bindParam(':data_nascimento', $data_nascimento, PDO::PARAM_STR);
    $stmtAluno->execute();

    if ($stmtAluno->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode([
            'status' => 'error',
            'message' => "Nenhum aluno encontrado com este CPF e Data de Nascimento."
        ]);
        die();
    }

    $alunoRow = $stmtAluno->fetch(PDO::FETCH_ASSOC);
    $ra = $alunoRow['ra'];
    $email = $alunoRow['email'];

    // --- 3.5. Verifica se o 2FA está ativo ---
    $smtpAtivo = getConfig($conn, 'smtp_ativo') === '1';
    if ($smtpAtivo) {
        if (empty($email)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'O 2FA está ativo, mas você não tem e-mail cadastrado. Contate a secretaria.'
            ]);
            die();
        }

        // Gerar código aleatório de 6 dígitos
        $codigo = sprintf("%06d", mt_rand(0, 999999));
        $expira_em = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Limpar códigos antigos do CPF e inserir o novo
        $conn->prepare("DELETE FROM verificacoes_email WHERE cpf = ?")->execute([$cpf]);
        $stmt = $conn->prepare("INSERT INTO verificacoes_email (cpf, codigo, expira_em, vezes_reenviado) VALUES (?, ?, ?, 0)");
        $stmt->execute([$cpf, $codigo, $expira_em]);

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
            $mail->Subject = 'Seu código de acesso aos resultados';
            $mail->Body    = "Olá,<br><br>Seu código de verificação é: <b>$codigo</b><br><br>Este código expira em 10 minutos.<br><br>Se você não solicitou este acesso, por favor ignore este e-mail.";
            $mail->AltBody = "Seu código de verificação é: $codigo. Este código expira em 10 minutos.";

            $mail->send();

            // Ocultar email parcialmente para exibição segura
            $emailParts = explode('@', $email);
            $emailOculto = substr($emailParts[0], 0, 3) . '***@' . $emailParts[1];

            http_response_code(200);
            echo json_encode([
                'status' => 'require_2fa',
                'message' => 'Código enviado para o e-mail cadastrado.',
                'email_hint' => $emailOculto,
                // pass these securely without sending real values just format confirmation
                'cpf' => $cpf,
                'data_nascimento' => $data_nascimento_br
            ]);
            die();

        } catch (Exception $e) {
            error_log("Erro PHPMailer: {$mail->ErrorInfo}");
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Erro ao enviar o e-mail de verificação. Tente novamente mais tarde.'
            ]);
            die();
        }
    }

    // --- 4. Consultar os Resultados pelo RA (Lógica Original) ---
    // Consulta os resultados pelo RA fazendo JOIN no gabarito apenas pela nome_avaliacao
    $query = "SELECT
                r.*,
                g.respostas AS gabarito_respostas, g.link_comentado
              FROM resultados r
              LEFT JOIN gabaritos g ON r.nome_avaliacao = g.nome_avaliacao
              WHERE r.ra = :ra
              ORDER BY r.id DESC";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':ra', $ra, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $resultados = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Decodifica os JSONs do banco para arrays associativos do PHP
            $row['respostas_aluno'] = json_decode($row['respostas'], true) ?: [];
            $row['notas_finais'] = json_decode($row['notas_finais'], true) ?: [];
            $row['gabarito'] = !empty($row['gabarito_respostas']) ? json_decode($row['gabarito_respostas'], true) : [];

            // Remove as colunas cruas em string JSON para enviar um payload limpo
            unset($row['respostas']);
            unset($row['gabarito_respostas']);

            $resultados[] = $row;
        }

        http_response_code(200); // OK
        echo json_encode([
            'status' => 'success',
            'ra' => $ra, // Devolve o RA só para exibir na tela (o aluno não digita mais)
            'data' => $resultados
        ]);

    } else {
        http_response_code(404); // Not Found
        echo json_encode([
            'status' => 'error',
            'message' => "Você não possui resultados cadastrados no momento."
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error

    echo json_encode([
        'status' => 'error',
        'message' => 'Erro interno ao consultar o banco de dados.',
        'error_detail' => $e->getMessage()
    ]);

    error_log("Erro na API de consulta: " . $e->getMessage());
}
?>
