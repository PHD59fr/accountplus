#!/bin/bash
set -euo pipefail
# Legacy datadirs have no upstream .my-healthcheck.cnf. Check with the existing
# runtime account instead, keeping credentials out of process arguments.
source /usr/local/bin/docker-entrypoint.sh
file_env MYSQL_PASSWORD
export MYSQL_PWD="$MYSQL_PASSWORD"
result="$(mariadb --connect-timeout=2 --batch --skip-column-names --skip-print-query-on-error \
    --protocol=tcp -h127.0.0.1 -u"${MYSQL_USERNAME:-compte}" \
    -e "SELECT IF(@@skip_networking = 0 AND EXISTS (SELECT 1 FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB' AND SUPPORT IN ('YES','DEFAULT','ENABLED')), 1, 0)" 2>/dev/null)"
[ "$result" = 1 ]
