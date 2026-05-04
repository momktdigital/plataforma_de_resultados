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

        $smtp_ativo = isset($_POST['smtp_ativo']) ? '1' : '0';
        $smtp_host = trim($_POST['smtp_host'] ?? '');
        $smtp_port = trim($_POST['smtp_port'] ?? '');
        $smtp_user = trim($_POST['smtp_user'] ?? '');
        $smtp_pass = trim($_POST['smtp_pass'] ?? '');
        $smtp_from_email = trim($_POST['smtp_from_email'] ?? '');
        $smtp_from_name = trim($_POST['smtp_from_name'] ?? '');

        $stmt->bindParam(':valor', $smtp_ativo, PDO::PARAM_STR);
        $stmt->bindValue(':chave', 'smtp_ativo', PDO::PARAM_STR);
        $stmt->execute();

        $stmt->bindParam(':valor', $smtp_host, PDO::PARAM_STR);
        $stmt->bindValue(':chave', 'smtp_host', PDO::PARAM_STR);
        $stmt->execute();

        $stmt->bindParam(':valor', $smtp_port, PDO::PARAM_STR);
        $stmt->bindValue(':chave', 'smtp_port', PDO::PARAM_STR);
        $stmt->execute();

        $stmt->bindParam(':valor', $smtp_user, PDO::PARAM_STR);
        $stmt->bindValue(':chave', 'smtp_user', PDO::PARAM_STR);
        $stmt->execute();

        if (!empty($smtp_pass)) {
            $stmt->bindParam(':valor', $smtp_pass, PDO::PARAM_STR);
            $stmt->bindValue(':chave', 'smtp_pass', PDO::PARAM_STR);
            $stmt->execute();
        }

        $stmt->bindParam(':valor', $smtp_from_email, PDO::PARAM_STR);
        $stmt->bindValue(':chave', 'smtp_from_email', PDO::PARAM_STR);
        $stmt->execute();

        $stmt->bindParam(':valor', $smtp_from_name, PDO::PARAM_STR);
        $stmt->bindValue(':chave', 'smtp_from_name', PDO::PARAM_STR);
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

$smtpAtivo = ($configuracoes['smtp_ativo'] ?? '0') === '1';
$smtpHost = $configuracoes['smtp_host'] ?? '';
$smtpPort = $configuracoes['smtp_port'] ?? '';
$smtpUser = $configuracoes['smtp_user'] ?? '';
$smtpFromEmail = $configuracoes['smtp_from_email'] ?? '';
$smtpFromName = $configuracoes['smtp_from_name'] ?? '';
$smtpPassExists = !empty($configuracoes['smtp_pass']);

?>

<div class="mb-8 flex items-center justify-between">
    <div>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Configurações do Sistema</h1>
        <p class="text-slate-500 mt-1">Gerencie chaves de API e recursos de segurança</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-4xl mx-auto mb-8">
    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
        <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <i class="ph-fill ph-envelope text-[#00b48d]"></i> Configurações de e-mail (2FA)
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
            <!-- TABS -->
            <div class="border-b border-slate-200 mb-6">
                <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                    <button type="button" class="tab-btn active border-primary text-primary whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" onclick="showTab('tab-config-email')">
                        Configuração de e-mail
                    </button>
                    <button type="button" class="tab-btn border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" onclick="showTab('tab-smtp')">
                        SMTP
                    </button>
                </nav>
            </div>

            <div id="tab-config-email" class="tab-content block">
                <div class="mb-6">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="smtp_ativo" class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded" <?= $smtpAtivo ? 'checked' : '' ?>>
                        <span class="ml-2 block text-sm font-medium text-slate-700">SMTP ativado</span>
                    </label>
                </div>

                <div class="space-y-4">
                    <div>
                        <label for="smtp_from_name" class="block text-sm font-bold text-slate-700 mb-1">Nome do Remetente</label>
                        <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= htmlspecialchars($smtpFromName) ?>" class="block w-full px-4 py-2 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                    </div>
                    <div>
                        <label for="smtp_from_email" class="block text-sm font-bold text-slate-700 mb-1">E-mail do Remetente</label>
                        <input type="email" id="smtp_from_email" name="smtp_from_email" value="<?= htmlspecialchars($smtpFromEmail) ?>" class="block w-full px-4 py-2 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                    </div>
                </div>
            </div>

            <div id="tab-smtp" class="tab-content hidden space-y-4">
                <div>
                    <label for="smtp_user" class="block text-sm font-bold text-slate-700 mb-1">Login</label>
                    <input type="text" id="smtp_user" name="smtp_user" value="<?= htmlspecialchars($smtpUser) ?>" class="block w-full px-4 py-2 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                </div>
                <div>
                    <label for="smtp_pass" class="block text-sm font-bold text-slate-700 mb-1">Senha</label>
                    <input type="password" id="smtp_pass" name="smtp_pass" placeholder="<?= $smtpPassExists ? '******** (Deixe em branco para não alterar)' : '' ?>" class="block w-full px-4 py-2 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                </div>
                <div>
                    <label for="smtp_host" class="block text-sm font-bold text-slate-700 mb-1">Endereço*</label>
                    <input type="text" id="smtp_host" name="smtp_host" value="<?= htmlspecialchars($smtpHost) ?>" class="block w-full px-4 py-2 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                </div>
                <div>
                    <label for="smtp_port" class="block text-sm font-bold text-slate-700 mb-1">Porta*</label>
                    <input type="text" id="smtp_port" name="smtp_port" value="<?= htmlspecialchars($smtpPort) ?>" class="block w-full px-4 py-2 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                </div>
            </div>

            <div class="mt-8 flex justify-start pt-5 border-t border-slate-100">
                <button type="submit" class="px-6 py-2 bg-slate-500 text-white rounded text-sm font-bold shadow-sm hover:bg-slate-600 focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 transition-all mr-2">
                    Salvar
                </button>
            </div>
        </form>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-4xl mx-auto">
    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
        <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <i class="ph-fill ph-shield-check text-[#00b48d]"></i> Segurança e Anti-Bot (reCAPTCHA v2)
        </h2>
    </div>

    <div class="p-6 sm:p-8">
        <form method="POST" action="">
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
function showTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => {
        el.classList.add('hidden');
        el.classList.remove('block');
    });

    document.querySelectorAll('.tab-btn').forEach(el => {
        el.classList.remove('border-primary', 'text-primary');
        el.classList.add('border-transparent', 'text-slate-500', 'hover:text-slate-700', 'hover:border-slate-300');
    });

    const content = document.getElementById(tabId);
    if(content) {
        content.classList.remove('hidden');
        content.classList.add('block');
    }

    const btn = document.querySelector(`button[onclick="showTab('${tabId}')"]`);
    if(btn) {
        btn.classList.remove('border-transparent', 'text-slate-500', 'hover:text-slate-700', 'hover:border-slate-300');
        btn.classList.add('border-primary', 'text-primary');
    }
}

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
