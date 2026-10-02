{{--
    Menu do usuário na barra superior: avatar (a inicial — usuários do sistema não têm foto), nome e, ao abrir, o perfil
    (nome, e-mail, papel), atalhos e "Sair". Teclado: Enter/Espaço abre, Esc fecha e devolve o foco, Tab percorre as opções.
    Variável: $usuarioLogado (Admin).
--}}
@php
    $nomeMenu = $usuarioLogado->username;
    $inicialMenu = mb_strtoupper(mb_substr($nomeMenu, 0, 1));
    $papelMenu = $usuarioLogado->ehCoordenador() ? 'Coordenador' : 'Administrador';
@endphp
<div class="relative" id="menu-usuario">
    <button type="button" id="menu-usuario-botao" aria-haspopup="menu" aria-expanded="false" aria-controls="menu-usuario-lista"
            class="flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1 pl-1 pr-3 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-bold text-slate-900" aria-hidden="true">{{ $inicialMenu }}</span>
        <span class="max-w-[160px] truncate text-sm font-medium text-slate-700">{{ $nomeMenu }}</span>
        <i class="ph ph-caret-down text-slate-500" aria-hidden="true"></i>
    </button>

    <div id="menu-usuario-lista" role="menu" aria-labelledby="menu-usuario-botao" hidden
         class="absolute right-0 z-30 mt-2 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
        <div class="border-b border-slate-100 px-4 py-3">
            <p class="truncate text-sm font-semibold text-slate-800">{{ $nomeMenu }}</p>
            @if ($usuarioLogado->email)
                <p class="truncate text-xs text-slate-500">{{ $usuarioLogado->email }}</p>
            @endif
            <span class="mt-1.5 inline-block rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">{{ $papelMenu }}</span>
        </div>
        <div class="py-1">
            <a href="{{ route('perfil.edit') }}" role="menuitem" class="flex items-center gap-3 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none">
                <i class="ph ph-user-circle text-lg text-slate-500" aria-hidden="true"></i> Meu perfil
            </a>
            @if ($usuarioLogado->ehCoordenador())
                <a href="{{ route('notificacoes.index') }}" role="menuitem" class="flex items-center gap-3 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none">
                    <i class="ph ph-bell text-lg text-slate-500" aria-hidden="true"></i> Notificações
                    <span data-notificacoes-contagem class="ml-auto min-w-[1.4rem] rounded-full bg-primary px-1.5 py-0.5 text-center text-xs font-bold text-slate-900 {{ $naoLidas > 0 ? '' : 'hidden' }}"><span aria-hidden="true">{{ $naoLidas > 99 ? '99+' : $naoLidas }}</span><span class="sr-only">{{ $naoLidas }} não lida(s)</span></span>
                </a>
            @endif
        </div>
        <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100 py-1">
            @csrf
            <button type="submit" role="menuitem" class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm text-red-700 hover:bg-red-50 focus:bg-red-50 focus:outline-none">
                <i class="ph ph-sign-out text-lg" aria-hidden="true"></i> Sair
            </button>
        </form>
    </div>
</div>

<script>
(function () {
    var botao = document.getElementById('menu-usuario-botao');
    var lista = document.getElementById('menu-usuario-lista');
    if (!botao || !lista) return;

    function abrir(aberto, devolverFoco) {
        lista.hidden = !aberto;
        botao.setAttribute('aria-expanded', aberto ? 'true' : 'false');
        if (aberto) {
            var primeiro = lista.querySelector('[role="menuitem"]');
            if (primeiro) primeiro.focus();
        } else if (devolverFoco) {
            botao.focus();
        }
    }

    botao.addEventListener('click', function () { abrir(lista.hidden, false); });
    document.addEventListener('click', function (e) {
        if (!lista.hidden && !document.getElementById('menu-usuario').contains(e.target)) abrir(false, false);
    });
    document.addEventListener('keydown', function (e) {
        if (lista.hidden) return;
        if (e.key === 'Escape') { abrir(false, true); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            var itens = Array.prototype.slice.call(lista.querySelectorAll('[role="menuitem"]'));
            var atual = itens.indexOf(document.activeElement);
            var proximo = e.key === 'ArrowDown' ? (atual + 1) % itens.length : (atual - 1 + itens.length) % itens.length;
            itens[proximo].focus();
            e.preventDefault();
        }
    });
    // Tab para fora do menu o fecha.
    lista.addEventListener('focusout', function (e) {
        if (e.relatedTarget && !document.getElementById('menu-usuario').contains(e.relatedTarget)) abrir(false, false);
    });
})();
</script>
