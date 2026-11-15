#!/bin/bash

set -e

if [ "$(id -u)" -eq 0 ]; then
    RUN_AS_USER="setpriv --reuid $USER --regid $USER --init-groups"
else
    RUN_AS_USER=""
fi

echo "**** 1/7 - Make sure /uploads folders exist ****"
[ ! -f /uploads ] && \
	mkdir -p /uploads

[ ! -f /thumbnails ] && \
	mkdir -p /thumbnails

echo "**** 2/7 - Create the symbolic link for the /uploads folder ****"
# `if` blocks (not an && chain) so every step runs under `set -e`: a mid-chain failure would
# otherwise be exempt from `set -e` and silently skip `ln -s`, leaving the app nowhere to write.
# `mkdir -p` guarantees the source exists even when the image ships no seed dir.
if [ ! -L /app/public/public/uploads ]; then
	mkdir -p /app/public/public/uploads
	cp -r /app/public/public/uploads/. /uploads
	rm -r /app/public/public/uploads
	ln -s /uploads /app/public/public/uploads
fi

if [ ! -L /app/public/public/thumbnails ]; then
	mkdir -p /app/public/public/thumbnails
	cp -r /app/public/public/thumbnails/. /thumbnails
	rm -r /app/public/public/thumbnails
	ln -s /thumbnails /app/public/public/thumbnails
fi

echo "**** 3/7 - Setting env variables ****"
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

if [ "$(id -u)" -eq 0 ]; then
	echo "**** 4/7 - Create user and use PUID/PGID ****"
	PUID=${PUID:-1000}
	PGID=${PGID:-1000}
	if [ ! "$(id -u "$USER")" -eq "$PUID" ]; then usermod -o -u "$PUID" "$USER" ; fi
	if [ ! "$(id -g "$USER")" -eq "$PGID" ]; then groupmod -o -g "$PGID" "$USER" ; fi
	echo -e " \tUser UID :\t$(id -u "$USER")"
	echo -e " \tUser GID :\t$(id -g "$USER")"

	echo "**** 5/7 - Set Permissions ****"
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

	mkdir -p /app/public/var/log
	chown -R "$USER":"$USER" /app/public/var /app/public/.env.local /data /config
	chown "$USER":"$USER" /proc/self/fd/1 /proc/self/fd/2
else
	echo "**** 4/7 - Running as UID $(id -u), skipping user creation ****"
	echo "**** 5/7 - Running as UID $(id -u), skipping permissions ****"
fi

echo "**** 6/7 - Migrate the database ****"
cd /app/public && \
$RUN_AS_USER php bin/console doctrine:migration:migrate --no-interaction --allow-no-migration --env=prod

echo "**** 7/7 - Setup complete, starting supervisord (web + workers). ****"
export WORKER_PHP_MEMORY_LIMIT=${WORKER_PHP_MEMORY_LIMIT:-2G}
exec $RUN_AS_USER supervisord -c /app/public/docker/supervisord.conf
