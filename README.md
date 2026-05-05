# Resultados DI - Plataforma de Avaliações e Rankings

![PHP 8+](https://img.shields.io/badge/PHP-8.0+-777BB4.svg?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1.svg?logo=mysql&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-3.x-38B2AC.svg?logo=tailwind-css&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-4.x-FF6384.svg?logo=chart.js&logoColor=white)

Um sistema web completo, responsivo e seguro focado na publicação e análise de resultados de simulados, provas e avaliações acadêmicas. O sistema garante a privacidade do aluno exibindo o número de acertos mediante validação de CPF e Data de Nascimento (com suporte a 2FA), e oferece um Dashboard Administrativo repleto de recursos de *Business Intelligence* (BI) para o corpo docente.

---

## 🚀 Funcionalidades Principais

### Para o Aluno (Front-End)
* **Acesso Seguro e 2FA:** A consulta aos boletins é feita utilizando CPF e Data de Nascimento. O sistema suporta validação em duas etapas (2FA) enviando um código de 6 dígitos para o e-mail cadastrado do aluno, garantindo total privacidade do seu desempenho.
* **Acessibilidade Completa:** O sistema é totalmente inclusivo, contando com:
  * Redimensionamento dinâmico de texto (A+ e A-).
  * Temas visuais configuráveis (Modo Claro, Modo Escuro e Alto Contraste).
  * Integração nativa com o tradutor automático de **VLibras**.
* **Layout Fluido:** Transições *Single Page Application* (SPA) com JavaScript Vanilla para navegação instantânea.
* **Espelho de Desempenho:** Cards dinâmicos exibem a performance do aluno por disciplina comparando o Total contra o Percentual, além de um mapa de respostas detalhado (Q1 a Q100).
* **Correção em Tempo Real:** Se houver um gabarito da avaliação carregado pelos professores, o front-end compara automaticamente a alternativa escolhida e gera *badges* coloridas (Verde = Acerto, Vermelho = Erro, Cinza = Anulada).

### Para o Administrador (Back-End)
* **Painel de Configurações Globais:** Permite alterar o Título do Site, o Logotipo (upload de arquivo de imagem) e configurar integrações anti-bot (Google reCAPTCHA v2 e hCaptcha) diretamente pela interface gráfica, sem necessidade de editar o código fonte.
* **Upsert Dinâmico via CSV:** Processamento inteligente de CSVs. O algoritmo lê vírgula ou ponto-e-vírgula e mapeia colunas. A operação permite que professores subam planilhas repetidas para corrigir métricas e acertos específicos sem duplicar os registros no banco.
* **Parse Flexível de Colunas:** Colunas contendo "Q1", "Q2"... são separadas como mapa de respostas do aluno, enquanto colunas finais ("Clínica Médica - Total de acertos", "Nota de Redação", "Total") são empacotadas de forma não-relacional num campo `JSON`, permitindo armazenar provas de diferentes estruturas na mesma tabela de resultados.
* **BI e Analytics Inteligentes:** O Dashboard escaneia as chaves JSON para construir gráficos em tempo real:
  * Histograma de Distribuição em barras (Faixas de Acertos).
  * Gráficos em Teia (Radar) do desempenho médio da turma por Matéria.
  * Top 5 Alunos de cada Simulado.
* **CRUDs Completos:** Gerenciamento de Alunos, Administradores, Edição de Perfil, alteração em tempo real de acertos/respostas do aluno (Editor Visual) e alteração do Gabarito Oficial.

---

## 🛠 Arquitetura e Stack Tecnológico

A aplicação foi projetada sem o uso de *frameworks heavy-weight* para manter a alta performance, reduzir a complexidade e facilitar a hospedagem em qualquer ambiente.

* **Back-end:** PHP Moderno (Orientado a Objetos e Funcional) com `PDO` para segurança nativa contra *SQL Injection*.
* **Database:** MySQL. A modelagem híbrida armazena dados relacionais vitais (RA, Período, Avaliação, CPF, Email) em colunas isoladas, e as métricas de acertos condensadas em colunas nativas do tipo `JSON`, aliviando drasticamente a sobrecarga e o tamanho do banco de dados.
* **Segurança:** Hashes de senha via `password_hash()`. Controle estrito de sessão (`SESSION`). Verificação de bots na área pública e administrativa.
* **Front-end UI:** TailwindCSS compilado em tempo de execução via CDN, garantindo CSS utility-first de forma modular.
* **Data-Viz:** Integração com Chart.js.

---

## 📂 Estrutura de Diretórios
```bash
/
├── admin/
│   ├── includes/           # Cabeçalhos, Menus, Rodapés e Helpers (config_helper.php)
│   ├── index.php           # Dashboard BI e Gráficos (Chart.js)
│   ├── login.php           # Entrada na área restrita
│   ├── configuracoes.php   # Configurações Globais (Título, Logo, Captchas)
│   ├── alunos.php          # Gestão de Alunos (CPF, Email, Data de Nasc.)
│   ├── upload.php          # Lógica de processamento de CSVs (Alunos)
│   ├── upload_gabarito.php # Lógica de processamento de CSVs (Gabaritos)
│   ├── resultados.php      # Leitura e Exclusão de resultados
│   ├── aluno_editar.php    # Editor UI para corrigir respostas isoladas
│   ├── avaliacoes.php      # Controle dos Metadados e Gabaritos das Provas
│   ├── usuarios.php        # Gerenciamento de Administradores
│   └── perfil.php          # Edição de senha e dados do próprio perfil
├── api/
│   └── consulta.php        # Endpoint REST JSON para consumo da SPA Pública
├── assets/
│   ├── css/
│   │   └── accessibility.css # Regras visuais para Temas Escuro/Claro e Alto Contraste
│   ├── js/
│   │   ├── app.js            # SPA Vanilla JS (Manipulação do DOM e Busca)
│   │   └── accessibility.js  # Lógica de Redimensionamento, VLibras e Temas
│   └── images/               # Logotipos e uploads do painel de configuração
├── includes/
│   └── Database.php        # Classe Singleton de Conexão com o Banco
├── database.sql            # DDL (Criação das Tabelas e Schema)
└── index.php               # Landing Page Pública (Busca com validação de CPF e Data de Nasc.)
```

---

## ⚙️ Instalação e Configuração

### Requisitos:
* PHP 8.0+
* Servidor Web (Apache/Nginx/PHP Built-in)
* Servidor MySQL 8.0+ ou MariaDB (Com suporte obrigatório à colunas `JSON`).

### Passos:
1. Clone este repositório para o diretório de execução do seu servidor web (`htdocs`, `www`, etc).
2. Execute o script `database.sql` em seu servidor MySQL para criar o banco de dados e todas as tabelas (`admins`, `alunos`, `resultados`, `gabaritos`, `configuracoes`). O script SQL possui uma diretriz `CREATE DATABASE IF NOT EXISTS resultados_di;`.
3. Edite a classe `/includes/Database.php` com as credenciais do seu MySQL local ou de Produção.
4. Acesse o sistema via navegador: `http://localhost/seu-diretorio/`.
5. Acesse o painel em `http://localhost/seu-diretorio/admin`.
   - **Usuário Padrão:** `admin`
   - **Senha Padrão:** `admin`
   - *(Recomenda-se ir em "Meu Perfil" e alterar a senha imediatamente após o primeiro acesso)*.
6. Ajuste as configurações do site (como o título da instituição, o upload do logotipo e as chaves do Google reCAPTCHA) acessando o menu lateral **Configurações**.

---

## 📄 Formatos de Arquivos Esperados (CSV)

### Arquivo de Alunos e Resultados
O algoritmo do sistema varre os arquivos dinamicamente, mas espera algumas convenções:
- **Identificação Obrigatória:** É necessário ter colunas para identificar o aluno de forma única (ex: `RA`, `CPF`, `Email`, `Data de Nascimento`).
- **Agrupamentos:** `Período` e `Avaliação` são essenciais para separar e organizar os dados inseridos.
- **Questões:** Nomear as colunas do gabarito do aluno como `Q1`, `Q2`, `Q3`...
- **Gráficos e Ranking:** O Dashboard procura especificamente por uma coluna final chamada `Total`, `Acertos Totais` ou `Pontuação Final` para montar o **Ranking dos Melhores**.
- **Gráficos por Matéria:** O algoritmo tenta identificar "Matérias" cortando o título da coluna antes de um hífen. Exemplo: Se tiver uma coluna chamada `Cirurgia - Total de acertos`, ele agrupará os dados no eixo "Cirurgia" para o gráfico de Radar.

### Arquivo de Gabaritos
O upload do Gabarito Oficial da prova funciona de forma vertical (duas colunas):
- Coluna 1: Título da Questão (ex: `Q1`)
- Coluna 2: A Alternativa Correta (ex: `B`)
*(Qualquer Questão que for enviada em branco na alternativa será automaticamente considerada como **Anulada** para todos os alunos).*

---

Desenvolvido com foco em acessibilidade universal, velocidade extrema de carregamento e interface limpa (UX/UI).
