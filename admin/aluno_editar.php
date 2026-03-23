<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$mensagem = '';
$tipoMensagem = '';

$idAluno = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($idAluno <= 0) {
    header("Location: resultados.php");
    die();
}

// Processar Update de Aluno
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_aluno') {
    $novoRa = trim($_POST['ra'] ?? '');
    $novoPeriodo = trim($_POST['periodo'] ?? '');

    // Montar JSON de Notas Finais baseado nos inputs
    $notasFinais = [];
    if (isset($_POST['nota_keys']) && is_array($_POST['nota_keys'])) {
        for ($i = 0; $i < count($_POST['nota_keys']); $i++) {
            $k = trim($_POST['nota_keys'][$i]);
            $v = trim($_POST['nota_values'][$i] ?? '');
            if (!empty($k)) {
                $notasFinais[$k] = $v;
            }
        }
    }
    $jsonNotasRaw = json_encode($notasFinais);

    // Montar JSON de Respostas baseado no Grid Manual Q1-Q100
    $respostasCorretas = [];
    for ($i = 1; $i <= 100; $i++) {
        $q = "Q$i";
        $val = trim($_POST[$q] ?? '');
        if ($val !== '') {
            $respostasCorretas[$q] = mb_strtoupper($val, 'UTF-8');
        }
    }
    $jsonRespostasRaw = json_encode($respostasCorretas);

    if (empty($novoRa) || empty($novoPeriodo)) {
        $mensagem = "Os campos RA e Período são obrigatórios.";
        $tipoMensagem = 'error';
    } else {
        try {
            $stmt = $conn->prepare("UPDATE resultados SET ra = :ra, periodo = :periodo, respostas = :respostas, notas_finais = :notas WHERE id = :id");
            $stmt->bindParam(':ra', $novoRa);
            $stmt->bindParam(':periodo', $novoPeriodo);
            $stmt->bindParam(':respostas', $jsonRespostasRaw);
            $stmt->bindParam(':notas', $jsonNotasRaw);
            $stmt->bindParam(':id', $idAluno, PDO::PARAM_INT);
            $stmt->execute();

            $mensagem = "Dados do aluno atualizados com sucesso.";
            $tipoMensagem = 'success';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Constraint violation
                $mensagem = "Já existe um registro com este mesmo RA, Período e Avaliação.";
            } else {
                $mensagem = "Erro ao atualizar aluno: " . $e->getMessage();
            }
            $tipoMensagem = 'error';
        }
    }
}

// Buscar Dados Atuais
$aluno = null;
try {
    $stmt = $conn->prepare("SELECT id, ra, periodo, nome_avaliacao, respostas, notas_finais, updated_at FROM resultados WHERE id = :id LIMIT 1");
    $stmt->bindParam(':id', $idAluno, PDO::PARAM_INT);
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $aluno = $row;
    } else {
        header("Location: resultados.php");
        die();
    }
} catch (PDOException $e) {}

// Decode
$respostasAluno = json_decode($aluno['respostas'], true) ?: [];
$notasFinais = json_decode($aluno['notas_finais'], true) ?: [];

?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div class="flex items-center">
        <a href="resultados.php" class="mr-4 text-slate-400 hover:text-primary transition-colors bg-white w-10 h-10 flex justify-center items-center rounded-full shadow-sm border border-slate-200">
            <i class="ph-bold ph-arrow-left text-xl"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Editar Resultado do Aluno</h1>
            <p class="text-slate-500 text-sm font-medium mt-0.5">RA: <span class="font-bold text-slate-700 bg-white px-2 py-0.5 rounded border border-slate-200"><?= htmlspecialchars($aluno['ra']) ?></span></p>
        </div>
    </div>
</div>

