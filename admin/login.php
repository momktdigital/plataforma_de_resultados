<?php
session_start();

// Se já estiver logado, redireciona para o painel
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    die();
}

require_once '../includes/Database.php';
require_once 'includes/config_helper.php';

$db = new Database();
$conn = $db->getConnection();

$recaptchaAtivo = getConfig($conn, 'recaptcha_ativo') === '1';
$siteKey = getConfig($conn, 'recaptcha_site_key');
$secretKey = getConfig($conn, 'recaptcha_secret_key');

$hcaptchaAtivo = getConfig($conn, 'hcaptcha_ativo') === '1';
$hSiteKey = getConfig($conn, 'hcaptcha_site_key');
$hSecretKey = getConfig($conn, 'hcaptcha_secret_key');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // --- Validar CAPTCHA se ativo ---
    $captchaValido = true;
    if ($recaptchaAtivo && !empty($secretKey)) {
        $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
        if (empty($recaptchaResponse)) {
            $error = 'Por favor, confirme que você não é um robô.';
            $captchaValido = false;
        } else {
            $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
            $data = [
                'secret' => $secretKey,
                'response' => $recaptchaResponse
            ];

            $options = [
                'http' => [
                    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query($data)
                ]
            ];
            $context  = stream_context_create($options);
            $result = file_get_contents($verifyUrl, false, $context);
            $recaptchaData = json_decode($result, true);

            if (!$recaptchaData || !$recaptchaData['success']) {
                $error = 'Falha na validação do reCAPTCHA.';
                $captchaValido = false;
            }
        }
    } elseif ($hcaptchaAtivo && !empty($hSecretKey)) {
        $hcaptchaResponse = $_POST['h-captcha-response'] ?? '';
        if (empty($hcaptchaResponse)) {
            $error = 'Por favor, confirme que você não é um robô.';
            $captchaValido = false;
        } else {
            $verifyUrl = 'https://hcaptcha.com/siteverify';
            $data = [
                'secret' => $hSecretKey,
                'response' => $hcaptchaResponse
            ];

            $options = [
                'http' => [
                    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query($data)
                ]
            ];
            $context  = stream_context_create($options);
            $result = file_get_contents($verifyUrl, false, $context);
            $hcaptchaData = json_decode($result, true);

            if (!$hcaptchaData || !$hcaptchaData['success']) {
                $error = 'Falha na validação do hCaptcha.';
                $captchaValido = false;
            }
        }
    }

    if ($captchaValido) {
        if (empty($username) || empty($password)) {
            $error = 'Por favor, preencha usuário e senha.';
        } else {
            try {
                $stmt = $conn->prepare("SELECT id, password_hash FROM admins WHERE username = :username LIMIT 1");
                $stmt->bindParam(':username', $username, PDO::PARAM_STR);
                $stmt->execute();

                if ($stmt->rowCount() === 1) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    // Verifica o hash da senha
                    if (password_verify($password, $row['password_hash'])) {
                        // Login bem-sucedido
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_id'] = $row['id'];
                        $_SESSION['admin_username'] = $username;

                        header('Location: index.php');
                        die();
                    } else {
                        $error = 'Usuário ou senha inválidos.';
                    }
                } else {
                    $error = 'Usuário ou senha inválidos.';
                }
            } catch (PDOException $e) {
                $error = 'Erro ao conectar ao banco de dados: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Administrativo - Resultados DI</title>
    <!-- TailwindCSS via CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#00b48d',      // Cor institucional principal
                        secondary: '#f8fafc',    // Fundo cinza bem claro
                        dark: '#1e293b',         // Texto escuro
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <?php if ($recaptchaAtivo && !empty($siteKey)): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <?php if ($hcaptchaAtivo && !empty($hSiteKey)): ?>
        <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <?php endif; ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col items-center justify-center p-4">

    <div class="w-full max-w-md">

        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-800 border border-slate-700 mb-4 shadow-lg">
                <i class="ph-fill ph-lock-key text-3xl text-primary"></i>
            </div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Área Restrita</h1>
            <p class="text-slate-400 mt-2 text-sm">Painel de Administração de Resultados</p>
        </div>

        <div class="bg-slate-800 rounded-2xl shadow-2xl border border-slate-700 overflow-hidden">
            <div class="p-8">

                <?php if ($error): ?>
                    <div class="bg-red-900/30 border border-red-800 text-red-300 p-4 mb-6 rounded-lg text-sm flex items-start">
                        <i class="ph-fill ph-warning-circle text-xl mr-2 mt-0.5"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" class="space-y-6">
                    <div>
                        <label for="username" class="block text-sm font-medium text-slate-300 mb-1 ml-1">Usuário</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="ph-fill ph-user text-slate-500 text-lg"></i>
                            </div>
                            <input type="text" id="username" name="username" required
                                   class="block w-full pl-10 pr-3 py-3 bg-slate-900 border border-slate-700 rounded-xl text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                                   placeholder="admin"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-300 mb-1 ml-1">Senha</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="ph-fill ph-lock text-slate-500 text-lg"></i>
                            </div>
                            <input type="password" id="password" name="password" required
                                   class="block w-full pl-10 pr-3 py-3 bg-slate-900 border border-slate-700 rounded-xl text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                                   placeholder="••••••••">
                        </div>
                    </div>

                    <?php if ($recaptchaAtivo && !empty($siteKey)): ?>
                        <div class="flex justify-center my-4">
                            <!-- Tema escuro (opcional, pode ser data-theme="dark") -->
                            <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($siteKey) ?>" data-theme="dark"></div>
                        </div>
                    <?php elseif ($hcaptchaAtivo && !empty($hSiteKey)): ?>
                        <div class="flex justify-center my-4">
                            <div class="h-captcha" data-sitekey="<?= htmlspecialchars($hSiteKey) ?>" data-theme="dark"></div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="w-full flex justify-center items-center py-3.5 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-slate-800 focus:ring-primary transition-all">
                        <i class="ph-bold ph-sign-in mr-2 text-lg"></i> Entrar no Sistema
                    </button>
                </form>

            </div>

            <div class="bg-slate-900/50 px-8 py-4 border-t border-slate-700 text-center">
                <a href="../index.php" class="text-sm text-slate-500 hover:text-slate-300 transition-colors flex items-center justify-center">
                    <i class="ph-bold ph-arrow-left mr-1"></i> Voltar para Consulta de Alunos
                </a>
            </div>
        </div>

    </div>

</body>
</html>
