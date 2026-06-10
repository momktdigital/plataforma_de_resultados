<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$mensagem = '';
$tipoMensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = $_POST['action'] ?? '';
    $id_tipo = $_POST['id_tipo'] ?? ''; // ex: "resultado_15" ou "gabarito_3"

    if (!empty($action) && !empty($id_tipo)) {
        list($tipo, $id) = explode('_', $id_tipo, 2);
        $tabela = ($tipo === 'gabarito') ? 'gabaritos' : 'resultados';

        try {
            if ($action === 'restore') {
                $stmt = $conn->prepare("UPDATE {$tabela} SET deleted_at = NULL WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $mensagem = "Registro restaurado com sucesso.";
                $tipoMensagem = 'success';
            } elseif ($action === 'force_delete') {
                $stmt = $conn->prepare("DELETE FROM {$tabela} WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $mensagem = "Registro excluído permanentemente.";
                $tipoMensagem = 'success';
            }
        } catch (PDOException $e) {
            $mensagem = "Erro ao processar: " . $e->getMessage();
            $tipoMensagem = 'error';
        }
    }
    
    // Restaurar Todos ou Excluir Todos
    if ($action === 'restore_all') {
        $conn->exec("UPDATE resultados SET deleted_at = NULL WHERE deleted_at IS NOT NULL");
        $conn->exec("UPDATE gabaritos SET deleted_at = NULL WHERE deleted_at IS NOT NULL");
        $mensagem = "Todos os itens foram restaurados.";
        $tipoMensagem = 'success';
    } elseif ($action === 'empty_trash') {
        $conn->exec("DELETE FROM resultados WHERE deleted_at IS NOT NULL");
        $conn->exec("DELETE FROM gabaritos WHERE deleted_at IS NOT NULL");
        $mensagem = "A lixeira foi esvaziada.";
        $tipoMensagem = 'success';
    }
}

// Buscar itens na lixeira
$itensLixeira = [];
try {
    // Busca resultados excluidos
    $stmt1 = $conn->query("SELECT 'resultado' as tipo, id, ra, periodo, nome_avaliacao, deleted_at FROM resultados WHERE deleted_at IS NOT NULL");
    while($row = $stmt1->fetch(PDO::FETCH_ASSOC)) {
        $itensLixeira[] = [
            'id_tipo' => 'resultado_' . $row['id'],
            'descricao' => "Resultado - Aluno RA: {$row['ra']} | Turma: {$row['periodo']} | Avaliação: {$row['nome_avaliacao']}",
            'deleted_at' => $row['deleted_at']
        ];
    }
    
    // Busca gabaritos excluidos
    $stmt2 = $conn->query("SELECT 'gabarito' as tipo, id, nome_avaliacao, deleted_at FROM gabaritos WHERE deleted_at IS NOT NULL");
    while($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $itensLixeira[] = [
            'id_tipo' => 'gabarito_' . $row['id'],
            'descricao' => "Gabarito - Avaliação: {$row['nome_avaliacao']}",
            'deleted_at' => $row['deleted_at']
        ];
    }
    
    // Sort by deleted_at DESC
    usort($itensLixeira, function($a, $b) {
        return strtotime($b['deleted_at']) - strtotime($a['deleted_at']);
    });
    
} catch (PDOException $e) {
    // ignore
}
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Lixeira</h1>
        <p class="text-slate-500 mt-1">Gerencie resultados e gabaritos excluídos recentemente</p>
    </div>
    <?php if (count($itensLixeira) > 0): ?>
    <div class="flex gap-3">
        <form method="POST" action="" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restore_all">
            <button type="submit" class="bg-blue-50 text-blue-600 hover:bg-blue-100 px-4 py-2 rounded-lg shadow-sm font-medium transition-colors flex items-center">
                <i class="ph-bold ph-arrow-counter-clockwise mr-2"></i> Restaurar Tudo
            </button>
        </form>
        <form method="POST" action="" class="inline" onsubmit="return confirm('Tem certeza que deseja esvaziar a lixeira? Os dados não poderão ser recuperados.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="empty_trash">
            <button type="submit" class="bg-red-50 text-red-600 hover:bg-red-100 px-4 py-2 rounded-lg shadow-sm font-medium transition-colors flex items-center">
                <i class="ph-bold ph-trash mr-2"></i> Esvaziar Lixeira
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if ($mensagem): ?>
    <div class="mb-6 p-4 rounded-lg <?= $tipoMensagem === 'success' ? 'bg-emerald-50 text-emerald-800 border-l-4 border-emerald-500' : 'bg-red-50 text-red-800 border-l-4 border-red-500' ?> flex items-center shadow-sm">
        <i class="ph-fill <?= $tipoMensagem === 'success' ? 'ph-check-circle text-emerald-500' : 'ph-warning-circle text-red-500' ?> text-2xl mr-3"></i>
        <span><?= htmlspecialchars($mensagem) ?></span>
    </div>
<?php endif; ?>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Descrição do Item</th>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Data de Exclusão</th>
                    <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-wider">Ações</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-slate-200">
                <?php if (count($itensLixeira) > 0): ?>
                    <?php foreach ($itensLixeira as $item): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4">
                                <span class="text-sm font-medium text-slate-700">
                                    <?= htmlspecialchars($item['descricao']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                <?= date('d/m/Y H:i:s', strtotime($item['deleted_at'])) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end gap-3">
                                    <form method="POST" action="" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="id_tipo" value="<?= $item['id_tipo'] ?>">
                                        <button type="submit" class="text-blue-500 hover:text-blue-700 flex items-center" title="Restaurar">
                                            <i class="ph-bold ph-arrow-counter-clockwise text-lg"></i>
                                        </button>
                                    </form>
                                    <form method="POST" action="" class="inline" onsubmit="return confirm('Excluir definitivamente?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="force_delete">
                                        <input type="hidden" name="id_tipo" value="<?= $item['id_tipo'] ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-600 flex items-center" title="Excluir Permanente">
                                            <i class="ph-bold ph-trash text-lg"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="px-6 py-12 text-center">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4">
                                <i class="ph ph-trash text-2xl text-slate-400"></i>
                            </div>
                            <h3 class="text-sm font-medium text-slate-900">Lixeira vazia</h3>
                            <p class="text-sm text-slate-500 mt-1">Nenhum item foi excluído recentemente.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
