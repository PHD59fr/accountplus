# Deployment and recovery

Read before replacing any image. Preserve both named volumes (`db_data`, `app_storage`)
and the Compose project name. Never remove volumes to solve an upgrade problem.

## Restoring configuration from deployments without persistent app storage

Before recreating a container that stores `config.php` at `/var/www/html/config.php`
and has no `app_storage` volume, copy that file and the full `app/storage/` directory
out of the old container. Restore the configuration to
`/var/www/html/app/storage/app/config.php` and archives to the matching storage
subdirectories in the persistent volume. Make them readable by the `apache` user.
Traditional installations can continue to use a root `config.php`, which takes
precedence over the storage configuration. Back up both locations when present.

## Database image transition

The audited local image is MariaDB 10.11.18/Alpine. The target is the official
MariaDB **10.11 LTS** image (same database series, maintained patch updates).
Do not attach a different major series or a newer source datadir to an older server.
Record `SELECT VERSION()`, image digests, configuration and `SHOW GRANTS` first.
Stop application writes and scheduled jobs. Make a logical backup using
`mariadb-dump --single-transaction --routines --events --triggers --hex-blob`
with an administrator-only defaults file, plus a cold copy of the stopped datadir
and all application storage. Verify the dump exit status and checksum. Keep the
old images by digest. Rehearse restoring the dump into a separate empty 10.11
instance, preserving utf8mb4, row counts, relationships and financial totals.
Only then replace the production image. New initialization variables do not
change existing users or passwords. The wrapper delegates SQL initialization to
the official entrypoint and refuses invalid database/user identifiers.
No automatic major upgrades or destructive initialization are enabled.

If an existing datadir requires `mariadb-upgrade`, perform it explicitly on the
COPY first, inspect its exit status, and repeat during the approved outage.
For older series follow MariaDB's documented supported upgrade hops or use a
logical restore into a separate server. Never assume downgrade compatibility.
Rollback: stop writes, restore the old image AND cold backup (or logical dump to
an empty old-version test/production replacement), restore matching app storage
and configuration, validate totals and users, then reopen traffic. New writes
after the backup need a separate reconciliation plan.

## Secrets and initial startup

Copy `.env.example` to `.env`, mode 0600. Generate independent random secrets
(e.g. `openssl rand -hex 32`). Empty secrets fail startup without printing values.
Compose preserves legacy `MYSQL_USERNAME` naming. For a direct container deployment,
`MYSQL_PASSWORD_FILE`, `MYSQL_ROOT_PASSWORD_FILE` and application `DB_PASSWORD_FILE`
accept mounted secret files. Environment and corresponding FILE are mutually
exclusive; no silent precedence. File paths refer to container paths: mount secrets
read-only into each service using a local Compose override. Compose `.env` single
quotes preserve literal dollar signs; shell environment variables must be quoted.
The application storage config, once created, remains the connection source of truth.

To rotate existing credentials, create a new SQL account using a secure SQL client,
grant the documented database privileges, update the stored config atomically and
its deployment secret, verify login/CLI/import/export, then revoke the old account.
Changing Compose secrets alone never rotates database accounts. Keep secrets out of
command arguments, shell history, URLs and logs. Do not publish port 3306.

## Setup authorization and recovery

Generate an independent installation token out of band, for example with
`openssl rand -hex 32`. Set `APP_SETUP_ENABLED=true` and `APP_SETUP_TOKEN` in the
server environment, or provide a readable `APP_SETUP_TOKEN_FILE` instead. The token
must contain at least 32 characters. Open `/setup` over a private or authenticated
connection and enter the token in its password field alongside the normal CSRF
protection. Tokens in URLs are not accepted. Disable setup and remove its token
after the administrator has been created.

If the administrator is created but configuration writing fails, restore or write
the private configuration using the original database credentials. The installation
marker and existing-user checks prevent setup from resetting that administrator.
Do not remove database users or persistent data to recover a missing configuration.

