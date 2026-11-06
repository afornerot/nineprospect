-- ============================================================================
-- Renommage de vocabulaire : cible -> produit, categorie -> cible
-- ============================================================================
--
-- À exécuter UNE FOIS, AVANT `doctrine:schema:update --force`.
--
-- Sans ce script, `schema:update --force` interprète le renommage comme une
-- création + deux suppressions :
--   CREATE TABLE produit / prospect_produit
--   DROP TABLE category / prospect_category        <-- perte de données
--
-- Correspondances (les identifiants sont conservés, donc les liens restent
-- valides et aucune donnée n'est perdue) :
--   cible            (id, titre, description, slug, active)  -> produit
--   category         (id, nom, description, slug, created_at) -> cible
--   prospect_cible   (lien + qualification)                   -> prospect_produit
--   prospect_category (ManyToMany prospect<->catégorie)       -> prospect_cible
--
-- Les noms d'index et de contraintes sont réalignés sur ceux que Doctrine
-- génère pour les nouvelles entités, afin que le `schema:update` qui suit soit
-- un no-op. L'ordre des étapes est imposé : les noms de FK sont uniques au
-- niveau du schéma, il faut donc libérer FK_A02377D1* (ancien prospect_cible)
-- avant de les réattribuer au nouveau prospect_cible.
--
-- Usage :
--   docker compose exec -T mariadb \
--     sh -c 'MYSQL_PWD=<mdp> mariadb -uroot nineprospect' \
--     < misc/sql/2026-11-06-renommage-cible-produit.sql
--
--   docker compose exec -T nineprospect php bin/console doctrine:schema:update --force
-- ============================================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- 1) Tables principales : libérer le nom `cible` avant de le réattribuer.
-- ----------------------------------------------------------------------------
RENAME TABLE cible            TO _tmp_cible_ex;
RENAME TABLE category         TO cible;
RENAME TABLE _tmp_cible_ex    TO produit;

-- ----------------------------------------------------------------------------
-- 2) Ancien prospect_cible (liens produit) -> prospect_produit
--    Les FK sont supprimées d'abord : leurs noms vont être réutilisés par la
--    table suivante.
-- ----------------------------------------------------------------------------
ALTER TABLE prospect_cible DROP FOREIGN KEY FK_A02377D11C42FCFA;
ALTER TABLE prospect_cible DROP FOREIGN KEY FK_A02377D1A96E5E09;
ALTER TABLE prospect_cible DROP FOREIGN KEY FK_A02377D1D182060A;

ALTER TABLE prospect_cible CHANGE cible_id produit_id INT(11) NOT NULL;

ALTER TABLE prospect_cible RENAME INDEX IDX_A02377D1D182060A TO IDX_EAE9FCF3D182060A;
ALTER TABLE prospect_cible RENAME INDEX IDX_A02377D1A96E5E09 TO IDX_EAE9FCF3F347EFB;
ALTER TABLE prospect_cible RENAME INDEX IDX_A02377D11C42FCFA TO IDX_EAE9FCF31C42FCFA;

RENAME TABLE prospect_cible TO prospect_produit;

ALTER TABLE prospect_produit
    ADD CONSTRAINT FK_EAE9FCF3D182060A FOREIGN KEY (prospect_id) REFERENCES prospect (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_EAE9FCF3F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_EAE9FCF31C42FCFA FOREIGN KEY (qualifie_modifie_par_id) REFERENCES user (id) ON DELETE SET NULL;

-- ----------------------------------------------------------------------------
-- 3) Ancien prospect_category (ManyToMany) -> prospect_cible
-- ----------------------------------------------------------------------------
ALTER TABLE prospect_category DROP FOREIGN KEY FK_8731431C12469DE2;
ALTER TABLE prospect_category DROP FOREIGN KEY FK_8731431CD182060A;

ALTER TABLE prospect_category CHANGE category_id cible_id INT(11) NOT NULL;

ALTER TABLE prospect_category RENAME INDEX IDX_8731431CD182060A TO IDX_A02377D1D182060A;
ALTER TABLE prospect_category RENAME INDEX IDX_8731431C12469DE2 TO IDX_A02377D1A96E5E09;

RENAME TABLE prospect_category TO prospect_cible;

ALTER TABLE prospect_cible
    ADD CONSTRAINT FK_A02377D1D182060A FOREIGN KEY (prospect_id) REFERENCES prospect (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_A02377D1A96E5E09 FOREIGN KEY (cible_id) REFERENCES cible (id) ON DELETE CASCADE;

-- ----------------------------------------------------------------------------
-- 4) Index uniques : le nom Doctrine dérive de la table ET de la colonne.
-- ----------------------------------------------------------------------------
-- produit (ex-cible) : UNIQ_E15DEC3B989D9B62 -> UNIQ_29A5EC27989D9B62
-- cible   (ex-category) : UNIQ_64C19C1989D9B62 -> UNIQ_E15DEC3B989D9B62
ALTER TABLE produit RENAME INDEX UNIQ_E15DEC3B989D9B62 TO UNIQ_29A5EC27989D9B62;
ALTER TABLE cible   RENAME INDEX UNIQ_64C19C1989D9B62 TO UNIQ_E15DEC3B989D9B62;

COMMIT;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- ----------------------------------------------------------------------------
-- 5) Contrôle : compter les lignes de chaque table renommée.
--    Repères attendus sur l'environnement de dev : produit=3, cible=2,
--    prospect_produit=1022, prospect_cible=128.
-- ----------------------------------------------------------------------------
SELECT 'produit'          AS table_, COUNT(*) AS lignes FROM produit
UNION ALL SELECT 'cible',            COUNT(*) FROM cible
UNION ALL SELECT 'prospect_produit', COUNT(*) FROM prospect_produit
UNION ALL SELECT 'prospect_cible',   COUNT(*) FROM prospect_cible;
