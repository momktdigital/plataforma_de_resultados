# Changelog

Histórico deste sistema (Avaliações), não do esqueleto `laravel/laravel`. Sem
versionamento numerado formal — organizado por área funcional, na ordem em
que os recursos foram introduzidos. Para o histórico completo, linha a
linha, veja `git log`.

## Núcleo — Avaliações, Questões e Resultados

- Cadastro de Avaliações, import de Questões/Gabarito (com metadados
  pedagógicos opcionais: Bloom, Miller, dificuldade, matriz/DCN/Portaria
  INEP/PPC) e import de Resultados.
- `Anulacao` como fonte única de verdade de "essa resposta contou como
  certa": uma questão pode ser anulada globalmente ou só para um
  subconjunto de alunos, e toda leitura (relatórios, boletim do portal,
  BI) passa por ali.
- `resultado_resumos` como cache de leitura (acertos/total/percentual por
  aluno+avaliação+período), mantido por `ResumoResultadoService` em vez de
  recalculado a cada requisição.
- Categorias em árvore para agrupar avaliações no boletim do portal.
- Status de avaliação (Ativa/Anulada) e exclusão em massa de questões.
- Dashboard de BI com filtro por avaliação e gráficos (Chart.js).
- **Meta por período (`questoes.periodo_minimo`)**: cada questão pode indicar o período
  do curso a partir do qual se espera que o aluno acerte (3 = 3º período em diante;
  vazio = vale para todos). Editável no formulário da questão, importado/exportado na
  planilha ("Período mínimo") e listado na tabela e na impressão. Alimenta o gráfico acerto x esperado do boletim —
  não altera acerto, nota nem resumos.

## Portal público do aluno

- Consulta por CPF + data de nascimento, com 2FA opcional por e-mail
  (código de 6 dígitos, expiração, limite de tentativas e bloqueio por IP).
- CAPTCHA opcional (reCAPTCHA ou hCaptcha) na consulta.
- Boletim por avaliação com exportação em PDF (html2pdf).
- Tour guiado na primeira visita do boletim e do detalhe da avaliação (a tela de login não tem), com "Refazer tour da
  página" no rodapé. O link da área administrativa ficou só na tela de login do aluno.
- Boletim sem "média geral" no card do topo, sem comparação com a turma nos cards de
  resumo e com percentuais em vez de "pontos". Novo card explicativo de Bloom e, em cada
  categoria, gráfico de rendimento (acerto total) x mínimo esperado por avaliação (meta
  `questoes.periodo_minimo`; 60% quando a prova não tem a meta) com "Leitura rápida" — substitui "Você x turma",
  "Dificuldade pedagógica", "Nível de Bloom" e "Áreas onde você mais diverge da turma". O mapa de
  domínio por área mostra o mínimo esperado em cada célula (amarelo abaixo, 60% sem a meta).
- Detalhe da avaliação sem "Comparativo com a turma", "Posição relativa" e "Sua resposta x turma"; "Desempenho por
  área" em barras com a meta de cada área; trilha de estudo e lacunas/consolidados só com as questões que o aluno
  precisava acertar pelo período dele. O gráfico de nível de Bloom saiu dessa tela e deu lugar a "Como você foi nesta
  prova", uma leitura em palavras simples (resultado, áreas, tipo de pergunta, questões de períodos à frente).
- Regeneração de sessão no login e invalidação completa no logout.

## Administração

- Login de administrador, "esqueci minha senha", CRUD de administradores
  (renomear/resetar senha, e-mail opcional).
- CRUD de alunos, com foto de perfil, busca/filtro e importação de
  matrícula em massa (planilha).
- Import de Questões/Gabarito e de Resultados com pré-visualização
  (dry-run) antes de confirmar.
- Ações em massa (lixeira e outras listas), busca global no menu lateral.
- Log de auditoria (`AtividadeLogger`) cobrindo ações administrativas e
  imports (quem, quando, o quê).
- Configurações do sistema divididas em duas tabelas paralelas:
  `configuracoes` (herdada do app legado — aparência do portal, CAPTCHA,
  SMTP/2FA) e `configuracoes_sistema` (exclusiva deste app — atualização,
  backup).
- Autoatualizador via GitHub (com confirmação manual de tag/hash e
  rollback de banco) e backups agendados com retenção configurável.

## Migração do sistema legado

- Este repositório já foi um app legado em PHP puro com as mesmas funções
  (portal do aluno, 2FA, login administrativo, CRUD de alunos e
  configurações), reescrito do zero nesta aplicação Laravel.
- Comando de import que lê as tabelas legadas (`gabaritos`, `resultados`)
  direto do banco compartilhado, sem exigir replanilhamento manual.
- Código legado removido do repositório depois que todas as suas funções
  foram portadas e validadas em produção (ver histórico do Git para
  consultar o código antigo, se necessário).

## Segurança

