#!/bin/sh

set -e

echo "**** Inject .env values ****"
rm -rf /var/www/mendako/.env.local
touch /var/www/mendako/.env.local

echo "APP_ENV=${APP_ENV:-prod}" >> "/var/www/mendako/.env.local"
echo "APP_DEBUG=${APP_DEBUG:-0}" >> "/var/www/mendako/.env.local"
echo "APP_SECRET=${APP_SECRET:-$(openssl rand -base64 21)}" >> "/var/www/mendako/.env.local"

echo "DB_DRIVER=${DB_DRIVER:-}" >> "/var/www/mendako/.env.local"
echo "DB_NAME=${DB_NAME:-}" >> "/var/www/mendako/.env.local"
echo "DB_HOST=${DB_HOST:-}" >> "/var/www/mendako/.env.local"
echo "DB_PORT=${DB_PORT:-}" >> "/var/www/mendako/.env.local"
echo "DB_USER=${DB_USER:-}" >> "/var/www/mendako/.env.local"
echo "DB_PASSWORD=${DB_PASSWORD:-}" >> "/var/www/mendako/.env.local"
echo "DB_VERSION=${DB_VERSION:-}" >> "/var/www/mendako/.env.local"

echo "session.cookie_secure=${HTTPS_ENABLED}" >> /etc/php/8.2/fpm/conf.d/php.ini
echo "date.timezone=${PHP_TZ}" >> /etc/php/8.2/fpm/conf.d/php.ini


echo "**** Migrate the database ****"
cd /var/www/mendako
composer install
php bin/console doctrine:migration:migrate --no-interaction --allow-no-migration --env=prod

echo "**** Create nginx log files ****"
mkdir -p /logs/nginx
chown -R www-data:www-data /logs/nginx

echo "**** Setup complete, starting the server. ****"
php-fpm8.2
exec $@