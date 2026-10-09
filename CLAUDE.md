# CLAUDE.md

Notas rápidas para quem for mexer neste código com um agente de IA. O
`README.md` é a referência completa (modelagem de dados, performance e
escala, cada tela); isto aqui é só o que costuma ser fácil de pisar na
bola por não estar óbvio à primeira vista.

## `DB::table` vs Eloquent

Não é escolha de estilo — é escala. `avaliacoes`/`questoes` ficam na casa de
dezenas de milhares de linhas; `respostas`/`resultado_metricas` crescem com
**aluno × avaliação × período × questão**, e uma única avaliação de 100
questões com 10.000 respondentes já é 1 milhão de linhas.

- CRUD, formulários, qualquer leitura/escrita em `avaliacoes`, `questoes`,
  `alunos`, `admins`, `categorias`: Eloquent normalmente.
- Qualquer coisa que agrega ou varre `respostas`/`resultado_metricas`
  (relatórios, BI, o resumo do boletim): `DB::table` com `JOIN` + `GROUP BY`
  + `SUM(CASE...)` direto no banco. Ver `ResumoResultadoService`,
  `RelatorioAlunoService`, `RelatorioAdminService`, `BiDashboardService`,
  `VisualizacaoDisponibilidadeService` — nenhum deles carrega essas tabelas
  para o PHP e soma em memória; a agregação sempre acontece no SQL.
- Imports em lote (`ResultadoImportService`, `QuestaoImportService`,
  `ImportarDadosLegados`) também usam `DB::table` com upsert em lote, pelo
  mesmo motivo: uma query por linha não escala pra planilhas de dezenas de
  milhares de linhas.

## `Anulacao` é a fonte única de verdade

"Essa resposta contou como certa?" tem duas modalidades
(`Questao::anulada_modo`, ver `app/Support/Anulacao.php`): `dar_ponto`
(credita todo mundo, mas a questão continua no total) e
`distribuir_pontuacao` (a questão some do cálculo inteiro, nem soma acerto
nem conta no total).

Todo lugar que soma acertos/total a partir de `respostas` × `questoes`
precisa passar pela mesma regra — hoje isso é `ResumoResultadoService`
(cálculo central, grava `resultado_resumos`) e mais uma leitura direta em
`BiDashboardService`, `RelatorioAdminService`, `RelatorioAlunoService`,
`EstatisticaErroService`, `ResultadoConsultaService`. Se adicionar mais um
lugar que faça essa soma, use `Anulacao::excluirDistribuidas()` (Eloquent
ou `DB::table`) ou o fragmento SQL equivalente — nunca reimplemente a
comparação resposta=gabarito do zero, ou duas telas podem discordar sobre o
percentual de acerto do mesmo aluno na mesma avaliação.

## Dois sistemas de configuração paralelos

- `App\Models\Configuracao` (tabela `configuracoes`) — schema **herdado da
  aplicação legada**, compartilhado com ela: aparência do portal (título,
  logo), CAPTCHA (reCAPTCHA/hCaptcha) e SMTP/template do e-mail de 2FA.
  Chave/valor genérico, cacheado inteiro em `Cache::remember` e invalidado
  em cada escrita (`Configuracao::definir`). As três chaves sensíveis
  (`recaptcha_secret_key`, `hcaptcha_secret_key`, `smtp_pass`) são
  criptografadas em repouso (`Crypt::encryptString`/`decryptString`) — se
  adicionar outra chave sensível aqui, inclua-a na lista `CHAVES_SENSIVEIS`
  do model.
- `App\Models\ConfiguracaoSistema` (tabela `configuracoes_sistema`) —
  **exclusiva deste app**, nada de legado: repositório/config do
  autoatualizador, retenção de backup, status do job de backup em
  andamento.

Os dois têm a mesma API (`valor()`/`definir()`), mas são tabelas e
propósitos diferentes — não confundir qual delas uma nova configuração
deveria usar. Regra prática: se o app legado (ou um DBA olhando o banco
compartilhado) precisaria enxergar/editar esse valor, é `Configuracao`; se é
estritamente deste app, é `ConfiguracaoSistema`.

