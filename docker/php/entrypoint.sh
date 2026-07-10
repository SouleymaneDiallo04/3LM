#!/bin/sh
# Démarre en root pour garantir que Laravel peut écrire dans storage/ et
# bootstrap/cache quel que soit l'hôte (bind mounts Windows/macOS montés
# root:root). php-fpm reste lancé en root : son master doit pouvoir rouvrir
# les fd stdout/stderr du conteneur ; les workers s'exécutent en `app` via
# la config de pool (zz-app-pool.conf). Toute autre commande est abaissée
# vers l'utilisateur `app`.
set -e

if [ "$(id -u)" = "0" ]; then
    # Pré-créer les répertoires d'écriture applicative : Flysystem les crée
    # sinon en 0700 au premier accès, propriété du process appelant — un
    # artisan lancé en root rendrait exports/ inaccessible aux workers app.
    mkdir -p storage/app/private/exports storage/app/sirene 2>/dev/null || true
    chmod -R ugo+rwX storage bootstrap/cache 2>/dev/null || true
    if [ "$1" = "php-fpm" ]; then
        exec "$@"
    fi
    exec su-exec app "$@"
fi

exec "$@"
