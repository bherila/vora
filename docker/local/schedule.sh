#!/bin/sh
set -eu
while [ ! -f /var/lib/vora-staging/.initialized ]; do sleep 1; done
exec su -s /bin/sh www-data -c 'exec php artisan schedule:work --no-interaction'
