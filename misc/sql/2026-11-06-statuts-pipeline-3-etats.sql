-- Migration : statuts d'étape de pipeline 6 valeurs -> 3 états.
--
--   NON_DEMARRE (0), A_QUALIFIER (1)  -> A_FAIRE  (0)
--   RELANCER    (2), EN_ATTENTE (3)  -> EN_COURS (1)
--   OUI         (4), NON        (5)  -> REALISE  (2)
--
-- Sans perte : aucune ligne n'est supprimée, `date` et `modifie_par_id` sont
-- conservés tels quels.
--
-- Colonne temporaire obligatoire : les valeurs cibles (0, 1, 2) recouvrent
-- d'anciennes valeurs sources (0 à 5). Un UPDATE en place n'est donc pas
-- idempotent (rejoué, il convertirait REALISE=2 en EN_COURS=1).
--
-- À exécuter AVANT de déployer le nouveau code : sinon `suivant()` reçoit une
-- valeur hors cycle (3 à 5) et les badges affichent « Inconnu ».

-- Répartition avant migration.
SELECT statut, COUNT(*) AS total FROM pipeline_valeur GROUP BY statut ORDER BY statut;

ALTER TABLE pipeline_valeur
    ADD COLUMN statut_3etats SMALLINT NOT NULL DEFAULT 0 AFTER statut;

UPDATE pipeline_valeur
   SET statut_3etats = CASE
        WHEN statut IN (2, 3) THEN 1  -- Relancer, En attente -> En cours
        WHEN statut IN (4, 5) THEN 2  -- Oui, Non            -> Réalisé
        ELSE 0                        -- Non démarré, À qualifier -> À faire
   END;

ALTER TABLE pipeline_valeur
    DROP COLUMN statut,
    CHANGE COLUMN statut_3etats statut SMALLINT NOT NULL DEFAULT 0;

-- Vérification post-migration : seules les valeurs 0, 1 et 2 doivent rester.
SELECT statut, COUNT(*) AS total FROM pipeline_valeur GROUP BY statut ORDER BY statut;
