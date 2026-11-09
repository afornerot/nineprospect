# API

## Swagger

Documentation Swagger : http://localhost:8030/v1/api/doc

## Authentification API

Header `X-API-SECRET` (valeur de `APP_SECRET`), firewall `api`
(`^/v1/api`, stateless). Le secret se renseigne dans `.env.local` :

```dotenv
APP_SECRET=<64 caractères aléatoires>
```

Le secret de démonstration livré dans `.env` (`changeme`) **n'ouvre pas**
l'API : `ApiKeyAuthenticator` le refuse et répond 503/401. Attention,
`APP_SECRET` est aussi le mot de passe initial de l'admin (fixtures) :
le changer impose un `app:init` ou une réinitialisation du mot de passe.

Sans header valide, la réponse est un **401 JSON** (jamais une redirection
vers le login). Le rôle requis est `ROLE_MCP`.

## Tableau de bord

Tous les chiffres de `/user/dashboard`, en JSON.

```bash
curl -H "X-API-SECRET: $APP_SECRET" https://nineprospect.cadol.es/v1/api/dashboard
```

Filtres facultatifs (mêmes paramètres que la page) : `utilisateur`,
`pipeline`, `sprint`, `produit`, `cible`.

| Clé | Contenu |
|-----|---------|
| `kpi` | `prospects`, `contacts`, `actionsEnRetard`, `actionsAujourdhui`, `actionsAVenir`, `nonContacte`, `nonQualifie` |
| `filtres` | Valeurs de filtre effectivement appliquées |
| `pipeline.etapes` / `pipeline.series` | Une série par statut (`À faire`, `En cours`, `Réalisé`), alignée sur les étapes |
| `contactes`, `qualifications`, `etapes`, `actionsRealisees` | `mois` (clés `YYYY-MM` triées) + `series` alignées |

Les compteurs proviennent de `App\Service\DashboardStats`, partagé avec
l'écran : les deux sorties ne peuvent pas diverger. Seuls les **chiffres**
sont exposés ; les listes détaillées d'actions (retard / aujourd'hui /
à venir) restent sur l'écran.

```bash
# Listes de référence pour construire une requête filtrée
curl -H "X-API-SECRET: $APP_SECRET" https://nineprospect.cadol.es/v1/api/dashboard/filtres
```

## MCP

Endpoint MCP : http://localhost:8030/mcp

Authentification : Bearer token dans le header `Authorization`
(`Bearer MCP_SECRET` à chaque requête, `stateless: true` — aucune session).

Un client sans header valide reçoit un **401** JSON. L'endpoint est
machine-to-machine : il ne peut pas suivre un flow de login interactif
(CAS/OIDC), d'où un firewall séparé du flux principal. Voir
[doc/authentification.md](authentification.md) pour le détail.
