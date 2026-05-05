<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

// Lógica de Filtro
$filtroAvaliacao = $_GET['avaliacao'] ?? '';

$avaliacoesDisponiveis = [];
$periodosDisponiveis = [];

try {
    $stmtAval = $conn->query("SELECT DISTINCT nome_avaliacao FROM resultados ORDER BY nome_avaliacao ASC");
    $avaliacoesDisponiveis = $stmtAval->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($filtroAvaliacao)) {
        $stmtPer = $conn->prepare("SELECT DISTINCT periodo FROM resultados WHERE nome_avaliacao = :avaliacao ORDER BY periodo ASC");
        $stmtPer->execute([':avaliacao' => $filtroAvaliacao]);
        $periodosDisponiveis = $stmtPer->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {}

// Queries para as estatísticas
$totalAlunos = 0;
$totalRegistros = 0;
$totalPeriodos = 0;
$resultadosFiltrados = [];

try {
    $whereClause = "";
    $params = [];

    $filtroPeriodo = $_GET['periodo'] ?? '';

    if (!empty($filtroAvaliacao)) {
        $whereClause = "WHERE nome_avaliacao = :avaliacao";
        $params[':avaliacao'] = $filtroAvaliacao;

        if (!empty($filtroPeriodo)) {
            $whereClause .= " AND periodo = :periodo";
            $params[':periodo'] = $filtroPeriodo;
        }
    }

    // Total de RAs únicos (Alunos)
    $queryAlunos = "SELECT COUNT(DISTINCT ra) AS total FROM resultados $whereClause";
    $stmt = $conn->prepare($queryAlunos);
    $stmt->execute($params);
    $totalAlunos = $stmt->fetch()['total'] ?? 0;

    // Total de Registros
    $queryRegs = "SELECT COUNT(*) AS total FROM resultados $whereClause";
    $stmt = $conn->prepare($queryRegs);
    $stmt->execute($params);
    $totalRegistros = $stmt->fetch()['total'] ?? 0;

    // Total de Períodos cadastrados
    $queryPer = "SELECT COUNT(DISTINCT periodo) AS total FROM resultados $whereClause";
    $stmt = $conn->prepare($queryPer);
    $stmt->execute($params);
    $totalPeriodos = $stmt->fetch()['total'] ?? 0;

    // Buscar todos os resultados se houver filtro, para processar médias
    if (!empty($filtroAvaliacao)) {
        $sqlRes = "
            SELECT r.ra, r.notas_finais, r.periodo, a.nome 
            FROM resultados r 
            LEFT JOIN alunos a ON r.ra = a.ra 
            WHERE r.nome_avaliacao = :avaliacao
        ";
        $paramsRes = [':avaliacao' => $filtroAvaliacao];
        
        if (!empty($filtroPeriodo)) {
            $sqlRes .= " AND r.periodo = :periodo";
            $paramsRes[':periodo'] = $filtroPeriodo;
        }

        $stmt = $conn->prepare($sqlRes);
        $stmt->execute($paramsRes);
        $resultadosFiltrados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Erro ao carregar dashboard: " . $e->getMessage());
}

// Lógica para extrair médias (Percentuais, Notas Totais) dos campos JSON flexíveis
$mediaGeralAcertos = 0;
$somaAcertos = 0;
$totalComAcertos = 0;

$mediaNotaGeral = 0;
$somaNotaGeral = 0;
$totalComNotaGeral = 0;

$topAlunos = [];

// Variáveis para Gráficos
$materiasAcertosSoma = [];
$materiasAcertosCount = [];
$distribuicaoNotas = [
    '0-20' => 0,
    '21-40' => 0,
    '41-60' => 0,
    '61-80' => 0,
    '81-100' => 0,
];

if (!empty($resultadosFiltrados)) {
    foreach ($resultadosFiltrados as $res) {
        $notasFinais = json_decode($res['notas_finais'] ?? '{}', true);
        if (!$notasFinais) continue;

        $notaRanking = 0; // Para ordenar top alunos
        $temNota = false;

        // Extract values
        foreach ($notasFinais as $key => $val) {
            $keyLower = strtolower(trim($key));
            $numericVal = floatval(str_replace(['%', ','], ['', '.'], $val));

            // Logic to calculate global averages (Media Geral)
            if (str_contains($keyLower, 'percent') || str_contains($keyLower, '%') || str_contains($keyLower, 'acerto')) {
                // Se não tiver hífen, provavelmente é o percentual geral
                if (!str_contains($keyLower, '-')) {
                    $somaAcertos += $numericVal;
                    $totalComAcertos++;
                }
            }
            elseif (str_contains($keyLower, 'nota') || str_contains($keyLower, 'total') || str_contains($keyLower, 'final') || str_contains($keyLower, 'pontuação')) {
                // Ignore specific subjects for general average if they have dashes
                if (!str_contains($keyLower, '-')) {
                    $somaNotaGeral += $numericVal;
                    $totalComNotaGeral++;
                }
            }

            // Explicit Logic for Ranking: Only use "Total"
            if ($keyLower === 'total' || $keyLower === 'pontuação final' || $keyLower === 'nota final') {
                $notaRanking = $numericVal;
                $temNota = true;
            }
        }

        // Fallback for ranking if 'Total' exact match isn't found
        if (!$temNota) {
             foreach ($notasFinais as $key => $val) {
                 $keyLower = strtolower(trim($key));
                 $numericVal = floatval(str_replace(['%', ','], ['', '.'], $val));
                 if ($keyLower === 'nota' || str_contains($keyLower, 'pontuação') || !str_contains($keyLower, '-')) {
                     $notaRanking = max($notaRanking, $numericVal);
                     $temNota = true;
                 }
             }
        }

        if ($temNota) {
            $topAlunos[] = [
                'ra' => $res['ra'],
                'nome' => !empty($res['nome']) ? $res['nome'] : 'Aluno',
                'periodo' => $res['periodo'],
                'nota' => $notaRanking
            ];

            // Populate distribution based on ranking score
            $score = $notaRanking;
            if ($score <= 20) $distribuicaoNotas['0-20']++;
            elseif ($score <= 40) $distribuicaoNotas['21-40']++;
            elseif ($score <= 60) $distribuicaoNotas['41-60']++;
            elseif ($score <= 80) $distribuicaoNotas['61-80']++;
            else $distribuicaoNotas['81-100']++;
        }

        // Populate subject stats
        foreach ($notasFinais as $key => $val) {
            $keyLower = strtolower(trim($key));
            $numericVal = floatval(str_replace(['%', ','], ['', '.'], $val));

            // Check if it's a specific subject score (user format: "Materia - Total de acertos")
            if (str_contains($keyLower, '- total') || str_contains($keyLower, '- percentual') || str_contains($keyLower, '- acertos')) {
                $materiaNome = explode('-', $key)[0];
                $materiaNome = trim($materiaNome);

                if (!isset($materiasAcertosSoma[$materiaNome])) {
                    $materiasAcertosSoma[$materiaNome] = 0;
                    $materiasAcertosCount[$materiaNome] = 0;
                }
                $materiasAcertosSoma[$materiaNome] += $numericVal;
                $materiasAcertosCount[$materiaNome]++;
            }
        }
    }

    if ($totalComAcertos > 0) {
        $mediaGeralAcertos = round($somaAcertos / $totalComAcertos, 1);
    }

    if ($totalComNotaGeral > 0) {
        $mediaNotaGeral = round($somaNotaGeral / $totalComNotaGeral, 2);
    }

    // Sort Top Alunos descendente
    usort($topAlunos, function($a, $b) {
        return $b['nota'] <=> $a['nota'];
    });

    // Pegar apenas top 5
    $topAlunos = array_slice($topAlunos, 0, 5);

    // Process Materias Data for Chart
    $chartMateriasLabels = [];
    $chartMateriasData = [];
    foreach ($materiasAcertosSoma as $materia => $soma) {
        if ($materiasAcertosCount[$materia] > 0) {
            $chartMateriasLabels[] = $materia;
            $chartMateriasData[] = round($soma / $materiasAcertosCount[$materia], 2);
        }
    }
}

// Convert to JSON for JS scripts
$distribuicaoJson = json_encode(array_values($distribuicaoNotas ?? []));
$distribuicaoLabelsJson = json_encode(array_keys($distribuicaoNotas ?? []));

$chartMateriasLabelsJson = json_encode($chartMateriasLabels ?? []);
$chartMateriasDataJson = json_encode($chartMateriasData ?? []);

?>

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
            
            <?php if (!empty($filtroAvaliacao) && !empty($periodosDisponiveis)): ?>
            <div class="relative flex items-center bg-white border border-slate-300 rounded-lg shadow-sm focus-within:ring-2 focus-within:ring-primary focus-within:border-primary transition-all ml-2">
                <i class="ph ph-users-three text-slate-400 absolute left-3"></i>
                <select name="periodo" onchange="this.form.submit()" class="pl-9 pr-8 py-2 bg-transparent text-sm text-slate-700 font-medium focus:outline-none appearance-none cursor-pointer">
                    <option value="">Todas as Turmas</option>
                    <?php foreach ($periodosDisponiveis as $per): ?>
                        <option value="<?= htmlspecialchars($per) ?>" <?= $filtroPeriodo === $per ? 'selected' : '' ?>>
                            <?= htmlspecialchars($per) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-500">
                    <i class="ph-bold ph-caret-down text-xs"></i>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($filtroAvaliacao) || !empty($filtroPeriodo)): ?>
                <a href="index.php" class="ml-2 text-slate-400 hover:text-red-500 transition-colors" title="Limpar Filtro">
                    <i class="ph-bold ph-x-circle text-xl"></i>
                </a>
            <?php endif; ?>
        </form>

        <a href="upload_form.php" class="bg-primary hover:bg-emerald-600 text-white px-4 py-2 rounded-lg shadow-sm font-medium transition-colors flex items-center">
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
        <?php if ($totalComAcertos > 0): ?>
        <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex items-start group hover:border-primary/50 transition-colors">
            <div class="bg-purple-50 text-purple-500 rounded-lg p-3 mr-4 group-hover:bg-purple-500 group-hover:text-white transition-colors">
                <i class="ph-fill ph-target text-2xl"></i>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Aproveitamento (%)</p>
                <div class="flex items-end gap-1">
                    <h3 class="text-3xl font-black text-slate-800 leading-none"><?= $mediaGeralAcertos ?></h3>
                    <span class="text-lg font-bold text-slate-500 mb-0.5">%</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex items-start group hover:border-primary/50 transition-colors <?= $totalComAcertos === 0 ? 'lg:col-span-2' : '' ?>">
            <div class="bg-orange-50 text-orange-500 rounded-lg p-3 mr-4 group-hover:bg-orange-500 group-hover:text-white transition-colors">
                <i class="ph-fill ph-exam text-2xl"></i>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Média de Acertos (Absoluto)</p>
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
                        <p class="text-sm">Não há métricas numéricas cadastradas nesta avaliação para gerar o ranking.</p>
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
                                    <p class="text-xs text-slate-500 uppercase font-bold mb-0.5 truncate max-w-[150px] sm:max-w-[180px]" title="<?= htmlspecialchars($aluno['nome']) ?>"><?= htmlspecialchars($aluno['nome']) ?></p>
                                    <p class="text-[11px] font-medium text-slate-400"><?= htmlspecialchars($aluno['periodo']) ?> • RA: <?= htmlspecialchars($aluno['ra']) ?></p>
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

        <!-- Seção de Gráficos (Colspan 2) -->
        <div class="lg:col-span-2 flex flex-col gap-6">

            <!-- Curva de Distribuição de Notas -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col h-full">
                <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                    <h3 class="font-bold text-slate-800 flex items-center">
                        <i class="ph-fill ph-chart-bar text-primary text-xl mr-2"></i>
                        Distribuição de Acertos Totais
                    </h3>
                </div>
                <div class="p-6 flex-1 min-h-[250px] relative">
                    <canvas id="chartDistribuicao"></canvas>
                </div>
            </div>

        </div>
    </div>

    <!-- Linha Extra de Gráficos (Matérias) se houver dados -->
    <?php if (count($chartMateriasLabels ?? []) > 0): ?>
    <div class="grid grid-cols-1 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                <h3 class="font-bold text-slate-800 flex items-center">
                    <i class="ph-fill ph-radar text-blue-500 text-xl mr-2"></i>
                    Média de Acertos por Área
                </h3>
            </div>
            <div class="p-6 h-[400px] flex justify-center w-full relative">
                <canvas id="chartMaterias" class="max-w-4xl mx-auto"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Painel Resumo Black -->
    <div class="bg-slate-900 rounded-xl shadow-md overflow-hidden text-slate-100 relative mb-8">
        <div class="px-6 py-6 sm:px-8 sm:py-8 relative z-10 flex flex-col sm:flex-row items-center justify-between">
            <div>
                <h3 class="font-bold text-white flex items-center text-xl mb-2">
                    <i class="ph-fill ph-presentation-chart text-primary mr-2"></i>
                    Resumo: <?= htmlspecialchars($filtroAvaliacao) ?>
                </h3>
                <p class="text-slate-400 text-sm max-w-2xl leading-relaxed">
                    Foram processados <strong class="text-white"><?= $totalRegistros ?> registros</strong>. 
                    <?php if ($totalComAcertos > 0): ?>
                        A média de desempenho da turma foi de <strong class="text-primary"><?= $mediaGeralAcertos ?>% de aproveitamento</strong>.
                    <?php elseif ($totalComNotaGeral > 0): ?>
                        A média de acertos totais foi de <strong class="text-primary"><?= $mediaNotaGeral ?></strong>.
                    <?php endif; ?>
                </p>
            </div>

            <div class="flex gap-3 mt-4 sm:mt-0 shrink-0">
                <a href="resultados.php?avaliacao=<?= urlencode($filtroAvaliacao) ?>" class="bg-primary hover:bg-emerald-600 text-white px-5 py-2.5 rounded-lg shadow-sm font-bold transition-colors flex items-center text-sm">
                    <i class="ph-bold ph-table mr-2"></i> Ver Tabela
                </a>
                <a href="avaliacoes.php" class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2.5 rounded-lg shadow-sm font-bold border border-slate-700 transition-colors flex items-center text-sm">
                    <i class="ph-bold ph-exam mr-2"></i> Gabaritos
                </a>
            </div>
        </div>
    </div>


    </div>
<?php endif; ?>

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
            <a href="resultados.php" class="bg-white border border-slate-300 hover:border-primary hover:text-primary text-slate-700 font-bold px-5 py-2.5 rounded-lg transition-all shadow-sm">
                <i class="ph-bold ph-table mr-1"></i> Explorar Resultados
            </a>
            <a href="upload_gabarito.php" class="bg-white border border-slate-300 hover:border-primary hover:text-primary text-slate-700 font-bold px-5 py-2.5 rounded-lg transition-all shadow-sm">
                <i class="ph-bold ph-check-square-offset mr-1"></i> Importar Gabaritos
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Chart.js and Custom Script -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const hasData = <?= !empty($filtroAvaliacao) && !empty($resultadosFiltrados) ? 'true' : 'false' ?>;
    if (!hasData) return;

    // Distribution Chart (Bar)
    const ctxDist = document.getElementById('chartDistribuicao');
    if (ctxDist) {
        new Chart(ctxDist, {
            type: 'bar',
            data: {
                labels: <?= $distribuicaoLabelsJson ?>,
                datasets: [{
                    label: 'Quantidade de Alunos',
                    data: <?= $distribuicaoJson ?>,
                    backgroundColor: 'rgba(0, 180, 141, 0.2)', // primary color
                    borderColor: 'rgba(0, 180, 141, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Subjects Performance Chart (Radar/Bar)
    const ctxMaterias = document.getElementById('chartMaterias');
    const labelsMaterias = <?= $chartMateriasLabelsJson ?>;
    const dataMaterias = <?= $chartMateriasDataJson ?>;

    if (ctxMaterias && labelsMaterias.length > 0) {
        // Use Bar chart if there are few subjects, Radar if there are many to look better
        const chartType = labelsMaterias.length > 3 ? 'radar' : 'bar';

        new Chart(ctxMaterias, {
            type: chartType,
            data: {
                labels: labelsMaterias,
                datasets: [{
                    label: 'Média de Acertos',
                    data: dataMaterias,
                    backgroundColor: chartType === 'radar' ? 'rgba(59, 130, 246, 0.2)' : 'rgba(59, 130, 246, 0.7)', // Blue
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: 'rgba(59, 130, 246, 1)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: chartType === 'radar' }
                },
                scales: chartType === 'radar' ? {
                    r: {
                        beginAtZero: true,
                        angleLines: { color: 'rgba(0,0,0,0.1)' },
                        grid: { color: 'rgba(0,0,0,0.1)' },
                        pointLabels: { font: { size: 11 } }
                    }
                } : {
                    y: { beginAtZero: true },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
