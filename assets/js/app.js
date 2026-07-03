document.addEventListener('DOMContentLoaded', () => {

    const searchForm = document.getElementById('search-form');
    const cpfInput = document.getElementById('cpf_input');
    const nascimentoInput = document.getElementById('nascimento_input');
    const btnSubmit = document.getElementById('btn-submit');

    const errorMessage = document.getElementById('error-message');
    const errorText = document.getElementById('error-text');

    const viewSearch = document.getElementById('view-search');
    const viewResults = document.getElementById('view-results');
    const view2fa = document.getElementById('view-2fa');

    // Elementos do 2FA
    const form2fa = document.getElementById('2fa-form');
    const displayEmail = document.getElementById('display-email');
    const btnSubmit2fa = document.getElementById('btn-submit-2fa');
    const errorMsg2fa = document.getElementById('error-message-2fa');
    const errorText2fa = document.getElementById('error-text-2fa');
    const btnResend = document.getElementById('btn-resend');
    const resendText = document.getElementById('resend-text');
    const resendTimer = document.getElementById('resend-timer');
    const codigoInput = document.getElementById('codigo_input');
    const btnCancel2fa = document.getElementById('btn-cancel-2fa');

    const btnBack = document.getElementById('btn-back');

    const displayRa = document.getElementById('display-ra');
    const summaryPanel = document.getElementById('summary-panel');
    const answersGrid = document.getElementById('answers-grid');

    const viewSelection = document.getElementById('view-selection');
    const selectionCardsGrid = document.getElementById('selection-cards-grid');
    const btnBackSelection = document.getElementById('btn-back-selection');
    const btnBackToSelection = document.getElementById('btn-back-to-selection');

    let allData = []; // Armazena todos os períodos do aluno consultado

    // --- Funções Auxiliares ---

    const showError = (msg) => {
        errorText.textContent = msg;
        errorMessage.classList.remove('hidden');
    };

    const hideError = () => {
        errorMessage.classList.add('hidden');
    };

    const switchView = (targetView) => {
        viewSearch.classList.add('hidden-view');
        view2fa.classList.add('hidden-view');
        viewResults.classList.add('hidden-view');
        viewSelection.classList.add('hidden-view');

        if (targetView === 'results') {
            viewResults.classList.remove('hidden-view');
        } else if (targetView === 'selection') {
            viewSelection.classList.remove('hidden-view');
        } else if (targetView === '2fa') {
            view2fa.classList.remove('hidden-view');
            codigoInput.focus();
        } else if (targetView === 'search') {
            cpfInput.value = '';
            nascimentoInput.value = '';
            codigoInput.value = '';
            if (typeof grecaptcha !== 'undefined') grecaptcha.reset();
            if (typeof hcaptcha !== 'undefined') hcaptcha.reset();
            viewSearch.classList.remove('hidden-view');
            cpfInput.focus();
        }
    };

    const showError2fa = (msg) => {
        errorText2fa.textContent = msg;
        errorMsg2fa.classList.remove('hidden');
    };
    const hideError2fa = () => {
        errorMsg2fa.classList.add('hidden');
        errorText2fa.textContent = '';
    };

    let resendInterval;
    const startResendTimer = (minutes) => {
        clearInterval(resendInterval);
        let time = minutes * 60;
        btnResend.disabled = true;
        resendTimer.classList.remove('hidden');

        resendInterval = setInterval(() => {
            let m = Math.floor(time / 60);
            let s = time % 60;
            resendTimer.textContent = `(${m}:${s < 10 ? '0' : ''}${s})`;
            time--;

            if (time < 0) {
                clearInterval(resendInterval);
                btnResend.disabled = false;
                resendTimer.classList.add('hidden');
                resendText.textContent = 'Reenviar código';
            }
        }, 1000);
    };

    const checkBlock = () => {
        const blockedUntil = localStorage.getItem('2fa_block_until');
        if (blockedUntil && Date.now() < parseInt(blockedUntil, 10)) {
            const remainingMinutes = Math.ceil((parseInt(blockedUntil, 10) - Date.now()) / 60000);
            showError(`Dispositivo bloqueado. Tente novamente em ${remainingMinutes} minuto(s).`);
            btnSubmit.disabled = true;
            return true;
        }
        if (blockedUntil && Date.now() >= parseInt(blockedUntil, 10)) {
             localStorage.removeItem('2fa_block_until');
             btnSubmit.disabled = false;
        }
        return false;
    };
    checkBlock();

    const getTotalScore = (notas_finais) => {
        const notas = notas_finais || {};
        for (const [key, val] of Object.entries(notas)) {
            const k = key.toLowerCase();
            if (k === 'total' || k === 'pontuação final' || k === 'nota final' || k === 'total de acertos') {
                return val;
            }
        }
        return null;
    };

    const renderSelectionCards = () => {
        selectionCardsGrid.innerHTML = '';
        allData.forEach((item, idx) => {
            const notaGeral = getTotalScore(item.notas_finais);
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'group w-full text-left bg-white rounded-2xl shadow-sm border border-slate-200 p-6 hover:border-primary hover:shadow-md transition-all hover:-translate-y-1 focus:outline-none focus:ring-2 focus:ring-primary';
            card.innerHTML = `
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-4 min-w-0">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-emerald-50 flex items-center justify-center">
                            <i class="ph-fill ph-exam text-primary text-2xl"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">${item.periodo || ''}</p>
                            <p class="font-bold text-slate-800 text-base leading-tight">${item.nome_avaliacao || 'Avaliação'}</p>
                            ${notaGeral !== null ? `<p class="text-sm text-slate-500 mt-1.5">Total: <span class="font-bold text-primary">${notaGeral}</span></p>` : ''}
                        </div>
                    </div>
                    <div class="flex-shrink-0 text-slate-300 group-hover:text-primary transition-colors mt-1">
                        <i class="ph-bold ph-arrow-right text-xl"></i>
                    </div>
                </div>
            `;
            card.addEventListener('click', () => {
                btnBackToSelection.classList.remove('hidden');
                renderDashboard(idx);
                switchView('results');
            });
            selectionCardsGrid.appendChild(card);
        });
    };

    const setupDashboard = (json) => {
        allData = json.data;
        if (allData.length === 0) return;

        renderSelectionCards();
        btnBackToSelection.classList.add('hidden');
        switchView('selection');
    };

    // --- Lógica de Renderização do Dashboard ---
    const renderDashboard = (index) => {
        const item = allData[index];
        if (!item) return;

        // Atualiza RA no topo
        displayRa.textContent = item.ra;

        // Limpa painéis
        summaryPanel.innerHTML = '';
        answersGrid.innerHTML = '';

        // 0. Estatísticas de desempenho (DI — usa gabarito + questoes_meta)
        let _statsPanel = document.getElementById('stats-panel');
        if (!_statsPanel) {
            _statsPanel = document.createElement('div');
            _statsPanel.id = 'stats-panel';
            _statsPanel.style.marginBottom = '2rem';
            if (summaryPanel && summaryPanel.parentNode) {
                summaryPanel.parentNode.insertBefore(_statsPanel, summaryPanel.nextSibling);
            }
        }
        _statsPanel.innerHTML = '';
        _statsPanel.style.display = 'none';

        const _res  = item.respostas_aluno || {};
        const _gab  = item.gabarito || {};
        const _meta = item.questoes_meta || {};
        const _temGab = Object.keys(_gab).length > 0;

        if (_temGab) {
            let _corretas = 0, _erradas = 0, _brancos = 0;
            for (const [qKey, gabResp] of Object.entries(_gab)) {
                const aResp = _res[qKey];
                if (!aResp || aResp === '' || aResp === null) _brancos++;
                else if (aResp === gabResp) _corretas++;
                else _erradas++;
            }
            const _total = Object.keys(_gab).length;
            const _pct   = _total > 0 ? (_corretas / _total * 100).toFixed(1) : '0.0';
            const _pctN  = parseFloat(_pct);
            const _acertoCor = _pctN >= 70 ? '#16a34a' : _pctN >= 50 ? '#d97706' : '#dc2626';

            // Breakdown por área
            const _areas = {};
            for (const [qKey, qMeta] of Object.entries(_meta)) {
                const areaList = (qMeta.area || '').split(';').map(s => s.trim()).filter(Boolean);
                for (const area of areaList) {
                    if (!_areas[area]) _areas[area] = { corretas: 0, total: 0 };
                    _areas[area].total++;
                    const aResp = _res[qKey], gabResp = _gab[qKey];
                    if (aResp && gabResp && aResp === gabResp) _areas[area].corretas++;
                }
            }
            const _areaArr = Object.entries(_areas)
                .map(([name, d]) => ({ name, ...d, pct: d.total > 0 ? d.corretas / d.total * 100 : 0 }))
                .sort((a, b) => b.pct - a.pct);

            const _cor = p => p >= 70 ? '#16a34a' : p >= 50 ? '#d97706' : '#dc2626';
            const _bar = (p, c) => `<div style="height:6px;background:#E2E8F0;border-radius:9px;overflow:hidden"><div style="height:100%;width:${p.toFixed(0)}%;background:${c};border-radius:9px"></div></div>`;

            let html = `
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50">
                    <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                        <i class="ph-fill ph-chart-pie-slice text-[#00b48d]"></i> Estatísticas de Desempenho
                    </h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-5">
                        <div class="col-span-2 sm:col-span-1 rounded-xl p-4 text-center border" style="background:#F8FAFC;border-color:#E2E8F0">
                            <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">% Acerto</div>
                            <div class="text-4xl font-black" style="color:${_acertoCor}">${_pct}%</div>
                            <div class="text-xs text-slate-400 mt-1">${_corretas} de ${_total} questões</div>
                        </div>
                        <div class="rounded-xl p-4 text-center border" style="background:#F0FDF4;border-color:#BBF7D0">
                            <div class="text-xs font-bold uppercase tracking-wider mb-1" style="color:#15803D">Corretas</div>
                            <div class="text-3xl font-black" style="color:#15803D">${_corretas}</div>
                        </div>
                        <div class="rounded-xl p-4 text-center border" style="background:#FEF2F2;border-color:#FECACA">
                            <div class="text-xs font-bold uppercase tracking-wider mb-1" style="color:#B91C1C">Incorretas</div>
                            <div class="text-3xl font-black" style="color:#B91C1C">${_erradas}</div>
                        </div>
                        <div class="rounded-xl p-4 text-center border" style="background:#F8FAFC;border-color:#CBD5E1">
                            <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Em branco</div>
                            <div class="text-3xl font-black text-slate-400">${_brancos}</div>
                        </div>
                    </div>
                    <div style="height:12px;border-radius:9px;overflow:hidden;display:flex;background:#E2E8F0" class="mb-6">
                        ${_corretas > 0 ? `<div style="flex:${_corretas};background:#16a34a" title="${_corretas} corretas"></div>` : ''}
                        ${_erradas  > 0 ? `<div style="flex:${_erradas};background:#dc2626" title="${_erradas} incorretas"></div>` : ''}
                        ${_brancos  > 0 ? `<div style="flex:${_brancos};background:#94a3b8" title="${_brancos} em branco"></div>` : ''}
                    </div>
            `;

            if (_areaArr.length > 0) {
                html += `<div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-4">Desempenho por Área</div><div class="space-y-3">`;
                for (const a of _areaArr) {
                    const c = _cor(a.pct);
                    html += `<div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm text-slate-700 font-medium">${a.name}</span>
                            <span class="text-sm font-black" style="color:${c}">${a.pct.toFixed(1)}% <span class="text-xs font-normal text-slate-400">(${a.corretas}/${a.total})</span></span>
                        </div>
                        ${_bar(a.pct, c)}
                    </div>`;
                }
                html += `</div>`;
            }

            html += `</div></div>`;
            _statsPanel.innerHTML = html;
            _statsPanel.style.display = 'block';
        }

        // 1. Renderizar Notas Finais (Resumo)
        const notas = item.notas_finais || {};

        let notaGeral = '';
        let disciplinas = {};

        // Processa as notas para agrupar totais e percentuais
        for (const [key, val] of Object.entries(notas)) {
            const keyLower = key.toLowerCase();

            // Ignora se for o Total que queremos destacar
            if (keyLower === 'total' || keyLower === 'pontuação final' || keyLower === 'nota final' || keyLower === 'total de acertos') {
                notaGeral = val;
                continue;
            }

            // Tenta identificar se é uma disciplina (ex: "Clínica Médica - Total de acertos" ou "Cirurgia - Percentual")
            if (key.includes('-')) {
                const parts = key.split('-');
                const disciplinaNome = parts.slice(0, -1).join('-').trim();
                const tipoNota = parts[parts.length - 1].toLowerCase().trim();

                if (!disciplinas[disciplinaNome]) {
                    disciplinas[disciplinaNome] = { total: '-', percentual: '-' };
                }

                if (tipoNota.includes('percentual') || tipoNota.includes('%')) {
                    disciplinas[disciplinaNome].percentual = val;
                } else if (tipoNota.includes('total') || tipoNota.includes('acerto') || tipoNota.includes('nota')) {
                    disciplinas[disciplinaNome].total = val;
                }
            } else {
                // Se não tem hífen e não é "Total" geral, adiciona direto
                disciplinas[key.trim()] = { total: val, percentual: '' };
            }
        }

        // Renderizar Nota Geral no Topo (Card Expandido)
        if (notaGeral !== '') {
            const cardGeral = document.createElement('div');
            cardGeral.className = 'col-span-full bg-slate-800 rounded-2xl p-6 md:p-8 shadow-md flex flex-col sm:flex-row items-center justify-between border-l-8 border-primary transition-transform hover:-translate-y-1';
            cardGeral.innerHTML = `
                <div class="flex items-center mb-4 sm:mb-0">
                    <div class="w-16 h-16 rounded-full bg-[#00b48d]/20 flex items-center justify-center mr-6">
                        <i class="ph-fill ph-trophy text-primary text-4xl"></i>
                    </div>
                    <div>
                        <span class="text-sm font-bold text-slate-400 uppercase tracking-widest block mb-1">Total de Acertos</span>
                        <span class="text-5xl font-black text-white">${notaGeral}</span>
                    </div>
                </div>
            `;
            summaryPanel.appendChild(cardGeral);
        }

        // Renderizar Disciplinas (Cards)
        for (const [disciplina, dados] of Object.entries(disciplinas)) {
            const displayTotal = (dados.total === '' || dados.total === null || dados.total === '-') ? '-' : dados.total;
            let displayPercent = (dados.percentual === '' || dados.percentual === null || dados.percentual === '-') ? '' : dados.percentual;

            // Adiciona o símbolo de porcentagem se for um número e não tiver o símbolo
            if (displayPercent !== '' && !displayPercent.toString().includes('%')) {
                displayPercent += '%';
            }

            let percentHtml = displayPercent ? `<span class="text-lg text-slate-400 ml-1 font-medium">(${displayPercent})</span>` : '';

            const card = document.createElement('div');
            card.className = 'bg-white p-5 rounded-2xl shadow-sm border border-slate-100 flex flex-col justify-between transition-transform hover:-translate-y-1 hover:border-primary/40 hover:shadow-md';
            card.innerHTML = `
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-4 leading-snug break-words" title="${disciplina}">${disciplina}</span>
                <div class="flex items-baseline mt-auto">
                    <span class="text-3xl font-black text-[#00b48d]">${displayTotal}</span>
                    ${percentHtml}
                </div>
            `;
            summaryPanel.appendChild(card);
        }

        // 2. Renderizar Respostas (Q1-Q100) com Comparação de Gabarito
        const respostas = item.respostas_aluno || {};
        const gabarito = item.gabarito || {};
        const temGabarito = Object.keys(gabarito).length > 0;

        // Vamos garantir a ordem de Q1 a Q100 (ou as que existirem)
        const totalQuestoes = 100;
        let hasAnswers = false;

        for (let i = 1; i <= totalQuestoes; i++) {
            const qKey = `Q${i}`;
            if (respostas.hasOwnProperty(qKey)) {
                hasAnswers = true;
                const rValue = respostas[qKey];

                // Se a resposta estiver vazia, exibimos um traço ou N/A
                const displayResp = (rValue === '' || rValue === null) ? '-' : rValue;

                let corFundo = 'bg-slate-400 text-white'; // Default cinza

                if (temGabarito && gabarito.hasOwnProperty(qKey)) {
                    const correta = gabarito[qKey];
                    if (correta === '') {
                        corFundo = 'bg-slate-400 text-white';
                    } else if (displayResp === correta) {
                        corFundo = 'bg-green-500 text-white'; // Acertou
                    } else {
                        corFundo = 'bg-red-500 text-white'; // Errou
                    }
                }

                // Badge Modificada com Alternativa
                const badge = document.createElement('div');
                badge.className = 'flex flex-col border border-slate-200 rounded-md shadow-sm overflow-hidden w-full transition-transform hover:-translate-y-1';

                let qLabel = temGabarito && gabarito[qKey] ? `${qKey} (${gabarito[qKey]})` : qKey;

                badge.innerHTML = `
                    <div class="${corFundo} text-[10px] text-center font-bold uppercase py-1 border-b border-white/20">
                        ${qLabel}
                    </div>
                    <div class="bg-white text-center py-2 font-bold text-lg text-slate-800 ${displayResp === '-' ? 'text-slate-300' : ''}">
                        ${displayResp}
                    </div>
                `;
                answersGrid.appendChild(badge);
            }
        }

        if (!hasAnswers) {
             answersGrid.innerHTML = '<div class="col-span-full p-8 text-center text-slate-400 flex flex-col items-center gap-2"><i class="ph-fill ph-ghost text-4xl"></i><p>Nenhuma resposta registrada para este período.</p></div>';
        }

        // 3. Renderizar Botão do Gabarito Comentado (se existir)
        const containerBotoes = document.getElementById('container-botoes-extras');
        if (containerBotoes) {
            containerBotoes.innerHTML = ''; // Limpa botões antigos
            if (item.link_comentado && item.link_comentado.trim() !== '') {
                containerBotoes.innerHTML = `
                    <a href="${item.link_comentado}" target="_blank" class="w-full md:w-auto inline-flex items-center justify-center px-6 py-3 bg-[#00b48d] hover:bg-emerald-600 text-white font-bold rounded-lg shadow-sm transition-all hover:shadow-md hover:-translate-y-0.5">
                        <i class="ph-bold ph-link text-xl mr-2"></i>
                        Acessar Gabarito Comentado
                    </a>
                `;
            }
        }
    };

    // Evento de Submissão do Formulário de Busca
    searchForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const cpf = cpfInput.value.replace(/\D/g, ''); // Remove pontuação para enviar limpo
        const data_nascimento = nascimentoInput.value.trim();

        if(checkBlock()) return;

        // Verifica se os CAPTCHAs existem e se foram marcados
        let recaptchaResponse = '';
        if (typeof grecaptcha !== 'undefined') {
            recaptchaResponse = grecaptcha.getResponse();
            // Se o widget estiver renderizado mas não marcado
            if (document.querySelector('.g-recaptcha') && !recaptchaResponse) {
                showError('Por favor, confirme que você não é um robô.');
                return;
            }
        }

        let hcaptchaResponse = '';
        if (typeof hcaptcha !== 'undefined') {
            hcaptchaResponse = hcaptcha.getResponse();
            if (document.querySelector('.h-captcha') && !hcaptchaResponse) {
                showError('Por favor, confirme que você não é um robô.');
                return;
            }
        }

        if (cpf.length !== 11) {
            showError('Por favor, digite um CPF válido.');
            return;
        }

        if (data_nascimento.length !== 10) {
            showError('Por favor, digite uma Data de Nascimento válida (DD/MM/AAAA).');
            return;
        }

        hideError();

        // Estado de Loading
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i class="ph-bold ph-spinner animate-spin text-xl"></i> <span class="ml-2">Buscando...</span>';

        try {
            // Chamada à API (Fetch)
            const formData = new FormData();
            formData.append('cpf', cpf);
            formData.append('data_nascimento', data_nascimento);
            if (recaptchaResponse) {
                formData.append('g-recaptcha-response', recaptchaResponse);
            }
            if (hcaptchaResponse) {
                formData.append('h-captcha-response', hcaptchaResponse);
            }

            const response = await fetch('api/consulta.php', {
                method: 'POST',
                body: formData
            });
            const json = await response.json();

            if (response.ok) {
                if (json.status === 'success') {
                    sessionStorage.setItem('sess_cpf', cpf);
                    sessionStorage.setItem('sess_nasc', data_nascimento);
                    setupDashboard(json);
                } else if (json.status === 'require_2fa') {
                    document.getElementById('temp_cpf').value = json.cpf;
                    document.getElementById('temp_data_nascimento').value = json.data_nascimento;
                    displayEmail.textContent = json.email_hint;
                    hideError2fa();
                    codigoInput.value = '';
                    switchView('2fa');
                    startResendTimer(1);
                } else {
                    showError(json.message || 'Dados não encontrados no formato esperado.');
                }
            } else {
                // Erro (ex: não encontrou, recaptcha inválido)
                showError(json.message || 'Aluno ou resultados não encontrados.');
                if (typeof grecaptcha !== 'undefined') grecaptcha.reset();
                if (typeof hcaptcha !== 'undefined') hcaptcha.reset();
            }
        } catch (err) {
            showError('Erro de conexão com o servidor. Tente novamente mais tarde.');
            console.error('Fetch error:', err);
            if (typeof grecaptcha !== 'undefined') grecaptcha.reset();
            if (typeof hcaptcha !== 'undefined') hcaptcha.reset();
        } finally {
            if (!viewSearch.classList.contains('hidden-view')) {
                // Restaura botão se ainda estiver na tela de busca
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = '<span>Consultar Resultado</span><i class="ph-bold ph-arrow-right ml-2 text-lg"></i>';
            }
        }
    });

    // Botão Voltar (Nova consulta) — sempre vai para a busca
    btnBack.addEventListener('click', () => {
        allData = [];
        sessionStorage.removeItem('sess_cpf');
        sessionStorage.removeItem('sess_nasc');
        switchView('search');
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<span>Consultar Resultado</span><i class="ph-bold ph-arrow-right ml-2 text-lg"></i>';
    });

    // Botão Outras avaliações — volta para a tela de seleção
    btnBackToSelection.addEventListener('click', () => {
        switchView('selection');
    });

    // Botão Nova consulta da tela de seleção
    btnBackSelection.addEventListener('click', () => {
        allData = [];
        sessionStorage.removeItem('sess_cpf');
        sessionStorage.removeItem('sess_nasc');
        switchView('search');
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<span>Consultar Resultado</span><i class="ph-bold ph-arrow-right ml-2 text-lg"></i>';
    });


    form2fa.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideError2fa();

        const cpf = document.getElementById('temp_cpf').value;
        const codigo = codigoInput.value.trim().toUpperCase();

        if(codigo.length !== 6) {
            showError2fa("O código deve ter 6 dígitos.");
            return;
        }

        btnSubmit2fa.disabled = true;
        btnSubmit2fa.innerHTML = '<i class="ph-bold ph-spinner animate-spin text-xl"></i>';

        try {
            const fd = new FormData();
            fd.append('cpf', cpf);
            fd.append('codigo', codigo);

            const response = await fetch('api/verify_2fa.php', { method: 'POST', body: fd });
            const json = await response.json();

            if(response.ok && json.status === 'success') {
                sessionStorage.setItem('sess_cpf', cpf);
                sessionStorage.setItem('sess_nasc', document.getElementById('temp_data_nascimento').value);
                setupDashboard(json);
            } else {
                if (json.status === 'blocked') {
                    localStorage.setItem('2fa_block_until', Date.now() + 3600000);
                    switchView('search');
                    checkBlock();
                } else {
                    showError2fa(json.message || 'Código inválido.');
                }
            }
        } catch(e) {
            showError2fa('Erro ao validar código. Tente novamente.');
        } finally {
            btnSubmit2fa.disabled = false;
            btnSubmit2fa.innerHTML = '<span>Confirmar Código</span><i class="ph-bold ph-check ml-2 text-lg"></i>';
        }
    });

    btnCancel2fa.addEventListener('click', () => {
        switchView('search');
    });

    // Auto-restauração de sessão após F5 (só funciona sem CAPTCHA ativo)
    const savedCpf = sessionStorage.getItem('sess_cpf');
    const savedNasc = sessionStorage.getItem('sess_nasc');
    const captchaActive = document.querySelector('.g-recaptcha') !== null || document.querySelector('.h-captcha') !== null;

    if (savedCpf && savedNasc && !captchaActive) {
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i class="ph-bold ph-spinner animate-spin text-xl"></i> <span class="ml-2">Restaurando...</span>';

        const fd = new FormData();
        fd.append('cpf', savedCpf);
        fd.append('data_nascimento', savedNasc);

        fetch('api/consulta.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(json => {
                if (json.status === 'success') {
                    setupDashboard(json);
                } else {
                    sessionStorage.removeItem('sess_cpf');
                    sessionStorage.removeItem('sess_nasc');
                }
            })
            .catch(() => {
                sessionStorage.removeItem('sess_cpf');
                sessionStorage.removeItem('sess_nasc');
            })
            .finally(() => {
                if (!viewSearch.classList.contains('hidden-view')) {
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = '<span>Consultar Resultado</span><i class="ph-bold ph-arrow-right ml-2 text-lg"></i>';
                }
            });
    }

    btnResend.addEventListener('click', async () => {
        const cpf = document.getElementById('temp_cpf').value;
        try {
            const fd = new FormData();
            fd.append('cpf', cpf);
            const response = await fetch('api/resend_2fa.php', { method: 'POST', body: fd });
            const json = await response.json();

            if(response.ok) {
                showError2fa('Código reenviado com sucesso.');
                errorMsg2fa.classList.replace('bg-red-50', 'bg-emerald-50');
                errorMsg2fa.classList.replace('border-red-100', 'border-emerald-100');
                errorText2fa.classList.replace('text-red-700', 'text-emerald-700');
                setTimeout(() => {
                    errorMsg2fa.classList.replace('bg-emerald-50', 'bg-red-50');
                    errorMsg2fa.classList.replace('border-emerald-100', 'border-red-100');
                    errorText2fa.classList.replace('text-emerald-700', 'text-red-700');
                    hideError2fa();
                }, 3000);
                const waitTime = json.espera_minutos || 1;
                startResendTimer(waitTime);
            } else {
                showError2fa(json.message || 'Erro ao reenviar.');
            }
        } catch(e) {}
    });
});