## O Dashboard lê o escore de `resultado_resumos`, não de `respostas`

Nota, ausência e escore da análise psicométrica de cada respondente estão em
`resultado_resumos` (`acertos`, `ausente`, `acertos_itens`, `itens_considerados`),
calculados em `ResumoResultadoService::recalcular()`. Não refaça esse cálculo
varrendo `respostas` numa tela nova — junte com o resumo. Quem grava
`respostas`/`questoes` por fora do fluxo normal (teste, script) chama `recalcular()`
depois. Agregados pesados vão em `CacheDeAnalise::lembrar()`: só **arrays** (nunca
objeto/stdClass — o cache não desserializa classes), e bump em `VERSAO` ao mudar a conta.

## Ausente, vínculo de aluno e CPF

- **Ausente** = nenhuma resposta de verdade na prova inteira. Nunca deduza "ausente"
  de `acertos = 0`: uma questão `dar_ponto` credita até quem faltou. O corte seguro
  é `acertos <= nº de questões dar_ponto` e, depois, conferir em `respostas`.
- **CPF** é guardado só com dígitos (`alunos`, `respostas`, resumos). Um resultado
  pode ter só CPF (RA nulo) — a maioria dos reais tem. Para achar a pessoa use
  RA **e** CPF **e** `aluno_id`, nunca só RA.
- Status de matrícula: `AlunoMatricula::vigenteNoPeriodo()` (ativa ou período
  cumprido: aprovado/reprovado) vs. saída (transferida, cancelada, trancada...).
  Só a saída é encerrada pela `Dt. Ocorrência`.
- `resultado_resumos.curso` nunca é zerado por falta de aluno: sem aluno conhecido
  o curso já gravado fica (ver `CursoDoResultadoService`).

## Painel da reitoria: só agregado, por categoria ou avaliação

O **painel** do reitor (`role = rector`, `/reitoria/*`) enxerga todos os cursos, então
é só agregado — nunca nome, RA ou CPF (`ReitorTest` procura nome de aluno nas telas).
Não ligue o reitor a `Avaliacao::visivelPara` nem ao BI/lista de alunos. As rotas dele
ficam em `perfil:reitor,administrador` e as de coordenador/administrador em
`perfil:administrador,coordenador` (lista **positiva**).

A única porta para dado nominal é a **visão do coordenador de um curso**: o reitor
escolhe UM curso (`ReitorCursoController`, sessão `visao_de_curso`) e o middleware
`VisaoDeCursoDoReitor` — antes de `perfil:`, só no grupo de rotas do coordenador —
troca o usuário da requisição por `Admin::comoCoordenadorDe([curso])`, uma cópia em
memória (nunca salva) com perfil de coordenador. Não reescreva controller para o
reitor: tudo que o coordenador vê já passa por `cursos()`/`visivelPara`. É só leitura,
auditada (`reitor.visao_de_curso`), com aviso fixo no layout; e só vale para o reitor
(não para administrador, que perderia as telas de gestão). `/reitoria` solta a visão.

- Não use o atributo `hidden` sozinho em elemento com classe de display do Tailwind
  (`flex`, `block`...): a classe vence. `reitor/_base.blade.php` define
  `[hidden] { display: none !important }` por isso — mantenha. Idem o `relative` do
  `<main>` em `layouts/app.blade.php`: sem ele, os `sr-only` (position absolute) das
  tabelas esticam a rolagem da PÁGINA e sobra uma área vazia no fim (em qualquer tela).
- O recorte (`ReitorDashboardService::contexto()`) é período letivo (ou "todos") + categoria
  ("todas" por padrão) + avaliação ("todas" da categoria). Em geral uma avaliação é de
  um curso, então a categoria reúne os cursos. "Todas as categorias" mistura provas não
  comparáveis: `avaliacao.mistura` liga o aviso nas telas — não esconda esse aviso.
  Categorias são uma árvore: escolher o pai vale para as filhas (`cadeia` de cada
  avaliação), e "mistura" olha a RAIZ — filhas do mesmo pai são a mesma prova.
  Só a evolução (`ReitorEvolucaoService`) cruza semestres, seguindo o mesmo filtro.
