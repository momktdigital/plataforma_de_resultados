{{-- Barra de acessibilidade (A+/A-, temas, VLibras) + widget Sienna (perfis de TDAH/dislexia/etc, complementar) --}}
<script src="{{ asset('assets/js/accessibility.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/sienna-accessibility/dist/sienna-accessibility.umd.js" async></script>
{{-- Texto alternativo dos gráficos (role="img" + aria-label com título e dados) --}}
<script src="{{ asset('assets/js/graficos-acessiveis.js') }}" defer></script>
@include('partials.erros-de-campo')
