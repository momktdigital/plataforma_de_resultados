<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$mensagem = '';
$tipoMensagem = '';

// ID do admin logado atualmente
$loggedAdminId = $_SESSION['admin_id'];

// Ação: Criar Usuário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_admin') {
    $username = trim($_POST['new_username'] ?? '');
    $password = $_POST['new_password'] ?? '';

    if (!empty($username) && !empty($password)) {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admins (username, password_hash) VALUES (:username, :hash)");
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':hash', $hash);
            $stmt->execute();

            $mensagem = "Administrador '$username' criado com sucesso!";
            $tipoMensagem = 'success';
        } catch (PDOException $e) {
            // Verifica se é erro de duplicidade
            if ($e->getCode() == 23000) {
                $mensagem = "Erro: O nome de usuário '$username' já existe.";
            } else {
                $mensagem = "Erro ao criar administrador: " . $e->getMessage();
            }
            $tipoMensagem = 'error';
        }
    } else {
        $mensagem = "Preencha todos os campos.";
        $tipoMensagem = 'error';
    }
}

// Ação: Excluir Usuário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_admin') {
    $idDelete = (int)($_POST['admin_id'] ?? 0);

    if ($idDelete > 0) {
        if ($idDelete === $loggedAdminId) {
            $mensagem = "Ação negada: Você não pode excluir a sua própria conta logada.";
            $tipoMensagem = 'error';
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM admins WHERE id = :id");
                $stmt->bindParam(':id', $idDelete, PDO::PARAM_INT);
                $stmt->execute();

                $mensagem = "Administrador excluído com sucesso!";
                $tipoMensagem = 'success';
            } catch (PDOException $e) {
                $mensagem = "Erro ao excluir administrador: " . $e->getMessage();
                $tipoMensagem = 'error';
            }
        }
    }
}

// Buscar todos os administradores
$admins = [];
try {
    $stmt = $conn->query("SELECT id, username, created_at FROM admins ORDER BY id ASC");
    $admins = $stmt->fetchAll();
} catch (PDOException $e) {
    // Log
}
?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Administradores</h1>
    <p class="text-slate-500 mt-1">Gerencie quem tem acesso ao painel</p>
</div>

<?php if ($mensagem): ?>
    <div class="mb-6 p-4 rounded-lg <?= $tipoMensagem === 'success' ? 'bg-emerald-50 text-emerald-800 border-l-4 border-emerald-500' : 'bg-red-50 text-red-800 border-l-4 border-red-500' ?> flex items-center">
        <i class="ph-fill <?= $tipoMensagem === 'success' ? 'ph-check-circle text-emerald-500' : 'ph-warning-circle text-red-500' ?> text-2xl mr-3"></i>
        <span><?= htmlspecialchars($mensagem) ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

    <!-- Tabela de Admins -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                <h3 class="text-lg font-bold text-slate-800"><i class="ph-fill ph-users text-primary mr-2"></i> Usuários Ativos</h3>
                <span class="bg-primary/10 text-primary font-bold px-3 py-1 rounded-full text-xs"><?= count($admins) ?></span>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-white">
                        <tr>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider w-16">ID</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Usuário</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Criado em</th>
                            <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-wider w-24">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        <?php foreach ($admins as $admin): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500 text-center font-mono">
                                    #<?= $admin['id'] ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center mr-3 text-slate-500">
                                            <i class="ph-fill ph-user"></i>
                                        </div>
                                        <span class="text-sm font-bold text-slate-800">
                                            <?= htmlspecialchars($admin['username']) ?>
                                            <?php if ($admin['id'] == $loggedAdminId): ?>
                                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800">Você</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                    <?= date('d/m/Y', strtotime($admin['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <?php if ($admin['id'] != $loggedAdminId): ?>
                                        <form method="POST" onsubmit="return confirm('Tem certeza que deseja excluir o administrador <?= htmlspecialchars($admin['username']) ?>?');" class="inline">
                                            <input type="hidden" name="action" value="delete_admin">
                                            <input type="hidden" name="admin_id" value="<?= $admin['id'] ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 px-3 py-1.5 rounded-md transition-colors flex items-center">
                                                <i class="ph-bold ph-trash mr-1"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-slate-300 px-3 py-1.5 cursor-not-allowed" title="Você não pode se excluir"><i class="ph-bold ph-trash"></i></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Formulário Criar Admin -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50">
                <h3 class="text-lg font-bold text-slate-800"><i class="ph-fill ph-user-plus text-primary mr-2"></i> Novo Administrador</h3>
            </div>

            <form method="POST" class="p-6">
                <input type="hidden" name="action" value="create_admin">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-2" for="new_username">Nome de Usuário</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="ph-fill ph-user text-slate-400"></i>
                        </div>
                        <input type="text" id="new_username" name="new_username" required class="block w-full pl-10 pr-3 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary" placeholder="Ex: professor">
                    </div>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-700 mb-2" for="new_password">Senha</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="ph-fill ph-lock-key text-slate-400"></i>
                        </div>
                        <input type="password" id="new_password" name="new_password" required minlength="4" class="block w-full pl-10 pr-3 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary" placeholder="••••••••">
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Mínimo de 4 caracteres.</p>
                </div>

                <button type="submit" class="w-full bg-primary hover:bg-emerald-600 text-white font-bold py-2.5 px-4 rounded-lg shadow-sm transition-colors flex items-center justify-center">
                    <i class="ph-bold ph-plus-circle mr-2"></i> Criar Conta
                </button>
            </form>
        </div>
    </div>

</div>

<?php require_once 'includes/footer.php'; ?>
