-- Migração 002 — Adiciona coluna deleted_at (soft-delete) em resultados e gabaritos
-- Compatível com MySQL 5.7+ e MariaDB 10.2+
-- Execute uma vez; o procedimento ignora colunas que já existem.

DROP PROCEDURE IF EXISTS _adicionar_coluna;

DELIMITER $$
CREATE PROCEDURE _adicionar_coluna(
    IN p_tabela  VARCHAR(64),
    IN p_coluna  VARCHAR(64),
    IN p_tipo    TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_tabela
          AND COLUMN_NAME  = p_coluna
        LIMIT 1
    ) THEN
        SET @sql = CONCAT(
            'ALTER TABLE `', p_tabela,
            '` ADD COLUMN `', p_coluna,
            '` ', p_tipo
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL _adicionar_coluna('resultados', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');
CALL _adicionar_coluna('gabaritos',  'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');

DROP PROCEDURE IF EXISTS _adicionar_coluna;
