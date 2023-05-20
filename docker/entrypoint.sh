#!/bin/bash

set -e

echo "**** 1/9 - Make sure /uploads folders exist ****"
[ ! -f /uploads ] && \
	mkdir -p /uploads

echo "**** 2/9 - Create the symbolic link for the /uploads folder ****"
[ ! -L /var/www/mendako/public/uploads ] && \
	cp -r /var/www/mendako/public/uploads/. /uploads && \
	rm -r /var/www/mendako/public/uploads && \
	ln -s /uploads /var/www/mendako/public/uploads

echo "**** 3/9 - Setting env variables ****"
rm -rf /var/www/mendako/.env.local
touch /var/www/mendako/.env.local

echo "APP_ENV=${APP_ENV:-prod}" >> "/var/www/mendako/.env.local"
echo "APP_DEBUG=${APP_DEBUG:-0}" >> "/var/www/mendako/.env.local"
echo "APP_SECRET=${APP_SECRET:-$(openssl rand -base64 21)}" >> "/var/www/mendako/.env.local"
echo "APP_THUMBNAILS_FORMAT=${APP_THUMBNAILS_FORMAT:-}" >> "/var/www/mendako/.env.local"

echo "DB_NAME=${DB_NAME:-}" >> "/var/www/mendako/.env.local"
echo "DB_HOST=${DB_HOST:-}" >> "/var/www/mendako/.env.local"
echo "DB_PORT=${DB_PORT:-}" >> "/var/www/mendako/.env.local"
echo "DB_USER=${DB_USER:-}" >> "/var/www/mendako/.env.local"
echo "DB_PASSWORD=${DB_PASSWORD:-}" >> "/var/www/mendako/.env.local"
echo "DB_VERSION=${DB_VERSION:-}" >> "/var/www/mendako/.env.local"

echo "session.cookie_secure=${HTTPS_ENABLED}" >> /etc/php/8.2/fpm/conf.d/php.ini
echo "date.timezone=${PHP_TZ:-'Europe\Paris'}" >> /etc/php/8.2/fpm/conf.d/php.ini
echo "memory_limit=${PHP_MEMORY_LIMIT:-'512M'}" >> /etc/php/8.2/fpm/conf.d/php.ini

echo "upload_max_filesize=${UPLOAD_MAX_FILESIZE:-'20M'}" >> /etc/php/8.2/fpm/conf.d/php.ini
echo "post_max_size=${UPLOAD_MAX_FILESIZE:-'100M'}" >> /etc/php/8.2/fpm/conf.d/php.ini
sed -i "s/client_max_body_size 100M;/client_max_body_size ${UPLOAD_MAX_FILESIZE:-'100M'};/g" /etc/nginx/nginx.conf

echo "**** 4/9 - Migrate the database ****"
cd /var/www/mendako && \
php bin/console doctrine:migration:migrate --no-interaction --allow-no-migration --env=prod

echo "**** 5/9 - Create user and use PUID/PGID ****"
PUID=${PUID:-1000}
PGID=${PGID:-1000}
if [ ! "$(id -u "$USER")" -eq "$PUID" ]; then usermod -o -u "$PUID" "$USER" ; fi
if [ ! "$(id -g "$USER")" -eq "$PGID" ]; then groupmod -o -g "$PGID" "$USER" ; fi
echo -e " \tUser UID :\t$(id -u "$USER")"
echo -e " \tUser GID :\t$(id -g "$USER")"

echo "**** 6/9 - Set Permissions ****"
find /uploads -type d \( ! -user "$USER" -o ! -group "$USER" \) -exec chown -R "$USER":"$USER" \{\} \;
find /uploads \( ! -user "$USER" -o ! -group "$USER" \) -exec chown "$USER":"$USER" \{\} \;
usermod -a -G "$USER" www-data
find /uploads -type d \( ! -perm -ug+w -o ! -perm -ugo+rX \) -exec chmod -R ug+w,ugo+rX \{\} \;
find /uploads \( ! -perm -ug+w -o ! -perm -ugo+rX \) -exec chmod ug+w,ugo+rX \{\} \;

echo "**** 7/9 - Create nginx log files ****"
mkdir -p /logs/nginx
chown -R "$USER":"$USER" /logs/nginx

echo "**** 8/9 - Create symfony log files ****"
[ ! -f /var/www/mendako/var/log ] && \
	mkdir -p /var/www/mendako/var/log

[ ! -f /var/www/mendako/var/log/prod.log ] && \
	touch /var/www/mendako/var/log/prod.log

echo "**** 9/9 - Setup complete, starting the server. ****"
php-fpm8.2
exec $@

echo "**** All done ****"