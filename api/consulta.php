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

    // Consulta os resultados pelo RA fazendo JOIN no gabarito apenas pela nome_avaliacao
    $query = "SELECT
                r.*,
                g.respostas AS gabarito_respostas
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

    // Agora retornamos o erro real no JSON para facilitar o debug (embora em produção não seja ideal)
    echo json_encode([
        'status' => 'error',
        'message' => 'Erro interno ao consultar o banco de dados.',
        'error_detail' => $e->getMessage()
    ]);

    error_log("Erro na API de consulta: " . $e->getMessage());
}
?>
