# Avaliações

Aplicação Laravel responsável pelo cadastro de **Provas**, import de
**Questões/Gabarito** (com metadados pedagógicos opcionais) e import de
**Resultados**. Substitui o antigo módulo experimental "DI" — o nome foi
abandonado porque o sistema passou a suportar avaliações de vários tipos, não
só o Diagnóstico Institucional.

Este módulo é uma aplicação separada da raiz do repositório (que continua
com o portal público do aluno, 2FA, login administrativo, CRUD de alunos e
configurações). As duas aplicações **compartilham o mesmo banco de dados**:
esta aqui lê/usa as tabelas legadas `admins` e `alunos` sem alterá-las, e
adiciona as tabelas novas descritas abaixo.

## Modelagem de dados

| Tabela | Campos obrigatórios | Observação |
|---|---|---|
| `provas` | — (código gerado automaticamente) | `nome`/`tipo`/`link_comentado` são só identificação, totalmente opcionais. |
| `questoes` | `prova_codigo`, `numero`, `gabarito` | Todo o resto (Bloom, Miller, dificuldade, DCN, Portaria INEP, PPC, Matriz da Prova) é opcional e só é gravado quando a coluna existe no arquivo importado. Suporta soft-delete. |
| `questao_matrizes` | `questao_id` | Uma questão pode estar em mais de um período/disciplina/código de matriz — por isso é uma tabela filha (1:N), não colunas fixas. |
| `respostas` | `prova_codigo`, (`ra` OU `cpf`), `questao_numero` | Formato longo: uma linha por resposta de um respondente a uma questão, num período (`periodo`, opcional — default `''`). `aluno_chave` é uma coluna gerada pelo banco (`COALESCE(cpf, ra)`) usada no índice único que evita duplicar a mesma resposta num reimport. Chama-se `respostas`, não `resultados`, porque a aplicação legada já tem uma tabela `resultados` no mesmo banco. |
| `resultado_metricas` | `prova_codigo`, (`ra` OU `cpf`), `nome_metrica` | Métricas agregadas por aluno+prova+período que não são resposta de uma questão (ex.: "Nota de Redação", "Total") — equivalente ao antigo JSON `resultados.notas_finais`, como linhas em vez de colunas dinâmicas. |

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
> troca já causou `errno 150` em produção (ver `provas.criado_por`,
> `respostas.aluno_id`, `resultado_metricas.aluno_id` como exemplo).
> Testes rodam em SQLite, que não enforce isso — só um banco MySQL real
> pega esse tipo de erro, por isso testamos manualmente contra MariaDB
> antes de publicar qualquer migration que toque nessas duas tabelas.

## Alunos (`/alunos`)

CRUD completo de alunos — porta `admin/alunos.php`/`aluno_form.php` da
aplicação legada para cá. Listagem com busca por RA/CPF/Nome e paginação,
criação e edição exigem RA, CPF (11 dígitos, usado como login no portal
público) e Data de Nascimento; Curso, Câmpus e E-mail são opcionais.
Excluir um aluno remove só o cadastro de acesso, não seus resultados.

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

> **Correção em relação ao protótipo legado:** `alunos_di_process.php` já
> gravava `cod_perfil`/`status`/`periodo_letivo`/`periodo`/`turma` e uma
> tabela `cursos`, mas nenhuma migration em nenhum dos dois apps chegou a
> criar essas colunas/tabela — a importação de matrícula sempre falhava em
> produção. As migrations daqui resolvem isso e também tornam `cpf`/
> `data_nascimento` nullable em `alunos` (a planilha de matrícula nunca
> garantiu os dois; só o cadastro manual em `/alunos` exige ambos, por
> serem a credencial de login do aluno no portal público).

## Administradores (`/administradores`) e Perfil (`/perfil`)

Porta `admin/usuarios.php` (CRUD de administradores: listar, criar, excluir —
não é possível excluir a própria conta logada) e `admin/perfil.php` (troca de
senha, exige confirmar a senha atual) para cá. Mesma tabela `admins` da
aplicação legada, então uma conta criada aqui já funciona para logar em
qualquer um dos dois apps.

