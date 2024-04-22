#!/bin/bash

set -e

echo "**** 1/8 - Make sure /uploads folders exist ****"
[ ! -f /uploads ] && \
	mkdir -p /uploads

[ ! -f /thumbnails ] && \
	mkdir -p /thumbnails

echo "**** 2/8 - Create the symbolic link for the /uploads folder ****"
[ ! -L /app/public/public/uploads ] && \
	cp -r /app/public/public/uploads/. /uploads && \
	rm -r /app/public/public/uploads && \
	ln -s /uploads /app/public/public/uploads

[ ! -L /app/public/public/thumbnails ] && \
	cp -r /app/public/public/thumbnails/. /thumbnails && \
	rm -r /app/public/public/thumbnails && \
	ln -s /thumbnails /app/public/public/thumbnails

echo "**** 3/8 - Setting env variables ****"
rm -rf /app/public/.env.local
touch /app/public/.env.local

echo "APP_ENV=${APP_ENV:-prod}" >> "/app/public/.env.local"
echo "APP_DEBUG=${APP_DEBUG:-0}" >> "/app/public/.env.local"
echo "APP_SECRET=${APP_SECRET:-$(openssl rand -base64 21)}" >> "/app/public/.env.local"
echo "APP_THUMBNAILS_FORMAT=${APP_THUMBNAILS_FORMAT:-}" >> "/app/public/.env.local"
echo "APP_ENABLE_METRICS=${APP_ENABLE_METRICS:-0}" >> "/app/public/.env.local"

echo "DB_NAME=${DB_NAME:-}" >> "/app/public/.env.local"
echo "DB_HOST=${DB_HOST:-}" >> "/app/public/.env.local"
echo "DB_PORT=${DB_PORT:-}" >> "/app/public/.env.local"
echo "DB_USER=${DB_USER:-}" >> "/app/public/.env.local"
echo "DB_PASSWORD=${DB_PASSWORD:-}" >> "/app/public/.env.local"
echo "DB_VERSION=${DB_VERSION:-}" >> "/app/public/.env.local"

echo "session.cookie_secure=${HTTPS_ENABLED}" >> /usr/local/etc/php/conf.d/php.ini
echo "date.timezone=${PHP_TZ:-'Europe\Paris'}" >> /usr/local/etc/php/conf.d/php.ini
echo "memory_limit=${PHP_MEMORY_LIMIT:-'512M'}" >> /usr/local/etc/php/conf.d/php.ini

echo "upload_max_filesize=${UPLOAD_MAX_FILESIZE:-'20M'}" >> /usr/local/etc/php/conf.d/php.ini
echo "post_max_size=${UPLOAD_MAX_FILESIZE:-'100M'}" >> /usr/local/etc/php/conf.d/php.ini

echo "**** 4/8 - Migrate the database ****"
cd /app/public && \
php bin/console doctrine:migration:migrate --no-interaction --allow-no-migration --env=prod

echo "**** 5/8 - Create user and use PUID/PGID ****"
PUID=${PUID:-1000}
PGID=${PGID:-1000}
if [ ! "$(id -u "$USER")" -eq "$PUID" ]; then usermod -o -u "$PUID" "$USER" ; fi
if [ ! "$(id -g "$USER")" -eq "$PGID" ]; then groupmod -o -g "$PGID" "$USER" ; fi
echo -e " \tUser UID :\t$(id -u "$USER")"
echo -e " \tUser GID :\t$(id -g "$USER")"

echo "**** 6/8 - Set Permissions ****"
find /uploads -type d \( ! -user "$USER" -o ! -group "$USER" \) -exec chown -R "$USER":"$USER" \{\} \;
find /uploads \( ! -user "$USER" -o ! -group "$USER" \) -exec chown "$USER":"$USER" \{\} \;
usermod -a -G "$USER" www-data
find /uploads -type d \( ! -perm -ug+w -o ! -perm -ugo+rX \) -exec chmod -R ug+w,ugo+rX \{\} \;
find /uploads \( ! -perm -ug+w -o ! -perm -ugo+rX \) -exec chmod ug+w,ugo+rX \{\} \;

find /thumbnails -type d \( ! -user "$USER" -o ! -group "$USER" \) -exec chown -R "$USER":"$USER" \{\} \;
find /thumbnails \( ! -user "$USER" -o ! -group "$USER" \) -exec chown "$USER":"$USER" \{\} \;
usermod -a -G "$USER" www-data
find /thumbnails -type d \( ! -perm -ug+w -o ! -perm -ugo+rX \) -exec chmod -R ug+w,ugo+rX \{\} \;
find /thumbnails \( ! -perm -ug+w -o ! -perm -ugo+rX \) -exec chmod ug+w,ugo+rX \{\} \;

echo "**** 4/8 - Create symfony log files ****"
[ ! -f /app/public/var/log ] && \
	mkdir -p /app/public/var/log

[ ! -f /app/public/var/log/prod.log ] && \
	touch /app/public/var/log/prod.log

chown -R "$USER":"$USER" /app/public/var/log
chown -R "$USER":"$USER" /app/public/var/log/prod.log

echo "**** 8/8 - Setup complete, starting the server. ****"
frankenphp run --config /etc/caddy/Caddyfile
exec "$@"

echo "**** All done ****"