# Configuration

Les valeurs par défaut se trouvent dans `.env` (commité). Toute surcharge va
dans `.env.local` (jamais commité).

## Variables obligatoires

| Variable | Description |
|----------|-------------|
| `APP_SECRET` | Secret Symfony (générer via `php -r "echo bin2hex(random_bytes(16));"`) |
| `APP_ENCRYPT_KEY` | Clé de chiffrement |
| `MCP_SECRET` | Secret du serveur MCP — à modifier avant prod (voir [authentification.md](authentification.md)) |
| `DATABASE_URL` | Connexion base de données |
| `MODE_AUTH` | Mode d'authentification : `SQL` (défaut), `CAS`, `OIDC` |

> Les secrets par défaut (`changeme`) ne servent qu'à l'exécution locale :
> liste complète et emplacements de surcharge dans la section
> « Secrets à modifier avant mise en production » de
> [installation.md](installation.md).

## Modes d'authentification

Le choix et le fonctionnement des modes SQL / CAS / OIDC sont documentés dans
[authentification.md](authentification.md) (rôles, provisioning auto,
extensibilité).

Le compte admin créé par `app:init` utilise `APP_ADMIN` (logins, séparés par
des virgules) et `APP_ADMIN_EMAIL` (emails, séparés par des virgules). Les
deux listes doivent avoir le **même nombre d'éléments** : chaque login reçoit
l'email de même position. Un désalignement ou un doublon d'email provoque une
erreur explicite au chargement des fixtures.

## Mailer

Le mailer est configuré avec `MAILER_DSN=null://null` par défaut (aucun envoi).
Pour activer l'envoi de mails, surcharger `MAILER_DSN` dans `.env.local` :

```env
# Exemple avec Mailgun
MAILER_DSN="https://KEY:DOMAIN@mailgun.org"

# Exemple avec SMTP local
MAILER_DSN="smtp://localhost:25"
```

## Mapbox (vue Carte)

Utilisée par la vue carte plein écran (`/user/carte`) et le pavé carte dans la fiche prospect.

| Variable | Description |
|----------|-------------|
| `MAPBOX_PUBLIC_TOKEN` | Token public Mapbox ([compte Mapbox](https://account.mapbox.com/access-tokens/)) |

Le défaut (vide) désactive ces deux vues avec un message d'avertissement invitant
à renseigner la clé. La saisie d'un token dans `.env` ne nécessite **pas** de
rebuild d'image (Symfony lit `.env` au boot du conteneur ; un `cache:clear` suffit).

## Dolibarr (liaison prospect ↔ tiers)

La fiche prospect permet de lier le prospect à un tiers Dolibarr existant
(bouton « Lier à Dolibarr »). L'intégration est **en lecture seule** : elle
cherche et affiche des tiers, sans jamais écrire dans Dolibarr.

| Variable | Description |
|----------|-------------|
| `DOLIBARR_URL` | URL de base de l'instance, sans `/api` (ex. `https://doli.example/dolibarr`) |
| `DOLIBARR_API_KEY` | Clé API **globale du produit** (`DOLAPIKEY`), pas de clé par utilisateur |

La clé se renseigne dans `.env.local` (jamais dans `.env`, versionné) :

```dotenv
DOLIBARR_URL=https://doli.example/dolibarr
DOLIBARR_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Variable vide ⇒ l'intégration est désactivée : le bouton n'apparaît pas sur la
fiche et aucune requête n'est émise. Un `cache:clear` suffit après modification
(pas de rebuild d'image).

## Notifications

Les notifications admin sont envoyées à l'adresse `APP_ADMIN_EMAIL`.
Cette adresse est aussi utilisée par `app:init` pour créer le compte admin.

## Commandes utiles

```bash
# Créer les utilisateurs admin + charger les fixtures
docker compose exec nineprospect php bin/console app:init

# Assigner des rôles
docker compose exec nineprospect php bin/console app:role <username> ROLE_ADMIN

# Nettoyer le cache
docker compose exec nineprospect php bin/console cache:clear

# Mettre à jour le schéma BDD
docker compose exec nineprospect php bin/console doctrine:schema:update --force
```

## Conventions — suppression d'enregistrement (CSRF)

Toute route de suppression (et plus généralement tout effet de bord déclenché
par un simple clic) doit suivre ce pattern :

1. **Route restreinte au POST** — un GET sur la route renverra 405 (la suppression
   est atteignable uniquement depuis un form POST authentifié) :

   ```php
   #[Route('/admin/entity/delete/{id}', name: 'app_admin_entity_delete', methods: ['POST'])]
   ```

2. **Validation du token CSRF** avant l'opération de suppression :

   ```php
   if (!$this->isCsrfTokenValid('delete-entity'.$id, (string) $request->request->get('_csrf_token'))) {
       $this->addFlash('error', 'Token CSRF invalide — suppression non effectuée.');
       return $this->redirectToRoute('app_admin_entity_update', ['id' => $id]);
   }
   ```

3. **Côté Vue (template edit)** : utiliser l'include Twig réutilisable
   `templates/include/delete_button.html.twig` (mini-form POST autonome) que
   l'on place **hors de tout form principal** (jamais entre `form_start` et
   `form_end`) dans :

   ```twig
   {% include('include/delete_button.html.twig', {
       route: 'app_admin_entity_delete',
       id: form.vars.value.id,
       tokenId: 'delete-entity' ~ form.vars.value.id
   }) %}
   ```
