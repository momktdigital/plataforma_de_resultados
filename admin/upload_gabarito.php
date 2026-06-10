<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

// Buscar todos os (nome_avaliacao) únicos já cadastrados
$avaliacoesList = [];
try {
    $stmt = $conn->query("SELECT DISTINCT nome_avaliacao FROM resultados WHERE deleted_at IS NULL ORDER BY nome_avaliacao ASC");
    $avaliacoesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Ignorar ou logar erro
}
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Upload de Gabaritos</h1>
        <p class="text-slate-500 mt-1">Carregue as respostas corretas das avaliações</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-4xl mx-auto">
    <div class="p-6 border-b border-slate-100 bg-slate-50">
        <h2 class="text-xl font-bold text-slate-800 flex items-center">
            <i class="ph-fill ph-check-square-offset text-primary mr-2"></i> Importar Gabarito (CSV)
        </h2>
    </div>

    <div class="p-8">
        <?php if (isset($_GET['success'])): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 mb-8 rounded-lg shadow-sm flex items-start" role="alert">
                <i class="ph-fill ph-check-circle text-emerald-500 text-2xl mr-3 mt-0.5"></i>
                <div>
                    <h4 class="font-bold text-emerald-900 mb-1">Gabarito processado com sucesso!</h4>
                    <?php if(isset($_GET['msg'])): ?>
                        <p class="text-sm"><?= htmlspecialchars($_GET['msg']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 p-4 mb-8 rounded-lg shadow-sm flex items-start" role="alert">
                <i class="ph-fill ph-warning-circle text-red-500 text-2xl mr-3 mt-0.5"></i>
                <div>
                    <h4 class="font-bold text-red-900 mb-1">Erro ao processar o gabarito</h4>
                    <?php if(isset($_GET['msg'])): ?>
                        <p class="text-sm"><?= htmlspecialchars($_GET['msg']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form action="process_gabarito.php" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="mb-6">
                <label class="block text-sm font-bold text-slate-700 mb-2">1. Selecione a Avaliação <span class="text-red-500">*</span></label>
                <div class="relative">
                    <select name="nome_avaliacao" required class="block w-full pl-3 pr-8 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary shadow-sm bg-white appearance-none">
                        <option value="">-- Escolha uma Avaliação já cadastrada --</option>
                        <?php foreach ($avaliacoesList as $av): ?>
                            <?php
                                $val = htmlspecialchars($av['nome_avaliacao']);
                                $label = htmlspecialchars($av['nome_avaliacao']);
                            ?>
                            <option value="<?= $val ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-slate-500">
                        <i class="ph-bold ph-caret-down"></i>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2">Escolha a avaliação para a qual este gabarito será aplicado. Todos os alunos, independentemente do período em que fizeram essa mesma avaliação, terão suas notas comparadas com esse gabarito único.</p>
            </div>

            <div class="mb-8">
                <label class="block text-sm font-bold text-slate-700 mb-3">2. Selecione o arquivo do Gabarito .CSV <span class="text-red-500">*</span></label>
                <div class="mt-1 flex justify-center px-6 pt-8 pb-10 border-2 border-slate-300 border-dashed rounded-xl hover:border-primary transition-colors bg-slate-50 group relative">
                    <div class="space-y-2 text-center">
                        <i class="ph-fill ph-file-csv text-5xl text-slate-400 group-hover:text-primary transition-colors mb-4 block"></i>
                        <div class="flex text-sm text-slate-600 justify-center">
                            <label for="csv_file" class="relative cursor-pointer bg-white rounded-md font-bold text-primary hover:text-emerald-700 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-primary px-3 py-1 border border-primary/20 shadow-sm">
                                <span>Procurar gabarito no computador</span>
                                <input id="csv_file" name="csv_file" type="file" accept=".csv" class="sr-only" required onchange="document.getElementById('file-name').innerHTML = '<i class=\'ph-fill ph-check-square mr-2 text-primary\'></i> ' + this.files[0].name">
                            </label>
                        </div>
                        <p class="text-xs text-slate-500 mt-2 pt-2">Apenas arquivos .CSV com Cabeçalho e Linha(s) de respostas.</p>
                        <p id="file-name" class="text-sm font-bold text-slate-800 mt-4 flex justify-center items-center h-6"></p>
                    </div>
                </div>
            </div>

            <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 mb-8 text-sm text-blue-800 shadow-inner">
                <h4 class="font-bold flex items-center mb-3 text-blue-900 text-base"><i class="ph-fill ph-info mr-2 text-blue-600"></i> Como formatar o Gabarito:</h4>

                <p class="font-semibold text-blue-700 mb-2">Estrutura do arquivo:</p>
                <ul class="space-y-1.5 pl-2 mb-4">
                    <li class="flex items-start"><i class="ph-bold ph-check text-blue-500 mt-0.5 mr-2 shrink-0"></i> <span>O arquivo deve ter <strong>duas colunas</strong> e <strong>uma linha por questão</strong>, conforme o modelo ao lado.</span></li>
                    <li class="flex items-start"><i class="ph-bold ph-check text-blue-500 mt-0.5 mr-2 shrink-0"></i> <span>Coluna da questão — nomeie como: <code class="bg-blue-100 px-1 rounded">Questão</code>, <code class="bg-blue-100 px-1 rounded">Questao</code>, <code class="bg-blue-100 px-1 rounded">Número</code>, <code class="bg-blue-100 px-1 rounded">Numero</code> ou <code class="bg-blue-100 px-1 rounded">#</code>.</span></li>
                    <li class="flex items-start"><i class="ph-bold ph-check text-blue-500 mt-0.5 mr-2 shrink-0"></i> <span>Coluna da resposta — nomeie como: <code class="bg-blue-100 px-1 rounded">Gabarito</code>, <code class="bg-blue-100 px-1 rounded">Resposta</code>, <code class="bg-blue-100 px-1 rounded">Alternativa</code>, <code class="bg-blue-100 px-1 rounded">Letra</code> ou <code class="bg-blue-100 px-1 rounded">Correta</code>.</span></li>
                    <li class="flex items-start"><i class="ph-bold ph-check text-blue-500 mt-0.5 mr-2 shrink-0"></i> <span>A resposta de cada questão deve ser uma única letra: <code class="bg-blue-100 px-1 rounded">A</code>, <code class="bg-blue-100 px-1 rounded">B</code>, <code class="bg-blue-100 px-1 rounded">C</code>, <code class="bg-blue-100 px-1 rounded">D</code> ou <code class="bg-blue-100 px-1 rounded">E</code>. Deixe em branco para questão anulada.</span></li>
                    <li class="flex items-start"><i class="ph-bold ph-arrows-clockwise text-blue-500 mt-0.5 mr-2 shrink-0"></i> <span>Se subir um novo gabarito para a mesma Avaliação, ele <strong>substituirá</strong> o anterior.</span></li>
                </ul>

                <details class="mt-1">
                    <summary class="cursor-pointer text-blue-700 font-semibold text-xs hover:underline flex items-center gap-1"><i class="ph-bold ph-table mr-1"></i> Ver exemplo de CSV</summary>
                    <div class="mt-3 flex gap-6 items-start flex-wrap">
                        <div class="overflow-x-auto rounded-lg border border-blue-200">
                            <table class="text-xs text-left font-mono">
                                <thead class="bg-blue-100 text-blue-800">
                                    <tr>
                                        <th class="px-3 py-1.5 border-r border-blue-200">Questão</th>
                                        <th class="px-3 py-1.5">Gabarito</th>
                                    </tr>
                                </thead>
                                <tbody class="text-slate-700">
                                    <tr class="bg-white border-t border-blue-100"><td class="px-3 py-1 border-r border-blue-100 text-center">1</td><td class="px-3 py-1 text-center">B</td></tr>
                                    <tr class="bg-blue-50 border-t border-blue-100"><td class="px-3 py-1 border-r border-blue-100 text-center">2</td><td class="px-3 py-1 text-center">C</td></tr>
                                    <tr class="bg-white border-t border-blue-100"><td class="px-3 py-1 border-r border-blue-100 text-center">3</td><td class="px-3 py-1 text-center">A</td></tr>
                                    <tr class="bg-blue-50 border-t border-blue-100"><td class="px-3 py-1 border-r border-blue-100 text-center">4</td><td class="px-3 py-1 text-center">B</td></tr>
                                    <tr class="bg-white border-t border-blue-100"><td class="px-3 py-1 border-r border-blue-100 text-center">5</td><td class="px-3 py-1 text-center">A</td></tr>
                                    <tr class="bg-blue-50 border-t border-blue-100"><td class="px-3 py-1 border-r border-blue-100 text-center text-slate-400 italic" colspan="2">… (uma linha por questão)</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-blue-600 italic self-center max-w-xs">O arquivo deve ter exatamente este formato: cabeçalho na primeira linha e uma questão por linha abaixo, sem linhas em branco no meio.</p>
                    </div>
                </details>
            </div>

            <div class="flex justify-end pt-4 border-t border-slate-100">
                <button type="submit" class="bg-primary hover:bg-emerald-600 text-white font-bold py-3 px-8 rounded-lg shadow-md hover:shadow-lg transition-all flex items-center">
                    <i class="ph-bold ph-upload-simple mr-2 text-lg"></i> Salvar Gabarito
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
