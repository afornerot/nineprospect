# Prospection (nineprospect)

Application de gestion de prospects, campagnes Meta, vagues de traitement et
pipeline commercial, sous les routes `/user/*` (firewall `main`, rôle `ROLE_USER`).

## Modules

| Module | Routes | Écran |
|--------|--------|-------|
| Tableau de bord | `/user/dashboard` | KPIs (prospects, contacts, actions en retard / aujourd'hui / à venir, prospects non contactés, non qualifiés), qualification des prospects (courbe mensuelle), pipeline (avec ses filtres), actions en retard / aujourd'hui / à venir |
| Prospects | `/user/prospects`, `/show/{id}`, `/submit`, `/update/{id}`, `/delete/{id}`, `/verify/{id}`, `/verify/{id}/apply` | Liste filtrée + fiche prospect (contacts, actions, pipeline, carte Mapbox). Vérification des données via Annuaire des Entreprises (data.gouv.fr) avec modale inline |
| Créer depuis l'Annuaire | `/user/prospects/from-annuaire`, `/from-annuaire/create` | Page dédiée pour créer un prospect en cherchant par SIREN / raison sociale — n'écrase aucun prospect existant |
| Carte | `/user/carte` | Vue plein écran Mapbox de tous les prospects géolocalisés. Style sélectionnable (Rues / Clair / Sombre / Satellite / Terrain, défaut Clair) |
| Contacts | `/user/contacts` | Liste, création, modification, suppression |
| Actions | `/user/actions` | 4 vues (En retard / Aujourd'hui / À venir / Réalisées), création, modification, suppression, clôture/déclôture |
| Agenda | `/user/agenda`, `/user/agenda/events`, `/user/agenda/csr/{id}`, `/user/agenda/csr/{id}/set-date` | Calendrier FullCalendar (vues mois/semaine/liste). Agenda personnel par défaut (sélecteur de collaborateur), réaffectation d'actions. Création d'action par clic sur une date, déplacement drag & drop d'événements, retour à la date mise à jour |
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
  - **Auteur du contact** : `contactePar` (FK `user`, `SET NULL`) signe le
    passage à `contacte = true` avec l'utilisateur qui l'a déclenché. Posé par
    `App\EventListener\ContacteModificationListener` (`prePersist` /
    `preUpdate`) qui ne réagit qu'à un changement effectif de `contacte` :
    passage à `true` → auteur de la session ; retour à `false`/`null` → champ
    vidé. Un enregistrement sans changement du drapeau (édition de notes,
    re-datage manuel) ne resigne pas. Reste `null` hors session (import,
    commande, formulaire public) et pour l'historique antérieur à l'audit.
    La fiche affiche deux lignes : « Contacté le » (date) et « Contacté par »
    (auteur, `—` si inconnu) ; la liste reprend l'auteur dans le `title` du
    switch et l'export PDF affiche « le JJ/MM/AAAA par \<auteur\> ».
  - **Qualifié** : `qualifie` (enum `App\Enum\QualificationProduit`, stockée en
    VARCHAR : `non`, `oui`, `hors_produit`, `injoignable`), porté par le lien
    `ProspectProduit` (une qualification par couple prospect × produit). Le badge
    clique en boucle `Non → Oui → Hors produit → Injoignable → Non` ; un lien créé
    (import, affectation en masse, formulaire public) naît « Non ».
  - **Audit de la qualification** : `qualifieModifieAt` (datetime) et
    `qualifieModifiePar` (FK `user`, `SET NULL`) mémorisent la date et l'auteur du
    dernier **changement effectif** de `qualifie`. Renseignés par
    `App\EventListener\QualifieModificationListener` (`prePersist` / `preUpdate`
    Doctrine) : tous les writers passent par `setQualifie()`, donc l'audit est
    unique. Un enregistrement qui ne change pas la valeur ne réhorodate pas ;
    un lien créé directement « Oui »/« Hors produit »/« Injoignable » est daté
    dès la création. Le retour à « Non » **vide** les deux champs : cet état ne
    porte aucune décision. `qualifieModifiePar` reste `null` pour un changement
    hors session (import, commande, formulaire public) et s'affiche alors
    « système ». Affiché sous le titre « Qualifiée par » (fiche, édition, export PDF).
  - **Prochaine action** : `Prospect::getProchaineAction()` calcule l'action planifiée la
    plus proche dans le futur (`datePrevue >= today`, non réalisée).
  - **Dernière action (liste)** : `Prospect::getDerniereAction()` renvoie l'action à
    afficher dans la colonne « Prc Action », priorité : en retard la plus récente →
    aujourd'hui → à venir la plus proche → planifiée sans date → réalisée la plus récente.
    Une action en retard reste visible même quand une action future existe.
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
  `statut` SMALLINT `App\Enum\PipelineStatut` + `date` nullable + `modifiePar`,
  FK `user` `SET NULL`). **Trois statuts seulement** : `A_FAIRE` (0),
  `EN_COURS` (1), `REALISE` (2). Les six anciennes valeurs ont été repliées
  sans perte : `NON_DEMARRE`/`A_QUALIFIER` → À faire, `RELANCER`/`EN_ATTENTE` →
  En cours, `OUI`/`NON` → Réalisé. **Stockage creux** :
  la ligne n'est créée que si `statut != A_FAIRE` ou `date != null`, sinon elle est
  retirée ; l'absence de ligne se lit `A_FAIRE` (`ProspectSprint::statutPour()`).
  **Cycle de statut cliquable** sur la liste et la fiche (`Prospect::cycleEtape()`,
  `PipelineStatut::suivant()`) : avance `À faire → En cours → Réalisé → À faire` ;
  pose la date du jour à chaque clic et l'efface quand le statut revient à `A_FAIRE`.
  **Qui et quand** : `date` et `modifiePar` mémorisent le dernier changement de
  statut. L'auteur est posé par
  `App\EventListener\PipelineValeurModificationListener` (`prePersist` / `preUpdate`
  Doctrine), qui ne réagit qu'à un changement effectif de `statut` — éditer la seule
  date ne resigne pas la valeur. Tous les writers passent par
  `ProspectSprint::definirStatut()` / `definirValeur()`, l'audit est donc unique et
  indépendant des contrôleurs. `modifiePar` reste `null` hors session (import,
  commande) et s'affiche alors comme la date seule. Restitution :
  `PipelineValeur::dateEtAuteur()` → « 12/03/2024 · alice », utilisé dans la cellule
  date du tableau de pipeline de la fiche et de l'export PDF, ainsi que dans la
  réponse JSON du cycle (mise à jour de la cellule après clic).
- `Contact` : la personne, rattachée à un prospect ; contact principal choisi à l'import.
  Téléphone validé via `libphonenumber` (format E164 stocké, `default_region='FR'`).
- `Action` : une action de prospection, **soit planifiée** (à faire à une date), **soit réalisée**
  (avec un résultat). Champs : `datePrevue` (échéance d'une planifiée, colonne SQL `date_relance`),
  `dateRealisee` (date effective de réalisation, colonne SQL `date`), `typeAction`
  (obligatoire, suggestions paramétrables), `aFairePar` (FK user), `realisePar` (FK user),
  `resultat`. Le **statut est dérivé**, jamais stocké, et se recalcule à chaque requête
  sur la **date seule** (heure ignorée) :
  - **Réalisée** : `dateRealisee != null`
  - **En retard** : planifiée & `datePrevue < aujourd'hui`
  - **Aujourd'hui** : planifiée & `datePrevue = aujourd'hui`
  - **À venir** : planifiée & (`datePrevue > aujourd'hui` ou `null`)
  Helpers : `Action::statut()` (clé `retard|aujourdhui|avenir|faites`), `statutLabel()`,
  `statutColor()`, `dateAffichee()`, `estEnRetard()`.
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
- **Affecter en masse** (`/user/vagues/bulk`) : deux panneaux. À gauche le
  listing des prospects avec ses filtres (vague, **produit**, **cible**,
  recherche) ; à droite la sélection à affecter. Le tableau de gauche est le
  plus large (7/5 en ≥ xl, empilés en dessous). Un prospect placé à droite
  **disparaît de gauche** et le réintègre au retrait : la ligne est masquée
  (`data-selectionne`), pas détruite, pour rester cohérente avec les filtres.
  Compteurs `(n)` de part et d'autre, en-têtes collants au scroll, état vide
  « Aucun prospect ne correspond aux filtres ». Le filtrage est **client** :
  chaque ligne porte `data-sprint`, `data-produits` et `data-cibles`
  (ids séparés par des virgules), alimentés par
  `ProspectRepository::findAllPourAffectation()` — une ligne par prospect,
  vague courante la plus haute (règle de `Prospect::vagueCourante()`), une
  requête par dimension pour éviter de dupliquer les lignes.
- Formulaire prospect : sélection multi-vagues (`prospect[sprints][]`) + section
  **Pipeline** générée depuis les étapes de la vague ciblée (`prospect[etape{ID}]` +
  `prospect[dateEtape{ID}]`, optionnelles). Une valeur soumise sans vague est refusée
  (« Sélectionnez au moins une vague de traitement pour renseigner le pipeline. »).
  Les champs d'un ancien pipeline sont ignorés (`allow_extra_fields`).
- Fiche prospect : badges des vagues + un tableau de pipeline **par vague** avec
  bouton de cycle cliquable sur chaque étape (badge `js-cycle-etape`).
- Liste prospects : colonnes **Prospect** (email en `mailto:`), **Contact principal**
  (email en `mailto:`), **Téléphone** (celui du contact principal, à défaut celui du
  prospect, lien `tel:`), **Vague**, **Pipeline** (une colonne par étape, cycle
  cliquable), **Contacté**, **Produit**, **Prc Action** (badge de statut
  `En retard / Aujourd'hui / À venir / Réalisée` + date de l'action de suivi
  `Prospect::getDerniereAction()`, lien vers la fiche de l'action).
  La colonne « Cibles » a été retirée de la liste (les cibles restent
  éditables sur la fiche et filtrées via `/user/prospects?cible=`).
- Tableau de bord : pavé **« Qualification des prospects »** au-dessus du graphe
  Pipeline — une courbe par **produit**, alignée sur les mois communs, du nombre de
  prospects qualifiés (« Oui », « Hors produit » ou « Injoignable »). Un prospect
  qualifié sur plusieurs produits apparaît dans chacune ; les qualifications
  **non datées** et l'état « Non » sont exclus.
  La série respecte le filtre **Affecté à**, qui porte ici sur
  **l'auteur du changement de qualification** (`qualifie_modifie_par_id`), et non sur
  l'affectation commerciale (`prospect_user`) : sélectionner un intervenant montre les
  décisions qu'il a prises, y compris sur des prospects qu'il n'a pas en portefeuille.
  Les décisions non signées (import, commande) n'apparaissent que pour « Tous ».
  Le pavé « Top départements » a été retiré (la répartition reste sur `/user/geographie`).
  Le pavé **« Leads par campagne »** a été retiré du tableau de bord (le détail reste sur
  `/user/campagnes`, colonne « Prospects »).
  Le graphe **Pipeline** occupe la carte en bas de la colonne de gauche (colonne KPIs,
  `col-md-4`), avec ses quatre filtres empilés **au-dessus** du graphe. Chaque filtre est
  un couple libellé au-dessus / champ en dessous (le libellé porte `d-block`) : il ne peut
  plus se retrouver seul en fin de ligne comme dans l'ancien en-tête en `flex-wrap`.
  Le filtre global **Affecté à** suit la même règle. Colonnes : 4 (KPIs + Pipeline),
  5 (Qualification des prospects), 3 (actions du jour).
  Le graphe Pipeline conserve son sélecteur **Pipeline** ET son sélecteur de vague
  (défaut « Toutes les vagues » ; un prospect présent dans plusieurs vagues est compté
  dans chacune). Les libellés de séries sont les **noms d'étapes** du pipeline choisi.
  Le pipeline par défaut (`par_defaut=1`) est sélectionné automatiquement s'il n'y a pas
  de query param `?pipeline=`.
  Sous « Qualification des prospects », le pavé **« Étape de pipeline des prospects »**
  trace une courbe par **libellé d'étape**, tous pipelines confondus : l'étape « Appel »
  du pipeline par défaut, d'« Essai » ou d'« EOLE » alimente la même série. Le
  regroupement se fait sur la clé normalisée du libellé (`App\Service\Normalizer\LabelKey`,
  casse/accents/ponctuation ignorés, partagée avec `CompanyKey`) ; le libellé affiché est
  la forme la plus fréquente du groupe, indépendamment de l'utilisateur sélectionné.
  Comptage : `PipelineRepository::countStatutsEtapeParMoisParLibelle()` compte les
  `pipeline_valeur` **datées**, quel que soit le statut, dans le mois de leur date. Comme
  la table ne conserve qu'une ligne par couple prospect↔vague↔étape, il s'agit de la
  **valeur courante** de chaque étape : deux changements dans le même mois ne comptent
  qu'une fois, et c'est le mois du dernier qui compte. Les valeurs sans date (antérieures
  à l'audit) sont exclues, comme pour la qualification. Le filtre **Affecté à** restreint
  à l'auteur du changement (`modifie_par_id`), sur le même principe que la qualification.
  Sous ce pavé, la carte **« Actions réalisées »** trace le nombre de clôtures
  par mois (`ActionRepository::countRealiseesParMois()`), tous types confondus. Le
  mois est celui de `dateRealisee` ; les actions planifiées non clôturées n'entrent
  pas. Le filtre **Affecté à** retient l'auteur de la clôture (`realise_par_user_id`),
  même sémantique que les deux pavés ci-dessus ; les clôtures non signées n'apparaissent
  que pour « Tous ».
  Les en-têtes des cartes **En retard / Aujourd'hui / À venir** portent leur compteur
  en badge (`kpi.actionsEnRetard / actionsAujourdhui / actionsAVenir`, mêmes requêtes
  que les KPI), pour distinguer une liste tronquée d'une liste vide.
- Import : les colonnes `Visio/Demo/Devis/Signature` du CSV sont associées aux étapes
  **par nom normalisé** (accents/espaces/casse ignorés) ; une colonne sans étape
  correspondante produit une anomalie.

## Contexte de retour (navigation)

Aucune URL de retour ne circule dans la query string. La session retient la
navigation, et les formulaires ne transportent qu'un champ caché `retour`
figé au GET.

- `App\Http\ContexteRetour` mémorise en session `_retour` :
  - `page` : la dernière page affichable consultée (liste, fiche, agenda…) ;
  - `listes` : la dernière URL de **chaque** liste, indexée par son chemin.
    L'indexation par section évite qu'un onglet consulté entre-temps
    n'écrase le retour d'un autre.
- `App\EventSubscriber\MemoriserContexteRetour` (sur `kernel.response`)
  mémorise toute réponse GET 200 hors redirection, hors XHR, hors format
  non HTML. Les actions `list()` / `index()` alimentent `listes` ; les
  formulaires et endpoints AJAX (`submit`, `update`, `delete`, `verify`,
  `events`…) sont explicitement exclus.
- Résolution d'une destination de retour :
  1. `page` si elle diffère de l'URL courante (on ne renvoie jamais un
     formulaire sur lui-même) ;
  2. sinon la route de repli du module.
- « Retour à la liste » d'une fiche utilise `listeOu('/user/prospects', …)` :
  la liste prospects retrouve ses filtres et l'ancre `#prospect-{id}`, et ne
  peut pas ramener dans une autre section.
- Après suppression, `CrudDeleteTrait` revient sur la page d'où l'action a
  été déclenchée ; en cas d'absence de contexte, sur la liste du module.
- `ContexteRetour::assainir()` n'accepte qu'un chemin interne absolu : ni
  schéma, ni hôte (`//hote`, `/\hote`), ni caractère de contrôle, ni CRLF
  encodé. Toute valeur refusée retombe sur la route de repli
  (protection open-redirect).
- Spécificité agenda : après modification d'une action ouverte depuis
  l'agenda, `ActionController` recale le retour sur `?date=YYYY-MM-DD` avec
  la date à jour, pour que l'utilisateur retrouve l'action déplacée.

## Liste des prospects — filtres et tri

La liste (`/user/prospects`, `templates/prospects/list.html.twig`) supporte
les filtres suivants (query string) :

- `q` : recherche plein texte (nom, ville, email, nomComplet d'un contact)
- `campagne`, `sprint`, `departement`, `utilisateur` : filtres par FK
- `contacte` : `oui` (match exact `true`) ou `non` (match `IS NULL OR false`,
  c'est-à-dire tous les prospects sans `true`)
- `qualifie` : `oui`, `non` (aucune décision tranchée sur aucun produit),
  `horsproduit` ou `injoignable` (match exact sur cet état)
- `statut` : au moins une action du statut donné — `retard` (planifiée
  `datePrevue < aujourd'hui`), `aujourdhui` (`datePrevue = aujourd'hui`), `avenir`
  (`datePrevue > aujourd'hui` ou sans date), `faites` (`dateRealisee != null`)

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
  `{siren, csrf_token}`). Le serveur re-vérifie le SIREN côté API
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
- **Agenda personnel** : chaque utilisateur voit par défaut **son propre
  agenda** (actions dont il est `aFairePar`). Un sélecteur « Agenda de »
  affiche l'agenda d'un autre collaborateur (`?user={id}`) — tout `ROLE_USER`
  peut consulter et reprendre le suivi d'un collègue.
- Endpoint JSON `/user/agenda/events` (paramètres `start`/`end`, optionnel
  `user`) : renvoie les actions via
  `ActionRepository::findForAgendaRange(start, end, ?user)`
  (planifiées `datePrevue < end` + réalisées `[start, end[`).
  Couleurs : vert `#198754` si faite, orange `#fd7e14` sinon.
  Tooltip natif via `eventDidMount` + `title` attr.
  Les actions « Aujourd'hui » sont **mises en avant** (`statutPriority`,
  `eventOrder` FullCalendar — en tête en vue liste) et surlignées en gras.
- **Création d'action** : clic sur une date → `/user/actions/submit?date=YYYY-MM-DD`.
  Le contrôleur initialise `datePrevue` à la date cliquée.
- **Drag & drop** d'un événement : endpoint POST
  `/user/actions/set-date/{id}` (JSON `{date, csrf_token}`), CSRF lié
  session (`set-date-action{id}`). Met à jour `datePrevue` (ou `dateRealisee`
  si l'action est déjà clôturée). Token récupéré via
  `GET /user/agenda/csrf/{id}`. En cas d'erreur serveur, `info.revert()`
  annule le déplacement côté UI.
- **Réaffectation** : bouton « Réaffecter » sur les événements (hors vue
  mois) → modale de choix du nouveau responsable. POST
  `/user/actions/reaffecter/{id}` (JSON `{versUser, csrf_token}`, token
  `reaffecter-action{id}` via `GET /user/agenda/reaffecter-csrf/{id}`) : met à
  jour `aFairePar` de l'action.
- **Retour à l'agenda** : la session retient que l'agenda était la dernière
  page consultée (voir « Contexte de retour »). Après modification d'une
  action, `ActionController` **récale** ce retour sur `?date=YYYY-MM-DD` avec
  la date prévue (ou réalisée) à jour — sans quoi l'utilisateur retomberait
  sur l'ancienne date de l'agenda.
- `initialDate` du calendrier lit `?date=YYYY-MM-DD` au load.

## Champs étendus du prospect

- **SIREN / SIRET / NAF / RCS/RM / N° TVA** : stockés sur `Prospect`,
  pré-remplis via l'Annuaire des Entreprises.
- **Tiers Dolibarr** : `Prospect::$idDolibarr` (colonne `id_dolibarr`) stocke
  l'identifiant du tiers Dolibarr lié. La liaison se fait exclusivement depuis
  la fiche (bouton « Lier à Dolibarr » / « Changer ») : le champ n'est plus
  saisissable librement dans le formulaire d'édition.
  - `src/Service/DolibarrApi.php` : client REST **lecture seule** de l'API
    Dolibarr (`{DOLIBARR_URL}/api/index.php`), authentifié par la clé API
    **globale du produit** (`DOLIBARR_API_KEY`) — pas de clé par utilisateur,
    l'intégration ne lisant que des tiers. `search()` filtre par nom, code
    client ou code fournisseur (`sqlfilters` assaini : `'`, `\`, `%`, `_`
    neutralisés), `findById()` relit un tiers (cache 10 min, `cache.app`).
    Toutes les méthodes renvoient `['results' => [], 'error' => '…']` plutôt
    que de lever ; un 404 Dolibarr est un résultat vide, pas une panne.
  - `ProspectController::dolibarrSearch()` (`GET
    /user/prospects/{id}/dolibarr/search`) rend le fragment
    `templates/prospects/_dolibarr_modal.html.twig`, injecté dans la fiche par
    `public/lib/app/app.js` (classes `js-dolibarr-*`). Sans `q`, la recherche
    est pré-remplie avec le nom du prospect.
  - `ProspectController::dolibarrLink()` (`POST
    /user/prospects/dolibarr/link`, CSRF `dolibarr-prospect`) écrit
    `id_dolibarr` **après revalidation du tiers** côté serveur ; un
    `thirdpartyId` vide retire la liaison. Un tiers inconnu renvoie 422.
  - La fiche affiche le nom du tiers lié avec un lien vers sa fiche Dolibarr
    (`/societe/card.php?socid=…`) ; `—` si non lié, « tiers introuvable » si
    l'ID stocké n'existe plus.
  - **Pavé « Dolibarr »** (sous la carte Entreprise, seulement si le prospect
    est lié) : `DolibarrApi::documentsForThirdparty()` interroge les endpoints
    `proposals` / `orders` / `invoices` avec
    `sqlfilters=(t.fk_soc:=:<id>) and (t.fk_statut:>:0)` — **les brouillons
    (statut 0) sont donc exclus** — triés par `t.rowid DESC` (cet API n'expose
    pas les colonnes `date*` au tri). La liste est **exhaustive** : chaque type
    est paginé (100 par page) jusqu'à épuisement, avec un garde-fou de 50 pages
    par type ; cache 5 min.
    Tableau **3 colonnes** (la date est empilée sous la référence) pour laisser
    la place à « Montant HT », jamais coupé : référence (lien vers la fiche
    Dolibarr), statut (libellés alignés sur Dolibarr) et montant HT ; chaque
    section se termine par un **Total HT**. Les factures d'avoir / acompte /
    pro forma portent en plus leur sous-type (`total_ht` reste signé comme
    dans Dolibarr). Une panne sur un type n'empêche pas l'affichage des autres
    (bandeau « Dolibarr incomplet »).
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
# 5. (optionnel) réimporter un fichier via l'écran UI /user/produits/import
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
# 4. (optionnel) enrichir via l'écran d'import UI /user/produits/import
```

Pas de migration Doctrine générée : le `d:s:update --force` est la procédure
officielle du projet (cf. `doc/installation.md`, section « Schéma BDD »).
Les `UPDATE` SQL directs sur les données sont autorisés (ex. correction
du `par_defaut` du pipeline « Pipeline par défaut »), mais **jamais**
sur le schéma.

## Import de produits depuis un tableur (UI)

L'application propose un import interactif depuis `/user/produits/import`
pour constituer une liste depuis un tableur (`.xlsx` ou `.csv`).

**Flux** :
1. L'utilisateur choisit le mode : nouveau produit ou produit existant.
2. Optionnellement : une ou plusieurs produits supplémentaires + une campagne d'affectation.
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

Si vous supprimez manuellement des Prospects, Produits, Campagnes ou Sprints en SQL,
les tables liées (`contact`, `action`, `prospect_produit`, `prospect_user`,
`prospect_sprint`) conservent des FK pointant dans le vide. Doctrine refuse alors
de charger ces entités ("Entity of type X for IDs id(Y) was not found").

Les `cascade: ['remove']` ne fonctionnent QUE via l'ORM. Pour nettoyer la base
après une purge manuelle, utilisez :

```bash
docker compose exec -T nineprospect php bin/console app:cleanup-orphans --dry-run  # preview
docker compose exec -T nineprospect php bin/console app:cleanup-orphans --yes      # exécution
```

La commande scanne 8 types d'orphelins (actions / contacts / liens prospect-user /
prospect_sprint × 2 / prospect_produit × 2 / prospects orphelins en campagne) et
supprime dans une seule transaction.

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

Le projet vise **221 tests verts** (8 skipped quand la base de test n'est pas
disponible) couvrant : prospection, contacts, CRUD générique, contexte de
retour (`tests/Http/ContexteRetourTest.php`,
`tests/RetourNavigationTest.php`), affectation en masse des vagues
(`tests/VaguesBulkTest.php`), agenda, agenda CSRF/déplacement,
AnnuaireEntreprises, AdresseApi, services, audit de la qualification
(`tests/QualificationAuditTest.php`), audit des étapes de pipeline
(`tests/PipelineAuditTest.php`) et leurs pavés de tableau de bord
(`tests/DashboardQualificationTest.php`, `tests/DashboardEtapesPipelineTest.php`,
`tests/DashboardActionsRealiseesTest.php`), etc.

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
