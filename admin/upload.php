<?php
session_start();

// Verifica se o admin está logado
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    die();
}

require_once '../includes/Database.php';

// Verifica se o formulário foi enviado e se o arquivo existe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {

    $fileTmpPath = $_FILES['csv_file']['tmp_name'];
    $fileName = $_FILES['csv_file']['name'];
    $fileSize = $_FILES['csv_file']['size'];
    $fileType = $_FILES['csv_file']['type'];
    $fileNameCmps = explode(".", $fileName);
    $fileExtension = strtolower(end($fileNameCmps));

    // Valida a extensão do arquivo
    if ($fileExtension !== 'csv') {
        header('Location: index.php?error=1&msg=' . urlencode('O arquivo enviado não é um CSV.'));
        die();
    }

    // Tenta abrir o arquivo
    if (($handle = fopen($fileTmpPath, "r")) !== FALSE) {

        // 1. Detecta o delimitador correto dinamicamente
        $delimiter = ",";
        $header = fgetcsv($handle, 10000, $delimiter);

        if ($header && count($header) === 1 && strpos($header[0], ';') !== false) {
            $delimiter = ";";
            rewind($handle);
            $header = fgetcsv($handle, 10000, $delimiter);
        }

        if (!$header) {
            header('Location: index.php?error=1&msg=' . urlencode('O arquivo CSV está vazio ou o formato é inválido.'));
            fclose($handle);
            die();
        }

        // 2. Remove o BOM (Byte Order Mark) do primeiro item do cabeçalho
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        // 3. Identifica os índices das colunas usando mb_strtoupper (corrige o bug do í em Período)
        $colIndex = array();
        foreach ($header as $index => $colName) {
            $cleanName = mb_strtoupper(trim($colName), 'UTF-8');
            $colIndex[$cleanName] = $index;
        }

        // Verifica se as colunas obrigatórias existem
        if (!isset($colIndex['RA']) || (!isset($colIndex['PERÍODO']) && !isset($colIndex['PERIODO']))) {
            header('Location: index.php?error=1&msg=' . urlencode('As colunas RA e Período são obrigatórias no CSV.'));
            fclose($handle);
            die();
        }

        // Padroniza a busca do período
        $periodoKey = isset($colIndex['PERÍODO']) ? 'PERÍODO' : 'PERIODO';

        // Identifica o índice da coluna "NOME1" para ignorá-la completamente
        $nomeIndex = isset($colIndex['NOME1']) ? $colIndex['NOME1'] : -1;

        // Identifica os índices das questões (Q1 a Q100)
        $qIndexes = array();
        for ($i = 1; $i <= 100; $i++) {
            $qName = "Q$i";
            if (isset($colIndex[$qName])) {
                $qIndexes[$qName] = $colIndex[$qName];
            }
        }

        // Identifica outras colunas que serão consideradas "Notas Finais"
        $notasFinaisIndexes = array();
        foreach ($colIndex as $name => $index) {
            // Ignora RA, Período, NOME1 e as Questões (Q1 a Q100)
            if ($name !== 'RA' && $name !== $periodoKey && $index !== $nomeIndex && !isset($qIndexes[$name])) {
                // Guarda o nome original da coluna para salvar no JSON
                $originalName = trim($header[$index]);
                $notasFinaisIndexes[$originalName] = $index;
            }
        }

        // Conecta ao banco de dados
        $db = new Database();
        $conn = $db->getConnection();

        // Contador de registros processados
        $count = 0;

        // Prepara a query de Upsert (INSERT ... ON DUPLICATE KEY UPDATE)
        $query = "INSERT INTO resultados (ra, periodo, respostas, notas_finais)
                  VALUES (:ra, :periodo, :respostas, :notas_finais)
                  ON DUPLICATE KEY UPDATE
                  respostas = VALUES(respostas), notas_finais = VALUES(notas_finais), updated_at = CURRENT_TIMESTAMP";
        $stmt = $conn->prepare($query);

        // Processa cada linha do CSV
        while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {

            // Pula linhas vazias
            if (empty(array_filter($data))) continue;

            $ra = isset($data[$colIndex['RA']]) ? trim($data[$colIndex['RA']]) : null;
            $periodo = isset($data[$colIndex[$periodoKey]]) ? trim($data[$colIndex[$periodoKey]]) : null;

            if (empty($ra) || empty($periodo)) {
                // Pula linhas onde RA ou Período estão vazios
                continue;
            }

            // A Regra de Ouro: "NOME1" é completamente ignorado. Não o lemos para nenhuma variável.
            // O índice $nomeIndex foi identificado antes e não é usado em lugar nenhum da leitura de dados.

            // Extrai as respostas (Q1 a Q100) para um array
            $respostas = array();
            foreach ($qIndexes as $qName => $index) {
                if (isset($data[$index])) {
                    $respostas[$qName] = trim($data[$index]);
                } else {
                     $respostas[$qName] = "";
                }
            }
            $respostasJson = json_encode($respostas);

            // Extrai as notas finais para um array
            $notasFinais = array();
            foreach ($notasFinaisIndexes as $originalName => $index) {
                if (isset($data[$index])) {
                    $notasFinais[$originalName] = trim($data[$index]);
                } else {
                    $notasFinais[$originalName] = "";
                }
            }
            $notasFinaisJson = json_encode($notasFinais);

            // Executa o Upsert
            try {
                $stmt->bindParam(':ra', $ra);
                $stmt->bindParam(':periodo', $periodo);
                $stmt->bindParam(':respostas', $respostasJson);
                $stmt->bindParam(':notas_finais', $notasFinaisJson);
                $stmt->execute();
                $count++;
            } catch (PDOException $e) {
                 // Log de erro (opcional, pode ser melhorado para produção)
                 error_log("Erro ao processar RA $ra: " . $e->getMessage());
            }
        }

        fclose($handle);

        header("Location: index.php?success=1&msg=" . urlencode("$count registros processados com sucesso."));
        die();

    } else {
        header('Location: index.php?error=1&msg=' . urlencode('Não foi possível ler o arquivo.'));
        die();
    }
} else {
    // Redireciona se houver erro no upload ou acesso direto
    header('Location: index.php?error=1&msg=' . urlencode('Erro ao fazer upload do arquivo.'));
    die();
}
?>
