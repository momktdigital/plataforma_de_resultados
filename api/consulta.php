<?php
// Configurações do cabeçalho para retornar JSON e aceitar requisições
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once '../includes/Database.php';

// Pega o RA via GET ou POST
$ra = $_REQUEST['ra'] ?? '';
$ra = trim($ra);

// Validação simples
if (empty($ra)) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'status' => 'error',
        'message' => 'Por favor, informe o RA.'
    ]);
    die();
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Consulta os resultados pelo RA, incluindo a nova coluna `nome_avaliacao`
    $query = "SELECT r.periodo, r.nome_avaliacao, r.respostas AS respostas_aluno, r.notas_finais, r.updated_at, g.respostas AS gabarito
              FROM resultados r LEFT JOIN gabaritos g ON r.periodo = g.periodo AND r.nome_avaliacao = g.nome_avaliacao
              WHERE ra = :ra
              ORDER BY id DESC";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':ra', $ra, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $resultados = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Decodifica os JSONs do banco para arrays associativos do PHP
            $row['respostas_aluno'] = json_decode($row['respostas_aluno'], true);
            $row['notas_finais'] = json_decode($row['notas_finais'], true);
            $row['gabarito'] = $row['gabarito'] ? json_decode($row['gabarito'], true) : [];

            $resultados[] = $row;
        }

        http_response_code(200); // OK
        echo json_encode([
            'status' => 'success',
            'ra' => $ra,
            'data' => $resultados
        ]);

    } else {
        http_response_code(404); // Not Found
        echo json_encode([
            'status' => 'error',
            'message' => "Nenhum resultado encontrado para o RA: $ra."
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'status' => 'error',
        'message' => 'Erro interno ao consultar o banco de dados.'
    ]);
    // Log do erro real (apenas para o servidor)
    error_log("Erro na API de consulta: " . $e->getMessage());
}
?>
