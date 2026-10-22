#!/bin/sh
set -eu
if [ "${1:-}" != '--initialize-sqlite' ] || [ "${APP_ENV:-}" != staging ] \
    || [ "${LOCAL_STAGING_ENABLED:-}" != 1 ] || [ "${DB_CONNECTION:-}" != sqlite ] \
    || [ "${DB_DATABASE:-}" != /var/lib/vora-staging/database.sqlite ]; then
    echo 'Refusing initialization: explicit opt-in and the isolated local SQLite configuration are required.' >&2
    exit 1
fi
mkdir -p /var/lib/vora-staging
touch "$DB_DATABASE"
chown -R www-data:www-data /var/lib/vora-staging
su -s /bin/sh www-data -c 'php artisan migrate --database=sqlite --no-interaction'
if [ ! -f /var/lib/vora-staging/.initialized ]; then
    su -s /bin/sh www-data -c 'php docker/local/seed.php --allow-disposable-accounts'
    touch /var/lib/vora-staging/.initialized
fi
echo 'Local staging is initialized. App: http://127.0.0.1:18080; Mail: http://127.0.0.1:18025'
