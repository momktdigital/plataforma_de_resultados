# Resultados DI - Plataforma de Avaliações e Rankings

![PHP 8+](https://img.shields.io/badge/PHP-8.0+-777BB4.svg?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1.svg?logo=mysql&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-3.x-38B2AC.svg?logo=tailwind-css&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-4.x-FF6384.svg?logo=chart.js&logoColor=white)

Um sistema web completo, responsivo e seguro focado na publicação e análise de resultados de simulados, provas e avaliações acadêmicas. O sistema garante a privacidade do aluno exibindo notas apenas por RA (Registro Acadêmico) e oferece um Dashboard Administrativo repleto de recursos de *Business Intelligence* (BI) para o corpo docente.

---

## 🚀 Funcionalidades Principais

### Para o Aluno (Front-End)
* **Privacidade By-Design:** Acesso a notas unicamente através do RA. O sistema sanitiza as bases de dados e sequer armazena nomes completos se não desejar.
* **Layout Fluido:** Transições *Single Page Application* (SPA) com JavaScript Vanilla para navegação instantânea.
* **Espelho de Desempenho:** Cards dinâmicos exibem a performance do aluno por disciplina comparando o Total contra o Percentual, além de um mapa de respostas (Q1 a Q100).
* **Correção em Tempo Real:** Se houver um gabarito da avaliação carregado pelos professores, o front-end compara automaticamente a alternativa escolhida e gera *badges* coloridas (Verde = Acerto, Vermelho = Erro, Cinza = Anulada).

### Para o Administrador (Back-End)
* **Upsert Dinâmico via CSV:** Processamento inteligente de CSVs (`admin/upload.php`). O algoritmo lê vírgula ou ponto-e-vírgula e mapeia colunas. A operação `INSERT ... ON DUPLICATE KEY UPDATE` permite que os professores subam planilhas repetidas para corrigir notas específicas sem duplicar registros.
* **Parse Flexível de Colunas:** Colunas contendo "Q1", "Q2"... são separadas como mapa de respostas do aluno, enquanto colunas finais ("Clínica Médica - Total de acertos", "Nota de Redação", "Total") são empacotadas de forma não-relacional num campo `JSON` (`notas_finais`), permitindo provas de diferentes estruturas na mesma tabela.
* **BI e Analytics Inteligentes:** O `index.php` do painel possui um parser que escaneia chaves JSON para construir gráficos:
  * Histograma de Distribuição em barras (Faixas de 0 a 100 de acordo com a nota da chave "Total").
  * Gráficos em Teia (Radar) do desempenho médio da turma por Matéria (Baseado no padrão `Matéria - Total...`).
  * Top 5 Alunos do Simulado.
* **CRUDs Completos:** Gerenciamento de outros Administradores, alteração em tempo real de notas e respostas do aluno (Editor Visual) e alteração do Gabarito Oficial (Upload de CSV vertical ou editor visual).

---

## 🛠 Arquitetura e Stack Tecnológico

A aplicação foi projetada sem o uso de *frameworks heavy-weight* (como Laravel) para manter a performance alta, a configuração simples e o deploy agilizado.

* **Back-end:** PHP Moderno (Orientado a Objetos e Funcional) com `PDO` para segurança nativa contra *SQL Injection* via prepared statements.
* **Database:** MySQL. A modelagem híbrida armazena dados relacionais vitais (RA, Período, Nome da Prova) em colunas isoladas para indexação (`B-TREE`), e as notas mutáveis (Q1 a Q100 e dezenas de métricas diferentes) condensadas em duas colunas nativas do tipo `JSON`, aliviando drasticamente a sobrecarga do banco.
* **Segurança:** Hashes de senha via `password_hash()` e `password_verify()`. Controle de sessão (`SESSION`) no namespace `/admin/`.
* **Front-end UI:** TailwindCSS compilado em tempo de execução via CDN, garantindo CSS utility-first modular sem necessidade de `npm install` complexos.
* **Data-Viz:** Integração com Chart.js.

---

## 📂 Estrutura de Diretórios
```bash
/
├── admin/
│   ├── includes/       # Cabeçalhos, Menus e Rodapés seguros
│   ├── index.php       # Dashboard BI e Gráficos (Chart.js)
│   ├── login.php       # Entrada na área restrita
│   ├── upload.php      # Lógica de processamento e Sanitização de CSVs (Alunos)
│   ├── upload_gabarito.php # Lógica de processamento de CSVs (Gabaritos Verticais)
│   ├── resultados.php  # CRUD principal (Leitura/Exclusão do Banco)
│   ├── aluno_editar.php    # Editor UI para corrigir respostas isoladas
│   ├── avaliacoes.php      # Controle dos Metadados e Gabaritos das Provas
│   └── usuarios.php    # Gerenciamento de Contas
├── api/
│   └── consulta.php    # Endpoint REST JSON para consumo da SPA Pública
├── assets/
│   ├── js/app.js       # SPA Vanilla JS (Manipulação do DOM)
│   └── images/
├── includes/
│   └── Database.php    # Classe Singleton PDO
├── database.sql        # DDL (Criação das Tabelas e Schema)
└── index.php           # Landing Page Pública (Busca por RA)
```

---

## ⚙️ Instalação e Configuração

### Requisitos:
* PHP 8.0+
* Servidor Web (Apache/Nginx/PHP Built-in)
* Servidor MySQL 8.0+ ou MariaDB (Com suporte à colunas `JSON`).

### Passos:
1. Clone este repositório para o diretório de execução local (`htdocs`, `www`, etc).
2. Execute o script `database.sql` em seu servidor MySQL para criar o banco de dados e as tabelas obrigatórias (`admins`, `resultados`, `gabaritos`). O script SQL possui uma diretriz `CREATE DATABASE IF NOT EXISTS resultados_di;`.
3. Edite a classe `/includes/Database.php` com as credenciais do seu MySQL local ou de Produção.
4. Acesse o sistema via navegador: `http://localhost/seu-diretorio/`.
5. Acesse o painel em `http://localhost/seu-diretorio/admin`.
   - **Usuário Padrão:** `admin`
   - **Senha Padrão:** `admin`
   - *(Recomenda-se ir em "Meu Perfil" e alterar imediatamente após o primeiro acesso)*.

---

## 📄 Formatos de Arquivos Esperados (CSV)

O algoritmo do sistema varre os arquivos dinamicamente, mas espera certas convenções:

### Arquivo de Alunos
Deve conter colunas com os seguintes nomes precisos para funcionar na indexação correta:
- **`RA`** (Obrigatório - Funciona como chave principal daquele aluno).
- **`Período`** (Obrigatório - Permite agrupar resultados de diferentes turmas).
- **`NOME1`** (Opcional - Será lido no CSV, mas por regras de privacidade o script é instruído a ignorá-lo e não inserir no BD).
- **Questões:** Nomear como `Q1`, `Q2`, `Q3`... até o número de questões.
- **Gráficos e Ranking:** O Dashboard procura especificamente por uma coluna chamada `Total`, `Nota Final` ou `Pontuação Final` para montar o **Ranking dos Melhores**.
- **Gráficos por Matéria:** O algoritmo tenta identificar "Matérias" cortando o título da coluna antes de um hífen. Exemplo: Se tiver uma coluna chamada `Cirurgia - Total de acertos`, ele somará as estatísticas da Turma no eixo "Cirurgia" no Radar Chart.

### Arquivo de Gabaritos
O upload de gabarito funciona verticalmente:
- Coluna 1: Título da Questão (ex: `Q1`)
- Coluna 2: A Alternativa Correta (ex: `B`)
- Qualquer Questão que for enviada em branco no CSV será dada como *Anulada* (Cinza) para os alunos na correção automática frontal.

---

Desenvolvido com foco em velocidade, flexibilidade relacional e UX.
