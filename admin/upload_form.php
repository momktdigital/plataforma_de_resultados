<?php
require_once 'includes/header.php';
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

            <div class="mb-6">
                <label class="block text-sm font-bold text-slate-700 mb-2">1. Nome da Avaliação <span class="text-red-500">*</span></label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="ph-fill ph-pencil-simple text-slate-400"></i>
                    </div>
                    <input type="text" id="nome_avaliacao" name="nome_avaliacao" required class="block w-full pl-10 pr-3 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary shadow-sm" placeholder="Ex: Simulado ENEM, Prova Bimestral, P1...">
                </div>
                <p class="text-xs text-slate-500 mt-2">Dê um nome claro para esta avaliação. Isso ajudará a diferenciar várias provas do mesmo período.</p>
            </div>

            <div class="mb-6">
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
                <h4 class="font-bold flex items-center mb-3 text-slate-800 text-base"><i class="ph-fill ph-info mr-2 text-blue-500"></i> Regras Importantes do CSV:</h4>
                <ul class="space-y-2 pl-2">
                    <li class="flex items-start"><i class="ph-bold ph-check text-emerald-500 mt-0.5 mr-2"></i> <span>O arquivo DEVE conter as colunas <strong>RA</strong> e <strong>Período</strong>.</span></li>
                    <li class="flex items-start"><i class="ph-bold ph-check text-emerald-500 mt-0.5 mr-2"></i> <span>A coluna <strong>NOME1</strong> será lida mas ignorada na gravação, garantindo privacidade.</span></li>
                    <li class="flex items-start"><i class="ph-bold ph-check text-emerald-500 mt-0.5 mr-2"></i> <span>As colunas das questões devem estar nomeadas exatamente como <strong>Q1, Q2... Q100</strong>.</span></li>
                    <li class="flex items-start"><i class="ph-bold ph-check text-emerald-500 mt-0.5 mr-2"></i> <span>Qualquer outra coluna após Q100 será automaticamente agrupada em "Notas Finais".</span></li>
                    <li class="flex items-start"><i class="ph-bold ph-arrows-clockwise text-blue-500 mt-0.5 mr-2"></i> <span>Se um RA já existir no mesmo Período e Avaliação, os dados serão <strong>atualizados (Upsert)</strong>.</span></li>
                </ul>
            </div>

            <div class="flex justify-end pt-4 border-t border-slate-100">
                <button type="submit" class="bg-primary hover:bg-emerald-600 text-white font-bold py-3 px-8 rounded-lg shadow-md hover:shadow-lg transition-all flex items-center">
                    <i class="ph-bold ph-paper-plane-right mr-2 text-lg"></i> Processar Arquivo CSV
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
