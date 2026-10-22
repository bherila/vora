#!/bin/sh
set -eu
mkdir -p /var/lib/vora-staging storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data /var/lib/vora-staging storage
if [ ! -f /var/lib/vora-staging/.initialized ]; then
    echo 'Waiting for explicit local SQLite initialization. See docker/local/README.md.'
fi
while [ ! -f /var/lib/vora-staging/.initialized ]; do sleep 1; done
exec su -s /bin/sh www-data -c 'exec php -S 0.0.0.0:8080 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