- Estatística de percentual (média, mediana, quartis, faixas, patamares) sai de um
  histograma em décimos de ponto (`Histograma`), vindo de um `GROUP BY percentual`
  — não carregue resultados para o PHP e não aproxime por faixas de 1 ponto (59,9%
  não é proficiente).
- **Previstos** (`Previstos`) vêm das matrículas vigentes e NÃO entram no cache
  (`CacheDeAnalise` não enxerga matrícula); só o que depende de resumos/respostas é
  cacheado, e só como array.
- Corte de proficiência e meta de participação ficam em `ConfiguracaoSistema`
  (`reitor_corte_proficiencia`, `reitor_meta_participacao`) — o corte entra na chave
  do cache.

## "Estudante em risco" é uma regra só (`RegraDeRisco`)

Quem está em risco é definido pela administração (Configurações → Estudante em risco; padrão: média de acerto abaixo de
60% ou 2 faltas, "ou"/"e"), com sobreposição por avaliação (`avaliacoes.risco_acerto`: vazio = padrão, 0 = fora do critério
de acerto; `risco_ignora_falta`). A lista do coordenador (`CoordenadorAlunosService::classificar`), as notificações e o
painel da reitoria (`ReitorRiscoService`, em SQL) usam essa MESMA regra e precisam contar as mesmas pessoas
(`RegraDeRiscoTest` compara os dois). Não reintroduza um limiar fixo (60, 2 faltas) em tela nova — leia `RegraDeRisco::atual()`.
O critério de acerto é "média do estudante < média dos limites das provas que ele fez"; a queda de nota do coordenador é um
sinal à parte. A regra e a de cada avaliação entram na chave do cache dos agregados.

## Cronograma de atividades e o perfil de colaborador

`collaborator` é o quarto perfil de `admins.role` (e, como o `rector`, **exige migration que acrescente o valor ao ENUM do MySQL
legado** — o SQLite dos testes não pega). Ele só alcança `/colaboracao/*` (grupo `perfil:colaborador,administrador`) e o perfil;
`PerfilPermitido` leva um GET dele em tela de coordenador de volta ao cronograma, o resto é 403. Não o ligue a
`Avaliacao::visivelPara` nem a nada de aluno/resultado.

O cronograma (`CronogramaItem` → `cronograma_item_cursos` → `CronogramaPendencia`) tem duas faces: o colaborador/administrador grava
(`ColaboradorCronogramaController`, `ColaboradorPendenciaController`) e o coordenador **só lê** (`CronogramaController`, no grupo do
coordenador, então o reitor na visão de curso também lê). Curso se compara por `NomeCurso` (acento/caixa não distinguem); atividade de
outro curso é 404. Pendência é histórico do coordenador: não mude curso/data do registro; o colaborador/administrador PODE excluí-la
(`ColaboradorPendenciaController::destroy`, que grava o conteúdo na auditoria antes), mas não se exclui atividade (nem se tira o
curso dela) enquanto tiver pendência — `SalvarCronogramaItemRequest` e `ColaboradorCronogramaController::destroy` barram. Calendário
e lista usam os MESMOS filtros (`CronogramaService::filtros()/itens()`): tela nova de atividades reaproveita isso, e o filtro de
curso/situação vale para os cursos do recorte do coordenador, nunca para os de outros. Tela nova que
mostre pendência ao coordenador filtra pelos cursos dele (`CronogramaPendencia::dosCursos`).

## Acompanhamento de alunos e visão do reitor

`acompanhamentos` é um LOG de eventos: nunca atualize nem apague um registro (o estado atual é o último). A observação é
dado sensível — vai só para quem coordena o `curso` do registro (`AcompanhamentoService::ultimos()/historico()` filtram
por `NomeCurso::variantes`) e NUNCA para `AtividadeLogger`. Qualquer rota nova que GRAVE algo no grupo do coordenador
já é bloqueada para o reitor pelo middleware `VisaoDeCursoDoReitor` (só métodos seguros); não crie atalho em volta disso.

