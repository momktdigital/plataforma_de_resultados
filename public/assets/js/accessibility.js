/**
 * Accessibility Module — portado de assets/js/accessibility.js do app legado.
 * Handles font size scaling, themes (Light, Dark, High Contrast), and VLibras injection.
 */
document.addEventListener('DOMContentLoaded', () => {

    // --- State ---
    let currentFontSize = parseFloat(localStorage.getItem('acc_font_size')) || 100; // percentage
    let currentTheme = localStorage.getItem('acc_theme') || 'light';

    // --- Core Functions ---
    const applyFontSize = () => {
        document.documentElement.style.fontSize = `${currentFontSize}%`;
        localStorage.setItem('acc_font_size', currentFontSize);
    };

    const applyTheme = () => {
        document.documentElement.classList.remove('theme-dark', 'theme-high-contrast');
        if (currentTheme === 'dark') {
            document.documentElement.classList.add('theme-dark');
        } else if (currentTheme === 'high-contrast') {
            document.documentElement.classList.add('theme-high-contrast');
        }
        localStorage.setItem('acc_theme', currentTheme);
    };

    const injectVLibras = () => {
        if (document.querySelector('script[data-vlibras]')) return;

        // A versão atual do plugin (v7.6.0) não usa mais o container
        // `[vw]`/`[vw-access-button]` que a gente montava na mão — ela se
        // auto-inicializa (via Shadow DOM, direto no <body>) assim que o
        // script carrega, e expõe `window.VLibrasWidget.open()` pra abrir o
        // tradutor programaticamente. Só carregamos o script e deixamos ele
        // se renderizar; o botão flutuante dele é escondido via CSS
        // (#vlibras-access-wrapper) e acionado pelo botão da nossa barra.
        const script = document.createElement('script');
        script.src = 'https://vlibras.gov.br/app/vlibras-plugin.js';
        script.dataset.vlibras = 'true';
        script.async = true;
        document.body.appendChild(script);
    };

    // --- Actions ---
    const setTheme = (theme) => {
        currentTheme = theme;
        applyTheme();
    };

    const triggerVLibras = () => {
        if (window.VLibrasWidget && typeof window.VLibrasWidget.open === 'function') {
            window.VLibrasWidget.open();

            return;
        }

        // O script carrega de forma assíncrona — se o clique acontecer antes
        // dele terminar, espera até 5s por `window.VLibrasWidget.open`.
        let tentativas = 0;
        const esperar = setInterval(() => {
            tentativas++;

            if (window.VLibrasWidget && typeof window.VLibrasWidget.open === 'function') {
                clearInterval(esperar);
                window.VLibrasWidget.open();
            } else if (tentativas >= 25) {
                clearInterval(esperar);
                console.warn('VLibras ainda não carregou.');
            }
        }, 200);
    };

    const triggerSienna = () => {
        // Sienna (accessibility-widget) cria seu próprio botão flutuante com
        // a classe .asw-menu-btn, escondido via CSS — disparamos o clique
        // nele pra abrir o painel dela, igual fazemos com o VLibras acima.
        const btn = document.querySelector('.asw-menu-btn');
        if (btn) {
            btn.click();
        } else {
            console.warn('Sienna button not found yet.');
        }
    };

    // --- Initialization ---
    applyFontSize();
    applyTheme();
    injectVLibras();

    // --- UI Injection ---
    // A barra é montada em cada `.accessibility-container`. Tudo nela funciona por teclado: o menu de temas abre com
    // Enter/Espaço (não só no hover), fecha com Esc devolvendo o foco ao botão, e cada botão só de ícone tem nome
    // acessível (aria-label) — `title` sozinho não é lido de forma confiável por leitores de tela.
    const containers = document.querySelectorAll('.accessibility-container');
    const foco = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1';
    const botaoIcone = `text-slate-600 hover:text-emerald-700 transition-colors p-1 flex items-center justify-center rounded hover:bg-slate-100 ${foco}`;
    const itemTema = `btn-acc-theme block w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:text-emerald-700 transition-colors ${foco} focus-visible:ring-inset`;

    const temas = [
        { id: 'light', rotulo: 'Modo claro', icone: 'ph-sun' },
        { id: 'dark', rotulo: 'Modo escuro', icone: 'ph-moon' },
        { id: 'high-contrast', rotulo: 'Alto contraste', icone: 'ph-circle-half' },
    ];

    const marcarTemaAtual = (raiz) => {
        raiz.querySelectorAll('.btn-acc-theme').forEach(btn => {
            btn.setAttribute('aria-pressed', btn.dataset.theme === currentTheme ? 'true' : 'false');
        });
    };

    containers.forEach((container, indice) => {
        const idMenu = `acc-menu-temas-${indice}`;

        container.innerHTML = `
            <div class="flex items-center gap-1 sm:gap-2">
                <div class="relative">
                    <button type="button" class="btn-acc-temas ${botaoIcone}" aria-label="Tema da página: claro, escuro ou alto contraste"
                            aria-haspopup="true" aria-expanded="false" aria-controls="${idMenu}">
                        <i class="ph ph-palette text-xl" aria-hidden="true"></i>
                    </button>
                    <div id="${idMenu}" role="group" aria-label="Tema da página" hidden
                         class="absolute right-0 top-full mt-1 w-44 bg-white border border-slate-200 shadow-lg rounded-lg z-50">
                        ${temas.map(t => `
                        <button type="button" class="${itemTema}" data-theme="${t.id}" aria-pressed="false">
                            <i class="ph ${t.icone} mr-2" aria-hidden="true"></i> ${t.rotulo}
                        </button>`).join('')}
                    </div>
                </div>

                <button type="button" class="btn-acc-vlibras ${botaoIcone}" aria-label="Abrir o tradutor de Libras (VLibras)">
                    <i class="ph ph-hands-clapping text-xl" aria-hidden="true"></i>
                </button>

                <button type="button" class="btn-acc-sienna ${botaoIcone}" aria-label="Abrir os recursos de acessibilidade (Sienna)">
                    <i class="ph ph-wheelchair text-xl" aria-hidden="true"></i>
                </button>
            </div>
        `;

        const alternar = container.querySelector('.btn-acc-temas');
        const menu = container.querySelector(`#${idMenu}`);

        const abrir = (aberto) => {
            menu.hidden = !aberto;
            alternar.setAttribute('aria-expanded', aberto ? 'true' : 'false');
        };

        alternar.addEventListener('click', () => {
            abrir(menu.hidden);
            if (!menu.hidden) {
                const atual = menu.querySelector('[aria-pressed="true"]') || menu.querySelector('.btn-acc-theme');
                atual && atual.focus();
            }
        });

        menu.addEventListener('keydown', (e) => {
            const itens = Array.from(menu.querySelectorAll('.btn-acc-theme'));
            const i = itens.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); itens[(i + 1) % itens.length].focus(); }
            if (e.key === 'ArrowUp') { e.preventDefault(); itens[(i - 1 + itens.length) % itens.length].focus(); }
        });

        container.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !menu.hidden) {
                abrir(false);
                alternar.focus();
            }
        });

        document.addEventListener('click', (e) => {
            if (!menu.hidden && !container.contains(e.target)) abrir(false);
        });

        container.querySelector('.btn-acc-vlibras').addEventListener('click', triggerVLibras);
        container.querySelector('.btn-acc-sienna').addEventListener('click', triggerSienna);

        container.querySelectorAll('.btn-acc-theme').forEach(btn => {
            btn.addEventListener('click', (e) => {
                setTheme(e.currentTarget.dataset.theme);
                document.querySelectorAll('.accessibility-container').forEach(marcarTemaAtual);
                abrir(false);
                alternar.focus();
            });
        });

        marcarTemaAtual(container);
    });
});
