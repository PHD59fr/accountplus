#!/bin/sh
set -e
umask 0077

STORAGE_DIR="${STORAGE_DIR:-/var/www/html/app/storage}"
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-compte}"
if [ -n "${DB_PASSWORD_FILE:-}" ]; then
    if [ -n "${DB_PASSWORD:-}" ] || [ ! -r "$DB_PASSWORD_FILE" ]; then
        echo '[Account+] Set exactly one readable DB_PASSWORD_FILE or DB_PASSWORD.' >&2
        exit 1
    fi
    DB_PASSWORD="$(cat "$DB_PASSWORD_FILE")"
    unset DB_PASSWORD_FILE
fi
if [ -z "${DB_PASSWORD:-}" ]; then
    echo '[Account+] DB_PASSWORD or DB_PASSWORD_FILE is required.' >&2
    exit 1
fi
export DB_HOST DB_PORT DB_USERNAME DB_PASSWORD

mkdir -p "$STORAGE_DIR/imports" "$STORAGE_DIR/exports" "$STORAGE_DIR/analysis" "$STORAGE_DIR/logs" "$STORAGE_DIR/app"
php /var/www/html/app/bin/prepare-apache.php
chown -R apache:apache "$STORAGE_DIR"
su-exec apache:apache chmod -R u+rwX "$STORAGE_DIR"

echo "[Account+] Waiting for MySQL at ${DB_HOST}:${DB_PORT} ..."
i=0
until php -r 'try {
    $pdo = new PDO(
        "mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";charset=utf8mb4",
        getenv("DB_USERNAME"),
        getenv("DB_PASSWORD")
    );
    $pdo->query("SELECT 1");
} catch (Throwable $e) {
    exit(1);
}' >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then
        echo "[Account+] MySQL did not become ready in time." >&2
        exit 1
    fi
    sleep 2
done
echo "[Account+] MySQL is ready."

mkdir -p /run/apache2 /var/log/apache2
chown -R apache:apache /run/apache2 /var/log/apache2
exec su-exec apache:apache "$@"
