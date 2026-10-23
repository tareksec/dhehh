#!/bin/sh
# Container start: prepares the storage folder, then runs the web server
# (default) or the queue worker (CONTAINER_ROLE=worker).
set -e
cd /app

# A freshly mounted persistent volume can be empty, and files written by an
# older image belong to another user. Fix it once (skipped when it is right).
mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/framework/testing storage/logs bootstrap/cache

if [ "$(stat -c %U storage)" != "www-data" ]; then
    chown -R www-data:www-data storage bootstrap/cache
    chmod -R a+rwX storage bootstrap/cache
fi

# Same as "php artisan storage:link", without booting the app.
ln -sfn /app/storage/app/public /app/public/storage

# "docker run <image> some command" runs that command instead of the server.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

case "${CONTAINER_ROLE:-web}" in
    worker)
        exec gosu www-data php artisan queue:work --sleep=1 --tries=3 --max-time=3600
        ;;
    *)
        sed "s/__PORT__/${PORT:-80}/g" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf
        exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
        ;;
esac
