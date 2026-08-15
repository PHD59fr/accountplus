# Account+ security remediation report

Baseline: `83f7d77add126563025a22db3d46658be904b877`. Baseline recorded on
2026-10-26; final local checks on 2026-10-27 (Europe/Paris).
The branch was restored to the verified remote commit with all edits retained,
as requested. No commits or pushes are part of the delivered changes.

## Scope and outcome

20 tasks FIXED, 1 NOT_REPRODUCED, 1 PARTIAL. These are task counts, not a claim of
20 distinct exploitable vulnerabilities. No production database or pre-existing
volume was attached, reset or removed. Tests use new named resources or tmpfs.

The deployable amd64 runtime is PHP 8.4.27, Apache 2.4.69, Alpine 3.24.3;
MariaDB is 10.11.19 on the official Ubuntu 22.04 base. Both final images pass
Trivy 0.76.1 with **0 HIGH and 0 CRITICAL** findings. See `scan-summary.json` for
image IDs and report hashes. PHP package versions were checked directly, without
assuming backports. Session upload progress is disabled as recommended by the
vendor advisory, including the additional CVE-2026-115560 mitigation.

Alpine/MariaDB upstream manifests contain amd64 and arm64 variants. Actual local
execution was amd64. The native arm64 CI matrix still requires its first hosted run.
The GitHub workflow cannot be claimed green until these uncommitted changes are
published by the owner; no remote CI/deployment was triggered.

## Task status

