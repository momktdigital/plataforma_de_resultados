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
| `provas` | — (código gerado automaticamente) | `nome`/`tipo` são só identificação, totalmente opcionais. |
| `questoes` | `prova_codigo`, `numero`, `gabarito` | Todo o resto (Bloom, Miller, dificuldade, DCN, Portaria INEP, PPC, Matriz da Prova) é opcional e só é gravado quando a coluna existe no arquivo importado. |
| `questao_matrizes` | `questao_id` | Uma questão pode estar em mais de um período/disciplina/código de matriz — por isso é uma tabela filha (1:N), não colunas fixas. |
| `resultados` | `prova_codigo`, (`ra` OU `cpf`), `questao_numero` | Formato longo: uma linha por resposta de um respondente a uma questão. `aluno_chave` é uma coluna gerada pelo banco (`COALESCE(cpf, ra)`) usada para o índice único que evita duplicar a mesma resposta num reimport. |

`admins` e `alunos` têm migrations próprias aqui, mas elas só criam a tabela
**se ela ainda não existir** (`Schema::hasTable`) — em produção, onde essas
tabelas já existem via `database.sql` da aplicação legada, elas são no-ops.
Isso permite rodar `php artisan migrate` com segurança tanto em produção
(banco já populado) quanto em um ambiente novo/de testes (banco vazio).

## Autenticação

Não há cadastro de usuário nem redefinição de senha por e-mail neste módulo.
O guard `admin` autentica contra a tabela `admins` já existente (mesmo hash
bcrypt gerado por `password_hash()` no PHP legado) — quem já tem acesso ao
painel administrativo entra aqui com as mesmas credenciais. Login tem
rate limiting (5 tentativas / 60s por usuário+IP).

## Instalação

```bash
cd avaliacoes
composer install
cp .env.example .env
php artisan key:generate
```

Edite `.env` com as credenciais do **mesmo banco `resultados_di`** usado
pela aplicação legada (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

```bash
php artisan migrate
php artisan serve
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
- Se o CPF/RA bater com um aluno já cadastrado em `alunos`, o resultado é vinculado a ele (`aluno_id`); caso contrário, fica registrado só com o identificador enviado, sem exigir que o aluno já esteja importado.
- Reimportar a mesma combinação prova + identificador + questão **atualiza** em vez de duplicar.

## Deploy

Esta é uma aplicação Laravel completa: o document root do servidor web deve
apontar para `avaliacoes/public/` (nunca para a raiz do projeto Laravel).
Como ela convive no mesmo repositório que a aplicação legada (cujo document
root é a raiz do repositório), configure-as como **vhosts/aliases
separados** — por exemplo um subdomínio (`avaliacoes.dominio.com` →
`avaliacoes/public`) ou um alias de path no Apache/Nginx apontando para essa
pasta, mantendo o `mod_rewrite`/`try_files` do Laravel.
