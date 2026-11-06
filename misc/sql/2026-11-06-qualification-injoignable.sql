-- Migration : qualification produit booléenne -> 4 états (non / oui /
-- hors_produit / injoignable).
--
-- `prospect_produit.qualifie` était un tinyint(1) nullable :
--   1     -> Oui
--   0     -> Hors produit
--   NULL  -> Non
--
-- Conversion sans perte : chaque ligne existante garde sa décision (et son
-- audit `qualifie_modifie_at` / `qualifie_modifie_par_id`). Aucune ligne n'est
-- supprimée.
--
-- À exécuter AVANT `doctrine:schema:update --force` : sans lui, Doctrine voit
-- un changement de type sur une colonne déjà peuplée et échoue (ou vide la
-- colonne). Script idempotent : relançable sans effet après conversion.

ALTER TABLE prospect_produit
    ADD COLUMN qualifie_enum VARCHAR(20) NULL AFTER qualifie;

UPDATE prospect_produit
   SET qualifie_enum = CASE
        WHEN qualifie = 1 THEN 'oui'
        WHEN qualifie = 0 THEN 'hors_produit'
        ELSE 'non'
   END;

-- Garde-fou : aucune ligne ne doit rester sans valeur avant de basculer.
SELECT COUNT(*) AS lignes_sans_qualification FROM prospect_produit WHERE qualifie_enum IS NULL;

ALTER TABLE prospect_produit
    DROP COLUMN qualifie,
    CHANGE COLUMN qualifie_enum qualifie VARCHAR(20) NOT NULL DEFAULT 'non';

-- Vérification post-migration : répartition des états.
SELECT qualifie, COUNT(*) AS total FROM prospect_produit GROUP BY qualifie;
