<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

$mensagem = '';
$tipoMensagem = '';

$nomeAvaliacaoAtual = $_GET['nome'] ?? ($_POST['nome_atual'] ?? '');
if (empty($nomeAvaliacaoAtual)) {
    header("Location: avaliacoes.php");
    die();
}

// Processar Update de Configurações Básicas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_basic') {
    $novoNome = trim($_POST['novo_nome']);
    $linkComentado = trim($_POST['link_comentado']);
    if (empty($linkComentado)) $linkComentado = null;

    try {
        $conn->beginTransaction();

        // 1. Atualiza resultados se o nome mudou
        if ($novoNome !== $nomeAvaliacaoAtual) {
            $stmtR = $conn->prepare("UPDATE resultados SET nome_avaliacao = :novo WHERE nome_avaliacao = :atual");
            $stmtR->bindParam(':novo', $novoNome);
            $stmtR->bindParam(':atual', $nomeAvaliacaoAtual);
            $stmtR->execute();
        }

        // 2. Atualiza ou insere gabarito
        // Verificamos se já existe um gabarito
        $stmtCheck = $conn->prepare("SELECT id FROM gabaritos WHERE nome_avaliacao = :nome");
        $stmtCheck->bindParam(':nome', $nomeAvaliacaoAtual);
        $stmtCheck->execute();

        if ($stmtCheck->rowCount() > 0) {
            $stmtG = $conn->prepare("UPDATE gabaritos SET nome_avaliacao = :novo, link_comentado = :link WHERE nome_avaliacao = :atual");
            $stmtG->bindParam(':novo', $novoNome);
            $stmtG->bindParam(':link', $linkComentado);
            $stmtG->bindParam(':atual', $nomeAvaliacaoAtual);
            $stmtG->execute();
        } else {
            // Cria um registro de gabarito caso não existisse, só para segurar o nome novo e link
            $stmtG = $conn->prepare("INSERT INTO gabaritos (nome_avaliacao, link_comentado, respostas) VALUES (:novo, :link, '{}')");
            $stmtG->bindParam(':novo', $novoNome);
            $stmtG->bindParam(':link', $linkComentado);
            $stmtG->execute();
        }

        $conn->commit();
        $nomeAvaliacaoAtual = $novoNome; // Atualiza a variável pra recarregar a tela
        $mensagem = "Configurações atualizadas com sucesso.";
        $tipoMensagem = 'success';

    } catch (PDOException $e) {
        $conn->rollBack();
        $mensagem = "Erro ao atualizar: " . $e->getMessage();
        $tipoMensagem = 'error';
    }
}

// Processar Update de Gabarito Manual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_gabarito') {
    $respostasCorretas = [];
    for ($i = 1; $i <= 100; $i++) {
        $q = "Q$i";
        $val = trim($_POST[$q] ?? '');
        // Pode salvar string vazia (anulada) ou letra maiúscula
        if ($val !== '') {
            $respostasCorretas[$q] = mb_strtoupper($val, 'UTF-8');
        }
    }

    $respostasJson = json_encode($respostasCorretas);

    try {
        $stmt = $conn->prepare("INSERT INTO gabaritos (nome_avaliacao, respostas) VALUES (:nome, :resp) ON DUPLICATE KEY UPDATE respostas = VALUES(respostas)");
        $stmt->bindParam(':nome', $nomeAvaliacaoAtual);
        $stmt->bindParam(':resp', $respostasJson);
        $stmt->execute();

        $mensagem = "Gabarito manual atualizado com sucesso.";
        $tipoMensagem = 'success';
    } catch (PDOException $e) {
        $mensagem = "Erro ao atualizar gabarito: " . $e->getMessage();
        $tipoMensagem = 'error';
    }
}

// Buscar Dados Atuais
$linkComentadoAtual = '';
$respostasGabarito = [];

try {
    $stmt = $conn->prepare("SELECT link_comentado, respostas FROM gabaritos WHERE nome_avaliacao = :nome LIMIT 1");
    $stmt->bindParam(':nome', $nomeAvaliacaoAtual);
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $linkComentadoAtual = $row['link_comentado'];
        $respostasGabarito = json_decode($row['respostas'], true) ?: [];
    }
} catch (PDOException $e) {
    // Log
}

