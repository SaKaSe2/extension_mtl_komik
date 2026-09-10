#!/bin/bash

echo "========================================"
echo " KomikoID-MTL Backend Starting..."
echo "========================================"

# Force Laravel logs to stderr so exceptions are visible in Render log console
export LOG_CHANNEL="stderr"

# 1. Pastikan APP_KEY valid base64
if [ -z "$APP_KEY" ] || [[ "$APP_KEY" != base64:* ]]; then
    echo "[STARTUP] Generating valid base64 APP_KEY..."
    export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
fi

# 2. Pastikan SQLite database siap jika menggunakan driver sqlite (lokal/fallback)
if [ "${DB_CONNECTION}" = "sqlite" ] || [ -z "${DB_CONNECTION}" ]; then
    echo "[STARTUP] Initializing SQLite database file..."
    mkdir -p /var/www/html/database
    touch /var/www/html/database/database.sqlite
    chown -R www-data:www-data /var/www/html/database
    chmod -R 777 /var/www/html/database
fi

# 2b. Kalau pakai PostgreSQL, tunggu hingga DB siap (max 30 detik)
if [ "${DB_CONNECTION}" = "pgsql" ]; then
    echo "[STARTUP] Waiting for PostgreSQL connection..."
    MAX_TRIES=10
    TRIES=0
    until php artisan db:show --json > /dev/null 2>&1 || [ $TRIES -ge $MAX_TRIES ]; do
        TRIES=$((TRIES + 1))
        echo "[STARTUP] DB not ready, retry $TRIES/$MAX_TRIES..."
        sleep 3
    done
    if [ $TRIES -ge $MAX_TRIES ]; then
        echo "[STARTUP] WARNING: Could not connect to PostgreSQL after $MAX_TRIES tries, continuing anyway..."
    else
        echo "[STARTUP] PostgreSQL connection OK."
    fi
fi

# 3. Pastikan permission direktori storage dan cache writable oleh www-data
mkdir -p /var/www/html/storage/logs \
         /var/www/html/storage/framework/cache \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

# 4. Jalankan migrasi database (tanpa menghapus data)
echo "[STARTUP] Running database migrations..."
php artisan migrate --force 2>&1
echo "[STARTUP] Migrations completed."

# 5. Buat symlink storage untuk akses file publik
echo "[STARTUP] Creating storage link..."
php artisan storage:link --force 2>&1
echo "[STARTUP] Storage link created."

# 6. Optimize: cache config, routes, events, dan views
echo "[STARTUP] Optimizing application..."
php artisan optimize 2>&1
echo "[STARTUP] Optimization completed."

# 7. Jalankan queue worker hemat memori di background
echo "[STARTUP] Starting queue worker in background..."
php artisan queue:work "${QUEUE_CONNECTION:-database}" \
    --sleep=3 \
    --tries=3 \
    --timeout=3600 \
    --max-jobs=50 \
    --memory=128 \
    2>&1 &
QUEUE_PID=$!
echo "[STARTUP] Queue worker started with PID: $QUEUE_PID"

# 8. Sesuaikan port Apache dengan environment variable PORT (Render memberikan PORT dinamis)
TARGET_PORT="${PORT:-8080}"
echo "[STARTUP] Binding Apache to port: $TARGET_PORT"
sed -i "s/Listen [0-9]\+/Listen ${TARGET_PORT}/" /etc/apache2/ports.conf
sed -i "s/*:[0-9]\+/*:${TARGET_PORT}/" /etc/apache2/sites-available/000-default.conf

echo "========================================"
echo " All services initialized successfully!"
echo " - Database: ready"
echo " - Storage: linked"
echo " - Cache: optimized"
echo " - Queue Worker: running (PID $QUEUE_PID)"
echo " - Apache listening on port: $TARGET_PORT"
echo "========================================"

# 9. Jalankan Apache di foreground
exec apache2-foreground