## Plano de ação: números do servidor, só agregado

O plano de ação (`planos_acao`, ver README) nasce de um dado do painel do coordenador e é lido pelo **colaborador**, que não
enxerga resultados nem alunos. Por isso:

- **Os números nunca vêm do navegador.** O link do ícone (`plano/_botao.blade.php`) só leva *onde* o coordenador estava (curso,
  período, categoria, visual, item); `PlanoAcaoOrigemService::montar()` recalcula tudo, só para os cursos dele, e o `store` o chama de
  novo. Participação, meta e proficiência vêm do `ReitorDashboardService` (via `PlanoAcaoIndicadoresService`) para o plano e o painel
  nunca discordarem; ele cai silenciosamente no período/categoria mais recente quando o pedido não existe, e o serviço trata isso como
  "sem resultado" (não troque por um número de outro recorte com o rótulo deste).
- **Só dado agregado** em `planos_acao` (incluindo o `contexto` JSON): nunca nome, RA ou CPF de aluno. Há teste (`PlanoAcaoTest`).
- **Estado muda só em `PlanoAcaoService`** (grava o evento, a auditoria e avisa o coordenador). `plano_acao_eventos` é log só de
  acrescentar, como `acompanhamentos`. "O que falta para enviar" é `PlanoAcaoChecagem` — a conferência do `plano-acao-form.js` é só um
  adiantamento da tela; mantenha as duas em sintonia.
- Quem vê: coordenador, planos dos cursos dele (`PlanoAcao::scopeVisivelPara`; plano de outro curso é **404**); colaborador e
  administrador, só os **enviados** (rascunho é privado: 404). Rota nova que GRAVA no grupo do coordenador já é barrada para o reitor
  pelo `VisaoDeCursoDoReitor`; os controllers conferem `emVisaoDeCurso` mesmo assim (`coordenadorQueEscreve()`).
- O número do menu (`comAjustes`/`aguardandoAnalise`) roda em TODA página: mantenha-o barato (sem `NomeCurso::variantes`).

## Perfil (`admins.role`) falha fechado

Nunca escreva `! $usuario->ehCoordenador()` para decidir "então é administrador":
um `role` desconhecido não é coordenador **nem** administrador. Use
`ehAdministrador()` para liberar e `ehCoordenador()` para restringir; `papel()`
devolve `null` para perfil inválido e o middleware `papel-valido` encerra a sessão.
Os escopos `Admin::administradores()/coordenadores()` já normalizam
(`LOWER(TRIM(role))`) — não compare `role` cru em SQL.

## Coordenadores: sempre filtrar por `visivelPara`

`admins.role` (`superadmin`/`coordinator`) é coluna **legada**. Qualquer
listagem/consulta de avaliação que um coordenador possa alcançar precisa
passar por `Avaliacao::visivelPara($usuario)` / `acessivelPara()` (curso em
comum via `avaliacao_cursos`, ou acesso excepcional via `avaliacao_usuarios`)
— rota nova que expõe dados de avaliação **e** fica fora do grupo
`somente-admin` em `routes/web.php` tem que fazer essa checagem. Dado nominal
de aluno (ranking, respondentes, busca) fica só no grupo `somente-admin`.

**Painel do coordenador (`/painel`, `/painel/alunos`, `/painel/alunos/{aluno}`,
`/painel/desempenho`).** Ficam FORA do grupo `somente-admin` de propósito (o dado
nominal é só dos alunos do curso dele), então cada controller (`PainelController`)
manda administrador para `avaliacoes.index`. Todas as telas partem de
`CoordenadorDashboardService::escopo()`; o curso do resultado é sempre
`resultado_resumos.curso`. A ficha de um aluno de outro curso é **404** (não 403) e
só mostra provas feitas nos cursos do coordenador. Ligar resultado a aluno é por
`aluno_id`, depois RA e CPF — `aluno_chave` (CPF ou RA) pode mudar entre provas.
O CPF nunca vai à tela nem à URL. Limiares da situação do aluno ficam em
constantes de `CoordenadorAlunosService`.

