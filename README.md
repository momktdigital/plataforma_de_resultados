# Resultados DI - Plataforma de Avaliações e Rankings

![PHP 8+](https://img.shields.io/badge/PHP-8.0+-777BB4.svg?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1.svg?logo=mysql&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-3.x-38B2AC.svg?logo=tailwind-css&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-4.x-FF6384.svg?logo=chart.js&logoColor=white)

Um sistema web completo, responsivo e seguro focado na publicação e análise de resultados de simulados, provas e avaliações acadêmicas (UNIFAA/FAA). O sistema garante a privacidade do aluno exigindo CPF + Data de Nascimento e, opcionalmente, um segundo fator de autenticação por e-mail (2FA), e oferece um Dashboard Administrativo com recursos de *Business Intelligence* (BI) para o corpo docente/coordenação.

---

## 🚀 Funcionalidades Principais

### Para o Aluno (Front-End — `index.php`)
* **Acesso Seguro:** Consulta por CPF e Data de Nascimento, com proteção anti-bot via **Google reCAPTCHA v2** ou **hCaptcha** (mutuamente exclusivos, configuráveis pelo painel).
* **2FA por E-mail (opcional):** Quando ativado em Configurações, o sistema envia um código de 6 dígitos por e-mail (via SMTP/PHPMailer) antes de liberar o boletim. Inclui reenvio de código com cooldown progressivo (1, 2, 5, 10 min) e bloqueio por IP após tentativas malsucedidas (`api/consulta.php`, `api/verify_2fa.php`, `api/resend_2fa.php`).
* **Acessibilidade Completa:** Redimensionamento dinâmico de texto (A+ / A-), temas Claro/Escuro/Alto Contraste (persistidos em `localStorage`) e integração com o tradutor **VLibras**.
* **Layout Fluido:** SPA em JavaScript Vanilla (`assets/js/app.js`), sem reload de página entre busca, 2FA e resultado.
* **Espelho de Desempenho:** Cards por disciplina (Total vs. Percentual), mapa de respostas detalhado (Q1 a Q100) e um **painel de estatísticas de desempenho** (% de acerto, corretas/erradas/brancas e breakdown por área/matéria) quando há gabarito vinculado.
* **Correção em Tempo Real:** Comparação automática entre a resposta do aluno e o gabarito oficial, com badges coloridas (Verde = Acerto, Vermelho = Erro, Cinza = Anulada).
* **Exportação em PDF:** Geração do boletim em PDF diretamente no navegador (html2pdf).

### Para o Administrador (Back-End — `admin/`)
* **Painel de Configurações Globais** (`configuracoes.php`): título do site, logotipo (versões clara e escura, com upload de imagem), reCAPTCHA/hCaptcha, SMTP para o 2FA (host, porta, usuário, senha, remetente) e template do e-mail de código (com placeholders `[NOME_DO_ALUNO]` e `[CODIGO]`) — com botão de teste de envio SMTP (`api_test_smtp.php` / `api_verify_test_smtp.php`).
* **Upsert Dinâmico via CSV:** upload de resultados (`upload.php`) e de gabaritos (`process_gabarito.php`) lê vírgula ou ponto-e-vírgula, mapeia colunas automaticamente e permite reenviar planilhas para corrigir métricas sem duplicar registros.
* **Parse Flexível de Colunas:** colunas `Q1`, `Q2`... viram o mapa de respostas do aluno; colunas finais (ex.: `Clínica Médica - Total de acertos`, `Nota de Redação`, `Total`) são empacotadas em `JSON` não-relacional, permitindo provas com estruturas diferentes na mesma tabela.
* **BI e Analytics** (`index.php` do admin): histograma de distribuição de acertos, gráfico radar de desempenho médio por matéria e Top 5 alunos por avaliação (via Chart.js), com filtro por avaliação/período.
* **CRUDs Completos:** Alunos (`alunos.php`, `aluno_form.php`, `aluno_editar.php`), Avaliações/Gabaritos (`avaliacoes.php`, `avaliacao_editar.php`), Resultados (`resultados.php`, com exclusão em massa por período/avaliação), Administradores (`usuarios.php`) e Perfil (`perfil.php`).
* **Lixeira (Soft-Delete)** (`lixeira.php`): resultados e gabaritos excluídos vão para `deleted_at` em vez de serem apagados; permite restaurar individualmente, restaurar tudo ou excluir permanentemente.
* **Backup Manual** (`backup.php`): gera e baixa um dump `.sql` completo do banco (estrutura + dados de todas as tabelas) sob demanda.

### 🎓 Importação de matrícula por Excel (em desenvolvimento)
Está em andamento uma evolução do cadastro de alunos para suportar planilhas Excel (`.xlsx`/`.xls`, além de `.csv`) com parsing 100% client-side (biblioteca [SheetJS/xlsx.js](https://github.com/SheetJS/sheetjs) via CDN + `admin/js/di_parser.js`), pensada para coordenadores de curso analisarem resultados por curso/período letivo.

* `admin/upload_alunos.php` foi reescrito: agora lê o arquivo no navegador, mostra pré-visualização (contagens, cursos/períodos detectados, avisos) e envia os dados em lotes (`BATCH_SIZE = 200`) via `fetch`/JSON para `admin/alunos_di_process.php`, com barra de progresso.
* Novas colunas mapeadas na matrícula: **Cód. Perfil, RA, Nome, Status, Per. Letivo, Curso, Turma, Período, Dt. Nascimento, CPF, Email** — com reconhecimento de várias grafias de cabeçalho (ex.: `RA`/`Matricula`/`MatriculaAluno`).
* Um esboço de controle de acesso por curso já existe no código (`$_SESSION['admin_role']` = `coordinator`/`superadmin` e `$_SESSION['admin_curso']` filtram o que é importado em `upload_alunos.php` e `alunos_di_process.php`), mas **ainda não há como definir essas sessões**: `admin/login.php` não as popula, a tabela `admins` não tem colunas `role`/`curso`, e o menu (`admin/includes/header.php`) ainda não filtra itens por perfil.

> ⚠️ **Atenção — pendência de banco de dados:** para a importação de matrícula funcionar de ponta a ponta ainda falta uma migration que adicione à tabela `alunos` as colunas `cod_perfil`, `status`, `periodo_letivo`, `periodo` e `turma` (usadas pelo `UPSERT` de `admin/alunos_di_process.php`) e crie a tabela `cursos` (referenciada no mesmo arquivo). Sem isso, a importação de matrícula por Excel falha.

> ℹ️ As funções de import de **gabarito com metadados** e **resultados por questão** que existiam aqui como protótipo (`parseQuestoes`/`parseAlunos` em `admin/js/di_parser.js`, tabelas `di_sessoes`/`di_questoes` nunca criadas) foram descontinuadas e substituídas pelo módulo **[Avaliações](avaliacoes/README.md)** — uma aplicação Laravel separada, que passou a cobrir Provas/Questões/Resultados de forma completa (schema normalizado, campos opcionais, imports com relatório de linhas ignoradas). O nome "DI" foi abandonado ali porque o sistema passou a suportar avaliações de vários tipos, não só o Diagnóstico Institucional. `api/consulta.php` ainda faz uma tentativa (silenciosa, em `try/catch`) de consultar `di_questoes` para enriquecer o portal do aluno — é código órfão, mantido por ora porque a integração do portal com os novos dados de Avaliações fica para uma etapa futura.

---

## 📊 Módulo de Avaliações (`avaliacoes/`)

Aplicação **Laravel** separada, no diretório [`avaliacoes/`](avaliacoes/README.md), responsável pelo cadastro de Provas e pelo import de Questões/Gabarito e de Resultados. É onde o antigo protótipo "DI" de gabarito com metadados e resultados por questão foi reconstruído do zero — com schema normalizado (campos obrigatórios mínimos, todo o resto opcional) e boas práticas de import (upsert sem duplicar, relatório de linhas ignoradas). Compartilha o mesmo banco de dados desta aplicação (usa `admins` para login e `alunos` para resolver CPF/RA), mas roda como um app PHP/Laravel independente, com seu próprio document root (`avaliacoes/public/`) — ver [`avaliacoes/README.md`](avaliacoes/README.md) para instalação, modelagem de dados e formatos de import.

Também tem ciclo de vida próprio para facilitar colocar em outro servidor: um **wizard de instalação** (`/instalar`, guarda conexão de banco + primeiro admin), um **atualizador** que busca a última Release pública do GitHub e aplica sozinho (gera backup, roda migrations pendentes, atualiza o código), e **backup completo sob demanda** (banco + arquivos, para download pelo admin).

---

## 🛠 Arquitetura e Stack Tecnológico

* **Back-end:** PHP 8+ (Orientado a Objetos e Funcional) com `PDO` (prepared statements) contra *SQL Injection*.
* **Database:** MySQL/MariaDB. Modelagem híbrida: dados relacionais (RA, Período, Avaliação, CPF, Email) em colunas isoladas; métricas de acertos condensadas em colunas `JSON`.
* **Segurança:** `password_hash()`/`password_verify()`; sessão de admin protegida; **CSRF token** por sessão (`includes/csrf_helper.php`) em formulários administrativos; **rate limiting por IP** para o 2FA (`includes/rate_limit_helper.php`); reCAPTCHA v2 / hCaptcha na área pública e no login administrativo; arquivo de configuração de banco fora de acesso web direto (`.htaccess` com `Require all denied`).
* **E-mail:** [PHPMailer](https://github.com/PHPMailer/PHPMailer) (`includes/PHPMailer/`) via SMTP (compatível com Amazon SES) para o envio dos códigos de 2FA.
* **Front-end UI:** TailwindCSS via CDN + Phosphor Icons.
* **Data-Viz:** Chart.js (dashboard administrativo) e parsing de planilhas com SheetJS (importação de matrícula).
* **Front-end Público:** JavaScript Vanilla (SPA), IMask.js (máscaras de CPF/data) e html2pdf (exportação de boletim).

---

## 📂 Estrutura de Diretórios
```bash
/
├── admin/
│   ├── includes/
│   │   ├── config_helper.php   # getConfig()/setConfig() sobre a tabela `configuracoes`
│   │   ├── header.php          # Layout + sidebar + sessão + CSRF
│   │   └── footer.php          # Fecha layout + toggle do menu mobile
│   ├── js/
│   │   └── di_parser.js        # Parsing de Excel/CSV (matrícula, resultados, gabarito, âncoras) — módulo DI
│   ├── index.php               # Dashboard BI e Gráficos (Chart.js)
│   ├── login.php                # Entrada na área restrita (CAPTCHA opcional)
│   ├── logout.php               # Encerra sessão
│   ├── perfil.php               # Troca de senha do próprio admin
│   ├── configuracoes.php        # Título, logos, reCAPTCHA/hCaptcha, SMTP e template do e-mail 2FA
│   ├── usuarios.php             # CRUD de administradores
│   ├── alunos.php               # Listagem/busca de alunos (RA, CPF, Nome)
│   ├── aluno_form.php           # Formulário de criação/edição de aluno
│   ├── aluno_novo.php           # Atalho para aluno_form.php
│   ├── aluno_editar.php         # Editor de respostas/notas de um resultado específico
│   ├── upload_alunos.php        # Importação de alunos (Excel/CSV, client-side) — módulo DI em construção
│   ├── alunos_di_process.php    # Endpoint AJAX que grava o upsert de alunos em lote (JSON)
│   ├── process_upload_alunos.php# Handler legado de CSV de alunos (upload direto ao servidor)
│   ├── upload_form.php          # Formulário de upload de resultados (.csv)
│   ├── upload.php               # Processa o CSV de resultados (Q1..Qn + notas finais)
│   ├── upload_gabarito.php      # Formulário de upload do gabarito oficial (.csv)
│   ├── process_gabarito.php     # Processa o CSV do gabarito
│   ├── avaliacoes.php           # Lista avaliações/gabaritos com estatísticas
│   ├── avaliacao_editar.php     # Edição manual do gabarito e estatísticas de erro por questão
│   ├── resultados.php           # Listagem/filtro/exclusão de resultados
│   ├── lixeira.php              # Restauração/expurgo de registros com soft-delete
│   ├── backup.php               # Download de dump .sql completo do banco
│   ├── api_test_smtp.php        # Envia e-mail de teste com código (config. SMTP)
│   └── api_verify_test_smtp.php # Valida o código do e-mail de teste
├── api/
│   ├── consulta.php             # Endpoint principal: valida CPF/Nascimento, CAPTCHA, dispara 2FA e retorna resultados
│   ├── verify_2fa.php           # Valida o código de 2FA e retorna os resultados
│   └── resend_2fa.php           # Reenvia o código de 2FA respeitando cooldown
├── assets/
│   ├── css/
│   │   └── accessibility.css    # Temas Escuro/Alto Contraste
│   ├── js/
│   │   ├── app.js               # SPA pública: busca, 2FA, resultados, painel de estatísticas
│   │   └── accessibility.js     # Fonte, temas e VLibras
│   └── img/                     # Logotipos enviados via Configurações
├── config/
│   ├── db.config.php            # Credenciais de conexão (host, db, usuário, senha, charset)
│   └── .htaccess                # Bloqueia acesso web direto à pasta
├── includes/
│   ├── Database.php             # Singleton/wrapper PDO, lê config/db.config.php
│   ├── csrf_helper.php          # Geração/validação de token CSRF
│   ├── rate_limit_helper.php    # Bloqueio por IP para tentativas de 2FA
│   ├── PHPMailer/                # Biblioteca de envio de e-mail (SMTP)
│   └── .htaccess                # Bloqueia acesso web direto à pasta
├── migrations/
│   ├── 001_add_performance_indexes.sql   # Índices em `resultados` e `verificacoes_email`
│   └── 002_add_deleted_at_columns.sql    # Coluna `deleted_at` (soft-delete) em resultados/gabaritos
├── database.sql                 # DDL completo (todas as tabelas + configurações padrão)
├── index.php                    # Landing Page Pública / SPA do aluno
├── pre_commit.php               # Script utilitário: valida a conexão com o banco
├── test_cuj.py                  # Script Playwright ad-hoc (login admin → configurações)
└── test_cuj2.py                 # Script Playwright ad-hoc (busca pública)
```

---

## 🗄️ Modelagem do Banco de Dados

Tabelas criadas por `database.sql`:

| Tabela | Finalidade |
|---|---|
| `admins` | Usuários do painel administrativo (`username`, `password_hash`). Usuário padrão `admin`/`admin` inserido via `INSERT IGNORE`. |
| `alunos` | Cadastro do aluno: `ra`, `cpf`, `data_nascimento` (chave de consulta pública), `nome`, `curso`, `campus`, `email` (usado no 2FA). |
| `resultados` | Um registro por aluno + período + avaliação (`UNIQUE (ra, periodo, nome_avaliacao)`). `respostas` e `notas_finais` em `JSON`. Suporta soft-delete (`deleted_at`). |
| `gabaritos` | Gabarito oficial por avaliação (`UNIQUE nome_avaliacao`), respostas em `JSON`, suporta soft-delete. |
| `verificacoes_email` | Códigos de 2FA emitidos por CPF (código, expiração, tentativas falhas, contagem de reenvios). |
| `rate_limit_2fa` | Bloqueio por IP após tentativas de 2FA malsucedidas (`tentativas`, `bloqueado_ate`). |
| `configuracoes` | Chave/valor genérico: CAPTCHA, SMTP, template de e-mail, título/logo do site. |

**Migrations aplicadas depois do `database.sql`** (idempotentes, seguras para reexecutar):
1. `migrations/001_add_performance_indexes.sql` — índices em `resultados` (avaliação, período, soft-delete) e `verificacoes_email` (CPF).
2. `migrations/002_add_deleted_at_columns.sql` — adiciona `deleted_at` a `resultados`/`gabaritos` (só necessário se o banco já existia antes do soft-delete; `database.sql` novo já cria a coluna).

> A tabela `cursos` e as colunas extras de `alunos` (`cod_perfil`, `status`, `periodo_letivo`, `periodo`, `turma`) usadas pela importação de matrícula **ainda não têm migration** — ver seção "Importação de matrícula por Excel" acima. As tabelas `di_sessoes`/`di_questoes` que chegaram a ser cogitadas para gabarito com metadados nunca foram criadas e não serão — essa funcionalidade foi reconstruída no módulo [Avaliações](avaliacoes/README.md), com seu próprio schema (`provas`, `questoes`, `questao_matrizes`, `resultados`), documentado no README dentro de `avaliacoes/`.

---

## ⚙️ Instalação e Configuração

### Requisitos:
* PHP 8.0+
* Servidor Web (Apache/Nginx/PHP Built-in)
* Servidor MySQL 8.0+ ou MariaDB (com suporte a colunas `JSON`)
* (Opcional, para 2FA) Uma conta SMTP (ex.: Amazon SES)

### Passos:
1. Clone este repositório para o diretório de execução do seu servidor web (`htdocs`, `www`, etc).
2. Execute o script `database.sql` no MySQL — ele cria o banco `resultados_di` (via `CREATE DATABASE IF NOT EXISTS`) e todas as tabelas.
3. Execute, em ordem, as migrations em `migrations/` (`001_...` e depois `002_...`) — são seguras para rodar mesmo em bancos já existentes, pois verificam antes de alterar.
4. Edite `config/db.config.php` com as credenciais do seu MySQL (host, `db_name`, `username`, `password`, `charset`). Em produção, mova este arquivo para fora do docroot e ajuste o caminho lido em `includes/Database.php`.
5. Acesse o sistema via navegador: `http://localhost/seu-diretorio/`.
6. Acesse o painel em `http://localhost/seu-diretorio/admin`.
   - **Usuário Padrão:** `admin`
   - **Senha Padrão:** `admin`
   - *(Recomenda-se ir em "Meu Perfil" e alterar a senha imediatamente após o primeiro acesso)*.
7. Em **Configurações**, ajuste título do site, logotipo (claro/escuro), reCAPTCHA/hCaptcha e, se desejar 2FA por e-mail, os dados de SMTP e o template do código (use o botão de teste de envio para validar as credenciais antes de ativar `smtp_ativo`).

---

## 📄 Formatos de Arquivos Esperados

### CSV de Resultados (`admin/upload_form.php` → `upload.php`)
- **Identificação Obrigatória:** colunas `RA` e `Período`.
- **Questões:** colunas nomeadas `Q1`, `Q2`, `Q3`... viram o mapa de respostas do aluno.
- **Notas/Ranking:** demais colunas finais (ex.: `Total`, `Acertos Totais`, `Pontuação Final`) são empacotadas em `notas_finais` (JSON) e usadas no Ranking dos Melhores.
- **Gráficos por Matéria:** o algoritmo identifica a "matéria" cortando o título da coluna antes de um hífen (ex.: `Cirurgia - Total de acertos` → agrupa em "Cirurgia" no radar).
- Cada upload é vinculado a uma **Avaliação** e **Período** escolhidos no formulário; reenviar o mesmo arquivo faz `UPSERT` (não duplica).

### CSV do Gabarito (`admin/upload_gabarito.php` → `process_gabarito.php`)
- Formato vertical: Coluna 1 = título da questão (`Q1`), Coluna 2 = alternativa correta (`B`).
- Questão enviada em branco é tratada como **Anulada** para todos os alunos.

### CSV de Alunos — legado (`admin/process_upload_alunos.php`, acionado a partir de `upload_alunos.php`)
- Colunas obrigatórias: `RA`, `CPF`, `Dt. Nascimento` (DD/MM/AAAA).
- Colunas opcionais: `Nome`, `Curso`, `Câmpus/Polo`, `Email` (necessário se o 2FA estiver ativo).
- Aluno já existente (pelo RA) é atualizado (upsert).

### Excel/CSV de Matrícula — módulo DI (`admin/upload_alunos.php`, novo fluxo client-side)
- Colunas reconhecidas (com variações de grafia): `Cód. Perfil` (opcional), `RA`/`Matricula` (obrigatória), `Nome` (opcional), `Status`/`Situação` (opcional), `Per. Letivo` (obrigatória, ex. `2026/1`), `Curso` (obrigatória), `Turma` (opcional), `Período` (obrigatória, ex. `5º`), `Dt. Nascimento` (opcional), `CPF` (opcional), `Email` (opcional).
- Todo o parsing acontece no navegador (SheetJS); o envio ao servidor é em lotes de 200 registros via JSON.
- **Requer as migrations pendentes** descritas na seção "Módulo DI" para funcionar sem erro.

---

Desenvolvido com foco em acessibilidade universal, velocidade extrema de carregamento e interface limpa (UX/UI).
