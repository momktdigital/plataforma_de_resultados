-- Migração 001 — Índices de performance
-- Compatível com MySQL 5.7+ e MariaDB 10.2+
-- Execute uma vez; o procedimento ignora índices que já existem.

DROP PROCEDURE IF EXISTS _criar_indice;

DELIMITER $$
CREATE PROCEDURE _criar_indice(
    IN p_tabela  VARCHAR(64),
    IN p_indice  VARCHAR(64),
    IN p_colunas TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_tabela
          AND INDEX_NAME   = p_indice
        LIMIT 1
    ) THEN
        SET @sql = CONCAT(
            'ALTER TABLE `', p_tabela,
            '` ADD INDEX `', p_indice,
            '` (', p_colunas, ')'
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- resultados: filtro por avaliação
CALL _criar_indice('resultados', 'idx_nome_avaliacao',  '`nome_avaliacao`');

-- resultados: filtro por período
CALL _criar_indice('resultados', 'idx_periodo',         '`periodo`');

-- resultados: soft-delete
CALL _criar_indice('resultados', 'idx_deleted_at',      '`deleted_at`');

-- resultados: avaliação + soft-delete (cobre a query principal do dashboard)
CALL _criar_indice('resultados', 'idx_aval_deleted',    '`nome_avaliacao`, `deleted_at`');

-- resultados: período + soft-delete
CALL _criar_indice('resultados', 'idx_periodo_deleted', '`periodo`, `deleted_at`');

-- verificacoes_email: lookup de CPF no 2FA
CALL _criar_indice('verificacoes_email', 'idx_cpf_verif', '`cpf`');

DROP PROCEDURE IF EXISTS _criar_indice;
