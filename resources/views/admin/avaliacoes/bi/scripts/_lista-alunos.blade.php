@if ($estado['ranking_completo']['visivelAdmin'] && $rankingCompleto !== null && $rankingTotal > count($rankingCompleto))
<script>
// Lista nominal: o HTML traz só a primeira página; as demais chegam por fetch (rolando até o fim ou pelo botão).
(function () {
    const caixa = document.getElementById('lista-alunos');
    if (!caixa) return;
    const corpo = caixa.querySelector('tbody');
    const rodape = document.getElementById('lista-alunos-mais');
    const botao = document.getElementById('lista-alunos-botao');
    const carregando = document.getElementById('lista-alunos-carregando');
    const total = parseInt(caixa.dataset.total, 10);
    let proximo = caixa.dataset.proximo === '' ? null : parseInt(caixa.dataset.proximo, 10);
    let ocupado = false;

    function rotulo() {
        botao.textContent = 'Mostrar mais (' + Math.max(0, total - corpo.rows.length) + ' restantes)';
    }

    async function carregar() {
        if (ocupado || proximo === null) return;
        ocupado = true;
        botao.classList.add('hidden');
        carregando.classList.remove('hidden');
        try {
            const url = new URL(caixa.dataset.url, window.location.origin);
            url.searchParams.set('inicio', proximo);
            if (caixa.dataset.periodo) url.searchParams.set('periodo', caixa.dataset.periodo);
            const resposta = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!resposta.ok) throw new Error('HTTP ' + resposta.status);
            const dados = await resposta.json();
            corpo.insertAdjacentHTML('beforeend', dados.html);
            proximo = dados.proximo;
            if (proximo === null) {
                rodape.classList.add('hidden');
            } else {
                rotulo();
            }
        } catch (erro) {
            botao.textContent = 'Não foi possível carregar — tentar de novo';
        } finally {
            ocupado = false;
            carregando.classList.add('hidden');
            if (proximo !== null) botao.classList.remove('hidden');
        }
    }

    botao.addEventListener('click', carregar);
    caixa.addEventListener('scroll', function () {
        if (caixa.scrollTop + caixa.clientHeight >= caixa.scrollHeight - 200) carregar();
    });
})();
</script>
@endif