- Rate-limiting em duas camadas: `throttle` do Laravel por rota (mais
  apertado nas ações que envolvem algo "adivinhável" — CPF/nascimento,
  código 2FA, login) e um `RateLimiter` próprio por usuário+IP no login.
- Comparação de código 2FA em tempo constante (`hash_equals`).
- Chaves sensíveis de configuração (segredo do reCAPTCHA/hCaptcha, senha
  SMTP) criptografadas em repouso na tabela `configuracoes`.
- Sanitização de upload de logo SVG (XSS armazenado) e de injeção de
  fórmula na exportação de questões.
- Senha mínima de administrador elevada e fluxo de instalação/atualização
  com confirmações explícitas para operações destrutivas.

## Performance e escala

- Imports (Questões, Resultados, Matrícula) batelados com `whereIn` +
  upsert em lote, em vez de uma query por linha.
- Imports despachados como jobs em fila, com tela de progresso.
- Índices compostos e cache (`Cache::remember`) nos pontos mais lidos
  (configuração, disponibilidade de visualização, resumo de resultado).
- Proteção de memória e limite de linhas na leitura de planilhas grandes.

## Qualidade

- Cobertura de teste crescente sobre os fluxos críticos: instalação,
  autoatualização, imports, 2FA/portal, leitura de planilha
  (`SpreadsheetReader`) e escrita do `.env` (`EnvFileWriter`) isolados de
  Feature tests.
- Páginas de erro (404/403/500) com a identidade visual do sistema, em vez
  da página padrão do Laravel.

## Perfil de colaborador e cronograma de atividades

- **Colaborador** (`admins.role = 'collaborator'`, nova aba em Usuários; migration acrescenta o valor ao ENUM do banco
  legado): monta o cronograma da checklist de auditoria ROC/ROD e registra as pendências. Entra por código no e-mail; não vê
  resultados nem alunos.
- **Cronograma**: o colaborador cadastra atividades (data, rotina ROD/ROC/Auditoria, projeto, o que conferir) e marca os cursos a
  que se aplicam, cada um com a sua situação. O calendário do coordenador (`/cronograma`) mostra só as atividades dos cursos dele.
- **Lista com filtros** além do calendário (coordenador e colaborador): busca, rotina, situação, curso e intervalo de datas, paginada.
- O colaborador pode **excluir pendências** (o conteúdo fica na auditoria).
- **Carga pela planilha**: `php artisan cronograma:importar` (simulação por padrão, `--gravar` para valer) importa o checklist e o
  registro de pendências da "Tabela-base da Auditoria ROC/ROD", com mapa de colunas para cursos; repetível sem duplicar.
- **Pendências** vinculadas à atividade e ao curso (pendência, encaminhamento, prazo, situação, responsável): o colaborador registra,
  o coordenador só visualiza; ficam como histórico e tudo vai para a auditoria.

## Plano de ação do coordenador

- **Ícone "iniciar plano de ação"** em cada visual e dado do painel do coordenador (cartões, gráficos de evolução, área, Bloom e
  tema, barras por período e por curso, linhas de avaliações, destaques, Comparar semestres e Dashboard da avaliação). Num visual com
  itens, abre um menu: o visual inteiro ou um item (uma área, um nível de Bloom...).
- **Roteiro em cinco etapas** (ponto de partida, leitura do dado, causas com Ishikawa e 5 Porquês, ações, síntese e envio) já
  preenchido com o curso, a participação atual, a meta de participação e a proficiência atual, mais o dado do visual e sugestões de
  texto. Os números são recalculados no servidor, nunca vêm do navegador.
- **Aprovação pelo colaborador** (e administrador): fila, critérios, e decisão de aprovar, pedir ajustes ou recusar, com
  justificativa obrigatória nas duas últimas. Plano devolvido volta ao coordenador, que edita e reenvia.
- **Acompanhamento** do plano aprovado: situação das ações, notas de andamento, prazo reprogramável com justificativa, encerramento
  com síntese, cancelamento com motivo e comparação com o DI seguinte (linha de base x meta). Lembretes diários
  (`planos:lembretes`) de prazo próximo, prazo vencido e plano parado. Histórico só de acrescentar e auditoria.

## Perfil de reitor e painel da reitoria

- Novo perfil **reitor** (`admins.role = rector`): só leitura, todos os cursos,
  sem dado nominal de aluno. Entra por código no e-mail (como o coordenador) ou
  por senha. Cadastro na aba **Reitoria** de Usuários (a migration
  `allow_rector_role_on_admins_table` acrescenta `rector` ao ENUM legado de
  `admins.role` no MySQL — sem ela o cadastro dava erro 500). Middleware `perfil:`
  (lista positiva de perfis) separa as áreas; o reitor não alcança avaliações,
  alunos nem o BI.
- **Painel da reitoria** (`/reitoria`) em cinco telas — Visão institucional,
  Desempenho, Trajetória no curso, Competências e Evolução entre semestres:
  participação por curso × meta, proficiência institucional e patamares,
  média/mediana, faixas de acerto, dispersão (quartis), mapa participação ×
  proficiência, mapa de calor curso × período, crescimento ao longo do curso,
  cobertura da aplicação, Bloom e áreas, evolução entre semestres, e planilha
  .xlsx. Cada quadro com "Sobre este quadro" e "Ver leitura".
