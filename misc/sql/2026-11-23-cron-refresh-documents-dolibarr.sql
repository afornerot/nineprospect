-- Cron interne : rafraîchissement quotidien des documents Dolibarr
-- (devis, commandes, factures) de tous les prospects liés à un tiers.
--
-- La commande `app:dolibarr:refresh-documents` est planifiée dans la table
-- `cron`, exécutée par `app:cron` (supercronic, chaque minute). Premier
-- passage au prochain 6:00, puis tous les jours à 6:00
-- (CronSchedule préserve les créneaux fixes pour les intervalles en heures).
--
-- Idempotent : relançable sans dupliquer la ligne.
--
-- À exécuter après `doctrine:schema:update --force` (aucun lien schema/data).

INSERT INTO cron (command, description, statut, repeatcall, repeatexec, repeatinterval, nextexecdate)
SELECT 'app:dolibarr:refresh-documents',
       'Rafraîchit les documents Dolibarr (devis, commandes, factures) de tous les prospects liés',
       2, -- STATUT_OK : planifié, attend la prochaine date d'exécution
       0, 0, 86400,
       CASE WHEN CURTIME() < '06:00:00' THEN TIMESTAMP(CURRENT_DATE, '06:00:00')
            ELSE TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '06:00:00') END
WHERE NOT EXISTS (SELECT 1 FROM cron WHERE command = 'app:dolibarr:refresh-documents');

-- Vérification.
SELECT id, command, statut, nextexecdate, repeatinterval FROM cron WHERE command = 'app:dolibarr:refresh-documents';