// Bônus: Calcular Índices de Acerto/Erro se houver resultados
$estatisticasQuestoes = [];
try {
    $stmtResultados = $conn->prepare("SELECT respostas FROM resultados WHERE nome_avaliacao = :nome");
    $stmtResultados->bindParam(':nome', $nomeAvaliacaoAtual);
    $stmtResultados->execute();

    $alunosCount = 0;
    while ($row = $stmtResultados->fetch(PDO::FETCH_ASSOC)) {
        $respostasAluno = json_decode($row['respostas'], true) ?: [];
        $alunosCount++;

        foreach ($respostasGabarito as $q => $correta) {
            if (!isset($estatisticasQuestoes[$q])) {
                $estatisticasQuestoes[$q] = ['acertos' => 0, 'erros' => 0, 'em_branco' => 0];
            }

            $marcada = $respostasAluno[$q] ?? '';

            if ($correta === '') {
                // Questão anulada, pula estatística
                continue;
            }

            if ($marcada === '') {
                $estatisticasQuestoes[$q]['em_branco']++;
            } elseif ($marcada === $correta) {
                $estatisticasQuestoes[$q]['acertos']++;
            } else {
                $estatisticasQuestoes[$q]['erros']++;
            }
        }
    }

    // Calcula % de erro
    if ($alunosCount > 0) {
        foreach ($estatisticasQuestoes as $q => &$stats) {
            $totalRespondido = $stats['acertos'] + $stats['erros'];
            $stats['taxa_erro'] = $totalRespondido > 0 ? round(($stats['erros'] / $totalRespondido) * 100, 1) : 0;
        }
        // Ordena pelas que mais erraram
        uasort($estatisticasQuestoes, function($a, $b) {
            return $b['taxa_erro'] <=> $a['taxa_erro'];
        });
    }
} catch(PDOException $e) {}

?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div class="flex items-center">
        <a href="avaliacoes.php" class="mr-4 text-slate-400 hover:text-primary transition-colors bg-white w-10 h-10 flex justify-center items-center rounded-full shadow-sm border border-slate-200">
            <i class="ph-bold ph-arrow-left text-xl"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Editar Avaliação</h1>
            <p class="text-slate-500 text-sm font-medium mt-0.5 truncate max-w-sm sm:max-w-md lg:max-w-xl" title="<?= htmlspecialchars($nomeAvaliacaoAtual) ?>">
                <?= htmlspecialchars($nomeAvaliacaoAtual) ?>
            </p>
        </div>
    </div>
</div>

