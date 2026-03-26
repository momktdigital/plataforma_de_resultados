<?php
// Função helper para buscar configurações facilmente

function getConfig($conn, $chave, $default = '') {
    try {
        $stmt = $conn->prepare("SELECT valor FROM configuracoes WHERE chave = :chave");
        $stmt->bindParam(':chave', $chave, PDO::PARAM_STR);
        $stmt->execute();
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return $row['valor'];
        }
    } catch (PDOException $e) {
        // Log error silently
    }
    return $default;
}
?>
