#!/bin/sh
set -e

# Role of this container (app, queue, reverb, scheduler, or custom)
ROLE=${CONTAINER_ROLE:-app}
ENVIRONMENT=${APP_ENV:-production}

echo "Starting WhatsOmni Backend Container [Role: $ROLE] [Env: $ENVIRONMENT]..."

# Ensure required storage directories exist
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/app/public \
         /var/www/html/storage/logs

# Fix permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create storage symlink if it doesn't exist
if [ ! -L /var/www/html/public/storage ]; then
    echo "Creating storage symlink..."
    php artisan storage:link || true
fi

# If role is app, wait for database connection and run migrations if enabled
if [ "$ROLE" = "app" ]; then
    if [ "$RUN_MIGRATIONS" = "true" ]; then
        echo "Waiting for MySQL database to become ready..."
        MAX_TRIES=30
        COUNT=0
        until php -r "try { new PDO('mysql:host=' . env('DB_HOST', 'mysql') . ';port=' . env('DB_PORT', 3306) . ';dbname=' . env('DB_DATABASE', 'whatsomni'), env('DB_USERNAME', 'whatsomni'), env('DB_PASSWORD', 'whatsomni')); echo 'Connected' . PHP_EOL; exit(0); } catch (Exception \$e) { exit(1); }" > /dev/null 2>&1; do
            COUNT=$((COUNT + 1))
            if [ $COUNT -gt $MAX_TRIES ]; then
                echo "Warning: Database connection timed out after $MAX_TRIES attempts. Skipping automatic migration."
                break
            fi
            echo "Database unavailable, waiting 2 seconds (attempt $COUNT/$MAX_TRIES)..."
            sleep 2
        done

        if [ $COUNT -le $MAX_TRIES ]; then
            echo "Database connected. Running migrations..."
            php artisan migrate --force || true
        fi
    fi

    if [ "$ENVIRONMENT" = "production" ] && [ "$CACHE_ON_STARTUP" = "true" ]; then
        echo "Caching Laravel configuration and routes for production..."
        php artisan config:cache || true
        php artisan route:cache || true
        php artisan view:cache || true
        php artisan event:cache || true
    fi
fi

# Dispatch based on role
if [ "$ROLE" = "app" ]; then
    echo "Starting PHP-FPM server on port 9000..."
    exec php-fpm -F
elif [ "$ROLE" = "queue" ]; then
    echo "Starting Laravel Queue Worker..."
    exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --timeout=120
elif [ "$ROLE" = "reverb" ]; then
    echo "Starting Laravel Reverb WebSocket Server on port 8080..."
    exec php artisan reverb:start --host=0.0.0.0 --port=8080
elif [ "$ROLE" = "scheduler" ]; then
    echo "Starting Laravel Task Scheduler..."
    while true; do
        php artisan schedule:run --verbose --no-interaction || true
        sleep 60
    done
else
    # Execute any custom command passed to docker
    exec "$@"
fi
