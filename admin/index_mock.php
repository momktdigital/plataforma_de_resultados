<?php
// Mock parameters so we can see the full dashboard UI
$totalAlunos = 1500;
$totalRegistros = 4500;
$totalPeriodos = 3;
$filtroAvaliacao = 'Simulado ENEM 2024';

$avaliacoesDisponiveis = ['Simulado ENEM 2024', 'Prova Bimestral - T1'];

$totalComAcertos = 1500;
$mediaGeralAcertos = 68.5;
$totalComNotaGeral = 1500;
$mediaNotaGeral = 685.40;

$topAlunos = [
    ['ra' => '12345', 'nota' => 950.0],
    ['ra' => '12346', 'nota' => 920.5],
    ['ra' => '12347', 'nota' => 890.0],
    ['ra' => '12348', 'nota' => 885.5],
    ['ra' => '12349', 'nota' => 880.0],
];
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
            <?php if (!empty($filtroAvaliacao)): ?>
                <a href="#" class="ml-2 text-slate-400 hover:text-red-500 transition-colors" title="Limpar Filtro">
                    <i class="ph-bold ph-x-circle text-xl"></i>
                </a>
            <?php endif; ?>
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
    <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex items-start group hover:border-primary/50 transition-colors">
            <div class="bg-purple-50 text-purple-500 rounded-lg p-3 mr-4 group-hover:bg-purple-500 group-hover:text-white transition-colors">
                <i class="ph-fill ph-target text-2xl"></i>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Média de Acertos</p>
                <div class="flex items-end gap-1">
                    <h3 class="text-3xl font-black text-slate-800 leading-none"><?= $totalComAcertos > 0 ? $mediaGeralAcertos : '--' ?></h3>
                    <span class="text-lg font-bold text-slate-500 mb-0.5"><?= $totalComAcertos > 0 ? '%' : '' ?></span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex items-start group hover:border-primary/50 transition-colors">
            <div class="bg-orange-50 text-orange-500 rounded-lg p-3 mr-4 group-hover:bg-orange-500 group-hover:text-white transition-colors">
                <i class="ph-fill ph-exam text-2xl"></i>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Média de Notas</p>
                <h3 class="text-3xl font-black text-slate-800 leading-none"><?= $totalComNotaGeral > 0 ? $mediaNotaGeral : '--' ?></h3>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php if (!empty($filtroAvaliacao)): ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

        <!-- Painel de Destaques (Top 5) -->
        <div class="lg:col-span-1 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                <h3 class="font-bold text-slate-800 flex items-center">
                    <i class="ph-fill ph-medal text-amber-500 text-xl mr-2"></i>
                    Top 5 Melhores
                </h3>
                <span class="text-xs font-bold text-slate-400 uppercase bg-white px-2 py-1 rounded border border-slate-200">Ranking</span>
            </div>
            <div class="p-0 flex-1">
                <?php if (empty($topAlunos)): ?>
                    <div class="p-6 flex flex-col items-center justify-center text-center h-full text-slate-500">
                        <i class="ph ph-ghost text-4xl mb-2 text-slate-300"></i>
                        <p class="text-sm">Não há notas numéricas formatadas nesta avaliação para gerar o ranking.</p>
                    </div>
                <?php else: ?>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach ($topAlunos as $index => $aluno):
                            $bgClass = $index === 0 ? 'bg-amber-50/50' : ($index === 1 ? 'bg-slate-50/50' : ($index === 2 ? 'bg-orange-50/50' : ''));
                            $medalColor = $index === 0 ? 'text-amber-500' : ($index === 1 ? 'text-slate-400' : ($index === 2 ? 'text-orange-500' : 'text-slate-300'));
                        ?>
                        <li class="px-6 py-4 flex items-center justify-between <?= $bgClass ?> hover:bg-slate-50 transition-colors">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-white border border-slate-200 flex items-center justify-center font-bold text-sm text-slate-600 shadow-sm">
                                    <?= $index + 1 ?>º
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 uppercase font-bold mb-0.5">RA do Aluno</p>
                                    <p class="font-mono text-sm font-semibold text-slate-800"><?= htmlspecialchars($aluno['ra']) ?></p>
                                </div>
                            </div>
                            <div class="text-right flex items-center gap-2">
                                <span class="font-black text-lg text-primary"><?= number_format($aluno['nota'], 2, ',', '') ?></span>
                                <i class="ph-fill ph-medal <?= $medalColor ?> text-xl"></i>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Estatísticas da Prova -->
        <div class="lg:col-span-2 bg-slate-900 rounded-xl shadow-md overflow-hidden text-slate-100 relative">
            <div class="absolute top-0 right-0 p-8 opacity-10 pointer-events-none">
                <i class="ph-fill ph-chart-line-up text-9xl"></i>
            </div>

            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between relative z-10">
                <h3 class="font-bold text-white flex items-center">
                    <i class="ph-fill ph-presentation-chart text-primary text-xl mr-2"></i>
                    Resumo: <?= htmlspecialchars($filtroAvaliacao) ?>
                </h3>
            </div>

            <div class="p-8 relative z-10 flex flex-col justify-center h-[calc(100%-60px)]">
                <?php if ($totalRegistros > 0): ?>
                    <p class="text-slate-400 mb-6 text-lg max-w-2xl leading-relaxed">
                        Foram processados <strong class="text-white"><?= $totalRegistros ?> registros</strong> para a avaliação selecionada.
                        <?php if ($totalComAcertos > 0): ?>
                            A média de desempenho da turma foi de <strong class="text-primary"><?= $mediaGeralAcertos ?>% de acertos</strong>.
                        <?php endif; ?>
                    </p>

                    <div class="flex flex-wrap gap-4 mt-4">
                        <a href="#" class="bg-primary hover:bg-emerald-600 text-white px-6 py-3 rounded-lg shadow-sm font-bold transition-colors flex items-center w-max">
                            <i class="ph-bold ph-list-magnifying-glass mr-2"></i> Ver Tabela Completa
                        </a>
                        <a href="#" class="bg-slate-800 hover:bg-slate-700 text-white px-6 py-3 rounded-lg shadow-sm font-bold border border-slate-700 transition-colors flex items-center w-max">
                            <i class="ph-bold ph-exam mr-2"></i> Gerenciar Gabarito
                        </a>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="ph ph-empty text-5xl text-slate-700 mb-3"></i>
                        <p class="text-slate-400">Nenhum dado encontrado para esta avaliação.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
<?php endif; ?>
</body>
</html>
