#!/bin/sh
# Everything worth keeping lives in storage/, which is a volume.
set -e

mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs

[ -f storage/.env ] || cp .env.example storage/.env
ln -sf storage/.env .env
grep -q '^APP_KEY=base64' storage/.env || php artisan key:generate --force

touch "$DB_DATABASE"
php artisan migrate --force

if [ ! -f storage/.installed ]; then
    (
        umask 077
        php artisan hunter:install --no-interaction \
            --country="${HUNTER_COUNTRY:-Egypt}" \
            --name="${HUNTER_NAME:-Your Name}" \
            --headline="${HUNTER_HEADLINE:-Full-stack web developer}" \
            --email="${HUNTER_EMAIL:-you@example.com}" \
            --demo > storage/first-login.txt
    )
    touch storage/.installed
    echo "First run done. Your login is in storage/first-login.txt inside the container."
fi

php artisan queue:work --sleep=3 --tries=3 --timeout=1400 &
php artisan schedule:work &

# --no-reload keeps the container environment. Without it, serve drops DB_DATABASE and APP_DEBUG.
exec php artisan serve --no-reload --host=0.0.0.0 --port=8000
