@php
    $abas = [
        ['label' => 'Geral', 'route' => 'sistema.configuracoes.index', 'padrao' => 'sistema.configuracoes.*'],
        ['label' => 'Portal público', 'route' => 'sistema.portal.index', 'padrao' => 'sistema.portal.*'],
        ['label' => 'Backups', 'route' => 'sistema.backups.index', 'padrao' => 'sistema.backups.*'],
        ['label' => 'Integração Avalia', 'route' => 'sistema.integracao-avalia.index', 'padrao' => 'sistema.integracao-avalia.*'],
        ['label' => 'Dados legados', 'route' => 'sistema.legado.index', 'padrao' => 'sistema.legado.*'],
        ['label' => 'Atualizações', 'route' => 'sistema.atualizacao.index', 'padrao' => 'sistema.atualizacao.*'],
        ['label' => 'Auditoria', 'route' => 'sistema.atividades.index', 'padrao' => 'sistema.atividades.*'],
    ];
@endphp

<div class="mb-6 border-b border-slate-200">
    <nav class="flex gap-6 text-sm">
        @foreach ($abas as $aba)
            @php $ativa = request()->routeIs($aba['padrao']); @endphp
            <a href="{{ route($aba['route']) }}"
               class="pb-3 -mb-px border-b-2 font-medium {{ $ativa ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                {{ $aba['label'] }}
            </a>
        @endforeach
    </nav>
</div>