## Configurações do portal público (`/sistema/portal`)

Porta `admin/configuracoes.php` (+ `api_test_smtp.php`/`api_verify_test_smtp.php`)
para uma aba própria em Configurações. Lê e grava na tabela `configuracoes`
— a mesma usada pelo portal público **legado** — então salvar aqui já tem
efeito lá, mesmo antes de o portal em si ser portado para este app:

- **Aparência:** título do site e logo (fundo claro/escuro). O arquivo é
  salvo em `assets/img/` **na raiz do repositório** (não em `storage/` do
  Laravel), o mesmo diretório físico que o portal legado lê — os dois apps
  continuam enxergando a mesma logo. Como o document root deste app é
  `avaliacoes/public/`, uma rota própria (`/assets/img/{arquivo}`,
  `App\Http\Controllers\AssetLegadoController`) serve esses arquivos
  publicamente para exibição na própria tela.
- **CAPTCHA:** Google reCAPTCHA v2 ou hCaptcha (mutuamente exclusivos).
- **SMTP + template do e-mail de 2FA:** host/porta/usuário/senha (senha em
  branco não altera a já salva), remetente e template com `[NOME_DO_ALUNO]`/
  `[CODIGO]`, com o mesmo botão de "testar envio" do legado (envia um
  código de 6 dígitos via SMTP puro do Symfony Mailer — não usa o
  `config/mail.php` do Laravel, pois as credenciais vêm do banco, editáveis
  pelo admin).

## Gestão de uma Prova (`/provas/{codigo}`)

Além dos imports, a tela de uma Prova agora cobre o que
`admin/avaliacao_editar.php`, `admin/resultados.php` e `admin/lixeira.php`
faziam sobre o schema legado (JSON por aluno), recalculado sobre o schema
normalizado (`questoes`/`respostas`/`resultado_metricas`):

- **Editar configurações:** nome, tipo, link do gabarito comentado.
- **Editor manual de gabarito:** cria ou corrige uma questão por vez (sem
  reimportar a planilha inteira); reenviar o mesmo número restaura uma
  questão excluída em vez de duplicar (mesma regra do import). Gabarito é
  obrigatório — a coluna é `NOT NULL`, mesma regra já aplicada pelo import
  em lote (uma linha sem gabarito é ignorada, não vira questão "anulada").
- **Questões críticas:** taxa de erro por questão entre os respondentes
  (`App\Services\EstatisticaErroService`), maiores erros primeiro.
- **Excluir prova:** soft-delete em cascata (questões, respostas, métricas).

### Resultados por aluno (`/provas/{codigo}/respondentes`)

Uma linha por respondente + período (equivalente a uma linha de
`admin/resultados.php`, sem o JSON): busca por RA/CPF, filtro por período,
"ver" abre as respostas comparadas ao gabarito (verde/vermelho) e as
métricas daquele respondente. Excluir/restaurar é sempre por período dentro
da prova (`resultados.php` também excluía por período, mas globalmente —
aqui é escopado à prova porque o schema novo já separa por prova).

### Painel BI (`/provas/{codigo}/bi`)

Substitui o dashboard de `admin/index.php` (Chart.js): histograma de
distribuição de % de acerto, radar de desempenho médio por disciplina (usa
`questao_matrizes.disciplina` — só aparece se o import de questões trouxe
essa coluna) e Top 5. Filtro por período. `App\Services\BiDashboardService`
concentra o cálculo.

## Lixeira (`/lixeira`)

Substitui `admin/lixeira.php`. Lista Provas excluídas (restaurar traz de
volta questões/respostas/métricas junto) e Questões excluídas
individualmente de provas que continuam ativas. Resultados/métricas
excluídos em lote por período são restaurados/expurgados direto na tela de
"Resultados por aluno" da prova (acima), não aparecem aqui um a um — seriam
potencialmente milhares de linhas por prova, ao contrário do schema legado
(uma linha por aluno).

