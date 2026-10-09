# Avaliações

Aplicação Laravel responsável pelo cadastro de **Avaliações**, import de
**Questões/Gabarito** (com metadados pedagógicos opcionais) e import de
**Resultados**, além do portal público do aluno (2FA), login administrativo,
CRUD de alunos e configurações. Substitui o antigo módulo experimental "DI"
— o nome foi abandonado porque o sistema passou a suportar avaliações de
vários tipos, não só o Diagnóstico Institucional.

Este repositório já foi um app legado em PHP puro (mesmas funções — portal
público do aluno, 2FA, login administrativo, CRUD de alunos e
configurações), reescrito do zero nesta aplicação Laravel; o código antigo
foi removido depois que todas as suas funções foram portadas e validadas em
produção (ver histórico do repositório caso precise consultar o código
legado). O schema herdou as tabelas `admins` e `alunos` desse app legado
sem alterá-las, e adiciona as tabelas novas descritas abaixo.

## Modelagem de dados

| Tabela | Campos obrigatórios | Observação |
|---|---|---|
| `categorias` | `nome` | Árvore (`categoria_pai_id` aponta pra outra `categorias`, nulo = raiz) — agrupa o boletim do aluno no portal por categoria/subcategoria. |
| `avaliacoes` | — (código gerado automaticamente) | `nome`/`tipo`/`link_comentado` são só identificação, totalmente opcionais. `categoria_id` (opcional) e `data_avaliacao` (opcional, distinta de `created_at`) alimentam o agrupamento e a ordenação/filtro por data no portal. |
| `questoes` | `avaliacao_codigo`, `numero`, `gabarito` | O resto que é **um valor só** por questão (Bloom, Miller, dificuldade, `periodo_minimo`) é opcional e vira coluna. `periodo_minimo` (1–20, NULL = vale para todos) é a meta por período: a partir de qual período do curso se espera que o aluno acerte a questão — só classificação, não altera acerto, nota nem `resultado_resumos`. O que pode ter **vários valores** (matriz da avaliação, DCN, Portaria INEP, PPC) vira linhas em `questao_referencias` — ver abaixo. Suporta soft-delete. |
| `questao_matrizes` | `questao_id` | Uma questão pode estar em mais de um período/disciplina/código de matriz — por isso é uma tabela filha (1:N), não colunas fixas. |
| `questao_referencias` | `questao_id`, `tipo`, `valor` | Referências da questão a matriz de avaliação/DCN/Portaria INEP/PPC — uma linha por valor, `tipo` diz a qual grupo pertence. Existiam como colunas numeradas (`dcn_a`, `dcn_b`...); viraram tabela porque é um grupo repetitivo (0..N valores), não um atributo de valor único, e o número de colunas era só um palpite (ver "Performance e escala" abaixo). |
| `respostas` | `avaliacao_codigo`, (`ra` OU `cpf`), `questao_numero` | Formato longo: uma linha por resposta de um respondente a uma questão, num período (`periodo`, opcional — default `''`). `aluno_chave` é uma coluna gerada pelo banco (`COALESCE(cpf, ra)`) usada no índice único que evita duplicar a mesma resposta num reimport; `ra`/`cpf` também têm índice próprio (ver "Performance e escala"). Chama-se `respostas`, não `resultados`, porque a aplicação legada já tem uma tabela `resultados` no mesmo banco. |
| `resultado_metricas` | `avaliacao_codigo`, (`ra` OU `cpf`), `nome_metrica` | Métricas agregadas por aluno+avaliação+período que não são resposta de uma questão (ex.: "Nota de Redação", "Total") — equivalente ao antigo JSON `resultados.notas_finais`, como linhas em vez de colunas dinâmicas. Mesma lógica de índice em `ra`/`cpf` que `respostas`. |
| `resultado_resumos` | `avaliacao_codigo`, `aluno_chave`, `periodo` | Cache de leitura: acertos/total/percentual já calculados por aluno+avaliação+período, mantida por `App\Services\ResumoResultadoService`. Nunca gravada diretamente pela aplicação — ver "Performance e escala". |

`admins`, `alunos`, `gabaritos` e `resultados` (a tabela legada, não confundir
com `respostas`) têm migrations próprias aqui, mas elas só criam a tabela
**se ela ainda não existir** (`Schema::hasTable`) — em produção, onde essas
tabelas já existem via `database.sql` da aplicação legada, elas são no-ops.
Isso permite rodar `php artisan migrate` com segurança tanto em produção
(banco já populado) quanto em um ambiente novo/de testes (banco vazio, onde
`gabaritos`/`resultados` legados ficam disponíveis para o comando de migração
de dados abaixo poder ler algo).

> **Se for referenciar `admins`/`alunos` com foreign key numa migration
> nova:** use `$table->integer('coluna')->nullable()` + `$table->foreign(...)`
> — **não** `$table->foreignId()` (que gera `BIGINT UNSIGNED`). O
> `database.sql` legado define `admins.id`/`alunos.id` como `INT` simples;
> o MySQL exige que os dois lados de uma FK tenham o mesmo tipo, e essa
> troca já causou `errno 150` em produção (ver `avaliacoes.criado_por`,
> `respostas.aluno_id`, `resultado_metricas.aluno_id` como exemplo).
> Testes rodam em SQLite, que não enforce isso — só um banco MySQL real
> pega esse tipo de erro, por isso testamos manualmente contra MariaDB
> antes de publicar qualquer migration que toque nessas duas tabelas.

## Performance e escala

Pensado para o sistema processar milhões de resultados sem travar. O ponto
de partida é notar que nem toda tabela cresce do mesmo jeito: `avaliacoes` e
`questoes` crescem com o número de avaliações/questões cadastradas (limitado
— dezenas de milhares, no máximo); `respostas` e `resultado_metricas` crescem
com **aluno × avaliação × período × questão/métrica** — uma única avaliação de 100
questões com 10.000 respondentes já é 1 milhão de linhas sozinha. É nessas
duas que qualquer decisão de schema/índice precisa ser pensada para escala;
nas outras, quase qualquer desenho funciona igual de bem.

- **Coluna vs. tabela filha em `questoes`:** um atributo opcional de **valor
  único** por questão (`bloom_nivel`, `dificuldade_tri`...) é uma coluna
  nullable — normal, não custa nada em espaço/performance. Um atributo que
  pode ter **vários valores** por questão, mas foi modelado como colunas
  numeradas (`dcn_a`, `dcn_b`, `ppc_a..d`) é um grupo repetitivo disfarçado —
  vira tabela filha (`questao_referencias`), senão qualquer novo valor além
  do que "a/b/c/d" previu exige alterar a tabela de novo. Mesma lógica já
  usada em `questao_matrizes` para período/disciplina/código.
- **Índice em `ra`/`cpf` de `respostas`/`resultado_metricas`:** o boletim do
  portal busca essas tabelas filtrando por `ra` OU `cpf`, sem saber de
  antemão qual avaliação ou qual das duas colunas está preenchida. Sem um
  índice em cada coluna, essa busca é um full table scan — inofensivo com
  poucas linhas, mas é exatamente a consulta que trava com milhões delas
  (a mais usada do sistema, ainda por cima). `Schema::index('ra')` +
  `Schema::index('cpf')` bastam para o MySQL fazer um index-merge na busca.
- **`resultado_resumos` como cache de leitura:** calcular "quantas questões
  o aluno acertou nesta avaliação" exige comparar cada resposta com o gabarito —
  um JOIN entre `respostas` e `questoes`. Fazer isso a cada vez que um aluno
  abre o boletim significa refazer esse JOIN toda hora, mesmo que ninguém
  tenha respondido nada novo desde a última consulta. `resultado_resumos`
  guarda o resultado desse cálculo (uma linha por aluno+avaliação+período) e é
  recalculada por `App\Services\ResumoResultadoService::recalcular($avaliacaoCodigo)`
  — sempre **para uma avaliação só** (nunca a tabela toda), com uma única query
  agregada (`JOIN` + `GROUP BY` + `SUM(CASE...)`), depois de qualquer coisa
  que mude o resultado dela: import de respostas/gabarito (`ResultadoImportController`,
  `QuestaoImportController`, `LegadoController`), edição/exclusão manual de
  uma questão (`QuestaoController`, `LixeiraController`) ou exclusão/
  restauração de um período inteiro (`RespondenteController`). O boletim
  (`ResultadoConsultaService::buscarPorAluno`) só lê dessa tabela — nunca
  escaneia `respostas` para montar a lista. A tela de detalhe de uma avaliação
  (`buscarUmaAvaliacao`) ainda busca as respostas individuais pra montar a grade
  de Q1..Qn (isso é sempre uma avaliação só, nunca escala com o total do
  sistema), mas usa o mesmo resumo pra acertos/total/percentual — garante
  que a lista e o detalhe sempre mostrem o mesmo número, e tem um fallback
  que recalcula na hora caso o resumo não exista por algum motivo.
- **O que o resumo guarda além de acertos/total/percentual** (Dashboard rápido):
  `ausente` (prova inteira em branco — `Resposta::semRespostaSql`, sobre TODAS as
  respostas do aluno), `acertos_itens` e `itens_considerados` (acertos e nº de
  respostas só nos itens da análise psicométrica: questão com gabarito e **sem**
  anulação em qualquer modo). Com isso o Dashboard, a presença, a lista nominal, a
  evolução e o painel do coordenador **não varrem mais `respostas` para saber a nota ou
  a presença de cada aluno** — antes o mesmo escore era refeito mais de dez vezes por
  visita. Consequência: **quem grava `respostas`/`questoes` direto (teste, script,
  migration) precisa chamar `ResumoResultadoService::recalcular()` depois**, como já
  faz todo fluxo do sistema. Ao subir esta versão, a migration
  `2026_10_05_120000_add_itens_da_analise_to_resultado_resumos_table` preenche as colunas
  recalculando o resumo de todas as avaliações (leva de segundos a alguns minutos,
  conforme o volume; ~1,5 min com 1,4 milhão de respostas).
- **Uma varredura por tipo de análise.** `RelatorioAdminService::contagensPorQuestao()` lê
  `respostas` UMA vez (por questão × resposta) e dela saem área, tema, bloom, miller,
  dificuldade e alternativas; `PsicometriaService::agregadosDosItens()` faz o mesmo para
  a análise de itens e as curvas características (agrupa por questão × escore, e os quintos
  e os grupos de 27% são decididos no PHP).
- **Cache dos agregados pesados** (`App\Support\CacheDeAnalise`): as duas varreduras acima,
  a simulação de remoção de itens, o desempenho por área do painel do coordenador e a
  média da turma no boletim ficam em cache e são invalidados pelos DADOS (impressão
  digital de `resultado_resumos` + `questoes` + um carimbo de "geração" que
  `ResumoResultadoService`, `CursoDoResultadoService` e o model `Questao` atualizam) —
  não por relógio (o TTL de 6 h é só rede de segurança). Regras: o valor cacheado é
  **array/escalar** (o cache não desserializa objetos; os testes rodam o cache em
  memória com serialização para pegar isso) e, ao mudar a conta de um agregado
  cacheado, **incremente `CacheDeAnalise::VERSAO`**. `php artisan cache:clear` limpa tudo.
- **Lista nominal paginada.** O Dashboard traz as primeiras 100 linhas no HTML; o resto
  vem sob demanda (botão "Mostrar mais" ou rolando a lista) por
  `GET /avaliacoes/{codigo}/bi/alunos/linhas` (`BiListaController::linhas`, mesmas regras
  de acesso da planilha). A ordem é total e estável (presentes primeiro, maior percentual,
  empate pela chave do aluno), então paginar não repete nem pula ninguém. A planilha
  `.xlsx` continua levando todos.
- Medido no banco local (avaliação de 171 mil respostas): Dashboard de 10,5 s para
  3,0 s na primeira visita e 0,7 s com cache (HTML de 4,7 MB para 0,7 MB); painel do
  coordenador de Medicina de 7,0 s para 1,3 s / 0,2 s; lista de avaliações de 1,0 s para
  0,1 s; boletim do aluno de 1,1 s para 0,4 s com cache.

## Alunos (`/alunos`)

CRUD completo de alunos — porta `admin/alunos.php`/`aluno_form.php` da
aplicação legada para cá. Listagem com busca por RA/CPF/Nome (ordenável por
cabeçalho de coluna) e paginação, criação e edição exigem RA, CPF (11
dígitos, usado como login no portal público) e Data de Nascimento; Curso,
Câmpus e E-mail são opcionais. **Excluir um aluno (botão "Excluir") remove só
o cadastro de acesso** — RA/CPF/nome continuam em `respostas`/
`resultado_metricas`/`resultado_resumos` (é assim de propósito: a estatística
agregada da avaliação não pode sumir só porque um cadastro foi apagado). Para
apagar de vez a identidade de alguém desses dados também, ver "LGPD" abaixo.

### LGPD: anonimizar um aluno

```bash
php artisan aluno:anonimizar --ra=2026001
php artisan aluno:anonimizar --cpf=12345678909
```

Atende um pedido de exclusão/anonimização: em uma transação,
`App\Services\AnonimizacaoAlunoService`

