# Postgres FBDE : PostGIS (recherche géographique) + pgvector (similarité IA,
# EF-08.3). On étend l'image officielle postgis avec le paquet PGDG pgvector.
FROM postgis/postgis:16-3.4

RUN apt-get update \
 && apt-get install -y --no-install-recommends postgresql-16-pgvector \
 && rm -rf /var/lib/apt/lists/*
