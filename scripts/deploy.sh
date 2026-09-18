#!/usr/bin/env bash
# ==============================================================================
# WabaMini - Automated Production Deployment & Update Script
# Target Directory: /www/apps/wabamini
# Target Architecture: Ubuntu 24.04 LTS (Nginx + PHP 8.3-FPM + Node.js + Systemd)
# ==============================================================================

set -euo pipefail

APP_DIR="/www/apps/wabamini"
BRANCH="main"

echo "=========================================================="
echo " Starting WabaMini Production Deployment at $(date)"
echo "=========================================================="

if [ ! -d "$APP_DIR" ]; then
    echo "ERROR: Application directory $APP_DIR does not exist."
    exit 1
fi

cd "$APP_DIR"

# 1. Fetch and reset to latest Git commit
echo "--> [1/7] Fetching latest git release from origin/${BRANCH}..."
git fetch origin "${BRANCH}"
git reset --hard "origin/${BRANCH}"

# 2. Update Backend (Laravel)
echo "--> [2/7] Updating Laravel backend dependencies and caches..."
cd "$APP_DIR/backend"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# Put app in quick maintenance mode during migrations & cache refresh
php artisan down || true

echo "--> Running database migrations..."
php artisan migrate --force

echo "--> Refreshing Laravel production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Ensure symbolic link for public media storage
php artisan storage:link || true

# Bring app back up immediately
php artisan up

# 3. Update Baileys Worker
echo "--> [3/7] Updating Baileys WhatsApp worker..."
cd "$APP_DIR/baileys-worker"
mkdir -p storage/sessions
npm install --omit=dev

# 4. Update Frontend (Next.js)
echo "--> [4/7] Building Next.js production frontend..."
cd "$APP_DIR/frontend"
npm install --omit=dev
npm run build

# 5. Fix permissions
echo "--> [5/7] Enforcing strict file ownership and storage permissions..."
cd "$APP_DIR"
chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod -R 775 "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"
chmod -R 775 "$APP_DIR/baileys-worker/storage"

# 6. Restart Systemd Services
echo "--> [6/7] Reloading PHP-FPM and restarting systemd services..."
systemctl reload php8.3-fpm
systemctl restart wabamini-queue
systemctl restart wabamini-reverb
systemctl restart wabamini-baileys
systemctl restart wabamini-frontend
systemctl reload nginx

# 7. Post-Deployment Verification
echo "--> [7/7] Running automated health verification checks..."
sleep 3

FAILURES=0

echo -n "Checking Frontend (http://127.0.0.1:3100/healthz)... "
if curl -sf http://127.0.0.1:3100/healthz > /dev/null; then
    echo "OK"
else
    echo "FAILED"
    FAILURES=$((FAILURES + 1))
fi

echo -n "Checking Baileys Worker (http://127.0.0.1:3101/health)... "
if curl -sf http://127.0.0.1:3101/health > /dev/null; then
    echo "OK"
else
    echo "FAILED"
    FAILURES=$((FAILURES + 1))
fi

echo -n "Checking Reverb WebSocket Port (127.0.0.1:8080)... "
if nc -z 127.0.0.1 8080 2>/dev/null || timeout 1 bash -c "cat < /dev/null > /dev/tcp/127.0.0.1/8080" 2>/dev/null; then
    echo "OK"
else
    echo "FAILED"
    FAILURES=$((FAILURES + 1))
fi

echo "=========================================================="
if [ "$FAILURES" -eq 0 ]; then
    echo " WabaMini deployed successfully with zero errors!"
else
    echo " WARNING: Deployment finished with $FAILURES check failure(s)."
    echo " Inspect service logs via: journalctl -u wabamini-frontend -n 50 --no-pager"
fi
echo "=========================================================="
