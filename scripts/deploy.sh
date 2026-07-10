#!/usr/bin/env bash
# Deploy (DEPLOY.md C2). Run ON the server as the deploy user:
#   ssh deploy@example.com 'bash /var/www/hep/current/scripts/deploy.sh'
# (first deploy: clone anywhere and run scripts/deploy.sh from the checkout)
set -euo pipefail

REPO=git@github.com:YOURUSER/video-workout-app.git
BRANCH=main
APP=/var/www/hep
PHP=php8.4
KEEP=3

TS=$(date +%Y%m%d%H%M%S)
NEW=$APP/releases/$TS
PREV=$(readlink -f $APP/current 2>/dev/null || true)

git clone --depth 1 --branch $BRANCH $REPO "$NEW"
cd "$NEW"

ln -sfn $APP/shared/.env .env
rm -rf storage && ln -sfn $APP/shared/storage storage

composer install --no-dev --optimize-autoloader --no-interaction

# first run only: generate the keys the .env skeleton left blank
# (after composer install — artisan needs vendor/autoload.php)
grep -q '^APP_KEY=.\+' $APP/shared/.env || php artisan key:generate --force
grep -q '^VAPID_PUBLIC_KEY=.\+' $APP/shared/.env || php artisan webpush:vapid
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules   # build-time only; ~300M of disk per release otherwise

# everything below must succeed before the swap — set -e guarantees we
# never point current/ at a half-built release
php artisan migrate --force
php artisan optimize   # config + route + view + event caches
php artisan storage:link

ln -sfn "$NEW" $APP/current

# mandatory + load-bearing: flushes opcache AND the realpath cache so fpm
# stops executing the old release. Do not skip; opcache_reset() is not enough.
sudo systemctl reload $PHP-fpm

# post-swap gate: /up through nginx; revert on failure.
# HTTPS first (post-certbot, plain HTTP is a 301 that never reaches PHP);
# HTTP host-header fallback covers the pre-TLS first deploy only.
up_check() {
    curl -fsS -m 10 https://example.com/up > /dev/null 2>&1 ||
    curl -fsS -m 10 -H "Host: example.com" http://127.0.0.1/up > /dev/null
}
if ! up_check; then
    echo "DEPLOY FAILED: /up unhealthy after swap — reverting" >&2
    if [ -n "$PREV" ] && [ -d "$PREV" ]; then
        ln -sfn "$PREV" $APP/current
        sudo systemctl reload $PHP-fpm
        echo "reverted to $PREV (NOTE: migrations are not rolled back)" >&2
    fi
    exit 1
fi

php artisan queue:restart
sudo systemctl start hep-queue   # no-op when already running

# prune old releases (disk is a one-box SPOF — DEPLOY.md F4)
cd $APP/releases
ls -1t | tail -n +$((KEEP + 1)) | xargs -r rm -rf

echo "deployed $TS ($(git -C "$NEW" rev-parse --short HEAD))"
