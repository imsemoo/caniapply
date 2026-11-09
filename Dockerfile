FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --ignore-platform-reqs

FROM php:8.3-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libicu-dev \
    && docker-php-ext-install zip intl pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=production APP_DEBUG=false DB_DATABASE=/app/storage/database.sqlite

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN composer dump-autoload --no-dev --optimize --no-interaction

RUN chmod +x docker/entrypoint.sh
EXPOSE 8000
ENTRYPOINT ["docker/entrypoint.sh"]
