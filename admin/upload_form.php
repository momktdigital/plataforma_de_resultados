<?php
require_once 'includes/header.php';
require_once '../includes/Database.php';

$db = new Database();
$conn = $db->getConnection();

// Buscar avaliações existentes
$avaliacoes_existentes = [];
try {
    $stmt = $conn->query("SELECT DISTINCT nome_avaliacao FROM resultados ORDER BY nome_avaliacao ASC");
    $avaliacoes_existentes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmtGab = $conn->query("SELECT DISTINCT nome_avaliacao FROM gabaritos ORDER BY nome_avaliacao ASC");
    $gabaritos_existentes = $stmtGab->fetchAll(PDO::FETCH_COLUMN);
    
    $avaliacoes_existentes = array_unique(array_merge($avaliacoes_existentes, $gabaritos_existentes));
    sort($avaliacoes_existentes);
} catch (PDOException $e) {
    // Silencia erros, array ficará vazio
}
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Upload de CSV</h1>
        <p class="text-slate-500 mt-1">Carregue novas planilhas de resultados no sistema</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-4xl mx-auto">
    <div class="p-6 border-b border-slate-100 bg-slate-50">
        <h2 class="text-xl font-bold text-slate-800 flex items-center">
            <i class="ph-fill ph-file-csv text-primary mr-2"></i> Importar Resultados
        </h2>
    </div>

    <div class="p-8">
        <?php if (isset($_GET['success'])): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 mb-8 rounded-lg shadow-sm flex items-start" role="alert">
                <i class="ph-fill ph-check-circle text-emerald-500 text-2xl mr-3 mt-0.5"></i>
                <div>
                    <h4 class="font-bold text-emerald-900 mb-1">Arquivo processado com sucesso!</h4>
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
                    <h4 class="font-bold text-red-900 mb-1">Erro ao processar o arquivo</h4>
                    <?php if(isset($_GET['msg'])): ?>
                        <p class="text-sm"><?= htmlspecialchars($_GET['msg']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form action="upload.php" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="mb-6">
                <label class="block text-sm font-bold text-slate-700 mb-2">1. Avaliação <span class="text-red-500">*</span></label>
                
                <div class="flex flex-col gap-3">
                    <?php if (!empty($avaliacoes_existentes)): ?>
                    <div class="flex items-center gap-4 mb-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="tipo_avaliacao" value="existente" class="text-primary focus:ring-primary" checked>
                            <span class="text-sm text-slate-700">Adicionar a uma avaliação existente</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="tipo_avaliacao" value="nova" class="text-primary focus:ring-primary">
                            <span class="text-sm text-slate-700">Criar nova avaliação</span>
                        </label>
                    </div>

                    <div id="div_avaliacao_existente" class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="ph-fill ph-list text-slate-400"></i>
                        </div>
                        <select name="avaliacao_existente" id="avaliacao_existente" class="block w-full pl-10 pr-3 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary shadow-sm bg-white">
                            <option value="">-- Selecione uma avaliação --</option>
                            <?php foreach ($avaliacoes_existentes as $av): ?>
                                <option value="<?= htmlspecialchars($av) ?>"><?= htmlspecialchars($av) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="tipo_avaliacao" value="nova">
                    <?php endif; ?>

                    <div id="div_nova_avaliacao" class="relative <?= !empty($avaliacoes_existentes) ? 'hidden' : '' ?>">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="ph-fill ph-pencil-simple text-slate-400"></i>
                        </div>
                        <input type="text" id="nova_avaliacao" name="nova_avaliacao" class="block w-full pl-10 pr-3 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary shadow-sm" placeholder="Ex: Simulado ENEM, Prova Bimestral, P1...">
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2">Escolha uma avaliação existente para adicionar notas, ou crie uma nova. Dar um nome claro ajudará a diferenciar várias provas.</p>
            </div>

            <div id="div_gabarito_comentado" class="mb-6 <?= !empty($avaliacoes_existentes) ? 'hidden' : '' ?>">
                <label class="block text-sm font-bold text-slate-700 mb-2">2. Arquivo do Gabarito Comentado <span class="text-slate-400 font-normal">(Opcional)</span></label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="ph-fill ph-link text-slate-400"></i>
                    </div>
                    <input type="url" name="link_comentado" placeholder="https://link.com/arquivo.pdf" class="block w-full pl-10 pr-3 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary shadow-sm">
                </div>
                <p class="text-xs text-slate-500 mt-2">Se você tiver um link de arquivo PDF ou vídeo com a resolução das questões, cole aqui para disponibilizá-lo junto aos resultados dessa avaliação.</p>
            </div>

            <div class="mb-8 border border-dashed border-slate-300 rounded-xl p-8 hover:bg-slate-50 transition-colors group">
                <label class="block text-sm font-bold text-slate-700 mb-3 text-center">3. Selecione o arquivo .CSV <span class="text-red-500">*</span></label>
                    <div class="space-y-2 text-center">
                        <i class="ph-fill ph-cloud-arrow-up text-5xl text-slate-400 group-hover:text-primary transition-colors mb-4 block"></i>
                        <div class="flex text-sm text-slate-600 justify-center">
                            <label for="csv_file" class="relative cursor-pointer bg-white rounded-md font-bold text-primary hover:text-emerald-700 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-primary px-3 py-1 border border-primary/20 shadow-sm">
                                <span>Procurar arquivo no computador</span>
                                <input id="csv_file" name="csv_file" type="file" accept=".csv" class="sr-only" required onchange="document.getElementById('file-name').innerHTML = '<i class=\'ph-fill ph-file-text mr-2 text-primary\'></i> ' + this.files[0].name">
                            </label>
                        </div>
                        <p class="text-xs text-slate-500 mt-2 pt-2">Apenas arquivos .CSV formatados corretamente.</p>
                        <p id="file-name" class="text-sm font-bold text-slate-800 mt-4 flex justify-center items-center h-6"></p>
                    </div>
                </div>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-6 mb-8 text-sm text-slate-700 shadow-inner">
                <h4 class="font-bold flex items-center mb-3 text-slate-800 text-base"><i class="ph-fill ph-info mr-2 text-blue-500"></i> Regras do CSV:</h4>

                <p class="font-semibold text-slate-600 mb-2 mt-1">Colunas obrigatórias:</p>
                <ul class="space-y-1.5 pl-2 mb-4">
                    <li class="flex items-start"><i class="ph-bold ph-check text-emerald-500 mt-0.5 mr-2 shrink-0"></i> <span><strong>RA</strong> — Registro Acadêmico do aluno.</span></li>
                    <li class="flex items-start"><i class="ph-bold ph-check text-emerald-500 mt-0.5 mr-2 shrink-0"></i> <span><strong>Período</strong> — Período do curso em que o aluno se encontra (ex: <code class="bg-slate-200 px-1 rounded">1º</code>, <code class="bg-slate-200 px-1 rounded">3º</code>, <code class="bg-slate-200 px-1 rounded">7º</code>). Não é o semestre letivo.</span></li>
                </ul>

                <p class="font-semibold text-slate-600 mb-2">Colunas de questões:</p>
                <ul class="space-y-1.5 pl-2 mb-4">
                    <li class="flex items-start"><i class="ph-bold ph-check text-emerald-500 mt-0.5 mr-2 shrink-0"></i> <span>As questões devem ser nomeadas exatamente como <strong>Q1, Q2, Q3… Q100</strong>. O valor de cada célula deve ser a alternativa marcada (ex: <code class="bg-slate-200 px-1 rounded">A</code>, <code class="bg-slate-200 px-1 rounded">B</code>, <code class="bg-slate-200 px-1 rounded">C</code>, <code class="bg-slate-200 px-1 rounded">D</code> ou <code class="bg-slate-200 px-1 rounded">E</code>).</span></li>
                </ul>

                <p class="font-semibold text-slate-600 mb-2">Cards de resultado:</p>
                <ul class="space-y-2 pl-2 mb-4">
                    <li class="flex items-start">
                        <i class="ph-bold ph-star text-amber-400 mt-0.5 mr-2 shrink-0"></i>
                        <span><strong>Card de destaque</strong> (card escuro no topo): a coluna cujo nome seja exatamente <code class="bg-slate-200 px-1 rounded">Total</code>, <code class="bg-slate-200 px-1 rounded">Nota Final</code>, <code class="bg-slate-200 px-1 rounded">Total de Acertos</code> ou <code class="bg-slate-200 px-1 rounded">Pontuação Final</code> é exibida com destaque no topo do relatório.</span>
                    </li>
                    <li class="flex items-start">
                        <i class="ph-bold ph-cards text-blue-500 mt-0.5 mr-2 shrink-0"></i>
                        <span><strong>Cards de disciplina com total + percentual:</strong> use o padrão <code class="bg-slate-200 px-1 rounded">[Disciplina] - Total de acertos</code> e <code class="bg-slate-200 px-1 rounded">[Disciplina] - Percentual de Acertos</code>. O sistema agrupa as duas colunas em um único card mostrando o número de acertos e o percentual entre parênteses.<br>
                        <span class="text-slate-500 text-xs">Ex: colunas <code class="bg-slate-200 px-1 rounded">Clínica Médica - Total de acertos</code> e <code class="bg-slate-200 px-1 rounded">Clínica Médica - Percentual de Acertos</code> geram um card <strong>Clínica Médica</strong> com o total em verde e o percentual ao lado.</span></span>
                    </li>
                    <li class="flex items-start">
                        <i class="ph-bold ph-check text-emerald-500 mt-0.5 mr-2 shrink-0"></i>
                        <span><strong>Card simples:</strong> qualquer outra coluna que não seja RA, Período, questão (Qn) ou NOME1 vira um card simples mostrando apenas o valor. Ex: <code class="bg-slate-200 px-1 rounded">Nota Redação</code>, <code class="bg-slate-200 px-1 rounded">Nota Objetiva</code>.</span>
                    </li>
                    <li class="flex items-start">
                        <i class="ph-bold ph-info text-blue-400 mt-0.5 mr-2 shrink-0"></i>
                        <span>A coluna <strong>NOME1</strong>, se presente, é lida e descartada — nunca é gravada, garantindo a privacidade dos dados.</span>
                    </li>
                </ul>

                <p class="font-semibold text-slate-600 mb-2">Outras regras:</p>
                <ul class="space-y-1.5 pl-2 mb-4">
                    <li class="flex items-start"><i class="ph-bold ph-arrows-clockwise text-blue-500 mt-0.5 mr-2 shrink-0"></i> <span>Se um RA já existir no mesmo Período e Avaliação, os dados serão <strong>atualizados</strong> automaticamente.</span></li>
                    <li class="flex items-start"><i class="ph-bold ph-check text-emerald-500 mt-0.5 mr-2 shrink-0"></i> <span>O delimitador pode ser <strong>vírgula</strong> (<code class="bg-slate-200 px-1 rounded">,</code>) ou <strong>ponto e vírgula</strong> (<code class="bg-slate-200 px-1 rounded">;</code>) — ambos são detectados automaticamente.</span></li>
                </ul>

                <details class="mt-2">
                    <summary class="cursor-pointer text-primary font-semibold text-xs hover:underline flex items-center gap-1"><i class="ph-bold ph-table mr-1"></i> Ver exemplo de CSV</summary>
                    <div class="mt-3 overflow-x-auto rounded-lg border border-slate-200">
                        <table class="text-xs w-full text-left">
                            <thead class="bg-slate-200 text-slate-700 font-mono">
                                <tr>
                                    <th class="px-2 py-1.5 border-r border-slate-300">RA</th>
                                    <th class="px-2 py-1.5 border-r border-slate-300">Período</th>
                                    <th class="px-2 py-1.5 border-r border-slate-300 text-slate-400">NOME1</th>
                                    <th class="px-2 py-1.5 border-r border-slate-300">Q1</th>
                                    <th class="px-2 py-1.5 border-r border-slate-300">Q2</th>
                                    <th class="px-2 py-1.5 border-r border-slate-300">…</th>
                                    <th class="px-2 py-1.5 border-r border-slate-300 bg-blue-50 text-blue-700" title="Card de disciplina — número grande">Clínica Médica - Total de acertos</th>
                                    <th class="px-2 py-1.5 border-r border-slate-300 bg-blue-50 text-blue-700" title="Card de disciplina — percentual">Clínica Médica - Percentual de Acertos</th>
                                    <th class="px-2 py-1.5 border-r border-slate-300 bg-blue-50 text-blue-700" title="Card de disciplina — número grande">Cirurgia Geral - Total de acertos</th>
                                    <th class="px-2 py-1.5 border-r border-slate-300 bg-blue-50 text-blue-700" title="Card de disciplina — percentual">Cirurgia Geral - Percentual de Acertos</th>
                                    <th class="px-2 py-1.5 border-r border-slate-300 bg-amber-50 text-amber-700" title="Card de destaque escuro no topo">Total de Acertos ★</th>
                                </tr>
                            </thead>
                            <tbody class="font-mono text-slate-600">
                                <tr class="bg-white border-t border-slate-200">
                                    <td class="px-2 py-1.5 border-r border-slate-200">123456</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200">7º</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 text-slate-400 italic">João Silva</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200">A</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200">C</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 text-slate-400">…</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 bg-blue-50">3</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 bg-blue-50">30%</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 bg-blue-50">6</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 bg-blue-50">60%</td>
                                    <td class="px-2 py-1.5 bg-amber-50">38</td>
                                </tr>
                                <tr class="bg-slate-50 border-t border-slate-200">
                                    <td class="px-2 py-1.5 border-r border-slate-200">789012</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200">3º</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 text-slate-400 italic">Maria Souza</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200">B</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200">A</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 text-slate-400">…</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 bg-blue-50">4</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 bg-blue-50">40%</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 bg-blue-50">5</td>
                                    <td class="px-2 py-1.5 border-r border-slate-200 bg-blue-50">50%</td>
                                    <td class="px-2 py-1.5 bg-amber-50">42</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-slate-400 mt-2 italic">
                        <span class="inline-block w-3 h-3 rounded-sm bg-amber-100 border border-amber-300 mr-1 align-middle"></span> ★ Card de destaque escuro no topo.
                        <span class="inline-block w-3 h-3 rounded-sm bg-blue-100 border border-blue-300 ml-3 mr-1 align-middle"></span> Cards de disciplina: par de colunas com <strong>- Total de acertos</strong> e <strong>- Percentual de Acertos</strong> após o nome da disciplina.
                        NOME1 é sempre descartado.
                    </p>
                </details>
            </div>

            <div class="flex justify-end pt-4 border-t border-slate-100">
                <button type="submit" class="bg-primary hover:bg-emerald-600 text-white font-bold py-3 px-8 rounded-lg shadow-md hover:shadow-lg transition-all flex items-center">
                    <i class="ph-bold ph-paper-plane-right mr-2 text-lg"></i> Processar Arquivo CSV
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const radios = document.querySelectorAll('input[name="tipo_avaliacao"]');
        
        function updateFields() {
            const tipo = document.querySelector('input[name="tipo_avaliacao"]:checked')?.value || 'nova';
            const divExistente = document.getElementById('div_avaliacao_existente');
            const divNova = document.getElementById('div_nova_avaliacao');
            const selectExistente = document.getElementById('avaliacao_existente');
            const inputNova = document.getElementById('nova_avaliacao');
            const divGabarito = document.getElementById('div_gabarito_comentado');
            
            if (tipo === 'existente') {
                if (divExistente) divExistente.classList.remove('hidden');
                if (divNova) divNova.classList.add('hidden');
                if (selectExistente) selectExistente.required = true;
                if (inputNova) inputNova.required = false;
                if (divGabarito) divGabarito.classList.add('hidden');
            } else {
                if (divExistente) divExistente.classList.add('hidden');
                if (divNova) divNova.classList.remove('hidden');
                if (selectExistente) selectExistente.required = false;
                if (inputNova) inputNova.required = true;
                if (divGabarito) divGabarito.classList.remove('hidden');
            }
        }

        radios.forEach(radio => radio.addEventListener('change', updateFields));
        
        // Trigger initial state
        updateFields();
    });
</script>

<?php require_once 'includes/footer.php'; ?>
