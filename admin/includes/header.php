<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    die();
}

// Determinar a página atual para o menu ativo
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Administrativo - Resultados DI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#00b48d',
                        secondary: '#f8fafc',
                        dark: '#1e293b',
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        /* Para o menu responsivo */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s ease-in-out; z-index: 50; position: fixed; height: 100vh; }
            .sidebar.open { transform: translateX(0); }
            .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 40; }
            .overlay.open { display: block; }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 h-screen flex overflow-hidden">

    <!-- Overlay para mobile -->
    <div id="sidebar-overlay" class="overlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar w-64 bg-slate-900 text-slate-300 flex flex-col shrink-0">
        <div class="h-16 flex items-center px-6 border-b border-slate-800 bg-slate-950">
            <i class="ph-fill ph-student text-primary text-2xl mr-2"></i>
            <span class="text-lg font-bold text-white tracking-wide">Resultados <span class="text-primary">DI</span></span>
        </div>

        <nav class="flex-1 overflow-y-auto py-4">
            <ul class="space-y-1 px-3">
                <li>
                    <a href="index.php" class="flex items-center px-3 py-2.5 rounded-lg transition-colors <?= $current_page === 'index.php' ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' ?>">
                        <i class="ph ph-squares-four text-xl mr-3 <?= $current_page === 'index.php' ? 'text-primary' : '' ?>"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="resultados.php" class="flex items-center px-3 py-2.5 rounded-lg transition-colors <?= $current_page === 'resultados.php' ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' ?>">
                        <i class="ph ph-table text-xl mr-3 <?= $current_page === 'resultados.php' ? 'text-primary' : '' ?>"></i> Resultados
                    </a>
                </li>
                <li>
                    <a href="upload_form.php" class="flex items-center px-3 py-2.5 rounded-lg transition-colors <?= $current_page === 'upload_form.php' ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' ?>">
                        <i class="ph ph-cloud-arrow-up text-xl mr-3 <?= $current_page === 'upload_form.php' ? 'text-primary' : '' ?>"></i> Upload de CSV
                    </a>
                </li>
                <li>
                    <a href="usuarios.php" class="flex items-center px-3 py-2.5 rounded-lg transition-colors <?= $current_page === 'usuarios.php' ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' ?>">
                        <i class="ph ph-users text-xl mr-3 <?= $current_page === 'usuarios.php' ? 'text-primary' : '' ?>"></i> Administradores
                    </a>
                </li>
                <li>
                    <a href="perfil.php" class="flex items-center px-3 py-2.5 rounded-lg transition-colors <?= $current_page === 'perfil.php' ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-slate-800 hover:text-white' ?>">
                        <i class="ph ph-user-circle text-xl mr-3 <?= $current_page === 'perfil.php' ? 'text-primary' : '' ?>"></i> Meu Perfil
                    </a>
                </li>
            </ul>
        </nav>

        <div class="p-4 border-t border-slate-800">
            <a href="logout.php" class="flex items-center px-3 py-2 text-red-400 hover:text-red-300 hover:bg-slate-800 rounded-lg transition-colors">
                <i class="ph ph-sign-out text-xl mr-3"></i> Sair
            </a>
        </div>
    </aside>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col h-screen overflow-hidden">

        <!-- Topbar mobile only -->
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:hidden shrink-0">
            <div class="flex items-center">
                <i class="ph-fill ph-student text-primary text-2xl mr-2"></i>
                <span class="font-bold text-slate-800">Admin</span>
            </div>
            <button onclick="toggleSidebar()" class="text-slate-500 hover:text-primary focus:outline-none">
                <i class="ph ph-list text-2xl"></i>
            </button>
        </header>

        <!-- Main Scrollable Area -->
        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-slate-50 p-4 sm:p-6 lg:p-8">
