# Sourced by the official entrypoint only when initializing an empty datadir.
# Identifiers are strictly checked by the wrapper; literals use the vendor SQL escaper.
file_env DB_MIGRATION_PASSWORD
migration_password_escaped="$(docker_sql_escape_string_literal "$DB_MIGRATION_PASSWORD")"
docker_process_sql <<SQL
CREATE USER '${DB_MIGRATION_USERNAME}'@'%' IDENTIFIED BY '${migration_password_escaped}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON \`${MARIADB_DATABASE}\`.* TO '${DB_MIGRATION_USERNAME}'@'%';
REVOKE ALL PRIVILEGES ON \`${MARIADB_DATABASE}\`.* FROM '${MARIADB_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MARIADB_DATABASE}\`.* TO '${MARIADB_USER}'@'%';
SQL
unset migration_password_escaped DB_MIGRATION_PASSWORD
