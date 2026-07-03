// Utilitários compartilhados de parsing para importação/edição DI
// Usado por upload_di.php e sessoes_di.php

function norm(s) {
    return String(s ?? '').trim().normalize('NFD').replace(/[̀-ͯ]/g, '');
}
function normUp(s) { return norm(s).toUpperCase(); }
function escHtml(s) { return String(s).replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }

function findVal(row, patterns) {
    for (const [key, val] of Object.entries(row)) {
        const nk = norm(key).toLowerCase();
        for (const pat of patterns) {
            if (typeof pat === 'string' ? nk === pat : pat.test(nk)) return val ?? '';
        }
    }
    return '';
}

// Carrega CSV ou Excel com xlsx.js
// Para CSV: tenta UTF-8 primeiro; se detectar corrupção, relê como Windows-1252
function loadWorkbook(file, onReady) {
    const isCsv = /\.csv$/i.test(file.name);

    function parseWb(text) { return XLSX.read(text, { type: 'string' }); }

    function hasCorruption(wb) {
        const ws = wb.Sheets[wb.SheetNames[0]];
        return Object.values(ws).some(cell => cell && cell.v && String(cell.v).includes(''));
    }

    if (isCsv) {
        const r1 = new FileReader();
        r1.onload = e1 => {
            try {
                const wb = parseWb(e1.target.result);
                if (hasCorruption(wb)) {
                    const r2 = new FileReader();
                    r2.onload = e2 => { try { onReady(parseWb(e2.target.result)); } catch (e) { alert('Erro ao ler o arquivo: ' + e.message); } };
                    r2.readAsText(file, 'windows-1252');
                } else {
                    onReady(wb);
                }
            } catch (e) { alert('Erro ao ler o arquivo: ' + e.message); }
        };
        r1.readAsText(file, 'UTF-8');
    } else {
        const reader = new FileReader();
        reader.onload = ev => {
            try { onReady(XLSX.read(new Uint8Array(ev.target.result), { type: 'array' })); }
            catch (e) { alert('Erro ao ler o arquivo: ' + e.message); }
        };
        reader.readAsArrayBuffer(file);
    }
}

// Retorna null se a aba não for encontrada
function findSheet(wb, names) {
    for (const n of names) {
        const found = wb.SheetNames.find(s => s.toLowerCase().includes(n.toLowerCase()));
        if (found) return XLSX.utils.sheet_to_json(wb.Sheets[found], { defval: null });
    }
    return null;
}

function sheetJSON(wb, idx) {
    const name = wb.SheetNames[idx] || wb.SheetNames[0];
    return XLSX.utils.sheet_to_json(wb.Sheets[name], { defval: null });
}

// Normaliza período: P1→1º, P2→2º, 1→1º, "12º PERÍODO"→12º, "ESTÁGIO / 9º PERÍODO"→9º
function normalizePeriodo(v) {
    const s = norm(v).toUpperCase().trim();
    if (!s) return '';
    const mP = s.match(/^P(\d+)$/);
    if (mP) return mP[1] + 'º';
    const mN = s.match(/^(\d+)$/);
    if (mN) return mN[1] + 'º';
    const mFull = s.match(/^(\d+)/);
    if (mFull) return mFull[1] + 'º';
    // Extrai número de strings compostas, ex: "ESTAGIO CURRICULAR / 9º PERIODO"
    const mAny = s.match(/(\d+)\s*[º°o]/i);
    if (mAny) return mAny[1] + 'º';
    return s;
}

// Infere grupo de prova a partir do número do período
function inferProva(periodo) {
    const n = parseInt((String(periodo).match(/(\d+)/) || [])[1] || '0', 10);
    if (n >= 1 && n <= 4) return '1º ao 4º';
    if (n >= 5)           return '5º ao 10º';
    return periodo;
}

// Arquivo de RESULTADOS: só RA + respostas por questão.
// Período e turma são buscados no banco (di_alunos) pelo período letivo especificado no import.
function parseAlunos(rowsAlunos) {
    const alunos = [];
    rowsAlunos.forEach(r => {
        const matricula = norm(findVal(r, [/^(matriculaaluno|matricula|ra)$/]));
        if (!matricula) return;

        const questoes = {};
        let respostasValidas = 0;
        Object.keys(r).forEach(k => {
            if (/^Q\s*0*\d+$/i.test(norm(k))) {
                const num = parseInt(k.replace(/\D/g, ''), 10);
                const resp = normUp(r[k]);
                questoes['Q' + num] = resp;
                if (resp && resp !== 'BLANK') respostasValidas++;
            }
        });

        alunos.push({ matricula, questoes, ausente: respostasValidas === 0 });
    });
    return alunos;
}

