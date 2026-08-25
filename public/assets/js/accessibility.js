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
        if (document.querySelector('[vw]')) return;

        const vwDiv = document.createElement('div');
        vwDiv.setAttribute('vw', '');
        vwDiv.className = 'enabled';
        vwDiv.innerHTML = `
            <div vw-access-button class="active"></div>
            <div vw-plugin-wrapper>
                <div class="vw-plugin-top-wrapper"></div>
            </div>
        `;
        document.body.appendChild(vwDiv);

        const script = document.createElement('script');
        script.src = 'https://vlibras.gov.br/app/vlibras-plugin.js';
        script.onload = () => {
            new window.VLibras.Widget('https://vlibras.gov.br/app');
        };
        document.body.appendChild(script);
    };

    // --- Actions ---
    const setTheme = (theme) => {
        currentTheme = theme;
        applyTheme();
    };

    const triggerVLibras = () => {
        const btn = document.querySelector('[vw-access-button]');
        if (btn) {
            btn.click();
        } else {
            console.warn('VLibras button not found yet.');
        }
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
