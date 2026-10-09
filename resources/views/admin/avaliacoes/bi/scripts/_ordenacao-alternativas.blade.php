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

// Filtro por área: esconde as questões de outras áreas (a ordenação continua valendo sobre as linhas visíveis).
function filtrarAlternativasPorArea(area) {
    var tabela = document.getElementById('tabela-alternativas');
    if (!tabela) return;
    var visiveis = 0;
    tabela.querySelectorAll('tbody tr').forEach(function (linha) {
        var mostrar = area === '' || linha.dataset.area === area;
        linha.hidden = !mostrar;
        if (mostrar) visiveis++;
    });
    var contagem = document.getElementById('alternativas-contagem');
    if (contagem) {
        contagem.textContent = visiveis + ' questão(ões)' + (area !== '' ? ' em ' + area : '');
    }
}
