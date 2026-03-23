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
    <!-- Phosphor Icons (para ícones modernos e limpos) -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <!-- Custom CSS para pequenas animações -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        body { font-family: 'Inter', sans-serif; }

        .fade-in { animation: fadeIn 0.4s ease-in forwards; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .hidden-view { display: none !important; }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-secondary text-dark min-h-screen flex flex-col items-center justify-center p-4">

    <!-- Navbar simplificada (apenas logo) -->
    <div class="fixed top-0 left-0 w-full bg-white shadow-sm z-50">
        <div class="max-w-5xl mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="ph-fill ph-student text-primary text-3xl"></i>
                <h1 class="font-bold text-xl tracking-tight text-slate-800">Resultados <span class="text-primary">DI</span></h1>
            </div>
            <!-- Botão Admin Invisível ou discreto (opcional) -->
            <a href="admin/login.php" class="text-slate-400 hover:text-primary transition-colors text-sm flex items-center gap-1" title="Acesso Restrito">
                 <i class="ph ph-lock-key"></i> <span class="hidden sm:inline">Admin</span>
            </a>
        </div>
    </div>

    <!-- VIEW 1: TELA DE BUSCA (Centralizada, muito whitespace) -->
    <div id="view-search" class="w-full max-w-md bg-white rounded-2xl shadow-xl border border-slate-100 p-8 sm:p-10 fade-in mt-16">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-50 mb-4">
                <i class="ph ph-magnifying-glass text-3xl text-primary"></i>
            </div>
            <h2 class="text-2xl font-bold mb-2">Consulte seu Resultado</h2>
            <p class="text-slate-500 text-sm">Digite seu Registro Acadêmico (RA) para acessar o seu desempenho detalhado.</p>
        </div>

        <form id="search-form" class="space-y-6">
            <div>
                <label for="ra_input" class="block text-sm font-medium text-slate-700 mb-1 ml-1">Seu RA</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="ph ph-identification-card text-slate-400 text-xl"></i>
                    </div>
                    <input type="text" id="ra_input" name="ra" required
                           class="block w-full pl-12 pr-4 py-4 bg-slate-50 border border-slate-200 rounded-xl text-lg font-medium text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all shadow-inner"
                           placeholder="Ex: 123456789">
                </div>
            </div>

            <!-- Mensagem de erro dinâmica -->
            <div id="error-message" class="hidden rounded-lg bg-red-50 p-4 border border-red-100 flex items-start gap-3">
                <i class="ph-fill ph-warning-circle text-red-500 text-xl mt-0.5"></i>
                <p class="text-sm text-red-700" id="error-text">RA não encontrado. Verifique e tente novamente.</p>
            </div>

            <button type="submit" id="btn-submit" class="w-full flex justify-center items-center py-4 px-4 border border-transparent rounded-xl shadow-md text-base font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors disabled:opacity-70">
                <span>Consultar Resultado</span>
                <i class="ph-bold ph-arrow-right ml-2 text-lg"></i>
            </button>
        </form>
    </div>

    <!-- VIEW 2: TELA DE RESULTADOS (Dashboard) -->
    <div id="view-results" class="hidden-view w-full max-w-5xl fade-in mt-20 mb-10">

        <!-- Header (Back button e Título) -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4">
            <div>
                <button id="btn-back" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-primary transition-colors bg-white px-4 py-2 rounded-full shadow-sm border border-slate-200 mb-3">
                    <i class="ph-bold ph-arrow-left mr-2"></i> Voltar à busca
                </button>
                <h2 class="text-3xl font-bold text-slate-800 flex items-center gap-2">
                    <i class="ph-fill ph-chart-bar text-primary"></i> Meu Boletim
                </h2>
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

        <!-- Painel de Resumo (Notas Finais) -->
        <div id="summary-panel" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <!-- Cards injetados via JS -->
        </div>

        <!-- Grid de Respostas (Q1-Q100) -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <i class="ph-fill ph-list-checks text-[#00b48d]"></i> Detalhamento das Respostas
                </h3>
                <div id="container-botoes-extras"></div>
            </div>
            <div class="p-6">
                <!-- Grid CSS Responsivo para "pílulas" -->
                <div id="answers-grid" class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-8 lg:grid-cols-10 gap-3">
                    <!-- Badges injetadas via JS -->
                </div>
            </div>
        </div>
    </div>

    <!-- Script de lógica da Interface -->
    <script src="assets/js/app.js"></script>

</body>
</html>
