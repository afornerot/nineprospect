# nineprospect

Application de gestion de prospects, campagnes Meta, vagues de traitement et
pipeline commercial. Stack : PHP 8.4, Symfony 7.4, MariaDB, Apache, Docker.

## Licence

Ce projet est distribué sous licence **[AGPL-3.0](LICENSE)** (GNU Affero General Public License).

## Démarrage rapide

```bash
# 1. Cloner
git clone https://github.com/afornerot/nineprospect.git && cd nineprospect

# 2. Configurer les secrets (voir doc/installation.md)
cp .env .env.local
# Éditer .env.local : APP_SECRET, APP_ENCRYPT_KEY, MCP_SECRET, DATABASE_URL
# Les valeurs par défaut (changeme) servent à l'exécution locale et
# DOIVENT être modifiées avant toute mise en production :
# voir la section « Secrets à modifier avant mise en production »
# de doc/installation.md.
# Créer compose.override.yaml : surcharger uniquement les passwords BDD

# 3. Démarrer (créer les répertoires partagés avant le premier up)
mkdir -p var uploads public/uploads
docker compose up -d --build
```

Application accessible sur http://localhost:8029 — compte admin créé
automatiquement (login = `APP_ADMIN`, mot de passe = `APP_SECRET`).

## Documentation

La documentation détaillée se trouve dans [doc/](doc/index.md) :

| Sujet | Documentation |
|-------|---------------|
| Modules métier (prospects, actions, agenda, carte, Annuaire Entreprises) | [doc/prospection.md](doc/prospection.md) |
| Installation détaillée (secrets, BDD, permissions) | [doc/installation.md](doc/installation.md) |
| Authentification (rôles, CAS/OIDC/SQL, MCP) | [doc/authentification.md](doc/authentification.md) |
| Variables d'environnement, mailer, Mapbox | [doc/configuration.md](doc/configuration.md) |
| Fixtures (données initiales, compte admin) | [doc/fixtures.md](doc/fixtures.md) |
| Crons (planificateur de tâches) | [doc/crons.md](doc/crons.md) |
| IA / LLM (service AiService) | [doc/ai.md](doc/ai.md) |
| API (Swagger, MCP) | [doc/api.md](doc/api.md) |

## Liens utiles

- Application : http://localhost:8029
- Swagger : http://localhost:8029/v1/api/doc
- Admin crons : http://localhost:8029/admin/cron
- Endpoint MCP : http://localhost:8029/mcp
