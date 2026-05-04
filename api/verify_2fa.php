<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$cpf = preg_replace('/[^0-9]/', '', $_POST['cpf'] ?? '');
$codigo = $_POST['codigo'] ?? '';

if (empty($cpf) || empty($codigo)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'CPF ou código inválidos.']);
    die();
}

try {
    $stmt = $conn->prepare("SELECT * FROM verificacoes_email WHERE cpf = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cpf]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Nenhuma verificação pendente para este CPF.']);
        die();
    }

    $verificacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (strtotime($verificacao['expira_em']) < time()) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Código expirado. Solicite um novo código.']);
        die();
    }

    if ($verificacao['tentativas_falhas'] >= 3) {
        http_response_code(403);
        echo json_encode(['status' => 'blocked', 'message' => 'Muitas tentativas falhas. Bloqueado por 1 hora.']);
        die();
    }

    if ($verificacao['codigo'] !== $codigo) {
        $novaTentativa = $verificacao['tentativas_falhas'] + 1;
        $conn->prepare("UPDATE verificacoes_email SET tentativas_falhas = ? WHERE id = ?")->execute([$novaTentativa, $verificacao['id']]);

        if ($novaTentativa >= 3) {
            http_response_code(403);
            echo json_encode(['status' => 'blocked', 'message' => 'Código incorreto 3 vezes. Dispositivo bloqueado por 1h.']);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Código incorreto. Tentativa $novaTentativa de 3."]);
        }
        die();
    }

    // Sucesso - Limpar verificação e retornar resultados
    $conn->prepare("DELETE FROM verificacoes_email WHERE id = ?")->execute([$verificacao['id']]);

    // Obter RA do aluno
    $stmtAluno = $conn->prepare("SELECT ra FROM alunos WHERE cpf = ? LIMIT 1");
    $stmtAluno->execute([$cpf]);
    $alunoRow = $stmtAluno->fetch(PDO::FETCH_ASSOC);
    $ra = $alunoRow['ra'];

    // Consulta original de resultados
    $query = "SELECT
                r.*,
                g.respostas AS gabarito_respostas, g.link_comentado
              FROM resultados r
              LEFT JOIN gabaritos g ON r.nome_avaliacao = g.nome_avaliacao
              WHERE r.ra = :ra
              ORDER BY r.id DESC";

    $stmtResult = $conn->prepare($query);
    $stmtResult->bindParam(':ra', $ra, PDO::PARAM_STR);
    $stmtResult->execute();

    if ($stmtResult->rowCount() > 0) {
        $resultados = [];
        while ($row = $stmtResult->fetch(PDO::FETCH_ASSOC)) {
            $row['respostas_aluno'] = json_decode($row['respostas'], true) ?: [];
            $row['notas_finais'] = json_decode($row['notas_finais'], true) ?: [];
            $row['gabarito'] = !empty($row['gabarito_respostas']) ? json_decode($row['gabarito_respostas'], true) : [];
            unset($row['respostas']);
            unset($row['gabarito_respostas']);
            $resultados[] = $row;
        }

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'ra' => $ra,
            'data' => $resultados
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => "Você não possui resultados cadastrados no momento."
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno.']);
}
