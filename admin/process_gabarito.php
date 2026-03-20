<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    die();
}

require_once '../includes/Database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {

    $nomeAvaliacao = $_POST['nome_avaliacao'] ?? '';

    if (empty($nomeAvaliacao)) {
        header('Location: upload_gabarito.php?error=1&msg=' . urlencode('Selecione uma avaliação válida.'));
        die();
    }

    $fileTmpPath = $_FILES['csv_file']['tmp_name'];
    $fileName = $_FILES['csv_file']['name'];
    $fileNameCmps = explode(".", $fileName);
    $fileExtension = strtolower(end($fileNameCmps));

    if ($fileExtension !== 'csv') {
        header('Location: upload_gabarito.php?error=1&msg=' . urlencode('O arquivo enviado não é um CSV.'));
        die();
    }

    if (($handle = fopen($fileTmpPath, "r")) !== FALSE) {

        // 1. Detecta o delimitador dinamicamente
        $delimiter = ",";
        $header = fgetcsv($handle, 10000, $delimiter);

        if ($header && count($header) === 1 && strpos($header[0], ';') !== false) {
            $delimiter = ";";
            rewind($handle);
            $header = fgetcsv($handle, 10000, $delimiter);
        }

        if (!$header) {
            header('Location: upload_gabarito.php?error=1&msg=' . urlencode('O arquivo CSV está vazio ou o formato é inválido.'));
            fclose($handle);
            die();
        }

        // 2. Remove o BOM (Byte Order Mark) do primeiro item do cabeçalho
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        // 3. Mapeia os índices das colunas de questões (Q1 a Q100) no cabeçalho
        $qIndexes = array();
        foreach ($header as $index => $colName) {
            $cleanName = mb_strtoupper(trim($colName), 'UTF-8');
            if (preg_match('/^Q\d+$/', $cleanName)) {
                $qIndexes[$cleanName] = $index;
            }
        }

        if (empty($qIndexes)) {
            header('Location: upload_gabarito.php?error=1&msg=' . urlencode('Não foram encontradas colunas de questões (Q1, Q2, etc.) no cabeçalho (Linha 1).'));
            fclose($handle);
            die();
        }

        // 4. Lê a SEGUNDA linha de dados para extrair as respostas do gabarito
        $gabaritoData = fgetcsv($handle, 10000, $delimiter);
        fclose($handle);

        if (!$gabaritoData) {
            header('Location: upload_gabarito.php?error=1&msg=' . urlencode('O arquivo CSV não contém uma segunda linha com as respostas corretas.'));
            die();
        }

        // 5. Mapeia os dados da segunda linha baseando-se nos índices da primeira linha
        $respostasCorretas = array();
        for ($i = 1; $i <= 100; $i++) {
            $qName = "Q$i";
            if (isset($qIndexes[$qName])) {
                $idx = $qIndexes[$qName];
                if (isset($gabaritoData[$idx])) {
                    // Remove espaços e converte para maiúsculo (A, B, C...)
                    $respostasCorretas[$qName] = mb_strtoupper(trim($gabaritoData[$idx]), 'UTF-8');
                } else {
                    $respostasCorretas[$qName] = "";
                }
            }
            // As outras colunas como "Período" são automaticamente ignoradas pois não entram no array qIndexes
        }

        $respostasJson = json_encode($respostasCorretas);

        // 6. Inserir ou atualizar na tabela gabaritos
        $db = new Database();
        $conn = $db->getConnection();

        try {
            $query = "INSERT INTO gabaritos (nome_avaliacao, respostas)
                      VALUES (:nome_avaliacao, :respostas)
                      ON DUPLICATE KEY UPDATE
                      respostas = VALUES(respostas), updated_at = CURRENT_TIMESTAMP";

            $stmt = $conn->prepare($query);
            $stmt->bindParam(':nome_avaliacao', $nomeAvaliacao);
            $stmt->bindParam(':respostas', $respostasJson);
            $stmt->execute();

            header("Location: upload_gabarito.php?success=1&msg=" . urlencode("O gabarito com " . count($respostasCorretas) . " respostas foi salvo com sucesso para a avaliação '$nomeAvaliacao'."));
            die();

        } catch (PDOException $e) {
            error_log("Erro ao salvar gabarito: " . $e->getMessage());
            header('Location: upload_gabarito.php?error=1&msg=' . urlencode('Erro ao salvar no banco de dados.'));
            die();
        }

    } else {
        header('Location: upload_gabarito.php?error=1&msg=' . urlencode('Não foi possível ler o arquivo.'));
        die();
    }
} else {
    header('Location: upload_gabarito.php?error=1&msg=' . urlencode('Erro ao fazer upload do arquivo.'));
    die();
}
?>
