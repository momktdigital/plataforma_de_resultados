<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$mensagem = '';
$tipoMensagem = '';

// Lógica de Exclusão por Período
if (isset($_POST['action']) && $_POST['action'] === 'delete_periodo') {
    $periodoDel = $_POST['periodo_delete'] ?? '';
    if (!empty($periodoDel)) {
        try {
            $stmt = $conn->prepare("DELETE FROM resultados WHERE periodo = :periodo");
            $stmt->bindParam(':periodo', $periodoDel);
            $stmt->execute();
            $mensagem = "Período '$periodoDel' excluído com sucesso!";
            $tipoMensagem = 'success';
        } catch (PDOException $e) {
            $mensagem = "Erro ao excluir: " . $e->getMessage();
            $tipoMensagem = 'error';
        }
    }
}

// Lógica de Exclusão Total (TRUNCATE)
if (isset($_POST['action']) && $_POST['action'] === 'truncate_db') {
    try {
        $conn->exec("TRUNCATE TABLE resultados");
        $mensagem = "Todos os resultados foram excluídos com sucesso!";
        $tipoMensagem = 'success';
    } catch (PDOException $e) {
        $mensagem = "Erro ao limpar banco: " . $e->getMessage();
        $tipoMensagem = 'error';
    }
}

// Filtros
$searchRa = $_GET['search'] ?? '';
$filterPeriodo = $_GET['periodo'] ?? '';

// Paginação
$limit = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Buscar lista de períodos para o select filter
$periodosList = [];
try {
    $stmt = $conn->query("SELECT DISTINCT periodo FROM resultados ORDER BY periodo DESC");
    $periodosList = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Ignorar ou logar erro
}

// Montar query principal com filtros
$where = [];
$params = [];

if (!empty($searchRa)) {
    $where[] = "ra LIKE :ra";
    $params[':ra'] = "%$searchRa%";
}
if (!empty($filterPeriodo)) {
    $where[] = "periodo = :periodo";
    $params[':periodo'] = $filterPeriodo;
}

$whereSql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// Contar total para paginação
$totalRows = 0;
try {
    $countSql = "SELECT COUNT(*) AS total FROM resultados $whereSql";
    $stmtCount = $conn->prepare($countSql);
    foreach ($params as $key => $val) {
        $stmtCount->bindValue($key, $val);
    }
    $stmtCount->execute();
    $totalRows = $stmtCount->fetchColumn();
} catch (PDOException $e) {
    // Ignorar ou logar
}

$totalPages = ceil($totalRows / $limit);

// Buscar os dados da página atual
$resultados = [];
try {
    $sql = "SELECT id, ra, periodo, respostas, notas_finais, updated_at
            FROM resultados
            $whereSql
            ORDER BY updated_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);

    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    // Limit e Offset precisam ser inteiros
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $resultados = $stmt->fetchAll();
} catch (PDOException $e) {
    // Erro ao buscar dados
}

?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Gestão de Resultados</h1>
    <p class="text-slate-500 mt-1">Visualize e gerencie as notas de todos os alunos</p>
</div>

<?php if ($mensagem): ?>
    <div class="mb-6 p-4 rounded-lg <?= $tipoMensagem === 'success' ? 'bg-emerald-50 text-emerald-800 border-l-4 border-emerald-500' : 'bg-red-50 text-red-800 border-l-4 border-red-500' ?> flex items-center">
        <i class="ph-fill <?= $tipoMensagem === 'success' ? 'ph-check-circle text-emerald-500' : 'ph-warning-circle text-red-500' ?> text-2xl mr-3"></i>
        <span><?= htmlspecialchars($mensagem) ?></span>
    </div>
<?php endif; ?>

