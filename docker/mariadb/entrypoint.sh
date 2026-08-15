#!/bin/bash
set -euo pipefail
# Keep the upstream initializer responsible for SQL quoting and password handling.
source /usr/local/bin/docker-entrypoint.sh
for name in MYSQL_PASSWORD MYSQL_ROOT_PASSWORD DB_MIGRATION_PASSWORD; do
    file_env "$name"
    if [[ -z "${!name}" ]]; then
        echo "[Account+] $name or ${name}_FILE is required." >&2
        exit 1
    fi
done
export MARIADB_DATABASE="${MYSQL_DATABASE:-compte}"
export MARIADB_USER="${MYSQL_USERNAME:-compte}"
export MARIADB_PASSWORD="$MYSQL_PASSWORD"
export MARIADB_ROOT_PASSWORD="$MYSQL_ROOT_PASSWORD"
export MARIADB_ROOT_HOST=localhost
if [[ ! "$MARIADB_DATABASE" =~ ^[A-Za-z][A-Za-z0-9_]{0,63}$ ]] ||
   [[ ! "$MARIADB_USER" =~ ^[A-Za-z][A-Za-z0-9_]{0,31}$ ]] ||
   [[ "$MARIADB_DATABASE" =~ ^(mysql|sys|performance_schema|information_schema)$ ]] ||
   [[ "$MARIADB_USER" == root ]]; then
    echo '[Account+] Invalid database or application user identifier.' >&2
    exit 1
fi
export DB_MIGRATION_USERNAME="${DB_MIGRATION_USERNAME:-compte_migration}"
if [[ ! "$DB_MIGRATION_USERNAME" =~ ^[A-Za-z][A-Za-z0-9_]{0,31}$ ]] ||
   [[ "$DB_MIGRATION_USERNAME" == "$MARIADB_USER" || "$DB_MIGRATION_USERNAME" == root ]]; then
    echo '[Account+] Invalid migration user identifier.' >&2
    exit 1
fi
exec /usr/local/bin/docker-entrypoint.sh "$@"
