FROM dunglas/frankenphp:php8.5 AS mendako-base

# Environment variables
ENV APP_ENV=prod
ENV PUID=1000
ENV PGID=1000
ENV USER=mendako
ENV FRANKENPHP_SERVER_NAME=":80"
ENV APP_RUNTIME="Symfony\\Component\\Runtime\\SymfonyRuntime"
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY ./ /app/public
COPY ./docker/Caddyfile /etc/caddy/Caddyfile

RUN set -eux ; \
    # Add User and Group
    addgroup --gid "$PGID" "$USER" ; \
    adduser --gecos '' --no-create-home --disabled-password --uid "$PUID" --gid "$PGID" "$USER" ; \
   # Install packages \
    apt-get update -qq ; \
    apt-get install -qqy --no-install-recommends  \
    curl \
    gnupg2 \
    ca-certificates \
    git \
    unzip \
    ffmpeg \
    supervisor \
    openssl ; \
    # Install PHP extensions \
    install-php-extensions opcache pdo_pgsql intl gd zip curl ; \
    #Install composer dependencies \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer ; \
    cd /app/public ; \
    COMPOSER_MEMORY_LIMIT=-1 composer install --classmap-authoritative ; \
    COMPOSER_MEMORY_LIMIT=-1 composer clearcache ; \
    # Clean up \
    apt-get purge -y git ca-certificates gnupg2 unzip ; \
    apt-get autoremove -y ; \
    apt-get clean ; \
    rm -rf /var/lib/apt/lists/* ; \
    rm -rf /usr/local/bin/composer ; \
    # Set permissions \
    chown -R "$USER":"$USER" /app/public ; \
    chmod +x /app/public/docker/entrypoint.sh ; \
    mkdir /run/php ; \
    # Add PHP config files \
    cp /app/public/docker/php.ini /usr/local/etc/php/conf.d/php.ini

FROM node:26-bookworm AS build-node

WORKDIR /app

COPY ./assets/ ./assets

WORKDIR /app/assets

RUN set -eux ; \
    mkdir -p /app/public/build/ ; \
    npm install -g corepack ; \
    corepack enable ; \
    yarn --version ; \
    yarn install ; \
    yarn build ;

FROM mendako-base AS mendako-final

COPY --from=build-node /app/public/build/ /app/public/public/build/

VOLUME /uploads
VOLUME /thumbnails

EXPOSE 80
EXPOSE 443

WORKDIR /app/public

HEALTHCHECK CMD curl --fail http://localhost:80/ || exit 1

ENTRYPOINT ["sh", "/app/public/docker/entrypoint.sh" ]