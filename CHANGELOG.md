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

## Portal público do aluno

- Consulta por CPF + data de nascimento, com 2FA opcional por e-mail
  (código de 6 dígitos, expiração, limite de tentativas e bloqueio por IP).
- CAPTCHA opcional (reCAPTCHA ou hCaptcha) na consulta.
- Boletim por avaliação com exportação em PDF (html2pdf).
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