<?php if ($mensagem): ?>
    <div class="mb-6 p-4 rounded-lg <?= $tipoMensagem === 'success' ? 'bg-emerald-50 text-emerald-800 border-l-4 border-emerald-500' : 'bg-red-50 text-red-800 border-l-4 border-red-500' ?> flex items-center shadow-sm">
        <i class="ph-fill <?= $tipoMensagem === 'success' ? 'ph-check-circle text-emerald-500' : 'ph-warning-circle text-red-500' ?> text-2xl mr-3"></i>
        <span><?= htmlspecialchars($mensagem) ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Coluna Esquerda: Edição Básica e Estatísticas -->
    <div class="lg:col-span-1 flex flex-col gap-6">

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                <h3 class="font-bold text-slate-800 flex items-center"><i class="ph-fill ph-gear text-primary mr-2"></i> Configurações</h3>
            </div>
            <form method="POST" class="p-6">
                <input type="hidden" name="action" value="update_basic">
                <input type="hidden" name="nome_atual" value="<?= htmlspecialchars($nomeAvaliacaoAtual) ?>">

                <div class="mb-4">
                    <label class="block text-sm font-bold text-slate-700 mb-1">Nome da Avaliação</label>
                    <input type="text" name="novo_nome" value="<?= htmlspecialchars($nomeAvaliacaoAtual) ?>" required class="block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
                    <p class="text-xs text-slate-500 mt-1">Isso afetará o nome para todos os alunos dessa prova.</p>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-bold text-slate-700 mb-1">Link do Gabarito Comentado</label>
                    <input type="url" name="link_comentado" value="<?= htmlspecialchars($linkComentadoAtual) ?>" placeholder="https://" class="block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
                </div>

                <button type="submit" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2.5 px-4 rounded-lg transition-colors flex justify-center items-center">
                    <i class="ph-bold ph-floppy-disk mr-2"></i> Salvar Alterações
                </button>
            </form>
        </div>

        <?php if ($alunosCount > 0 && !empty($respostasGabarito)): ?>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex-1">
            <div class="px-6 py-4 border-b border-slate-100 bg-amber-50">
                <h3 class="font-bold text-amber-900 flex items-center"><i class="ph-fill ph-trend-down text-amber-500 mr-2"></i> Questões Críticas</h3>
                <p class="text-xs text-amber-700 mt-1">Maiores índices de erro na turma (Base: <?= $alunosCount ?> alunos)</p>
            </div>
            <div class="p-0 overflow-y-auto max-h-[400px]">
                <ul class="divide-y divide-slate-100">
                    <?php
                    $countDisplay = 0;
                    foreach ($estatisticasQuestoes as $q => $stats):
                        if ($stats['taxa_erro'] == 0 || $countDisplay >= 10) break;
                        $countDisplay++;
                    ?>
                        <li class="p-4 flex items-center justify-between hover:bg-slate-50">
                            <div class="flex items-center">
                                <span class="w-10 h-10 rounded-full bg-red-100 text-red-600 flex items-center justify-center font-bold mr-3"><?= $q ?></span>
                                <div>
                                    <p class="text-xs font-bold text-slate-700">Erros: <?= $stats['erros'] ?> <span class="text-slate-400 font-normal ml-1">/ Acertos: <?= $stats['acertos'] ?></span></p>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="inline-block px-2 py-1 bg-red-50 text-red-700 rounded text-xs font-bold border border-red-100"><?= $stats['taxa_erro'] ?>%</span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <?php if($countDisplay === 0): ?>
                        <li class="p-6 text-center text-sm text-slate-500">Turma excelente! Não há registros de erros (ou as respostas ainda não foram cruzadas).</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Coluna Direita: Editor de Gabarito Manual -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden h-full flex flex-col">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                <h3 class="font-bold text-slate-800 flex items-center"><i class="ph-fill ph-check-square-offset text-primary mr-2"></i> Editor de Gabarito Manual</h3>
                <span class="text-xs font-medium bg-white px-2 py-1 rounded border border-slate-200 text-slate-500">Q1 a Q100</span>
            </div>

            <form method="POST" class="p-6 flex-1 flex flex-col">
                <input type="hidden" name="action" value="update_gabarito">
                <input type="hidden" name="nome_atual" value="<?= htmlspecialchars($nomeAvaliacaoAtual) ?>">

                <p class="text-sm text-slate-500 mb-6">Você pode corrigir as respostas do gabarito diretamente nesta tela. Deixe em branco se a questão foi anulada ou não existe nesta prova.</p>

                <div class="grid grid-cols-5 sm:grid-cols-8 md:grid-cols-10 gap-3 overflow-y-auto mb-6 pr-2" style="max-height: calc(100vh - 350px);">
                    <?php for ($i = 1; $i <= 100; $i++):
                        $qKey = "Q$i";
                        $val = $respostasGabarito[$qKey] ?? '';
                    ?>
                        <div class="flex flex-col border border-slate-200 rounded overflow-hidden focus-within:ring-2 focus-within:ring-primary focus-within:border-primary transition-all">
                            <label class="bg-slate-100 text-[10px] text-center font-bold text-slate-500 py-1 border-b border-slate-200"><?= $qKey ?></label>
                            <input type="text" name="<?= $qKey ?>" value="<?= htmlspecialchars($val) ?>" maxlength="1" class="w-full text-center py-2 min-h-[40px] font-bold text-lg text-slate-800 focus:outline-none uppercase bg-white">
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="mt-auto pt-4 border-t border-slate-100 flex justify-end">
                    <button type="submit" class="bg-primary hover:bg-emerald-600 text-white font-bold py-2.5 px-8 rounded-lg transition-colors shadow-sm flex items-center">
                        <i class="ph-bold ph-check mr-2 text-lg"></i> Salvar Gabarito
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<?php require_once 'includes/footer.php'; ?>
