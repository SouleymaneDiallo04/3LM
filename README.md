# FBDE — France Business Data Extractor

Plateforme B2B de collecte, centralisation et exploitation des données d'entreprises françaises,
à partir de sources **ouvertes et légales** (SIRENE, OpenStreetMap), pour la prospection commerciale,
les études de marché et la veille.

> Projet réalisé pour **3LM Solutions**. Périmètre : France.

## Architecture

Monorepo :

```
fbde/
├── backend/          # API REST — Laravel 12 / PHP 8.4
├── frontend/         # SPA — React 18 + TypeScript + Vite + Tailwind
├── docker/           # Images et configuration des conteneurs
│   ├── php/          #   PHP-FPM 8.4 (extensions pgsql, redis…)
│   └── nginx/        #   Reverse proxy → backend/public
├── docs/             # Cahier des charges, notes de cadrage
└── docker-compose.yml
```

### Sources de données
| Rôle | Source | Statut |
|------|--------|--------|
| Socle registre | **SIRENE / INSEE** (open data) | SIREN/SIRET, NAF, effectifs, statut, géoloc |
| Enrichissement POI | **OpenStreetMap** (Overpass / Geofabrik) | téléphone, site, horaires |
| Géocodage | **Base Adresse Nationale** + SIRENE géolocalisée + Nominatim (repli) | gratuit |
| Enrichissement web | Crawler (robots.txt, 1 req/s/domaine) | email, réseaux, note JSON-LD |

### Stack
Laravel 12 · PostgreSQL 16 + PostGIS + pgvector · Redis 7 + Horizon · Sanctum + TOTP ·
React 18 + TS + Tailwind · Leaflet + OSM · Docker · Nginx.

## Démarrage (développement)

Prérequis : **Docker Desktop** et **Node 20+**.

```bash
# 1. Backend + services (PHP, PostgreSQL, Redis, Nginx)
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

# 2. Frontend (serveur de dev Vite, hot reload)
cd frontend
npm install
npm run dev
```

- API : http://localhost:8080
- Frontend : http://localhost:5173

## Conventions

- Backend : PSR-12, tests **Pest**. Frontend : ESLint + Prettier, tests **Vitest** / **Playwright**.
- Branches protégées, revue de code par pull request.
- Journal des décisions d'architecture : `docs/adr/`.
