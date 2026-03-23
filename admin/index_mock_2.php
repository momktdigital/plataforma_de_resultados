<?php
// Mock parameters so we can see the full dashboard UI
$totalAlunos = 1500;
$totalRegistros = 4500;
$totalPeriodos = 3;
$filtroAvaliacao = '';

$avaliacoesDisponiveis = ['Simulado ENEM 2024', 'Prova Bimestral - T1'];
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
</head>
<body class="bg-slate-50 text-slate-800 p-8">

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Dashboard</h1>
        <p class="text-slate-500 mt-1">Visão geral do sistema de resultados</p>
    </div>
    <div class="flex flex-col sm:flex-row gap-3">
        <!-- Filtro de Avaliação -->
        <form method="GET" class="flex items-center">
            <div class="relative flex items-center bg-white border border-slate-300 rounded-lg shadow-sm focus-within:ring-2 focus-within:ring-primary focus-within:border-primary transition-all">
                <i class="ph ph-funnel text-slate-400 absolute left-3"></i>
                <select name="avaliacao" onchange="this.form.submit()" class="pl-9 pr-8 py-2 bg-transparent text-sm text-slate-700 font-medium focus:outline-none appearance-none cursor-pointer">
                    <option value="">Todas as Avaliações</option>
                    <?php foreach ($avaliacoesDisponiveis as $aval): ?>
                        <option value="<?= htmlspecialchars($aval) ?>" <?= $filtroAvaliacao === $aval ? 'selected' : '' ?>>
                            <?= htmlspecialchars($aval) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-500">
                    <i class="ph-bold ph-caret-down text-xs"></i>
                </div>
            </div>
        </form>

        <a href="#" class="bg-primary hover:bg-emerald-600 text-white px-4 py-2 rounded-lg shadow-sm font-medium transition-colors flex items-center">
            <i class="ph-bold ph-plus mr-2"></i> Novo Upload
        </a>
    </div>
</div>

<!-- Cards Principais -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex items-start group hover:border-primary/50 transition-colors">
        <div class="bg-blue-50 text-blue-500 rounded-lg p-3 mr-4 group-hover:bg-blue-500 group-hover:text-white transition-colors">
            <i class="ph-fill ph-users text-2xl"></i>
        </div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Total de Alunos</p>
            <h3 class="text-3xl font-black text-slate-800 leading-none"><?= number_format($totalAlunos, 0, ',', '.') ?></h3>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex items-start group hover:border-primary/50 transition-colors">
        <div class="bg-emerald-50 text-primary rounded-lg p-3 mr-4 group-hover:bg-primary group-hover:text-white transition-colors">
            <i class="ph-fill ph-file-text text-2xl"></i>
        </div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Total de Registros</p>
            <h3 class="text-3xl font-black text-slate-800 leading-none"><?= number_format($totalRegistros, 0, ',', '.') ?></h3>
        </div>
    </div>

    <?php if (empty($filtroAvaliacao)): ?>
        <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex items-start group hover:border-primary/50 transition-colors lg:col-span-2">
            <div class="bg-amber-50 text-amber-500 rounded-lg p-3 mr-4 group-hover:bg-amber-500 group-hover:text-white transition-colors">
                <i class="ph-fill ph-lightbulb text-2xl"></i>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Dica de Relatório</p>
                <p class="text-sm font-medium text-slate-600">Selecione uma avaliação no filtro acima para visualizar médias gerais e o ranking dos Top 5 alunos!</p>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Bem-vindo (mostrado se não houver filtro) -->
<?php if (empty($filtroAvaliacao)): ?>
<div class="bg-white rounded-xl shadow-sm border border-slate-100 p-8 text-center sm:text-left flex flex-col sm:flex-row items-center gap-8">
    <div class="w-32 h-32 bg-slate-50 rounded-full flex items-center justify-center shrink-0 border border-slate-100 shadow-inner">
        <i class="ph-fill ph-rocket-launch text-6xl text-primary animate-pulse"></i>
    </div>
    <div>
        <h2 class="text-2xl font-bold text-slate-800 mb-2">Painel de Controle Ativo</h2>
        <p class="text-slate-600 mb-5 max-w-2xl leading-relaxed">
            Use o menu lateral ou os atalhos abaixo para gerenciar o sistema. O filtro no topo desta página permite analisar o desempenho geral por avaliação.
        </p>
        <div class="flex flex-wrap gap-3 justify-center sm:justify-start">
            <a href="#" class="bg-white border border-slate-300 hover:border-primary hover:text-primary text-slate-700 font-bold px-5 py-2.5 rounded-lg transition-all shadow-sm">
                <i class="ph-bold ph-table mr-1"></i> Explorar Resultados
            </a>
            <a href="#" class="bg-white border border-slate-300 hover:border-primary hover:text-primary text-slate-700 font-bold px-5 py-2.5 rounded-lg transition-all shadow-sm">
                <i class="ph-bold ph-check-square-offset mr-1"></i> Importar Gabaritos
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

</body>
</html>
