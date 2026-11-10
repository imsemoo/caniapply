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
    umask 077
    status=0
    php artisan hunter:install --no-interaction \
        --country="${HUNTER_COUNTRY:-Egypt}" \
        --name="${HUNTER_NAME:-Your Name}" \
        --headline="${HUNTER_HEADLINE:-Full-stack web developer}" \
        --email="${HUNTER_EMAIL:-you@example.com}" \
        --demo > storage/first-login.new || status=$?

    if [ "$status" -ne 0 ]; then
        grep -v 'Password:' storage/first-login.new >&2 || true
    fi

    # A retry finds the account already made and prints no password, so it must not replace the file that holds one.
    if grep -q 'Password:' storage/first-login.new; then
        mv storage/first-login.new storage/first-login.txt
    fi
    rm -f storage/first-login.new

    if [ "$status" -ne 0 ]; then
        # Waiting keeps one clear message in the log instead of a restart loop.
        echo "Setup did not finish. Fix the setting above, then run: docker compose up -d --force-recreate" >&2
        exec tail -f /dev/null
    fi
    umask 022
    touch storage/.installed
    echo "First run done. Your login is in storage/first-login.txt inside the container. Delete that file once you have changed the password."
fi

# Nothing else watches these two, so each one comes back if it dies.
keep_running() {
    while :; do
        "$@" || echo "Stopped with an error, restarting in 5 seconds: $*" >&2
        sleep 5
    done
}

keep_running php artisan queue:work --sleep=3 --tries=3 --timeout=1400 --max-time=3600 &
keep_running php artisan schedule:work &

# --no-reload keeps the container environment. Without it, serve drops DB_DATABASE and APP_DEBUG.
exec php artisan serve --no-reload --host=0.0.0.0 --port=8000
