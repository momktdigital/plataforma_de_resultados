{{--
    Mantém o sino do coordenador em dia sem recarregar a página (consulta /notificacoes/resumo a cada minuto) e, se o
    coordenador permitiu, mostra um aviso do navegador (Notification API) quando chega notificação nova.

    Funciona com o sistema aberto numa aba. Aviso com o navegador FECHADO exigiria Web Push (chaves VAPID, service worker
    e HTTPS) — ver README. Variável: $adminId.
--}}
<script>
(function () {
    'use strict';

    var URL_RESUMO = @json(route('notificacoes.resumo'));
    var CHAVE = 'notificacoes-ultimo-id-' + @json($adminId);
    var INTERVALO = 60000;

    function lerUltimo() { try { return parseInt(localStorage.getItem(CHAVE) || '', 10); } catch (e) { return NaN; } }
    function gravarUltimo(id) { try { localStorage.setItem(CHAVE, String(id)); } catch (e) { /* sem armazenamento: só não evita repetir */ } }

    function atualizarSino(total) {
        document.querySelectorAll('[data-notificacoes-contagem]').forEach(function (el) {
            el.classList.toggle('hidden', total <= 0);
            var visivel = el.querySelector('[aria-hidden="true"]');
            var leitor = el.querySelector('.sr-only');
            if (visivel) visivel.textContent = total > 99 ? '99+' : String(total);
            if (leitor) leitor.textContent = total + ' não lida(s)';
        });
        document.querySelectorAll('[data-notificacoes-ponto]').forEach(function (el) { el.classList.toggle('hidden', total <= 0); });
    }

    function avisarNoNavegador(novas) {
        if (!('Notification' in window) || !window.isSecureContext || Notification.permission !== 'granted') return;
        novas.slice(0, 3).forEach(function (n) {
            var aviso = new Notification(n.titulo, { body: n.texto, tag: 'notificacao-' + n.id });
            aviso.onclick = function () { window.focus(); window.location.href = n.url; aviso.close(); };
        });
    }

    function consultar() {
        if (document.visibilityState === 'hidden') return;
        fetch(URL_RESUMO, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (dados) {
                if (!dados) return;
                atualizarSino(dados.naoLidas);

                var ultimo = lerUltimo();
                // Primeira visita neste navegador: só marca o ponto de partida, sem avisar o que já existia.
                if (!isNaN(ultimo)) {
                    avisarNoNavegador(dados.ultimas.filter(function (n) { return n.id > ultimo; }));
                }
                if (isNaN(ultimo) || dados.maiorId > ultimo) gravarUltimo(dados.maiorId);
            })
            .catch(function () { /* sem rede: tenta no próximo ciclo */ });
    }

    // Botões "Ativar avisos no navegador" / "Enviar aviso de teste" da página de notificações.
    var botao = document.getElementById('ativar-avisos-navegador');
    var teste = document.getElementById('testar-aviso-navegador');
    var estado = document.getElementById('estado-avisos-navegador');

    function dizer(texto) { if (estado) estado.textContent = texto; }

    function descreverEstado() {
        if (!botao) return;
        var suportado = 'Notification' in window;
        var pode = suportado && window.isSecureContext;
        var permissao = pode ? Notification.permission : 'indisponivel';

        botao.classList.toggle('hidden', permissao !== 'default');
        if (teste) teste.classList.toggle('hidden', permissao !== 'granted');

        if (!suportado) {
            dizer('Este navegador não oferece avisos.');
        } else if (!window.isSecureContext) {
            dizer('O navegador só libera avisos em conexão segura (HTTPS ou localhost). Acesse o sistema por um endereço https://.');
        } else if (permissao === 'granted') {
            dizer('Avisos do navegador ativados: você será avisado quando chegar notificação nova, enquanto o sistema estiver aberto. Use o teste para conferir.');
        } else if (permissao === 'denied') {
            dizer('Os avisos estão bloqueados neste navegador. Clique no cadeado ao lado do endereço, abra "Configurações do site", mude Notificações para "Permitir" e recarregue a página.');
        } else {
            dizer('Receba um aviso do navegador quando chegarem notificações novas (com o sistema aberto).');
        }
    }

    function pedirPermissao() {
        var concluido = function () { descreverEstado(); };
        // Navegadores antigos só aceitam a forma com função; os novos devolvem uma promessa.
        var resultado = Notification.requestPermission(concluido);
        if (resultado && typeof resultado.then === 'function') resultado.then(concluido, concluido);

        // O navegador pode não mostrar a janela (Chrome com o pedido já dispensado mostra só um sino riscado na barra de
        // endereço; navegadores embutidos em aplicativos nem isso): então ensina o caminho manual.
        setTimeout(function () {
            if (Notification.permission === 'default') mostrarAjudaManual();
        }, 2500);
    }

    function enderecoDasConfiguracoes() {
        var ua = navigator.userAgent;
        if (/Edg\//.test(ua)) return 'edge://settings/content/notifications';
        if (/Firefox\//.test(ua)) return 'about:preferences#privacy';
        return 'chrome://settings/content/notifications';
    }

    function mostrarAjudaManual() {
        var ajuda = document.getElementById('ajuda-avisos-navegador');
        if (!ajuda) return;
        var endereco = enderecoDasConfiguracoes();
        document.getElementById('ajuda-avisos-endereco').textContent = endereco;
        document.getElementById('ajuda-avisos-site').textContent = window.location.origin;
        ajuda.classList.remove('hidden');
        dizer('O navegador não mostrou a janela de permissão (permissão atual: "' + Notification.permission + '"). Siga os passos abaixo.');
    }

    function enviarTeste() {
        try {
            var aviso = new Notification('Avisos ativados', { body: 'É assim que você será avisado de novos resultados e alunos em atenção.', tag: 'teste-aviso' });
            aviso.onclick = function () { window.focus(); aviso.close(); };
            dizer('Aviso de teste enviado. Se não apareceu, confira se as notificações do navegador estão liberadas nas configurações do Windows/macOS (e o modo "Não perturbe" desligado).');
        } catch (e) {
            dizer('Não foi possível mostrar o aviso: ' + e.message);
        }
    }

    var copiar = document.getElementById('ajuda-avisos-copiar');
    if (copiar) {
        copiar.addEventListener('click', function () {
            var texto = document.getElementById('ajuda-avisos-endereco').textContent;
            var feito = function () { copiar.textContent = 'Copiado!'; setTimeout(function () { copiar.textContent = 'Copiar endereço'; }, 2000); };
            if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(texto).then(feito, function () {});
        });
    }

    if (botao) {
        descreverEstado();
        botao.addEventListener('click', function () {
            if (!('Notification' in window) || !window.isSecureContext) { descreverEstado(); return; }
            pedirPermissao();
        });
        if (teste) teste.addEventListener('click', enviarTeste);
    }

    consultar();
    setInterval(consultar, INTERVALO);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') consultar(); });
})();
</script>
