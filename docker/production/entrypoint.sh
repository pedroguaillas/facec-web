#!/bin/sh
set -e

cd /var/www/html

# storage/app vive en un volumen (comprobantes/XML firmados persistentes);
# el resto de storage/ es del contenedor y se recrea en cada deploy.
mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "Recordatorio: corre las migraciones manualmente si este deploy las trae:"
echo "  docker compose -f compose.prod.yaml exec app php artisan migrate --force"

exec "$@"