| ID | Problem | Status | Modified files | Validation | Residual risk |
|---|---|---|---|---|---|
| SEC-000 | Reproducible baseline | FIXED | `docs/security/baseline.md` | Clean starting commit; 44 unit and 11 integration checks initially passed | Original host PHP 8.4.5 is not the production target; use patched Docker or upgrade traditional hosting |
| SEC-001 | PHP runtime | FIXED | `Dockerfile`, `app/bin/check-runtime.php`, workflow | No-cache pull/build; installed PHP 8.4.27 and required extensions; Apache/HTTP/CLI/ZIP tested; clean image scan | arm64 execution pending hosted CI; future packages must be rebuilt/scanned |
| SEC-002 | Maintained MariaDB and data migration | FIXED | `docker/mariadb/*`, Compose, upgrade guide | Cold copy of old-series volume; all table row hashes equal after upgrade; logical restoration; cold rollback with original image | Production-sized backup and outage timing remain operator-specific; only 10.11 -> 10.11 physically rehearsed |
| SEC-003 | Default SQL credentials | FIXED | Compose, entrypoints, `Secret.php`, config, ignores, `.env.example` | Missing secrets fail; complex environment/file secret tests; logs contain no secret; real Compose startup | Existing SQL passwords/config need explicit operator rotation; environment changes alone do not rotate accounts |
| SEC-004 | SQL bootstrap interpolation | FIXED | Official MariaDB initializer, validating wrapper, DML init hook | Complex quote/backslash/dollar/space secrets initialize; invalid identifiers fail; vendor SQL escaping used; existing datadir not reinitialized | Database/user identifiers restricted to ASCII letters/digits/underscore, starting with a letter; DB max 64, user max 32 |
| SEC-005 | First administrator takeover | FIXED | Kernel, setup controller/template, installer/state, SetupAuthorization, locales | Disabled/invalid/GET token denied; independent CSRF; DML setup after explicit schema init; second admin refused; config deletion returns 503; setup DB lock | Token must be generated out of band, transmitted privately and removed after use; restore lost/partially written config administratively |
| SEC-006 | Sensitive HTTP errors | FIXED | `SecurityError.php`, kernel, setup, index, locales | Secret markers absent from production 500/503 pages and logs; real PDO host failure with debug configured; correlation reference test | Logs deliberately omit raw exception text/arguments; inspect type/code/source/line and private operational context |
| SEC-007 | Account denial through lockout | FIXED | LoginRateLimiter, AuthController/AuthService | Pair/network expiry, shared budgets, successful-login isolation; 8 concurrent workers; actual HTTP 429 and Retry-After | NAT contention and distributed botnets remain; ASCII identifier case-folding and edge controls documented |
| SEC-008 | Proxy origin trust | FIXED | Request, auth/audit/admin/IP services, Compose, docs | IPv4/IPv6/CIDR and multi-hop unit tests; malformed/untrusted headers ignored; real trusted-origin whitelist and forged-header denial | Ingress must overwrite protocol and maintain the client chain; Apache Require rules need matching mod_remoteip configuration |
| SEC-009 | Implicit migrations | FIXED | Kernel, CLI, Migrator, installer | DML-only HTTP/CLI; concurrent explicit migrations; idempotence; invalid SQL failure; schema mismatch 503 | MySQL DDL has implicit commits; failed DDL recovery requires inspection/backup, not assumed automatic rollback |
| SEC-010 | SQL least privilege | FIXED | MariaDB init hook, CLI credentials, Secret/config, upgrade guide | Runtime CREATE TABLE rejected; HTTP accounts/transactions/audit/ZIP and CLI jobs run with DML; migration account succeeds | Existing accounts must be split/revoked explicitly; initial traditional setup with a DDL user requires a DML credential cutover |
| SEC-011 | Private code/config/archives | FIXED | Apache rules, router, transfer controller, IP service, docs | Real archive/config/source probes, encoded paths, Apache and PHP router; administrator downloads; traversal/symlink checks; private file modes | Legacy document root retained with documented Apache controls; arbitrary static servers and Nginx/Caddy are unsupported |
| SEC-012 | External redirects | FIXED | Response, CompanyController | Absolute, scheme-relative, backslash, nested encoding, CR/LF/control rejection; valid query strings preserved; HTTP company redirect | Future redirects must use the shared response validator |
| SEC-013 | Session security | FIXED | index, Request, AuthService, Compose | Real ID regeneration/login/logout/stale-cookie denial; Secure/HttpOnly/SameSite; trusted/untrusted HTTPS; inactive accounts and role revocation refreshed | HTTPS-only production must configure TLS ingress/APP_SESSION_SECURE; local plain HTTP requires a separate configuration |
| SEC-014 | Headers and CSP | FIXED | index, nonce helper, templates, security-ui.js, Apache | Real headless browser renders all charts and changes dashboard settings under nonce CSP; event attributes removed; JSON script termination escaped; headers/HSTS tested | style-src unsafe-inline retained for existing styling; HSTS domain-wide flags deliberately require separate review |
| SEC-015 | Process/filesystem privileges | FIXED | Dockerfile, entrypoint, Compose, Apache, MariaDB helper | Read-only root and reduced capabilities start Apache as apache; DML and uploads work; MariaDB switches to mysql; production source root-owned | Short startup preparation still uses root; only explicit storage/tmp/runtime directories writable; source files must remain immutable |
| SEC-016 | ZIP/import/export audit | FIXED | Archive, ImportService, ExportService, TransferController, tests | Archive round trip, CRC, bounds, truncation, Windows/Unix traversal and special-type rejection, NDJSON split/line bound; transaction rollback and cross-company references | No extraction-based Zip Slip reproduced; absolute decompression limits used instead of arbitrary ratio cap; SHA-256 is deduplication, not authenticity |
| SEC-017 | Multi-company IDOR | NOT_REPRODUCED | Integration security tests and authorization documentation | Two restricted users/two companies plus admin; scoped account/transaction/recurrence reads/mutations, transfer routes, missing IDs, active/role refresh; persisted assignments survive upgrade | No IDOR confirmed in examined routes; this is not a proof against every possible future endpoint/input combination |
| SEC-018 | Security CI | PARTIAL | workflow, Dependabot, policy gate/tests, CI policy | Workflow YAML parses; equivalent local jobs pass; scanners and canary tested; actual critical witness blocks; EOL images block | First GitHub-hosted PR/master and arm64 runs pending owner publication; job permissions/token exposure not verified on a hosted run |
| SEC-019 | Automated regression coverage | FIXED | Unit/security tests, integration suites, policy test | 53 unit checks; 14 DB integration checks; full HTTP/browser scenario; physical upgrade/rollback; lint and JS syntax | Exhaustive HTTP fuzzing/load testing and production data testing remain outside these synthetic regression fixtures |
| SEC-020 | Security and upgrade documentation | FIXED | README, `.env.example`, SECURITY.md, `docs/security/*` | Commands exercised with Compose v1 equivalent and Docker; backup/restore/cold rollback procedures rehearsed | Operator must supply real secrets, SQL rotation, ingress configuration, volume preservation and production-sized rehearsal |
| SEC-021 | End-to-end validation | FIXED | Security stack/upgrade rehearsal scripts and this report | Fresh install, schema/data migration, HTTP/browser controls, CLI, logical restore and physical rollback all pass on amd64 | Hosted CI/arm64 follow-up belongs to SEC-018; no production deployment performed |

