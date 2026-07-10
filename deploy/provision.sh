#!/usr/bin/env bash
# One-time server provisioning for example.com (DEPLOY.md B2-B6).
# Run as root on a fresh Ubuntu 24.04 box:
#   scp deploy/provision.sh root@203.0.113.10:/root/ && ssh root@203.0.113.10 'bash /root/provision.sh'
set -euo pipefail

DOMAIN=example.com
APP_DIR=/var/www/hep
DEPLOY_USER=deploy
PHP=8.4

[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 1; }
grep -q 'Ubuntu 24.04' /etc/os-release || { echo "expected Ubuntu 24.04" >&2; exit 1; }

# --- deploy user + SSH hardening (B2) ---
if ! id "$DEPLOY_USER" &>/dev/null; then
    adduser --disabled-password --gecos "" "$DEPLOY_USER"
    usermod -aG sudo "$DEPLOY_USER"
    install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /home/$DEPLOY_USER/.ssh
    cp /root/.ssh/authorized_keys /home/$DEPLOY_USER/.ssh/authorized_keys
    chown "$DEPLOY_USER:$DEPLOY_USER" /home/$DEPLOY_USER/.ssh/authorized_keys
    chmod 600 /home/$DEPLOY_USER/.ssh/authorized_keys
fi

cat > /etc/ssh/sshd_config.d/99-hardening.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOF
systemctl reload ssh

ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -yq unattended-upgrades software-properties-common
dpkg-reconfigure -f noninteractive unattended-upgrades

# --- runtime (B3) ---
# PHP 8.4 via ondrej PPA: composer.lock is resolved on 8.4 (dev + CI); 24.04 ships only 8.3.
# Versioned php8.4-* names only — the PPA's unversioned php-* metapackages already target 8.5.
# Exit ramp: on Ubuntu 26.04, ppa-purge ondrej/php and return to distro PHP.
add-apt-repository -y ppa:ondrej/php
apt-get update -q
apt-get install -yq -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
    php$PHP-cli php$PHP-fpm php$PHP-pgsql php$PHP-mbstring php$PHP-xml php$PHP-curl \
    php$PHP-zip php$PHP-intl php$PHP-gd php$PHP-bcmath \
    nginx postgresql-16 git jq

# pin the CLI deterministically — the systemd unit, cron, and deploy.sh all call bare php
update-alternatives --set php /usr/bin/php$PHP
php -v | grep -q "^PHP $PHP" || { echo "/usr/bin/php is not $PHP" >&2; exit 1; }

# unattended-upgrades ignores PPA origins by default — without this, PHP silently
# stops receiving security patches after the switch to the ondrej packages
cat > /etc/apt/apt.conf.d/51ondrej-php <<'EOF'
Unattended-Upgrade::Allowed-Origins {
    "LP-PPA-ondrej-php:${distro_codename}";
};
EOF

curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt-get install -yq nodejs

php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
rm /tmp/composer-setup.php

snap install --classic certbot
ln -sf /snap/bin/certbot /usr/bin/certbot

php -m | grep -qi '^Zend OPcache$' || echo "WARNING: opcache not enabled — investigate before go-live" >&2

# --- Postgres (B4): scoped role, localhost only (24.04 default already localhost) ---
DB_PASS=""
DB_PASS_NOTE="unchanged (pre-existing role; password already in shared/.env)"
if ! sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='hep'" | grep -q 1; then
    DB_PASS=$(openssl rand -base64 24 | tr -d '/+=')
    sudo -u postgres psql -c "CREATE ROLE hep LOGIN PASSWORD '$DB_PASS'"
    DB_PASS_NOTE="$DB_PASS"
fi
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='hep_production'" | grep -q 1 || \
    sudo -u postgres createdb -O hep hep_production

# --- app layout (C1) + prod .env skeleton (C3) ---
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" $APP_DIR $APP_DIR/releases $APP_DIR/shared
sudo -u "$DEPLOY_USER" install -d $APP_DIR/shared/storage/{app/public,framework/{cache/data,sessions,views},logs}
chgrp -R www-data $APP_DIR/shared/storage
chmod -R g+w $APP_DIR/shared/storage

if [ ! -f $APP_DIR/shared/.env ]; then
# role exists from a prior run but .env is gone: reset the password so they match
if [ -z "$DB_PASS" ]; then
    DB_PASS=$(openssl rand -base64 24 | tr -d '/+=')
    sudo -u postgres psql -c "ALTER ROLE hep PASSWORD '$DB_PASS'"
    DB_PASS_NOTE="$DB_PASS (reset — role predated missing .env)"
fi
cat > $APP_DIR/shared/.env <<EOF
APP_NAME=HEP
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_TIMEZONE=America/Los_Angeles
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=hep_production
DB_USERNAME=hep
DB_PASSWORD=$DB_PASS

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true

QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log
MAIL_MAILER=log
MAIL_FROM_ADDRESS="hep@$DOMAIN"
MAIL_FROM_NAME=HEP

VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:admin@example.com
VITE_APP_NAME=HEP
EOF
    # .env is the highest-value file on the box (C4): deploy-owned, group-readable by fpm only
    chown "$DEPLOY_USER":www-data $APP_DIR/shared/.env
    chmod 640 $APP_DIR/shared/.env
fi

# --- php-fpm pool (B6) ---
sed -i \
    -e 's/^pm = .*/pm = ondemand/' \
    -e 's/^pm.max_children = .*/pm.max_children = 5/' \
    -e 's/^;\?pm.process_idle_timeout = .*/pm.process_idle_timeout = 10s/' \
    /etc/php/$PHP/fpm/pool.d/www.conf
systemctl reload php$PHP-fpm

# --- nginx vhost (B5): HTTP only; certbot --nginx adds TLS + redirect (D2) ---
# Never clobber a certbot-managed vhost on re-run
if grep -q ssl_certificate /etc/nginx/sites-available/$DOMAIN 2>/dev/null; then
    echo "vhost already TLS-managed by certbot — skipping vhost write"
else
cat > /etc/nginx/sites-available/$DOMAIN <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $APP_DIR/current/public;
    index index.php;

    client_max_body_size 2m;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml application/manifest+json;

    # Vite output is content-hashed: cache forever
    location /build/ {
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files \$uri =404;
    }

    # SW + manifest must revalidate every load or deploys strand old clients
    location = /sw.js {
        add_header Cache-Control "no-cache";
        try_files \$uri =404;
    }
    location = /manifest.webmanifest {
        add_header Cache-Control "no-cache";
        try_files \$uri =404;
    }

    location / {
        try_files \$uri /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        # \$realpath_root, not \$document_root: with the current-symlink layout,
        # \$document_root lets fpm's realpath cache serve the OLD release after a swap
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php$PHP-fpm.sock;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
EOF
fi
ln -sf /etc/nginx/sites-available/$DOMAIN /etc/nginx/sites-enabled/$DOMAIN
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# --- queue worker unit (C7) ---
cat > /etc/systemd/system/hep-queue.service <<EOF
[Unit]
Description=HEP queue worker
After=network.target postgresql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=3
ExecStart=/usr/bin/php $APP_DIR/current/artisan queue:work --tries=3 --max-time=3600 --sleep=3
StandardOutput=append:$APP_DIR/shared/storage/logs/queue.log
StandardError=inherit

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable hep-queue  # started by first deploy, not now — current/ doesn't exist yet

# --- scheduler cron (C8): logged, not /dev/null, so first-day verification is possible ---
cat > /etc/cron.d/hep-scheduler <<EOF
* * * * * $DEPLOY_USER cd $APP_DIR/current && /usr/bin/php artisan schedule:run >> $APP_DIR/shared/storage/logs/cron.log 2>&1
EOF
cat > /etc/logrotate.d/hep <<EOF
$APP_DIR/shared/storage/logs/*.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
}
EOF

# --- limited sudo for deploy.sh (reload/restart only) ---
cat > /etc/sudoers.d/hep-deploy <<EOF
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php$PHP-fpm, /usr/bin/systemctl restart hep-queue, /usr/bin/systemctl start hep-queue, /usr/bin/systemctl reload nginx
EOF
chmod 440 /etc/sudoers.d/hep-deploy

# --- GitHub deploy key (repo is private) ---
if [ ! -f /home/$DEPLOY_USER/.ssh/id_ed25519 ]; then
    sudo -u "$DEPLOY_USER" ssh-keygen -t ed25519 -N "" -C "hep-deploy@$DOMAIN" -f /home/$DEPLOY_USER/.ssh/id_ed25519
fi
# non-interactive git clone needs github.com pre-trusted
sudo -u "$DEPLOY_USER" bash -c 'ssh-keyscan -t ed25519 github.com 2>/dev/null >> ~/.ssh/known_hosts'

echo
echo "=== PROVISIONED. Next steps ==="
echo "1. Add this read-only deploy key to github.com/YOURUSER/video-workout-app → Settings → Deploy keys:"
echo
cat /home/$DEPLOY_USER/.ssh/id_ed25519.pub
echo
echo "2. DB password: $DB_PASS_NOTE"
echo "3. First deploy (as $DEPLOY_USER): bash scripts/deploy.sh   — generates APP_KEY + VAPID keys on first run"
echo "4. TLS (D2): certbot --nginx -d $DOMAIN"
echo "5. Back up shared/.env off-box, encrypted (F1) — it now holds the only DB password + soon APP_KEY/VAPID"
