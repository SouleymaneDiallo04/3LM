FROM php:8.4-fpm-alpine

# Dépendances système + extensions PHP nécessaires à Laravel + PostgreSQL/PostGIS + Redis
RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        su-exec \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        opcache \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Composer (copié depuis l'image officielle)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Utilisateur non-root aligné sur l'UID hôte courant.
# L'entrypoint démarre en root et répare les permissions de storage/ et
# bootstrap/cache (bind mounts Windows/macOS) ; le master php-fpm reste en
# root, les workers s'exécutent en `app` (voir zz-app-pool.conf).
RUN addgroup -g 1000 app && adduser -G app -u 1000 -D app

COPY zz-app-pool.conf /usr/local/etc/php-fpm.d/zz-app-pool.conf
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
