# Resultados DI - Plataforma de Avaliações e Rankings

![PHP 8+](https://img.shields.io/badge/PHP-8.0+-777BB4.svg?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20.svg?logo=laravel&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1.svg?logo=mysql&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-3.x-38B2AC.svg?logo=tailwind-css&logoColor=white)

Um sistema web completo, responsivo e seguro focado na publicação e análise de resultados de simulados, provas e avaliações acadêmicas (UNIFAA/FAA). O sistema garante a privacidade do aluno exigindo CPF + Data de Nascimento e, opcionalmente, um segundo fator de autenticação por e-mail (2FA), e oferece um Dashboard Administrativo com recursos de *Business Intelligence* (BI) para o corpo docente/coordenação.

## Onde está o código

Toda a aplicação vive no módulo **[Avaliações](avaliacoes/README.md)**, em [`avaliacoes/`](avaliacoes/) — uma aplicação Laravel completa (portal público + 2FA, CRUD de alunos/administradores, configurações, importação de matrícula, cadastro de provas/gabaritos/resultados, dashboard de BI, boletim por categoria, acessibilidade). Este era originalmente um projeto PHP puro na raiz do repositório; todas as suas funções foram portadas para o Laravel em `avaliacoes/` (sobre um schema novo e normalizado) e o código antigo foi removido depois que a migração foi concluída e validada em produção — ver o histórico do repositório caso precise consultar o código legado.

Para instalação, modelagem de dados, formatos de import, deploy, backups/atualização automática e todo o resto: **[leia `avaliacoes/README.md`](avaliacoes/README.md)**.

## Migração de dados de uma instalação anterior

Quem está migrando de uma instalação antiga (schema `admins`/`alunos`/`gabaritos`/`resultados`/`configuracoes` do app legado, no mesmo banco de dados) não precisa dos arquivos removidos: o **processo de migração continua disponível dentro do próprio Laravel**, em `avaliacoes/`, na tela **Sistema → Dados legados** (`/sistema/legado`) ou via `php artisan legado:importar` — lê direto do banco compartilhado ou de um arquivo de backup `.sql`. Ver a seção "Migração dos dados legados" em [`avaliacoes/README.md`](avaliacoes/README.md#migração-dos-dados-legados-sistemalegado) para detalhes.

---

Desenvolvido com foco em acessibilidade universal, velocidade extrema de carregamento e interface limpa (UX/UI).