<!-- Painel de Ações e Filtros -->
<div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 mb-6 flex flex-col md:flex-row gap-6 justify-between items-start md:items-center">

    <!-- Filtros de Busca -->
    <form method="GET" class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
        <div class="relative w-full sm:w-48">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="ph ph-magnifying-glass text-slate-400"></i>
            </div>
            <input type="text" name="search" value="<?= htmlspecialchars($searchRa) ?>" placeholder="Buscar RA..." class="block w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
        </div>

        <div class="w-full sm:w-48">
            <select name="periodo" class="block w-full pl-3 pr-8 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary bg-white appearance-none">
                <option value="">Todos os Períodos</option>
                <?php foreach ($periodosList as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $filterPeriodo === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
            Filtrar
        </button>
        <?php if (!empty($searchRa) || !empty($filterPeriodo)): ?>
            <a href="resultados.php" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center justify-center">
                Limpar
            </a>
        <?php endif; ?>
    </form>

    <!-- Botões Destrutivos -->
    <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
        <button onclick="document.getElementById('modal-delete-period').classList.remove('hidden')" class="bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 hover:text-red-600 px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center justify-center shadow-sm">
            <i class="ph ph-trash mr-2"></i> Excluir por Período
        </button>

        <button onclick="document.getElementById('modal-truncate').classList.remove('hidden')" class="bg-red-50 text-red-600 hover:bg-red-600 hover:text-white border border-red-200 hover:border-red-600 px-4 py-2 rounded-lg text-sm font-bold transition-colors flex items-center justify-center shadow-sm">
            <i class="ph-bold ph-warning-circle mr-2"></i> Danger Zone
        </button>
    </div>
</div>

<!-- Tabela de Resultados -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider w-16">ID</th>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">RA</th>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Período</th>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Atualizado em</th>
                    <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-wider">Ações</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-slate-200">
                <?php if (count($resultados) > 0): ?>
                    <?php foreach ($resultados as $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                #<?= $row['id'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-bold text-slate-800"><i class="ph-fill ph-identification-card text-primary mr-1"></i> <?= htmlspecialchars($row['ra']) ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">
                                    <?= htmlspecialchars($row['periodo']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                <?= date('d/m/Y H:i', strtotime($row['updated_at'])) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <button onclick='viewDetails(<?= json_encode($row['respostas']) ?>, <?= json_encode($row['notas_finais']) ?>, "<?= htmlspecialchars($row['ra']) ?>")' class="text-primary hover:text-emerald-700 transition-colors flex items-center justify-end w-full">
                                    <i class="ph ph-eye text-lg mr-1"></i> Visualizar
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4">
                                <i class="ph ph-magnifying-glass text-2xl text-slate-400"></i>
                            </div>
                            <h3 class="text-sm font-medium text-slate-900">Nenhum resultado encontrado</h3>
                            <p class="text-sm text-slate-500 mt-1">Tente ajustar seus filtros de busca.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <?php if ($totalPages > 1): ?>
        <div class="bg-white px-6 py-4 border-t border-slate-200 flex items-center justify-between">
            <div class="text-sm text-slate-500">
                Mostrando <span class="font-medium"><?= count($resultados) ?></span> de <span class="font-medium"><?= $totalRows ?></span> resultados
            </div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($searchRa) ?>&periodo=<?= urlencode($filterPeriodo) ?>" class="px-3 py-1 border border-slate-300 rounded-md text-sm hover:bg-slate-50">Anterior</a>
                <?php endif; ?>

                <span class="px-3 py-1 border border-primary bg-primary/10 text-primary font-medium rounded-md text-sm">Página <?= $page ?> de <?= $totalPages ?></span>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($searchRa) ?>&periodo=<?= urlencode($filterPeriodo) ?>" class="px-3 py-1 border border-slate-300 rounded-md text-sm hover:bg-slate-50">Próxima</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Visualizar Detalhes -->
<div id="modal-details" class="fixed inset-0 bg-slate-900/50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-2xl w-full max-h-[90vh] flex flex-col">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50 rounded-t-2xl">
            <h3 class="text-lg font-bold text-slate-800 flex items-center">
                <i class="ph-fill ph-student text-primary mr-2"></i> Detalhes do RA: <span id="modal-ra" class="ml-2 font-mono bg-white px-2 py-1 rounded border border-slate-200"></span>
            </h3>
            <button onclick="document.getElementById('modal-details').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 transition-colors">
                <i class="ph-bold ph-x text-xl"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto bg-slate-50">

            <h4 class="text-sm font-bold text-slate-500 uppercase mb-3 flex items-center"><i class="ph-fill ph-star mr-2 text-amber-400"></i> Notas Finais</h4>
            <div id="modal-notas" class="bg-white p-4 rounded-xl border border-slate-200 mb-6 font-mono text-sm overflow-x-auto shadow-sm"></div>

            <h4 class="text-sm font-bold text-slate-500 uppercase mb-3 flex items-center"><i class="ph-fill ph-list-checks mr-2 text-primary"></i> Respostas (Gabarito)</h4>
            <div id="modal-respostas" class="bg-white p-4 rounded-xl border border-slate-200 font-mono text-sm overflow-x-auto shadow-sm max-h-64 overflow-y-auto"></div>

        </div>
        <div class="p-4 border-t border-slate-100 flex justify-end bg-white rounded-b-2xl">
            <button onclick="document.getElementById('modal-details').classList.add('hidden')" class="bg-slate-800 hover:bg-slate-700 text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors">
                Fechar
            </button>
        </div>
    </div>
</div>

<!-- Modal Excluir por Período -->
<div id="modal-delete-period" class="fixed inset-0 bg-slate-900/50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full overflow-hidden">
        <div class="p-6">
            <div class="flex items-center justify-center w-12 h-12 mx-auto bg-red-100 rounded-full mb-4">
                <i class="ph-bold ph-trash text-2xl text-red-600"></i>
            </div>
            <h3 class="text-lg font-bold text-center text-slate-800 mb-2">Excluir Registros</h3>
            <p class="text-sm text-center text-slate-500 mb-6">Selecione o período que deseja remover permanentemente do banco de dados. Esta ação não pode ser desfeita.</p>

            <form method="POST" action="resultados.php">
                <input type="hidden" name="action" value="delete_periodo">
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-700 mb-2">Período para excluir:</label>
                    <select name="periodo_delete" required class="block w-full pl-3 pr-8 py-2.5 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 bg-white">
                        <option value="">-- Selecione --</option>
                        <?php foreach ($periodosList as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-3 justify-end">
                    <button type="button" onclick="document.getElementById('modal-delete-period').classList.add('hidden')" class="px-4 py-2 bg-white border border-slate-300 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors">Cancelar</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-bold shadow-sm transition-colors flex items-center"><i class="ph ph-trash mr-2"></i> Excluir</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Truncate DB -->
<div id="modal-truncate" class="fixed inset-0 bg-slate-900/80 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl border border-red-200 max-w-md w-full overflow-hidden relative">
        <div class="absolute top-0 left-0 w-full h-2 bg-red-600"></div>
        <div class="p-6">
            <div class="flex items-center justify-center w-16 h-16 mx-auto bg-red-100 rounded-full mb-4">
                <i class="ph-fill ph-warning-circle text-3xl text-red-600 animate-pulse"></i>
            </div>
            <h3 class="text-xl font-bold text-center text-slate-800 mb-2 uppercase tracking-wide">Atenção! Perigo Real</h3>
            <p class="text-sm text-center text-slate-600 mb-6">
                Você está prestes a apagar <strong>TODOS OS RESULTADOS</strong> de todos os alunos e períodos. O banco de dados ficará completamente vazio.
            </p>

            <div class="bg-red-50 border border-red-200 p-3 rounded-lg text-xs text-red-800 font-bold text-center mb-6">
                ESTA AÇÃO É IRREVERSÍVEL!
            </div>

            <form method="POST" action="resultados.php">
                <input type="hidden" name="action" value="truncate_db">
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-700 mb-2">Para confirmar, digite "LIMPAR BANCO":</label>
                    <input type="text" id="truncate-confirm" onkeyup="checkTruncate()" class="block w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 font-mono text-center uppercase">
                </div>
                <div class="flex flex-col gap-3">
                    <button type="button" onclick="document.getElementById('modal-truncate').classList.add('hidden'); document.getElementById('truncate-confirm').value=''" class="w-full px-4 py-3 bg-white border border-slate-300 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors">Cancelar e Voltar Seguro</button>
                    <button type="submit" id="btn-truncate" disabled class="w-full px-4 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-bold shadow-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex justify-center items-center"><i class="ph-bold ph-skull mr-2"></i> EXCLUIR TUDO DEFINITIVAMENTE</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function viewDetails(respostasJson, notasJson, ra) {
    document.getElementById('modal-ra').innerText = ra;

    // Parse strings JSON se estiverem em formato string, ou usar o objeto se já estiver parseado pelo json_encode do PHP
    let r = typeof respostasJson === 'string' ? JSON.parse(respostasJson || '{}') : respostasJson;
    let n = typeof notasJson === 'string' ? JSON.parse(notasJson || '{}') : notasJson;

    // Formatar bonita pra tela
    document.getElementById('modal-respostas').innerHTML = '<pre class="text-xs text-slate-600">' + JSON.stringify(r, null, 4) + '</pre>';
    document.getElementById('modal-notas').innerHTML = '<pre class="text-xs text-primary font-bold">' + JSON.stringify(n, null, 4) + '</pre>';

    document.getElementById('modal-details').classList.remove('hidden');
}

function checkTruncate() {
    const input = document.getElementById('truncate-confirm').value;
    const btn = document.getElementById('btn-truncate');
    if (input === 'LIMPAR BANCO') {
        btn.disabled = false;
        btn.classList.add('animate-pulse');
    } else {
        btn.disabled = true;
        btn.classList.remove('animate-pulse');
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