- Seletores com busca e árvore para categoria e avaliação; "Todos os períodos"
  no filtro de período.
- **Visão do coordenador de um curso** para o reitor: abre as telas do coordenador
  (painel, alunos, desempenho, comparar semestres, avaliações/Dashboard) de um
  curso à escolha, somente leitura, com aviso na tela e registro na auditoria.
- **Trajetória no curso**: os dois gráficos de linhas ganham seletor para ver todos os
  cursos juntos, em **minigráficos** (um por curso, mesma escala) ou **um curso** só.
- **Análise dos itens** (institucional): mapa acerto × discriminação, gabarito
  suspeito, problema da questão × lacuna de formação, por área e por avaliação.
- **Estudantes em risco** (agregado): por faltas, por acerto e no total, por curso e
  período do curso, com tendência.
- **Regra de "estudante em risco" definida pela administração** (Configurações →
  Estudante em risco): percentual de acerto e/ou faltas, combinados por "ou"/"e", com
  sobreposição por avaliação (limite de acerto próprio, dispensa de falta). Vale para a
  lista de alunos em atenção do coordenador, para as notificações e para o painel da
  reitoria — os dois contam as mesmas pessoas. Substitui o "ausência recorrente" e o
  "baixo desempenho persistente" fixos do painel.
- **Drill-down** nos gráficos e tabelas (abre a análise do curso no recorte
  clicado) e pontos de atenção que apontam para o quadro de origem.
- **Ver como tabela** em todos os gráficos, com cópia para planilha.
- **Relatório institucional** (`/reitoria/relatorio`): capa, resumo, quadros e
  leituras; imprime em A4 / salvar como PDF e exporta PowerPoint. As telas
  agora imprimem sem o menu lateral.
- Filtros **período letivo / categoria / avaliação**: como cada avaliação costuma
  ser de um curso, escolher a categoria reúne os cursos (o campo Avaliação só
  lista as da categoria); "todas" é o padrão, com aviso quando categorias
  diferentes se misturam. A evolução entre semestres soma as avaliações da
  categoria em cada semestre.
- Critério de proficiência e meta de participação configuráveis em
  Configurações do sistema.

## Painel de gestão do coordenador

- **Acompanhamento de alunos** (contatado / em acompanhamento / resolvido, com
  observação e data; histórico que só se acrescenta; selo e filtro na lista; coluna
  na planilha). Restrito ao curso; o reitor na visão do curso só lê.

- Saudação (Bom dia/Boa tarde/Boa noite) e navegação por abas: Visão geral,
  Alunos do curso, Desempenho e Avaliações.
- Visão geral com avaliações recentes e alunos por período do curso. Sem a lista "Alunos que precisam de atenção", sem o
  quadro "Situação dos alunos" e sem "Em atenção" na faixa verde (o cartão "Precisam de atenção" continua, com a regra em
  vigor e o link para a lista). Avaliações recentes ganharam "Dentro do esperado" (alunos que alcançaram o mínimo do
  período deles, ex.: 5 (20%); sem a meta na prova, só a média) e alunos por período ganhou o % de acerto.
- Lista dos alunos do curso por semestre (busca, filtros, ordenação,
  planilha .xlsx) e ficha individual (nota vs. média do curso, posição,
  evolução, áreas e matrículas).
- O detalhamento por categoria foi para a aba Desempenho. Nela, cada categoria virou um dropdown, com filtros de
  categoria e período do curso, "alunos abaixo do desempenho esperado" (ou abaixo de 60% sem a meta), evolução dos alunos
  que atingiram o esperado, gráficos de área, Bloom e tema com abas Geral/Por período, e a tabela de avaliações no fim.
- Comparar semestres: média, alunos abaixo do esperado (ou de 60%), presença, áreas e
  período do curso de dois períodos letivos, por categoria, com filtros de categoria e
  período do curso; e, por período do curso, quantos alunos atingiram o esperado em cada
  semestre (subiu, estável ou caiu). Menu na ordem Visão geral, Desempenho, Avaliações,
  Comparar semestres, Alunos.
- Notificações do coordenador (novos resultados, média em queda, presença
  baixa, alunos que passaram a precisar de atenção), com sino, marcar como lida
  e avisos do navegador com o sistema aberto.
- Destaques da visão geral agora agrupados por categoria, e números dos
  insights no formato brasileiro (vírgula).

## Dashboard da avaliação

- Link do gabarito no cabeçalho; coluna "Acertos dentro do esperado" na lista de alunos; desempenho por área sem a teia; filtro de área na análise de
  alternativas; análise demográfica no fim; sem "Evolução da média na categoria"; forma de ingresso (da planilha de alunos) no perfil demográfico e na
  equidade.
