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
    $jsonNotasRaw = $_POST['notas_finais_json'] ?? '{}';
    $jsonRespostasRaw = $_POST['respostas_json'] ?? '{}';

    // Validar JSON
    $notasOk = json_decode($jsonNotasRaw, true);
    $respostasOk = json_decode($jsonRespostasRaw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $mensagem = "Erro de Sintaxe no JSON. Verifique as aspas e vírgulas. A alteração não foi salva.";
        $tipoMensagem = 'error';
    } elseif (empty($novoRa) || empty($novoPeriodo)) {
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

// Formatar JSON bonito para o textarea
$prettyRespostas = json_encode(json_decode($aluno['respostas']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$prettyNotas = json_encode(json_decode($aluno['notas_finais']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

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

<form method="POST" action="" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <input type="hidden" name="action" value="update_aluno">
    <input type="hidden" name="id" value="<?= $idAluno ?>">

    <!-- Coluna 1: Dados Básicos -->
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
                    <p class="text-[10px] text-slate-400 mt-1">Para mover o aluno de avaliação, edite no CSV e reimporte, ou mude o Período.</p>
                </div>

                <div class="text-xs text-slate-400 flex items-center justify-center mt-2 p-2 bg-slate-50 rounded border border-slate-100">
                    <i class="ph ph-clock-counter-clockwise mr-1 text-slate-400"></i> Atualizado em <?= date('d/m/Y H:i', strtotime($aluno['updated_at'])) ?>
                </div>

            </div>

            <div class="p-4 bg-slate-50 border-t border-slate-100">
                <button type="submit" class="w-full bg-primary hover:bg-emerald-600 text-white font-bold py-3 px-4 rounded-lg transition-colors shadow-sm flex items-center justify-center">
                    <i class="ph-bold ph-floppy-disk text-lg mr-2"></i> Salvar Edição Completa
                </button>
            </div>
        </div>

    </div>

    <!-- Coluna 2 e 3: Edição Avançada JSON -->
    <div class="lg:col-span-2 flex flex-col gap-6">

        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-start shadow-sm">
            <i class="ph-fill ph-warning-circle text-blue-500 text-2xl mr-3 mt-0.5"></i>
            <div>
                <h4 class="font-bold text-blue-900 text-sm">Edição em Formato JSON</h4>
                <p class="text-xs text-blue-800 mt-1">As notas e gabaritos estão armazenados estruturalmente. Você pode alterar os valores (à direita dos dois pontos) livremente, mas <strong>NÃO</strong> quebre a sintaxe das aspas (<code>""</code>) ou chaves (<code>{}</code>).</p>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col h-full">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center">
                <h3 class="font-bold text-slate-800"><i class="ph-fill ph-star text-amber-400 mr-2"></i> Editar Notas Finais</h3>
            </div>
            <div class="p-4 flex-1">
                <textarea name="notas_finais_json" rows="10" class="w-full h-full min-h-[250px] p-4 bg-slate-900 text-emerald-400 font-mono text-sm rounded-lg focus:outline-none focus:ring-2 focus:ring-primary shadow-inner resize-y" spellcheck="false"><?= $prettyNotas ?></textarea>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col h-full">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center">
                <h3 class="font-bold text-slate-800"><i class="ph-fill ph-list-checks text-primary mr-2"></i> Editar Respostas (Gabarito Pessoal)</h3>
            </div>
            <div class="p-4 flex-1">
                <textarea name="respostas_json" rows="10" class="w-full h-full min-h-[250px] p-4 bg-slate-900 text-blue-400 font-mono text-sm rounded-lg focus:outline-none focus:ring-2 focus:ring-primary shadow-inner resize-y" spellcheck="false"><?= $prettyRespostas ?></textarea>
            </div>
        </div>

    </div>

</form>

<?php require_once 'includes/footer.php'; ?>
