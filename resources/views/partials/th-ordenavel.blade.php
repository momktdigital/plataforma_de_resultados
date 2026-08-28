{{--
    Cabeçalho de coluna ordenável — usado nas listas do admin que crescem
    (Alunos, Avaliações). Espera $sort/$direction (coluna+direção atuais,
    resolvidos por App\Support\Ordenacao) e, por @include:
    - campo: nome da coluna (tem que estar na lista de colunas permitidas
      passada pro controller — ver Ordenacao::resolver()).
    - label: texto do cabeçalho.
    - class (opcional): classes extras no <th>.
    - pageName (opcional): nome do parâmetro de página do paginate() desta
      lista — default 'page'. Removido da URL ao trocar de ordenação, senão
      o usuário poderia cair numa página que não existe mais na nova ordem.
--}}
@php
    $ativo = $sort === $campo;
    $proximaDirecao = $ativo && $direction === 'asc' ? 'desc' : 'asc';
    $query = request()->query();
    unset($query[$pageName ?? 'page']);
    $query['sort'] = $campo;
    $query['direction'] = $proximaDirecao;
@endphp
<th class="px-4 py-3 {{ $class ?? '' }}">
    <a href="{{ request()->url().'?'.http_build_query($query) }}"
       class="inline-flex items-center gap-1 hover:text-slate-700 {{ $ativo ? 'text-slate-800 font-semibold' : '' }}">
        {{ $label }}
        <span aria-hidden="true" class="text-slate-400">{{ $ativo ? ($direction === 'asc' ? '▲' : '▼') : '⇅' }}</span>
    </a>
</th>
