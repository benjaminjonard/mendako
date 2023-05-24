FROM ubuntu:latest

ARG DEBIAN_FRONTEND=noninteractive

# Environment variables
ENV APP_ENV='prod'
ENV PUID='1000'
ENV PGID='1000'
ENV USER='mendako'

COPY ./ /var/www/mendako

# Add User and Group
RUN addgroup --gid "$PGID" "$USER" && \
    adduser --gecos '' --no-create-home --disabled-password --uid "$PUID" --gid "$PGID" "$USER" && \
# Install some basics dependencies
    apt-get update && \
    apt-get install -y curl wget lsb-release software-properties-common && \
# PHP
    add-apt-repository ppa:ondrej/php && \
# Nodejs
    curl -sL https://deb.nodesource.com/setup_18.x | bash - && \
# Yarn
    curl -sS https://dl.yarnpkg.com/debian/pubkey.gpg | apt-key add - && \
    echo "deb https://dl.yarnpkg.com/debian/ stable main" | tee /etc/apt/sources.list.d/yarn.list && \
# Install packages
    apt-get update && \
    apt-get install -y \
    ca-certificates \
    apt-transport-https \
    gnupg2 \
    git \
    unzip \
    nginx-light \
    libpuzzle-dev \
    openssl \
    ffmpeg \
    php8.2 \
    php8.2-dev \
    php8.2-pgsql \
    php8.2-mysql \
    php8.2-mbstring \
    php8.2-gd \
    php8.2-xml \
    php8.2-zip \
    php8.2-fpm \
    php8.2-intl \
    nodejs \
    yarn && \
#Install composer dependencies
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    cd /var/www/mendako && \
    composer install --classmap-authoritative && \
    composer clearcache && \
# Install javascript dependencies and build assets
    cd /var/www/mendako/assets && \
    yarn --version && \
    yarn install && \
    yarn build && \
    yarn cache clean && \
# Set permissions
    sed -i "s/user = www-data/user = $USER/g" /etc/php/8.2/fpm/pool.d/www.conf && \
    sed -i "s/group = www-data/group = $USER/g" /etc/php/8.2/fpm/pool.d/www.conf && \
    chown -R "$USER":"$USER" /var/www/mendako && \
    chmod +x /var/www/mendako/docker/entrypoint.sh && \
    mkdir /run/php && \
# Add nginx and PHP config files
    cp /var/www/mendako/docker/default.conf /etc/nginx/nginx.conf && \
    cp /var/www/mendako/docker/php.ini /etc/php/8.2/fpm/conf.d/php.ini && \
# Build libpuzzle extension
    cd /tmp && \
    wget https://github.com/benjaminjonard/libpuzzle-php-extension-builder/archive/refs/heads/main.zip && \
    unzip main.zip && \
    cd libpuzzle-php-extension-builder-main/src && \
    phpize && \
    ./configure && \
    make clean && \
    make && \
    make install && \
    echo "extension=libpuzzle.so" >> /etc/php/8.2/fpm/php.ini && \
    echo "extension=libpuzzle.so" >> /etc/php/8.2/cli/php.ini && \
    rm -rf /tmp/libpuzzle-php-extension-builder-main && \
# Clean up \
    rm -rf /var/www/mendako/assets/node_modules && \
    apt-get purge -y wget lsb-release software-properties-common git nodejs yarn apt-transport-https ca-certificates gnupg2 unzip php8.2-dev && \
    apt-get autoremove -y && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* && \
    rm -rf /usr/local/bin/composer

EXPOSE 80

VOLUME /uploads
VOLUME /thumbnails

WORKDIR /var/www/mendako

HEALTHCHECK CMD curl --fail http://localhost:80/ || exit 1

ENTRYPOINT ["sh", "/var/www/mendako/docker/entrypoint.sh" ]

CMD [ "nginx" ]
