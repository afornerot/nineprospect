# Prospection (nineprospect)

Application de gestion de prospects, campagnes Meta, vagues de traitement et
pipeline commercial, sous les routes `/user/*` (firewall `main`, rôle `ROLE_USER`).

## Modules

| Module | Routes | Écran |
|--------|--------|-------|
| Tableau de bord | `/user/dashboard` | KPIs (prospects, contacts, actions en retard / à venir, prospects non contactés, non qualifiés), pipeline, leads par campagne, top départements, actions en retard |
| Prospects | `/user/prospects`, `/show/{id}`, `/submit`, `/update/{id}`, `/delete/{id}`, `/verify/{id}`, `/verify/{id}/apply` | Liste filtrée + fiche prospect (contacts, actions, pipeline, carte Mapbox). Vérification des données via Annuaire des Entreprises (data.gouv.fr) avec modale inline |
| Créer depuis l'Annuaire | `/user/prospects/from-annuaire`, `/from-annuaire/create` | Page dédiée pour créer un prospect en cherchant par SIREN / raison sociale — n'écrase aucun prospect existant |
| Carte | `/user/carte` | Vue plein écran Mapbox de tous les prospects géolocalisés. Style sélectionnable (Rues / Clair / Sombre / Satellite / Terrain, défaut Clair) |
| Contacts | `/user/contacts` | Liste, création, modification, suppression |
| Actions | `/user/actions` | 3 vues (En retard / À venir / Réalisées), création, modification, suppression, clôture/déclôture |
| Agenda | `/user/agenda`, `/user/agenda/events`, `/user/agenda/csr/{id}`, `/user/agenda/csr/{id}/set-date` | Calendrier FullCalendar (vues mois/semaine/liste). Création d'action par clic sur une date, déplacement drag & drop d'événements, retour à la date mise à jour |
| Types d'action | `/user/action-types` | CRUD des types suggérés à la création d'une action (libellé, ordre, actif) |
| Campagnes | `/user/campagnes` | Budget, leads, coût par lead |
| Vagues | `/user/vagues` | Vagues de traitement (anciennement « sprints »), pipeline affecté |
| Pipelines | `/user/pipelines` | Étapes de pipeline (nom, ordre), pipeline par défaut |
| Géographie | `/user/geographie` | Répartition des prospects par département |

Le menu se trouve dans la barre latérale (section `PROSPECTION`), `templates/base.html.twig`.

## Modèle de données

