{{--
    Base comum das telas do painel da reitoria: carrega o Chart.js, os padrões visuais dos gráficos (_viz) e os
    ajudantes do painel (window.ReitorViz) — linha de referência, rótulos de valor, formatação pt-BR — mais os
    comportamentos compartilhados: abrir/fechar "Sobre este quadro" e "Ver leitura", tabelas ordenáveis com filtro e
    o seletor de cursos da barra de filtros. Inclua UMA vez por tela, no fim do conteúdo, antes dos scripts dos gráficos.
--}}
{{-- O atributo hidden perde para classes de display do Tailwind (flex, block...): sem esta regra, seletor com setas
     "escondidas" e filhas "recolhidas" continuariam aparecendo. --}}
<style>[hidden] { display: none !important; }</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
@include('_viz')
<script>
window.ReitorViz = (function () {
    'use strict';

    var cores = {
        marinho: '#1e3a5f',
        verde: '#00a67e',
        verdeEscuro: '#0b6b53',
        ambar: '#e8a317',
        vermelho: '#c1121f',
        cinza: '#aab2bd',
        tinta: '#0f1720',
        grade: '#e3e9e7',
    };

    function fmt(valor, casas) {
        if (valor === null || valor === undefined || isNaN(valor)) return '—';
        return Number(valor).toLocaleString('pt-BR', { minimumFractionDigits: casas === undefined ? 1 : casas, maximumFractionDigits: casas === undefined ? 1 : casas });
    }
    /** Escapa texto para montar HTML por JavaScript (nomes de curso vêm de planilha digitada à mão). */
    function esc(texto) {
        return String(texto === null || texto === undefined ? '' : texto).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function pct(valor, casas) { return valor === null || valor === undefined ? '—' : fmt(valor, casas) + '%'; }
    function pp(valor) {
        if (valor === null || valor === undefined) return '—';
        return (valor > 0 ? '+' : (valor < 0 ? '−' : '')) + fmt(Math.abs(valor)) + ' pp';
    }

    /** Linhas de referência horizontais atravessando o gráfico inteiro: options.plugins.linhaReferencia.linhas. */
    var linhaReferencia = {
        id: 'linhaReferencia',
        afterDatasetsDraw: function (chart, args, opcoes) {
            var linhas = (opcoes && opcoes.linhas) || [];
            var escala = chart.scales[(opcoes && opcoes.eixo) || 'y'];
            if (!escala) return;
            var ctx = chart.ctx, area = chart.chartArea;
            linhas.forEach(function (linha) {
                if (linha.valor === null || linha.valor === undefined) return;
                var y = escala.getPixelForValue(linha.valor);
                ctx.save();
                ctx.beginPath();
                ctx.setLineDash(linha.tracejado === false ? [] : [6, 4]);
                ctx.lineWidth = linha.largura || 1.5;
                ctx.strokeStyle = linha.cor || cores.vermelho;
                ctx.moveTo(area.left, y);
                ctx.lineTo(area.right, y);
                ctx.stroke();
                if (linha.rotulo) {
                    ctx.setLineDash([]);
                    ctx.fillStyle = linha.cor || cores.vermelho;
                    ctx.font = '600 11px ui-sans-serif, system-ui, sans-serif';
                    ctx.textAlign = 'right';
                    ctx.fillText(linha.rotulo, area.right - 4, y - 5);
                }
                ctx.restore();
            });
        },
    };

    /** Linha de referência VERTICAL (gráficos de barras horizontais / dispersão no eixo x). */
    var linhaReferenciaX = {
        id: 'linhaReferenciaX',
        afterDatasetsDraw: function (chart, args, opcoes) {
            var linhas = (opcoes && opcoes.linhas) || [];
            var escala = chart.scales[(opcoes && opcoes.eixo) || 'x'];
            if (!escala) return;
            var ctx = chart.ctx, area = chart.chartArea;
            linhas.forEach(function (linha) {
                if (linha.valor === null || linha.valor === undefined) return;
                var x = escala.getPixelForValue(linha.valor);
                ctx.save();
                ctx.beginPath();
                ctx.setLineDash([6, 4]);
                ctx.lineWidth = 1.5;
                ctx.strokeStyle = linha.cor || cores.vermelho;
                ctx.moveTo(x, area.top);
                ctx.lineTo(x, area.bottom);
                ctx.stroke();
                if (linha.rotulo) {
                    ctx.setLineDash([]);
                    ctx.fillStyle = linha.cor || cores.vermelho;
                    ctx.font = '600 11px ui-sans-serif, system-ui, sans-serif';
                    ctx.textAlign = 'left';
                    ctx.fillText(linha.rotulo, x + 4, area.top + 11);
                }
                ctx.restore();
            });
        },
    };

    /** Valor escrito na ponta de cada barra: options.plugins.rotulosValores = { dataset: 0, formato: fn, horizontal: bool }. */
    var rotulosValores = {
        id: 'rotulosValores',
        afterDatasetsDraw: function (chart, args, opcoes) {
            if (!opcoes) return;
            var indice = opcoes.dataset || 0;
            var meta = chart.getDatasetMeta(indice);
            var dados = chart.data.datasets[indice].data;
            var ctx = chart.ctx;
            ctx.save();
            ctx.font = '600 11px ui-sans-serif, system-ui, sans-serif';
            ctx.fillStyle = cores.tinta;
            meta.data.forEach(function (barra, i) {
                var valor = dados[i];
                if (valor === null || valor === undefined) return;
                var texto = opcoes.formato ? opcoes.formato(valor, i) : fmt(valor);
                if (opcoes.horizontal) {
                    ctx.textAlign = 'left';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(texto, barra.x + 6, barra.y);
                } else {
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(texto, barra.x, barra.y - 4);
                }
            });
            ctx.restore();
        },
    };

    if (window.Chart) {
        Chart.register(linhaReferencia, linhaReferenciaX, rotulosValores);
    }

    function criar(id, config) {
        var el = document.getElementById(id);
        if (!el || !window.Chart) return null;
        return new Chart(el, config);
    }

    /** Eixo percentual de 0 a 100. */
    function eixoPct(extra) {
        return Object.assign({ min: 0, max: 100, ticks: { stepSize: 10, callback: function (v) { return v + '%'; } }, grid: { color: cores.grade } }, extra || {});
    }

    /** Legenda sem marcador quadrado, clicável (liga/desliga a série) — padrão do painel. */
    function legenda(posicao) {
        return { position: posicao || 'top', labels: { usePointStyle: true, boxWidth: 8, padding: 12 } };
    }

    return { cores: cores, fmt: fmt, esc: esc, pct: pct, pp: pp, criar: criar, eixoPct: eixoPct, legenda: legenda };
})();

// ---------------------------------------------------------------------------------------------------------------
// "Sobre este quadro" / "Ver leitura": botões que mostram/escondem um painel logo abaixo do gráfico.
// ---------------------------------------------------------------------------------------------------------------
document.addEventListener('click', function (evento) {
    var botao = evento.target.closest('[data-alternar]');
    if (!botao) return;
    var painel = document.getElementById(botao.getAttribute('data-alternar'));
    if (!painel) return;
    var abrir = painel.hidden;
    painel.hidden = !abrir;
    botao.setAttribute('aria-expanded', abrir ? 'true' : 'false');
});

// ---------------------------------------------------------------------------------------------------------------
// Tabelas ordenáveis: <table data-ordenavel>; cabeçalho <th data-ordem="numero|texto"> com <button>;
// células com data-valor (o valor de ordenação); linhas em <tbody>, totais em <tfoot> (nunca entram na ordenação).
// Filtro: <input data-filtro-tabela="id-da-tabela"> esconde as linhas cujo texto não contém o digitado.
// ---------------------------------------------------------------------------------------------------------------
(function () {
    function normalizar(texto) {
        return (texto || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    document.querySelectorAll('table[data-ordenavel]').forEach(function (tabela) {
        var cabecalhos = Array.prototype.slice.call(tabela.querySelectorAll('th[data-ordem]'));
        cabecalhos.forEach(function (th) {
            var botao = th.querySelector('button');
            if (!botao) return;
            botao.addEventListener('click', function () {
                var coluna = th.cellIndex;
                var numerico = th.getAttribute('data-ordem') === 'numero';
                var sentido = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
                cabecalhos.forEach(function (outro) { outro.removeAttribute('aria-sort'); });
                th.setAttribute('aria-sort', sentido === 1 ? 'ascending' : 'descending');
                var corpo = tabela.tBodies[0];
                var linhas = Array.prototype.slice.call(corpo.rows);
                linhas.sort(function (a, b) {
                    var va = a.cells[coluna].getAttribute('data-valor');
                    var vb = b.cells[coluna].getAttribute('data-valor');
                    if (numerico) {
                        va = va === null || va === '' ? null : parseFloat(va);
                        vb = vb === null || vb === '' ? null : parseFloat(vb);
                        if (va === null && vb === null) return 0;
                        if (va === null) return 1;      // sem valor vai sempre para o fim
                        if (vb === null) return -1;
                        return (va - vb) * sentido;
                    }
                    return (va || '').localeCompare(vb || '', 'pt-BR') * sentido;
                });
                linhas.forEach(function (linha) { corpo.appendChild(linha); });
            });
        });
    });

    document.querySelectorAll('input[data-filtro-tabela]').forEach(function (campo) {
        var tabela = document.getElementById(campo.getAttribute('data-filtro-tabela'));
        if (!tabela) return;
        campo.addEventListener('input', function () {
            var termo = normalizar(campo.value);
            Array.prototype.forEach.call(tabela.tBodies[0].rows, function (linha) {
                linha.hidden = termo !== '' && normalizar(linha.getAttribute('data-nome') || linha.textContent).indexOf(termo) === -1;
            });
        });
    });
})();

// ---------------------------------------------------------------------------------------------------------------
// Seletor de cursos da barra de filtros (<details data-seletor-cursos>): marcar todos / limpar, e fecha ao clicar fora.
// ---------------------------------------------------------------------------------------------------------------
(function () {
    document.querySelectorAll('[data-seletor-cursos]').forEach(function (caixa) {
        var marcar = function (valor) {
            caixa.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = valor; });
            atualizar();
        };
        var rotulo = caixa.querySelector('[data-seletor-rotulo]');
        var atualizar = function () {
            var todos = caixa.querySelectorAll('input[type=checkbox]');
            var marcados = caixa.querySelectorAll('input[type=checkbox]:checked');
            if (rotulo) {
                rotulo.textContent = marcados.length === 0 || marcados.length === todos.length
                    ? 'Todos os cursos (' + todos.length + ')'
                    : marcados.length + ' de ' + todos.length + ' cursos';
            }
        };
        caixa.querySelectorAll('[data-marcar-todos]').forEach(function (b) { b.addEventListener('click', function () { marcar(true); }); });
        caixa.querySelectorAll('[data-limpar]').forEach(function (b) { b.addEventListener('click', function () { marcar(false); }); });
        caixa.addEventListener('change', atualizar);
        document.addEventListener('click', function (e) { if (caixa.open && !caixa.contains(e.target)) caixa.open = false; });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && caixa.open) { caixa.open = false; caixa.querySelector('summary').focus(); } });
        atualizar();
    });
})();
// ---------------------------------------------------------------------------------------------------------------
// Seletor com busca e árvore (_combo.blade.php): <div data-combo> com campo escondido, botão role=combobox e painel.
// ---------------------------------------------------------------------------------------------------------------
(function () {
    function normalizar(texto) {
        return (texto || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    document.querySelectorAll('[data-combo]').forEach(function (combo) {
        var botao = combo.querySelector('[role=combobox]');
        var painel = combo.querySelector('[data-combo-painel]');
        var busca = combo.querySelector('[data-combo-busca]');
        var lista = combo.querySelector('[data-combo-lista]');
        var vazio = combo.querySelector('[data-combo-vazio]');
        var caixaSelecionado = combo.querySelector('[data-combo-selecionado]');
        var textoSelecionado = combo.querySelector('[data-combo-selecionado-texto]');
        var tituloLista = combo.querySelector('[data-combo-titulo-lista]');
        var valor = combo.querySelector('[data-combo-valor]');
        var texto = combo.querySelector('[data-combo-texto]');
        var itens = Array.prototype.slice.call(lista.querySelectorAll('[role=option]'));

        var porId = {};
        var temFilhas = {};
        itens.forEach(function (item) {
            if (item.getAttribute('data-id')) porId[item.getAttribute('data-id')] = item;
        });
        itens.forEach(function (item) {
            var pai = item.getAttribute('data-pai');
            if (pai && porId[pai]) temFilhas[pai] = true;
        });

        var abertos = {};
        var visiveis = [];
        var ativo = -1;

        function escolhido() {
            return itens.filter(function (i) { return i.getAttribute('data-valor') === valor.value; })[0] || itens[0];
        }

        function ancestraisAbertos(item) {
            var pai = item.getAttribute('data-pai');
            while (pai && porId[pai]) {
                if (!abertos[pai]) return false;
                pai = porId[pai].getAttribute('data-pai');
            }
            return true;
        }

        function abrirCaminhoDe(item) {
            var pai = item.getAttribute('data-pai');
            while (pai && porId[pai]) {
                abertos[pai] = true;
                pai = porId[pai].getAttribute('data-pai');
            }
        }

        function marcarAtivo(indice) {
            if (visiveis[ativo]) visiveis[ativo].classList.remove('bg-slate-100');
            ativo = indice;
            if (visiveis[ativo]) {
                visiveis[ativo].classList.add('bg-slate-100');
                visiveis[ativo].scrollIntoView({ block: 'nearest' });
            }
        }

        function atualizar() {
            var termo = normalizar(busca.value.trim());
            var atual = escolhido();
            visiveis = [];

            itens.forEach(function (item) {
                var id = item.getAttribute('data-id');
                var mostrar = termo !== '' ? normalizar(item.getAttribute('data-texto')).indexOf(termo) !== -1 : ancestraisAbertos(item);
                item.hidden = !mostrar;
                item.setAttribute('aria-selected', item === atual ? 'true' : 'false');
                item.querySelector('[data-combo-caminho]').hidden = termo === '' || !item.querySelector('[data-combo-caminho]').textContent;

                var seta = item.querySelector('[data-combo-seta]');
                var recuo = item.querySelector('[data-combo-recuo]');
                var comFilhas = !!(id && temFilhas[id]) && termo === '';
                seta.hidden = !comFilhas;
                recuo.hidden = comFilhas;
                seta.setAttribute('aria-expanded', abertos[id] ? 'true' : 'false');
                seta.querySelector('i').style.transform = abertos[id] ? 'rotate(90deg)' : '';
                if (mostrar) visiveis.push(item);
            });

            // No resultado da busca a árvore é achatada: cada item mostra o caminho completo (a categoria pai).
            textoSelecionado.textContent = atual.getAttribute('data-rotulo');
            caixaSelecionado.hidden = false;
            tituloLista.textContent = termo === '' ? 'Todas as opções' : 'Resultados da busca';
            vazio.hidden = visiveis.length > 0;
            marcarAtivo(visiveis.length ? Math.max(0, visiveis.indexOf(atual)) : -1);
        }

        function abrir() {
            if (!painel.hidden) return;
            busca.value = '';
            abrirCaminhoDe(escolhido());
            painel.hidden = false;
            botao.setAttribute('aria-expanded', 'true');
            atualizar();
            busca.focus();
        }

        function fechar(devolverFoco) {
            if (painel.hidden) return;
            painel.hidden = true;
            botao.setAttribute('aria-expanded', 'false');
            if (devolverFoco) botao.focus();
        }

        function escolher(item) {
            valor.value = item.getAttribute('data-valor');
            texto.textContent = item.getAttribute('data-rotulo');
            var form = combo.closest('form');
            (combo.getAttribute('data-limpa') || '').split(',').filter(Boolean).forEach(function (nome) {
                if (form && form.elements[nome]) form.elements[nome].value = '';
            });
            fechar(false);
            if (form) form.submit();
        }

        function alternarFilhas(item) {
            var id = item.getAttribute('data-id');
            if (!id || !temFilhas[id]) return;
            abertos[id] = !abertos[id];
            atualizar();
        }

        botao.addEventListener('click', function () { painel.hidden ? abrir() : fechar(false); });
        botao.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); abrir(); }
        });

        busca.addEventListener('input', atualizar);
        busca.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (visiveis.length) marcarAtivo(Math.min(visiveis.length - 1, ativo + 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (visiveis.length) marcarAtivo(Math.max(0, ativo - 1)); }
            else if (e.key === 'Enter') { e.preventDefault(); if (visiveis[ativo]) escolher(visiveis[ativo]); }
            else if (e.key === 'Escape') { e.preventDefault(); fechar(true); }
            else if (e.key === 'Tab') { fechar(false); }
            else if (busca.value === '' && (e.key === 'ArrowRight' || e.key === 'ArrowLeft') && visiveis[ativo]) {
                var id = visiveis[ativo].getAttribute('data-id');
                if (id && temFilhas[id]) {
                    e.preventDefault();
                    abertos[id] = e.key === 'ArrowRight';
                    atualizar();
                    marcarAtivo(visiveis.indexOf(porId[id]));
                }
            }
        });

        lista.addEventListener('click', function (e) {
            var item = e.target.closest('[role=option]');
            if (!item) return;
            if (e.target.closest('[data-combo-seta]')) {
                alternarFilhas(item);
                return;
            }
            escolher(item);
        });

        document.addEventListener('click', function (e) { if (!painel.hidden && !combo.contains(e.target)) fechar(false); });

        atualizar();
        painel.hidden = true;
    });
})();

// Busca dentro do seletor de cursos (checkboxes) da barra de filtros.
(function () {
    document.querySelectorAll('[data-cursos-busca]').forEach(function (campo) {
        var itens = campo.closest('[data-seletor-cursos]').querySelectorAll('[data-curso-item]');
        campo.addEventListener('input', function () {
            var termo = (campo.value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
            itens.forEach(function (li) {
                var nome = (li.getAttribute('data-curso-item') || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
                li.hidden = termo !== '' && nome.indexOf(termo) === -1;
            });
        });
    });
})();
</script>
