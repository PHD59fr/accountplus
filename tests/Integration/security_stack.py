"""Run an isolated Docker security rehearsal. Never attaches existing volumes.

Build accountplus-security-app and accountplus-security-db first. Test data uses
container tmpfs; only the containers created by this invocation are stopped.
"""
import concurrent.futures
import http.cookiejar
import io
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[2]
PREFIX = 'accountplus-security-' + secrets.token_hex(4)
APP_IMAGE = os.environ.get('SECURITY_APP_IMAGE', 'accountplus-security-app')
DB_IMAGE = os.environ.get('SECURITY_DB_IMAGE', 'accountplus-security-db')
PASSWORD = secrets.token_hex(24) + " 'dollar$ back\\slash\""
ROOT_PASSWORD = secrets.token_hex(32)
MIGRATION_PASSWORD = secrets.token_hex(24) + " 'migration$\\\""
TOKEN = secrets.token_hex(32)
ADMIN_PASSWORD = secrets.token_hex(24)
SENSITIVE = [PASSWORD, ROOT_PASSWORD, MIGRATION_PASSWORD, TOKEN, ADMIN_PASSWORD]
CREATED = []


def safe(value):
    for secret in SENSITIVE:
        value = value.replace(secret, '[redacted]')
    return value


def run(*args, data=None, check=True):
    result = subprocess.run(args, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if check and result.returncode:
        raise RuntimeError(safe(result.stderr.decode(errors='replace') + result.stdout.decode(errors='replace')))
    return result


def docker(*args, **kwargs):
    return run('docker', *args, **kwargs)


def envfile(directory, name, values):
    path = Path(directory) / name
    path.write_text(''.join(f'{key}={value}\n' for key, value in values.items()))
    path.chmod(0o600)
    return str(path)


def sql(statement, database='compte'):
    return docker('exec', '-i', PREFIX + '-db', 'bash', '-c',
                  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mariadb -uroot --batch --skip-column-names "$1"',
                  'sql', database, data=statement.encode()).stdout


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, port):
        self.url = f'http://127.0.0.1:{port}'
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
            urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())

    def request(self, path, values=None, headers=None, raw=None):
        data = urllib.parse.urlencode(values).encode() if values is not None else raw
        request = urllib.request.Request(self.url + path, data=data, headers=headers or {})
        try:
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read()
        for marker in [b'SECRET_PASSWORD_TEST', b'PRIVATE_DB_HOST_TEST', b'INTERNAL_SQL_QUERY_TEST', TOKEN.encode(), PASSWORD.encode()]:
            assert marker not in body, 'Sensitive data in HTTP response'
        return response.code, response.headers, body

    def csrf(self, path):
        code, headers, body = self.request(path)
        assert code == 200, (path, code, safe(body.decode(errors='replace')[:300]))
        return re.search(rb'name="_csrf_token" value="([^"]+)"', body).group(1).decode()


def php(source):
    return docker('exec', '-i', '--user', 'apache', PREFIX + '-app', 'php', data=('<?php ' + source).encode()).stdout


def check(name):
    print('ok   ' + name, flush=True)