// Normaliza período letivo: "2026.1", "2026 1", "20261" → "2026/1"
function normalizePeriodoLetivo(v) {
    const s = norm(v).trim();
    if (!s) return '';
    const m = s.match(/^(\d{4})[.\-\/\s]?(\d)$/);
    if (m) return m[1] + '/' + m[2];
    return s;
}

// Converte valor de data (Excel serial ou string) → "YYYY-MM-DD"
function parseDate(v) {
    if (v === null || v === undefined || v === '') return '';
    if (typeof v === 'number') {
        const d = new Date(Math.round((v - 25569) * 86400 * 1000));
        if (!isNaN(d.getTime())) {
            const y  = d.getUTCFullYear();
            const mo = String(d.getUTCMonth() + 1).padStart(2, '0');
            const dy = String(d.getUTCDate()).padStart(2, '0');
            return y + '-' + mo + '-' + dy;
        }
    }
    const s = String(v).trim();
    if (!s) return '';
    const m1 = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (m1) return m1[3] + '-' + m1[2].padStart(2, '0') + '-' + m1[1].padStart(2, '0');
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
    return s;
}

// Arquivo de MATRÍCULA: todos os campos do cadastro acadêmico.
// Obrigatórios por linha: RA, Período, Per. Letivo e Curso — todos devem vir do próprio arquivo.
function parseMatriculas(rows) {
    return rows.map(r => ({
        cod_perfil:     norm(findVal(r, [/^(c[oó]d\.?\s*perfil|codigoperfil|cod\s*perfil)$/])),
        ra:             norm(findVal(r, [/^(ra|matricula|matriculaaluno)$/])),
        nome:           norm(findVal(r, [/^(nome|aluno|estudante|nome\s*do\s*aluno)$/])),
        status:         norm(findVal(r, [/^(status|situacao)$/])),
        periodo_letivo: normalizePeriodoLetivo(norm(findVal(r, [/^(per\.?\s*letivo|periodo\s*letivo|periodoletivo)$/]))),
        curso:          normUp(findVal(r, [/^curso$/])),
        turma:          norm(findVal(r, [/^turma$/])),
        periodo:        normalizePeriodo(norm(findVal(r, [/^periodo$/]))),
        dt_nascimento:  parseDate(findVal(r, [/^(dt\.?\s*nascimento|data\s*(?:de\s*)?nascimento|datanascimento)$/])),
        cpf:            norm(findVal(r, [/^cpf$/])),
        email:          norm(findVal(r, [/^(e-?mail|email)$/])),
    })).filter(x => x.ra);
}

function parseQuestoes(rowsGab) {
    const questoes = [];
    if (!rowsGab || !rowsGab.length) return questoes;
    rowsGab.forEach(r => {
        const numRaw = findVal(r, [/^(questao|numero|item|#|q)$/, /^quest/, /^num/]);
        const numero = parseInt(String(numRaw).replace(/\D/g, ''), 10) || 0;
        const gabarito = normUp(findVal(r, [/^(gabarito|resposta|alternativa|letra|correta)$/]));
        if (!numero || !gabarito) return;
        questoes.push({
            prova:       norm(findVal(r, [/^prova$/])),
            numero,
            gabarito,
            conteudo:    norm(findVal(r, [/^(conteudo|conteudo programatico)$/, /conteudo/])),
            bloom:       norm(findVal(r, [/^(bloom|taxonomia)$/, /bloom/])),
            area:        norm(findVal(r, [/^area$/])),
            tema:        norm(findVal(r, [/^tema$/])),
            habilidade:  norm(findVal(r, [/^habilidade$/])),
            tipo:        norm(findVal(r, [/^tipo$/])),
            competencia: norm(findVal(r, [/^(competencia|competencia \(dcn|dcn)/, /competencia/])),
        });
    });
    return questoes;
}

function parseAncoras(rowsAnc) {
    if (!rowsAnc.length) return [];
    const cols = Object.keys(rowsAnc[0] || {});
    const ancoras = [];
    rowsAnc.forEach((r, i) => {
        const q14  = Number(r[cols[0]]);
        const q510 = Number(r[cols[1]]);
        if (q14 && q510) ancoras.push({ par: i + 1, q14, q510 });
    });
    return ancoras;
}