**Curso do aluno é por matrícula e por prova.** Nunca filtre/agrupe resultado
por `alunos.curso` (é só a matrícula *atual*): use `resultado_resumos.curso` — o
curso em que o aluno estava na data da prova (`CursoDoResultadoService`, a
partir de `aluno_matriculas`). Em consultas sobre `respostas`/`resultado_metricas`
use `EscopoCurso::restringir($q, 'alias.', $avaliacaoCodigo)` (o prefixo SEMPRE
qualificado); em `resultado_resumos`, `restringirResumos`. Comparar nomes de
curso é `NomeCurso::chave()/estaEm()/variantes()` — acento/caixa não distinguem
cursos. `avaliacao_cursos` tem `origem` (`auto` = refeito a cada importação,
`manual` = nunca apagado).

Cuidado ao juntar tabelas legadas com as novas: `alunos`/`admins` têm
collation diferente (`utf8mb4_general_ci`) de `resultado_resumos`/`respostas`
(`utf8mb4_unicode_ci`) — comparar `alunos.ra` com `resultado_resumos.ra`
direto no SQL dá "Illegal mix of collations" no MySQL (SQLite não pega). Junte
por `aluno_id` (INT) ou passe os valores como parâmetros.

## Consultas cruas em `respostas` precisam de `deleted_at IS NULL`

`respostas` usa soft delete ("Excluir resultados do período"). `Resposta::query()`
já filtra sozinho; **`DB::table('respostas ...')` não** — acrescente
`->whereNull('r.deleted_at')` (ver `RespostasExcluidasTest`, que compara todos os
visuais antes/depois de apagar). Sem isso, o resumo e os gráficos passam a
discordar sobre a mesma turma.

## O atualizador substitui o código — cuidado com a fronteira de confiança

`UpdateService` baixa um zip e sobrescreve a aplicação. Por isso:

- O repositório vem **só do `.env`** (`config('sistema.repositorio')`). Nunca
  volte a ler repositório de `ConfiguracaoSistema`/formulário: quem alcança o
  painel passaria a poder rodar código arbitrário no servidor.
- `composer install` roda com `--no-scripts --no-plugins` (+ `package:discover`
  depois). Não remova as flags.
- Aplicar uma atualização pede a senha do admin e é auditado; a verificação de
  assinatura (`ATUALIZACAO_EXIGIR_ASSINATURA`) passa por
  `exigirAssinaturaSeConfigurado()` tanto em `atualizar()` quanto em
  `baixarParaConfirmacao()` — um caminho novo que aplique código precisa chamá-la.

## Portal: o 2FA depende da pré-autenticação da sessão

`/portal/verificar` e `/portal/reenviar` só funcionam com `portal_pre_auth` na
sessão (gravada por `consultar` depois de CPF + data de nascimento). Não receba
o CPF dessas rotas por campo/corpo — foi exatamente o furo corrigido (qualquer
CPF dava 2FA sem primeiro fator). O código de 2FA é guardado como HMAC
(`VerificacaoEmail::hashDoCodigo`); nunca grave nem logue o código em texto, e
reenviar significa **gerar um código novo**. O limite de falhas é por IP
(`RateLimit2faService`) **e** por CPF (`RateLimiter`, `MAX_FALHAS_POR_CPF`).

## Blade: `@include` não compartilha variáveis atribuídas

Variável criada num `@php` de um partial **não existe** nos outros partials,
mesmo incluídos pela mesma view (a `bi.blade.php` foi quebrada em `bi/_*.blade.php`
e `bi/scripts/_*.blade.php` e isso já gerou um gráfico vazio em silêncio, porque
`$x ?? []` esconde o erro). Se mais de um partial precisa do mesmo valor derivado,
calcule num helper PHP (`App\Support\EvolucaoDoDashboard`) e chame dos dois
lados — ou passe via `@include('...', [...])`. Ao mexer na view, compare o HTML
renderizado antes/depois, não só se a página abre.

## Acessibilidade das views

