<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

// Queries para as estatísticas
$totalAlunos = 0;
$totalRegistros = 0;
$totalPeriodos = 0;

try {
    // Total de RAs únicos (Alunos)
    $stmt = $conn->query("SELECT COUNT(DISTINCT ra) AS total FROM resultados");
    if ($row = $stmt->fetch()) {
        $totalAlunos = $row['total'];
    }

    // Total de Registros
    $stmt = $conn->query("SELECT COUNT(*) AS total FROM resultados");
    if ($row = $stmt->fetch()) {
        $totalRegistros = $row['total'];
    }

    // Total de Períodos cadastrados
    $stmt = $conn->query("SELECT COUNT(DISTINCT periodo) AS total FROM resultados");
    if ($row = $stmt->fetch()) {
        $totalPeriodos = $row['total'];
    }

} catch (PDOException $e) {
    // Silently ignore or log error
    error_log("Erro ao carregar dashboard: " . $e->getMessage());
}

?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Dashboard</h1>
        <p class="text-slate-500 mt-1">Visão geral do sistema de resultados</p>
    </div>
    <div>
        <a href="upload_form.php" class="bg-primary hover:bg-emerald-600 text-white px-4 py-2 rounded-lg shadow-sm font-medium transition-colors flex items-center">
            <i class="ph-bold ph-plus mr-2"></i> Novo Upload
        </a>
    </div>
</div>

<!-- Cards de Estatísticas -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">

    <!-- Card 1 -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex items-start group hover:border-primary/50 transition-colors">
        <div class="bg-blue-50 text-blue-500 rounded-lg p-3 mr-4 group-hover:bg-blue-500 group-hover:text-white transition-colors">
            <i class="ph-fill ph-users text-3xl"></i>
        </div>
        <div>
            <p class="text-sm font-medium text-slate-500 mb-1">Total de Alunos (RAs)</p>
            <h3 class="text-3xl font-bold text-slate-800"><?= number_format($totalAlunos, 0, ',', '.') ?></h3>
        </div>
    </div>

    <!-- Card 2 -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex items-start group hover:border-primary/50 transition-colors">
        <div class="bg-emerald-50 text-primary rounded-lg p-3 mr-4 group-hover:bg-primary group-hover:text-white transition-colors">
            <i class="ph-fill ph-file-text text-3xl"></i>
        </div>
        <div>
            <p class="text-sm font-medium text-slate-500 mb-1">Total de Registros</p>
            <h3 class="text-3xl font-bold text-slate-800"><?= number_format($totalRegistros, 0, ',', '.') ?></h3>
        </div>
    </div>

    <!-- Card 3 -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex items-start group hover:border-primary/50 transition-colors">
        <div class="bg-purple-50 text-purple-500 rounded-lg p-3 mr-4 group-hover:bg-purple-500 group-hover:text-white transition-colors">
            <i class="ph-fill ph-calendar-blank text-3xl"></i>
        </div>
        <div>
            <p class="text-sm font-medium text-slate-500 mb-1">Períodos Cadastrados</p>
            <h3 class="text-3xl font-bold text-slate-800"><?= number_format($totalPeriodos, 0, ',', '.') ?></h3>
        </div>
    </div>

</div>

<!-- Bem-vindo -->
<div class="bg-white rounded-xl shadow-sm border border-slate-100 p-8 text-center sm:text-left flex flex-col sm:flex-row items-center gap-8">
    <div class="w-32 h-32 bg-slate-100 rounded-full flex items-center justify-center shrink-0">
        <i class="ph-fill ph-hand-waving text-6xl text-amber-400"></i>
    </div>
    <div>
        <h2 class="text-2xl font-bold text-slate-800 mb-2">Bem-vindo(a) de volta!</h2>
        <p class="text-slate-600 mb-4 max-w-2xl">
            Use o menu lateral para navegar pelo painel. Você pode fazer o upload de novas planilhas CSV,
            gerenciar os resultados já cadastrados ou gerenciar os acessos de outros administradores.
        </p>
        <div class="flex flex-wrap gap-3 justify-center sm:justify-start">
            <a href="resultados.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium px-4 py-2 rounded-lg transition-colors">
                <i class="ph ph-table mr-1"></i> Ver Resultados
            </a>
            <a href="perfil.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium px-4 py-2 rounded-lg transition-colors">
                <i class="ph ph-user mr-1"></i> Meu Perfil
            </a>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