## Local command results

| Requested command / equivalent | Result |
|---|---|
| `docker compose config --quiet` | Compose v2 subcommand absent on host; **`docker-compose config --quiet` passed** with v1.29.2 |
| `docker compose build --pull` | Equivalent **`docker build --pull --no-cache` passed** separately for app and database; final build logs in `/tmp/accountplus-security-*-final-build.log` |
| `docker compose up -d` | **`docker-compose -p accountplus-security-compose-de02f4 ... up -d --no-build` passed**, using the images built above, isolated fresh named volumes and a temporary secret env file |
| `docker compose ps` | Equivalent v1 output: application Up, localhost published port; database Up (healthy), no public DB binding |
| `docker compose exec -T app php -v` | Equivalent v1 output: **PHP 8.4.27** (Alpine build 2026-10-21) |
| `docker compose exec -T app php -m` | Equivalent v1 includes **ctype, fileinfo, json, PDO, pdo_mysql, session, zip, zlib** (and core/filter/hash/mysqlnd/openssl/etc.) |
| `php app/bin/check-runtime.php` inside image | **Runtime verified: PHP 8.4.27** |
| PHP syntax, all app/test PHP plus index | **Passed**, both host and deployed runtime |
| `php tests/run.php` | **53 checks, 0 failed**, including deployed runtime under the apache user (root is inappropriate for the read-only permission fixture) |
| `python3 tests/Integration/security_stack.py` | **Passed**; 14 SQL integration checks plus Docker HTTP/CLI/proxy/session/setup/archive/restore scenarios |
| Same script with `SECURITY_BROWSER` set | **Passed** including real rendered chart pixels, legends and dashboard settings under application CSP |
| `python3 tests/Integration/upgrade_rehearsal.py` | **Passed**; builds original commit, seeds representative data, copies stopped volumes, compares every table, preserves storage/IP rules, validates logical restore and cold rollback |
| `node --check assets/js/security-ui.js` | **Passed** |
| `python3 tests/security_policy_test.py` | **Passed**; HIGH/CRITICAL/EOL block and clean/MEDIUM follow documented policy |
| `git diff --check` | **Passed**; HEAD remains the remote baseline commit |
| Trivy app/database, severity HIGH/CRITICAL, exit code 1 policy | **Both passed with zero findings**; final gate also rejects unsupported OS metadata |
| Gitleaks working tree and accessible `--all` history | **No leaks found**; the accessible history contains the original single remote commit |
| Generated synthetic GitHub credential | **Detected**, scanner exit code **1**, redacted output |
| Actual intermediate MariaDB image with original gosu | **24 HIGH + 1 CRITICAL**, scanner exit code **1**; the vulnerable executable is absent from the deliverable image |

The old Alpine/PHP image produced **no PHP findings** from Trivy despite its known
vulnerable PHP 8.2.28 version, and was correctly identified as EOSL. This scanner
blind spot is documented, never treated as a waiver: the version guard and EOSL
policy independently reject that baseline. No ignored CVEs or accepted exceptions
were added. The first failing startup, ctype-extension and 419-status tests led to
fixes; no failed checks were disabled.

## Test resources and data preservation

Only newly created test resources were used. Their containers were stopped after
validation. Named test volumes are deliberately retained, notably prefixes
`accountplus-upgrade-f0a202cc` (successful physical rehearsal) and
`accountplus-security-compose-de02f4` (real Compose deployment), along with earlier
rehearsal resources. No volume deletion or production database cleanup was run.
Temporary tmpfs test databases are intentionally ephemeral. No production records
or real credentials are present in fixtures, documents, logs or Git changes.

## Compatibility and deployment actions

Read `upgrade.md` before rollout. Mandatory changes: patched PHP/Alpine; independent
SQL secrets; authorized setup; explicit migration command with deployment credentials;
DML-only runtime account; localhost binding; explicit trusted proxy/HTTPS configuration.
Existing stored config wins over environment, so credential rotation needs a config
update and SQL change. Keep the Compose project name and both volumes. New archive
metadata restrictions and the 200,000-line NDJSON ceiling are documented; the archive
format itself is unchanged. New installed.lock prevents deleting config alone from
reopening setup. Recover partial setup by restoring config, never resetting users.

