# Prospection (nineprospect)

Application de gestion de prospects et de campagnes de prospection (sources Meta),
sous les routes `/user/*` (firewall `main`, rôle `ROLE_USER`).

## Modules

| Module | Routes | Écran |
|--------|--------|-------|
| Tableau de bord | `/user/dashboard` | KPIs, pipeline, leads par campagne, top départements, actions en retard |
| Prospects | `/user/prospects`, `/show/{id}`, `/submit`, `/update/{id}`, `/delete/{id}` | Liste filtrée + fiche prospect (contacts, actions, pipeline) |
| Contacts | `/user/contacts` | Liste, création, modification, suppression |
| Actions | `/user/actions` | 3 vues (En retard / À venir / Réalisées), création, modification, suppression |
| Types d'action | `/user/action-types` | CRUD des types suggérés à la création d'une action (libellé, ordre, actif) |
| Campagnes | `/user/campagnes` | Budget, leads, coût par lead |
| Vagues | `/user/vagues` | Vagues de traitement (anciennement « sprints »), pipeline affecté |
| Pipelines | `/user/pipelines` | Étapes de pipeline (nom, ordre), pipeline par défaut |
| Géographie | `/user/geographie` | Répartition des prospects par département |

Le menu se trouve dans la barre latérale (section `PROSPECTION`), `templates/base.html.twig`.

## Modèle de données

- `Prospect` : l'entreprise (regroupée sur une clé normalisée) ou un particulier (1 prospect/ligne).
  Porte le suivi : campagne, vagues de traitement, qualification, affectation (`users`,
  ManyToMany), géolocalisation.
- `ProspectSprint` : lien **prospect ↔ vague** (table `prospect_sprint`, contrainte
  `uniq_prospect_sprint`). Un prospect appartient à **0..n vagues** (relance en vague
  suivante) ; ses valeurs de pipeline sont portées par `pipeline_valeur` (ci-dessous).
  **Vague courante** = dernière vague ajoutée (numéro le plus élevé, à défaut date
  d'ajout) : `Prospect::vagueCourante()`.
- `Pipeline` : liste d'étapes ordonnées (table `pipeline`, `nom` unique, un seul
  `par_defaut`). Sert de modèle à la création d'une vague et de repli (vague sans
  pipeline) — `PipelineRepository::getDefault()`.
- `PipelineEtape` : étape d'un pipeline (table `pipeline_etape`, contrainte unique
  `(pipeline_id, ordre)`), ex. Visio / Démo / Devis / Signature pour le pipeline par
  défaut. Le pipeline « affiche » une colonne **par étape** : il n'y a plus de colonnes
  pipeline figées.
- `PipelineValeur` : valeur d'une étape pour **un lien prospect↔vague**
  (table `pipeline_valeur`, contrainte unique `(prospect_sprint_id, etape_id)` ;
  `statut` SMALLINT `App\Enum\PipelineStatut` + `date` nullable). **Stockage creux** :
  la ligne n'est créée que si `statut != NON_DEMARRE` ou `date != null`, sinon elle est
  retirée ; l'absence de ligne se lit `NON_DEMARRE` (`ProspectSprint::statutPour()`).
