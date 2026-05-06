<?php
session_start();

// Ensure admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Não autorizado.';
    die();
}

require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

// Fetch all tables
$tables = [];
$stmt = $conn->query("SHOW TABLES");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

$sql = "-- Backup do Banco de Dados: Resultados DI\n";
$sql .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";
$sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    $sql .= "-- Tabela: $table\n";
    $sql .= "DROP TABLE IF EXISTS `$table`;\n";

    // Create table syntax
    $stmtCreate = $conn->query("SHOW CREATE TABLE `$table`");
    $rowCreate = $stmtCreate->fetch(PDO::FETCH_NUM);
    $sql .= $rowCreate[1] . ";\n\n";

    // Dump data
    $stmtData = $conn->query("SELECT * FROM `$table`");
    $rowCount = $stmtData->rowCount();

    if ($rowCount > 0) {
        $sql .= "-- Dados da tabela: $table\n";
        while ($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {
            $keys = array_keys($row);
            $values = array_values($row);
            
            $keysEscaped = array_map(function($key) {
                return "`$key`";
            }, $keys);

            $valuesEscaped = array_map(function($value) use ($conn) {
                if ($value === null) {
                    return 'NULL';
                }
                return $conn->quote($value);
            }, $values);

            $sql .= "INSERT INTO `$table` (" . implode(', ', $keysEscaped) . ") VALUES (" . implode(', ', $valuesEscaped) . ");\n";
        }
        $sql .= "\n";
    }
}

$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

// Force download
$filename = "backup_resultados_di_" . date('Y-m-d_His') . ".sql";

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($sql));

echo $sql;
exit;
