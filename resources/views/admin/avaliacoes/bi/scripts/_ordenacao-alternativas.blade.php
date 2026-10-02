function ordenarTabelaAlternativas(campo) {
    var tabela = document.getElementById('tabela-alternativas');
    if (!tabela) return;
    var tbody = tabela.querySelector('tbody');
    var linhas = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    linhas.sort(function (a, b) {
        return parseFloat(a.dataset[campo]) - parseFloat(b.dataset[campo]);
    });
    linhas.forEach(function (linha) { tbody.appendChild(linha); });
    var label = document.getElementById('alternativas-ordenacao-label');
    if (label) {
        label.textContent = campo === 'numero' ? '(ordenado por número da questão)' : '(ordenado por % de acerto)';
    }
}
