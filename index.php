<?php
require_once 'includes/Database.php';
require_once 'admin/includes/config_helper.php';

$db = new Database();
$conn = $db->getConnection();

$recaptchaAtivo = getConfig($conn, 'recaptcha_ativo') === '1';
$siteKey = getConfig($conn, 'recaptcha_site_key');
$hcaptchaAtivo = getConfig($conn, 'hcaptcha_ativo') === '1';
$hSiteKey = getConfig($conn, 'hcaptcha_site_key');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Resultados - DI</title>
    <!-- TailwindCSS via CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#00b48d',      // Cor institucional principal
                        secondary: '#f8fafc',    // Fundo cinza bem claro (slate-50)
                        dark: '#1e293b',         // Texto escuro (slate-800)
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
    <!-- IMask para as máscaras de CPF e Data de Nascimento -->
    <script src="https://unpkg.com/imask"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        body { font-family: 'Inter', sans-serif; }

        .fade-in { animation: fadeIn 0.4s ease-in forwards; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .hidden-view { display: none !important; }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-secondary text-dark min-h-screen flex flex-col items-center justify-center p-4">

    <!-- Navbar -->
    <div class="fixed top-0 left-0 w-full bg-white shadow-sm z-50">
        <div class="max-w-5xl mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="ph-fill ph-student text-primary text-3xl"></i>
                <h1 class="font-bold text-xl tracking-tight text-slate-800">Resultados <span class="text-primary">DI</span></h1>
            </div>
            <a href="admin/login.php" class="text-slate-400 hover:text-primary transition-colors text-sm flex items-center gap-1" title="Acesso Restrito">
                 <i class="ph ph-lock-key"></i> <span class="hidden sm:inline">Admin</span>
            </a>
        </div>
    </div>

    <!-- VIEW 1: TELA DE BUSCA -->
    <div id="view-search" class="w-full max-w-md bg-white rounded-2xl shadow-xl border border-slate-100 p-8 sm:p-10 fade-in mt-16">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-50 mb-4">
                <i class="ph ph-magnifying-glass text-3xl text-primary"></i>
            </div>
            <h2 class="text-2xl font-bold mb-2">Consulte seu Resultado</h2>
            <p class="text-slate-500 text-sm">Digite seu CPF e sua Data de Nascimento para acessar seu desempenho detalhado.</p>
        </div>

        <form id="search-form" class="space-y-6">
            <div>
                <label for="cpf_input" class="block text-sm font-bold text-slate-700 mb-1 ml-1">Seu CPF</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="ph ph-identification-card text-slate-400 text-xl"></i>
                    </div>
                    <input type="tel" id="cpf_input" name="cpf" required
                           class="block w-full pl-12 pr-4 py-4 bg-slate-50 border border-slate-200 rounded-xl text-lg font-medium text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all shadow-inner"
                           placeholder="000.000.000-00">
                </div>
            </div>

            <div>
                <label for="nascimento_input" class="block text-sm font-bold text-slate-700 mb-1 ml-1">Data de Nascimento</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="ph ph-calendar-blank text-slate-400 text-xl"></i>
                    </div>
                    <input type="tel" id="nascimento_input" name="data_nascimento" required
                           class="block w-full pl-12 pr-4 py-4 bg-slate-50 border border-slate-200 rounded-xl text-lg font-medium text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all shadow-inner"
                           placeholder="DD/MM/AAAA">
                </div>
            </div>

            <?php if ($recaptchaAtivo && !empty($siteKey)): ?>
                <div class="flex justify-center my-4">
                    <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($siteKey) ?>"></div>
                </div>
            <?php elseif ($hcaptchaAtivo && !empty($hSiteKey)): ?>
                <div class="flex justify-center my-4">
                    <div class="h-captcha" data-sitekey="<?= htmlspecialchars($hSiteKey) ?>"></div>
                </div>
            <?php endif; ?>

            <!-- Mensagem de erro dinâmica -->
            <div id="error-message" class="hidden rounded-lg bg-red-50 p-4 border border-red-100 flex items-start gap-3">
                <i class="ph-fill ph-warning-circle text-red-500 text-xl mt-0.5"></i>
                <p class="text-sm text-red-700 font-medium" id="error-text">Aluno não encontrado. Verifique os dados.</p>
            </div>

            <button type="submit" id="btn-submit" class="w-full flex justify-center items-center py-4 px-4 border border-transparent rounded-xl shadow-md text-base font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-all hover:-translate-y-0.5 disabled:opacity-70 disabled:hover:translate-y-0">
                <span>Consultar Resultado</span>
                <i class="ph-bold ph-arrow-right ml-2 text-lg"></i>
            </button>
        </form>
    </div>

    <!-- VIEW 1.5: TELA DE 2FA -->
    <div id="view-2fa" class="hidden-view w-full max-w-md bg-white rounded-2xl shadow-xl border border-slate-100 p-8 sm:p-10 fade-in mt-16">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-50 mb-4">
                <i class="ph ph-envelope-open text-3xl text-primary"></i>
            </div>
            <h2 class="text-2xl font-bold mb-2">Verificação de Segurança</h2>
            <p class="text-slate-500 text-sm">Enviamos um código de 6 dígitos para o seu e-mail <br><strong id="display-email" class="text-slate-700"></strong>.</p>
        </div>

        <form id="2fa-form" class="space-y-6">
            <input type="hidden" id="temp_cpf">
            <input type="hidden" id="temp_data_nascimento">

            <div>
                <label for="codigo_input" class="block text-sm font-bold text-slate-700 mb-1 ml-1 text-center">Código de Verificação</label>
                <input type="text" id="codigo_input" name="codigo" required maxlength="6"
                       class="block w-full px-4 py-4 bg-slate-50 border border-slate-200 rounded-xl text-3xl font-bold text-center tracking-[0.5em] text-slate-800 placeholder-slate-300 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all shadow-inner uppercase"
                       placeholder="------" autocomplete="one-time-code">
            </div>

            <!-- Mensagem de erro dinâmica -->
            <div id="error-message-2fa" class="hidden rounded-lg bg-red-50 p-4 border border-red-100 flex items-start gap-3">
                <i class="ph-fill ph-warning-circle text-red-500 text-xl mt-0.5"></i>
                <p class="text-sm text-red-700 font-medium" id="error-text-2fa">Código incorreto.</p>
            </div>

            <button type="submit" id="btn-submit-2fa" class="w-full flex justify-center items-center py-4 px-4 border border-transparent rounded-xl shadow-md text-base font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-all hover:-translate-y-0.5 disabled:opacity-70 disabled:hover:translate-y-0">
                <span>Confirmar Código</span>
                <i class="ph-bold ph-check ml-2 text-lg"></i>
            </button>

            <div class="text-center mt-6">
                <button type="button" id="btn-resend" class="text-sm font-medium text-slate-500 hover:text-primary transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    <span id="resend-text">Reenviar código</span>
                    <span id="resend-timer" class="hidden font-mono ml-1"></span>
                </button>
            </div>
            <div class="text-center mt-2">
                 <button type="button" id="btn-cancel-2fa" class="text-xs text-slate-400 hover:text-slate-600 underline">
                    Cancelar e voltar
                </button>
            </div>
        </form>
    </div>

    <!-- VIEW 2: TELA DE RESULTADOS (Dashboard) -->
    <div id="view-results" class="hidden-view w-full max-w-5xl fade-in mt-20 mb-10">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4">
            <div>
                <button id="btn-back" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-primary transition-colors bg-white px-4 py-2 rounded-full shadow-sm border border-slate-200 mb-3">
                    <i class="ph-bold ph-arrow-left mr-2"></i> Nova consulta
                </button>
                <h2 class="text-3xl font-bold text-slate-800 flex items-center gap-2">
                    <i class="ph-fill ph-chart-bar text-primary"></i> Meu Boletim
                </h2>
                <!-- Agora exibe o RA e o Nome Oculto (opcional), mas vamos manter apenas o RA por privacidade da tela de resultados -->
                <p class="text-slate-500 mt-1">RA: <span id="display-ra" class="font-bold text-slate-700">---</span></p>
            </div>

            <!-- Seletor de Período -->
            <div id="period-selector-container" class="hidden">
                <label for="period_select" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Selecione o Período</label>
                <div class="relative">
                    <select id="period_select" class="block w-full pl-4 pr-10 py-2.5 text-base border-slate-300 focus:outline-none focus:ring-primary focus:border-primary sm:text-sm rounded-lg bg-white shadow-sm font-medium text-slate-700 cursor-pointer appearance-none border">
                        <!-- Options injetadas via JS -->
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-500">
                        <i class="ph-bold ph-caret-down"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Painel de Resumo -->
        <div id="summary-panel" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <!-- Cards injetados via JS -->
        </div>

        <!-- Grid de Respostas -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <i class="ph-fill ph-list-checks text-[#00b48d]"></i> Detalhamento das Respostas
                </h3>
                <div id="container-botoes-extras"></div>
            </div>
            <div class="p-6">
                <div id="answers-grid" class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-8 lg:grid-cols-10 gap-3">
                    <!-- Badges injetadas via JS -->
                </div>
            </div>
        </div>
    </div>

    <!-- Script de lógica da Interface e Máscaras -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Aplica Máscaras
            const cpfInputEl = document.getElementById('cpf_input');
            const dataInputEl = document.getElementById('nascimento_input');

            if(cpfInputEl) IMask(cpfInputEl, { mask: '000.000.000-00' });
            if(dataInputEl) IMask(dataInputEl, {
                mask: Date,
                pattern: 'd/m/Y',
                blocks: {
                    d: { mask: IMask.MaskedRange, from: 1, to: 31, maxLength: 2 },
                    m: { mask: IMask.MaskedRange, from: 1, to: 12, maxLength: 2 },
                    Y: { mask: IMask.MaskedRange, from: 1900, to: 2999 }
                },
                format: function (date) {
                    var day = date.getDate();
                    var month = date.getMonth() + 1;
                    var year = date.getFullYear();
                    if (day < 10) day = "0" + day;
                    if (month < 10) month = "0" + month;
                    return [day, month, year].join('/');
                },
                parse: function (str) {
                    var yearMonthDay = str.split('/');
                    return new Date(yearMonthDay[2], yearMonthDay[1] - 1, yearMonthDay[0]);
                }
            });
        });
    </script>
    <script src="assets/js/app.js"></script>

</body>
</html>