with tempfile.TemporaryDirectory(prefix=PREFIX + '-') as directory:
    db_env = envfile(directory, 'db.env', {
        'MYSQL_DATABASE': 'compte', 'MYSQL_USERNAME': 'compte', 'MYSQL_PASSWORD': PASSWORD,
        'MYSQL_ROOT_PASSWORD': ROOT_PASSWORD, 'DB_MIGRATION_USERNAME': 'compte_migration',
        'DB_MIGRATION_PASSWORD': MIGRATION_PASSWORD,
    })
    app_values = {'DB_HOST': 'db', 'DB_PORT': '3306', 'DB_DATABASE': 'compte', 'DB_USERNAME': 'compte',
                  'DB_PASSWORD': PASSWORD, 'APP_SETUP_ENABLED': 'true', 'APP_SETUP_TOKEN': TOKEN,
                  'APP_ENV': 'production', 'APP_DEBUG': 'true'}
    secret_dir = Path(directory) / 'secrets'
    secret_dir.mkdir(mode=0o700)
    (secret_dir / 'mysql-password').write_text(PASSWORD)
    (secret_dir / 'mysql-password').chmod(0o600)
    app_env = envfile(directory, 'app.env', {key: value for key, value in app_values.items() if key != 'DB_PASSWORD'} | {'DB_PASSWORD_FILE': '/run/secrets/mysql-password'})
    cli_env = envfile(directory, 'cli.env', {**app_values, 'DB_MIGRATION_USERNAME': 'compte_migration',
                                           'DB_MIGRATION_PASSWORD': MIGRATION_PASSWORD})
    try:
        missing = docker('run', '--rm', DB_IMAGE, check=False)
        assert missing.returncode != 0 and b'is required' in missing.stderr
        missing = docker('run', '--rm', APP_IMAGE, check=False)
        assert missing.returncode != 0 and b'is required' in missing.stderr
        invalid_env = envfile(directory, 'invalid.env', {'MYSQL_PASSWORD': PASSWORD, 'MYSQL_ROOT_PASSWORD': ROOT_PASSWORD,
            'DB_MIGRATION_PASSWORD': MIGRATION_PASSWORD, 'MYSQL_DATABASE': 'bad`name'})
        invalid = docker('run', '--rm', '--env-file', invalid_env, DB_IMAGE, check=False)
        assert invalid.returncode != 0 and b'Invalid database' in invalid.stderr
        check('missing secrets and invalid SQL identifiers fail without revealing secrets')
        docker('network', 'create', PREFIX)
        docker('run', '-d', '--name', PREFIX + '-db', '--network', PREFIX, '--network-alias', 'db',
               '--tmpfs', '/var/lib/mysql', '--security-opt', 'no-new-privileges:true', '--env-file', db_env, DB_IMAGE)
        CREATED.append(PREFIX + '-db')
        for attempt in range(90):
            result = docker('exec', PREFIX + '-db', 'accountplus-db-healthcheck', check=False)
            if result.returncode == 0:
                break
            state = docker('inspect', '--format', '{{.State.Running}}', PREFIX + '-db').stdout.strip()
            if state != b'true':
                logs = docker('logs', PREFIX + '-db')
                raise RuntimeError(safe((logs.stdout + logs.stderr).decode(errors='replace')))
            time.sleep(1)
        else:
            raise RuntimeError('Test database did not become healthy')
        check('official MariaDB initializes with quotes backslashes dollar signs and spaces in passwords')
        sql('CREATE DATABASE accountplus_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; '
            "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER ON accountplus_test.* TO 'compte_migration'@'%';")
        test_env = envfile(directory, 'tests.env', {'TEST_DB_HOST': 'db', 'TEST_DB_DATABASE': 'accountplus_test',
            'TEST_DB_USERNAME': 'compte_migration', 'TEST_DB_PASSWORD': MIGRATION_PASSWORD})
        result = docker('run', '--rm', '--network', PREFIX, '--env-file', test_env, '--entrypoint', 'php',
               '-v', f'{ROOT}:/workspace:ro', '-w', '/workspace', APP_IMAGE, 'tests/Integration/run.php')
        print(result.stdout.decode(), end='', flush=True)
        command = ['docker', 'run', '--rm', '--network', PREFIX, '--env-file', cli_env,
                   '--entrypoint', 'php', APP_IMAGE, 'app/bin/console.php', 'db:migrate']
        with concurrent.futures.ThreadPoolExecutor() as pool:
            outcomes = list(pool.map(lambda _: run(*command), range(2)))
        assert all(outcome.returncode == 0 for outcome in outcomes)
        assert sql('SELECT COUNT(*) FROM migrations;').strip() == b'8'
        check('concurrent explicit migrations apply schema once')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            dev_port = sock.getsockname()[1]
        browser_mount = []
        browser = os.environ.get('SECURITY_BROWSER')
        if browser:
            fixture = Path(directory) / 'chart-check.html'
            fixture.write_text((ROOT / 'tests/Integration/charts.html').read_text().replace('integration-password', ADMIN_PASSWORD).replace('A integration', 'Company A'))
            fixture.chmod(0o644)
            browser_mount = ['-v', str(fixture) + ':/var/www/html/chart-check.html:ro']
        docker('run', '-d', '--name', PREFIX + '-app', '--network', PREFIX, '--env-file', app_env,
            '-v', f'{secret_dir}:/run/secrets:ro', '--read-only', '--tmpfs', '/var/www/html/app/storage', '--tmpfs', '/run/apache2',
            '--tmpfs', '/var/log/apache2', '--tmpfs', '/tmp', '--security-opt', 'no-new-privileges:true',
            '--cap-drop', 'ALL', '--cap-add', 'CHOWN', '--cap-add', 'DAC_OVERRIDE', '--cap-add', 'SETUID',
            '--cap-add', 'SETGID', '--cap-add', 'NET_BIND_SERVICE', '-p', f'127.0.0.1:{port}:80', '-p', f'127.0.0.1:{dev_port}:8000', *browser_mount, APP_IMAGE)
        CREATED.append(PREFIX + '-app')
        client = Client(port)
        for attempt in range(30):
            try:
                if client.request('/setup')[0] == 200:
                    break
            except OSError:
                pass
            time.sleep(1)
        else:
            logs = docker('logs', PREFIX + '-app')
            raise RuntimeError(safe((logs.stdout + logs.stderr).decode(errors='replace')))
        csrf = client.csrf('/setup')
        payload = {'_csrf_token': csrf, 'setup_token': TOKEN, 'app_name': 'Security rehearsal',
            'admin_username': 'admin', 'admin_password': ADMIN_PASSWORD, 'admin_password_confirm': ADMIN_PASSWORD,
            'db_host': 'db', 'db_port': '3306', 'db_name': 'compte', 'db_username': 'compte', 'db_password': PASSWORD}
        assert client.request('/setup', {**payload, 'setup_token': 'invalid'})[0] == 403
        response = client.request('/setup', {**payload, '_csrf_token': 'invalid'})
        assert response[0] == 419, (response[0], response[2].decode()[:300])
        assert client.request('/setup?setup_token=' + TOKEN, {key: value for key, value in payload.items() if key != 'setup_token'})[0] == 403
        code, headers, body = client.request('/setup', payload)
        assert code == 302 and headers['Location'] == '/login', (code, safe(body.decode()[:500]))
        assert sql('SELECT COUNT(*) FROM users;').strip() == b'1'
        check('setup requires a POST-only server token plus independent CSRF and succeeds with the DML account')
        csrf = client.csrf('/login')
        old_session = next(cookie.value for cookie in client.jar if cookie.name == 'php_compte')
        assert client.request('/login', {'username': 'admin', 'password': ADMIN_PASSWORD})[0] == 419
        assert client.request('/login', {'_csrf_token': csrf, 'username': 'admin', 'password': 'invalid'})[0] == 422
        code, headers, body = client.request('/login', {'_csrf_token': csrf, 'username': 'admin', 'password': ADMIN_PASSWORD})
        assert code == 302
        new_session = next(cookie.value for cookie in client.jar if cookie.name == 'php_compte')
        assert old_session != new_session
        assert 'HttpOnly' in headers['Set-Cookie'] and 'SameSite=Lax' in headers['Set-Cookie']
        for path in ['/', '/accounts', '/reports/transactions', '/admin/data', '/admin/settings']:
            code, headers, body = client.request(path)
            assert code == 200, (path, code)
            script_policy = re.search(r'script-src ([^;]+)', headers['Content-Security-Policy']).group(1)
            assert "'unsafe-inline'" not in script_policy and "'nonce-" in script_policy
            assert headers['X-Content-Type-Options'] == 'nosniff'
        assert client.request('/setup', payload)[0] == 302
        assert sql('SELECT COUNT(*) FROM users;').strip() == b'1'
        check('login CSRF session regeneration headers and installed setup protection work')
        csrf = client.csrf('/companies')
        assert client.request('/companies', {'_csrf_token': csrf, 'name': 'Company A', 'source_label': 'SECURITY'})[0] == 302
        company = sql("SELECT id FROM companies WHERE name='Company A';").strip().decode()
        assert client.request('/companies/select', {'_csrf_token': csrf, 'company_id': company, 'redirect_to': '/\\evil.example'})[1]['Location'] == '/companies'
        assert client.request('/accounts', {'_csrf_token': csrf, 'name': 'Private account', 'initial_balance': '12.00'})[0] == 302
        account = sql('SELECT id FROM accounts ORDER BY id DESC LIMIT 1;').strip().decode()
        code, _, body = client.request('/transactions', {'_csrf_token': csrf, 'account_id': account,
            'transaction_date': '2026-10-26', 'label': '<script>alert(1)</script>', 'amount': '25.50'})
        assert code == 302, (code, safe(body.decode()[:200]))
        assert client.request('/admin/data/export', {'_csrf_token': csrf, 'company_id': company})[0] == 302
        exports = php('echo json_encode(array_map("basename", glob("/var/www/html/app/storage/exports/*.compte.zip")));')
        filename = json.loads(exports)[0]
        code, _, archive = client.request('/admin/data/download?file=' + filename)
        assert code == 200 and zipfile.ZipFile(io.BytesIO(archive)).testzip() is None
        boundary = '----accountplus' + secrets.token_hex(8)
        fields = {'_csrf_token': csrf, 'company_id': company}
        upload = b''
        for key, value in fields.items():
            upload += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
        upload += f'--{boundary}\r\nContent-Disposition: form-data; name="import_file"; filename="roundtrip.zip"\r\nContent-Type: application/zip\r\n\r\n'.encode() + archive + f'\r\n--{boundary}--\r\n'.encode()
        assert client.request('/admin/data/import', headers={'Content-Type': 'multipart/form-data; boundary=' + boundary}, raw=upload)[0] == 302
        assert sql('SELECT status FROM imports ORDER BY id DESC LIMIT 1;').strip() == b'completed'
        assert php('echo count(glob("/var/www/html/app/storage/imports/upload-*"));').strip() == b'0'
        assert client.request('/accounts?account=' + account)[2].find(b'<script>alert(1)</script>') == -1
        check('HTTP company account transaction ZIP upload download and escaped text work')
        if browser:
            assert client.request('/categories', {'_csrf_token': csrf, 'name': 'Chart category', 'category_type': 'income', 'is_active': '1', 'sort_order': '0'})[0] == 302
            category = sql("SELECT id FROM categories WHERE name='Chart category';").strip().decode()
            assert category.isdigit()
            sql('UPDATE transactions SET category_id=' + category + ' WHERE company_id=' + company + ';')
            rendered = run(browser, '--headless', '--no-sandbox', '--disable-dev-shm-usage',
                '--user-data-dir=' + directory + '/browser', '--virtual-time-budget=15000', '--dump-dom',
                f'http://127.0.0.1:{port}/chart-check.html')
            assert '<pre id="test-result">PASS ' in rendered.stdout.decode(), safe(rendered.stdout.decode()[-500:])
            check('real browser renders all charts and dashboard settings under the application nonce CSP')

        php('foreach (["imports", "exports", "logs"] as $dir) file_put_contents("/var/www/html/app/storage/$dir/probe.compte.zip", "PRIVATE_DB_HOST_TEST");')
        anonymous = Client(port)
        for path in ['/app/storage/app/config.php', '/app/storage/exports/probe.compte.zip', '/app/storage/imports/probe.compte.zip',
            '/app/storage/logs/probe.compte.zip', '/app/database/migrations/', '/app/src/Application/AppKernel.php',
            '/.git/config', '/%2egit/config', '/docker-compose.yml', '/app%2fstorage/exports/probe.compte.zip', '/%61pp/storage/exports/probe.compte.zip']:
            assert anonymous.request(path)[0] in (403, 404), path
        assert anonymous.request('/assets/css/app.css')[0] == 200
        assert anonymous.request('/admin/data/download?file=' + filename)[0] == 302
        check('real private files and encoded paths are denied while public assets work')
        assert anonymous.request('/login', headers={'X-Forwarded-Proto': 'https'})[1].get('Strict-Transport-Security') is None
        network = json.loads(docker('network', 'inspect', PREFIX).stdout)[0]['IPAM']['Config'][0]['Subnet']
        docker('exec', '-d', '--user', 'apache', '-e', 'APP_TRUSTED_PROXIES=' + network,
            '-e', 'APP_HSTS_MAX_AGE=600', PREFIX + '-app', 'php', '-S', '0.0.0.0:8000', 'index.php')
        development = Client(dev_port)
        for attempt in range(20):
            try:
                if development.request('/login')[0] == 200:
                    break
            except OSError:
                pass
            time.sleep(0.2)
        for path in ['/app/storage/exports/probe.compte.zip', '/app/storage/imports/probe.compte.zip',
                     '/app/src/Application/AppKernel.php', '/%61pp/storage/app/config.php', '/docker-compose.yml']:
            assert development.request(path)[0] in (403, 404), path
        assert development.request('/assets/css/app.css')[0] == 200
        secure = Client(dev_port)
        code, headers, _ = secure.request('/login', headers={'X-Forwarded-Proto': 'https', 'X-Forwarded-For': '198.51.100.12'})
        assert code == 200 and 'secure' in headers['Set-Cookie'].lower() and 'HttpOnly' in headers['Set-Cookie']
        assert headers['Strict-Transport-Security'] == 'max-age=600'
        assert development.request('/login')[1].get('Strict-Transport-Security') is None
        check('development router protects private files and trusted HTTPS preserves Secure cookies and HSTS')
        php('require "app/src/Support/Autoloader.php"; App\\Support\\Autoloader::register(__DIR__."/app"); (new App\\Service\\IpRestrictionService(__DIR__))->update("whitelist", "198.51.100.12", "", "198.51.100.12");')
        assert Client(dev_port).request('/login', headers={'X-Forwarded-For': '198.51.100.12'})[0] == 200
        assert Client(dev_port).request('/login', headers={'X-Forwarded-For': '198.51.100.99'})[0] == 403
        assert Client(port).request('/login', headers={'X-Forwarded-For': '198.51.100.12'})[0] == 403
        php('require "app/src/Support/Autoloader.php"; App\\Support\\Autoloader::register(__DIR__."/app"); (new App\\Service\\IpRestrictionService(__DIR__))->update("disabled", "198.51.100.12", "", "198.51.100.12");')
        check('IP restrictions enforce trusted origins and forged untrusted headers cannot bypass them')

        assert php('require "app/src/Support/Autoloader.php"; App\\Support\\Autoloader::register(__DIR__."/app"); $c=App\\Support\\Config::fromBasePath(__DIR__); $p=App\\Support\\Database::connect($c->get("database")); try {$p->exec("CREATE TABLE forbidden (id INT)"); echo "UNSAFE";} catch(PDOException $e) {echo $e->getCode();}') == b'42000'
        for command in [['help'], ['recurring:process'], ['export:run', '--company=' + company]]:
            docker('exec', PREFIX + '-app', 'php', 'app/bin/console.php', *command)
        docker('exec', PREFIX + '-app', 'php', 'app/bin/console.php', 'import:inspect', '/var/www/html/app/storage/exports/' + filename)
        docker('exec', PREFIX + '-app', 'php', 'app/bin/console.php', 'import:run', '/var/www/html/app/storage/exports/' + filename, '--company=' + company)
        assert sql('SELECT COUNT(*) FROM migrations;').strip() == b'8'
        check('runtime DDL is refused and existing CLI commands work without implicit migrations')
        snapshot = sql('SELECT COUNT(*), SUM(amount) FROM transactions;')
        dump = docker('exec', PREFIX + '-db', 'bash', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mariadb-dump -uroot --single-transaction --routines --events --triggers --hex-blob compte').stdout
        sql('CREATE DATABASE accountplus_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
        sql(dump.decode(), 'accountplus_restore')
        assert sql('SELECT COUNT(*), SUM(amount) FROM transactions;', 'accountplus_restore') == snapshot
        assert sql('SELECT COUNT(*) FROM users;', 'accountplus_restore') == sql('SELECT COUNT(*) FROM users;')
        check('logical backup restores users transactions and financial totals to an isolated database')
        sql("INSERT INTO migrations (filename, applied_at) VALUES ('999_missing.php', NOW());")
        assert anonymous.request('/login')[0] == 503
        sql("DELETE FROM migrations WHERE filename='999_missing.php';")
        php('copy("app/storage/app/config.php", "app/storage/app/config.safe"); $c=require "app/storage/app/config.php"; $c["database"]["host"]="PRIVATE_DB_HOST_TEST"; $c["app"]["debug"]=true; file_put_contents("app/storage/app/config.php", "<?php return " . var_export($c,true) . ";");')
        assert anonymous.request('/login')[0] == 503
        php('rename("app/storage/app/config.safe", "app/storage/app/config.php");')
        php("""copy("app/storage/app/config.php", "app/storage/app/config.safe"); file_put_contents("app/storage/app/config.php", '<?php throw new RuntimeException("SECRET_PASSWORD_TEST PRIVATE_DB_HOST_TEST INTERNAL_SQL_QUERY_TEST");');""")
        assert anonymous.request('/login')[0] == 500
        php('rename("app/storage/app/config.safe", "app/storage/app/config.php");')
        check('schema mismatch and production exceptions return generic 503 and 500 responses')
        attacker = Client(port)
        attack_csrf = attacker.csrf('/login')
        for _ in range(5):
            assert attacker.request('/login', {'_csrf_token': attack_csrf, 'username': 'unknown-security-user', 'password': 'wrong'})[0] == 422
        code, headers, _ = attacker.request('/login', {'_csrf_token': attack_csrf, 'username': 'unknown-security-user', 'password': 'wrong'})
        assert code == 429 and int(headers['Retry-After']) > 0
        check('HTTP throttling returns 429 with Retry-After for unknown accounts')
        assert client.request('/logout', {'_csrf_token': csrf})[0] == 302
        stale = Client(port)
        assert stale.request('/accounts', headers={'Cookie': 'php_compte=' + new_session})[0] == 302
        php('rename("app/storage/app/config.php", "app/storage/app/config.backup");')
        assert anonymous.request('/setup')[0] == 503
        assert anonymous.request('/setup', payload)[0] == 503
        php('rename("app/storage/app/config.backup", "app/storage/app/config.php");')
        check('logout invalidates old session and deleting only config never reopens setup')
        logs = docker('logs', PREFIX + '-app').stdout + docker('logs', PREFIX + '-app').stderr
        logs += docker('logs', PREFIX + '-db').stdout + docker('logs', PREFIX + '-db').stderr
        assert not any(secret.encode() in logs for secret in SENSITIVE), 'Secret in container logs'
        check('container logs contain no deployment secrets')
        print('All isolated Docker security checks passed.', flush=True)
    finally:
        for name in reversed(CREATED):
            docker('stop', name, check=False)
        # No volume removal, application database reset or production resource cleanup.
