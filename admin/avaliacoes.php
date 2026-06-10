<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$mensagem = '';
$tipoMensagem = '';

// Exclusão de Avaliação Inteira
if (isset($_POST['action']) && $_POST['action'] === 'delete_avaliacao') {
    csrf_validate();
    $avDelete = $_POST['nome_avaliacao'] ?? '';
    if (!empty($avDelete)) {
        try {
            $conn->beginTransaction();

            // Marca como excluído os resultados
            $stmt1 = $conn->prepare("UPDATE resultados SET deleted_at = CURRENT_TIMESTAMP WHERE nome_avaliacao = :nome");
            $stmt1->bindParam(':nome', $avDelete);
            $stmt1->execute();

            // Marca como excluído o gabarito
            $stmt2 = $conn->prepare("UPDATE gabaritos SET deleted_at = CURRENT_TIMESTAMP WHERE nome_avaliacao = :nome");
            $stmt2->bindParam(':nome', $avDelete);
            $stmt2->execute();

            $conn->commit();
            $mensagem = "Avaliação '$avDelete' e todos os resultados associados foram excluídos permanentemente.";
            $tipoMensagem = 'success';
        } catch (PDOException $e) {
            $conn->rollBack();
            $mensagem = "Erro ao excluir avaliação: " . $e->getMessage();
            $tipoMensagem = 'error';
        }
    }
}

// Buscar lista de avaliações agregada
$avaliacoes = [];
try {
    $sql = "
        SELECT
            COALESCE(r.nome_avaliacao, g.nome_avaliacao) AS nome,
            COUNT(DISTINCT r.ra) as total_alunos,
            COUNT(DISTINCT r.periodo) as total_periodos,
            MAX(r.updated_at) as ultimo_resultado,
            MAX(g.id) as gabarito_id,
            MAX(g.link_comentado) as link_comentado,
            MAX(g.respostas) as gabarito_respostas
        FROM resultados r
        LEFT JOIN gabaritos g ON r.nome_avaliacao = g.nome_avaliacao AND g.deleted_at IS NULL
        WHERE r.deleted_at IS NULL
        GROUP BY nome

        UNION

        SELECT
            g.nome_avaliacao AS nome,
            0 as total_alunos,
            0 as total_periodos,
            NULL as ultimo_resultado,
            g.id as gabarito_id,
            g.link_comentado,
            g.respostas as gabarito_respostas
        FROM gabaritos g
        LEFT JOIN resultados r ON g.nome_avaliacao = r.nome_avaliacao AND r.deleted_at IS NULL
        WHERE r.nome_avaliacao IS NULL AND g.deleted_at IS NULL

        ORDER BY nome ASC
    ";
    $stmt = $conn->query($sql);
    $avaliacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro buscar avaliações: " . $e->getMessage());
}
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Gestão de Avaliações</h1>
        <p class="text-slate-500 mt-1">Gerencie, edite ou exclua o ciclo completo das provas</p>
    </div>
    <div class="flex gap-3">
        <a href="upload_form.php" class="bg-primary hover:bg-emerald-600 text-white px-4 py-2 rounded-lg shadow-sm font-medium transition-colors flex items-center">
            <i class="ph-bold ph-plus mr-2"></i> Novo Resultado
        </a>
    </div>
</div>

