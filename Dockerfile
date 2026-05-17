# ============================================================
# Стейдж 1: composer-зависимости (отдельный слой для кэша)
# ============================================================
FROM composer:2 AS composer_deps

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# ============================================================
# Стейдж 2: финальный рантайм-образ (PHP-FPM + код)
# ============================================================
FROM php:8.3-fpm-alpine AS app

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        pdo_pgsql \
        bcmath \
        intl \
        opcache \
        pcntl \
        redis \
        sockets

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY --from=composer_deps /app/vendor ./vendor

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]