<?php if ($mensagem): ?>
    <div class="mb-6 p-4 rounded-lg <?= $tipoMensagem === 'success' ? 'bg-emerald-50 text-emerald-800 border-l-4 border-emerald-500' : 'bg-red-50 text-red-800 border-l-4 border-red-500' ?> flex items-center shadow-sm">
        <i class="ph-fill <?= $tipoMensagem === 'success' ? 'ph-check-circle text-emerald-500' : 'ph-warning-circle text-red-500' ?> text-2xl mr-3"></i>
        <span><?= htmlspecialchars($mensagem) ?></span>
    </div>
<?php endif; ?>

<form method="POST" action="" class="flex flex-col gap-6">
    <input type="hidden" name="action" value="update_aluno">
    <input type="hidden" name="id" value="<?= $idAluno ?>">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Coluna 1: Dados Básicos e Notas Finais -->
        <div class="lg:col-span-1 flex flex-col gap-6">

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                    <h3 class="font-bold text-slate-800"><i class="ph-fill ph-identification-card text-primary mr-2"></i> Identificação</h3>
                    <span class="text-xs text-slate-400 font-mono">ID: #<?= $aluno['id'] ?></span>
                </div>

                <div class="p-6 space-y-4">

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Registro Acadêmico (RA)</label>
                        <input type="text" name="ra" value="<?= htmlspecialchars($aluno['ra']) ?>" required class="block w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-sm font-bold text-slate-800 focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary focus:bg-white transition-colors">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Período</label>
                        <input type="text" name="periodo" value="<?= htmlspecialchars($aluno['periodo']) ?>" required class="block w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-sm font-bold text-slate-800 focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary focus:bg-white transition-colors">
                    </div>

                    <div class="pt-4 border-t border-slate-100">
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Avaliação Vinculada</label>
                        <input type="text" disabled value="<?= htmlspecialchars($aluno['nome_avaliacao']) ?>" class="block w-full px-3 py-2 bg-slate-100 border border-slate-200 rounded-lg text-sm font-medium text-slate-500 cursor-not-allowed">
                        <p class="text-[10px] text-slate-400 mt-1">Para mover o aluno de avaliação, edite no CSV e reimporte.</p>
                    </div>

                    <div class="text-xs text-slate-400 flex items-center justify-center mt-2 p-2 bg-slate-50 rounded border border-slate-100">
                        <i class="ph ph-clock-counter-clockwise mr-1 text-slate-400"></i> Atualizado em <?= date('d/m/Y H:i', strtotime($aluno['updated_at'])) ?>
                    </div>

                </div>
            </div>

            <!-- Editor de Notas Finais Dinâmico -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col flex-1">
                <div class="px-6 py-4 border-b border-slate-100 bg-amber-50 flex items-center">
                    <h3 class="font-bold text-amber-900"><i class="ph-fill ph-star text-amber-500 mr-2"></i> Editor de Notas Finais</h3>
                </div>
                <div class="p-6 flex-1 flex flex-col">
                    <p class="text-xs text-slate-500 mb-4">Edite os valores (Totais ou Percentuais) extraídos diretamente do CSV.</p>

                    <div id="notas-container" class="space-y-3 overflow-y-auto max-h-[350px] pr-2">
                        <?php if (empty($notasFinais)): ?>
                            <div class="text-sm text-slate-400 text-center py-4">Nenhuma nota final importada do CSV.</div>
                        <?php else: ?>
                            <?php foreach($notasFinais as $key => $val): ?>
                                <div class="flex items-center gap-2 bg-slate-50 p-2 rounded-lg border border-slate-200 group">
                                    <input type="text" name="nota_keys[]" value="<?= htmlspecialchars($key) ?>" readonly class="w-2/3 bg-transparent text-xs font-bold text-slate-600 focus:outline-none cursor-not-allowed truncate" title="<?= htmlspecialchars($key) ?>">
                                    <input type="text" name="nota_values[]" value="<?= htmlspecialchars($val) ?>" class="w-1/3 px-2 py-1 text-sm font-bold text-primary text-center border border-slate-300 rounded focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary bg-white">
                                    <button type="button" onclick="this.parentElement.remove()" class="text-slate-300 hover:text-red-500 transition-colors" title="Remover esta nota"><i class="ph-bold ph-x"></i></button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <button type="button" onclick="addNovaNota()" class="mt-4 w-full py-2 border-2 border-dashed border-slate-200 text-slate-500 hover:text-primary hover:border-primary hover:bg-emerald-50 rounded-lg text-xs font-bold transition-colors flex items-center justify-center">
                        <i class="ph-bold ph-plus mr-1"></i> Adicionar Nova Métrica
                    </button>
                </div>
            </div>

        </div>

        <!-- Coluna 2 e 3: Editor de Gabarito Manual (Alunos) -->
        <div class="lg:col-span-2">

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col h-full">
                <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                    <h3 class="font-bold text-slate-800"><i class="ph-fill ph-list-checks text-primary mr-2"></i> Respostas do Aluno (Q1 a Q100)</h3>
                    <span class="text-xs font-medium bg-white px-2 py-1 rounded border border-slate-200 text-slate-500">Editor Visual</span>
                </div>
                <div class="p-6 flex flex-col flex-1">
                    <p class="text-sm text-slate-500 mb-6">Corrija ou insira a alternativa marcada pelo aluno (A, B, C, D, E). Deixe em branco se a questão foi anulada ou não existe nesta prova.</p>

                    <div class="grid grid-cols-5 sm:grid-cols-8 md:grid-cols-10 gap-3 overflow-y-auto mb-6 pr-2" style="max-height: calc(100vh - 250px);">
                        <?php for ($i = 1; $i <= 100; $i++):
                            $qKey = "Q$i";
                            $val = $respostasAluno[$qKey] ?? '';
                        ?>
                            <div class="flex flex-col border border-slate-200 rounded overflow-hidden focus-within:ring-2 focus-within:ring-primary focus-within:border-primary transition-all">
                                <label class="bg-slate-100 text-[10px] text-center font-bold text-slate-500 py-1 border-b border-slate-200"><?= $qKey ?></label>
                                <input type="text" name="<?= $qKey ?>" value="<?= htmlspecialchars($val) ?>" maxlength="1" class="w-full text-center py-3 h-12 font-bold text-xl text-slate-800 focus:outline-none uppercase bg-white">
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="p-4 bg-slate-50 border-t border-slate-100 mt-auto">
                    <button type="submit" class="w-full bg-primary hover:bg-emerald-600 text-white font-bold py-3 px-4 rounded-lg transition-colors shadow-sm flex items-center justify-center">
                        <i class="ph-bold ph-floppy-disk text-lg mr-2"></i> Salvar Todas as Edições do Aluno
                    </button>
                </div>
            </div>

        </div>
    </div>
</form>

<script>
function addNovaNota() {
    const container = document.getElementById('notas-container');
    const div = document.createElement('div');
    div.className = 'flex items-center gap-2 bg-emerald-50 p-2 rounded-lg border border-emerald-200 group animate-pulse';
    setTimeout(() => div.classList.remove('animate-pulse'), 1000);

    div.innerHTML = `
        <input type="text" name="nota_keys[]" placeholder="Nome da Métrica" class="w-2/3 px-2 py-1 bg-white text-xs font-bold text-slate-800 border border-slate-300 rounded focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary">
        <input type="text" name="nota_values[]" placeholder="0" class="w-1/3 px-2 py-1 text-sm font-bold text-primary text-center border border-slate-300 rounded focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary bg-white">
        <button type="button" onclick="this.parentElement.remove()" class="text-slate-400 hover:text-red-500 transition-colors" title="Remover"><i class="ph-bold ph-x"></i></button>
    `;
    container.appendChild(div);
}
</script>

<?php require_once 'includes/footer.php'; ?>