- `Prospect` : l'entreprise (regroupée sur une clé normalisée) ou un particulier (1 prospect/ligne).
  Porte le suivi : campagne, vagues de traitement, qualification, affectation (`users`,
  ManyToMany), géolocalisation, informations d'identification enrichies (SIREN, SIRET,
  NAF, RCS/RM, N° TVA, ID Dolibarr), email, téléphone, LinkedIn, site web, latitude/longitude
  (calculées via API Adresse sur autocompletion ou via l'Annuaire des Entreprises).
  - **Contacté** : booléen simple `contacte` (cycle `null → true → false → true`,
    date premier contact syncronisée automatiquement à `true`).
  - **Qualifié** : `qualifie` nullable (`null` = Non, `true` = Oui, `false` = Hors cible).
  - **Prochaine action** : `Prospect::getProchaineAction()` calcule l'action planifiée la
    plus proche dans le futur (`datePrevue >= today`, non réalisée).
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
  **Cycle de statut cliquable** sur la liste et la fiche (`Prospect::cycleEtape()`,
  `PipelineStatut::suivant()`) : avance `NON_DEMARRE → OUI → NON → EN_COURS → PERDU →
  A_RELANCER → NON_DEMARRE` ; pose la date du jour à chaque clic et l'efface
  quand le statut revient à `NON_DEMARRE`.
- `Contact` : la personne, rattachée à un prospect ; contact principal choisi à l'import.
  Téléphone validé via `libphonenumber` (format E164 stocké, `default_region='FR'`).
- `Action` : une action de prospection, **soit planifiée** (à faire à une date), **soit réalisée**
  (avec un résultat). Champs : `datePrevue` (échéance d'une planifiée, colonne SQL `date_relance`),
  `dateRealisee` (date effective de réalisation, colonne SQL `date`), `typeAction`
  (obligatoire, suggestions paramétrables), `aFairePar` (FK user), `realisePar` (FK user),
  `resultat`. Statut **dérivé** : réalisée si `dateRealisee != null`, planifiée sinon.
  Aucune colonne `faite` / `statut` : le statut n'est jamais stocké, il se recalcule à
  chaque requête.
- `Campagne` : campagne publicitaire Meta (identifiant externe, budget).
- `Sprint` : vague interne de traitement des leads (numéro, période) ; porte son
  **pipeline** (`pipeline_id`, ManyToOne, obligatoire au niveau applicatif, `RESTRICT`
  en base) et expose `getLiens()`.
- `Departement` : référentiel des 101 départements (fixtures).
- `ActionTypeDefaut` : types d'action suggérés à la création d'une action
  (`libellé`, `ordre`, `actif`). CRUD via `/user/action-types`.

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
- Fiche prospect : badges des vagues + un tableau de pipeline **par vague** avec
  bouton de cycle cliquable sur chaque étape (badge `js-cycle-etape`).
- Liste prospects : badges des vagues + pipeline de la vague courante (colonnes = étapes)
  avec cycle cliquable. Colonne supplémentaire **« Prc Action »** : lien vers la fiche
  de la prochaine action planifiée (`Prospect::getProchaineAction()`).
- Tableau de bord : sélecteur **Pipeline** ET sélecteur de vague sur le graphe Pipeline
  (défaut « Toutes les vagues » ; un prospect présent dans plusieurs vagues est compté
  dans chacune). Les libellés de séries sont les **noms d'étapes** du pipeline choisi.
  Le pipeline par défaut (`par_defaut=1`) est sélectionné automatiquement s'il n'y a pas
  de query param `?pipeline=`.
- Import : les colonnes `Visio/Demo/Devis/Signature` du CSV sont associées aux étapes
  **par nom normalisé** (accents/espaces/casse ignorés) ; une colonne sans étape
  correspondante produit une anomalie.

## Liste des prospects — filtres et tri

La liste (`/user/prospects`, `templates/prospects/list.html.twig`) supporte
les filtres suivants (query string) :

- `q` : recherche plein texte (nom, ville, email, nomComplet d'un contact)
- `campagne`, `sprint`, `departement`, `utilisateur` : filtres par FK
- `contacte` : `oui` (match exact `true`) ou `non` (match `IS NULL OR false`,
  c'est-à-dire tous les prospects sans `true`)
- `qualifie` : `oui` (match exact `true`), `non` (`IS NULL`, défaut Non), `horscible`
  (match exact `false`)

Les filtres « En anomalie », « Hors Meta », « Sans département » ont été
**retirés** de la barre de filtres (les KPI correspondants ont aussi été
retirés du dashboard) ; les données restent en base et restent filtrables
programmatiquement via le repository (`countAnomalies`, `countHorsCampagne`,
`countProspectsSansDepartement`).

Boutons en haut de la liste :
- **Ajouter** : `/user/prospects/submit` (form prospect vierge)
- **Créer depuis l'Annuaire** : `/user/prospects/from-annuaire` (page dédiée
  décrite plus bas)

## Annuaire des Entreprises (data.gouv.fr)

Mécanisme d'enrichissement automatique des prospects via l'API publique
[Annuaire des Entreprises](https://recherche-entreprises.api.gouv.fr)
(alias opéré par Etalab/DINUM). Pas de clé API, throttling implicite.

### Services

- `src/Service/AnnuaireEntreprises.php` :
  - `search(string $q)` : GET `recherche-entreprises.api.gouv.fr/search?q=…&per_page=10`
  - `searchBySiren(string $siren)` : filtre match exact sur le champ `siren`
  - `normalize(array $row)` : extrait `siren`, `siret`, `nom`, `adresse`,
    `code_postal`, `ville`, `naf`, `lat`, `lon`, `dept`, `ville_greffe`
  - `buildRcs(string $siren, string $deptCode)` : produit `<SIREN> RCS <VILLE>`
    via table statique departement → ville du greffe (table de correspondance
    de 100+ entrées stockée en constante de classe, couvrant métropole +
    Corse + DOM 971-976)
  - `computeTva(string $siren)` : TVA intracommunautaire française
    `FR + ((12 + 3 × (SIREN mod 97)) mod 97) formaté sur 2 chiffres + SIREN`
    → 13 caractères
  - Toutes les méthodes sont tolérantes aux erreurs HTTP / réseau (try/catch +
    logger, retour `['results' => [], 'error' => '…']`)
- `src/Service/AdresseApi.php` :
  - `geocode(string $adresse, string $cp, string $ville)` : GET
    `api-adresse.data.gouv.fr/search/?q=…&postcode=…&limit=1`
    → `{lat, lon}` ou `null`

### Endpoint `verify` (modale inline depuis la fiche prospect)

- Bouton **« Vérifier les données »** dans la fiche prospect
  (`templates/prospects/show.html.twig`, classe `.js-verify-prospect`).
- Click → fetch GET `/user/prospects/verify/{id}?q=…` (le query `q` est
  optionnel : par défaut le nom du prospect).
- Le serveur retourne le fragment HTML de la modale Bootstrap
  (`templates/prospects/_verify_modal.html.twig`) injectée dans le DOM puis
  ouverte par `bootstrap.Modal.getOrCreateInstance()`.
- La modale contient : champ de recherche pour changer le critère, liste
  radio de résultats (SIREN, SIRET, NAF, adresse, ville), bouton
  **« Appliquer ce résultat »** (`js-verify-apply`).
- Submit → fetch POST `/user/prospects/verify/{id}/apply` (JSON :
  `{siren, csrf_token, redirect}`). Le serveur re-vérifie le SIREN côté API
  avant d'écrire, et applique via `ProspectController::applyAnnuaire()` qui :
  - écrase SIREN, SIRET, NAF, adresse, code postal, ville, pays (`FR`), RCS/RM,
    TVA, **nom** et **latitude/longitude** (re-géocodage via AdresseApi si
    l'API ne fournit pas les coords)
- CSRF : token `verify-prospect{id}` lié à la session (endpoint `/csrf/{id}`
  pour le récupérer côté JS).

### Endpoint `from-annuaire` (page dédiée)

Bouton **« Créer depuis l'Annuaire »** dans la liste prospects à côté de
« Ajouter ». Le bouton ouvre `/user/prospects/from-annuaire` :

- Page avec champ de recherche (`?q=…`) + liste radio de résultats
  + bouton **« Créer ce prospect »**.
- Submit → POST `/user/prospects/from-annuaire/create` (JSON :
  `{siren, csrf_token}`).
- Le serveur :
  1. Valide CSRF (`from-annuaire-create`).
  2. Valide SIREN (9 chiffres).
  3. Re-cherche par SIREN exact côté API.
  4. Vérifie l'unicité : `prospect.siren` doit être unique (sinon 409
     Conflict avec `existingRedirect` pointant vers le prospect existant).
  5. Crée un nouveau `Prospect` avec tous les champs remplis.
- Redirection vers `/user/prospects/update/{id}` (form d'édition pour
  finaliser campagne, vagues, etc.).

### Tests

- `tests/Service/AnnuaireEntreprisesTest.php` (20 tests, 42 assertions) :
  parsing JSON, gestion erreurs HTTP 503/500, extraction département
  (Corse / DOM), calcul RCS, calcul TVA (avec SIREN de référence).
- `tests/Service/AdresseApiTest.php` (4 tests, 7 assertions) : parsing
  coordonnées, gestion erreurs.
- `tests/ProspectVerifyTest.php` (7 tests, 13 assertions) : GET modale,
  rejet CSRF, SIREN invalide, SIREN inconnu, page dédiée, création.

## Carte (Mapbox)

- Vue plein écran `/user/carte` (`src/Controller/CarteController.php`).
  Le lien est dans la sidebar (`templates/base.html.twig`, icône
  `fa-map-location-dot`).
- Affiche tous les prospects géolocalisés (`ProspectRepository::findGeolocalises()`,
  retourne ceux avec `latitude` ET `longitude` non nulls + joins
  `campagne` et `departement`).
- Token Mapbox via `#[Autowire('%mapboxPublicToken%')]` — variable
  `MAPBOX_PUBLIC_TOKEN` dans `.env.local` (vide par défaut ; un
  avertissement s'affiche si vide).
- Mapbox GL JS v3.6 via CDN. 5 styles whitelistés (sélection depuis un
  `<select>` qui soumet l'URL en `?style=…`) :
  - `streets-v12` (Rues)
  - `light-v11` (Clair, **défaut**)
  - `dark-v11` (Sombre)
  - `satellite-streets-v12` (Satellite)
  - `outdoors-v12` (Terrain)
- Vue initiale : `fitBounds` sur les bounds fixes France métropolitaine
  `[[-5.5, 41.0], [9.7, 51.2]]` + `padding: 40`. Marqueur bleu par prospect,
  popup au clic (nom + adresse + SIREN + lien fiche). Navigation Mapbox
  native (`NavigationControl`, `ScaleControl`).
- Le pavé carte de la fiche prospect (`templates/prospects/show.html.twig`)
  affiche la carte centrée sur le prospect (zoom 14) si lat/lon sont définis.
  Fallback warning si token Mapbox absent.

## Agenda (FullCalendar)

- Page `/user/agenda` (`src/Controller/AgendaController.php`) : calendrier
  FullCalendar v6.1.15 via CDN, locale `fr`, vues `dayGridMonth` /
  `timeGridWeek` / `listWeek`.
- Endpoint JSON `/user/agenda/events` (paramètres `start`/`end`) :
  renvoie les actions via `ActionRepository::findForAgendaRange(start, end)`
  (planifiées `datePrevue < end` + réalisées `[start, end[`).
  Couleurs : vert `#198754` si faite, orange `#fd7e14` sinon.
  Tooltip natif via `eventDidMount` + `title` attr.
- **Création d'action** : clic sur une date → `/user/actions/submit?date=YYYY-MM-DD`.
  Le contrôleur initialise `datePrevue` à la date cliquée.
- **Drag & drop** d'un événement : endpoint POST
  `/user/actions/set-date/{id}` (JSON `{date, csrf_token}`), CSRF lié
  session (`set-date-action{id}`). Met à jour `datePrevue` (ou `dateRealisee`
  si l'action est déjà clôturée). Token récupéré via
  `GET /user/agenda/csrf/{id}`. En cas d'erreur serveur, `info.revert()`
  annule le déplacement côté UI.
- **Retour à l'agenda** : `redirect=/user/agenda?date=YYYY-MM-DD` où la
  date est la date prévue (ou réalisée) de l'action. Le contrôleur
  `ActionController::update()` **régénère** ce redirect avec la date
  actuelle si l'utilisateur a modifié `datePrevue` (ou `dateRealisee`) — le
  retour cible la date mise à jour, pas l'ancienne.
- `initialDate` du calendrier lit `?date=YYYY-MM-DD` au load.

## Champs étendus du prospect

- **SIREN / SIRET / NAF / RCS/RM / N° TVA / ID Dolibarr** : stockés sur
  `Prospect`, pré-remplis via l'Annuaire des Entreprises.
- **Email / Téléphone / LinkedIn / Site web** : stockés sur `Prospect`,
  **auto-initialisés depuis le contact principal** à la soumission du
  formulaire si le champ prospect est vide (helpers
  `appliquerEmailDepuisContactPrincipal`,
  `appliquerTelephoneDepuisContactPrincipal`,
  `appliquerLinkedinDepuisContactPrincipal` dans `ProspectController`).
  Le téléphone est validé via `App\Form\Type\PhoneNumberType` (libphonenumber,
  format E164).
- **Adresse / Pays / Latitude / Longitude** : saisis via l'autocompletion
  de l'API Adresse (data.gouv.fr) côté formulaire :
  - `templates/prospects/edit.html.twig` + `public/lib/app/app.js` : handler
    `dateClick` → fetch API Adresse, suggestions clavier (↑/↓/Échap/Entrée),
    clic sur une suggestion → remplit `rue`, `code_postal`, `ville`,
    `latitude`, `longitude`, `pays='FR'`, et département via match
    `data-numero` sur l'`<option>` du `<select>` (2 chiffres métropole,
    3 chiffres DOM 97x/98x). L'input n'a pas de `name` côté visible pour
    éviter l'autocomplétion Firefox native (qui contredit nos suggestions) ;
    un `<input type="hidden">` porte la valeur à la soumission.
- **Géolocalisation** : recalculée via AdresseApi lors de la mise à jour
  Annuaire, sinon saisie manuelle latitude/longitude.

## Migration schéma (sans migration Doctrine)

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
# 5. (optionnel) réimporter un fichier via l'écran UI /user/cibles/import
```

L'étape 5 est indispensable : `d:s:update --force` supprime les anciennes colonnes
(sans sauvegarde possible dans la base) ; l'import rejoué est sans effet sur le reste
des données et réécrit `pipeline_valeur` à partir du CSV.

Pour ajouter un nouveau champ `Prospect` (ex. `idDolibarr`) :

```bash
# 1. ajouter la propriété + l'accesseur dans src/Entity/Prospect.php
# 2. ajouter le champ dans src/Form/ProspectType.php
# 3. mettre à jour le schéma :
docker compose exec -T nineprospect php bin/console doctrine:schema:update --force
# 4. (optionnel) enrichir via l'écran d'import UI /user/cibles/import
```

Pas de migration Doctrine générée : le `d:s:update --force` est la procédure
officielle du projet (cf. `doc/installation.md`, section « Schéma BDD »).
Les `UPDATE` SQL directs sur les données sont autorisés (ex. correction
du `par_defaut` du pipeline « Pipeline par défaut »), mais **jamais**
sur le schéma.

## Import d'une cible depuis un tableur (UI)

L'application propose un import interactif depuis `/user/cibles/import`
pour constituer une liste depuis un tableur (`.xlsx` ou `.csv`).

**Flux** :
1. L'utilisateur choisit le mode : nouvelle cible ou cible existante.
2. Optionnellement : une ou plusieurs cibles supplémentaires + une campagne d'affectation.
3. Upload du tableur (modèle téléchargeable depuis l'écran).
4. **Écran de pré-import** : affiche chaque ligne, les erreurs de format et les doublons
   détectés (prospect ou contact déjà existant). L'utilisateur choisit l'action par ligne
   (Importer / Modifier / Ignorer). Un bouton "Tout ignorer" permet de marquer
   l'ensemble des lignes disponibles en SKIP.
5. **Rapport final** : compteurs (créés, rattachés, ignorés, en erreur).

**Format attendu** (10 colonnes, une ligne = un contact potentiel) :
- **Obligatoire** : `Organisation` (seule cette colonne est requise)
- **Facultatives** : `Nom`, `Prénom`, `Courriel`, `Fonction`, `Téléphone`, `Adresse`, `Code postal`, `Ville`, `Site web`

**Règles de création** :
- Un Prospect est créé dès qu'une ligne a une Organisation
- Un Contact n'est créé que si **Nom ET Prénom** sont présents (les deux ensemble)
- L'email du row est copié sur `Prospect.email` (fill-only) si **aucun Contact n'est créé** (= Nom OU Prénom manquant)
- L'email du row va uniquement sur `Contact.email` si un Contact est créé (pas de duplication sur Prospect)
- Plusieurs lignes avec la même `Organisation` → 1 seul Prospect avec plusieurs Contacts (regroupement)

**Regroupement** : les lignes sont regroupées par `Organisation` normalisée
(insensible casse/accents/ponctuation). Chaque groupe produit 1 Prospect ;
chaque ligne produit 1 Contact.

**Détection des doublons** : email normalisé ou `cleEntreprise` ; les doublons
intra-fichier sont signalés. Par défaut l'action est "Ignorer" (sécuritaire) ;
l'utilisateur peut choisir "Importer" ou "Modifier" (lien vers Prospect existant)
au cas par cas.

**Notes techniques** :
- Fichier temporaire stocké dans `sys_get_temp_dir()/nine_import/`, supprimé
  après execute.
- Service : `App\Service\Import\ImportAnalyzer` (analyse dry-run),
  `App\Service\Import\ImportExecutor` (écrit en base).
- L'ancienne commande `app:import-prospects` (CLI) a été supprimée : elle a été
  utilisée pour initialiser la base une fois, mais n'a plus d'usage opérationnel.

## Nettoyage des orphelins (SQL brut)

Si vous supprimez manuellement des Prospects, Cibles, Campagnes ou Sprints en SQL,
les tables liées (`contact`, `action`, `prospect_cible`, `prospect_user`,
`prospect_sprint`) conservent des FK pointant dans le vide. Doctrine refuse alors
de charger ces entités ("Entity of type X for IDs id(Y) was not found").

Les `cascade: ['remove']` ne fonctionnent QUE via l'ORM. Pour nettoyer la base
après une purge manuelle, utilisez :

```bash
docker compose exec -T nineprospect php bin/console app:cleanup-orphans --dry-run  # preview
docker compose exec -T nineprospect php bin/console app:cleanup-orphans --yes      # exécution
```

La commande scanne 8 catégories (actions / contacts / liens prospect-user /
prospect_sprint × 2 / prospect_cible × 2 / prospects orphelins en campagne) et
supprime en transaction.

## Géocodage des Prospects (API Adresse)

À chaque création ou enrichissement d'adresse d'un Prospect, un message
`GeocodeProspectMessage` est dispatché sur le transport `async` pour géocoder
le Prospect via [l'API Adresse du gouvernement](https://api-adresse.data.gouv.fr).

Règles :
- Dispatch uniquement si le Prospect a une adresse utilisable ET n'a pas
  encore de coordonnées (pas de re-géocodage si lat/lon existent déjà — ça
  éviterait d'écraser une correction manuelle).
- L'API Adresse est appelée avec `limit=2` ; si elle renvoie 2+ résultats
  (= géocodage ambigu), aucune coordonnée n'est assignée.
- Si une seule adresse est renvoyée, lat/lon sont mis à jour.

Pour re-géocoder un Prospect manuellement :

```bash
docker compose exec -T nineprospect php bin/console app:geocode-prospect <id>
```

Pour dispatcher un batch de tous les Prospects sans coordonnées :

```bash
docker compose exec -T nineprospect php bin/console app:geocode-prospects [--dry-run] [--limit N]
```

Les messages sont consommés par le worker Messenger, lancé automatiquement
au démarrage du container par `misc/script/reconfigure.sh` :

```bash
# Le worker tourne en arrière-plan automatiquement (PID supervisé par reconfigure.sh)
docker compose exec -T nineprospect ps -ef | grep messenger
# Logs du worker :
docker compose exec -T nineprospect tail -f var/log/messenger-worker.log

# Pour rejouer manuellement les messages en attente :
docker compose exec -T nineprospect php bin/console messenger:consume async failed --limit=20
```

## Qualité du code (à lancer avant chaque livraison)

```bash
# Analyse statique et style (hôte, à la racine du projet)
php vendor/bin/phpstan analyse --no-progress
php vendor/bin/php-cs-fixer fix

# Lint des gabarits + tests (conteneur)
docker compose exec -T nineprospect php bin/console lint:twig templates
docker compose exec -T nineprospect php vendor/bin/phpunit
```

Le projet vise **117 tests verts** (3 skipped quand la base de test n'est pas
disponible) couvrant : prospection, contacts, CRUD générique, agenda, agenda
CSRF/déplacement, AnnuaireEntreprises, AdresseApi, services, etc.

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
