#!/bin/sh
# Démarre en root pour garantir que Laravel peut écrire dans storage/ et
# bootstrap/cache quel que soit l'hôte (bind mounts Windows/macOS montés
# root:root). php-fpm reste lancé en root : son master doit pouvoir rouvrir
# les fd stdout/stderr du conteneur ; les workers s'exécutent en `app` via
# la config de pool (zz-app-pool.conf). Toute autre commande est abaissée
# vers l'utilisateur `app`.
set -e

if [ "$(id -u)" = "0" ]; then
    chmod -R ugo+rwX storage bootstrap/cache 2>/dev/null || true
    if [ "$1" = "php-fpm" ]; then
        exec "$@"
    fi
    exec su-exec app "$@"
fi

exec "$@"
