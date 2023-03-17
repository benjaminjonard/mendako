#!/bin/sh

set -e

echo "**** Inject .env values ****" && \
	/inject.sh

[ ! -e /tmp/first_run ] && \
	echo "**** Migrate the database ****" && \
	cd /var/www/mendako && \
	php bin/console doctrine:migration:migrate --no-interaction --allow-no-migration --env=prod && \
	touch /tmp/first_run

echo "**** Create nginx log files ****" && \
mkdir -p /logs/nginx
chown -R www-data:www-data /logs/nginx

echo "**** Setup complete, starting the server. ****"
php-fpm8.2
exec $@