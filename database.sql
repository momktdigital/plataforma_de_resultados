-- Tabela de Administradores
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inserindo o usuário admin padrão (admin / admin)
INSERT IGNORE INTO `admins` (`username`, `password_hash`) VALUES
('admin', '$2y$10$T7cTshEteRiKcsDyRJGjS.F0CJj6bT.2uWM2FJhAL5LzTW0b3SjAu');

-- Tabela de Resultados
CREATE TABLE IF NOT EXISTS `resultados` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ra` VARCHAR(50) NOT NULL,
    `periodo` VARCHAR(100) NOT NULL,
    `nome_avaliacao` VARCHAR(150) NOT NULL,
    `respostas` JSON,
    `link_comentado` VARCHAR(255) NULL,
    `notas_finais` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ra_periodo_avaliacao` (`ra`, `periodo`, `nome_avaliacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Gabaritos
-- Tabela de Gabaritos

CREATE TABLE IF NOT EXISTS `gabaritos` (

    `id` INT AUTO_INCREMENT PRIMARY KEY,

    `nome_avaliacao` VARCHAR(150) NOT NULL,

    `respostas` JSON,
    `link_comentado` VARCHAR(255) NULL,

    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uk_avaliacao` (`nome_avaliacao`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Alunos
CREATE TABLE IF NOT EXISTS `alunos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ra` VARCHAR(50) NOT NULL UNIQUE,
    `cpf` VARCHAR(20) NOT NULL UNIQUE,
    `data_nascimento` DATE NOT NULL,
    `nome` VARCHAR(255) NULL,
    `curso` VARCHAR(255) NULL,
    `campus` VARCHAR(255) NULL,
    `email` VARCHAR(255) NULL, -- Added to support 2FA
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Verificações de E-mail 2FA
CREATE TABLE IF NOT EXISTS `verificacoes_email` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cpf` VARCHAR(20) NOT NULL,
    `codigo` VARCHAR(10) NOT NULL,
    `tentativas_falhas` INT DEFAULT 0,
    `vezes_reenviado` INT DEFAULT 0,
    `ultimo_reenvio` TIMESTAMP NULL,
    `expira_em` TIMESTAMP NOT NULL,
    `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Configurações
CREATE TABLE IF NOT EXISTS `configuracoes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `chave` VARCHAR(100) NOT NULL UNIQUE,
    `valor` TEXT NULL,
    `descricao` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inserindo configurações padrão
INSERT IGNORE INTO `configuracoes` (`chave`, `valor`, `descricao`) VALUES
('recaptcha_ativo', '0', 'Ativar reCAPTCHA (1 = Sim, 0 = Não)'),
('recaptcha_site_key', '', 'Chave de Site (Site Key) do Google reCAPTCHA v2'),
('recaptcha_secret_key', '', 'Chave Secreta (Secret Key) do Google reCAPTCHA v2'),
('smtp_ativo', '0', 'Ativar envio de email 2FA (1 = Sim, 0 = Não)'),
('smtp_host', 'email-smtp.sa-east-1.amazonaws.com', 'Host do SMTP'),
('smtp_port', '587', 'Porta do SMTP'),
('smtp_user', '', 'Usuário do SMTP'),
('smtp_pass', '', 'Senha do SMTP'),
('smtp_from_email', 'no-reply@seudominio.com.br', 'Email do remetente'),
('smtp_from_name', 'Resultados DI', 'Nome do remetente');
