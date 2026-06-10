-- Migração 001 — Índices de performance
-- Execute este script uma vez em bancos existentes.
-- Bancos criados a partir do database.sql já incluem estes índices.

-- resultados: cobre filtros por avaliação, período e soft-delete
ALTER TABLE `resultados`
    ADD INDEX IF NOT EXISTS `idx_nome_avaliacao`  (`nome_avaliacao`),
    ADD INDEX IF NOT EXISTS `idx_periodo`         (`periodo`),
    ADD INDEX IF NOT EXISTS `idx_deleted_at`      (`deleted_at`),
    ADD INDEX IF NOT EXISTS `idx_aval_deleted`    (`nome_avaliacao`, `deleted_at`),
    ADD INDEX IF NOT EXISTS `idx_periodo_deleted` (`periodo`, `deleted_at`);

-- verificacoes_email: cobre lookup por CPF no 2FA
ALTER TABLE `verificacoes_email`
    ADD INDEX IF NOT EXISTS `idx_cpf_verif` (`cpf`);

-- rate_limit_2fa: já criada com índices — sem alterações necessárias
