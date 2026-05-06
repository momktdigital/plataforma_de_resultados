<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_type = $_POST['form_type'] ?? '';

    if ($form_type === 'captcha') {
        $captcha_type = $_POST['captcha_type'] ?? 'none';

        $recaptcha_ativo = ($captcha_type === 'recaptcha') ? '1' : '0';
        $hcaptcha_ativo = ($captcha_type === 'hcaptcha') ? '1' : '0';

        $recaptcha_site_key = trim($_POST['recaptcha_site_key'] ?? '');
        $recaptcha_secret_key = trim($_POST['recaptcha_secret_key'] ?? '');
        $hcaptcha_site_key = trim($_POST['hcaptcha_site_key'] ?? '');
        $hcaptcha_secret_key = trim($_POST['hcaptcha_secret_key'] ?? '');

        $chaves_valores = [
            'recaptcha_ativo' => $recaptcha_ativo,
            'recaptcha_site_key' => $recaptcha_site_key,
            'recaptcha_secret_key' => $recaptcha_secret_key,
            'hcaptcha_ativo' => $hcaptcha_ativo,
            'hcaptcha_site_key' => $hcaptcha_site_key,
            'hcaptcha_secret_key' => $hcaptcha_secret_key
        ];

        try {
            foreach ($chaves_valores as $chave => $valor) {
                // Tenta fazer o update primeiro
                $stmt = $conn->prepare("UPDATE configuracoes SET valor = :valor WHERE chave = :chave");
                $stmt->execute([':valor' => $valor, ':chave' => $chave]);

                // Se nada foi atualizado (pode ser que a chave não exista em bd antigos)
                if ($stmt->rowCount() === 0) {
                    // Verifica se a chave realmente não existe
                    $checkStmt = $conn->prepare("SELECT 1 FROM configuracoes WHERE chave = :chave");
                    $checkStmt->execute([':chave' => $chave]);
                    if ($checkStmt->rowCount() === 0) {
                        $insertStmt = $conn->prepare("INSERT INTO configuracoes (chave, valor) VALUES (:chave, :valor)");
                        $insertStmt->execute([':chave' => $chave, ':valor' => $valor]);
                    }
                }
            }
            $sucesso = "Configurações de CAPTCHA salvas com sucesso.";
        } catch (PDOException $e) {
            $erro = "Erro ao salvar: " . $e->getMessage();
        }
    } elseif ($form_type === 'smtp') {
        $smtp_ativo = isset($_POST['smtp_ativo']) ? '1' : '0';
        $smtp_host = trim($_POST['smtp_host'] ?? '');
        $smtp_port = trim($_POST['smtp_port'] ?? '');
        $smtp_user = trim($_POST['smtp_user'] ?? '');
        $smtp_pass = trim($_POST['smtp_pass'] ?? '');
        $smtp_from_email = trim($_POST['smtp_from_email'] ?? '');
        $smtp_from_name = trim($_POST['smtp_from_name'] ?? '');

        try {
            $stmt = $conn->prepare("UPDATE configuracoes SET valor = :valor WHERE chave = :chave");
            $stmt->execute([':valor' => $smtp_ativo, ':chave' => 'smtp_ativo']);
            $stmt->execute([':valor' => $smtp_host, ':chave' => 'smtp_host']);
            $stmt->execute([':valor' => $smtp_port, ':chave' => 'smtp_port']);
            $stmt->execute([':valor' => $smtp_user, ':chave' => 'smtp_user']);
            if (!empty($smtp_pass)) {
                $stmt->execute([':valor' => $smtp_pass, ':chave' => 'smtp_pass']);
            }
            $stmt->execute([':valor' => $smtp_from_email, ':chave' => 'smtp_from_email']);
            $stmt->execute([':valor' => $smtp_from_name, ':chave' => 'smtp_from_name']);
            $sucesso = "Configurações de e-mail (SMTP) salvas com sucesso.";
        } catch (PDOException $e) {
            $erro = "Erro ao salvar: " . $e->getMessage();
        }
    } elseif ($form_type === 'appearance') {
        $site_title = trim($_POST['site_title'] ?? '');
        $chaves_valores = [
            'site_title' => $site_title
        ];

        if (isset($_FILES['site_logo']) && !empty($_FILES['site_logo']['name'])) {
            if ($_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                // Usando caminho absoluto para evitar problemas de resolução de diretório no Linux
                $uploadDir = realpath(__DIR__ . '/../assets') . '/img/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fileName = time() . '_light_' . basename($_FILES['site_logo']['name']);
                $uploadFile = $uploadDir . $fileName;

                $imageFileType = strtolower(pathinfo($uploadFile, PATHINFO_EXTENSION));
                $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

                if (in_array($imageFileType, $allowedTypes)) {
                    if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $uploadFile)) {
                        $chaves_valores['site_logo'] = 'assets/img/' . $fileName;
                    } elseif (copy($_FILES['site_logo']['tmp_name'], $uploadFile)) {
                        // Fallback: alguns servidores (CageFS, suPHP) podem bloquear move_uploaded_file, mas permitir copy
                        $chaves_valores['site_logo'] = 'assets/img/' . $fileName;
                    } else {
                        $lastError = error_get_last();
                        $detalhe = $lastError ? $lastError['message'] : 'Desconhecido. Verifique permissões na pasta de destino.';
                        $erro = "Erro ao mover o arquivo para a pasta 'assets/img/'. Detalhe PHP: " . $detalhe;
                    }
                } else {
                    $erro = "Tipo de arquivo inválido para a logo normal. Permitido: jpg, png, gif, webp, svg.";
                }
            } else {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'O arquivo excede o limite (upload_max_filesize) do php.ini.',
                    UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite (MAX_FILE_SIZE) do formulário.',
                    UPLOAD_ERR_PARTIAL => 'O upload foi feito parcialmente.',
                    UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente no servidor.',
                    UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever o arquivo em disco (permissão na pasta tmp).',
                    UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP interrompeu o upload.'
                ];
                $code = $_FILES['site_logo']['error'];
                $erro = "Erro no upload do arquivo (Logo Normal): " . ($uploadErrors[$code] ?? "Código de erro desconhecido: $code");
            }
        }

        // Upload da logo escura
        if (empty($erro) && isset($_FILES['site_logo_dark']) && !empty($_FILES['site_logo_dark']['name'])) {
            if ($_FILES['site_logo_dark']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = realpath(__DIR__ . '/../assets') . '/img/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fileNameDark = time() . '_dark_' . basename($_FILES['site_logo_dark']['name']);
                $uploadFileDark = $uploadDir . $fileNameDark;

                $imageFileType = strtolower(pathinfo($uploadFileDark, PATHINFO_EXTENSION));
                $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

                if (in_array($imageFileType, $allowedTypes)) {
                    if (move_uploaded_file($_FILES['site_logo_dark']['tmp_name'], $uploadFileDark)) {
                        $chaves_valores['site_logo_dark'] = 'assets/img/' . $fileNameDark;
                    } elseif (copy($_FILES['site_logo_dark']['tmp_name'], $uploadFileDark)) {
                        $chaves_valores['site_logo_dark'] = 'assets/img/' . $fileNameDark;
                    } else {
                        $lastError = error_get_last();
                        $detalhe = $lastError ? $lastError['message'] : 'Desconhecido.';
                        $erro = "Erro ao mover a Logo Escura para 'assets/img/'. Detalhe: " . $detalhe;
                    }
                } else {
                    $erro = "Tipo de arquivo inválido para a Logo Escura.";
                }
            } else {
                $code = $_FILES['site_logo_dark']['error'];
                $erro = "Erro no upload da Logo Escura (Código: $code).";
            }
        }

        if (empty($erro)) {
            try {
                foreach ($chaves_valores as $chave => $valor) {
                    $stmt = $conn->prepare("UPDATE configuracoes SET valor = :valor WHERE chave = :chave");
                    $stmt->execute([':valor' => $valor, ':chave' => $chave]);

                    if ($stmt->rowCount() === 0) {
                        $checkStmt = $conn->prepare("SELECT 1 FROM configuracoes WHERE chave = :chave");
                        $checkStmt->execute([':chave' => $chave]);
                        if ($checkStmt->rowCount() === 0) {
                            $insertStmt = $conn->prepare("INSERT INTO configuracoes (chave, valor) VALUES (:chave, :valor)");
                            $insertStmt->execute([':chave' => $chave, ':valor' => $valor]);
                        }
                    }
                }
                $sucesso = "Configurações de aparência salvas com sucesso.";
            } catch (PDOException $e) {
                $erro = "Erro ao salvar: " . $e->getMessage();
            }
        }
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