0. Descobre **quem é a pessoa**: busca o cadastro em `alunos` pelo RA e/ou CPF
   informado e amplia os identificadores (RA informado ⇒ também o CPF do
   cadastro, e vice-versa) além do vínculo por `aluno_id`. Isso importa porque a
   maioria dos resultados reais foi importada **só com CPF** (RA nulo): sem
   essa etapa, `--ra=` não achava nada. O CPF pode vir com máscara
   (`123.456.789-09`); as linhas são achadas com e sem ela.
1. Troca RA e CPF por um token anônimo (`ANON-XXXXXXXXXX`) em toda linha de
   `respostas`/`resultado_metricas` que bater com qualquer desses
   identificadores — sempre gravando o token no campo `ra` e zerando `cpf`,
   mesmo que a linha original só tivesse `cpf` preenchido, pra unificar as duas
   formas de identificar a mesma pessoa num token só (`aluno_chave`, coluna
   gerada pelo banco como `COALESCE(cpf, ra)`, se recalcula sozinha). Exceção:
   se a mesma pessoa tem uma linha pelo RA **e** outra pelo CPF para a mesma
   questão/avaliação/período (dado duplicado de dois imports), unificar violaria
   o índice único — então cada identificação ganha o seu token (o comando
   informa todos).
2. Reconstrói `resultado_resumos` das avaliações afetadas chamando
   `ResumoResultadoService::recalcular()` de novo — é puro cache de leitura,
   então não precisa (nem deve) ser editado na mão. O **curso** de cada
   resultado é devolvido ao resumo novo (a pessoa continua nos números do curso).
3. Apaga qualquer `verificacoes_email` pendente com aquele CPF.
4. Apaga o cadastro em `alunos`, se ainda existir (não precisa existir — dá
   pra anonimizar o histórico de alguém cujo cadastro já foi excluído antes
   pela tela).
5. **Trilha de auditoria:** o registro `aluno.anonimizado` guarda só o token
   (nunca RA/CPF) e os registros **antigos** de `atividades` que citam a pessoa
   (por exemplo o `aluno_chave` de "respondente.excluido") têm o RA/CPF trocado
   pelo token. RA só é trocado quando o valor é exatamente igual — um RA curto
   dentro de outro texto não é reescrito. É a única edição que a trilha admite.

**O que fica preservado de propósito:** o número de respostas por questão,
a nota/percentual de cada linha anonimizada e qualquer outro aluno da mesma
avaliação — só a identidade daquela pessoa deixa de ser rastreável. **O que
isto não cobre:** backups já gerados anteriormente (ver "Backups" abaixo,
que também carregam o `.env` e o dump completo do banco) e qualquer log de
aplicação fora do banco — expurgar esses é um processo manual, fora do
escopo deste comando.

Só existe via CLI (exige acesso ao servidor) de propósito — um pedido LGPD é
raro e formal o bastante pra não precisar de botão na tela, e destrutivo o
bastante pra não ganhar um.

### Importação de matrícula (`/alunos/importar`)

Reconstrói, do lado do servidor, o antigo fluxo client-side de
`admin/upload_alunos.php`/`alunos_di_process.php` (parsing em
`admin/js/di_parser.js`). Upload de uma planilha `.csv`/`.xlsx`/`.xls`:

- **Obrigatórias por linha:** RA (aceita `Matricula`/`MatriculaAluno`), Per.
  Letivo (ex.: `2026/1`, aceita `2026.1`), Curso, Período (ex.: `5º`, aceita
  `P5`/`5`) — linha sem alguma das quatro é ignorada, sem derrubar o import.
- **Opcionais:** Cód. Perfil, Nome, Status/Situação, Turma, Dt. Nascimento,
  CPF, Email.
- RA é o identificador único (mesma coluna `UNIQUE` da tabela `alunos`):
  reimportar atualiza em vez de duplicar. Campos de identidade
  (nome/CPF/nascimento/email/cód. perfil) só são sobrescritos quando a
  planilha traz um valor novo — não apagam um cadastro já completado
  manualmente; Status/Per. Letivo/Período/Turma sempre refletem a última
  planilha importada.
- Cada curso visto na planilha é registrado em `cursos` (só para telas de
  referência/filtro — não há hoje nenhuma tela de gestão de cursos).
- **CPF com máscara** (`123.456.789-09`) é gravado só com dígitos — é assim que
  os resultados importados e o login do portal procuram o aluno.
- **Planilha antiga não desfaz o estado novo.** A mesma matrícula (aluno ×
  curso × período letivo) já gravada com um fato mais recente que o da planilha
  fica como está: reimportar uma planilha exportada *antes* de um cancelamento
  não reverte `CANCELADA` para `ATIVA`. "Mais recente" = a data do último fato
  da linha (`Dt. Ocorrência`, senão `Dt. Ativação`); sem datas para comparar, ou
  com datas iguais, vale a planilha importada agora. Limite conhecido: uma
  reativação cuja `Dt. Ativação` não foi atualizada na origem parece "antiga" e
  não substitui a saída já registrada.

> **Correção em relação ao protótipo legado:** `alunos_di_process.php` já
> gravava `cod_perfil`/`status`/`periodo_letivo`/`periodo`/`turma` e uma
> tabela `cursos`, mas nenhuma migration em nenhum dos dois apps chegou a
> criar essas colunas/tabela — a importação de matrícula sempre falhava em
> produção. As migrations daqui resolvem isso e também tornam `cpf`/
> `data_nascimento` nullable em `alunos` (a planilha de matrícula nunca
> garantiu os dois; só o cadastro manual em `/alunos` exige ambos, por
> serem a credencial de login do aluno no portal público).

## Usuários (`/usuarios`) e Perfil (`/perfil`)

Porta `admin/usuarios.php` (CRUD de usuários: listar, criar, editar, excluir —
não é possível excluir a própria conta logada) e `admin/perfil.php` (troca de
senha, exige confirmar a senha atual) para cá. Mesma tabela `admins`
herdada do schema da aplicação legada, então uma conta já cadastrada
antes de o app legado ser removido continua funcionando aqui normalmente.
(`/administradores` antigo redireciona para `/usuarios`.)

A tela tem quatro abas, distinguidas pela coluna legada `admins.role`:

- **Administradores** (`superadmin`, ou sem role): acesso total.
- **Coordenadores** (`coordinator`): acesso limitado aos cursos a que estão
  vinculados. Veem só o **painel da coordenação** (`/painel`: visão geral,
  alunos do curso e desempenho), a lista de
  avaliações (`/avaliacoes`, sem criar/editar/excluir), o **Dashboard** de
  cada uma (somente leitura, só com os alunos dos cursos dele) e o próprio
  perfil. Todo o resto responde 403 (middleware `somente-admin`, ver
  `routes/web.php`) e a busca global some do menu.
- **Reitoria** (`rector`): só leitura, **todos os cursos**. O **painel da reitoria**
  (`/reitoria`, ver abaixo) é **agregado, sem dado nominal de aluno**. Não tem curso
  vinculado, nem acesso direto a avaliações, questões, alunos ou ao Dashboard de
  uma avaliação: o middleware `perfil:` (lista positiva de perfis,
  `PerfilPermitido`) leva um GET dele em `/avaliacoes`, `/painel`... de volta ao
  painel da reitoria, e o resto responde 403. A exceção é a **visão do
  coordenador de um curso** (próximo parágrafo), aberta de propósito para
  análise aprofundada.
  Cadastro: e-mail obrigatório (é para ele que vai o código de acesso), senha
  opcional, nenhum curso.
- **Colaboradores** (`collaborator`): montam o **cronograma de atividades** e registram as
  pendências (ver "Cronograma de atividades"). Não veem avaliações, resultados nem alunos. Cadastro como o da
  reitoria: e-mail obrigatório, senha opcional, nenhum curso.

**Login.** A tela de login tem duas abas. **Administrador**: usuário e senha
(a senha é obrigatória só para ele). **Coordenação / Reitoria** (coordenador, reitor e colaborador): informa usuário ou e-mail
e recebe um **código de 6 dígitos** no e-mail cadastrado (`/login/codigo`,
`LoginPorCodigoService`) — sem senha. O código vale 10 minutos e uma única vez,
3 erros o invalidam, o reenvio tem espera crescente (1, 2, 5, 10 min) e só o
**hash** (HMAC) do código fica no banco (`login_codigos`). A resposta nunca
revela se o usuário existe. Para isso o coordenador precisa ter **e-mail**
(obrigatório no cadastro; a senha é opcional — sem ela o `password_hash` recebe
um valor aleatório que ninguém conhece) e o SMTP do portal precisa estar
**ativado** (Configurações → Portal público → E-mail). Administrador não entra
por código. Um coordenador que tenha senha cadastrada também pode entrar por ela: na
aba **Coordenador** há o botão **Entrar com senha** (`/login?modo=coordenador`,
usuário + senha — a mesma rota `login` do administrador) e, na tela de senha, o
botão **Receber código por e-mail** para voltar. Entrando por senha ou por código,
o coordenador cai direto no seu **painel** (`/painel`) e o reitor no **painel da reitoria**
(`Admin::rotaInicial()`). Quem não tem senha
cadastrada vê a mesma mensagem genérica de "usuário ou senha inválidos".

**Perfil desconhecido não acessa nada.** `Admin::papel()` normaliza `admins.role`
(caixa e espaços não distinguem): `superadmin` ou vazio ⇒ administrador,
`coordinator` ⇒ coordenador, `rector` ⇒ reitor, **qualquer outro valor ⇒ sem perfil**. Antes,
tudo que não fosse exatamente `coordinator` virava administrador completo
(falha aberta). Agora o login recusa a conta, o middleware `papel-valido`
(grupo `auth:admin` de `routes/web.php`) encerra uma sessão que ficou com perfil
inválido e `somente-admin` exige `ehAdministrador()`. Contas assim somem das
abas da tela de usuários — corrija o `role` direto no banco.

Os cursos do coordenador ficam em `admin_cursos` (por **nome**, igual a
`alunos.curso`); `admins.curso` (legado, um só) guarda o primeiro, só por
compatibilidade.

### Quem vê qual avaliação