Recommended order: production-sized backup/restore rehearsal, private maintenance
window, stop jobs/writes, cold copies, build/scan, start DB, explicit db:migrate,
DML config cutover, application smoke tests, remove setup authorization, reopen TLS
ingress. Roll back with the old image AND pre-upgrade data copy, not a database
binary downgrade on the upgraded datadir. Publish the owner's reviewed changes
before validating the first hosted CI run; that remaining step is not claimed done.

## Changed file inventory

See the generated list below; batch order follows the requested runtime -> database
-> setup/errors -> migrations/privileges -> auth/proxy -> storage/isolation -> HTTP
-> archive/CI/docs -> validation sequence. Changes remain uncommitted for owner review.

- `.dockerignore`
- `.env.example`
- `.github/dependabot.yml`
- `.github/workflows/phase1.yml`
- `.gitignore`
- `.htaccess`
- `Dockerfile`
- `README.md`
- `SECURITY.md`
- `app/bin/check-runtime.php`
- `app/bin/console.php`
- `app/config/database.php`
- `app/lang/de.php`
- `app/lang/en.php`
- `app/lang/es.php`
- `app/lang/fr.php`
- `app/lang/it.php`
- `app/lang/nl.php`
- `app/lang/pl.php`
- `app/lang/pt.php`
- `app/src/Application/AppKernel.php`
- `app/src/Controller/AdminController.php`
- `app/src/Controller/AuthController.php`
- `app/src/Controller/CompanyController.php`
- `app/src/Controller/SetupController.php`
- `app/src/Controller/TransferController.php`
- `app/src/Http/Request.php`
- `app/src/Http/Response.php`
- `app/src/Service/AuditLogService.php`
- `app/src/Service/AuthService.php`
- `app/src/Service/ExportService.php`
- `app/src/Service/ImportService.php`
- `app/src/Service/InstallationState.php`
- `app/src/Service/InstallerService.php`
- `app/src/Service/IpRestrictionService.php`
- `app/src/Service/LoginRateLimiter.php`
- `app/src/Support/Archive.php`
- `app/src/Support/Database.php`
- `app/src/Support/Migrator.php`
- `app/src/Support/Secret.php`
- `app/src/Support/SecurityError.php`
- `app/src/Support/SetupAuthorization.php`
- `app/src/Support/helpers.php`
- `app/templates/accounts/index.php`
- `app/templates/admin/transfer.php`
- `app/templates/categories/_form.php`
- `app/templates/categories/index.php`
- `app/templates/companies/index.php`
- `app/templates/dashboard/index.php`
- `app/templates/layout/app.php`
- `app/templates/partials/_breakdown_chart.php`
- `app/templates/partials/_label_chart.php`
- `app/templates/recurring/_form.php`
- `app/templates/recurring/index.php`
- `app/templates/setup/index.php`
- `app/templates/transactions/_table.php`
- `assets/js/security-ui.js`
- `docker-compose.yml`
- `docker/apache-accountplus.conf`
- `docker/entrypoint.sh`
- `docker/mariadb/10-runtime-privileges.sh`
- `docker/mariadb/Dockerfile`
- `docker/mariadb/entrypoint.sh`
- `docker/mariadb/gosu`
- `docker/mariadb/healthcheck.sh`
- `docs/security/baseline.md`
- `docs/security/ci-policy.md`
- `docs/security/remediation-report.md`
- `docs/security/scan-summary.json`
- `docs/security/upgrade.md`
- `index.php`
- `tests/Integration/run.php`
- `tests/Integration/security.php`
- `tests/Integration/security_stack.py`
- `tests/Integration/upgrade_rehearsal.py`
- `tests/Integration/web_check.py`
- `tests/Unit/ArchiveSecurityTest.php`
- `tests/Unit/HttpErrorSecurityTest.php`
- `tests/Unit/RedirectSecurityTest.php`
- `tests/Unit/RequestSecurityTest.php`
- `tests/Unit/SecretSecurityTest.php`
- `tests/Unit/SetupSecurityTest.php`
- `tests/security_policy_test.py`
- `tools/security-check.py`
