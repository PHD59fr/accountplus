# 🚀 **Account+**

**Account+** is a self-hosted, multi-company accounting web application. It lets you manage accounts, transactions, categories and recurring operations per company, while keeping a full audit trail. The project is currently under active development, and contributions are welcome!

🔗 **GitHub Repository**: [https://github.com/phd59fr/accountplus](https://github.com/phd59fr/accountplus)

---

## 📦 **Portable Accounting Archives**

Account+ imports and exports company data through a versioned ZIP format
(`compte-import` / `compte-export`). Archives can move accounting records between
instances or provide a portable company export. Full instance backups also require
the database and persistent application storage.

Imports validate archive structure before applying accounting records in a database
transaction. Structural failures roll back those writes; record-level issues are
reported in the import history. SHA-256 fingerprints detect repeated imports into
the same company.

---

## 🔍 **Features Overview**

### 👤 **Core Features (End Users)**

| Area                     | Description                                                                  |
|--------------------------|------------------------------------------------------------------------------|
| 📊 **Dashboard**         | Global balance, income, expenses, recent transactions, recurring overview and activity. |
| 🏢 **Companies**         | Create, rename, delete and **select the company workspace** for the current session. Each company keeps its own accounts, categories and transactions. |
| 🏦 **Accounts**          | Bank account inventory with balances and activity counts. Currency, initial balance, bank name, account number, description, archive/restore. |
| 🧾 **Transactions**      | Ledger entries per company: date, value date, label, category, amount, reconciliation status, archive/restore, search and **full change history**. |
| 🗂️ **Categories**        | Hierarchical (parent/child) classification with **emoji**, type (income / expense / transfer), sort order and active state. Cycle detection on parent change. |
| 🔁 **Recurring Rules**    | Automatically post recurring income, expense and transfer entries (daily, weekly, monthly or yearly, with intervals, end date and on/off toggle). |
| 📈 **Reports**           | Transaction report with date / account / category / label filters, category breakdown, opening & closing balances, and **CSV export**. |

### 🦸 **Admin Commands (Manage the Application)**

| Feature                    | Description                                                             |
|----------------------------|-------------------------------------------------------------------------|
| 👥 **Users**               | Create users, promote to **admin**, attach users to companies, activate / deactivate accounts. |
| 📥 **Imports**             | Upload a `compte-import` ZIP and target the company that receives its accounts, transactions and categories. Inspect warnings / errors per import. |
| 📤 **Exports**             | Generate a reusable, versioned `compte-export` ZIP for any company and download it. |
| 🕘 **Audit Log**           | Searchable trace of actions, authentication events and data changes, with filters and details. |
| ⚙️ **Settings**            | Global date format, dashboard visibility and IP restrictions: disabled, whitelist or blacklist, with only one active mode. |

### 🛡️ **Restricted Operations**

| Feature             | Description                                                       |
|---------------------|-------------------------------------------------------------------|
| 🔒 **Setup wizard** | Server-authorized first-run configuration with an installation token and CSRF protection. It creates the initial administrator and cannot reset existing users. |
| 🔐 **Login throttling** | Shared limits per account/origin pair and network, with temporary throttling and bcrypt password verification. |

---

## 📦 **Data Transfer (Import / Export)**

### 🧩 **Archive Format**

Both import and export use the same stable, versioned ZIP layout:

| Artifact                    | Role                                         |
|-----------------------------|----------------------------------------------|
| `manifest.json`             | Package metadata and versioning              |
| `accounts.ndjson`           | Normalized account records                   |
| `categories.ndjson`         | Normalized categories and themes             |
| `transaction_types.ndjson`  | Normalized transaction type codes            |
| `transactions.ndjson`       | Normalized transaction records               |
| `report.json`               | Warnings and diagnostics                       |

### 🪜 **Import Pipeline**

1. **Prepare the archive**, a versioned ZIP produced by the export feature of Account+ (or of another instance).
2. **Import the package**. Account+ validates the archive and imports it into **one target company**, transactionally, with per-record warnings/errors recorded in `import_errors`.

```sh
# Import the archive into a company
php app/bin/console.php import:run import.zip --company=1
```

### 🧩 **Import details**

- Archives are fingerprinted by **SHA-256**; duplicates are skipped unless `--force` is passed.
- Accounting writes run inside a **single transaction**. A failed transaction rolls back those writes; the import history records the outcome.
- Statuses: `processing`, `completed`, `completed_with_errors`, `failed`.
- Exports preserve paired transfer links through the optional `transfer_legacy_id` field. An incomplete or inconsistent linked transfer causes the import to roll back.
- The `inspect` page (`/admin/data/inspect?id=...`) reviews warnings and errors recorded per import.

Linked transfers share their date, label and amount across both entries. Archiving
or deleting a transfer affects both entries; category, description and reconciliation
remain specific to each account. An entry retains its debit or credit sign.

### 🗂️ **Export**

Exporting a company writes `<company>-<id>-<timestamp>-<random>.compte.zip` containing the same stable layout, ready for backup, migration or replay:

```sh
# CLI export
php app/bin/console.php export:run --company=1
```

---

## ⚙️ **Console CLI**

The CLI runs via the PHP binary and does not need a web server:

| Command                                      | Description                                                          |
|----------------------------------------------|----------------------------------------------------------------------|
| `php app/bin/console.php help`               | List available commands.                                             |
| `php app/bin/console.php db:migrate` | Apply schema upgrades with dedicated deployment credentials. |
| `php app/bin/console.php app:name`           | Print the application name.                                          |
| `php app/bin/console.php recurring:process [reference-date] [--company=<id>]` | Post all due recurring transactions for a reference date. |
| `php app/bin/console.php import:from-csv <directory-or-zip> --output=<path>` | Convert legacy CSV files into an import archive. |
| `php app/bin/console.php import:inspect <archive.zip>` | Inspect an archive without touching the database.        |
| `php app/bin/console.php import:run <archive.zip> --company=<id> [--force]` | Import an archive into a company.                   |
| `php app/bin/console.php export:run [--company=<id>]` | Export a company to a `compte-export` ZIP.                 |

---

## 🔐 **Security & Reliability**

* **Passwords** hashed with **bcrypt** (`password_hash` / `password_verify`).
* **Sessions** hardened: strict mode, cookie-only, `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`, and `session_regenerate_id` on login/logout.
* **CSRF protection** on every POST request (token + constant-time comparison).
* **Security headers** with per-response script nonces (no script unsafe-inline) on every dynamic response: `Content-Security-Policy`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cache-Control: no-store`.
* **Login throttling**: five failures per account/origin pair, plus thirty per IPv4 address or IPv6 /64 in fifteen minutes; counters are shared across sessions and serialized in SQL.
* **Audit log** records actions, authentication events and data changes with old/new values, IP and username.
* **Transaction history** gives a per-transaction audit trail (`/transactions/history`).
* **Protected internal files**: the root `index.php` handles application requests. Apache's `.htaccess` blocks direct access to application code, configuration, repository metadata, archives and deployment files, and disables directory listings.
* **Transactional imports**: failed accounting writes are rolled back, with diagnostics retained in the import history.

For HTTPS/proxy settings, operational limits and vulnerability reporting, see the
[deployment guide](docs/security/upgrade.md) and [security policy](SECURITY.md).

---

## 🌐 **Internationalization**

Account+ is fully translatable. The interface language is stored per user and switchable at runtime:

| Locale   | Language      |
|----------|---------------|
| 🇬🇧 `en` | English       |
| 🇫🇷 `fr` | French        |
| 🇩🇪 `de` | German        |
| 🇪🇸 `es` | Spanish       |
| 🇮🇹 `it` | Italian       |
| 🇵🇹 `pt` | Portuguese    |
| 🇳🇱 `nl` | Dutch         |
| 🇵🇱 `pl` | Polish        |

Translation files live in `app/lang/` (`en.php`, `fr.php`, ...) and can be extended easily.

---

## 📥 **Installation**

### 🐘 Requirements

Use maintained, patched PHP **8.4.27+ on the 8.4 branch**, with `pdo_mysql`,
`zip`, `fileinfo`, `json`, `session`, `zlib` and `ctype`. Docker builds enforce this
minimum. MariaDB **10.11 LTS** is the tested database series. Docker uses maintained
Alpine **3.24** for Apache/PHP and the official MariaDB 10.11 image.

### 🛠️ Setup steps

For an existing installation, follow the [upgrade guide](docs/security/upgrade.md).

1. Clone this repository and provision a utf8mb4 database, an application user with
   `SELECT, INSERT, UPDATE, DELETE`, and a separate deployment user with the DDL rights
   listed in the upgrade guide. Keep credentials out of shell history.
2. Set the runtime `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (or
   `DB_PASSWORD_FILE`) in the PHP service environment. Run the explicit initialization:

   ```sh
   # Supply DB_MIGRATION_USERNAME and DB_MIGRATION_PASSWORD securely in the environment.
   php app/bin/console.php db:migrate
   ```

3. Configure Apache with `mod_rewrite` and the supplied root `.htaccess`, or use the
   **local development only** PHP router: `php -S 127.0.0.1:8000 index.php`.
4. Set `APP_SETUP_ENABLED=true` and an independently generated `APP_SETUP_TOKEN`
   (at least 32 random characters) in the server environment. Open `/setup` over a
   private or authenticated connection and enter the token in the form. Use the
   **application DML user** in the DB form after the schema has been initialized.
5. Disable setup and remove the token after creating the administrator. Log in and
   create your companies. A lost configuration must be restored, never reinitialized.

HTTP requests and ordinary CLI commands check schema compatibility without DDL.
Existing deployments must run `db:migrate` explicitly during deployment.

### 📦 Deploying to a server (files to copy)

Copy these files to the application directory, then configure the web server document root to that directory:

| Path          | Purpose                                             |
|---------------|-----------------------------------------------------|
| `index.php`   | Front controller (all HTTP requests go through it)  |
| `.htaccess`   | Apache access restrictions and routing to the root `index.php` |
| `assets/`     | CSS and bundled JavaScript assets |
| `app/`        | Application code (`bin`, `config`, `database`, `lang`, `src`, `storage`, `templates`) |
| `app/storage/app/config.php` | Generated by setup; preserve this file when deploying |

Do **not** copy: `tests/`, `.github/`, `README.md`, `.gitignore`.

Alternatively, clean a deployment copy with the script at the project root:

```sh
# Classic PHP/Apache hosting (default mode)
./clean4prod.sh --hosting --dry-run --target /path/to/deployment
./clean4prod.sh --hosting --target /path/to/deployment

# Docker deployment
./clean4prod.sh --docker --dry-run --target /path/to/deployment
./clean4prod.sh --docker --target /path/to/deployment
```

Without `--target`, the script cleans its own directory. It removes tests, project
documentation (including `SECURITY.md`), CI tooling in `tools/`, Git and editor
metadata, including editor swap files at the project root. `--hosting` is the default:
it also removes `Dockerfile`, `docker-compose.yml`,
`.dockerignore`, `docker/`, `.env.example` and the Docker-only
`app/bin/prepare-apache.php` helper.
Use `--docker` to keep those files, including the environment template needed to
configure Compose. The two modes cannot be combined. Application code, migrations,
CLI commands (including the runtime checker), third-party licenses, configuration,
actual `.env` files, `secrets/` and all of `app/storage/` are preserved in both modes.
`--dry-run` previews the cleanup without deleting anything. Run cleanup on the deployment copy.

Make sure the web server user can **write** to `app/storage/`, including `app/`, `imports/`, `exports/` and `logs/`. Application source files do not need to be writable.

To edit IP restrictions from **Administration → Settings**, the web server also needs
write access to `.htaccess` and its containing directory for atomic replacement.
You can keep the project directory read-only by making `.htaccess` a symlink to
`app/storage/app/apache.htaccess` and granting write access only to that storage directory.
Copy the root `.htaccess` into that file before creating the symlink. Keep the target
readable by Apache and allow symbolic links in the server configuration.
Docker configures this automatically and persists the rules in the `app_storage` volume.

```sh
# Example: copy to the web server
scp -r index.php .htaccess app assets user@server:/var/www/accountplus/
```

### 🐳 Docker (standalone example)

Copy `.env.example` to `.env`, protect it with mode 0600 and fill in independent
random `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `DB_MIGRATION_PASSWORD` and initial
`APP_SETUP_TOKEN` values. Set `APP_SETUP_ENABLED=true` during first setup only.
There are **no default passwords**. Compose binds HTTP to `127.0.0.1:8080` by default.

```sh
docker compose config --quiet
docker compose build --pull --no-cache
docker compose up -d db
# Pass deployment credentials only to this short-lived process, not the web service:
docker compose run --rm -e DB_MIGRATION_USERNAME -e DB_MIGRATION_PASSWORD app php app/bin/console.php db:migrate
docker compose up -d app
```

The two migration variables in the command above must be exported securely in the
shell environment; Compose's `.env` interpolation does not itself export them.
Then use `/setup`, host `db`, database/user from `.env`, and the runtime DB password.
Do not enter the migration password in the setup DB form. Disable setup and recreate
only the application container after installation. Use `docker-compose` if only v1
is installed. The `db_data` volume stores MariaDB data; `app_storage` stores configuration,
archives and IP rules. Preserve both volumes and the Compose project name.

Mounted secret files, credential rotation, HTTPS/proxies, backup/restore, existing
account privilege changes and rollback are covered in
[deployment and recovery](docs/security/upgrade.md). The app runs with a read-only
root filesystem and Apache/PHP under the unprivileged `apache` user. Only persistent
storage and temporary runtime paths are writable.

---

## ⚙️ **Configuration**

The setup wizard writes `app/storage/app/config.php`, which supplies the application
name and database connection. A root-level `config.php` takes precedence when present.
Without either file, defaults in `app/config/` read the server environment. PHP does
not load `.env` files; Docker Compose uses `.env` for container configuration.

| Setting | Purpose |
|---------|---------|
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database connection before a configuration file exists. |
| `DB_MIGRATION_USERNAME`, `DB_MIGRATION_PASSWORD` | Separate credentials for `db:migrate`. |
| `APP_SETUP_ENABLED`, `APP_SETUP_TOKEN` | Server authorization for the initial setup. |
| `APP_URL` | Base application URL in the default configuration. |
| `APP_TRUSTED_PROXIES` | Explicit proxy addresses or CIDRs trusted for client IP and HTTPS headers. |
| `APP_SESSION_SECURE` | Force Secure session cookies for HTTPS-only deployments. |
| `APP_HSTS_MAX_AGE` | Optional HSTS duration, emitted only for detected HTTPS. |
| `IMPORT_MAX_SIZE` | Maximum uploaded archive size in bytes. |

Passwords and setup tokens also support mounted `*_FILE` secrets. A secret and its
corresponding `*_FILE` must not both be set. See the [deployment guide](docs/security/upgrade.md)
for file access requirements, local diagnostics, TLS/proxies, backups, credential
rotation and upgrades.

Changing a Compose secret does not update stored configuration or an existing SQL
account. Preserve the configuration and storage when deploying application files.
Run `db:migrate` explicitly with deployment credentials before reopening traffic.

### `config.php` example (generated by `/setup`)

```php
<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Account+',
        'env' => 'production',
        'debug' => false,
        'url' => 'http://127.0.0.1:8000',
        'session_name' => 'php_compte',
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'compte',
        'username' => 'compte',
        'password' => '<runtime-database-password>',
        'charset' => 'utf8mb4',
    ],
];
```

> **Note:** To change the database connection or application name, edit the existing configuration file directly. Setup cannot reset a database that already contains users. If the file is lost, restore it from backup.

### Dashboard display

In **Administration → Settings → Dashboard elements**, administrators can show or
hide each of these elements independently:

- Global balance.
- Monthly income.
- Monthly expenses.
- Upcoming recurring operations.
- Accounts.
- Recent transactions.
- Category breakdown.
- Type breakdown.
- Label breakdown.

All elements are visible by default. Settings apply to all users and companies;
report charts have their own display. Hiding every dashboard element shows an empty
state.

The label chart groups trimmed transaction labels, displays the ten largest groups
and combines the rest under **Other labels**. It uses absolute amounts from active
transactions and accounts. Blank labels appear as **Unlabelled**. Legends show
percentages; tooltips show full labels and amounts.

### IP restrictions

Administrators can configure two independent lists in **Administration → Settings**.
Select **Disabled**, **Whitelist** (allow only listed addresses), or **Blacklist**
(block listed addresses). Only the selected list is enforced; both lists remain saved.
Each list accepts up to 256 IPv4 addresses, IPv6 addresses or CIDR networks, one per line.
An active list cannot be empty, and a change that would block the administrator's current
IP is rejected. Changes are recorded in the audit log.

Rules are written directly into the root `.htaccess`, between
`# BEGIN ACCOUNTPLUS IP RESTRICTIONS` and `# END ACCOUNTPLUS IP RESTRICTIONS`.
All other rules are preserved. Apache 2.4 with `mod_authz_core`, `mod_authz_host` and
`AllowOverride AuthConfig` (included in `AllowOverride All`) enforces the rules immediately
for the entire site, including static assets. The application also checks these rules
on dynamic requests, including when using the PHP development router. Apache's static
file protections still require Apache configuration.

