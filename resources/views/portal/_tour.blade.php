{{--
    Dados do tour guiado de uma página do portal (lidos por public/assets/js/portal-tour.js). A página declara:

        @section('tour')
            @include('portal._tour', ['chave' => 'resultados', 'passos' => [
                ['titulo' => '...', 'texto' => '...'],                       // sem alvo = quadro central (boas-vindas)
                ['alvo' => '#id-ou-[data-tour="x"]', 'titulo' => '...', 'texto' => '...'],
            ]])
        @endsection

    Passo cujo alvo não existe/está oculto na tela é pulado sozinho (ex.: painel que a avaliação não libera ao aluno).
    A seção 'tour' também liga o botão "Refazer tour da página" do rodapé (layouts/portal.blade.php).
--}}
<script type="application/json" id="portal-tour-dados">{!! json_encode(['chave' => $chave, 'passos' => $passos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
