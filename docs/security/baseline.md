# Security baseline

Date: 2026-10-26. Audited commit: `83f7d77add126563025a22db3d46658be904b877` (clean worktree).

## Architecture and persistence

Dependency-free PHP front controller, Apache rewrite rules, PDO MySQL repositories,
eight PHP translation dictionaries and company-scoped accounting services. ZIP archives
are parsed in memory (no extraction), with CRC, entry-count and decompression limits.
`app/storage` contains generated configuration, Apache IP rules, imports, exports,
analysis and logs. Legacy root `config.php` takes precedence. Compose volumes are
`db_data:/var/lib/mysql` and `app_storage:/var/www/html/app/storage`. Neither may be removed.
Apache's persistent `.htaccess` symlink enables atomic administrator-managed IP rules.
Traditional Apache and the PHP development router are supported; Nginx/Caddy are not.

## Observed versions

Local CLI: PHP 8.4.5, without PDO MySQL. Existing local application image:
Alpine 3.20.10, PHP 8.2.28, Apache 2.4.67. Existing database image: MariaDB
10.11.18 on Alpine (amd64). Dockerfiles specify Alpine 3.20 and php82, not fixed
patch releases; installed versions depend on build time. The local application image
is below PHP 8.2.35 and affected by the reported request-handling CVEs.

Vendor evidence checked on the audit date:
- https://alpinelinux.org/releases/ (3.20 on-request support; 3.24 maintained)
- https://www.cyber.gc.ca/en/alerts-advisories/al26-026-critical-vulnerabilities-impacting-php-cve-2026-109618-cve-2026-109619 (fixed PHP 8.4.27; disable session upload progress also for CVE-2026-115560)
- https://www.ncsc.gov.uk/news/critical-security-issue-affecting-php

## Confirmed findings

Known Docker password fallbacks; custom unescaped SQL bootstrap; publicly claimable
initial setup; raw bootstrap exception in HTTP and logs; debug setup exception messages;
HTTP and all CLI commands run migrations; runtime SQL account gets ALL on its database;
account-wide five-failure denial of service; no trusted proxy resolver; ambiguous backslash
redirects; inline scripts require unsafe-inline CSP; upload names can overwrite temporary
imports; downloadable export symlinks are not rejected; archive names do not reject all
Windows absolute paths or special Unix entry types.

## Conditional findings and compatibility

Private files are already denied by Apache and development router, and archive downloads
are administrator-only. Company repositories and mutations generally scope IDs by company;
these require explicit regression tests, not an assumed IDOR finding. Secure cookies work
for direct TLS but not trusted TLS termination. Source is root-owned in Docker; Apache
master starts as root. Missing config cannot reset existing users, but setup and migrations
need serialization and a safe recovery path after config write failure. Changing database
image families requires an offline copy, backup/restore validation and an explicit upgrade;
changing environment passwords does not rotate existing SQL accounts.

## Reproduction and initial results

- `git status --short`, `git rev-parse HEAD`, source inspection with `cat`, `sed`, `rg`.
- `php tests/run.php`: **44 checks, 0 failed**.
- `php tests/Integration/run.php` on host: unavailable PDO MySQL; session directory read-only.
- Dedicated tmpfs MariaDB container, local port 18306, no production volumes; existing app
  image with source mounted read-only and entrypoint PHP: **11 integration checks passed**,
  including eight concurrent login workers, schema upgrade and export/import round trip.
- `docker run --rm --entrypoint sh accountplus-audit-app ...`: `php -v`, `php -m`,
  `httpd -v`, `/etc/alpine-release`; database `mariadbd --version`.
- `trivy` and `gitleaks` are not installed on the host. Image scanners must be run separately.

Unit runner: `php tests/run.php`. Integration runner requires a NEW EMPTY dedicated
`accountplus_test[_suffix]` database; it refuses populated databases. HTTP runner:
`python3 tests/Integration/web_check.py --url http://127.0.0.1:PORT`. Browser chart
fixture: `tests/Integration/charts.html`. Existing workflow runs syntax, unit and DB tests.
