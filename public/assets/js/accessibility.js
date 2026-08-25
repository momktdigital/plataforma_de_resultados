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
    // Look for a container to inject the menu.
    const containers = document.querySelectorAll('.accessibility-container');

    containers.forEach(container => {
        container.innerHTML = `
            <div class="flex items-center gap-1 sm:gap-2">
                <!-- Theme Selector -->
                <div class="relative group">
                    <button class="text-slate-500 hover:text-primary transition-colors p-1 flex items-center justify-center rounded hover:bg-slate-100" title="Temas (Claro, Escuro, Contraste)">
                        <i class="ph ph-palette text-xl"></i>
                    </button>
                    <!-- Dropdown -->
                    <div class="absolute right-0 top-full mt-1 w-44 bg-white border border-slate-200 shadow-lg rounded-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50">
                        <button class="btn-acc-theme block w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:text-primary first:rounded-t-lg transition-colors" data-theme="light">
                            <i class="ph ph-sun mr-2"></i> Modo Claro
                        </button>
                        <button class="btn-acc-theme block w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:text-primary transition-colors" data-theme="dark">
                            <i class="ph ph-moon mr-2"></i> Modo Escuro
                        </button>
                        <button class="btn-acc-theme block w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:text-primary last:rounded-b-lg transition-colors" data-theme="high-contrast">
                            <i class="ph ph-circle-half mr-2"></i> Alto Contraste
                        </button>
                    </div>
                </div>

                <!-- VLibras Trigger -->
                <button title="Tradutor de Libras" class="btn-acc-vlibras text-slate-500 hover:text-primary transition-colors p-1 flex items-center justify-center rounded hover:bg-slate-100">
                    <i class="ph ph-hands-clapping text-xl"></i>
                </button>

                <!-- Sienna Trigger -->
                <button title="Recursos de acessibilidade (Sienna)" class="btn-acc-sienna text-slate-500 hover:text-primary transition-colors p-1 flex items-center justify-center rounded hover:bg-slate-100">
                    <i class="ph ph-wheelchair text-xl"></i>
                </button>
            </div>
        `;

        // Attach events
        container.querySelector('.btn-acc-vlibras').addEventListener('click', triggerVLibras);
        container.querySelector('.btn-acc-sienna').addEventListener('click', triggerSienna);

        container.querySelectorAll('.btn-acc-theme').forEach(btn => {
            btn.addEventListener('click', (e) => {
                setTheme(e.currentTarget.dataset.theme);
            });
        });
    });
});
