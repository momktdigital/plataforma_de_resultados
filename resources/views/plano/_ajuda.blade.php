{{--
    "Dúvidas desta etapa": orientações do roteiro (do dashboard à ação pedagógica) para cada etapa do plano. Texto fixo —
    não consulta os dados do curso nem usa IA; questões específicas dependem das evidências e da discussão com o NDE.
    O script do formulário mostra só o bloco da etapa em que o coordenador está.
--}}
@php
    $ajuda = [
        1 => [
            ['O que é participação?', 'É a proporção de estudantes previstos (pela matrícula) que realizaram a avaliação. O número já vem calculado pelo painel; compare com a meta institucional.'],
            ['O que significa proficiência?', 'Nesta leitura institucional, é proficiente o estudante com pelo menos 60% de acerto (o corte pode ser alterado nas Configurações). O indicador é o percentual de estudantes proficientes. Não é a escala oficial do ENADE/ENAMED.'],
            ['Como definir a meta de proficiência?', 'Pactue com o NDE um avanço factível para a próxima avaliação, olhando a distribuição dos resultados e a capacidade real de intervenção. Evite metas que dependam de mudanças estruturais fora do ciclo.'],
            ['Preciso de um plano se a participação está adequada?', 'Não. Se a participação está na meta, concentre o plano na proficiência. Investigue causas de participação somente quando houver necessidade real de intervenção.'],
        ],
        2 => [
            ['Por onde começo a leitura?', 'Do geral para o específico: resultado geral → períodos do curso → estudantes próximos ao corte → áreas, temas e níveis cognitivos → questões. O dado que originou o plano já está resumido na etapa anterior.'],
            ['Um período inferior ao outro indica queda de aprendizagem?', 'Não necessariamente: períodos diferentes podem ter grupos de estudantes diferentes. Trate a comparação como sinal de investigação, não como prova de perda de aprendizagem.'],
            ['O painel revela a causa do problema?', 'Não. O painel mostra onde está a fragilidade; as causas são hipóteses que o NDE precisa investigar e sustentar com evidências — é a próxima etapa.'],
            ['Preciso olhar todas as questões?', 'Não. Aprofunde as questões e os níveis cognitivos ligados às fragilidades prioritárias.'],
        ],
        3 => [
            ['O que entra no Ishikawa?', 'Hipóteses de causa em dimensões como currículo em execução, práticas de ensino, avaliação, aprendizagem, docentes e condições de gestão. Hipótese não é conclusão comprovada.'],
            ['Como pontuar as causas?', 'Impacto, Evidência e Governabilidade, cada um de 1 (baixo) a 3 (alto). A pontuação é o produto das três (até 27) e orienta a discussão; não substitui a validação pelo NDE.'],
            ['Como usar os 5 Porquês?', 'Pergunte por que a causa ocorre e aprofunde cada resposta. Pare quando houver uma causa específica, verificável, sob governabilidade e modificável antes da próxima avaliação. Não é obrigatório chegar a cinco.'],
            ['E se a causa for estrutural?', 'Registre o achado para encaminhamento próprio, mas não o use como única explicação de um plano que precisa produzir intervenção antes da próxima avaliação.'],
        ],
        4 => [
            ['O que é uma ação pedagógica válida?', 'Uma mudança concreta na experiência de aprendizagem, ligada à causa-raiz, com responsável, prazo, grupo alcançado e evidência de execução. Comece sempre com um verbo no infinitivo (implementar, revisar, aplicar...).'],
            ['Reunião com docentes é uma ação suficiente?', 'Pode ser uma etapa de articulação, mas não substitui a intervenção com os estudantes. Explicite o que mudará nas atividades de aprendizagem.'],
            ['Como monitorar antes da próxima avaliação?', 'Registre a execução, os estudantes alcançados, os produtos e as verificações intermediárias de aprendizagem. Use os resultados para ajustar a intervenção ainda no ciclo.'],
            ['E se não houver tempo para executar?', 'Redimensione a ação para algo viável antes da próxima aplicação. Uma proposta só de longo prazo não atende ao objetivo deste ciclo.'],
        ],
        5 => [
            ['O que acontece depois que eu enviar?', 'O colaborador analisa o plano e decide: aprovar, pedir ajustes (o plano volta para você editar e reenviar) ou recusar — sempre com justificativa. Você é avisado pelas notificações.'],
            ['Posso mudar o plano depois de aprovado?', 'O conteúdo aprovado não muda. Você acompanha a execução: atualiza a situação das ações, reprograma prazos (com justificativa) e registra o andamento. Se o plano precisar mudar de rumo, cancele e crie outro a partir dele.'],
            ['A meta atingida prova que a ação funcionou?', 'O resultado da próxima avaliação permite ver o avanço frente à meta, mas atribuir causalidade à ação exige cautela e análise das evidências. O plano mostrará a comparação quando houver a avaliação seguinte.'],
        ],
    ];
@endphp
<div class="space-y-3" aria-label="Dúvidas frequentes por etapa">
    @foreach ($ajuda as $numero => $perguntas)
        <div data-ajuda-etapa="{{ $numero }}" class="{{ $numero === ($etapa ?? 1) ? '' : 'hidden' }} space-y-2">
            @foreach ($perguntas as [$pergunta, $resposta])
                <details class="rounded-lg border border-slate-200 bg-white">
                    <summary class="cursor-pointer list-none [&::-webkit-details-marker]:hidden px-3 py-2.5 text-sm font-semibold text-slate-800 flex items-center justify-between gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded-lg">
                        <span>{{ $pergunta }}</span>
                        <i class="ph-bold ph-caret-down text-slate-500 shrink-0" aria-hidden="true"></i>
                    </summary>
                    <p class="px-3 pb-3 text-sm text-slate-600">{{ $resposta }}</p>
                </details>
            @endforeach
        </div>
    @endforeach
</div>
