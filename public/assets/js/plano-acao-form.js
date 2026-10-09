/**
 * Formulário do plano de ação do coordenador (resources/views/plano/form.blade.php): o roteiro em cinco etapas num só
 * <form>. Sem JavaScript todas as etapas aparecem empilhadas e o envio funciona igual; com ele, uma etapa por vez, a
 * pontuação das causas, as ações dinâmicas, "Inserir sugestão do painel" e a síntese ao vivo.
 *
 * Nenhuma etapa é obrigatória. A conferência de "o que está em branco" daqui (conferirFaltas) só INFORMA, como
 * App\Support\PlanoAcaoChecagem::lacunas no servidor — manter as duas em sintonia.
 */
(function () {
    'use strict';

    var form = document.getElementById('form-plano');
    if (!form) return;

    var TOTAL = 5;
    var secoes = Array.prototype.slice.call(form.querySelectorAll('[data-etapa]'));
    var campoEtapa = document.getElementById('etapa-atual');
    var botaoAnterior = document.getElementById('etapa-anterior');
    var botaoProxima = document.getElementById('etapa-proxima');
    var etapa = parseInt(form.getAttribute('data-etapa-inicial'), 10) || 1;
    var sujo = false;

    function q(sel, raiz) { return (raiz || document).querySelector(sel); }
    function qa(sel, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(sel)); }
    function valor(nome) {
        var el = form.elements[nome];
        return el ? String(el.value || '').trim() : '';
    }

    // -------------------------------------------------------------------------------------------------------------
    // Etapas
    // -------------------------------------------------------------------------------------------------------------

    function irPara(numero, rolar) {
        etapa = Math.max(1, Math.min(TOTAL, numero));
        campoEtapa.value = String(etapa);

        secoes.forEach(function (secao) {
            secao.hidden = parseInt(secao.getAttribute('data-etapa'), 10) !== etapa;
        });
        qa('.etapa-botao').forEach(function (botao) {
            var ativa = parseInt(botao.getAttribute('data-ir-etapa'), 10) === etapa;
            if (ativa) botao.setAttribute('aria-current', 'step'); else botao.removeAttribute('aria-current');
            botao.classList.toggle('bg-slate-800', ativa);
            botao.classList.toggle('border-slate-800', ativa);
            botao.classList.toggle('text-white', ativa);
            botao.classList.toggle('bg-white', !ativa);
            botao.classList.toggle('border-slate-300', !ativa);
            botao.classList.toggle('text-slate-700', !ativa);
        });
        qa('[data-ajuda-etapa]').forEach(function (bloco) {
            bloco.classList.toggle('hidden', parseInt(bloco.getAttribute('data-ajuda-etapa'), 10) !== etapa);
        });

        botaoAnterior.disabled = etapa === 1;
        botaoProxima.classList.toggle('hidden', etapa === TOTAL);
        if (etapa === TOTAL) montarSintese();

        if (rolar) {
            var titulo = q('#titulo-etapa-' + etapa);
            if (titulo) {
                titulo.setAttribute('tabindex', '-1');
                titulo.focus({ preventScroll: true });
                titulo.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        }
    }

    form.classList.add('com-js');
    // Os botões "Ir para a etapa N" da lista de pendências são criados depois; delegação de evento.
    document.addEventListener('click', function (e) {
        var alvo = e.target.closest ? e.target.closest('[data-ir-etapa]') : null;
        if (alvo && form.contains(alvo)) irPara(parseInt(alvo.getAttribute('data-ir-etapa'), 10), true);
    });
    botaoAnterior.addEventListener('click', function () { irPara(etapa - 1, true); });
    botaoProxima.addEventListener('click', function () { irPara(etapa + 1, true); });

    // -------------------------------------------------------------------------------------------------------------
    // Pontuação das causas
    // -------------------------------------------------------------------------------------------------------------

    function pontuacao() {
        var notas = ['nota_impacto', 'nota_evidencia', 'nota_governabilidade'].map(function (n) { return parseInt(valor(n), 10); });
        var ok = notas.every(function (n) { return n >= 1 && n <= 3; });

        return ok ? notas[0] * notas[1] * notas[2] : null;
    }
    function atualizarPontuacao() {
        var p = pontuacao();
        var el = q('[data-pontuacao]');
        if (el) el.textContent = p === null ? 'não calculada' : p + ' / 27';
    }
    qa('[data-nota]', form).forEach(function (sel) { sel.addEventListener('change', atualizarPontuacao); });
    atualizarPontuacao();

    // -------------------------------------------------------------------------------------------------------------
    // Ações
    // -------------------------------------------------------------------------------------------------------------

    var lista = document.getElementById('lista-acoes');
    var modelo = document.getElementById('modelo-acao');
    var proximoIndice = qa('[data-acao]', lista).length;

    function renumerarAcoes() {
        var linhas = qa('[data-acao]', lista);
        linhas.forEach(function (linha, i) {
            var titulo = q('[data-titulo-acao]', linha);
            if (titulo) titulo.textContent = 'Ação ' + (i + 1);
            var remover = q('[data-remover-acao]', linha);
            if (remover) remover.classList.toggle('hidden', linhas.length === 1);
        });
    }

    function adicionarAcao() {
        var html = modelo.innerHTML.replace(/__I__/g, String(proximoIndice++));
        var caixa = document.createElement('div');
        caixa.innerHTML = html;
        var nova = caixa.firstElementChild;
        lista.appendChild(nova);
        renumerarAcoes();
        var primeiro = q('textarea', nova);
        if (primeiro) primeiro.focus();
        sujo = true;

        return nova;
    }

    document.getElementById('adicionar-acao').addEventListener('click', adicionarAcao);

    // Banco de ações: "Usar esta ação" preenche uma ação (a única linha em branco, se for o caso; senão uma nova).
    qa('[data-usar-ideia]', form).forEach(function (botao) {
        botao.addEventListener('click', function () {
            var ideia = botao.closest('[data-ideia-descricao]');
            var linhas = qa('[data-acao]', lista);
            var vazia = linhas.length === 1 && qa('textarea, input[type="text"]', linhas[0]).every(function (el) { return !String(el.value).trim(); });
            var alvo = vazia ? linhas[0] : adicionarAcao();
            function de(sufixo, valor) { var el = q('[name$="[' + sufixo + ']"]', alvo); if (el) el.value = valor; }
            de('descricao', ideia.getAttribute('data-ideia-descricao'));
            de('execucao', ideia.getAttribute('data-ideia-execucao'));
            de('verificacao', ideia.getAttribute('data-ideia-verificacao'));
            sujo = true;
            var primeiro = q('textarea', alvo);
            if (primeiro) primeiro.focus();
        });
    });

    lista.addEventListener('click', function (e) {
        var botao = e.target.closest ? e.target.closest('[data-remover-acao]') : null;
        if (!botao) return;
        var linha = botao.closest('[data-acao]');
        if (qa('[data-acao]', lista).length <= 1) return;
        if (!window.confirm('Remover esta ação do plano?')) return;
        linha.parentNode.removeChild(linha);
        renumerarAcoes();
        sujo = true;
    });
    renumerarAcoes();

    // -------------------------------------------------------------------------------------------------------------
    // Sugestões do painel
    // -------------------------------------------------------------------------------------------------------------

    var sugestoes = {};
    try { sugestoes = JSON.parse(q('#plano-sugestoes').textContent) || {}; } catch (e) { sugestoes = {}; }

    qa('[data-sugestao]', form).forEach(function (botao) {
        botao.addEventListener('click', function () {
            var nome = botao.getAttribute('data-sugestao');
            var campo = form.elements[nome];
            var texto = String(sugestoes[nome] || '').trim();
            if (!campo) return;
            if (!texto) {
                window.alert('O painel não tem uma sugestão para este campo neste recorte. Escreva com as suas palavras.');
                return;
            }
            var atual = String(campo.value || '').trim();
            if (atual.indexOf(texto) !== -1) return;
            campo.value = atual === '' ? texto : atual + '\n\n' + texto;
            campo.focus();
            sujo = true;
        });
    });

    // -------------------------------------------------------------------------------------------------------------
    // Síntese e conferência
    // -------------------------------------------------------------------------------------------------------------

    function acoesDoFormulario() {
        return qa('[data-acao]', lista).map(function (linha) {
            function de(sufixo) { var el = q('[name$="[' + sufixo + ']"]', linha); return el ? String(el.value || '').trim() : ''; }

            return { descricao: de('descricao'), execucao: de('execucao'), responsavel: de('responsavel'), prazo: de('prazo'), verificacao: de('verificacao') };
        }).filter(function (a) { return a.descricao || a.execucao || a.responsavel || a.verificacao; });
    }

    function comecaComVerbo(texto) {
        var m = /^[^A-Za-zÀ-ÿ]*([A-Za-zÀ-ÿ]+)/.exec(texto);

        return !!m && m[1].length >= 3 && /(ar|er|ir|or|ôr)$/i.test(m[1]);
    }

    /** @return {Array<{etapa: number, mensagem: string}>} */
    function conferirFaltas() {
        var faltas = [];
        function falta(n, m) { faltas.push({ etapa: n, mensagem: m }); }
        function curto(t) { return String(t || '').trim().length < 5; }
        var hoje = new Date(); hoje.setHours(0, 0, 0, 0);

        if (valor('meta_proficiencia') === '') falta(1, 'Defina a meta de proficiência para a próxima avaliação.');
        if (curto(valor('recorte'))) falta(2, 'Diga qual recorte merece atenção (período, grupo, faixa de desempenho).');
        if (curto(valor('resultado'))) falta(2, 'Descreva o resultado que precisa ser enfrentado.');
        if (curto(valor('fragilidades'))) falta(2, 'Registre as competências, objetivos de aprendizagem ou níveis cognitivos com fragilidade.');
        if (curto(valor('evidencias'))) falta(2, 'Registre os dados que sustentam a escolha.');

        var temCausa = qa('[name^="causas["]', form).some(function (el) { return !curto(el.value); });
        if (!temCausa) falta(3, 'Levante ao menos uma possível causa (Ishikawa).');
        if (curto(valor('causa_priorizada'))) falta(3, 'Indique a causa a priorizar.');
        if (pontuacao() === null) falta(3, 'Dê as três notas da priorização (impacto, evidência e governabilidade).');
        if (curto(valor('causa_raiz'))) falta(3, 'Escreva a causa-raiz acionável.');

        var acoes = acoesDoFormulario();
        if (acoes.length === 0) falta(4, 'Inclua ao menos uma ação pedagógica.');
        acoes.forEach(function (a, i) {
            var n = i + 1;
            if (curto(a.descricao) || !comecaComVerbo(a.descricao)) falta(4, 'Ação ' + n + ': inicie com um verbo no infinitivo (ex.: implementar, revisar, aplicar).');
            if (curto(a.execucao)) falta(4, 'Ação ' + n + ': explique como ela será executada.');
            if (!a.responsavel) falta(4, 'Ação ' + n + ': informe o responsável.');
            if (!a.prazo) falta(4, 'Ação ' + n + ': informe o prazo.');
            else if (new Date(a.prazo + 'T00:00:00') < hoje) falta(4, 'Ação ' + n + ': o prazo já passou — informe uma data a partir de hoje.');
            if (curto(a.verificacao)) falta(4, 'Ação ' + n + ': diga como a execução e os sinais de aprendizagem serão verificados antes da próxima avaliação.');
        });

        return faltas;
    }

    function mostrarFaltas(faltas) {
        var caixa = document.getElementById('faltas');
        var ul = document.getElementById('lista-faltas');
        if (!caixa || !ul) return;
        ul.innerHTML = '';
        faltas.forEach(function (f) {
            var li = document.createElement('li');
            li.appendChild(document.createTextNode(f.mensagem + ' '));
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'underline font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded';
            b.setAttribute('data-ir-etapa', String(f.etapa));
            b.textContent = 'Ir para a etapa ' + f.etapa;
            li.appendChild(b);
            ul.appendChild(li);
        });
        caixa.classList.toggle('hidden', faltas.length === 0);
    }

    function montarSintese() {
        var alvo = document.getElementById('sintese');
        if (!alvo) return;
        var dimensoes = {};
        try { dimensoes = JSON.parse(q('#plano-dimensoes').textContent) || {}; } catch (e) { dimensoes = {}; }

        var linhas = [
            ['Meta de proficiência', valor('meta_proficiencia') ? valor('meta_proficiencia') + '%' : ''],
            ['Recorte prioritário', valor('recorte')],
            ['Resultado a enfrentar', valor('resultado')],
            ['Fragilidades', valor('fragilidades')],
            ['Dados que sustentam a escolha', valor('evidencias')]
        ];
        Object.keys(dimensoes).forEach(function (chave) {
            var el = form.elements['causas[' + chave + ']'];
            if (el && String(el.value).trim()) linhas.push(['Causa possível · ' + dimensoes[chave], String(el.value).trim()]);
        });
        var p = pontuacao();
        linhas.push(['Causa priorizada', valor('causa_priorizada') + (p !== null ? '  (pontuação ' + p + ' de 27)' : '')]);
        var porques = qa('[name="porques[]"]', form).map(function (el) { return String(el.value).trim(); }).filter(Boolean);
        if (porques.length) linhas.push(['5 Porquês', porques.map(function (t, i) { return (i + 1) + '. ' + t; }).join('\n')]);
        linhas.push(['Causa-raiz', valor('causa_raiz')]);
        acoesDoFormulario().forEach(function (a, i) {
            linhas.push(['Ação ' + (i + 1), a.descricao]);
            linhas.push(['   Como será executada', a.execucao]);
            linhas.push(['   Responsável e prazo', [a.responsavel, a.prazo ? a.prazo.split('-').reverse().join('/') : ''].filter(Boolean).join(' · ')]);
            linhas.push(['   Como será verificada', a.verificacao]);
        });

        alvo.innerHTML = '';
        linhas.forEach(function (l) {
            var linha = document.createElement('div');
            linha.className = 'grid gap-1 px-4 py-2.5 sm:grid-cols-[14rem_1fr]';
            var t = document.createElement('div');
            t.className = 'font-semibold text-slate-700';
            t.textContent = l[0].replace(/^ +/, '');
            if (/^ /.test(l[0])) t.className += ' pl-4 font-normal';
            var v = document.createElement('div');
            v.className = 'whitespace-pre-line ' + (l[1] ? 'text-slate-800' : 'text-slate-500 italic');
            v.textContent = l[1] || 'Não informado';
            linha.appendChild(t);
            linha.appendChild(v);
            alvo.appendChild(linha);
        });

        mostrarFaltas(conferirFaltas());
    }

    var enviar = q('[data-enviar]', form);
    if (enviar) {
        enviar.addEventListener('click', function (e) {
            var faltas = conferirFaltas();
            var aviso = faltas.length
                ? 'Há ' + faltas.length + ' ponto(s) em branco. Nenhuma etapa é obrigatória, mas o colaborador verá o que faltou.\n\nEnviar o plano para análise mesmo assim?'
                : 'Enviar o plano para análise do colaborador?';
            if (!window.confirm(aviso)) e.preventDefault();
        });
    }

    // -------------------------------------------------------------------------------------------------------------
    // Alterações não salvas
    // -------------------------------------------------------------------------------------------------------------

    form.addEventListener('input', function () { sujo = true; });
    form.addEventListener('submit', function () { sujo = false; });
    window.addEventListener('beforeunload', function (e) {
        if (!sujo) return undefined;
        e.preventDefault();
        e.returnValue = '';

        return '';
    });

    irPara(etapa, false);
})();