## Portal público do aluno (`/portal`)

Porta `index.php` (SPA de consulta pública) + `api/consulta.php` +
`api/verify_2fa.php` + `api/resend_2fa.php` para cá — sem exigir login de
admin, server-rendered (formulários simples, sem SPA em JS). Reaproveita as
tabelas legadas `verificacoes_email` e `rate_limit_2fa` (migrations
próprias, mesmo padrão idempotente das demais tabelas compartilhadas), mas
busca o boletim no **schema novo** (`respostas`/`resultado_metricas`/
`questoes` por Prova), não no JSON de `resultados`/`gabaritos`.

**A raiz do site (`/`) é o portal público**: quem acessa deslogado cai
direto na tela de consulta; um administrador já logado é redirecionado
para `/provas`. O acesso à área administrativa (`/login`) fica só num link
discreto no rodapé das telas do portal — não é destacado na página.

Fluxo, igual ao legado (o CPF circula pelos passos via campo oculto — não
há sessão de autenticação):

1. **`GET /portal`** — formulário de CPF + Data de Nascimento (+ CAPTCHA se
   ativo em Configurações → Portal público).
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
5. **Boletim** (`portal.resultados`) — para cada Prova/período em que o
   aluno tem resposta: % de acerto (comparando `respostas` com o gabarito
   de `questoes`), notas finais (`resultado_metricas`), link do gabarito
   comentado e grade de respostas colorida (verde/vermelho). Exportação em
   PDF no navegador (html2pdf, igual ao legado).

## Autenticação

Não há cadastro de usuário nem redefinição de senha por e-mail neste módulo.
O guard `admin` autentica contra a tabela `admins` já existente (mesmo hash
bcrypt gerado por `password_hash()` no PHP legado) — quem já tem acesso ao
painel administrativo entra aqui com as mesmas credenciais. Login tem
rate limiting (5 tentativas / 60s por usuário+IP).

## Instalação

Passos únicos, feitos por SSH (preparam o código; não tocam em banco nem em admin):

```bash
cd avaliacoes
composer install
cp .env.example .env
php artisan key:generate
```

Aponte o document root do servidor web para `avaliacoes/public/` (ver
"Deploy" abaixo) e acesse a aplicação pelo navegador — como ainda não há
nenhum administrador cadastrado, você cai automaticamente no **wizard de
instalação** (`/instalar`), que cobre o resto:

1. Verifica requisitos (versão do PHP, extensões, permissões de escrita).
2. Testa a conexão com o banco e grava no `.env` (não precisa editar o
   arquivo manualmente).
3. Roda as migrations.
4. Cria o primeiro usuário administrador.

O wizard **bloqueia sozinho** assim que existir um administrador — não dá
pra reabri-lo depois num site em produção. Se o deploy apontar para o mesmo
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

Os testes rodam contra SQLite em memória (configurado em `phpunit.xml`) —
não é preciso um MySQL rodando nem tocar no banco de produção para testar.

## Formatos de import

### Questões / Gabarito (`/provas/{codigo}/questoes/import`)

Uma linha por questão. Colunas reconhecidas (cabeçalho flexível, com ou sem
acento):

- **Obrigatórias:** `Questão` (aceita `Questao`, `Número`, `Item`, `#`), `Gabarito` (aceita `Resposta`, `Alternativa`, `Letra`, `Correta`).
- **Opcionais:** `Matriz da Prova (campo A/B/C)`, `Bloom (nível)`, `Bloom (verbo)`, `Miller (nível)`, `Dificuldade Pedagógica` (fácil/médio/difícil), `Dificuldade TRI`, `DCN (campo A/B)`, `Portaria INEP (campo A/B/C)`, `PPC (campo A/B/C/D)`, `Matriz (período)`, `Matriz (disciplina)`, `Matriz (código)`.
- As três colunas de Matriz (período/disciplina/código) aceitam **múltiplos valores por célula**, separados por `,`, `;` ou `|` — cada posição vira uma linha em `questao_matrizes`.
- Reimportar o mesmo número de questão desta prova **atualiza** em vez de duplicar.

