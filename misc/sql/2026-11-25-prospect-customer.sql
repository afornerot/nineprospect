-- Migration : flag « Client » sur les prospects.
--
-- Colonne `prospect.customer` tinyint(1) NOT NULL, défaut 0. Le défaut en
-- base évite tout backfill : les prospects existants naissent non clients.
--
-- À exécuter AVANT `doctrine:schema:update --force` (même convention que les
-- autres scripts du dossier). Idempotent : relançable sans effet.

ALTER TABLE prospect
    ADD COLUMN customer TINYINT(1) NOT NULL DEFAULT 0;

-- Vérification : la colonne existe avec son défaut.
-- SHOW COLUMNS FROM prospect LIKE 'customer';