Verbose local diagnostics require `app.env=development` and `app.debug=true` in the
active configuration, `APP_LOCAL_DEBUG=true`, and a direct loopback peer. Production
errors display a generic message with a correlation reference; use the sanitized
server logs for diagnostics. Keep verbose diagnostics disabled on public services.

## Runtime privileges and explicit migrations

For a NEW Docker database the initializer creates:
- Runtime user: SELECT, INSERT, UPDATE, DELETE on its database only.
- `DB_MIGRATION_USERNAME` (default compte_migration): SELECT, INSERT, UPDATE, DELETE,
  CREATE, ALTER, DROP, INDEX, REFERENCES on that database only. Its independent
  `DB_MIGRATION_PASSWORD` (or mutually exclusive FILE) is mandatory.
- Root: localhost administration only; never passed to the application.

Before first setup run `db:migrate` with the deployment credentials, then use the
runtime user in the setup form. Authorized setup retains legacy empty-schema
initialization for traditional deployments with an explicitly provisioned DDL user;
replace that stored credential with the DML user before publishing the application.
Never store a migration password in the web config on a production deployment.
Ordinary CLI jobs use runtime credentials and check the schema without changing it.
`help`, `app:name`, `import:inspect` and `import:from-csv` require no database.
Two `db:migrate` invocations serialize using a per-database advisory lock; setup uses
its own lock and refuses any existing user. Migration history is preserved.
MySQL DDL can commit implicitly: after failed DDL, stop traffic and inspect/recover
the failed migration from backup rather than expecting a full DDL transaction rollback.

Existing accounts are NOT automatically revoked or rotated. Before reopening traffic:
create a distinct migration account, run `db:migrate` in a short-lived process,
verify schema and financial totals, provision a new runtime account with only DML,
update `app/storage/app/config.php` (or legacy config.php), test all features, then
revoke the old runtime account. SQL commands below contain placeholders, not passwords;
execute them through a private authenticated client and replace identifiers safely:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON compte.* TO 'new_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
    ON compte.* TO 'deployment_user'@'%';
