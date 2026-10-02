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
| `questoes` | `avaliacao_codigo`, `numero`, `gabarito` | O resto que é **um valor só** por questão (Bloom, Miller, dificuldade) é opcional e vira coluna. O que pode ter **vários valores** (matriz da avaliação, DCN, Portaria INEP, PPC) vira linhas em `questao_referencias` — ver abaixo. Suporta soft-delete. |
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

A tela tem duas abas, distinguidas pela coluna legada `admins.role`:

- **Administradores** (`superadmin`, ou sem role): acesso total.
- **Coordenadores** (`coordinator`): acesso limitado aos cursos a que estão
  vinculados. Veem só o **Painel do curso** (`/painel`), a lista de
  avaliações (`/avaliacoes`, sem criar/editar/excluir), o **Dashboard** de
  cada uma (somente leitura, só com os alunos dos cursos dele) e o próprio
  perfil. Todo o resto responde 403 (middleware `somente-admin`, ver
  `routes/web.php`) e a busca global some do menu.

**Login.** A tela de login tem duas abas. **Administrador**: usuário e senha
(a senha é obrigatória só para ele). **Coordenador**: informa usuário ou e-mail
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
o coordenador cai direto no seu **painel** (`/painel`). Quem não tem senha
cadastrada vê a mesma mensagem genérica de "usuário ou senha inválidos".

**Perfil desconhecido não acessa nada.** `Admin::papel()` normaliza `admins.role`
(caixa e espaços não distinguem): `superadmin` ou vazio ⇒ administrador,
`coordinator` ⇒ coordenador, **qualquer outro valor ⇒ sem perfil**. Antes,
tudo que não fosse exatamente `coordinator` virava administrador completo
(falha aberta). Agora o login recusa a conta, o middleware `papel-valido`
(grupo `auth:admin` de `routes/web.php`) encerra uma sessão que ficou com perfil
inválido e `somente-admin` exige `ehAdministrador()`. Contas assim somem das duas
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

Tudo é analisado **por categoria de avaliação** — provas de categorias
diferentes não são comparáveis (o mesmo vale para a "Comparação entre
avaliações" do BI, que só oferece avaliações da mesma categoria). A
"avaliação anterior" é a anterior **da mesma categoria**, mesmo de outro
período letivo. Equivalente ao boletim do aluno, para o curso: filtro por **período letivo**
(2026/1, 2026/2 — derivado da data da avaliação, igual ao portal; padrão = o
mais recente; avaliação **sem data** usa o período do início do nome —
`2026/2 - Diagnóstico...` — ou, sem isso, o período letivo em que a maioria
dos alunos dela estava matriculada, em vez de aparecer só em "Todos") e, com mais de um curso, por curso. Mostra insights em texto,
média do curso, presença, % de alunos abaixo de 60%, evolução da média por
avaliação, desempenho por período do curso e por área. **Ausentes** (prova
inteira em branco) ficam fora das médias e entram só na presença.
`CoordenadorDashboardService` agrega tudo em SQL sobre `resultado_resumos`
(unido a `alunos` por `aluno_id`) e, para presença e áreas, sobre
`respostas` restrito aos alunos do curso e ao período escolhido.

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

Fluxo, igual ao legado até a verificação (o CPF circula pelos passos via
campo oculto, sem sessão de autenticação); só depois de CPF+Data de
Nascimento (e 2FA, se ativo) confirmados é que entra uma sessão — só pra
permitir uma URL própria do boletim (item 5 abaixo), nunca antes disso:

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
   código expirado manda de volta para `/portal`. Bloqueio por IP após 10
   tentativas falhas em 1h (`App\Services\Portal\RateLimit2faService`,
   tabela `rate_limit_2fa`) — reescrito com Eloquent em vez do
   `ON DUPLICATE KEY ... IF(...)` só-MySQL do legado, para funcionar também
   em SQLite (testes). Diferente do legado, o IP do cliente vem de
   `$request->ip()` (respeita `trusted proxies` do Laravel) em vez de
   confiar cegamente em `CF-Connecting-IP`/`X-Forwarded-For` — o legado
   permitia um cliente falsificar esses cabeçalhos para escapar do (ou
   incriminar outro IP no) rate limit.
4. **`POST /portal/reenviar`** — reenvia o mesmo código (só estende a
   validade), respeitando o cooldown progressivo (1, 2, 5, 10 min).
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
- **Opcionais:** `Matriz Prova (campo A/B/C)`, `Bloom (nível)`, `Bloom (verbo)`, `Miller (nível)`, `Dificuldade Pedagógica` (fácil/médio/difícil), `Dificuldade TRI`, `DCN (campo A/B)`, `Portaria INEP (campo A/B/C)`, `PPC (campo A/B/C/D)`, `Matriz (período)`, `Matriz (disciplina)`, `Matriz (código)`.
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
O processo busca a última *Release* pública do repositório configurado em
**Configurações** (ou `ATUALIZACAO_REPOSITORIO` no `.env`, se nunca tiver
sido definido pela interface — formato `owner/repo`) e, se houver uma versão
mais nova que a instalada:

1. Gera um backup completo (ver seção abaixo) — sempre, sem exceção.
2. Coloca a aplicação em modo de manutenção.
3. Baixa e extrai a Release, copiando por cima os arquivos do repositório
   — preservando `.env` e `storage/` intocados.
4. Roda `composer install --no-dev` e as migrations pendentes.
5. Grava a nova versão em `VERSION` e sai do modo de manutenção.

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

- **Repositório do GitHub para atualizações** (`owner/repositorio`).
- **Quantos backups manter** (os mais antigos além desse número são apagados
  a cada novo backup).

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
