-- Fix données : prospect 5541
--  - flag « Contacté » posé, date de premier contact au 2025-01-01,
--    contacté par Ingrid ;
--  - qualification produit LaCanne : date + auteur (Ingrid) au 2025-01-01.
--
-- À exécuter en production (aucun accès DB depuis l'environnement local).
-- Idempotent : relançable sans effet.

-- Contacté le 2025-01-01 par Ingrid.
UPDATE prospect
SET contacte = 1,
    date_premier_contact = '2025-01-01',
    contacte_par_id = (SELECT id FROM user WHERE username = 'ingrid')
WHERE id = 5541;

-- Date de qualification LaCanne : 2025-01-01 par Ingrid.
UPDATE prospect_produit pp
JOIN produit pr ON pr.id = pp.produit_id
SET pp.qualifie_modifie_at = '2025-01-01 00:00:00',
    pp.qualifie_modifie_par_id = (SELECT id FROM user WHERE username = 'ingrid')
WHERE pp.prospect_id = 5541
  AND pr.titre LIKE '%LaCanne%';

-- Vérifications :
-- SELECT id, contacte, date_premier_contact, contacte_par_id FROM prospect WHERE id = 5541;
-- SELECT pr.titre, pp.qualifie, pp.qualifie_modifie_at, pp.qualifie_modifie_par_id
--   FROM prospect_produit pp JOIN produit pr ON pr.id = pp.produit_id
--  WHERE pp.prospect_id = 5541;