<?php if ($mensagem): ?>
    <div class="mb-6 p-4 rounded-lg <?= $tipoMensagem === 'success' ? 'bg-emerald-50 text-emerald-800 border-l-4 border-emerald-500' : 'bg-red-50 text-red-800 border-l-4 border-red-500' ?> flex items-center shadow-sm">
        <i class="ph-fill <?= $tipoMensagem === 'success' ? 'ph-check-circle text-emerald-500' : 'ph-warning-circle text-red-500' ?> text-2xl mr-3"></i>
        <span><?= htmlspecialchars($mensagem) ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php if (count($avaliacoes) === 0): ?>
        <div class="col-span-full bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4">
                <i class="ph ph-exam text-3xl text-slate-400"></i>
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-1">Nenhuma avaliação encontrada</h3>
            <p class="text-slate-500 max-w-md mx-auto mb-6">Comece fazendo o upload de uma planilha de resultados ou gabarito para criar a primeira avaliação do sistema.</p>
            <a href="upload_form.php" class="inline-flex items-center justify-center px-6 py-3 bg-primary hover:bg-emerald-600 text-white font-bold rounded-lg shadow-sm transition-colors">
                Fazer Upload Agora
            </a>
        </div>
    <?php else: ?>
        <?php foreach ($avaliacoes as $av): ?>
            <?php
                $temGabarito = !empty($av['gabarito_id']);
                $numRespostasGabarito = 0;
                if ($temGabarito && !empty($av['gabarito_respostas'])) {
                    $arr = json_decode($av['gabarito_respostas'], true);
                    $numRespostasGabarito = is_array($arr) ? count(array_filter($arr)) : 0;
                }
            ?>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 hover:border-primary/40 hover:shadow-md transition-all overflow-hidden flex flex-col group relative">

                <div class="p-5 border-b border-slate-100 flex items-start justify-between bg-slate-50 group-hover:bg-primary/5 transition-colors">
                    <div>
                        <h3 class="font-bold text-slate-800 text-lg leading-tight mb-1 truncate pr-4" title="<?= htmlspecialchars($av['nome']) ?>">
                            <?= htmlspecialchars($av['nome']) ?>
                        </h3>
                        <div class="flex items-center gap-2 mt-2">
                            <?php if ($temGabarito && $numRespostasGabarito > 0): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-800 border border-green-200">
                                    <i class="ph-fill ph-check-circle mr-1"></i> GABARITO OK
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-orange-800 border border-orange-200">
                                    <i class="ph-fill ph-warning-circle mr-1"></i> SEM GABARITO
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($av['link_comentado'])): ?>
                                <span class="text-slate-400" title="Possui Link Comentado">
                                    <i class="ph-fill ph-link"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="p-5 flex-1 flex flex-col gap-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
                            <span class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1"><i class="ph-fill ph-users mr-1"></i> Alunos</span>
                            <span class="text-xl font-black text-slate-700"><?= number_format($av['total_alunos']) ?></span>
                        </div>
                        <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
                            <span class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1"><i class="ph-fill ph-calendar-blank mr-1"></i> Períodos</span>
                            <span class="text-xl font-black text-slate-700"><?= number_format($av['total_periodos']) ?></span>
                        </div>
                    </div>

                    <div class="text-xs text-slate-500 flex items-center">
                        <i class="ph ph-clock-counter-clockwise mr-1.5 text-slate-400"></i> Última att: <?= $av['ultimo_resultado'] ? date('d/m/Y H:i', strtotime($av['ultimo_resultado'])) : 'Apenas Gabarito' ?>
                    </div>
                </div>

                <div class="border-t border-slate-100 p-3 bg-slate-50/50 grid grid-cols-3 gap-2">
                    <a href="avaliacao_editar.php?nome=<?= urlencode($av['nome']) ?>" class="col-span-1 text-center py-2 rounded-lg bg-white border border-slate-200 text-sm font-medium text-slate-600 hover:text-primary hover:border-primary hover:bg-emerald-50 transition-colors" title="Gerenciar Configurações e Gabarito">
                        <i class="ph-bold ph-pencil-simple text-lg block mb-0.5"></i> Editar
                    </a>

                    <a href="resultados.php?search=&periodo=&avaliacao=<?= urlencode($av['nome']) ?>" class="col-span-1 text-center py-2 rounded-lg bg-white border border-slate-200 text-sm font-medium text-slate-600 hover:text-blue-600 hover:border-blue-300 hover:bg-blue-50 transition-colors" title="Ver Notas dos Alunos">
                        <i class="ph-bold ph-list-numbers text-lg block mb-0.5"></i> Notas
                    </a>

                    <button type="button" onclick="confirmDelete('<?= htmlspecialchars(addslashes($av['nome'])) ?>')" class="col-span-1 text-center py-2 rounded-lg bg-white border border-slate-200 text-sm font-medium text-slate-600 hover:text-red-600 hover:border-red-300 hover:bg-red-50 transition-colors" title="Excluir Avaliação inteira">
                        <i class="ph-bold ph-trash text-lg block mb-0.5"></i> Excluir
                    </button>
                </div>

            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Form Invisível p/ Excluir -->
<form id="delete-form" method="POST" action="avaliacoes.php" class="hidden">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_avaliacao">
    <input type="hidden" name="nome_avaliacao" id="delete-nome">
</form>

<script>
function confirmDelete(nome) {
    if (confirm("ATENÇÃO: Você tem certeza que deseja EXCLUIR DEFINITIVAMENTE a avaliação '" + nome + "'?\n\nIsso apagará o Gabarito e os Resultados de TODOS os alunos que a fizeram. Esta ação não tem volta.")) {
        document.getElementById('delete-nome').value = nome;
        document.getElementById('delete-form').submit();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
