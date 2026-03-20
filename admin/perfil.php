<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$mensagem = '';
$tipoMensagem = '';
$loggedAdminId = $_SESSION['admin_id'];

// Ação: Alterar Senha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!empty($currentPassword) && !empty($newPassword) && !empty($confirmPassword)) {
        if ($newPassword === $confirmPassword) {

            // Buscar dados atuais do usuário para verificar a senha antiga
            try {
                $stmt = $conn->prepare("SELECT username, password_hash FROM admins WHERE id = :id LIMIT 1");
                $stmt->bindParam(':id', $loggedAdminId, PDO::PARAM_INT);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $admin = $stmt->fetch();

                    if (password_verify($currentPassword, $admin['password_hash'])) {
                        // Senha atual confere, vamos atualizar para a nova
                        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                        $updateStmt = $conn->prepare("UPDATE admins SET password_hash = :hash WHERE id = :id");
                        $updateStmt->bindParam(':hash', $newHash);
                        $updateStmt->bindParam(':id', $loggedAdminId, PDO::PARAM_INT);
                        $updateStmt->execute();

                        $mensagem = "Senha alterada com sucesso! Utilize a nova senha no próximo login.";
                        $tipoMensagem = 'success';
                    } else {
                        $mensagem = "A senha atual informada está incorreta.";
                        $tipoMensagem = 'error';
                    }
                } else {
                    $mensagem = "Usuário não encontrado na base de dados.";
                    $tipoMensagem = 'error';
                }
            } catch (PDOException $e) {
                $mensagem = "Erro no banco de dados: " . $e->getMessage();
                $tipoMensagem = 'error';
            }
        } else {
            $mensagem = "As senhas novas não coincidem.";
            $tipoMensagem = 'error';
        }
    } else {
        $mensagem = "Por favor, preencha todos os campos obrigatórios.";
        $tipoMensagem = 'error';
    }
}
?>

<div class="mb-8 max-w-2xl mx-auto">
    <h1 class="text-3xl font-bold text-slate-800 tracking-tight text-center sm:text-left">Meu Perfil</h1>
    <p class="text-slate-500 mt-1 text-center sm:text-left">Atualize suas credenciais de acesso</p>
</div>

<?php if ($mensagem): ?>
    <div class="mb-6 max-w-2xl mx-auto p-4 rounded-lg <?= $tipoMensagem === 'success' ? 'bg-emerald-50 text-emerald-800 border-l-4 border-emerald-500' : 'bg-red-50 text-red-800 border-l-4 border-red-500' ?> flex items-center">
        <i class="ph-fill <?= $tipoMensagem === 'success' ? 'ph-check-circle text-emerald-500' : 'ph-warning-circle text-red-500' ?> text-2xl mr-3"></i>
        <span><?= htmlspecialchars($mensagem) ?></span>
    </div>
<?php endif; ?>

<div class="max-w-2xl mx-auto bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">

    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex items-center">
        <h3 class="text-lg font-bold text-slate-800"><i class="ph-fill ph-lock-key text-primary mr-2"></i> Alterar Senha de Acesso</h3>
    </div>

    <div class="p-6 md:p-8">

        <div class="bg-blue-50 text-blue-800 p-4 rounded-lg text-sm mb-6 flex items-start border border-blue-200">
            <i class="ph-fill ph-info text-blue-500 text-xl mr-3 mt-0.5"></i>
            <div>
                <strong>Atenção:</strong> Por motivos de segurança, você precisa informar a sua senha atual antes de cadastrar uma nova. Mantenha suas senhas seguras.
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="change_password">

            <div class="space-y-5">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2" for="current_password">Senha Atual <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="ph-fill ph-lock-key-open text-slate-400"></i>
                        </div>
                        <input type="password" id="current_password" name="current_password" required class="block w-full pl-10 pr-3 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary shadow-sm" placeholder="••••••••">
                    </div>
                </div>

                <hr class="border-slate-100 my-4">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2" for="new_password">Nova Senha <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="ph-fill ph-lock-key text-slate-400"></i>
                            </div>
                            <input type="password" id="new_password" name="new_password" required minlength="4" class="block w-full pl-10 pr-3 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary shadow-sm" placeholder="Nova Senha">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2" for="confirm_password">Confirmar Nova Senha <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="ph-fill ph-check-circle text-slate-400"></i>
                            </div>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="4" class="block w-full pl-10 pr-3 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary shadow-sm" placeholder="Repetir Nova Senha">
                        </div>
                    </div>
                </div>

            </div>

            <div class="mt-8 flex justify-end">
                <button type="submit" class="bg-primary hover:bg-emerald-600 text-white font-bold py-2.5 px-6 rounded-lg shadow-sm transition-colors flex items-center justify-center min-w-[150px]">
                    <i class="ph-bold ph-floppy-disk mr-2"></i> Salvar Senha
                </button>
            </div>

        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
