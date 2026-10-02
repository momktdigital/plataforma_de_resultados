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
