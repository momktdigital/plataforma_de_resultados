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
- **FK pra `admins`/`alunos`**: use
  `$table->integer('coluna')->nullable()` + `$table->foreign(...)`, nunca
  `$table->foreignId()` (gera `BIGINT UNSIGNED`, mas o `database.sql`
  legado define esses IDs como `INT` simples — MySQL exige o mesmo tipo
  dos dois lados de uma FK; SQLite não pega esse erro, só MySQL real).
- **Rotas com segmento literal + wildcard no mesmo prefixo**: registre o
  literal antes do wildcard (ex.: `/lixeira/restaurar-tudo` antes de
  `/lixeira/{id}`), senão o wildcard casa primeiro.
