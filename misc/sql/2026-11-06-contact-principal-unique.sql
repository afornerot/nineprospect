-- Correctif de données : plus d'un contact principal par prospect.
--
-- Règle métier : un prospect a AU PLUS un contact principal, et il en garde
-- toujours un tant qu'il a des contacts (on change de principal en en
-- promouvant un autre, pas en décochant).
--
-- L'application garantit désormais l'unicité (ContactController), ce script
-- répare les doublons déjà présents : on conserve le principal le plus ancien
-- (plus petit id) et on démarque les suivants.

-- Avant : liste des doublons.
SELECT prospect_id, GROUP_CONCAT(id ORDER BY id) AS principaux
  FROM contact
 WHERE est_principal = 1
 GROUP BY prospect_id
HAVING COUNT(*) > 1;

UPDATE contact c
  JOIN (
        SELECT prospect_id, MIN(id) AS garde
          FROM contact
         WHERE est_principal = 1
         GROUP BY prospect_id
        HAVING COUNT(*) > 1
       ) d ON d.prospect_id = c.prospect_id
   SET c.est_principal = 0
 WHERE c.est_principal = 1
   AND c.id <> d.garde;

-- Après : plus aucun doublon.
SELECT prospect_id, GROUP_CONCAT(id ORDER BY id) AS principaux
  FROM contact
 WHERE est_principal = 1
 GROUP BY prospect_id
HAVING COUNT(*) > 1;