SHOW GRANTS FOR 'new_runtime'@'%';
```

Use CREATE USER / ALTER USER with correctly escaped literals through the SQL client
for password provisioning; do not interpolate environment strings into shell SQL.
Check the runtime user cannot CREATE TABLE and can still import/export and write audit logs.

## Proxy, HTTPS and sessions

`APP_TRUSTED_PROXIES` is a comma-separated list of explicit IPv4/IPv6 addresses or
CIDRs of actual ingress proxies. Empty means no proxy trust. The resolver walks
X-Forwarded-For right to left, ignoring headers from untrusted peers and falling back
to the peer for malformed chains. The trusted ingress must OVERWRITE incoming
X-Forwarded-Proto with a single `https` or `http`, and append/replace X-Forwarded-For
according to its trusted topology. Never trust all Internet addresses. Do not expose
the backend directly. Authentication, audit and application IP checks use one resolver.

Apache IP Require rules also need the real client address. For proxied traditional
Apache configure mod_remoteip with exactly the trusted proxy CIDRs, for example:

```apache
RemoteIPHeader X-Forwarded-For
RemoteIPTrustedProxy 192.0.2.10
```

The application also enforces saved IP restrictions after resolving proxies, but
Apache may deny access first unless mod_remoteip is correctly configured. Review
existing whitelist rules through a private connection before enabling a proxy.

Direct TLS and trusted forwarded HTTPS set Secure cookies. For HTTPS-only production
set `APP_SESSION_SECURE=true` as an additional guarantee (local plain HTTP login will
then require HTTPS). HttpOnly, SameSite=Lax, strict cookie-only sessions remain enabled.
Login/logout invalidate the old session ID. Active state, role and company assignments
are refreshed from SQL each request. Configure HSTS at the TLS ingress; the app can
emit `APP_HSTS_MAX_AGE` only for detected HTTPS. Do not enable includeSubDomains/preload
without a separate domain inventory. Never send HSTS from a plain local HTTP service.

## Private storage, Apache and permissions

The legacy project document root is retained for compatibility with stored Apache IP
rules and traditional hosting. This requires Apache rewrite and access rules; it is
not safe to serve this directory with an arbitrary static server. Deploy the supplied
`.htaccess` plus the Directory restrictions from `docker/apache-accountplus.conf`,
including denial of `app` and script execution in assets. Nginx/Caddy deployments
are not supported by this change; a public-only document root would require a separate
migration of assets, routing and administrator-managed IP restrictions.

Archives/configuration stay in private storage and downloads require an administrator.
Archive basenames are server-generated; downloads reject symlinks and traversal.
Upload temporary names are unique, mode 0600 and removed on success/failure. ZIP
parsing never extracts to disk; absolute size bounds prevent bombs without imposing
an unnecessary compression-ratio cap. Non-regular entries and Windows paths are
rejected. A new 200,000 physical-line cap per NDJSON entry prevents memory exhaustion;
exceptionally large historical archives must be split using the existing format.
SHA-256 is an import fingerprint, not an authenticity signature: accept archives only
from trusted sources. Imported record-level warnings remain supported by the existing
format; structural failures/invalid references roll back accounting writes.

Source remains root-owned and read-only. Docker's short initial root process prepares
storage ownership and runtime directories, then execs Apache/PHP as apache. Root
capabilities are limited to those needed for ownership, privilege switching and port
binding; no-new-privileges and a process limit apply. MariaDB's official entrypoint
switches to mysql; a restricted setpriv wrapper replaces the upstream gosu binary
whose embedded Go runtime produced HIGH CVEs in the first scan. Only the mysql
service-user switch is supported. The final database image is scanned again.
Generated config/archive modes are 0600. Apache's editable rules remain 0644 in the
private persistent storage directory. Keep storage backups outside the document root.

Default Apache access logs intentionally omit query strings/referrers/cookies; custom
traditional hosting/proxy logs must do the same so accidentally submitted GET secrets
cannot be logged. PHP upload progress is disabled (including the vendor-recommended
mitigation for CVE-2026-115560). HTTP errors show only correlation IDs; sanitized logs
retain exception type/code/source basename/line, never exception arguments or text.

## Remaining boundaries

Network throttling can affect users sharing a NAT; IPv6 /64 groups privacy addresses.
A distributed botnet can still spend five attempts per origin and account in each
15-minute window. Edge-level rate limits, monitoring and access controls are needed
for large distributed attacks. Username pair normalization is ASCII case-folding;
SQL collation aliases are still bounded by the shared network budget. Bcrypt gives
unknown/inactive accounts a dummy verification cost; no account-wide lockout is used.
Expired counters are deleted in batches of 100 per attempt (at most two new buckets
per attempt). This is bounded under ordinary load; very large attack volumes also
need ingress controls and database monitoring.

Production upgrade order: tested backup/restore, maintenance window, stop jobs and
writes, preserve volume copies, build and scan both images, start DB, explicitly
migrate with deployment credentials, start app with DML config, verify login/companies/
transactions/recurrences/archives/IP settings, disable setup, then reopen ingress.

## Reproducing the verified backup and restore rehearsal

`python3 tests/Integration/upgrade_rehearsal.py` builds the original audited commit,
seeds two additional companies, restricted users, accounts, transactions and recurring
rules, and writes a private configuration, IP blacklist and archive into a new storage
volume. It takes a logical dump, stops the source database, and copies its datadir to
separate backup and upgrade volumes. The official 10.11 image starts on the copy;
every table's row count and row-content hash must remain identical before/after the
explicit migration. A separate logical restore must retain accounting totals and
users. The upgraded application must load login, export, config and saved IP rules.
Rollback starts the old image against a fresh copy of the PRE-upgrade cold backup;
all table hashes must equal the original. The upgraded datadir is never downgraded.

This rehearsal passed on amd64. Named test volumes are intentionally retained and
printed with unique prefixes for inspection; no volume-removal command is run.
Repeat with a private representative copy of your own installation and measure the
outage/backup size before production. For secret files, use single-line Unix files;
the short-lived deployment process must be able to read its migration secret.
Do not mount that secret into the long-running web container.
