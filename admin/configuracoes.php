<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$erro = '';
$sucesso = '';

// Atualiza as configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recaptcha_ativo = isset($_POST['recaptcha_ativo']) ? '1' : '0';
    $recaptcha_site_key = trim($_POST['recaptcha_site_key'] ?? '');
    $recaptcha_secret_key = trim($_POST['recaptcha_secret_key'] ?? '');

    try {
        $stmt = $conn->prepare("UPDATE configuracoes SET valor = :valor WHERE chave = :chave");

        $stmt->bindParam(':valor', $recaptcha_ativo, PDO::PARAM_STR);
        $stmt->bindValue(':chave', 'recaptcha_ativo', PDO::PARAM_STR);
        $stmt->execute();

        $stmt->bindParam(':valor', $recaptcha_site_key, PDO::PARAM_STR);
        $stmt->bindValue(':chave', 'recaptcha_site_key', PDO::PARAM_STR);
        $stmt->execute();

        $stmt->bindParam(':valor', $recaptcha_secret_key, PDO::PARAM_STR);
        $stmt->bindValue(':chave', 'recaptcha_secret_key', PDO::PARAM_STR);
        $stmt->execute();

        $sucesso = "Configurações salvas com sucesso.";
    } catch (PDOException $e) {
        $erro = "Erro ao salvar configurações: " . $e->getMessage();
    }
}

// Busca as configurações atuais
$configuracoes = [];
try {
    $stmt = $conn->query("SELECT chave, valor FROM configuracoes");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $configuracoes[$row['chave']] = $row['valor'];
    }
} catch (PDOException $e) {
    $erro = "Erro ao carregar configurações: " . $e->getMessage();
}

$recaptchaAtivo = ($configuracoes['recaptcha_ativo'] ?? '0') === '1';
$siteKey = $configuracoes['recaptcha_site_key'] ?? '';
$secretKey = $configuracoes['recaptcha_secret_key'] ?? '';
?>

<div class="mb-8 flex items-center justify-between">
    <div>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Configurações do Sistema</h1>
        <p class="text-slate-500 mt-1">Gerencie chaves de API e recursos de segurança</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-4xl mx-auto">
    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
        <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <i class="ph-fill ph-shield-check text-[#00b48d]"></i> Segurança e Anti-Bot (reCAPTCHA v2)
        </h2>
    </div>

    <div class="p-6 sm:p-8">
        <?php if ($erro): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-800 p-4 rounded-lg shadow-sm flex items-start">
                <i class="ph-fill ph-warning-circle text-red-500 text-xl mr-3 mt-0.5"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($erro) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-lg shadow-sm flex items-start">
                <i class="ph-fill ph-check-circle text-emerald-500 text-xl mr-3 mt-0.5"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($sucesso) ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-8">
                <label class="flex items-center cursor-pointer p-4 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                    <div class="relative">
                        <input type="checkbox" name="recaptcha_ativo" id="recaptcha_ativo" class="sr-only" <?= $recaptchaAtivo ? 'checked' : '' ?> onchange="toggleFields()">
                        <div class="block bg-slate-200 w-14 h-8 rounded-full shadow-inner transition-colors duration-300" id="toggle-bg"></div>
                        <div class="dot absolute left-1 top-1 bg-white w-6 h-6 rounded-full transition transform duration-300 shadow" id="toggle-dot"></div>
                    </div>
                    <div class="ml-4">
                        <span class="block text-sm font-bold text-slate-800">Ativar Google reCAPTCHA v2</span>
                        <span class="block text-xs text-slate-500 mt-0.5">Se ativo, protege o login do Admin e a consulta do Aluno contra ataques de força bruta.</span>
                    </div>
                </label>
            </div>

            <div id="recaptcha-fields" class="space-y-6 <?= $recaptchaAtivo ? 'block' : 'hidden' ?> transition-all duration-300">
                <div class="bg-blue-50 border border-blue-200 text-blue-800 p-4 rounded-lg shadow-sm text-sm">
                    <i class="ph-fill ph-info mr-1 text-blue-500"></i> Para obter essas chaves, acesse o painel do <strong>Google reCAPTCHA</strong>, crie um projeto usando a versão <strong>v2 (Caixa de seleção "Não sou um robô")</strong> e adicione o seu domínio.
                </div>

                <div>
                    <label for="recaptcha_site_key" class="block text-sm font-bold text-slate-700 mb-1">Chave de Site (Site Key)</label>
                    <input type="text" id="recaptcha_site_key" name="recaptcha_site_key" value="<?= htmlspecialchars($siteKey) ?>"
                           class="block w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary font-mono text-slate-600 shadow-inner">
                </div>

                <div>
                    <label for="recaptcha_secret_key" class="block text-sm font-bold text-slate-700 mb-1">Chave Secreta (Secret Key)</label>
                    <input type="password" id="recaptcha_secret_key" name="recaptcha_secret_key" value="<?= htmlspecialchars($secretKey) ?>"
                           class="block w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary font-mono text-slate-600 shadow-inner">
                    <p class="text-xs text-slate-500 mt-1">Mantenha esta chave confidencial. Ela é usada para comunicação segura com os servidores do Google.</p>
                </div>
            </div>

            <div class="mt-8 flex justify-end pt-5 border-t border-slate-100">
                <button type="submit" class="px-6 py-3 bg-primary text-white rounded-lg text-sm font-bold shadow-md hover:shadow-lg hover:bg-emerald-600 focus:ring-2 focus:ring-primary focus:ring-offset-2 transition-all flex items-center">
                    <i class="ph-bold ph-floppy-disk mr-2 text-lg"></i> Salvar Configurações
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    /* Toggle Switch Custom CSS */
    input:checked ~ #toggle-bg { background-color: #00b48d; }
    input:checked ~ #toggle-dot { transform: translateX(100%); background-color: white; }
</style>

<script>
function toggleFields() {
    const isChecked = document.getElementById('recaptcha_ativo').checked;
    const fields = document.getElementById('recaptcha-fields');
    if (isChecked) {
        fields.classList.remove('hidden');
        fields.classList.add('block');
    } else {
        fields.classList.remove('block');
        fields.classList.add('hidden');
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
