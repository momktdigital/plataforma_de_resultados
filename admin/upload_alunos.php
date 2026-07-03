<?php
require_once 'includes/header.php';
?>

<style>
details[open] .col-arrow { transform: rotate(180deg); }
.col-arrow { transition: transform .2s; }
.col-tag-req { display:inline-block; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; padding:1px 7px; border-radius:999px; background:#FEE2E2; color:#B91C1C; }
.col-tag-opt { display:inline-block; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; padding:1px 7px; border-radius:999px; background:#D1FAE5; color:#065F46; }
.col-var { font-family:ui-monospace,monospace; font-size:10.5px; color:#475569; background:#F1F5F9; padding:1px 5px; border-radius:4px; margin-right:3px; white-space:nowrap; }
</style>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Importar Alunos</h1>
        <p class="text-slate-500 mt-1">Cadastre e atualize a lista de alunos por período letivo e curso</p>
    </div>
    <a href="alunos.php" class="bg-white border border-slate-200 text-slate-600 hover:text-primary hover:border-primary px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center shadow-sm">
        <i class="ph ph-arrow-left mr-2"></i> Voltar para Alunos
    </a>
</div>

<!-- Referência de colunas -->
<div class="mb-6 space-y-2">
    <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3 flex items-center gap-2">
        <i class="ph-fill ph-info text-slate-400"></i> Referência de colunas por arquivo
    </p>

    <details class="bg-white border border-slate-200 rounded-xl overflow-hidden group">
        <summary class="flex items-center justify-between px-5 py-3.5 cursor-pointer select-none hover:bg-slate-50 list-none">
            <div class="flex items-center gap-3">
                <i class="ph-fill ph-file-xls text-blue-600 text-xl"></i>
                <span class="font-bold text-sm text-slate-800">Arquivo de Matrícula de Alunos</span>
                <span class="text-xs bg-slate-100 text-slate-500 font-bold px-2 py-0.5 rounded-full">.xlsx / .xls / .csv</span>
                <span class="text-xs bg-red-100 text-red-700 font-bold px-2 py-0.5 rounded-full">obrigatório</span>
            </div>
            <i class="ph ph-caret-down text-slate-400 col-arrow"></i>
        </summary>
        <div class="px-5 pb-4 border-t border-slate-100">
            <p class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-3 leading-relaxed">
                <strong>Regras importantes:</strong> Apenas as colunas abaixo são lidas — demais colunas do arquivo são ignoradas.
                <strong>Curso</strong> e <strong>Per. Letivo</strong> devem estar no próprio arquivo (não há seleção manual).
                A importação usa <em>upsert</em>: se o aluno (RA + Per. Letivo + Curso) já existir, seus dados serão atualizados.
                <strong>Per. Letivo</strong> e <strong>Período</strong> são conceitos distintos: Per. Letivo é o semestre (ex.: 2026/1); Período é a fase acadêmica (ex.: 5º).
            </p>
            <table class="w-full text-xs mt-3 border-collapse">
                <thead>
                    <tr class="text-left text-[10px] text-slate-400 uppercase tracking-wider border-b border-slate-100">
                        <th class="pb-2 pr-3 font-bold w-6">#</th>
                        <th class="pb-2 pr-3 font-bold w-8"></th>
                        <th class="pb-2 pr-4 font-bold">Coluna principal</th>
                        <th class="pb-2 pr-4 font-bold">Nomes aceitos no arquivo</th>
                        <th class="pb-2 font-bold">Descrição</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">1</td>
                        <td class="py-2 pr-3"><span class="col-tag-opt">Opt</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">Cód. Perfil</td>
                        <td class="py-2 pr-4"><span class="col-var">Cód. Perfil</span><span class="col-var">Cod Perfil</span><span class="col-var">CodigoPerfil</span></td>
                        <td class="py-2 text-slate-600">Código de perfil do sistema acadêmico.</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">2</td>
                        <td class="py-2 pr-3"><span class="col-tag-req">Req</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">RA</td>
                        <td class="py-2 pr-4"><span class="col-var">RA</span><span class="col-var">Matricula</span><span class="col-var">MatriculaAluno</span></td>
                        <td class="py-2 text-slate-600">Registro Acadêmico. Linhas sem RA são ignoradas.</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">3</td>
                        <td class="py-2 pr-3"><span class="col-tag-opt">Opt</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">Nome</td>
                        <td class="py-2 pr-4"><span class="col-var">Nome</span><span class="col-var">Aluno</span><span class="col-var">Estudante</span><span class="col-var">Nome do Aluno</span></td>
                        <td class="py-2 text-slate-600">Nome completo do aluno.</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">4</td>
                        <td class="py-2 pr-3"><span class="col-tag-opt">Opt</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">Status</td>
                        <td class="py-2 pr-4"><span class="col-var">Status</span><span class="col-var">Situacao</span></td>
                        <td class="py-2 text-slate-600">Situação da matrícula (ex.: Ativo, Trancado).</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">5</td>
                        <td class="py-2 pr-3"><span class="col-tag-req">Req</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">Per. Letivo</td>
                        <td class="py-2 pr-4"><span class="col-var">Per. Letivo</span><span class="col-var">Periodo Letivo</span><span class="col-var">PeriodoLetivo</span></td>
                        <td class="py-2 text-slate-600">Semestre de matrícula (ex.: 2026/1). Obrigatório no arquivo.</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">6</td>
                        <td class="py-2 pr-3"><span class="col-tag-req">Req</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">Curso</td>
                        <td class="py-2 pr-4"><span class="col-var">Curso</span></td>
                        <td class="py-2 text-slate-600">Nome do curso. Obrigatório no arquivo.</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">7</td>
                        <td class="py-2 pr-3"><span class="col-tag-opt">Opt</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">Turma</td>
                        <td class="py-2 pr-4"><span class="col-var">Turma</span><span class="col-var">TURMA</span></td>
                        <td class="py-2 text-slate-600">Divisão dentro do período (ex.: A, B, Manhã, Tarde).</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">8</td>
                        <td class="py-2 pr-3"><span class="col-tag-req">Req</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">Período</td>
                        <td class="py-2 pr-4"><span class="col-var">Período</span><span class="col-var">PERIODO</span></td>
                        <td class="py-2 text-slate-600">Fase acadêmica do aluno (1º, 2º, …, 10º). Determina o agrupamento nos dashboards.</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">9</td>
                        <td class="py-2 pr-3"><span class="col-tag-opt">Opt</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">Dt. Nascimento</td>
                        <td class="py-2 pr-4"><span class="col-var">Dt. Nascimento</span><span class="col-var">Data Nascimento</span><span class="col-var">Data de Nascimento</span></td>
                        <td class="py-2 text-slate-600">Data de nascimento (dd/mm/aaaa ou formato Excel).</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">10</td>
                        <td class="py-2 pr-3"><span class="col-tag-opt">Opt</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">CPF</td>
                        <td class="py-2 pr-4"><span class="col-var">CPF</span><span class="col-var">Cpf</span></td>
                        <td class="py-2 text-slate-600">CPF do aluno.</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-400 font-mono text-[10px]">11</td>
                        <td class="py-2 pr-3"><span class="col-tag-opt">Opt</span></td>
                        <td class="py-2 pr-4 font-bold text-slate-700">Email</td>
                        <td class="py-2 pr-4"><span class="col-var">Email</span><span class="col-var">E-mail</span></td>
                        <td class="py-2 text-slate-600">Endereço de e-mail institucional ou pessoal.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </details>
</div>

<!-- Formulário de importação -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-4xl mx-auto">
    <div class="p-6 border-b border-slate-100 bg-slate-50">
        <h2 class="text-lg font-bold text-slate-800 flex items-center">
            <i class="ph-fill ph-upload-simple text-primary mr-2 text-xl"></i> Importar Alunos
        </h2>
    </div>
    <div class="p-8 space-y-6">

        <div id="alertAlunos" class="hidden"></div>

        <!-- Drop zone -->
        <div>
            <label class="block text-sm font-bold text-slate-700 mb-2">
                Arquivo de Matrícula <span class="text-red-500">*</span>
            </label>
            <label id="dropMatricula" class="flex flex-col items-center justify-center border-2 border-dashed border-slate-300 rounded-xl p-10 cursor-pointer hover:border-primary hover:bg-blue-50/30 transition-colors group">
                <i class="ph-fill ph-student text-5xl text-slate-400 group-hover:text-primary mb-3 transition-colors"></i>
                <span class="text-sm font-medium text-slate-600" id="labelMatricula">Clique para selecionar ou arraste o arquivo</span>
                <span class="text-xs text-slate-400 mt-1">.xlsx, .xls ou .csv — deve conter as colunas: RA, Período, Per. Letivo, Curso</span>
                <input type="file" id="fileMatricula" accept=".xlsx,.xls,.csv" class="hidden">
            </label>
        </div>

        <!-- Preview -->
        <div id="previewMatricula" class="hidden bg-blue-50 border border-blue-200 rounded-xl p-5">
            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center mb-3">
                <i class="ph-fill ph-magnifying-glass mr-2 text-primary"></i> Pré-visualização
            </h3>
            <div id="previewMatriculaStats" class="grid grid-cols-2 sm:grid-cols-4 gap-3"></div>
            <div id="previewMatriculaExtra" class="mt-3 space-y-1"></div>
            <div id="previewMatriculaErros" class="hidden mt-3 bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-800"></div>
        </div>

        <!-- Progresso -->
        <div id="progressArea" class="hidden bg-slate-50 border border-slate-200 rounded-xl p-5">
            <div class="flex justify-between items-center mb-2">
                <span class="text-sm font-bold text-slate-700" id="progressLabel">Importando...</span>
                <span class="text-sm font-black text-primary" id="progressPct">0%</span>
            </div>
            <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                <div id="progressBar" class="h-3 bg-primary rounded-full transition-all duration-300" style="width:0%"></div>
            </div>
            <p id="progressDetail" class="text-xs text-slate-500 mt-2">Preparando importação…</p>
        </div>

        <!-- Botão -->
        <div class="flex gap-3 pt-2 border-t border-slate-100">
            <button id="btnImportarAlunos" disabled
                    class="bg-primary hover:bg-emerald-600 text-white font-bold py-3 px-8 rounded-lg shadow-md hover:shadow-lg transition-all flex items-center disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="ph-bold ph-paper-plane-right mr-2 text-lg"></i> Importar Alunos
            </button>
        </div>

    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="js/di_parser.js"></script>
<script>
const BATCH_SIZE = 200;
const ADMIN_CURSO = <?= json_encode($_SESSION['admin_curso'] ?? null) ?>;
const ADMIN_ROLE  = <?= json_encode($_SESSION['admin_role']  ?? 'superadmin') ?>;

let wbMatricula  = null;
let parsedAlunos = null;

const dropEl = document.getElementById('dropMatricula');

dropEl.addEventListener('dragover',  e => { e.preventDefault(); dropEl.classList.add('border-primary','bg-blue-50/30'); });
dropEl.addEventListener('dragleave', ()  => { if (!wbMatricula) dropEl.classList.remove('border-primary','bg-blue-50/30'); });
dropEl.addEventListener('drop',      e  => { e.preventDefault(); const f = e.dataTransfer.files[0]; if (f) handleFile(f); });
document.getElementById('fileMatricula').addEventListener('change', e => { const f = e.target.files[0]; if (f) handleFile(f); });

function handleFile(file) {
    loadWorkbook(file, wb => {
        wbMatricula = wb;
        document.getElementById('labelMatricula').textContent = '✓ ' + file.name;
        dropEl.classList.add('border-primary', 'bg-blue-50/30');
        processPreview();
    });
}

function processPreview() {
    const previewEl = document.getElementById('previewMatricula');
    const statsEl   = document.getElementById('previewMatriculaStats');
    const extraEl   = document.getElementById('previewMatriculaExtra');
    const errosEl   = document.getElementById('previewMatriculaErros');
    const btn       = document.getElementById('btnImportarAlunos');

    parsedAlunos = null;
    btn.disabled = true;
    previewEl.classList.remove('hidden');
    errosEl.classList.add('hidden');
    statsEl.innerHTML = '';
    extraEl.innerHTML = '';

    let rows;
    try { rows = sheetJSON(wbMatricula, 0); }
    catch (err) { showErro('Não foi possível ler o arquivo: ' + err.message); return; }

    if (!rows || !rows.length) { showErro('Nenhum dado encontrado na primeira aba.'); return; }

    let result;
    try { result = parseMatriculas(rows); }
    catch (err) { showErro('Erro ao processar arquivo: ' + err.message); return; }

    if (!result || !result.length) { showErro('Nenhum aluno válido encontrado. Verifique a coluna RA.'); return; }

    // Filtra por curso do coordenador se aplicável
    const visivel = (ADMIN_ROLE === 'coordinator' && ADMIN_CURSO)
        ? result.filter(a => a.curso === ADMIN_CURSO)
        : result;

    const comCurso         = result.filter(a => a.curso).length;
    const comPeriodoLetivo = result.filter(a => a.periodo_letivo).length;
    const comPeriodo       = result.filter(a => a.periodo).length;
    const comTurma         = result.filter(a => a.turma).length;

    const cursos   = [...new Set(result.map(a => a.curso).filter(Boolean))].sort();
    const pLetivos = [...new Set(result.map(a => a.periodo_letivo).filter(Boolean))].sort();
    const periodos = [...new Set(result.map(a => a.periodo).filter(Boolean))].sort((a, b) => parseInt(a) - parseInt(b));

    statsEl.innerHTML = [
        statCard('Total de alunos', result.length, 'ph-users', 'text-blue-600', 'bg-blue-50'),
        statCard('Com período', comPeriodo, 'ph-graduation-cap', 'text-emerald-600', 'bg-emerald-50'),
        statCard('Com turma', comTurma, 'ph-chalkboard-teacher', 'text-purple-600', 'bg-purple-50'),
        statCard('Serão importados', visivel.length, 'ph-paper-plane-right', 'text-primary', 'bg-emerald-50'),
    ].join('');

    const avisos = [];

    if (cursos.length) {
        extraEl.insertAdjacentHTML('beforeend', `<div class="text-xs text-slate-600"><strong>Cursos detectados:</strong> ${cursos.map(c => '<span class="inline-block bg-slate-200 rounded px-2 py-0.5 mr-1">' + escHtml(c) + '</span>').join('')}</div>`);
    }
    if (pLetivos.length) {
        extraEl.insertAdjacentHTML('beforeend', `<div class="text-xs text-slate-600"><strong>Per. Letivo detectado:</strong> ${pLetivos.map(p => '<span class="inline-block bg-blue-100 text-blue-800 rounded px-2 py-0.5 mr-1 font-mono">' + escHtml(p) + '</span>').join('')}</div>`);
    }
    if (periodos.length) {
        extraEl.insertAdjacentHTML('beforeend', `<div class="text-xs text-slate-600"><strong>Períodos acadêmicos:</strong> ${periodos.map(p => '<span class="inline-block bg-slate-200 rounded px-2 py-0.5 mr-1">' + escHtml(p) + '</span>').join('')}</div>`);
    }

    if (comCurso === 0)         avisos.push('Coluna <strong>Curso</strong> não encontrada — todos os alunos serão ignorados na importação.');
    if (comPeriodoLetivo === 0) avisos.push('Coluna <strong>Per. Letivo</strong> não encontrada — todos os alunos serão ignorados na importação.');
    if (comPeriodo === 0)       avisos.push('Coluna <strong>Período</strong> não encontrada — todos os alunos serão ignorados na importação.');
    if (ADMIN_ROLE === 'coordinator' && ADMIN_CURSO && visivel.length < result.length) {
        avisos.push(`${result.length - visivel.length} aluno(s) de outros cursos serão ignorados (seu acesso é restrito a <strong>${escHtml(ADMIN_CURSO)}</strong>).`);
    }

    if (avisos.length) {
        extraEl.insertAdjacentHTML('beforeend', `<div class="bg-red-50 border border-red-200 rounded-lg p-2 text-xs text-red-800 space-y-1">${avisos.map(a => '<div>⚠ ' + a + '</div>').join('')}</div>`);
    }

    parsedAlunos = result;
    btn.disabled = (visivel.length === 0);
}

function statCard(label, val, icon, color, bg) {
    return `<div class="rounded-xl border border-slate-200 p-4 ${bg} flex items-center gap-3">
        <i class="ph-fill ${icon} text-2xl ${color}"></i>
        <div><div class="text-xs font-bold text-slate-500 uppercase">${label}</div><div class="text-xl font-black text-slate-800">${val}</div></div>
    </div>`;
}

function showErro(msg) {
    const el = document.getElementById('previewMatriculaErros');
    el.classList.remove('hidden');
    el.innerHTML = '<strong>Erro:</strong> ' + msg;
}

// ── Importar em lotes ─────────────────────────────────────────────────────────
document.getElementById('btnImportarAlunos').addEventListener('click', async () => {
    if (!parsedAlunos || !parsedAlunos.length) {
        showAlert('Nenhum dado para importar. Selecione um arquivo válido.', true);
        return;
    }

    const btn            = document.getElementById('btnImportarAlunos');
    const progressArea   = document.getElementById('progressArea');
    const progressBar    = document.getElementById('progressBar');
    const progressPct    = document.getElementById('progressPct');
    const progressLabel  = document.getElementById('progressLabel');
    const progressDetail = document.getElementById('progressDetail');

    btn.disabled  = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner-gap animate-spin mr-2"></i> Importando…';
    progressArea.classList.remove('hidden');
    progressBar.className = 'h-3 bg-primary rounded-full transition-all duration-300';
    document.getElementById('alertAlunos').classList.add('hidden');

    const batches     = [];
    const totalAlunos = parsedAlunos.length;
    for (let i = 0; i < parsedAlunos.length; i += BATCH_SIZE) {
        batches.push(parsedAlunos.slice(i, i + BATCH_SIZE));
    }

    function setProgress(done, total, label, detail) {
        const pct = Math.round((done / total) * 100);
        progressBar.style.width    = pct + '%';
        progressPct.textContent    = pct + '%';
        progressLabel.textContent  = label;
        progressDetail.textContent = detail;
    }

    let totalImportados = 0;
    try {
        for (let i = 0; i < batches.length; i++) {
            setProgress(
                i, batches.length,
                `Importando lote ${i + 1} de ${batches.length}…`,
                `${totalImportados} de ${totalAlunos} alunos enviados`
            );

            const resp = await fetch('alunos_di_process.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ alunos: batches[i] }),
            });

            if (!resp.ok) throw new Error(`Erro HTTP ${resp.status} no lote ${i + 1}.`);
            const result = await resp.json();
            if (!result.success) throw new Error(result.message);
            totalImportados += result.count ?? batches[i].length;
        }

        setProgress(batches.length, batches.length, 'Importação concluída!',
            `${totalImportados} aluno(s) importado(s)/atualizado(s).`);
        progressBar.classList.replace('bg-primary', 'bg-emerald-500');
        showAlert(`<strong>Importação concluída!</strong> ${totalImportados} aluno(s) importado(s)/atualizado(s) em ${batches.length} lote(s).`, false);

    } catch (err) {
        progressBar.classList.replace('bg-primary', 'bg-red-500');
        progressLabel.textContent  = 'Erro na importação';
        progressDetail.textContent = err.message;
        showAlert('<strong>Erro:</strong> ' + escHtml(err.message), true);
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="ph-bold ph-paper-plane-right mr-2 text-lg"></i> Importar Alunos';
    }
});

function showAlert(html, isError) {
    const el = document.getElementById('alertAlunos');
    el.className = 'p-4 rounded-xl mb-4 flex items-start gap-3 ' +
        (isError ? 'bg-red-50 border border-red-200 text-red-800' : 'bg-emerald-50 border border-emerald-200 text-emerald-800');
    el.innerHTML = '<i class="ph-fill ' + (isError ? 'ph-warning-circle text-red-500' : 'ph-check-circle text-emerald-500') +
        ' text-2xl shrink-0"></i><div class="text-sm">' + html + '</div>';
    el.classList.remove('hidden');
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<?php require_once 'includes/footer.php'; ?>
