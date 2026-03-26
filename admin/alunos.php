<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

// --- Lógica de Exclusão ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $idDelete = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($idDelete) {
        try {
            $stmt = $conn->prepare("DELETE FROM alunos WHERE id = :id");
            $stmt->bindParam(':id', $idDelete, PDO::PARAM_INT);
            $stmt->execute();
            header("Location: alunos.php?success=1&msg=" . urlencode("Aluno excluído com sucesso."));
            exit();
        } catch (PDOException $e) {
            header("Location: alunos.php?error=1&msg=" . urlencode("Erro ao excluir aluno: " . $e->getMessage()));
            exit();
        }
    }
}

// --- Lógica de Busca e Paginação ---
$search = trim($_GET['search'] ?? '');
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$limit = 50;
$offset = ($page - 1) * $limit;

try {
    // Conta o total de registros
    $countQuery = "SELECT COUNT(*) FROM alunos WHERE ra LIKE :search OR cpf LIKE :search OR nome LIKE :search";
    $countStmt = $conn->prepare($countQuery);
    $searchParam = "%$search%";
    $countStmt->bindParam(':search', $searchParam, PDO::PARAM_STR);
    $countStmt->execute();
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Busca os registros
    $query = "SELECT * FROM alunos WHERE ra LIKE :search OR cpf LIKE :search OR nome LIKE :search ORDER BY nome ASC, ra ASC LIMIT :limit OFFSET :offset";
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':search', $searchParam, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro ao carregar alunos: " . $e->getMessage());
}
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Alunos</h1>
        <p class="text-slate-500 mt-1">Gerenciamento do cadastro de estudantes</p>
    </div>
    <div class="flex gap-2">
        <a href="upload_alunos.php" class="inline-flex items-center justify-center px-4 py-2 border border-slate-300 rounded-lg shadow-sm text-sm font-medium text-slate-700 bg-white hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors">
            <i class="ph-bold ph-upload-simple mr-2"></i> Importar CSV
        </a>
        <a href="aluno_novo.php" class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors">
            <i class="ph-bold ph-plus mr-2"></i> Novo Aluno
        </a>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 mb-6 rounded-lg shadow-sm flex items-start">
        <i class="ph-fill ph-check-circle text-emerald-500 text-xl mr-3 mt-0.5"></i>
        <p class="text-sm font-medium"><?= htmlspecialchars($_GET['msg']) ?></p>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 p-4 mb-6 rounded-lg shadow-sm flex items-start">
        <i class="ph-fill ph-warning-circle text-red-500 text-xl mr-3 mt-0.5"></i>
        <p class="text-sm font-medium"><?= htmlspecialchars($_GET['msg']) ?></p>
    </div>
<?php endif; ?>

<!-- Barra de Busca -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6">
    <form method="GET" action="alunos.php" class="flex flex-col sm:flex-row gap-3">
        <div class="relative flex-grow">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="ph ph-magnifying-glass text-slate-400"></i>
            </div>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por Nome, RA ou CPF..." class="block w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
        </div>
        <button type="submit" class="inline-flex justify-center items-center px-4 py-2 bg-slate-800 text-white text-sm font-medium rounded-lg hover:bg-slate-700 transition-colors">
            Buscar
        </button>
        <?php if (!empty($search)): ?>
            <a href="alunos.php" class="inline-flex justify-center items-center px-4 py-2 border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">
                Limpar
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabela -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">RA</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Nome</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">CPF</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Nascimento</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Curso</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-slate-500 uppercase tracking-wider">Ações</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-slate-200">
                <?php if (count($alunos) > 0): ?>
                    <?php foreach ($alunos as $aluno): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">
                                <?= htmlspecialchars($aluno['ra']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                                <?= htmlspecialchars($aluno['nome'] ?: '-') ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                                <?php
                                    $cpf = $aluno['cpf'];
                                    // Formata CPF se tiver 11 dígitos
                                    if(strlen($cpf) == 11) {
                                        echo substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
                                    } else {
                                        echo htmlspecialchars($cpf);
                                    }
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                                <?= date('d/m/Y', strtotime($aluno['data_nascimento'])) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                                <?= htmlspecialchars($aluno['curso'] ?: '-') ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="aluno_form.php?id=<?= $aluno['id'] ?>" class="text-blue-600 hover:text-blue-900 mr-3" title="Editar">
                                    <i class="ph-bold ph-pencil-simple text-lg"></i>
                                </a>
                                <form method="POST" class="inline-block" onsubmit="return confirm('Tem certeza que deseja excluir o aluno <?= htmlspecialchars($aluno['nome'] ?: $aluno['ra']) ?>? Esta ação não removerá os resultados dele, apenas o cadastro de acesso.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $aluno['id'] ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700" title="Excluir">
                                        <i class="ph-bold ph-trash text-lg"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-slate-500">
                            <i class="ph-fill ph-student text-4xl mb-3 text-slate-300"></i>
                            <p class="text-base font-medium">Nenhum aluno encontrado.</p>
                            <p class="text-sm mt-1">Faça a importação via CSV ou cadastre manualmente.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <?php if ($totalPages > 1): ?>
        <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 flex items-center justify-between">
            <span class="text-sm text-slate-500">
                Página <span class="font-medium text-slate-700"><?= $page ?></span> de <span class="font-medium text-slate-700"><?= $totalPages ?></span>
                (<?= $totalRecords ?> registros)
            </span>
            <div class="flex gap-1">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="px-3 py-1 border border-slate-300 rounded text-sm bg-white text-slate-600 hover:bg-slate-50">&laquo; Ant</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="px-3 py-1 border border-slate-300 rounded text-sm bg-white text-slate-600 hover:bg-slate-50">Próx &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