Client IPs come from `REMOTE_ADDR` unless the peer belongs to `APP_TRUSTED_PROXIES`.
Only trusted peers can supply forwarded client IP and HTTPS information. Behind a
reverse proxy, configure Apache's `mod_remoteip` consistently with the application;
see [proxy configuration](docs/security/upgrade.md#proxy-https-and-sessions).

For emergency recovery, remove the complete marked block from `.htaccess` (or its
symlink target); this disables IP restrictions and clears both lists. Back up the file
before deployment. In Docker, only the managed block is carried over from the persistent
file when rebuilding `.htaccess` from the current image's routing and security rules.

---

## 🗂️ **Project Structure**

```
app/
├── bin/console.php            # CLI entry point
├── config/                    # app.php, database.php
├── database/migrations/       # versioned schema migrations
├── lang/                      # translation files (en, fr, de, es, it, nl, pl, pt)
├── src/
│   ├── Application/           # kernel wiring all routes and services
│   ├── Controller/            # HTTP controllers
│   ├── Domain/                # domain models (Company, User)
│   ├── Http/                  # Request, Response, Router
│   ├── Repository/            # data access
│   ├── Service/               # business logic (Auth, Import, Export, Recurring, ...)
│   └── Support/               # Config, Database, View, Migrator, Translator, Archive
├── storage/                   # imports, exports, analysis, logs (git-ignored)
├── templates/                 # views (accounts, admin, auth, categories, ...)
index.php                      # web entry point at the project root
.htaccess                      # Apache access restrictions and routing
assets/                        # CSS and bundled JavaScript assets
tests/                         # lightweight PHP unit tests
```

The architecture is **dependency-light and framework-free**: a simple `Autoloader`, a plain `Router`, PDO-based repositories and services that stay easy to test. There is no external PHP dependency. `composer` is not required.

Charts use the bundled Chart.js 4.4.1 distribution in `assets/vendor/chart.js/`,
including its MIT license. It is served locally so the application works without a CDN
and uses a Content Security Policy restricted to local scripts and response nonces.

---

## 🧪 **Testing**

The PHP unit suite uses a lightweight runner and needs no database:

```sh
php tests/run.php
find app tests -name '*.php' -print0 | xargs -0 -n1 php -l
php -l index.php
```

Docker integration checks cover setup, authentication, company isolation, CLI jobs,
archives and backup/restore using isolated test databases:

```sh
docker build -t accountplus-security-app .
docker build -t accountplus-security-db docker/mariadb
python3 tests/Integration/security_stack.py
```

The GitHub workflow runs tests and scans image vulnerabilities and accessible Git
history for secrets. HIGH/CRITICAL findings and unsupported operating systems block
the workflow. See [security checks](docs/security/ci-policy.md) for manual database
tests, browser checks, migration rehearsals and scanner policy.

---

## 🤝 **Contributing**

Contributions are what make the open-source community such an amazing place to learn, inspire, and create. Any contributions you make are **greatly appreciated**.
Here’s how you can help:

* Report bugs
* Suggest features
* Submit pull requests

To contribute:

1. Fork the project
2. Create a new branch (`git checkout -b feature/YourFeature`)
3. Commit your changes (`git commit -m 'Add YourFeature'`)
4. Push to the branch (`git push origin feature/YourFeature`)
5. Open a pull request

---

## 📄 **License**

This project is licensed under the [MIT License](https://opensource.org/licenses/MIT).

---

## ❤️ Support

A simple star on this project repo is enough to keep me motivated for days. If you’re excited about this project, let me know with a tweet.
If you have any questions, feel free to reach out to me on [X](https://x.com/xxPHDxx).
