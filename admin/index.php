<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    die();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrativo - Resultados DI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#00b48d',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 font-sans min-h-screen">

    <!-- Navbar -->
    <nav class="bg-white shadow-sm border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-xl font-bold text-slate-800"><i class="fa-solid fa-graduation-cap text-primary mr-2"></i> Resultados DI - Admin</span>
                </div>
                <div class="flex items-center">
                    <span class="text-slate-600 mr-4">Olá, Admin</span>
                    <a href="logout.php" class="text-red-500 hover:text-red-700 transition"><i class="fa-solid fa-sign-out-alt"></i> Sair</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-6 border-b border-slate-200 bg-slate-50">
                <h1 class="text-2xl font-bold text-slate-800">Upload de Resultados (CSV)</h1>
                <p class="text-slate-500 mt-1">Faça o upload do arquivo CSV para processar e disponibilizar os resultados para os alunos.</p>
            </div>

            <div class="p-8">
                <?php if (isset($_GET['success'])): ?>
                    <div class="bg-emerald-50 border-l-4 border-primary text-emerald-800 p-4 mb-6 rounded shadow-sm" role="alert">
                        <div class="flex items-center">
                            <i class="fa-solid fa-check-circle text-primary text-xl mr-3"></i>
                            <p class="font-medium">Arquivo processado com sucesso!</p>
                        </div>
                        <?php if(isset($_GET['msg'])): ?>
                            <p class="mt-2 text-sm ml-8"><?= htmlspecialchars($_GET['msg']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded shadow-sm" role="alert">
                        <div class="flex items-center">
                            <i class="fa-solid fa-triangle-exclamation text-red-500 text-xl mr-3"></i>
                            <p class="font-medium">Erro ao processar o arquivo</p>
                        </div>
                        <?php if(isset($_GET['msg'])): ?>
                            <p class="mt-2 text-sm ml-8"><?= htmlspecialchars($_GET['msg']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form action="upload.php" method="POST" enctype="multipart/form-data" class="max-w-2xl mx-auto">
                    <div class="mb-8">
                        <label class="block text-sm font-medium text-slate-700 mb-2">Selecione o arquivo .CSV</label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-slate-300 border-dashed rounded-lg hover:border-primary transition bg-slate-50 relative group">
                            <div class="space-y-1 text-center">
                                <i class="fa-solid fa-cloud-arrow-up text-4xl text-slate-400 group-hover:text-primary mb-3"></i>
                                <div class="flex text-sm text-slate-600 justify-center">
                                    <label for="csv_file" class="relative cursor-pointer bg-white rounded-md font-medium text-primary hover:text-emerald-600 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-primary px-2">
                                        <span>Fazer upload de um arquivo</span>
                                        <input id="csv_file" name="csv_file" type="file" accept=".csv" class="sr-only" required onchange="document.getElementById('file-name').textContent = this.files[0].name">
                                    </label>
                                </div>
                                <p class="text-xs text-slate-500 mt-2">Apenas arquivos .CSV são permitidos</p>
                                <p id="file-name" class="text-sm font-semibold text-slate-700 mt-3"></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-8 text-sm text-blue-800">
                        <h4 class="font-bold flex items-center mb-2"><i class="fa-solid fa-circle-info mr-2"></i> Regras Importantes:</h4>
                        <ul class="list-disc pl-5 space-y-1">
                            <li>O arquivo DEVE conter as colunas <strong>RA</strong> e <strong>Período</strong>.</li>
                            <li>A coluna <strong>NOME1</strong> será ignorada por motivos de privacidade.</li>
                            <li>As colunas das questões devem estar nomeadas como <strong>Q1</strong>, <strong>Q2</strong> ... até <strong>Q100</strong>.</li>
                            <li>Quaisquer outras colunas após a Q100 serão consideradas "Notas Finais".</li>
                            <li>Se um RA já existir no mesmo Período, os dados serão <strong>atualizados</strong> (Upsert).</li>
                        </ul>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="bg-primary hover:bg-emerald-600 text-white font-bold py-3 px-6 rounded-lg shadow-sm transition duration-300 flex items-center">
                            <i class="fa-solid fa-paper-plane mr-2"></i> Processar Arquivo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

</body>
</html>
