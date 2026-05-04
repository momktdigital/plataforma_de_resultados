<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    die();
}

require_once '../includes/Database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['csv_file']['tmp_name'];
    $fileName = $_FILES['csv_file']['name'];
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if ($fileExtension !== 'csv') {
        header('Location: upload_alunos.php?error=1&msg=' . urlencode('O arquivo enviado não é um CSV.'));
        die();
    }

    if (($handle = fopen($fileTmpPath, "r")) !== FALSE) {
        // Detecta o delimitador
        $delimiter = ",";
        $header = fgetcsv($handle, 10000, $delimiter);

        if ($header && count($header) === 1 && strpos($header[0], ';') !== false) {
            $delimiter = ";";
            rewind($handle);
            $header = fgetcsv($handle, 10000, $delimiter);
        }

        if (!$header) {
            header('Location: upload_alunos.php?error=1&msg=' . urlencode('O arquivo CSV está vazio ou inválido.'));
            fclose($handle);
            die();
        }

        // Remove BOM e mapeia colunas
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $colIndex = [];
        foreach ($header as $index => $colName) {
            // Normaliza o nome da coluna para evitar problemas com acentos e espaços
            $cleanName = mb_strtoupper(trim($colName), 'UTF-8');
            $colIndex[$cleanName] = $index;
        }

        // Variáveis de mapeamento flexível (baseado na especificação)
        $idxRA = $colIndex['RA'] ?? null;
        $idxCPF = $colIndex['CPF'] ?? null;

        // Pode vir como NOME, NOME1, NOME COMPLETO...
        $idxNome = $colIndex['NOME'] ?? ($colIndex['NOME1'] ?? null);

        // Data de nascimento: DT. NASCIMENTO, DATA DE NASCIMENTO, DATA NASCIMENTO...
        $idxDataNascimento = $colIndex['DT. NASCIMENTO'] ?? ($colIndex['DATA DE NASCIMENTO'] ?? ($colIndex['DATA NASCIMENTO'] ?? null));

        $idxCurso = $colIndex['CURSO'] ?? null;

        // Campus: CÂMPUS/POLO, CAMPUS, POLO
        $idxCampus = $colIndex['CÂMPUS/POLO'] ?? ($colIndex['CAMPUS'] ?? ($colIndex['POLO'] ?? null));

        // Email: EMAIL, E-MAIL
        $idxEmail = $colIndex['EMAIL'] ?? ($colIndex['E-MAIL'] ?? null);

        // Validação das colunas obrigatórias
        if ($idxRA === null || $idxCPF === null || $idxDataNascimento === null) {
            header('Location: upload_alunos.php?error=1&msg=' . urlencode('As colunas RA, CPF e Dt. Nascimento são obrigatórias.'));
            fclose($handle);
            die();
        }

        $db = new Database();
        $conn = $db->getConnection();

        $countSuccess = 0;
        $countErrors = 0;
        $errorsList = [];

        // Query de Upsert (A regra diz: atualizar se o RA já existir)
        // OBS: o MySQL vai reclamar de CPF duplicado se tentar atualizar RA e colocar CPF de outro.
        $query = "INSERT INTO alunos (ra, nome, cpf, data_nascimento, curso, campus, email)
                  VALUES (:ra, :nome, :cpf, :data_nascimento, :curso, :campus, :email)
                  ON DUPLICATE KEY UPDATE
                  nome = VALUES(nome), cpf = VALUES(cpf), data_nascimento = VALUES(data_nascimento),
                  curso = VALUES(curso), campus = VALUES(campus), email = VALUES(email), updated_at = CURRENT_TIMESTAMP";
        $stmt = $conn->prepare($query);

        while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
            if (empty(array_filter($data))) continue;

            $ra = isset($data[$idxRA]) ? trim($data[$idxRA]) : null;
            $cpfRaw = isset($data[$idxCPF]) ? trim($data[$idxCPF]) : null;
            $dataNascimentoRaw = isset($data[$idxDataNascimento]) ? trim($data[$idxDataNascimento]) : null;
            $nome = $idxNome !== null && isset($data[$idxNome]) ? trim($data[$idxNome]) : null;
            $curso = $idxCurso !== null && isset($data[$idxCurso]) ? trim($data[$idxCurso]) : null;
            $campus = $idxCampus !== null && isset($data[$idxCampus]) ? trim($data[$idxCampus]) : null;
            $email = $idxEmail !== null && isset($data[$idxEmail]) ? trim($data[$idxEmail]) : null;

            if (empty($ra) || empty($cpfRaw) || empty($dataNascimentoRaw)) {
                $countErrors++;
                continue;
            }

            // Remove caracteres não numéricos do CPF
            $cpf = preg_replace('/[^0-9]/', '', $cpfRaw);

            // Converte Data de Nascimento (DD/MM/AAAA para YYYY-MM-DD)
            $dataNascimento = null;
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dataNascimentoRaw, $matches)) {
                $dia = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $mes = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                $ano = $matches[3];
                $dataNascimento = "$ano-$mes-$dia";
            }

            if (!$dataNascimento || strlen($cpf) !== 11) {
                $countErrors++;
                $errorsList[] = "RA $ra: CPF (" . strlen($cpf) . " digitos) ou Data ($dataNascimentoRaw) inválidos.";
                continue;
            }

            try {
                $stmt->bindParam(':ra', $ra);
                $stmt->bindParam(':nome', $nome);
                $stmt->bindParam(':cpf', $cpf);
                $stmt->bindParam(':data_nascimento', $dataNascimento);
                $stmt->bindParam(':curso', $curso);
                $stmt->bindParam(':campus', $campus);
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                $countSuccess++;
            } catch (PDOException $e) {
                $countErrors++;
                $errorsList[] = "RA $ra: " . $e->getMessage();
            }
        }

        fclose($handle);

        $msg = "$countSuccess registros processados com sucesso.";
        if ($countErrors > 0) {
            $msg .= " $countErrors erros encontrados (veja o log do sistema ou verifique CPFs/Datas inválidas).";
            error_log("Erros Importação Alunos: " . implode(" | ", array_slice($errorsList, 0, 10)));
        }

        header("Location: upload_alunos.php?success=1&msg=" . urlencode($msg));
        die();

    } else {
        header('Location: upload_alunos.php?error=1&msg=' . urlencode('Não foi possível ler o arquivo.'));
        die();
    }
} else {
    header('Location: upload_alunos.php?error=1&msg=' . urlencode('Erro ao fazer upload do arquivo.'));
    die();
}