$hcaptchaAtivo = ($configuracoes['hcaptcha_ativo'] ?? '0') === '1';
$hSiteKey = $configuracoes['hcaptcha_site_key'] ?? '';
$hSecretKey = $configuracoes['hcaptcha_secret_key'] ?? '';

$smtpAtivo = ($configuracoes['smtp_ativo'] ?? '0') === '1';
$smtpHost = $configuracoes['smtp_host'] ?? '';
$smtpPort = $configuracoes['smtp_port'] ?? '';
$smtpUser = $configuracoes['smtp_user'] ?? '';
$smtpFromEmail = $configuracoes['smtp_from_email'] ?? '';
$smtpFromName = $configuracoes['smtp_from_name'] ?? '';
$smtpPassExists = !empty($configuracoes['smtp_pass']);
$siteTitle = $configuracoes['site_title'] ?? 'Resultados DI';
$siteLogo = $configuracoes['site_logo'] ?? '';
$siteLogoDark = $configuracoes['site_logo_dark'] ?? '';
$form_type = $_POST['form_type'] ?? '';
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
            <i class="ph-fill ph-palette text-[#00b48d]"></i> Aparência Geral
        </h2>
    </div>

    <div class="p-6 sm:p-8">
        <?php if ($erro && $form_type === 'appearance'): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-800 p-4 rounded-lg shadow-sm flex items-start">
                <i class="ph-fill ph-warning-circle text-red-500 text-xl mr-3 mt-0.5"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($erro) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($sucesso && $form_type === 'appearance'): ?>
            <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-lg shadow-sm flex items-start">
                <i class="ph-fill ph-check-circle text-emerald-500 text-xl mr-3 mt-0.5"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($sucesso) ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="form_type" value="appearance">

            <div class="space-y-4">
                <div>
                    <label for="site_title" class="block text-sm font-bold text-slate-700 mb-1">Título do Site</label>
                    <input type="text" id="site_title" name="site_title" value="<?= htmlspecialchars($siteTitle) ?>" class="block w-full px-4 py-2 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary">
                    <p class="text-xs text-slate-500 mt-1">Exibido na aba do navegador e no menu, caso não tenha uma logo.</p>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Logo do Site (Fundo Claro)</label>
                    <?php if (!empty($siteLogo)): ?>
                        <div class="mb-3">
                            <img src="../<?= htmlspecialchars($siteLogo) ?>" alt="Logo Atual" class="h-12 object-contain border border-slate-200 rounded p-1 bg-white">
                        </div>
                    <?php endif; ?>
                    <input type="file" id="site_logo" name="site_logo" accept="image/*" class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 transition-all">
                    <p class="text-xs text-slate-500 mt-1">Exibida na tela de consulta de resultados quando no Modo Claro.</p>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Logo do Site (Fundo Escuro)</label>
                    <?php if (!empty($siteLogoDark)): ?>
                        <div class="mb-3">
                            <img src="../<?= htmlspecialchars($siteLogoDark) ?>" alt="Logo Dark Atual" class="h-12 object-contain border border-slate-600 rounded p-1 bg-slate-800">
                        </div>
                    <?php endif; ?>
                    <input type="file" id="site_logo_dark" name="site_logo_dark" accept="image/*" class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 transition-all">
                    <p class="text-xs text-slate-500 mt-1">Usada no menu do painel Admin e na tela de resultados no Modo Escuro (use uma logo branca/clara).</p>
                </div>
            </div>

            <div class="mt-8 flex justify-start pt-5 border-t border-slate-100 gap-2">
                <button type="submit" class="px-6 py-2 bg-primary text-white rounded text-sm font-bold shadow-sm hover:bg-emerald-600 focus:ring-2 focus:ring-primary focus:ring-offset-2 transition-all">
                    Salvar
                </button>
            </div>
        </form>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-4xl mx-auto mb-8">
    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
        <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <i class="ph-fill ph-envelope text-[#00b48d]"></i> Configurações de e-mail (2FA)
        </h2>
    </div>

    <div class="p-6 sm:p-8">
        <?php if ($erro && $form_type === 'smtp'): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-800 p-4 rounded-lg shadow-sm flex items-start">
                <i class="ph-fill ph-warning-circle text-red-500 text-xl mr-3 mt-0.5"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($erro) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($sucesso && $form_type === 'smtp'): ?>
            <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-lg shadow-sm flex items-start">
                <i class="ph-fill ph-check-circle text-emerald-500 text-xl mr-3 mt-0.5"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($sucesso) ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="form_type" value="smtp">

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

            <div class="mt-8 flex justify-start pt-5 border-t border-slate-100 gap-2">
                <button type="submit" class="px-6 py-2 bg-primary text-white rounded text-sm font-bold shadow-sm hover:bg-emerald-600 focus:ring-2 focus:ring-primary focus:ring-offset-2 transition-all">
                    Salvar
                </button>
                <button type="button" onclick="openTestModal()" class="px-6 py-2 bg-slate-500 text-white rounded text-sm font-bold shadow-sm hover:bg-slate-600 focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 transition-all">
                    Teste
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Teste SMTP -->
<div id="smtp-test-modal" class="fixed inset-0 z-50 hidden bg-slate-900 bg-opacity-50 overflow-y-auto h-full w-full flex items-center justify-center p-4">
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-lg border border-slate-100 p-6 fade-in">
        <button type="button" onclick="closeTestModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">
            <i class="ph-bold ph-x text-xl"></i>
        </button>

        <div class="text-center mb-6">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-emerald-50 mb-4">
                <i class="ph ph-envelope-open text-primary text-2xl"></i>
            </div>
            <h3 class="text-lg font-bold text-slate-900" id="modal-title">Testar Envio de E-mail</h3>
            <p class="text-sm text-slate-500 mt-1" id="modal-desc">Informe um e-mail para receber o código de teste.</p>
        </div>

        <!-- Step 1: Request Email -->
        <div id="step-1">
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">E-mail de Destino</label>
                <input type="email" id="test-email-input" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-primary focus:border-primary sm:text-sm">
            </div>
            <div class="flex flex-col gap-2">
                <button type="button" onclick="sendTestEmail()" id="btn-send-test" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-primary text-base font-bold text-white hover:bg-emerald-600 focus:outline-none sm:text-sm transition-all">
                    Enviar Código
                </button>
            </div>
        </div>

        <!-- Step 2: Verify Code -->
        <div id="step-2" class="hidden">
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1 text-center">Código Recebido</label>
                <input type="text" id="test-code-input" maxlength="6" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-primary focus:border-primary text-2xl tracking-[0.5em] text-center font-bold uppercase sm:text-lg">
            </div>
            <div class="flex flex-col gap-2">
                <button type="button" onclick="verifyTestCode()" id="btn-verify-test" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-primary text-base font-bold text-white hover:bg-emerald-600 focus:outline-none sm:text-sm transition-all">
                    Validar Código
                </button>
            </div>
        </div>

        <!-- Messages -->
        <div id="test-message" class="mt-4 p-3 rounded text-sm hidden"></div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-4xl mx-auto">
    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
        <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <i class="ph-fill ph-shield-check text-[#00b48d]"></i> Segurança e Anti-Bot (CAPTCHA)
        </h2>
    </div>

    <div class="p-6 sm:p-8">
        <?php if ($erro && (!isset($form_type) || $form_type === 'captcha')): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-800 p-4 rounded-lg shadow-sm flex items-start">
                <i class="ph-fill ph-warning-circle text-red-500 text-xl mr-3 mt-0.5"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($erro) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($sucesso && (!isset($form_type) || $form_type === 'captcha')): ?>
            <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-lg shadow-sm flex items-start">
                <i class="ph-fill ph-check-circle text-emerald-500 text-xl mr-3 mt-0.5"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($sucesso) ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="form_type" value="captcha">

            <div class="mb-8 space-y-4">
                <p class="text-sm text-slate-600 mb-4">Escolha o serviço de CAPTCHA para proteger o login do Admin e a consulta do Aluno contra ataques de força bruta. Apenas um pode estar ativo por vez.</p>

                <label class="flex items-start p-4 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer <?= (!$recaptchaAtivo && !$hcaptchaAtivo) ? 'ring-2 ring-primary border-primary bg-slate-50' : '' ?>" id="label_captcha_none">
                    <div class="flex items-center h-5">
                        <input type="radio" name="captcha_type" value="none" class="w-4 h-4 text-primary bg-gray-100 border-gray-300 focus:ring-primary" <?= (!$recaptchaAtivo && !$hcaptchaAtivo) ? 'checked' : '' ?> onchange="toggleCaptchaFields()">
                    </div>
                    <div class="ml-3 text-sm">
                        <span class="block font-bold text-slate-800">Desativado</span>
                        <span class="block text-xs text-slate-500">Nenhum CAPTCHA será exigido.</span>
                    </div>
                </label>

                <label class="flex items-start p-4 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer <?= $recaptchaAtivo ? 'ring-2 ring-primary border-primary bg-slate-50' : '' ?>" id="label_captcha_recaptcha">
                    <div class="flex items-center h-5">
                        <input type="radio" name="captcha_type" value="recaptcha" class="w-4 h-4 text-primary bg-gray-100 border-gray-300 focus:ring-primary" <?= $recaptchaAtivo ? 'checked' : '' ?> onchange="toggleCaptchaFields()">
                    </div>
                    <div class="ml-3 text-sm">
                        <span class="block font-bold text-slate-800">Google reCAPTCHA v2</span>
                        <span class="block text-xs text-slate-500">Caixa de seleção "Não sou um robô".</span>
                    </div>
                </label>

                <label class="flex items-start p-4 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer <?= $hcaptchaAtivo ? 'ring-2 ring-primary border-primary bg-slate-50' : '' ?>" id="label_captcha_hcaptcha">
                    <div class="flex items-center h-5">
                        <input type="radio" name="captcha_type" value="hcaptcha" class="w-4 h-4 text-primary bg-gray-100 border-gray-300 focus:ring-primary" <?= $hcaptchaAtivo ? 'checked' : '' ?> onchange="toggleCaptchaFields()">
                    </div>
                    <div class="ml-3 text-sm">
                        <span class="block font-bold text-slate-800">hCaptcha</span>
                        <span class="block text-xs text-slate-500">Alternativa com foco em privacidade.</span>
                    </div>
                </label>
            </div>

            <div id="recaptcha-fields" class="space-y-6 <?= $recaptchaAtivo ? 'block' : 'hidden' ?> transition-all duration-300 mt-6 pt-6 border-t border-slate-100">
                <div class="bg-blue-50 border border-blue-200 text-blue-800 p-4 rounded-lg shadow-sm text-sm">
                    <i class="ph-fill ph-info mr-1 text-blue-500"></i> Para obter essas chaves, acesse o painel do <strong>Google reCAPTCHA</strong>, crie um projeto usando a versão <strong>v2 (Caixa de seleção "Não sou um robô")</strong> e adicione o seu domínio.
                </div>

                <div>
                    <label for="recaptcha_site_key" class="block text-sm font-bold text-slate-700 mb-1">Chave de Site (Site Key) - reCAPTCHA</label>
                    <input type="text" id="recaptcha_site_key" name="recaptcha_site_key" value="<?= htmlspecialchars($siteKey) ?>"
                           class="block w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary font-mono text-slate-600 shadow-inner">
                </div>

                <div>
                    <label for="recaptcha_secret_key" class="block text-sm font-bold text-slate-700 mb-1">Chave Secreta (Secret Key) - reCAPTCHA</label>
                    <input type="password" id="recaptcha_secret_key" name="recaptcha_secret_key" value="<?= htmlspecialchars($secretKey) ?>"
                           class="block w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary font-mono text-slate-600 shadow-inner">
                    <p class="text-xs text-slate-500 mt-1">Mantenha esta chave confidencial. Ela é usada para comunicação segura com os servidores do Google.</p>
                </div>
            </div>

            <div id="hcaptcha-fields" class="space-y-6 <?= $hcaptchaAtivo ? 'block' : 'hidden' ?> transition-all duration-300 mt-6 pt-6 border-t border-slate-100">
                <div class="bg-blue-50 border border-blue-200 text-blue-800 p-4 rounded-lg shadow-sm text-sm">
                    <i class="ph-fill ph-info mr-1 text-blue-500"></i> Para obter essas chaves, acesse o painel do <strong>hCaptcha</strong>, crie um novo site e obtenha a Sitekey e a Secret key.
                </div>

                <div>
                    <label for="hcaptcha_site_key" class="block text-sm font-bold text-slate-700 mb-1">Chave de Site (Sitekey) - hCaptcha</label>
                    <input type="text" id="hcaptcha_site_key" name="hcaptcha_site_key" value="<?= htmlspecialchars($hSiteKey) ?>"
                           class="block w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary font-mono text-slate-600 shadow-inner">
                </div>

                <div>
                    <label for="hcaptcha_secret_key" class="block text-sm font-bold text-slate-700 mb-1">Chave Secreta (Secret key) - hCaptcha</label>
                    <input type="password" id="hcaptcha_secret_key" name="hcaptcha_secret_key" value="<?= htmlspecialchars($hSecretKey) ?>"
                           class="block w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary font-mono text-slate-600 shadow-inner">
                    <p class="text-xs text-slate-500 mt-1">Mantenha esta chave confidencial.</p>
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

    .fade-in { animation: fadeIn 0.3s ease-in-out; }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .msg-error { background-color: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .msg-success { background-color: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .msg-info { background-color: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
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
function toggleCaptchaFields() {
    const selected = document.querySelector('input[name="captcha_type"]:checked').value;

    const recaptchaFields = document.getElementById('recaptcha-fields');
    const hcaptchaFields = document.getElementById('hcaptcha-fields');

    // Reset classes
    document.getElementById('label_captcha_none').classList.remove('ring-2', 'ring-primary', 'border-primary', 'bg-slate-50');
    document.getElementById('label_captcha_recaptcha').classList.remove('ring-2', 'ring-primary', 'border-primary', 'bg-slate-50');
    document.getElementById('label_captcha_hcaptcha').classList.remove('ring-2', 'ring-primary', 'border-primary', 'bg-slate-50');

    // Add selected classes
    document.getElementById(`label_captcha_${selected}`).classList.add('ring-2', 'ring-primary', 'border-primary', 'bg-slate-50');

    if (selected === 'recaptcha') {
        recaptchaFields.classList.remove('hidden');
        recaptchaFields.classList.add('block');
        hcaptchaFields.classList.remove('block');
        hcaptchaFields.classList.add('hidden');
    } else if (selected === 'hcaptcha') {
        hcaptchaFields.classList.remove('hidden');
        hcaptchaFields.classList.add('block');
        recaptchaFields.classList.remove('block');
        recaptchaFields.classList.add('hidden');
    } else {
        recaptchaFields.classList.remove('block');
        recaptchaFields.classList.add('hidden');
        hcaptchaFields.classList.remove('block');
        hcaptchaFields.classList.add('hidden');
    }
}

function openTestModal() {
    document.getElementById('smtp-test-modal').classList.remove('hidden');
    document.getElementById('step-1').classList.remove('hidden');
    document.getElementById('step-2').classList.add('hidden');
    document.getElementById('test-email-input').value = '';
    document.getElementById('test-code-input').value = '';
    hideTestMsg();
    document.getElementById('modal-title').textContent = "Testar Envio de E-mail";
    document.getElementById('modal-desc').textContent = "Informe um e-mail para receber o código de teste.";
}

function closeTestModal() {
    document.getElementById('smtp-test-modal').classList.add('hidden');
}

function showTestMsg(msg, type) {
    const el = document.getElementById('test-message');
    el.textContent = msg;
    el.className = `mt-4 p-3 rounded text-sm block msg-${type}`;
}
function hideTestMsg() {
    document.getElementById('test-message').classList.add('hidden');
}

async function sendTestEmail() {
    const email = document.getElementById('test-email-input').value.trim();
    if(!email) { showTestMsg("Informe um e-mail válido.", "error"); return; }

    const btn = document.getElementById('btn-send-test');
    btn.disabled = true; btn.textContent = "Enviando...";
    hideTestMsg();

    try {
        const fd = new FormData(); fd.append('email', email);
        const res = await fetch('api_test_smtp.php', {method: 'POST', body: fd});
        const json = await res.json();

        if(res.ok && json.status === 'success') {
            document.getElementById('step-1').classList.add('hidden');
            document.getElementById('step-2').classList.remove('hidden');
            document.getElementById('modal-title').textContent = "Validação do Código";
            document.getElementById('modal-desc').textContent = `Enviado para ${email}`;
            showTestMsg("E-mail enviado! Verifique sua caixa de entrada.", "info");
        } else {
            showTestMsg(json.message || "Erro ao enviar.", "error");
        }
    } catch(e) { showTestMsg("Erro: falha ao processar a resposta do servidor. Verifique o console.", "error"); console.error(e); }
    btn.disabled = false; btn.textContent = "Enviar Código";
}

async function verifyTestCode() {
    const code = document.getElementById('test-code-input').value.trim().toUpperCase();
    if(code.length !== 6) { showTestMsg("O código deve ter 6 caracteres.", "error"); return; }

    const btn = document.getElementById('btn-verify-test');
    btn.disabled = true; btn.textContent = "Validando...";
    hideTestMsg();

    try {
        const fd = new FormData(); fd.append('codigo', code);
        const res = await fetch('api_verify_test_smtp.php', {method: 'POST', body: fd});
        const json = await res.json();

        if(res.ok && json.status === 'success') {
            showTestMsg("Sucesso! Código validado e SMTP configurado corretamente.", "success");
            document.getElementById('step-2').classList.add('hidden');
            setTimeout(closeTestModal, 3000);
        } else {
            showTestMsg(json.message || "Código inválido.", "error");
        }
    } catch(e) { showTestMsg("Erro: falha ao processar a resposta do servidor. Verifique o console.", "error"); console.error(e); }
    btn.disabled = false; btn.textContent = "Validar Código";
}
</script>

<?php require_once 'includes/footer.php'; ?>