- `Contact` : la personne, rattachée à un prospect ; contact principal choisi à l'import.
- `Action` : une action de prospection, **soit planifiée** (à faire à une date), **soit réalisée**
  (avec un résultat). Champs : `datePrevue` (échéance d'une planifiée, colonne SQL `date_relance`),
  `dateRealisee` (date effective de réalisation, colonne SQL `date`), `resultat`.
  Statut **dérivé** : réalisée si `dateRealisee != null`, planifiée sinon. Aucune colonne
  `faite` / `statut` : le statut n'est jamais stocké, il se recalcule à chaque requête.
- `Campagne` : campagne publicitaire Meta (identifiant externe, budget).
- `Sprint` : vague interne de traitement des leads (numéro, période) ; porte son
  **pipeline** (`pipeline_id`, ManyToOne, obligatoire au niveau applicatif, `RESTRICT`
  en base) et expose `getLiens()`.
- `Departement` : référentiel des 101 départements (fixtures).

Qualité des données : l'import **normalise et signale** (`anomalie` + `anomalieMotif`),
il ne supprime jamais d'enregistrement. Avoir un prospect dans plusieurs vagues est un
**cas normal** : l'anomalie « vagues de traitement multiples » n'existe plus.

### Écrans liés au pipeline

- **Pipelines** (`/user/pipelines`) : liste (nb d'étapes, nb de vagues), création,
  édition, suppression. L'édition affiche un mini-formulaire par étape (nom + ordre,
  suppression dédiée) et un formulaire d'ajout ; chaque ligne est son propre POST.
  Suppressions bloquées : pipeline **par défaut**, pipeline encore affecté à une vague,
  étape portant des valeurs (message en flash après redirection). Un ordre d'étape en
  doublon est refusé en erreur de formulaire (422, « Cet ordre est déjà utilisé dans ce
  pipeline. ») au lieu d'une exception SQL.
- **Vagues** (`/user/vagues`) : colonne Pipeline, choix du pipeline à la création
  (défaut : pipeline par défaut). La création renvoie sur la **fiche de la vague**
  (`/user/vagues/update/{id}`) qui ajoute une section **Prospects** : multiselect
  `select2` synchronisé (ajout **et** retrait à la validation, mêmes règles que la
  sélection de vagues côté formulaire prospect), nombre de prospects rattachés et lien
  vers `/user/prospects?sprint={id}`. Le numéro de vague est **unique** : un doublon
  devient une erreur de formulaire (422, « Ce numéro de vague est déjà utilisé. »)
  au lieu d'une exception SQL.
- Formulaire prospect : sélection multi-vagues (`prospect[sprints][]`) + section
  **Pipeline** générée depuis les étapes de la vague ciblée (`prospect[etape{ID}]` +
  `prospect[dateEtape{ID}]`, optionnelles). Une valeur soumise sans vague est refusée
  (« Sélectionnez au moins une vague de traitement pour renseigner le pipeline. »).
  Les champs d'un ancien pipeline sont ignorés (`allow_extra_fields`).
- Fiche prospect : badges des vagues + un tableau de pipeline **par vague**.
- Liste prospects : badges des vagues + pipeline de la vague courante (colonnes = étapes).
- Tableau de bord : sélecteur **Pipeline** ET sélecteur de vague sur le graphe Pipeline
  (défaut « Toutes les vagues » ; un prospect présent dans plusieurs vagues est compté
  dans chacune). Les libellés de séries sont les **noms d'étapes** du pipeline choisi.
- Import : les colonnes `Visio/Demo/Devis/Signature` du CSV sont associées aux étapes
  **par nom normalisé** (accents/espaces/casse ignorés) ; une colonne sans étape
  correspondante produit une anomalie.

### Migration schéma (sans migration Doctrine)

Le projet n'utilise pas les migrations : `doctrine:schema:update --force` fait foi.

> **Attention** : `--force` **DROPpe les tables inconnues du mapping** — ne jamais
> stocker une sauvegarde dans la base visée (`_bak_*` a été détruit par le schema
> update). Sauvegarder hors base : `mariadb-dump ... nineprospect > /tmp/avant.sql`.

Conversion des colonnes pipeline fixes vers `pipeline` / `pipeline_etape` /
`pipeline_valeur` (schéma réellement appliqué ici) :

```bash
# 1. sauvegarde hors base (voir avertissement ci-dessus)
docker compose exec -T mariadb mariadb-dump -unineprospect -p<mdp> --single-transaction nineprospect > /tmp/avant-pipeline.sql
# 2. structure (crée les 3 tables + sprint.pipeline_id, supprime les 8 colonnes)
docker compose exec -T nineprospect php bin/console doctrine:schema:update --force
# 3. fixtures : crée « Pipeline par défaut » + Visio/Démo/Devis/Signature si absent
docker compose exec -T nineprospect php bin/console app:init
# 4. rattacher toutes les vagues existantes au pipeline par défaut
docker compose exec -T mariadb mariadb -unineprospect -p<mdp> nineprospect -e \
  "UPDATE sprint SET pipeline_id = (SELECT id FROM pipeline WHERE par_defaut = 1) WHERE pipeline_id IS NULL"
# 5. reconstituer les valeurs depuis le CSV (commande idempotente, sans --reset)
docker compose exec -T nineprospect php bin/console app:import-prospects misc/import/Prospects.csv
```

L'étape 5 est indispensable : `d:s:update --force` supprime les anciennes colonnes
(sans sauvegarde possible dans la base) ; l'import rejoué est sans effet sur le reste
des données et réécrit `pipeline_valeur` à partir du CSV.

## Import des données (one-shot)

Source : `misc/import/Prospects.csv` (converti depuis `Prospects.ods`).

```bash
docker compose exec -T nineprospect php bin/console app:import-prospects misc/import/Prospects.csv
docker compose exec -T nineprospect php bin/console app:import-prospects misc/import/Prospects.csv --dry-run
docker compose exec -T nineprospect php bin/console app:import-prospects misc/import/Prospects.csv --reset
```

Le service `App\Service\Import\ProspectImporter` est **idempotent** (rejouer la commande
ne recrée rien) : il répare les lignes décalées, regroupe les entreprises, crée le contact
principal, les vagues, les campagnes et les actions, puis journalise les anomalies.

État attendu après import : 889 prospects, 904 contacts, 117 actions, 3 campagnes,
21 vagues (toutes rattachées au pipeline par défaut), **743 liens prospect↔vague**
(dont 28 prospects multi-vagues), 67 anomalies, 65 prospects hors Meta, 784 sans
département résolu, **38 valeurs de pipeline** (31 Visio, 4 Démo, 3 Devis, 0 Signature).
La base de développement peut contenir en plus des saisies manuelles passées par les
écrans (elles sont ignorées par l'idempotence de l'import).

## Qualité du code (à lancer avant chaque livraison)

```bash
# Analyse statique et style (hôte, à la racine du projet)
php vendor/bin/phpstan analyse --no-progress
php vendor/bin/php-cs-fixer fix

# Lint des gabarits + tests (conteneur)
docker compose exec -T nineprospect php bin/console lint:twig templates
docker compose exec -T nineprospect php vendor/bin/phpunit
```

## Base de test

Les tests utilisent `nineprospect_test` (`dbname_suffix: '_test'` en env `test`).
Préparer la base une fois :

```bash
docker compose exec -T mariadb sh -lc \
  'MYSQL_PWD=<root> mariadb -uroot -e "CREATE DATABASE IF NOT EXISTS nineprospect_test;
   GRANT ALL PRIVILEGES ON nineprospect_test.* TO '"'"'nineprospect'"'"'@'"'"'%'"'"';"'
docker compose exec -T mariadb sh -lc \
  'MYSQL_PWD=<root> mariadb-dump -uroot --single-transaction --add-drop-table nineprospect \
   | MYSQL_PWD=<root> mariadb -uroot nineprospect_test'
docker compose exec -T -e APP_ENV=test nineprospect php bin/console app:init
```

`app:init` en env `test` charge les fixtures : le compte admin reçoit **toujours** le
mot de passe `APP_SECRET` de `.env.test` (même si la base a été recopiée depuis la dev,
d'où un import de dump puis `app:init`). `PipelineFixtures` est idempotent : si un
pipeline par défaut existe déjà (cas d'un dump recopié depuis la dev), elle ne crée rien.
Sans base de test, les tests liés à la base sont signalés comme « skipped ».