Na configuração da avaliação (`/avaliacoes/{codigo}`, "Acesso aos
resultados"):

1. **Curso(s)** (`avaliacao_cursos`): preenchido **automaticamente** a cada
   importação/recálculo de resultados com o curso **de cada resultado** (ver
   "Curso é da matrícula" abaixo) — `AvaliacaoCursoService`, chamado por
   `ResumoResultadoService::recalcular()` e pela importação de matrícula. O que
   é automático (`origem = auto`) é **refeito** a cada vez: entra o curso que
   passou a ter resultado e sai o que deixou de ter (uma avaliação de
   Odontologia não fica "de Medicina" só porque um aluno já foi de Medicina).
   O que um administrador marca na tela (`origem = manual`) nunca é apagado
   pelas importações.
2. **Usuários com acesso aos resultados** (`avaliacao_usuarios`): acesso
   excepcional para um coordenador sem aluno do curso dele na avaliação.

Um coordenador vê a avaliação se ela tem algum dos cursos dele **ou** se ele
está na lista excepcional (`Avaliacao::scopeVisivelPara()`); fora disso, 404.
Administradores veem todas. No BI, o coordenador vê **só os alunos dos cursos
dele**: cada serviço de análise recebe um `EscopoCurso` (`paraCursos()`) que
restringe toda consulta a `respostas`/`resultado_resumos`/`resultado_metricas`
ao **curso do resultado** (`resultado_resumos.curso`); os filtros de
turma/demografia também só listam alunos do curso.

Nomes de curso que só diferem em **acento, caixa ou espaços** (ADMINISTRACAO =
ADMINISTRAÇÃO) são o mesmo curso em todo o sistema (`App\Support\NomeCurso`):
a lista de seleção mostra uma grafia só (a acentuada) e a visibilidade vale
para qualquer grafia gravada em `alunos.curso`/`aluno_matriculas.curso`.

### Curso é da matrícula, não do aluno

O mesmo aluno pode mudar de curso (transferência) ou ter dois ativos ao mesmo
tempo, e o coordenador de cada curso só enxerga as provas **que o aluno fez
naquele curso**. Por isso:

- **`aluno_matriculas`** guarda uma linha por matrícula (aluno × curso ×
  período letivo, com status, `Dt. Ativação` e `Dt. Ocorrência`), alimentada
  pela importação de matrícula e **nunca apagada** por uma importação nova —
  então planilhas antigas (2026/1...) podem ser importadas depois, em qualquer
  ordem. Um aluno transferido no meio do semestre tem duas linhas no mesmo
  período letivo (Medicina `TRANSFERIDA` com data de ocorrência; Odontologia
  `ATIVA`). O número de linha da primeira coluna da planilha nunca é gravado.
- **`alunos`** continua com a matrícula **atual** (um curso por RA), agora
  escolhida do histórico e não pela ordem das linhas do arquivo: período
  letivo mais recente e, nele, ativa > aguardando > transferida/cancelada/
  trancada/desistente (todos esses status significam "não é mais do curso").
  **`APROVADO`, `APROVADO_PARCIALMENTE` e `REPROVADO` não são saída:** são
  alunos que cumpriram o período (milhares nos dados reais) e contam como
  ativos (`AlunoMatricula::vigenteNoPeriodo()`); a `Dt. Ocorrência` deles é só a
  data do lançamento do resultado e não encerra a matrícula.
- **`resultado_resumos.curso`** é o curso do aluno **na época da prova**,
  decidido por `CursoDoResultadoService` (determinístico, refeito sempre que o
  histórico ou o resultado muda): (1) matrícula válida na data da avaliação —
  de `Dt. Ativação` até `Dt. Ocorrência` (ou o fim do período letivo); (2)
  matrícula do mesmo período letivo; (3) curso marcado **à mão** na avaliação
  (desempata dois cursos ativos); (4) curso atual. **Preencha a data das
  avaliações**: sem data, só os critérios 3 e 4 se aplicam.
  Resultado cujo aluno não está no cadastro (excluído, ou importado antes da
  matrícula) é ligado por `aluno_id`, depois por RA e por CPF — a matrícula
  importada depois alcança resultados que ainda não tinham `aluno_id`. Se
  mesmo assim não há aluno, o curso **já gravado é mantido** (um recálculo não
  zera mais o curso, que faria o coordenador perder a prova).
- Reimportar uma planilha de matrícula refaz o curso dos resultados desses
  alunos e os cursos das avaliações onde aparecem (`MatriculaImportService`).
- Cabeçalhos das datas: exatamente `Dt. Ativação` (início da matrícula) e
  `Dt. Ocorrência` (fim, nas que não estão ativas); outras colunas de data
  (ingresso, status...) são ignoradas. Sem elas a matrícula vale pelo período
  letivo inteiro.
- A edição manual em `/alunos` altera só o cadastro **atual**; o histórico vem
  das planilhas (a tela de edição mostra o histórico, somente leitura).

### Painel do coordenador (`/painel`)

Painel de gestão do curso, com saudação (Bom dia/Boa tarde/Boa noite, como no
boletim do aluno) e quatro seções, todas com o mesmo recorte de **curso** (se
tiver mais de um) e **período letivo** (2026/1, 2026/2 — derivado da data da
avaliação, igual ao portal; padrão = o mais recente; avaliação **sem data** usa
o início do nome — `2026/2 - Diagnóstico...` — ou o período letivo em que a
maioria dos alunos dela estava matriculada):

- **Visão geral** (`/painel`): alunos, quantos precisam de atenção (com a regra em vigor), presença e
  nº de avaliações; destaques em texto; avaliações mais recentes, com a coluna **Dentro do esperado** (alunos presentes que
  alcançaram o mínimo esperado para o período deles — a fatia da prova que cabe no período, meta `periodo_minimo` —, ex.:
  `5 (20%)`; numa prova sem a meta, só a média no lugar); alunos por período do curso, com presença e **% de acerto**; atalhos.
  A lista nominal de quem precisa de atenção fica na aba Alunos.
- **Alunos do curso** (`/painel/alunos`): todos os alunos do semestre (quem
  fez ao menos uma avaliação **mais** os matriculados nele, mesmo sem
  resultado), com presença, média, última nota, tendência e **situação**. Busca
  por nome/RA, filtro por situação, período do curso e categoria, ordenação
  (inclusive "quem precisa de atenção primeiro") e **planilha .xlsx** do que
  está filtrado (a exportação é registrada na atividade).
- **Ficha do aluno** (`/painel/alunos/{id}`): trajetória do aluno no semestre —
  situação e motivos, nota em cada avaliação **vs. média do curso** e posição,
  gráfico de evolução por categoria, desempenho por área vs. curso e as
  matrículas dele no curso. Aluno de outro curso responde 404; resultados de
  provas feitas em OUTRO curso (aluno transferido) não aparecem.
- **Desempenho** (`/painel/desempenho`): a análise detalhada, **por categoria
  de avaliação** (provas de categorias diferentes não são comparáveis). Filtros:
  curso, período letivo, **categoria** e **período do curso**. Cada categoria é um
  **dropdown** (fechado por padrão; abre sozinho com uma só categoria ou ao filtrar por ela) com: média, **alunos abaixo do
  desempenho esperado** (o aluno que não alcançou o mínimo do próprio período — a fatia da prova que cabe nele, meta
  `periodo_minimo`; sem a meta nas questões, o cartão volta a ser "Abaixo de 60%"), presença e avaliações; gráficos de
  **evolução** (alunos que atingiram o esperado a cada avaliação; sem a meta, a média), **área**, **nível de Bloom** e **tema**,
  cada um com as abas **Geral** e **Por período** (do curso); e, no fim, a tabela das avaliações da categoria. A "avaliação
  anterior" é a anterior **da mesma categoria**, mesmo de outro período letivo. Os gráficos só são criados quando a categoria
  é aberta (`public/assets/js/painel-desempenho.js`).

- **Comparar semestres** (`/painel/comparativo`): o período escolhido contra
  outro (padrão: o anterior). Alunos, quem precisa de atenção, presença e nº de
  avaliações dos dois períodos (filtros de curso, categoria e **período do curso**, estes
  valendo para os dois semestres); e, **dentro de cada categoria** (que só se
  compara com ela mesma), média, **alunos abaixo do esperado** (abaixo de 60% quando
  a categoria não traz o mínimo por período), presença, desempenho por área e por
  período do curso, com a variação. Em vez de parear a pessoa, o quadro **"Alunos
  dentro do esperado, por período do curso"** olha para o período: em cada período do
  curso (1º, 2º...), quantos alunos atingiram o esperado (ou 60%) em cada semestre e se o
  período subiu, ficou estável ou caiu (menos de 5 pontos é estável). Categoria que só
  existiu em um dos períodos é avisada, não comparada. O menu do coordenador segue a
  ordem Visão geral · Desempenho · Avaliações · Comparar semestres · Alunos do curso.

**Notificações** (`/notificacoes`, só coordenador). Quando resultados de uma
avaliação do curso são importados (`ImportarResultadosJob`),
`NotificacaoCoordenadorService` avisa cada coordenador que enxerga a avaliação
(`Avaliacao::visivelPara`), só com números do curso dele: *novos resultados*
(presença e média, com a variação), *média caiu* (5 pontos ou mais frente à
anterior da mesma categoria), *presença baixa* (abaixo de 85%) e *alunos que
passaram a precisar de atenção* (quem está em atenção com a avaliação e não
estava sem ela, no semestre, até a data dela). Cada aviso tem uma chave, então
reimportar atualiza o aviso — e só o reabre como "não lido" se o conteúdo mudou.
O coordenador abre o aviso (que já o marca como lido), marca um ou todos como
lidos e vê o contador no sino e no menu; a visão geral destaca os não lidos.
Para quem já tinha dados importados: `php artisan notificacoes:gerar [avaliacao]`.

*Avisos do navegador:* o sino se atualiza sozinho (consulta `/notificacoes/resumo`
a cada minuto) e, se o coordenador clicar em **Ativar avisos no navegador**, o
navegador mostra um aviso quando chega notificação nova — **com o sistema aberto
numa aba**. Aviso com o navegador **fechado** (Web Push de verdade) exige chaves
VAPID, um service worker, uma tabela de inscrições, a biblioteca
`minishlink/web-push` e HTTPS no servidor; não está implementado.

**Situação do aluno** (`CoordenadorAlunosService::classificar`, sempre com os
motivos à vista): *Em atenção* = a **regra de risco da instituição** (padrão: média abaixo de
60% ou 2 faltas ou mais; ver *Estudante em risco*) ou queda de 20 pontos ou mais entre as duas últimas avaliações da mesma
categoria (a queda é um sinal à parte);
*Ausente em tudo* = faltou em todas; *Destaque* = média ≥ 80% e nenhuma falta;
*Regular* = o resto; *Sem resultado* = matriculado sem avaliação registrada.
**Ausentes** (prova inteira em branco) ficam fora das médias e entram só em
presença/faltas. Na lista, sem filtro de categoria, a média é a simples entre
as avaliações do semestre (a "média geral" do boletim do aluno); a tendência só
compara avaliações da mesma categoria.

**Acompanhamento de alunos.** Na ficha do aluno (e em "Alunos do curso") o coordenador registra **contatado**,
**em acompanhamento** ou **resolvido**, com observação (até 1000 caracteres) e data (`acompanhamentos`,
`AcompanhamentoService`). Cada registro é um evento que só se acrescenta: o estado atual é o último e a ficha mostra o
histórico (data, situação, quem registrou). A lista de alunos mostra o selo do último registro e filtra por
**Acompanhamento** (sem registro / contatado / em acompanhamento / resolvido); o painel mostra o selo nos alunos em
atenção; a planilha de alunos leva duas colunas. O registro pertence ao **curso** em que foi feito e só é visível a quem
coordena esse curso; a observação nunca vai para a auditoria (só o fato do registro) e some junto com o cadastro do
aluno (FK `ON DELETE CASCADE`, então também na anonimização LGPD). O reitor na visão do curso **vê** o histórico, mas
não registra: essa visão é só leitura de verdade (o middleware recusa qualquer requisição que não seja de leitura).

`CoordenadorDashboardService` agrega tudo em SQL sobre `resultado_resumos`
e, para presença e áreas, sobre `respostas` restrito aos alunos do curso e ao
período. `CoordenadorAlunosService` lê só `resultado_resumos` (uma linha por
aluno×avaliação×período) e toca `respostas` apenas na ficha de UM aluno.

## Cronograma de atividades (`/colaboracao`, `/cronograma`)

A checklist de auditoria ROC/ROD (planilha "Tabela-base da Auditoria") vira um **calendário por coordenador**. Quem monta é o
**colaborador** (`admins.role = 'collaborator'`, quarta aba de `/usuarios`; entra por código no e-mail como o coordenador; só alcança
o cronograma, a análise dos planos de ação (ver abaixo) e o próprio perfil — o resto responde 403 e um GET fora do lugar volta para `/colaboracao`). O administrador também
gerencia (sem ele ninguém corrigiria nada se o colaborador saísse).

- **Atividade** (`cronograma_itens`): data, rotina (**ROD** = docentes, **ROC** = coordenação, **Auditoria** = conferência final),
  projeto/atividade e "o que vou conferir". Os **cursos a que se aplica** ficam em `cronograma_item_cursos`, cada um com a sua
  situação (Aguardando, Em acompanhamento, Pendente, Resolvido). Curso fora da tabela = "Não se aplica" da planilha. A atividade
  aparece no calendário do coordenador de cada curso marcado, e só nele.
- **Pendências** (`cronograma_pendencias`, a aba "Registro de Pendências"): curso, data do registro, pendência, encaminhamento,
  prazo, situação e responsável, **vinculadas à atividade**. O colaborador registra e atualiza a situação; o coordenador **só
  visualiza** (calendário, lista "Pendências registradas" e a tela da atividade, só com os cursos dele). Serve de histórico: curso e
  data do registro não mudam (`resolvida_em` marca quando foi resolvida). O colaborador (e o administrador) pode **excluir** uma
  pendência — o conteúdo todo fica na auditoria (`cronograma.pendencia_excluida`). Uma atividade com pendência não pode ser
  excluída nem perder o curso da pendência enquanto ela existir. Cada gravação vai para a auditoria (`cronograma.*`).
- **Coordenador** (`/cronograma`): duas visões, alternadas por `?visao=` e com os mesmos filtros (busca em projeto/descrição, rotina,
  situação, curso se tiver mais de um; na lista, também intervalo `de`/`ate`): **calendário** mensal (domingo a sábado; no celular
  vira agenda por dia) e **lista** paginada por data, com a situação de cada curso e as pendências abertas. A situação filtrada vale
  para os cursos DELE (a pendência de outro curso não faz a atividade aparecer). Números de pendências em aberto/vencidas. Atividade de outro curso é **404**, não 403. O reitor na visão do curso enxerga o
  cronograma daquele curso (só leitura, pelo mesmo middleware).
- **Colaborador** (`/colaboracao`): o mesmo calendário e a mesma lista, com todas as atividades e um "+" em cada dia, o formulário da atividade,
  a tela da atividade (situação por curso + pendências) e `/colaboracao/pendencias` (todas, com filtros).

**Carga inicial pela planilha.** `php artisan cronograma:importar "Tabela-base da Auditoria ROC ROD.xlsx"` lê as abas "Checklist de
Auditoria" e "Registro de Pendências" (`CronogramaImportService`). Sem `--gravar` é só uma **simulação** que mostra como cada coluna de
curso da planilha foi mapeada; o que não casa pelo nome (sem acento/caixa, ou com erro de digitação óbvio de até 2 letras) exige
`--mapa="Coluna=CURSO"` (vários cursos com `|`) ou `--ignorar="Coluna"`, e `--criar-cursos` cadastra os nomes do mapa que ainda não
existem. "Não se aplica" tira o curso da atividade, célula em branco = Aguardando, e a pendência se liga à atividade pela
rotina + processo + curso. Pode ser repetido: o que já existe não é duplicado nem sobrescrito. Ex. da carga feita:

```bash
php artisan cronograma:importar planilha.xlsx --mapa="Medicina Veterinária Integral=MEDICINA VETERINARIA" --mapa="Odontologia Integral=ODONTOLOGIA" --mapa="Odontologia Integral2=ODONTOLOGIA" --mapa="Cursos EAD=Cursos EAD" --mapa="Cursos Semipresenciais=Cursos Semipresenciais" --criar-cursos --gravar
```

Leituras em `CronogramaService`; o recorte por curso compara pelo nome sem acento/caixa (`NomeCurso`). Os cursos selecionáveis são os
de `Curso::nomesDisponiveis()` (vindos da matrícula): um nome que a planilha usa e não existe lá (por exemplo, "Cursos EAD")
precisa existir como curso para poder ser marcado.

## Plano de ação (`/painel/planos`, `/colaboracao/planos`)

O resultado da avaliação vira **ação pedagógica** dentro da própria plataforma. O coordenador inicia um plano a partir de um dado do
painel, percorre o roteiro **dado → causa → ação**, envia ao **colaborador** (que aprova, pede ajustes ou recusa, sempre com
justificativa) e, depois de aprovado, acompanha a execução até o encerramento. O roteiro é o do "Assistente interativo" (Do dashboard à ação pedagógica)
(leitura orientada, Ishikawa, 5 Porquês, ação com verbo no infinitivo).

**Onde começa.** Cada visual e cada dado do painel tem o ícone de prancheta (`resources/views/plano/_botao.blade.php`, só para o
coordenador que pode gravar): os cartões de presença/média/abaixo do esperado, os gráficos de evolução, área, Bloom e tema, as
barras por período do curso e por curso, as linhas das tabelas de avaliações, os "destaques", os cartões e quadros de **Comparar
semestres** e os gráficos de área e Bloom do **Dashboard da avaliação**. Num visual com itens o ícone abre um menu — "plano sobre
o visual inteiro" ou sobre **um item** (uma área, um nível de Bloom, um período do curso, uma avaliação...); é uma lista de links
(teclado e leitor de tela), não depende de clicar no gráfico. O reitor na visão do curso e o administrador não veem o ícone.

**O que já vem preenchido** (`PlanoAcaoOrigemService`, `PlanoAcaoIndicadoresService`). A URL só diz *onde* o coordenador estava
(curso, período letivo, categoria ou avaliação, visual, item); **os números são recalculados no servidor**, só para os cursos
dele, e nunca vêm do navegador:

1. **Identificação do curso**, período letivo e categoria de avaliação;
2. **Participação atual**, **meta de participação** (a institucional, de Configurações) e **proficiência atual** — os mesmos
   números do painel da reitoria para aquele curso (`ReitorDashboardService`: participação = fizeram ÷ previstos pela matrícula;
   proficiência = % dos presentes no corte institucional, 60% por padrão), de modo que plano e painel nunca discordem;
3. o **dado do visual** (as linhas que o gráfico mostra, com o item clicado em destaque), guardado dentro do plano — o colaborador
   não enxerga o painel de resultados e analisa só com o que está no plano — e **sugestões de texto** para a etapa de leitura
   ("Inserir sugestão do painel"), sempre editáveis.

A **meta de proficiência** é a única que o coordenador pactua (sem ela o plano não tem como ser avaliado depois).

**O roteiro** (formulário único em `plano/form.blade.php`, cinco etapas; sem JavaScript as etapas ficam empilhadas e o envio
funciona igual): 1 *Ponto de partida* · 2 *Leitura do dado* (recorte, resultado, fragilidades, dados que sustentam) · 3 *Causas*
(Ishikawa em seis dimensões, priorização por Impacto × Evidência × Governabilidade de 1 a 3, 5 Porquês, causa-raiz acionável) ·
4 *Ações* (uma ou mais: verbo no infinitivo, como será executada, responsável, prazo, como se verifica a execução e os sinais de
aprendizagem) · 5 *Síntese e envio* (resumo ao vivo e lista do que falta). Cada etapa tem "Dúvidas desta etapa" (texto fixo do
roteiro, sem IA). "Salvar rascunho" a qualquer momento. `PlanoAcaoChecagem` é a fonte única do que **bloqueia o envio** (campo
vazio, ação sem verbo no infinitivo, prazo no passado, falta meta) e do que só **alerta** (ação depois da próxima avaliação, plano só de
reuniões, sem 5 Porquês, causa com pontuação baixa).

**Estados** (`PlanoAcao::STATUS`, transições só em `PlanoAcaoService`):

```
rascunho ──enviar──▶ em_analise ──aprovar──▶ aprovado (em execução) ──encerrar──▶ concluido
   ▲  ▲                 │  │                      └──cancelar──▶ cancelado
   │  └──retirar────────┘  └─recusar──▶ recusado (fim)
   └──── ajustes ◀──pedir ajustes─┘   (o coordenador edita e reenvia)
```

- **Análise** (`/colaboracao/planos`, colaborador e administrador): fila "Aguardando análise" (os mais antigos primeiro), quadro dos
  planos em execução, ações com prazo vencido e planos parados. Na tela do plano: critérios (o dado sustenta o resultado? a causa-raiz
  é acionável? as ações respondem à causa? prazos viáveis? há como verificar?) e a decisão. **Pedir ajustes e recusar exigem
  justificativa**; aprovar aceita observação. Os critérios não atendidos aparecem ao coordenador na devolução. Rascunho é
  privado do coordenador (o colaborador recebe 404).
- **Acompanhamento** (coordenador, plano aprovado): situação de cada ação (não iniciada, em andamento, concluída, cancelada), notas
  de andamento, prazo reprogramável. **Concluir uma ação e mudar um prazo exigem a nota** (evidência / justificativa); o novo prazo não
  pode ir para o passado. O plano é encerrado com uma síntese do que foi feito e aprendido, quando não há ação aberta; ou cancelado, com o motivo.
- **Funcionou?** (`PlanoAcaoResultadoService`): compara a linha de base (foto dos indicadores na criação) com o primeiro período
  letivo posterior com resultado na mesma categoria e com as metas. É um sinal, não uma prova — a tela diz isso.
- **Histórico** (`plano_acao_eventos`): log só de acrescentar (criado, enviado, decisão com justificativa e critérios, andamento,
  prazo reprogramado, comentário, encerrado...). Cada mudança de estado vai também para a auditoria (`plano_acao.*`).
- **Avisos**: a decisão e os comentários do colaborador chegam ao coordenador pelo sino (`notificacoes`, tipos `plano*`). O
  colaborador vê o número de planos aguardando no menu. `php artisan planos:lembretes` (agendado todo dia às 07:00) avisa o
  coordenador de ação que vence em até 7 dias, ação vencida e plano sem movimento há 30 dias — cada lembrete é criado uma vez.
- **Cópia**: um plano concluído, recusado, cancelado ou em execução pode virar um **novo rascunho** (indicadores recalculados, sem
  prazos nem situação) para o ciclo seguinte.

**Quem vê o quê.** Dois coordenadores do mesmo curso enxergam os mesmos planos (e qualquer um deles edita); plano de outro curso é
**404**. O reitor na visão do curso lê a lista e o plano (só leitura, pelo `VisaoDeCursoDoReitor`); fora da visão é redirecionado.
O colaborador e o administrador analisam planos enviados de qualquer curso. **O plano só guarda dado agregado** — nunca nome, RA ou
CPF de aluno (há teste para isso) — porque é lido por quem não enxerga alunos.

Tabelas: `planos_acao` (o plano e a foto dos indicadores), `plano_acao_acoes`, `plano_acao_eventos`.

## Painel da reitoria (`/reitoria`)

A visão **institucional**: todos os cursos lado a lado numa avaliação (em geral o
Diagnóstico Institucional do semestre). Para o reitor (e, para conferência, o
administrador); o coordenador não entra. **Só indicadores agregados**: nenhum
nome, RA ou CPF passa por essas telas (`ReitorTest` confere).

**Analisar um curso a fundo.** O item **Análise do curso** do menu lateral (e a aba
de mesmo nome no cabeçalho) lista todos os cursos, com busca e os números do recorte,
e o botão **Analisar** abre a **visão do coordenador daquele curso** (o nome do curso
na tabela de participação da Visão institucional também é um atalho): painel, alunos do curso e ficha do aluno,
desempenho, comparar semestres, lista de avaliações e Dashboard — exatamente as
telas (e as restrições) do coordenador, **somente leitura**. Por baixo,
`ReitorCursoController` grava o curso na sessão e o middleware
`VisaoDeCursoDoReitor` (grupo de rotas do coordenador, antes de `perfil:`) troca,
só naquela requisição, o usuário por uma cópia em memória com perfil de
coordenador desse curso (`Admin::comoCoordenadorDe()`; nada é gravado no banco).
Um aviso azul em toda tela e o item **Voltar à reitoria** no menu encerram a
visão; abrir o painel da reitoria também a encerra. Diferente do painel
agregado, essa visão **traz dados nominais** dos alunos do curso (CPF segue fora
da tela), por isso cada abertura vai para a auditoria (`reitor.visao_de_curso`).
Só o reitor tem essa entrada (o administrador já enxerga tudo).

**O recorte** vem da barra de filtros e fica na URL: **período letivo**
(`periodo=`, padrão o mais recente; "Todos os períodos" soma os semestres — útil
para categorias com avaliações em vários semestres, como os simulados — e cada
semestre usa os previstos das matrículas dele), **categoria** (`categoria=`, padrão "todas") e
**avaliação** (`avaliacao=`, padrão "todas" as da categoria; o campo só lista as
avaliações da categoria escolhida) e, se quiser, um **subconjunto de cursos**
(`cursos[]`, chaves de `NomeCurso::chave()`). Como em geral **cada avaliação é de um
curso** (a mesma prova aplicada a vários cursos vira várias avaliações da mesma
categoria), escolher a categoria reúne os cursos: os resultados das avaliações do
recorte são somados por curso. **As categorias são uma árvore** (ex.: "Diagnóstico
Institucional (DI) › Direito", "DI › Medicina"...): escolher o **pai** reúne as
avaliações de todas as filhas, e cada filha continua escolhível (o seletor mostra o
pai com o total e as filhas recolhíveis; os seletores de categoria e de avaliação
têm busca sem acento e teclado). Escolher uma avaliação mostra só o curso
dela. Se o recorte reúne **famílias de categoria diferentes** (categoria "todas" com
mais de uma raiz no período — "DI" e "Simulado", mas não "DI › Direito" e
"DI › Medicina"), as telas avisam: essas provas não são comparáveis em desempenho — a
participação segue valendo, mas proficiência, média e evolução devem ser lidas
com uma categoria escolhida. "Estudante" = um resultado (se a mesma pessoa tiver
resultado em duas avaliações do recorte, conta duas vezes). O curso de cada
resultado é `resultado_resumos.curso` (o curso na data da prova), nunca
`alunos.curso`.

Sete telas sobre o mesmo recorte (mais a lista **Análise do curso**, abaixo):

| Tela | O que mostra |
| --- | --- |
| **Visão institucional** | pontos de atenção; cartões (proficiência, acerto médio, participação, cursos na meta, com variação frente ao semestre anterior); **participação por curso** (previstos × fizeram × ausentes, dif. da média, distância e "alunos a mais" para a meta, períodos avaliados, ativos sem aplicação; ordenável e filtrável); **proficiência por curso**; **patamares** (60–80%, também por período de um curso); **mapa participação × proficiência** (bolhas, quadrante de atenção) |
| **Desempenho** | média e mediana; distribuição por faixa de acerto; **dispersão** (quartis e P10–P90 — caixa e haste); histograma do conjunto (com um curso à escolha); tabela estatística |
| **Trajetória no curso** | proficiência e acerto médio por período do curso (1º, 2º...), com seletor **Todos juntos / Minigráficos** (um gráfico pequeno por curso, mesma escala, total da visão como referência) **/ Um curso**; **mapa de calor curso × período** (média, proficientes, participação ou participantes); **crescimento** (pp por período, ajuste ponderado); **cobertura da aplicação** (onde a prova chegou e onde há aluno ativo sem aplicação) |
| **Competências** | níveis de **Bloom** (proficientes × não proficientes, mapa por curso, radar curso × conjunto) e **áreas** de conhecimento (ranking e mapa curso × área) |
| **Evolução entre semestres** | série institucional (proficiência, média, participação), **variação de cada curso** frente a um semestre à escolha, curso × semestre |
| **Análise dos itens** | análise **institucional** das questões (`ReitorItensService`): mapa acerto × discriminação, itens a revisar — **gabarito suspeito** (discriminação negativa e uma alternativa errada mais marcada que o gabarito), **problema da questão** (acerto muito baixo e não discrimina) × **lacuna de formação** (acerto muito baixo, mas discrimina bem), item fraco, baixo em todos os cursos —, por área e por avaliação (KR-20). O item é sempre (avaliação × número): cada avaliação cadastra as suas questões. Usa `PsicometriaService` e `RelatorioAdminService::analiseAlternativas()` (com cache próprio) e, quando a avaliação tem estudantes de vários cursos, calcula o item por curso |
| **Estudantes em risco** | **só agregado** (`ReitorRiscoService`): quantos estudantes se enquadram na **regra de risco da instituição** (ver *Estudante em risco*, abaixo) — por faltas, por acerto e no total —, por curso e por período do curso, com tendência frente ao semestre anterior. Funciona com uma aplicação só (a regra de faltas avisa quando o recorte tem menos aplicações que o limite); grupos com menos de 5 estudantes não mostram percentual. A lista nominal é do coordenador (o nome do curso abre a lista de alunos em atenção) e usa a MESMA regra |

**Drill-down.** Para o reitor, clicar numa barra/ponto/célula, ou no nome de um curso em qualquer tabela,
abre a análise **daquele curso, naquele recorte** (período letivo, período do curso, avaliação) na visão do
coordenador — `reitor.curso.abrir?curso=...&destino=alunos|desempenho|comparativo|bi&periodo_letivo=...`, com
destinos e valores validados. Cada **ponto de atenção** da Visão institucional tem o link para o quadro de onde saiu.

**Ver como tabela.** Todo gráfico tem, logo abaixo, **Ver como tabela** (os mesmos números, montados a partir do que o
gráfico mostra no momento — acessível e com **Copiar para planilha**).

**Relatório institucional.** O botão **Relatório (PDF / PowerPoint)** (`/reitoria/relatorio`) monta, para o recorte
atual, capa, resumo, participação, proficiência, desempenho, trajetória, competências e evolução, cada um com a
leitura. **PDF**: *Imprimir / salvar como PDF* (A4 retrato; o layout esconde menu e botões ao imprimir — qualquer tela
imprime sem o menu lateral). **PowerPoint**: gerado no navegador (PptxGenJS, via CDN), com os gráficos como imagem e as
leituras em cada slide. Cada abertura vai para a auditoria (`reitor.relatorio_aberto`).

Cada quadro tem **Sobre este quadro** (o que mede e como ler) e **Ver leitura** (o que os
números de agora dizem, com o tom bom/atenção/precisa de ação) — textos em
`ReitorLeituraService`. A planilha `.xlsx` (por curso e por período do curso) sai
em `/reitoria/exportar.xlsx` e a exportação é registrada na auditoria.

**Definições** (ficam nas telas também):

- **Proficiente** = acertou o **critério** (padrão 60%) ou mais. É um critério
  interno, **sem validação contra ENADE/ENAMED** — as telas dizem isso. As faixas
  e os patamares se ancoram nele (60% ⇒ <40, 40–49, 50–59, 60–69, ≥70).
- **Previstos** = matrículas **vigentes** (ativa ou período cumprido) no período
  letivo da avaliação, só nos períodos do curso em que ela foi aplicada
  (`Previstos`). Alunos ativos em períodos **sem aplicação** ficam à parte ("ativos
  sem aplicação") — a prova não chegou lá, o aluno não faltou. Nunca abaixo de quem
  tem resultado; sem matrícula importada, os previstos são os resultados (a
  participação vira presença, e a tela avisa). **Participação** = fizeram ÷ previstos.
- **Ausente** = prova inteira em branco (`resultado_resumos.ausente`): fora de
  média, mediana, faixas e proficiência.
- **Evolução**: acompanha o mesmo filtro de categoria nos outros períodos letivos
  (cada semestre reúne as avaliações dela); com uma avaliação escolhida, vale a
  escolhida no semestre dela e a de mais participantes da mesma categoria nos outros.
  É uma foto de cada semestre, não o acompanhamento dos mesmos estudantes — idem
  para a trajetória por período do curso (cada período é uma turma diferente).

**Estudante em risco** (`App\Support\RegraDeRisco`). Quem é "estudante em risco" é definido pela administração em
**Configurações do sistema → Estudante em risco** — uma regra só, usada pela lista de alunos em atenção do coordenador, pelas
notificações dele e pelo painel da reitoria (que conta, em agregado, as mesmas pessoas):

- **Acerto**: a *média de acerto* do estudante nas provas em que esteve presente fica abaixo de X% (padrão 60).
- **Faltas**: faltou a N ou mais aplicações — prova inteira em branco (padrão 2).
- Cada critério pode ser ligado ou desligado (um dos dois é obrigatório) e o operador diz se **basta um** ("ou") ou se é
  preciso **os dois ao mesmo tempo** ("e").
- **Por avaliação** (Avaliação → *Estudante em risco nesta avaliação*): limite de acerto próprio (em branco = padrão da
  instituição; **0** = a prova não entra no critério de acerto; pode também ligar o acerto só nela) e *Não contar falta
  nesta avaliação* (prova opcional). Com limites diferentes, compara-se a média do estudante com a **média dos limites** das
  provas que ele fez (com um limite só, é "média < limite").
- Gravado em `configuracoes_sistema` (`risco_acerto`, `risco_faltas`, `risco_operador`; vazio = critério desligado) e em
  `avaliacoes.risco_acerto` / `avaliacoes.risco_ignora_falta`. Mudar a regra é auditado (`configuracao.risco_alterado`) e os
  agregados em cache levam a regra (e a de cada avaliação) na chave: trocar o valor nunca serve número velho.

**Parâmetros** (Configurações do sistema → *Painel da reitoria*, só administrador;
`configuracoes_sistema`): `reitor_corte_proficiencia` (inteiro 30–90, padrão 60) e
`reitor_meta_participacao` (50–100, padrão 98).

**Como é calculado.** Tudo em SQL sobre `resultado_resumos`: um `GROUP BY` por curso ×
período × percentual (`percentual` tem 1 casa, então no máximo ~1000 linhas por
grupo) vira um **histograma** (`App\Support\Histograma`) e dele saem média, mediana,
quartis, faixas e patamares **exatos** em PHP — nada de trazer resultados um a um.
Bloom e área são a única varredura de `respostas` (`ReitorCompetenciasService`: um
`JOIN` + `GROUP BY curso × proficiente × Bloom × área`, regra de `Anulacao`),
cacheada por avaliação com `CacheDeAnalise` (só arrays). Os previstos vêm de
`aluno_matriculas` a cada visita (não entram no cache, que não enxerga matrícula).

## Configurações do portal público (`/sistema/portal`)

Porta `admin/configuracoes.php` (+ `api_test_smtp.php`/`api_verify_test_smtp.php`)
para uma aba própria em Configurações, gravando na tabela `configuracoes`
(schema herdado da aplicação legada):

- **Aparência:** título do site e logo (fundo claro/escuro). O arquivo é
  salvo em `public/uploads/logos/` (`App\Support\LogoUploader`) e servido
  como um arquivo estático comum, sem controller/rota dedicados — só o
  `basename()` do caminho salvo em `configuracoes.site_logo` é usado pra
  montar a URL (`asset('uploads/logos/'.basename(...))`), então uma
  instalação migrada de uma base com o valor antigo (`assets/img/xxx.png`,
  do layout da aplicação legada) continua exibindo a logo certa sem
  precisar atualizar a tabela.
- **CAPTCHA:** Google reCAPTCHA v2 ou hCaptcha (mutuamente exclusivos).
- **SMTP + template do e-mail de 2FA:** host/porta/usuário/senha (senha em
  branco não altera a já salva), remetente e template com `[NOME_DO_ALUNO]`/
  `[CODIGO]`, com o mesmo botão de "testar envio" do legado (envia um
  código de 6 dígitos via SMTP puro do Symfony Mailer — não usa o
  `config/mail.php` do Laravel, pois as credenciais vêm do banco, editáveis
  pelo admin).
- **Destino do código de 2FA do aluno** (`email_destino_2fa`): o código pode ir
  para o **e-mail pessoal** do aluno (o da matrícula — padrão) ou para o
  **e-mail acadêmico** (`RA@somos.unifaa.edu.br`, derivado do RA em
  `Aluno::emailParaCodigo()`), e então o aluno nem precisa ter e-mail pessoal
  cadastrado. A mesma escolha vale para o reenvio. O "SMTP ativado" também é o
  que liga o login por código do coordenador e o "esqueci minha senha".

## Categorias de avaliação (`/categorias`)

Árvore de categoria/subcategoria (sem limite de profundidade) para agrupar
o boletim do aluno no portal público — ex.: "Simulados" → "1º ao 4º
período" → cada avaliação dentro. Uma Avaliação sem categoria continua funcionando
normalmente, só aparece à parte no boletim ("Sem categoria"). Excluir uma
categoria é bloqueado enquanto ela tiver subcategorias ou avaliações vinculadas
(mude-as de categoria primeiro) — evita apagar organização por engano.

## Gestão de uma Avaliação (`/avaliacoes/{codigo}`)

Além dos imports, a tela de uma Avaliação agora cobre o que
`admin/avaliacao_editar.php`, `admin/resultados.php` e `admin/lixeira.php`
faziam sobre o schema legado (JSON por aluno), recalculado sobre o schema
normalizado (`questoes`/`respostas`/`resultado_metricas`):

- **Editar configurações:** nome, tipo, link do gabarito comentado,
  categoria (opcional — ver [Categorias de avaliação](#categorias-de-avaliação-categorias))
  e data em que a avaliação foi aplicada (opcional, distinta de "criada em").
- **Editor manual de gabarito:** cria ou corrige uma questão por vez (sem
  reimportar a planilha inteira); reenviar o mesmo número restaura uma
  questão excluída em vez de duplicar (mesma regra do import). Gabarito é
  obrigatório — a coluna é `NOT NULL`, mesma regra já aplicada pelo import
  em lote (uma linha sem gabarito é ignorada, não vira questão "anulada").
- **Questões críticas:** taxa de erro por questão entre os respondentes
  (`App\Services\EstatisticaErroService`), maiores erros primeiro. A conta é
  feita em SQL (`JOIN` + `SUM(CASE...)` agrupado por questão) — uma versão
  anterior trazia cada resposta pra PHP via `->chunk()`, que pagina por
  `LIMIT`/`OFFSET` e degrada conforme o offset cresce; numa avaliação com 167
  mil respostas isso estourava o `max_execution_time` (500 na tela).
- **Excluir avaliação:** soft-delete em cascata (questões, respostas, métricas).
- **Status "Anulada"** (prova inteira, diferente de anular uma questão): a
  avaliação deixa de existir para todo mundo, menos para o administrador — some
  do portal do aluno (boletim, detalhe e evolução), das evoluções e comparações
  do Dashboard e de tudo que o coordenador enxerga (painel, lista, Dashboard;
  `Avaliacao::naoAnulada()` / `visivelPara()`). O administrador continua vendo e
  abrindo pela lista, que mostra a etiqueta **Anulada**.

### Resultados por aluno (`/avaliacoes/{codigo}/respondentes`)

Uma linha por respondente + período (equivalente a uma linha de
`admin/resultados.php`, sem o JSON): busca por RA/CPF, filtro por período,
"ver" abre as respostas comparadas ao gabarito (verde/vermelho) e as
métricas daquele respondente. Excluir/restaurar é sempre por período dentro
da avaliação (`resultados.php` também excluía por período, mas globalmente —
aqui é escopado à avaliação porque o schema novo já separa por avaliação).

### Dashboard da avaliação (`/avaliacoes/{codigo}/bi`)

Substitui o dashboard de `admin/index.php` (Chart.js): histograma de
distribuição de % de acerto, radar de desempenho médio por disciplina (usa
`questao_matrizes.disciplina` — só aparece se o import de questões trouxe
essa coluna) e Top 5. Filtro por período. `App\Services\BiDashboardService`
concentra o cálculo — mesma correção de performance da EstatisticaErroService
acima: a nota de cada respondente e a média por disciplina são agregadas em
SQL, não varrendo um Collection do PHP com todas as respostas da avaliação.

**Estrutura da view.** `admin/avaliacoes/bi.blade.php` só compõe as seções:
cada bloco de HTML vive em `bi/_*.blade.php` e o JavaScript de cada gráfico em
`bi/scripts/_*.blade.php` (antes eram ~1.700 linhas num arquivo só). Regra que
custou um bug: **variáveis atribuídas num `@php` de um partial NÃO existem nos
outros** (cada `@include` tem escopo próprio). O que mais de um partial precisa
sai de um helper PHP testável — ex.: `App\Support\EvolucaoDoDashboard::periodosComEvolucao()`
alimenta tanto o HTML da evolução por categoria quanto o `porPeriodo` do script
(`tests/Feature/BiEvolucaoTurmaTest.php` lê todos os partials e tem um teste de
regressão para isso).

**Alunos da avaliação:** lista nominal com foto de perfil (a mesma do portal,
via `cod_perfil`; sem foto, a inicial), nome, RA, curso, período, turma e total
(acertos/total e %), ordenada pelo percentual — ausentes (prova inteira em
branco) vão ao fim, marcados. Respeita o filtro de período e, para o
coordenador, só traz os alunos dos cursos dele. O botão **Baixar XLSX**
(`/avaliacoes/{codigo}/bi/alunos.xlsx`, `ListaAlunosExportService`) exporta a
mesma lista (com a URL da foto), grava a exportação em
`atividades` (`avaliacao.lista_alunos_exportada`) e segue a configuração de
visualizações: se a lista (visual `ranking_completo`) estiver desligada para a
avaliação, o download também responde 404. Textos da planilha são gravados como
string (um nome começando em `=` não vira fórmula) e o RA preserva zeros à
esquerda.

## Lixeira (`/lixeira`)

Substitui `admin/lixeira.php`. Lista Avaliações excluídas (restaurar traz de
volta questões/respostas/métricas junto) e Questões excluídas
individualmente de avaliações que continuam ativas. Resultados/métricas
excluídos em lote por período são restaurados/expurgados direto na tela de
"Resultados por aluno" da avaliação (acima), não aparecem aqui um a um — seriam
potencialmente milhares de linhas por avaliação, ao contrário do schema legado
(uma linha por aluno).

## Portal público do aluno (`/portal`)

Porta `index.php` (SPA de consulta pública) + `api/consulta.php` +
`api/verify_2fa.php` + `api/resend_2fa.php` para cá — sem exigir login de
admin, server-rendered (formulários simples, sem SPA em JS). Reaproveita as
tabelas legadas `verificacoes_email` e `rate_limit_2fa` (migrations
próprias, mesmo padrão idempotente das demais tabelas compartilhadas), mas
busca o boletim no **schema novo** (`respostas`/`resultado_metricas`/
`questoes` por Avaliação), não no JSON de `resultados`/`gabaritos`.

**A raiz do site (`/`) é o portal público**: quem acessa deslogado cai
direto na tela de consulta; um administrador já logado é redirecionado
para `/avaliacoes`. O acesso à área administrativa (`/login`) fica só num link
discreto no rodapé das telas do portal — não é destacado na página.

Fluxo, igual ao legado até a verificação — mas o 2FA **não** é mais um
formulário solto que aceita qualquer CPF: depois do primeiro fator (CPF + Data
de Nascimento) o servidor guarda uma *pré-autenticação* na sessão
(`portal_pre_auth`: aluno, momento, validade de 15 min) e é ela que autoriza
`/portal/verificar` e `/portal/reenviar`. Esses dois ignoram qualquer `cpf`
enviado no corpo e não exigem (nem aceitam) o CPF em campo oculto. Sem o
primeiro fator, ou com ele expirado, voltam para `/portal`. Só depois do
segundo fator confirmado (ou direto, sem 2FA) entra a sessão do boletim
(`portal_aluno_id`) — para permitir uma URL própria do boletim (item 5 abaixo):

1. **`GET /portal`** — formulário de CPF + Data de Nascimento (ambos com
   máscara via IMask, igual ao cadastro manual de aluno) + CAPTCHA se ativo
   em Configurações → Portal público.
2. **`POST /portal/consultar`** — valida CAPTCHA (`App\Services\Portal\CaptchaVerifier`,
   chama o siteverify do Google/hCaptcha), localiza o aluno por CPF + data
   de nascimento. Se SMTP/2FA estiver ativo em Configurações, emite um
   código de 6 dígitos (`verificacoes_email`) e envia por e-mail
   (`App\Services\Portal\SmtpEmailSender`, mesmo SMTP puro via Symfony
   Mailer usado no teste de envio de Configurações); senão, mostra o
   boletim direto.
3. **`POST /portal/verificar`** — valida o código; 3 erros seguidos ou
   código expirado manda de volta para `/portal`. O código é guardado como
   **HMAC-SHA256 com a `APP_KEY`** (`VerificacaoEmail::hashDoCodigo`, coluna
   `codigo` de 64 caracteres) e comparado com `hash_equals` — um dump da tabela
   não entrega códigos válidos. Além do bloqueio por IP abaixo há um **limite
   por CPF**: 10 falhas em 1 h bloqueiam aquele CPF a partir de qualquer IP
   (um atacante com muitos IPs, ou testando CPFs que não existem, também é
   contado; o contador zera no acesso bem-sucedido), checado antes do CAPTCHA.
   Bloqueio por IP após 10
   tentativas falhas em 1h (`App\Services\Portal\RateLimit2faService`,
   tabela `rate_limit_2fa`) — reescrito com Eloquent em vez do
   `ON DUPLICATE KEY ... IF(...)` só-MySQL do legado, para funcionar também
   em SQLite (testes). Diferente do legado, o IP do cliente vem de
   `$request->ip()` (respeita `trusted proxies` do Laravel) em vez de
   confiar cegamente em `CF-Connecting-IP`/`X-Forwarded-For` — o legado
   permitia um cliente falsificar esses cabeçalhos para escapar do (ou
   incriminar outro IP no) rate limit.
4. **`POST /portal/reenviar`** — gera e envia um **código novo** (o anterior
   deixa de valer, porque só o hash é guardado e o texto original não existe
   mais), zera as tentativas erradas e respeita o cooldown progressivo
   (1, 2, 5, 10 min).
5. **Boletim** (`GET /portal/resultados`) — depois que `consultar()`/
   `verificar()` confirmam CPF+Data de Nascimento (e o código de 2FA, se
   ativo), o controller grava `session(['portal_aluno_id' => $aluno->id])`
   e redireciona (POST/Redirect/GET) para esta rota — é a única exceção ao
   fluxo "sem sessão" do portal, e existe só pra permitir uma URL própria
   (ver abaixo). As avaliações são agrupadas na árvore de
   [categorias](#categorias-de-avaliação-categorias)
   (`App\Services\Portal\ResultadoConsultaService::montarArvore` — só
   entram categorias em que o aluno tem algum resultado, direto ou numa
   subcategoria; uma avaliação sem categoria aparece à parte). Cada categoria é
   um acordeão colapsável (clique pra expandir); dentro dela, cada avaliação
   aparece como um **card resumido** (nome, data, % de acerto). Um filtro
   por período (De/Até) esconde os cards fora do intervalo e as categorias
   que ficam vazias — tudo client-side, sem nova consulta ao servidor.

   **Tour guiado e rodapé.** Cada tela do portal com tour (boletim e detalhe da avaliação; a tela de login não tem) abre sozinha, na
   primeira visita, um popup que destaca os elementos da tela e explica cada um (`public/assets/js/portal-tour.js`, JS
   puro, sem biblioteca; estilos em `portal-tour.css`). Os passos ficam na própria view, em `@section('tour')` com
   `@include('portal._tour', ['chave' => ..., 'passos' => [...]])`; passo cujo alvo não existe na tela é pulado. Quem já
   viu fica registrado no `localStorage` do navegador (uma chave por tela, `portal_tour_v1_<chave>`). O rodapé tem
   **"Refazer tour da página"** nas telas com tour. O link da **área administrativa** aparece só na tela de login do aluno
   (`@section('acesso-administrativo')` na `consulta`), nunca no boletim, no detalhe ou na verificação do código.

   **O que o boletim mostra (e o que não mostra).** O card verde do topo traz só o
   número de avaliações — sem "média geral". Os cards de resumo falam em **percentual de
   acerto** ("subiu de 55% para 68%"), nunca em "pontos", e **não comparam o aluno com a
   turma** (nada de "acima/abaixo da média da turma" nem "entre os X% melhores"). Um dos
   cards traduz o **nível de Bloom** de menor acerto em linguagem de estudante
   (`App\Support\BloomExplicado`: "As questões que pedem para relacionar dados de um caso
   foram as mais difíceis pra você… Treine com casos, não só com teoria."), só com ≥ 2
   níveis, ≥ 3 questões no pior e diferença que não seja ruído. Dentro de cada categoria
   o gráfico **rendimento x mínimo esperado** (título na tela) substituiu "Você x turma", "Dificuldade pedagógica",
   "Nível de Bloom" e "Áreas onde você mais diverge da turma": **uma barra por avaliação** com o % de
   acerto TOTAL do aluno (verde = no mínimo ou acima, amarela = abaixo) e uma **linha tracejada em pé**
   no **mínimo esperado**, mais a **Leitura rápida** em texto
   (`AnaliseConsolidadaService::metaPorPeriodo()` + `App\Support\LeituraMetaPeriodo`).
   O mínimo vem da meta `questoes.periodo_minimo`: é a fatia da prova que já cabe no
   período do aluno (questão sem meta conta para todos; questão de período à frente não
   é cobrada). O período do aluno é o de `respostas.periodo` daquela prova, não o do
   cadastro. **Prova sem meta (nenhuma questão com período mínimo, ou período do aluno
   irreconhecível): mínimo padrão de 60%** (`AnaliseConsolidadaService::MINIMO_PADRAO`).
   O **mapa de domínio por área** segue a mesma regra por célula (área × avaliação): mostra o acerto e o
   "mín." esperado — a fatia das questões daquela área que o aluno já deveria acertar pelo período dele —,
   verde no mínimo ou acima, **amarelo abaixo**, e 60% quando as questões da célula não trazem a meta.
   Os cards de resumo usam "rendimento" (não "desempenho") e o rótulo do card verde vai
   para o singular quando há uma única avaliação.

   **Detalhe da avaliação sem comparação com a turma.** A tela da avaliação não mostra mais "Comparativo com a
   turma", "Posição relativa" nem "Sua resposta x turma, por questão" (os três visuais continuam existindo no
   catálogo de visualizações, mas o portal não os renderiza). "Desempenho por área" virou barras com uma linha
   tracejada em pé na **meta de cada área** (`RelatorioAlunoService::desempenhoPorAreaComMeta()`, desenhado por
   `Viz.barrasComMinimo()` em `_viz.blade.php`, o mesmo gráfico do acerto x mínimo da categoria): a meta é a fatia das
   questões da área que já cabem no período do aluno (`respostas.periodo` da prova), ou 60% sem essa informação; verde
   atinge, amarelo não. **Trilha de estudo** e **Lacunas / Conhecimentos consolidados** só consideram as questões que o
   aluno precisava acertar pelo período dele (as de `periodo_minimo` à frente ficam de fora, e a tela diz quantas); o
   ganho da trilha continua sendo sobre a prova inteira. O gráfico "Desempenho por nível de Bloom" saiu da tela da
   avaliação; no lugar entrou **"Como você foi nesta prova"** (`App\Support\LeituraDaProva`): uma abertura (muito bem /
   bem / perto do esperado / abaixo, contra o esperado do período — ou a referência de 60%), o ponto forte e as áreas a
   reforçar, o tipo de pergunta que mais deu trabalho (o Bloom traduzido por `BloomExplicado`, sem citar "Bloom") e as
   questões de períodos à frente. Linguagem de estudante, sem jargão nem comparação com a turma; cada trecho respeita a
   configuração de visuais (nota geral, desempenho por área, nível de Bloom).

   **O detalhe de cada avaliação abre em nova aba** (`GET
   /portal/resultados/avaliacoes/{avaliacao}?periodo=`,
   `App\Services\Portal\ResultadoConsultaService::buscarUmaAvaliacao`), não
   mais num modal — pensado pra deixar espaço a outras análises que serão
   adicionadas ali no futuro, sem espremer tudo num popup. Essa rota
   também exige a sessão do boletim e, além disso, confere que a
   avaliação/período pedidos realmente pertencem ao aluno autenticado (senão,
   404) — a URL carrega só o código da avaliação, nunca dado nenhum do aluno,
   então adivinhar/alterar um código de avaliação na barra de endereço não
   revela boletim alheio. A tela de detalhe mostra o comparativo completo
   (`respostas` x gabarito de `questoes`; notas finais de
   `resultado_metricas`; link do gabarito comentado; grade de respostas
   colorida verde/vermelho) e o botão de **baixar o PDF daquela avaliação**
   (não existe mais um "baixar tudo" do boletim inteiro). O PDF ganha um
   cabeçalho próprio (nome do site, nome do aluno, RA, data/hora de
   geração) inserido antes de capturar e removido depois — mesma técnica
   do legado, mas por avaliação em vez de uma vez só pra tudo.

   **`GET /portal/sair`** encerra essa sessão (usado pelo link "Nova
   consulta" no boletim) — importante em computador compartilhado
   (laboratório, secretaria), pra não deixar o boletim acessível pro
   próximo que abrir o navegador.

## Aparência e acessibilidade

O visual segue a mesma identidade do app legado — sem isso, as duas
aplicações convivendo no mesmo domínio ficariam com "cara" diferente:
Tailwind via CDN com a paleta `primary #00b48d`/`secondary`/`dark`, fonte
Inter (Google Fonts) e [Phosphor Icons](https://phosphoricons.com/). O
layout do portal público, do login e do painel administrativo (agora uma
sidebar escura, como o `admin/includes/header.php` legado, em vez do
topbar fino que tinha antes) foram redesenhados nesse padrão; as telas de
gestão internas (CRUDs) ainda usam um estilo mais simples — não foram o
foco desta rodada.

**Acessibilidade** — dois mecanismos complementares, presentes em toda
tela (`resources/views/partials/accessibility-*.blade.php`):

- **Barra própria** (`public/assets/js/accessibility.js` +
  `public/assets/css/accessibility.css`, baseados no
  `assets/js/accessibility.js` legado): tema Claro/Escuro/Alto Contraste
  (via `filter: invert()/contrast()` no `<html>`, persistido em
  `localStorage`), um botão que abre o **VLibras** (tradutor de Libras do
  governo federal, injetado sob demanda) e um botão que abre o **Sienna**
  (ver abaixo). Os ícones de aumentar/diminuir fonte (A+/A-) do legado
  foram removidos — com o widget Sienna cobrindo ajuste de tamanho de
  texto (entre outros perfis), a barra ficava com controles redundantes.
  Aparece no rodapé do portal público e na topbar do painel
  administrativo.
- **[Sienna](https://accessibility-widget.pages.dev/accessibility/)**
  (`sienna-accessibility.umd.js` via jsDelivr, carregado em toda página):
  widget de terceiro, gratuito e open-source, com perfis prontos (TDAH,
  dislexia, baixa visão, etc.), ajuste de fonte, cursor ampliado e redução
  de animação. **Não substitui a barra própria** — não inclui VLibras nem
  tema de alto contraste equivalente. O botão flutuante que o Sienna cria
  sozinho (`.asw-widget`) fica oculto via CSS — igual já era feito com o
  `[vw-access-button]` do VLibras — e é a barra própria quem dispara o
  clique nele (`.asw-menu-btn`) a partir do próprio botão da barra, pra
  não conviver com dois balões flutuantes de cantos diferentes na tela.
  Vale registrar a ressalva de acessibilidade real: "widgets overlay"
  como este são vistos com desconfiança por usuários de leitor de tela
  (ajustam a página por fora, sem corrigir HTML semântico/ARIA/foco de
  teclado); foi incluído a pedido, como complemento à barra própria, não
  como solução única de acessibilidade.

**Acessibilidade do próprio HTML** (o que não depende do widget):

- **Teclado e leitor de tela.** Todas as telas têm o link *Pular para o
  conteúdo* (aparece ao receber foco) e `<main id="conteudo-principal">`. No
  admin, o menu lateral tem `aria-label`, marca a página atual com
  `aria-current="page"`, e o botão do menu no celular declara `aria-controls`/
  `aria-expanded`/`aria-label` e fecha com Esc devolvendo o foco. A barra lateral
  escondida usa `visibility: hidden`, então sai da ordem de tabulação. O menu de
  temas da barra de acessibilidade abre por Enter/Espaço (não só no *hover*),
  navega com as setas, fecha com Esc e indica o tema atual com `aria-pressed`.
- **Ponto de quebra.** O CSS do menu lateral usa `max-width: 767.98px`, não
  `768px`: o `md:` do Tailwind começa *em* 768 px, onde o botão do menu some —
  com `768px` a barra ficava escondida e sem botão para abri-la.
- **Erros de formulário.** O resumo de erros (`partials/flash`, e as quatro
  telas de `auth/*`) é `role="alert"` com `id="erros-do-formulario"`; quando há
  erros, `partials/erros-de-campo` marca cada campo recusado com `aria-invalid`,
  liga-o ao resumo via `aria-describedby` e leva o foco ao primeiro. Mensagens
  de sucesso são `role="status"`.
- **Gráficos.** `public/assets/js/graficos-acessiveis.js` dá a cada `<canvas>` do
  Chart.js `role="img"` e um `aria-label` montado do título da seção e dos dados
  desenhados (até 14 pontos por série), atualizado depois de cliques/alterações
  que redesenham o gráfico. Para um título específico, use `data-titulo` no canvas.
- **Contraste (WCAG 1.4.3, 4,5:1).** Em fundo claro o texto pequeno usa
  `text-slate-500`/`text-emerald-700`/`text-amber-700`/`text-red-600`, nunca
  `text-slate-400`/`-emerald-600`/`-amber-600`/`-red-500`/`text-primary` (ficam
  entre 2,5:1 e 3,8:1); em fundo escuro (login, instalador, barra lateral) o
  inverso: `text-slate-400` em vez de `-500`. Ícones decorativos (`<i>`) ficam
  de fora. `AcessibilidadeTest` varre as views e falha se uma dessas cores
  voltar. **Decisão em aberto:** texto branco sobre `bg-primary` (#00b48d) dá
  2,5:1 — é a cor da marca e não foi trocada.

## Autenticação

O guard `admin` autentica contra a tabela `admins` já existente (mesmo hash
bcrypt gerado por `password_hash()` no PHP legado) — quem já tem acesso ao
painel administrativo entra aqui com as mesmas credenciais. Login tem
rate limiting (5 tentativas / 60s por usuário+IP). Há redefinição de senha
por e-mail (`/esqueci-senha`, precisa de SMTP ativo e de e-mail cadastrado na
conta) e, pela linha de comando, `php artisan admin:redefinir-senha <usuario>`.

Pontos de segurança que valem conhecer:

- **Links de e-mail usam `APP_URL`**, nunca o cabeçalho `Host` da requisição
  (quem pede o reset controla esse cabeçalho). Defina `APP_URL` com o endereço
  público real; enquanto ele estiver em `http://localhost`, o link cai para o
  endereço da requisição só para não sair quebrado. Opcionalmente,
  `TRUSTED_HOSTS=meu.dominio.edu.br` (lista separada por vírgula) faz o Laravel
  recusar qualquer outro `Host` (só tem efeito fora do ambiente `local`).
- **Cabeçalhos de segurança** (`SecurityHeaders`, no grupo `web`):
  `X-Frame-Options: SAMEORIGIN` + `frame-ancestors 'self'` (anti-clickjacking),
  `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` e
  HSTS **somente** quando a requisição já veio por HTTPS. Área logada e
  resultados do portal saem com `Cache-Control: no-store`. De propósito não há CSP
  de scripts/estilos: as telas usam scripts inline e bibliotecas por CDN.
- **CAPTCHA falha fechado.** Com reCAPTCHA/hCaptcha ativo e a *secret key* vazia,
  a consulta do portal é **recusada** (antes a verificação era pulada). A tela de
  configuração também não deixa ativar sem site key e secret key.
- **Cookie de sessão:** em produção com HTTPS, mantenha `SESSION_SECURE_COOKIE=true`
  no `.env`.

## Instalação

Passos únicos, feitos por SSH (preparam o código; não tocam em banco nem em admin):

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Aponte o document root do servidor web para `public/` (ver "Deploy" abaixo)
e acesse a aplicação pelo navegador — como ainda não há
nenhum administrador cadastrado, você cai automaticamente no **wizard de
instalação** (`/instalar`), que cobre o resto:

1. Verifica requisitos (versão do PHP, extensões, permissões de escrita).
2. Testa a conexão com o banco e grava no `.env` (não precisa editar o
   arquivo manualmente).
3. Roda as migrations.
4. Cria o primeiro usuário administrador.

O wizard **bloqueia sozinho** assim que existir um administrador — não dá
pra reabri-lo depois num site em produção. Ao concluir a instalação (ou ao
confirmar um sistema já instalado) é gravado o marcador
`storage/app/instalado.lock`: **se ele existe e o banco não responde, o sistema
devolve 503 em vez de reabrir o wizard** (queda do MySQL não pode virar convite
para criar um novo administrador). Os dados do banco digitados no wizard são
validados por formato antes de irem para o `.env`, e a mensagem de erro do driver
não é devolvida na tela (fica só no log). Se o deploy apontar para o mesmo
banco que a aplicação legada (que já tem admins cadastrados), o wizard nem
aparece: o sistema já se considera instalado.

Prefere fazer manualmente por SSH em vez do wizard? Também funciona:

```bash
php artisan migrate
php artisan tinker --execute="App\Models\Admin::create(['username' => 'admin', 'password_hash' => Hash::make('sua-senha')])"
```

## Testes

**MySQL.** Além do SQLite em memória, toda a suíte (e os testes de esquema legado de `tests/Mysql`) roda em
MySQL/MariaDB: `composer test:mysql` (ou `vendor/bin/phpunit -c phpunit.mysql.xml`). É um segundo ambiente porque o
SQLite não enxerga o que o banco herdado da aplicação legada tem de diferente — `admins.role` como ENUM e `alunos`/`admins`
em `utf8mb4_general_ci` contra `utf8mb4_unicode_ci` das tabelas novas — e dois erros reais só apareceram no MySQL
(o ENUM do perfil de reitor e o "Illegal mix of collations"). `tests/Mysql/BancoLegadoMysqlTest` reproduz esse formato
(altera as tabelas, exercita o painel da reitoria, a visão do coordenador e o cadastro de usuários e depois restaura o
esquema). Requer um banco de testes vazio, que **precisa se chamar `*_testes`** (o padrão é `resultados_di_testes`,
usuário `root` sem senha em `127.0.0.1` — ajustável pelas variáveis `DB_HOST`/`DB_USERNAME`/`DB_PASSWORD`):
`RefreshDatabase` apaga todas as tabelas, então `tests/TestCase.php` **recusa** rodar em MySQL num banco com outro nome.
Crie-o uma vez: `CREATE DATABASE resultados_di_testes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`. A primeira
execução leva uns 40 s (migra o banco inteiro); a suíte toda, alguns minutos.

```bash
php artisan test
```

Os testes rodam contra SQLite em memória (configurado em `phpunit.xml`, com
`force="true"` para um `DB_*` do ambiente nunca vencer) — não é preciso um MySQL
rodando nem tocar no banco de produção para testar. O `phpunit.xml` também isola
tudo o que o app grava fora do banco: modo de manutenção em memória
(`APP_MAINTENANCE_DRIVER=cache` + store `array`, para o atualizador não colocar o
site real em 503), backups em `storage/framework/testing/backups` (`BACKUP_DIR`),
uploads em `storage/framework/testing/uploads` (`UPLOADS_DIR`) e log desligado
(`LOG_CHANNEL=null`). Em testes que precisem do arquivo de um upload use
`$this->caminhoDoUpload('uploads/...')` (`tests/TestCase.php`).
SQLite não pega certos erros que só aparecem num MySQL de verdade (ver "FK
pra admins/alunos" no `CLAUDE.md`) — por isso o CI (`.github/workflows/tests.yml`)
também tem um job separado (`migrate-mysql`) que roda `php artisan migrate
--force` contra um MySQL real, sem os testes: existe só pra pegar migration
incompatível antes que o autoatualizador rode a mesma migration em produção.

## Formatos de import

As duas telas de import (abaixo) têm um botão **"Baixar planilha de exemplo
(.xlsx)"** — arquivos em `public/exemplos/`, com o cabeçalho exato reconhecido
pelo import, algumas linhas de exemplo e uma segunda aba ("Instruções")
explicando o que preencher; comentários nas células do cabeçalho explicam
cada coluna. Coberto por teste (`PlanilhaExemploImportTest`) que importa os
dois arquivos de verdade, então se o formato reconhecido mudar (`HeaderResolver`,
`QuestaoImportService`, `ResultadoImportService`) e a planilha de exemplo não
for atualizada junto, o teste quebra.

### Questões / Gabarito (`/avaliacoes/{codigo}/questoes/import`)

Uma linha por questão. Colunas reconhecidas (cabeçalho flexível, com ou sem
acento):

- **Obrigatórias:** `Questão` (aceita `Questao`, `Número`, `Item`, `#`), `Gabarito` (aceita `Resposta`, `Alternativa`, `Letra`, `Correta`).
- **Opcionais:** `Matriz Prova (campo A/B/C)`, `Bloom (nível)`, `Bloom (verbo)`, `Miller (nível)`, `Dificuldade Pedagógica` (fácil/médio/difícil), `Dificuldade TRI`, `Período mínimo` (também "Período esperado" ou "A partir do período"; aceita `3`, `3º`, `3º período` ou `P3`; vazio = vale para todos — não confundir com `Matriz (período)`), `DCN (campo A/B)`, `Portaria INEP (campo A/B/C)`, `PPC (campo A/B/C/D)`, `Matriz (período)`, `Matriz (disciplina)`, `Matriz (código)`.
- As três colunas de Matriz (período/disciplina/código) aceitam **múltiplos valores por célula**, separados por `,`, `;` ou `|` — cada posição vira uma linha em `questao_matrizes`. `Matriz Prova`, `DCN`, `Portaria INEP` e `PPC` são um valor por coluna de campo (A/B/C/D) — cada um vira uma linha em `questao_referencias` (ver "Performance e escala").
- Reimportar o mesmo número de questão desta avaliação **atualiza** em vez de duplicar.
- **Planilha parcial não apaga o que ela não traz:** só as colunas de metadado (Área, Tema, Habilidade, Bloom, Miller, Dificuldade, TRI) que existem no cabeçalho são regravadas. Reimportar só `Questão` + `Gabarito` (para corrigir um gabarito) mantém todo o resto. Já uma célula em branco de uma coluna que a planilha tem limpa o valor.

### Resultados (`/avaliacoes/{codigo}/resultados/import`)

Uma linha por resposta (formato longo, não uma coluna por questão):

- **Obrigatórias:** `CPF` OU `RA` (ao menos uma), `Questão`, `Resposta` (pode vir vazia — significa que o respondente deixou em branco).
- **Opcional:** `Período` (ex.: `2026/1`) — só é necessário se o mesmo aluno puder refazer a mesma avaliação em períodos diferentes; sem essa coluna, todas as respostas do aluno nesta avaliação contam como uma tentativa única.
- Se o CPF/RA bater com um aluno já cadastrado em `alunos`, o resultado é vinculado a ele (`aluno_id`); caso contrário, fica registrado só com o identificador enviado, sem exigir que o aluno já esteja importado.
- Reimportar a mesma combinação avaliação + identificador + período + questão **atualiza** em vez de duplicar.

## Painel de Configurações (`/sistema/configuracoes`)

Migração de dados legados, backups, atualização e os ajustes do sistema
vivem todos sob um único item de menu — **Configurações** — com abas
(Geral / Backups / Dados legados / Atualizações). São quatro controllers
e conjuntos de rotas independentes por baixo (nada de lógica compartilhada
forçada só pela UI), a aba (`resources/views/admin/sistema/_subnav.blade.php`)
é só a navegação comum entre eles.

## Migração dos dados legados (`/sistema/legado`)

Duas formas de importar, a mesma regra de transformação nas duas (uma única
implementação em `App\Services\Legado\LegadoImportador`, para não divergir):

**1. Direto do banco compartilhado** — quando esta aplicação está configurada
no mesmo banco que a aplicação legada:

```bash
php artisan legado:importar --dry-run   # mostra o que seria migrado, sem gravar nada
php artisan legado:importar             # migra de verdade
```

Ou pelo painel, em "Dados legados" → "Importar do banco".

**2. De um arquivo de backup `.sql`** — quando o sistema legado está em outro
servidor e você só tem o arquivo gerado por "Backup Manual" no painel antigo
(`admin/backup.php`). Envie o arquivo em "Dados legados" → "De um arquivo de
backup", com opção de simular antes (mostra os números sem gravar nada).

> **Segurança:** o arquivo enviado é só **interpretado como dados** — as
> linhas `INSERT INTO` de `gabaritos`/`resultados` são extraídas por um
> parser dedicado (`App\Services\Legado\BackupSqlParser`), e todo o resto do
> arquivo (schema, outras tabelas) é ignorado. **O SQL do arquivo nunca é
> executado** contra o banco — evita que um backup malicioso ou corrompido
> (com `DROP TABLE`, etc.) rode qualquer comando.
>
> **Tamanho:** teto da aplicação é 100 MB, mas o limite real também depende
> do `php.ini` do servidor (`post_max_size`/`upload_max_filesize`) — a tela
> mostra o limite efetivo antes do envio. Se o backup for maior que isso, o
> PHP recusa a requisição antes de chegar à aplicação (erro 413); aumente
> essas duas diretivas (e `client_max_body_size` no Nginx, se houver) e
> reinicie o PHP-FPM/Apache.

Nos dois casos, o que é lido e para onde vai:

- Uma `Avaliacao` é criada (ou reaproveitada) por `nome_avaliacao` distinto.
- `gabaritos.respostas` (JSON) vira linhas em `questoes`; `gabaritos.link_comentado` vira `avaliacoes.link_comentado`.
- `resultados.respostas` (JSON) vira linhas em `respostas`, carregando o `periodo` original.
- `resultados.notas_finais` (JSON) vira linhas em `resultado_metricas`, uma por chave (ex.: "Nota de Redação", "Total").
- Registros que já estavam na lixeira (`deleted_at` preenchido) são migrados como soft-deleted no schema novo — nada da lixeira se perde nem vira visível de repente.
- **Idempotente:** pode rodar de novo a qualquer momento (ex.: depois de um novo upload na aplicação antiga) sem duplicar nada — encontra os registros existentes e atualiza.
- **Em lote:** cada linha legada (aluno+avaliação) grava suas respostas/métricas com um `upsert` só, não uma consulta por questão — importar milhares de alunos leva segundos, não minutos. Testado com 2.000 alunos × 50 questões (100 mil respostas) em ~9s contra MySQL local.

Nenhuma informação das duas tabelas legadas fica sem um lugar no schema novo:
`ra`/`periodo`/`nome_avaliacao`/`respostas`/`notas_finais`/`link_comentado`
(de ambas as tabelas) e o estado de exclusão são todos preservados.

### Excluir as tabelas legadas depois de migrar

A mesma tela ("Dados legados") tem uma segunda seção pra excluir
`gabaritos`/`resultados` do banco depois que a migração acima já rodou —
essas duas tabelas ficam existindo só como fonte pro import; uma vez que os
dados já estão em `avaliacoes`/`questoes`/`respostas`/`resultado_metricas`, elas
não são mais lidas por nada. **Não confundir com `admins`, `alunos`,
`configuracoes`, `verificacoes_email` e `rate_limit_2fa`** — essas
continuam sendo usadas diretamente pela aplicação (não foram substituídas
por um schema novo) e não são tocadas por esta ação.

Por ser irreversível (`DROP TABLE`), a exclusão tem três travas:

1. **Confirmação por texto** — precisa digitar `EXCLUIR` no campo antes de
   enviar (mais um `confirm()` no navegador).
2. **Bloqueio automático se nada foi migrado ainda** — se não existir
   nenhuma `Avaliacao` no schema novo, o botão fica desabilitado e o servidor
   também recusa a exclusão, pra não apagar o único lugar onde os dados
   existiam.
3. A tela mostra a contagem de linhas em cada tabela legada e de Avaliações já
   migradas, para conferir antes de excluir — e o texto recomenda gerar um
   [backup completo](#backups-sistemabackups) antes, já que a ação não pode
   ser desfeita.

## Versionamento

A versão instalada fica no arquivo `VERSION` (raiz desta pasta, ex.: `1.0.0`)
— acompanha o código a cada commit/tag. Releases publicadas no GitHub usam
tag `vX.Y.Z` correspondente. `php artisan migrate --force` (chamado
automaticamente pelo wizard e pelo atualizador) só aplica as migrations que
ainda não rodaram nesse banco — o Laravel já rastreia isso sozinho pela
tabela `migrations`, então versões novas nunca reaplicam o que já existe.

## Atualização (`/sistema/atualizacao`)

```bash
php artisan sistema:atualizar --check   # só verifica se há versão nova
php artisan sistema:atualizar           # verifica e aplica
```

Também disponível para o administrador pela interface, em **Atualizações**.
O processo busca a última *Release* pública do repositório definido em
`ATUALIZACAO_REPOSITORIO` no `.env` (formato `owner/repo`) e, se houver uma
versão mais nova que a instalada:

1. Gera um backup completo (ver seção abaixo) — sempre, sem exceção.
2. Coloca a aplicação em modo de manutenção.
3. Baixa e extrai a Release, copiando por cima os arquivos do repositório
   — preservando `.env` e `storage/` intocados.
4. Roda `composer install --no-dev --no-scripts --no-plugins` (o código baixado
   não executa nada durante a instalação das dependências), depois
   `package:discover` e as migrations pendentes.
5. Grava a nova versão em `VERSION` e sai do modo de manutenção.

**O que a atualização confia.** Ela substitui o código da aplicação, então o
repositório de onde vem é uma fronteira de confiança:

- O repositório só se define no **`.env`** — não existe mais campo na tela de
  Configurações (que o mostra somente leitura). Quem só alcança o painel não
  consegue apontar o atualizador para um repositório próprio. Um valor antigo
  de `repositorio_atualizacao` em `configuracoes_sistema` é ignorado, e a tela
  Atualizações avisa que ele existe.
- `ATUALIZACAO_EXIGIR_ASSINATURA=true` só aplica releases cujo commit tenha
  **assinatura verificada pelo GitHub** (`commit.verification.verified`); com
  `ATUALIZACAO_ASSINANTES=login1,login2` o autor da assinatura também precisa
  estar na lista. A tela mostra o estado da assinatura da versão disponível.
  Desligado por padrão (não quebra quem ainda não assina as releases).
- Aplicar exige **a senha do próprio administrador** (`senha_atual`), com limite
  de 5 tentativas por 15 min; baixar, aplicar, senha recusada e falha ficam em
  `atividades` (`sistema.atualizacao_baixada`/`_aplicada`/`_senha_recusada`/`_falhou`).

Se qualquer passo falhar **depois** que os arquivos já começaram a ser
substituídos, o atualizador tenta reverter automaticamente a aplicação a
partir do backup gerado no passo 1 antes de reportar o erro. Uma falha
*antes* disso (download, extração) não mexe em nada — só sai do modo de
manutenção e mostra o erro.

## Backups (`/sistema/backups`)

```bash
php artisan sistema:backup
```

Gera um `.zip` com o dump completo do banco (`database.sql`, via
`mysqldump` quando disponível no servidor, ou um dump em PHP puro lendo a
tabela por cursor — sem carregar tudo na memória de uma vez — quando não
está) mais todos os arquivos da aplicação — exceto `vendor/`,
`node_modules/` e caches, que são reproduzíveis via `composer
install`/`npm install` a partir do `composer.lock`/`package-lock.json`
incluídos. **Inclui o `.env` real**, com credenciais — por isso o download
só é permitido para administradores autenticados, nunca por link direto.
Mantém automaticamente só os N backups mais recentes (configurável em
**Configurações**, padrão 5). Os arquivos ficam em `storage/app/backups`
(`BACKUP_DIR` no `.env` troca o diretório) com nome `backup-AAAA-MM-DD_HHMMSS-<8 hex>.zip`
— o sufixo aleatório impede adivinhar a URL pelo horário e evita que dois backups
no mesmo segundo se sobrescrevam. No Windows, as entradas do zip e as exclusões
(`vendor/`, `.git`, `.claude`...) são normalizadas para `/`.

**Clicar em "Gerar backup agora" não gera o arquivo na hora** — só
enfileira `App\Jobs\GerarBackupJob` (`ConfiguracaoSistema.backup_status`
vai para `processando`) e a tela volta imediatamente, atualizando-se
sozinha a cada alguns segundos até o job terminar (`concluido`) ou falhar
(`erro`, com a mensagem exibida). Um dump completo de uma base com volume
real de dados facilmente passa do tempo de execução de uma requisição
HTTP; processado pela fila, quem gera o arquivo é o worker
(`php artisan queue:work`), que como processo CLI não tem esse limite.
**Sem um worker rodando, o backup fica pendente indefinidamente** — em
produção, garanta `php artisan queue:work` como processo persistente
(supervisor/systemd) ou, no Windows, `php artisan queue:work
--stop-when-empty` agendado no Agendador de Tarefas a cada poucos minutos.
O comando `sistema:backup` acima roda fora da fila (síncrono) e é a opção
certa para backups agendados via cron/Agendador de Tarefas — inclusive
usado internamente pelo atualizador (ver abaixo), que precisa que o backup
já exista antes de prosseguir.

## Configurações (`/sistema/configuracoes`)

Tela para ajustar, sem precisar de acesso ao servidor:

- **Quantos backups manter** (os mais antigos além desse número são apagados
  a cada novo backup).
- **Painel da reitoria**: critério de proficiência (% de acerto) e meta de
  participação (ver "Painel da reitoria").

O repositório de atualização aparece só para leitura: vem de
`ATUALIZACAO_REPOSITORIO` no `.env` (ver "Atualização" acima).

Guardado na tabela `configuracoes_sistema` (chave/valor — nome escolhido
para não colidir com a tabela `configuracoes` da aplicação legada, que tem
outra finalidade). Um valor não definido aqui cai no padrão do `.env`/
`config/sistema.php`.

## Deploy

Esta é uma aplicação Laravel padrão: o document root do servidor web deve
apontar para `public/` (nunca para a raiz do repositório) — configure um
vhost apontando pra essa pasta, mantendo o `mod_rewrite`/`try_files` do
Laravel.

O servidor precisa de acesso a shell/Composer (usado pelo atualizador para
rodar `composer install` após cada atualização) e, idealmente, ao binário
`mysqldump` (usado nos backups — sem ele, cai para um dump em PHP puro,
mais lento mas funcional).

No `.env`: `APP_URL` com o endereço público (links de e-mail), `APP_TIMEZONE`
(`America/Sao_Paulo` no `.env.example`; sem ele os horários saem em UTC, 3 h
adiantados) e `DB_QUEUE_RETRY_AFTER` (padrão 2000 s, **maior que o `timeout` de
1800 s** dos jobs de import/backup — com 90 s, um segundo worker pegava o mesmo
job ainda em andamento e rodava o import em duplicidade).

Precisa também de um worker de fila rodando continuamente
(`php artisan queue:work`, `QUEUE_CONNECTION=database` por padrão — sem
worker, o backup sob demanda pela interface (ver "Backups" acima) nunca
sai do estado "processando").

## Dashboard da avaliação: ajustes recentes

- **Gabarito:** o cabeçalho tem "Gabarito comentado" (o link da avaliação, quando cadastrado) e, para o administrador, "Gabarito da avaliação" (leva à lista de questões).
- **Alunos da avaliação:** coluna **Acertos dentro do esperado** (ex.: `5 de 8`): acertos nas questões que o aluno precisava acertar pelo período em que
  estava (as de `periodo_minimo` à frente não contam). Só aparece quando a avaliação traz o período mínimo em alguma questão.
- **Desempenho por área** sem o gráfico de teia (ficam as barras); **Análise de alternativas** com filtro por área; a **Análise demográfica** vai para o
  fim do Dashboard; "Evolução da média na categoria" saiu (a evolução está na tela de desempenho do coordenador).
- **Forma de ingresso** (`alunos.forma_ingresso`, da planilha de alunos — cabeçalho "Forma de ingresso"/"Tipo de ingresso"/"Modalidade de ingresso"):
  aparece no perfil demográfico e entra na **equidade** como um recorte (grupos com menos de 10 respondentes são omitidos), para comparar se
  PROUNI, Vestibular etc. influenciam o desempenho na avaliação.