- Texto pequeno em fundo claro: `text-slate-500`/`-emerald-700`/`-amber-700`/
  `-red-600`; nunca `text-slate-400`, `-emerald-600`, `-amber-600`, `-red-500`
  ou `text-primary` (`AcessibilidadeTest` varre as views e falha). Em fundo
  escuro, `text-slate-400` (não `-500`).
- Botão só de ícone precisa de `aria-label`; ícone decorativo, `aria-hidden="true"`.
- Caixa de erros de formulário: `id="erros-do-formulario" data-resumo-erros
  role="alert"` (o `partials/erros-de-campo` marca os campos inválidos e foca o primeiro).
- Gráfico novo em `<canvas>`: o `graficos-acessiveis.js` descreve sozinho a partir
  do `Chart.getChart(canvas)` e do título (`h1–h3`) mais próximo acima; use
  `data-titulo` no canvas se o título da seção não servir.
- O ponto de quebra do menu lateral é `max-width: 767.98px` (o `md:` do Tailwind
  começa em 768px) — não volte para `768px`.

## Convenções de teste

- **Roda também em MySQL** (`composer test:mysql`, banco `*_testes` obrigatório — `tests/TestCase.php` recusa outro
  nome, porque `RefreshDatabase` apaga tudo). O SQLite não enxerga o banco legado: ENUM em `admins.role`, collations
  `general_ci` vs `unicode_ci`. Mudou perfil de usuário, coluna de texto juntada entre tabelas legadas e novas, ou
  migration em `admins`/`alunos`? Rode o MySQL também e estenda `tests/Mysql/BancoLegadoMysqlTest`. Teste que usa
  recurso só de SQLite (TRIGGER...) precisa de `markTestSkipped` fora do SQLite.
- `tests/Unit/`: `PHPUnit\Framework\TestCase` puro, sem Laravel — pra
  classes sem dependência de banco/container (`Anulacao`, `EnvFileWriter`,
  `SpreadsheetReader`, `AlunoVinculoResolver`).
- `tests/Feature/`: `Tests\TestCase` (boota o app) + `RefreshDatabase`
  (SQLite `:memory:`) — tudo que passa por rota/controller/banco.
- Nada de teste mexe no ambiente real: backups/uploads vão para
  `config('sistema.backup_dir')`/`config('sistema.uploads_dir')` (descartáveis,
  ver `phpunit.xml`), o modo de manutenção é `array`, e o marcador do instalador
  (`INSTALL_MARKER`) fica vazio. Não use `storage_path('app/backups')` nem
  `public_path('uploads')` em teste.
- Um teste que grava no `.env` real do ambiente é perigoso — `EnvFileWriter`
  sempre recebe um caminho de arquivo descartável em teste, nunca o `.env`
  do processo de teste.

## Outras pegadinhas já resolvidas (não reintroduzir)

- **`SpreadsheetReader::readSpreadsheet`**: não usar
  `setReadDataOnly(true)` no reader do Xlsx — esse modo pula o parse de
  qual aba estava ativa (`workbookView`/`activeTab`) e `getActiveSheet()`
  cai pra aba 0, errado justamente pro caso comum de planilha de exemplo
  com aba de instruções antes da aba de dados.
- **`admins.role` é ENUM no MySQL legado**: um valor novo de perfil exige migration que
  acrescente o valor ao ENUM (ver `allow_rector_role_on_admins_table`); o SQLite dos
  testes usa VARCHAR e NÃO pega esse erro.
- **FK pra `admins`/`alunos`**: use
  `$table->integer('coluna')->nullable()` + `$table->foreign(...)`, nunca
  `$table->foreignId()` (gera `BIGINT UNSIGNED`, mas o `database.sql`
  legado define esses IDs como `INT` simples — MySQL exige o mesmo tipo
  dos dois lados de uma FK; SQLite não pega esse erro, só MySQL real).
- **Rotas com segmento literal + wildcard no mesmo prefixo**: registre o
  literal antes do wildcard (ex.: `/lixeira/restaurar-tudo` antes de
  `/lixeira/{id}`), senão o wildcard casa primeiro.

## `questoes.periodo_minimo` é meta, não regra de acerto

