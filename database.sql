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
