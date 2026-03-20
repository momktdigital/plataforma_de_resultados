/**
 * Lógica da Interface do Aluno - Resultados DI
 */

document.addEventListener('DOMContentLoaded', () => {

    const viewSearch = document.getElementById('view-search');
    const viewResults = document.getElementById('view-results');
    const searchForm = document.getElementById('search-form');
    const raInput = document.getElementById('ra_input');
    const btnSubmit = document.getElementById('btn-submit');
    const errorMessage = document.getElementById('error-message');
    const errorText = document.getElementById('error-text');
    const btnBack = document.getElementById('btn-back');
    const displayRa = document.getElementById('display-ra');
    const periodSelectorContainer = document.getElementById('period-selector-container');
    const periodSelect = document.getElementById('period_select');
    const summaryPanel = document.getElementById('summary-panel');
    const answersGrid = document.getElementById('answers-grid');

    let allData = []; // Armazena todos os períodos do RA consultado

    // Função para exibir erro na tela de busca
    const showError = (msg) => {
        errorText.textContent = msg;
        errorMessage.classList.remove('hidden');
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<span>Consultar Resultado</span><i class="ph-bold ph-arrow-right ml-2 text-lg"></i>';
    };

    // Função para esconder erro
    const hideError = () => {
        errorMessage.classList.add('hidden');
    };

    // Alternar entre visões (Busca / Resultados)
    const switchView = (toResults = true) => {
        if (toResults) {
            viewSearch.classList.add('hidden-view');
            viewResults.classList.remove('hidden-view');
            viewResults.classList.add('fade-in');
        } else {
            viewResults.classList.add('hidden-view');
            viewSearch.classList.remove('hidden-view');
            viewSearch.classList.add('fade-in');
            raInput.value = '';
            hideError();
        }
    };

    // Renderizar Dashboard de um período específico
    const renderDashboard = (index) => {
        const item = allData[index];
        if (!item) return;

        // Atualiza RA no topo
        displayRa.textContent = item.ra || raInput.value;

        // Limpa painéis anteriores
        summaryPanel.innerHTML = '';
        answersGrid.innerHTML = '';

        // 1. Renderizar Notas Finais (Summary com Agrupamento por Disciplina)
        const notas = item.notas_finais || {};

        if (Object.keys(notas).length === 0) {
             summaryPanel.innerHTML = '<div class="col-span-full p-4 bg-slate-50 rounded text-slate-500 text-center text-sm border border-slate-200">Nenhum resumo de notas disponível.</div>';
        } else {
            let disciplinas = {};
            let notaGeral = '';

            for (const [key, val] of Object.entries(notas)) {
                const upperKey = key.toUpperCase().trim();

                // Verifica se a chave original é o "Total" geral
                if (upperKey === 'TOTAL' || upperKey === 'NOTA FINAL' || upperKey === 'PONTUAÇÃO FINAL') {
                    notaGeral = val;
                    continue;
                }

                // Separa Disciplina do Tipo usando hífen (-)
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
                        <div class="w-16 h-16 rounded-full bg-primary/20 flex items-center justify-center mr-6">
                            <i class="ph-fill ph-trophy text-primary text-4xl"></i>
                        </div>
                        <div>
                            <span class="text-sm font-bold text-slate-400 uppercase tracking-widest block mb-1">Pontuação Final do Aluno</span>
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
        }
        // 2. Renderizar Respostas (Q1-Q100)
        const respostas = item.respostas || {};

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

                // Badge
                const badge = document.createElement('div');
                badge.className = 'flex flex-col rounded-xl overflow-hidden border border-slate-200 shadow-sm bg-white hover:border-primary hover:shadow-md transition-all group';

                badge.innerHTML = `
                    <div class="bg-slate-50 text-slate-500 text-[10px] font-bold uppercase text-center py-1 border-b border-slate-200 group-hover:bg-emerald-50 group-hover:text-primary transition-colors">
                        ${qKey}
                    </div>
                    <div class="text-center py-3 font-bold text-lg text-slate-800 ${displayResp === '-' ? 'text-slate-300' : ''}">
                        ${displayResp}
                    </div>
                `;
                answersGrid.appendChild(badge);
            }
        }

        if (!hasAnswers) {
             answersGrid.innerHTML = '<div class="col-span-full p-8 text-center text-slate-400 flex flex-col items-center gap-2"><i class="ph-fill ph-ghost text-4xl"></i><p>Nenhuma resposta registrada para este período.</p></div>';
        }
    };

    // Evento de Submissão do Formulário de Busca
    searchForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const ra = raInput.value.trim();
        if (!ra) {
            showError('Por favor, digite o seu RA.');
            return;
        }

        hideError();

        // Estado de Loading
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i class="ph-bold ph-spinner animate-spin text-xl"></i> <span class="ml-2">Buscando...</span>';

        try {
            // Chamada à API (Fetch)
            const response = await fetch(`api/consulta.php?ra=${encodeURIComponent(ra)}`);
            const json = await response.json();

            if (response.ok && json.status === 'success') {
                allData = json.data; // Array de resultados (ordenados do mais recente ao mais antigo)

                // Preenche o seletor de períodos se houver mais de um
                periodSelect.innerHTML = '';
                if (allData.length > 0) {
                    allData.forEach((item, idx) => {
                        const option = document.createElement('option');
                        option.value = idx;
                        option.textContent = item.periodo;
                        periodSelect.appendChild(option);
                    });

                    // Exibe o dropdown se houver múltiplos, esconde se for apenas um
                    if (allData.length > 1) {
                        periodSelectorContainer.classList.remove('hidden');
                        periodSelectorContainer.classList.add('block');
                    } else {
                        periodSelectorContainer.classList.add('hidden');
                        periodSelectorContainer.classList.remove('block');
                    }

                    // Renderiza o primeiro item (o mais recente pela ordenação da API)
                    renderDashboard(0);
                    switchView(true);
                } else {
                     showError('Dados não encontrados no formato esperado.');
                }
            } else {
                // Erro 404 ou 400
                showError(json.message || 'RA não encontrado na base de dados.');
            }
        } catch (err) {
            showError('Erro de conexão com o servidor. Tente novamente mais tarde.');
            console.error('Fetch error:', err);
        } finally {
            if (!viewSearch.classList.contains('hidden-view')) {
                // Restaura botão se ainda estiver na tela de busca
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = '<span>Consultar Resultado</span><i class="ph-bold ph-arrow-right ml-2 text-lg"></i>';
            }
        }
    });

    // Evento para alternar entre períodos no select
    periodSelect.addEventListener('change', (e) => {
        const selectedIndex = parseInt(e.target.value, 10);
        renderDashboard(selectedIndex);
    });

    // Botão Voltar
    btnBack.addEventListener('click', () => {
        switchView(false);
    });

});