`periodo_minimo` (1–20, NULL = vale para todos) diz a partir de qual período do curso se espera que o aluno acerte a
questão. É só classificação: **não** entra em `Anulacao`, em `resultado_resumos` nem na nota — quem estiver abaixo do
período pode acertar sem problema. Para comparar com o aluno use o período dele **na data da prova** (`aluno_matriculas`
via `CursoDoResultadoService`; `PeriodoCurso::ordinal()` normaliza "3º", "P3", "3º PERÍODO"), nunca o período atual de
`alunos`. Não confundir com `questao_matrizes.periodo` (período da disciplina na matriz curricular, texto livre) — o
import distingue pelo cabeçalho ("Matriz (período)" nunca vira período mínimo).

O boletim do aluno usa essa meta em `AnaliseConsolidadaService::metaPorPeriodo()`: o mínimo esperado de cada avaliação é a
fatia da prova que já cabe no período do aluno em `respostas.periodo` (agregado em SQL por avaliação × período × área × meta),
e **60%** quando a prova não traz a meta (`MINIMO_PADRAO`). Na tela da avaliação (`resultadoAvaliacao`), a trilha de estudo e as lacunas/consolidados pulam as questões "à frente"
(`PeriodoCurso::aFrente()`) e o desempenho por área usa a meta de cada área. O mapa de domínio por área usa a mesma regra por célula
(`mapaDominio()['areas'][]['esperados']`): abaixo do mínimo = amarelo. O texto "Como você foi nesta prova" (`LeituraDaProva`) é para o estudante: sem jargão (nada de "Bloom", "TRI",
"percentil") e sem coloquialismo (nada de "deu trabalho", "dá para", "pra", "bônus") — registro claro e cordial, nem técnico nem informal
(`LeituraDaProvaTest` confere). O mesmo vale para os textos dos cards de resumo e da leitura rápida. O boletim **não compara o aluno com a
turma** nos cards de resumo e fala em percentual, não em "pontos" (`InsightService`) — não reintroduza.

## Portal do aluno: tour guiado e rodapé

O tour (`public/assets/js/portal-tour.js`) é declarado na view da tela, em `@section('tour')` + `portal._tour` (chave e passos com
`alvo` CSS, `titulo`, `texto`); o layout só liga o botão "Refazer tour da página" e carrega o JS quando a seção existe. Tela
nova do portal: adicione `data-tour="..."`/ids nos elementos e uma `@section('tour')`. Alvo ausente ou oculto é pulado — mas
confira o HTML renderizado (`PortalTourTest`). Texto do tour segue o registro do restante do portal (claro e cordial, sem
gíria nem jargão). A tela de login do aluno (`portal/consulta`) não tem tour. O atalho para a área administrativa é só dela (`@section('acesso-administrativo')`): não o recoloque no layout para todas as telas.

Na visão geral do coordenador, "Dentro do esperado" (`CoordenadorDashboardService::alunosDentroDoEsperado()`) conta, entre os presentes
com nota, quem alcançou o mínimo do PRÓPRIO período (fatia da prova que cabe nele, meta `periodo_minimo`; período irreconhecível =
60%). Prova sem meta em nenhuma questão não tem esperado: a coluna mostra só a média. A lista nominal de alunos em atenção não
fica na visão geral (fica na aba Alunos).

Na tela de desempenho do coordenador, `CoordenadorDashboardService::gerar(..., $opcoes)` só liga o que é caro com `detalhado => true`
(esperado por período, Bloom, tema, abas por período); `ComparacaoSemestresService`/notificações chamam sem opções e não pagam isso. O
filtro de período do curso é aplicado em PHP sobre agregados por período (cacheados), não em SQL. Os gráficos (`painel-desempenho.js`)
são criados ao abrir o `<details>` da categoria: um canvas em `<details>` fechado tem tamanho zero.

A comparação entre semestres (`ComparacaoSemestresService`) olha para o PERÍODO DO CURSO, não para a pessoa: não volte a parear o mesmo aluno
nos dois semestres. Ela chama `gerar()` com `estrito => true` (um filtro de período do curso ausente num semestre fica vazio, não é
ignorado) e `campos => false` (não precisa de Bloom/tema).
