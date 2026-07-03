<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Não autenticado.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once '../includes/Database.php';

$adminRole  = $_SESSION['admin_role']  ?? '';
$adminCurso = $_SESSION['admin_curso'] ?? null;

$raw = file_get_contents('php://input');
if (!$raw) { echo json_encode(['success' => false, 'message' => 'Nenhum dado recebido.']); exit; }

$payload = json_decode($raw, true);
if (!$payload) { echo json_encode(['success' => false, 'message' => 'JSON inválido.']); exit; }

$alunos = $payload['alunos'] ?? [];
if (!count($alunos)) {
    echo json_encode(['success' => false, 'message' => 'Nenhum aluno encontrado nos dados.']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    $stmtAluno = $conn->prepare(
        "INSERT INTO alunos
             (ra, nome, cpf, data_nascimento, email, curso, cod_perfil, status, periodo_letivo, periodo, turma)
         VALUES
             (:ra, :nome, :cpf, :data_nascimento, :email, :curso, :cod_perfil, :status, :periodo_letivo, :periodo, :turma)
         ON DUPLICATE KEY UPDATE
             nome            = COALESCE(VALUES(nome), nome),
             cpf             = COALESCE(VALUES(cpf), cpf),
             data_nascimento = COALESCE(VALUES(data_nascimento), data_nascimento),
             email           = COALESCE(VALUES(email), email),
             curso           = COALESCE(VALUES(curso), curso),
             cod_perfil      = COALESCE(VALUES(cod_perfil), cod_perfil),
             status          = VALUES(status),
             periodo_letivo  = VALUES(periodo_letivo),
             periodo         = VALUES(periodo),
             turma           = VALUES(turma),
             updated_at      = CURRENT_TIMESTAMP"
    );

    $stmtCurso = $conn->prepare("INSERT IGNORE INTO cursos (nome) VALUES (:nome)");

    $count   = 0;
    $skipped = 0;
    foreach ($alunos as $a) {
        $ra            = trim($a['ra']             ?? '');
        $periodo       = trim($a['periodo']        ?? '');
        $cursoRow      = trim($a['curso']          ?? '');
        $periodoLetivo = trim($a['periodo_letivo'] ?? '');

        if (!$ra || !$periodo || !$cursoRow || !$periodoLetivo) { $skipped++; continue; }

        if ($adminRole === 'coordinator' && $adminCurso && $cursoRow !== $adminCurso) { $skipped++; continue; }

        $dtNasc = null;
        $dtRaw  = trim($a['dt_nascimento'] ?? '');
        if ($dtRaw) {
            $d = DateTime::createFromFormat('Y-m-d', $dtRaw)
              ?: DateTime::createFromFormat('d/m/Y', $dtRaw);
            if ($d) $dtNasc = $d->format('Y-m-d');
        }

        $stmtAluno->execute([
            ':ra'             => $ra,
            ':nome'           => trim($a['nome']       ?? '') ?: null,
            ':cpf'            => trim($a['cpf']        ?? '') ?: null,
            ':data_nascimento'=> $dtNasc,
            ':email'          => trim($a['email']      ?? '') ?: null,
            ':curso'          => $cursoRow,
            ':cod_perfil'     => trim($a['cod_perfil'] ?? '') ?: null,
            ':status'         => trim($a['status']     ?? '') ?: null,
            ':periodo_letivo' => $periodoLetivo,
            ':periodo'        => $periodo,
            ':turma'          => trim($a['turma']      ?? '') ?: null,
        ]);

        $stmtCurso->execute([':nome' => $cursoRow]);

        $count++;
    }

    $msg = "{$count} aluno(s) importado(s)/atualizado(s).";
    if ($skipped) $msg .= " {$skipped} ignorado(s) (dados incompletos ou curso diferente).";

    echo json_encode(['success' => true, 'message' => $msg, 'count' => $count]);

} catch (PDOException $e) {
    error_log('[alunos_di_process] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar no banco: ' . $e->getMessage()]);
}
