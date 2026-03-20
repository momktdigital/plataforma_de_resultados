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

        // 3. Mapeamento Flexível do Cabeçalho Vertical
        $idxQuestao = -1;
        $idxResposta = -1;

        foreach ($header as $index => $colName) {
            $cleanName = mb_strtoupper(trim($colName), 'UTF-8');

            // Possíveis nomes para a coluna de Número da Questão
            if (in_array($cleanName, ['QUESTÃO', 'QUESTAO', 'NUMERO', 'NÚMERO', 'Q', '#'])) {
                $idxQuestao = $index;
            }

            // Possíveis nomes para a coluna de Alternativa Correta
            if (in_array($cleanName, ['RESPOSTA', 'GABARITO', 'ALTERNATIVA', 'LETRA', 'CORRETA'])) {
                $idxResposta = $index;
            }
        }

        if ($idxQuestao === -1 || $idxResposta === -1) {
            header('Location: upload_gabarito.php?error=1&msg=' . urlencode('O CSV deve conter uma coluna para a Questão (ex: "Questão" ou "Número") e uma para a Resposta (ex: "Gabarito" ou "Alternativa").'));
            fclose($handle);
            die();
        }

        // 4. Lê as linhas de dados do CSV Vertical
        $respostasCorretas = array();

        while (($gabaritoData = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
            // Pula linhas vazias
            if (empty(array_filter($gabaritoData))) continue;

            if (isset($gabaritoData[$idxQuestao]) && isset($gabaritoData[$idxResposta])) {
                $rawQuestao = trim($gabaritoData[$idxQuestao]);
                $rawResposta = mb_strtoupper(trim($gabaritoData[$idxResposta]), 'UTF-8');

                // Extrai apenas os números da coluna questão (caso venha "Questão 1" ou "Q01")
                if (preg_match('/\d+/', $rawQuestao, $matches)) {
                    $numeroQuestao = (int)$matches[0];

                    // Valida se é uma questão de 1 a 100 e se a resposta tem apenas 1 caractere ou está vazia (anulada)
                    if ($numeroQuestao >= 1 && $numeroQuestao <= 100) {
                        // Aceita apenas letras A-Z ou string vazia
                        if (preg_match('/^[A-Z]$/', $rawResposta) || $rawResposta === '') {
                            $respostasCorretas["Q" . $numeroQuestao] = $rawResposta;
                        }
                    }
                }
            }
        }

        fclose($handle);

        if (empty($respostasCorretas)) {
            header('Location: upload_gabarito.php?error=1&msg=' . urlencode('Nenhuma resposta válida encontrada no arquivo CSV.'));
            die();
        }

        // Ordena as chaves pela numeração correta (Q1, Q2... Q10) ao invés de alfabética (Q1, Q10, Q2)
        uksort($respostasCorretas, function($a, $b) {
            return (int)substr($a, 1) - (int)substr($b, 1);
        });

        $respostasJson = json_encode($respostasCorretas);
        $linkComentado = $_POST['link_comentado'] ?? '';
        if (empty(trim($linkComentado))) {
            $linkComentado = null;
        }

        // 5. Inserir ou atualizar na tabela gabaritos
        $db = new Database();
        $conn = $db->getConnection();

        try {
            $query = "INSERT INTO gabaritos (nome_avaliacao, respostas, link_comentado)
                      VALUES (:nome_avaliacao, :respostas, :link_comentado)
                      ON DUPLICATE KEY UPDATE
                      respostas = VALUES(respostas), link_comentado = VALUES(link_comentado), updated_at = CURRENT_TIMESTAMP";

            $stmt = $conn->prepare($query);
            $stmt->bindParam(':nome_avaliacao', $nomeAvaliacao);
            $stmt->bindParam(':respostas', $respostasJson);
            $stmt->bindParam(':link_comentado', $linkComentado);
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
