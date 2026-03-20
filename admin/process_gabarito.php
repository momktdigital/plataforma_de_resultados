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

        // 3. Mapeamento Flexível do Cabeçalho
        // Ignora "Nome", "Período" e busca "Q1", "Questão 1", etc.
        $qIndexes = array();
        foreach ($header as $index => $colName) {
            $cleanName = mb_strtoupper(trim($colName), 'UTF-8');

            // Regex para capturar o número da questão logo após "Q" ou "QUESTÃO"
            // Suporta: "Q1", "Q 1", "QUESTÃO 1", "QUESTAO 1"
            if (preg_match('/^(?:Q|QUESTÃO|QUESTAO)\s*(\d+)$/', $cleanName, $matches)) {
                $numeroQuestao = $matches[1];
                // Se for até 100, a gente padroniza como Q1, Q2, etc.
                if ((int)$numeroQuestao >= 1 && (int)$numeroQuestao <= 100) {
                    $padronizado = "Q" . $numeroQuestao;
                    $qIndexes[$padronizado] = $index;
                }
            }
        }

        if (empty($qIndexes)) {
            header('Location: upload_gabarito.php?error=1&msg=' . urlencode('Não foram encontradas colunas de questões (ex: Questão 1, Q1) no cabeçalho.'));
            fclose($handle);
            die();
        }

        // 4. Lê as linhas de dados até encontrar a primeira linha válida com respostas
        $respostasCorretas = array();
        $gabaritoProcessado = false;

        while (($gabaritoData = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
            // Pula linhas vazias
            if (empty(array_filter($gabaritoData))) continue;

            $temRespostaValida = false;
            $respostasTemp = array();

            // Mapeia os dados da linha baseando-se nos índices do cabeçalho
            for ($i = 1; $i <= 100; $i++) {
                $qName = "Q$i";
                if (isset($qIndexes[$qName])) {
                    $idx = $qIndexes[$qName];
                    if (isset($gabaritoData[$idx]) && trim($gabaritoData[$idx]) !== '') {
                        $resposta = mb_strtoupper(trim($gabaritoData[$idx]), 'UTF-8');
                        $respostasTemp[$qName] = $resposta;

                        // Consideramos válido apenas letras únicas ou respostas lógicas
                        if (preg_match('/^[A-Z]$/', $resposta)) {
                            $temRespostaValida = true;
                        }
                    } else {
                        $respostasTemp[$qName] = "";
                    }
                }
            }

            // Se encontrou respostas válidas (letras), assume que esta é a linha correta do gabarito
            if ($temRespostaValida) {
                $respostasCorretas = $respostasTemp;
                $gabaritoProcessado = true;
                break; // Ignora as próximas linhas do CSV
            }
        }

        fclose($handle);

        if (!$gabaritoProcessado) {
            header('Location: upload_gabarito.php?error=1&msg=' . urlencode('O arquivo CSV não contém uma linha com letras válidas para o gabarito.'));
            die();
        }

        $respostasJson = json_encode($respostasCorretas);

        // 5. Inserir ou atualizar na tabela gabaritos
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

            header("Location: upload_gabarito.php?success=1&msg=" . urlencode("O gabarito com " . count(array_filter($respostasCorretas)) . " respostas foi salvo com sucesso para a avaliação '$nomeAvaliacao'."));
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