### Resultados (`/provas/{codigo}/resultados/import`)

Uma linha por resposta (formato longo, não uma coluna por questão):

- **Obrigatórias:** `CPF` OU `RA` (ao menos uma), `Questão`, `Resposta` (pode vir vazia — significa que o respondente deixou em branco).
- **Opcional:** `Período` (ex.: `2026/1`) — só é necessário se o mesmo aluno puder refazer a mesma prova em períodos diferentes; sem essa coluna, todas as respostas do aluno nesta prova contam como uma tentativa única.
- Se o CPF/RA bater com um aluno já cadastrado em `alunos`, o resultado é vinculado a ele (`aluno_id`); caso contrário, fica registrado só com o identificador enviado, sem exigir que o aluno já esteja importado.
- Reimportar a mesma combinação prova + identificador + período + questão **atualiza** em vez de duplicar.

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

- Uma `Prova` é criada (ou reaproveitada) por `nome_avaliacao` distinto.
- `gabaritos.respostas` (JSON) vira linhas em `questoes`; `gabaritos.link_comentado` vira `provas.link_comentado`.
- `resultados.respostas` (JSON) vira linhas em `respostas`, carregando o `periodo` original.
- `resultados.notas_finais` (JSON) vira linhas em `resultado_metricas`, uma por chave (ex.: "Nota de Redação", "Total").
- Registros que já estavam na lixeira (`deleted_at` preenchido) são migrados como soft-deleted no schema novo — nada da lixeira se perde nem vira visível de repente.
- **Idempotente:** pode rodar de novo a qualquer momento (ex.: depois de um novo upload na aplicação antiga) sem duplicar nada — encontra os registros existentes e atualiza.
- **Em lote:** cada linha legada (aluno+prova) grava suas respostas/métricas com um `upsert` só, não uma consulta por questão — importar milhares de alunos leva segundos, não minutos. Testado com 2.000 alunos × 50 questões (100 mil respostas) em ~9s contra MySQL local.

Nenhuma informação das duas tabelas legadas fica sem um lugar no schema novo:
`ra`/`periodo`/`nome_avaliacao`/`respostas`/`notas_finais`/`link_comentado`
(de ambas as tabelas) e o estado de exclusão são todos preservados.

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
3. Baixa e extrai a Release, copiando por cima só a subpasta `avaliacoes/`
   do repositório (o repositório também contém a aplicação legada) —
   preservando `.env` e `storage/` intocados.
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
`mysqldump` quando disponível no servidor) mais todos os arquivos da
aplicação — exceto `vendor/`, `node_modules/` e caches, que são
reproduzíveis via `composer install`/`npm install` a partir do
`composer.lock`/`package-lock.json` incluídos. **Inclui o `.env` real**, com
credenciais — por isso o download só é permitido para administradores
autenticados, nunca por link direto. Mantém automaticamente só os N backups
mais recentes (configurável em **Configurações**, padrão 5).

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

Esta é uma aplicação Laravel completa: o document root do servidor web deve
apontar para `avaliacoes/public/` (nunca para a raiz do projeto Laravel).
Como ela convive no mesmo repositório que a aplicação legada (cujo document
root é a raiz do repositório), configure-as como **vhosts/aliases
separados** — por exemplo um subdomínio (`avaliacoes.dominio.com` →
`avaliacoes/public`) ou um alias de path no Apache/Nginx apontando para essa
pasta, mantendo o `mod_rewrite`/`try_files` do Laravel.

O servidor precisa de acesso a shell/Composer (usado pelo atualizador para
rodar `composer install` após cada atualização) e, idealmente, ao binário
`mysqldump` (usado nos backups — sem ele, cai para um dump em PHP puro,
mais lento mas funcional).
