"""Rehearse the audited 10.11/Alpine -> official 10.11 upgrade and cold rollback.

Only new uniquely named test volumes are attached. They are deliberately retained;
no database or volume is removed. A private temporary logical dump is also verified.
"""
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
BASELINE = '83f7d77add126563025a22db3d46658be904b877'
PREFIX = 'accountplus-upgrade-' + secrets.token_hex(4)
PASSWORD = secrets.token_hex(24)
ROOT_PASSWORD = secrets.token_hex(24)
MIGRATION_PASSWORD = secrets.token_hex(24)
CREATED = []


def run(*args, data=None, check=True):
    result = subprocess.run(args, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if check and result.returncode:
        message = (result.stdout + result.stderr).decode(errors='replace')
        for value in [PASSWORD, ROOT_PASSWORD, MIGRATION_PASSWORD]:
            message = message.replace(value, '[redacted]')
        raise RuntimeError(message)
    return result


def docker(*args, **kwargs):
    return run('docker', *args, **kwargs)


def write_env(directory, name, values):
    path = Path(directory) / name
    path.write_text(''.join(f'{key}={value}\n' for key, value in values.items()))
    path.chmod(0o600)
    return str(path)


def sql(name, statement):
    return docker('exec', '-i', name, 'sh', '-c',
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mariadb -uroot --batch --skip-column-names accountplus_test',
        data=statement.encode()).stdout


def wait_db(name):
    for attempt in range(90):
        if docker('exec', name, 'sh', '-c', 'MYSQL_PWD="$MYSQL_PASSWORD" mariadb --protocol=tcp -h127.0.0.1 -ucompte -Nse "SELECT 1"', check=False).returncode == 0:
            return
        if docker('inspect', '--format', '{{.State.Running}}', name).stdout.strip() != b'true':
            raise RuntimeError('Database test container exited during upgrade')
        time.sleep(1)
    raise RuntimeError('Database test container failed readiness')


def snapshot(name):
    # Row hashes stay inside the test process; no financial records are printed.
    tables = sql(name, 'SHOW TABLES;').decode().splitlines()
    result = {}
    for table in tables:
        assert table.replace('_', '').isalnum()
        rows = sql(name, 'SELECT * FROM `' + table + '`;').splitlines()
        result[table] = (len(rows), hashlib.sha256(b'\n'.join(sorted(rows))).hexdigest())
    return result


def copy_volume(source, destination):
    docker('volume', 'create', destination)
    docker('run', '--rm', '-v', source + ':/source:ro', '-v', destination + ':/target', 'alpine:3.24',
        'sh', '-c', 'test ! -e /target/mysql && cp -a /source/. /target/')


def check(name):
    print('ok   ' + name, flush=True)


with tempfile.TemporaryDirectory(prefix=PREFIX + '-') as directory:
    original = Path(directory) / 'original'
    original.mkdir()
    archive = run('git', '-C', str(ROOT), 'archive', BASELINE).stdout
    run('tar', '-xf', '-', '-C', str(original), data=archive)
    old_app = 'accountplus-security-legacy-app'
    old_db = 'accountplus-security-legacy-db'
    docker('build', '-t', old_app, str(original))
    docker('build', '-t', old_db, str(original / 'docker/mariadb'))
    db_env = write_env(directory, 'db.env', {'MYSQL_DATABASE': 'accountplus_test', 'MYSQL_USERNAME': 'compte',
        'MYSQL_PASSWORD': PASSWORD, 'MYSQL_ROOT_PASSWORD': ROOT_PASSWORD,
        'DB_MIGRATION_USERNAME': 'compte_migration', 'DB_MIGRATION_PASSWORD': MIGRATION_PASSWORD})
    tests_env = write_env(directory, 'tests.env', {'TEST_DB_HOST': 'db', 'TEST_DB_DATABASE': 'accountplus_test',
        'TEST_DB_USERNAME': 'compte', 'TEST_DB_PASSWORD': PASSWORD})
    old_volume = PREFIX + '-original'
    backup_volume = PREFIX + '-backup'
    upgrade_volume = PREFIX + '-upgraded'
    rollback_volume = PREFIX + '-rollback'
    storage_volume = PREFIX + '-storage'
    try:
        docker('network', 'create', PREFIX)
        for volume in [old_volume, storage_volume]:
            docker('volume', 'create', volume)
        old_name = PREFIX + '-old'
        docker('run', '-d', '--name', old_name, '--network', PREFIX, '--network-alias', 'db',
            '--env-file', db_env, '-v', old_volume + ':/var/lib/mysql', old_db)
        CREATED.append(old_name)
        wait_db(old_name)
        result = docker('run', '--rm', '--network', PREFIX, '--env-file', tests_env, '--entrypoint', 'php',
            '-v', str(original) + ':/workspace:ro', '-w', '/workspace', old_app, 'tests/Integration/run.php')
        assert b'All integration checks passed.' in result.stdout
        fixture = r'''<?php
require 'app/src/Support/Autoloader.php'; App\Support\Autoloader::register(__DIR__.'/app');
$p=App\Support\Database::connect(['host'=>'db','port'=>3306,'database'=>'accountplus_test','username'=>'compte','password'=>getenv('TEST_DB_PASSWORD')]);
$users=new App\Repository\UserRepository($p); $companies=new App\Repository\CompanyRepository($p);
$assignments=new App\Repository\UserCompanyRepository($p); $accounts=new App\Repository\AccountRepository($p);
$transactions=new App\Repository\TransactionRepository($p); $context=new App\Service\CompanyContext($companies);
foreach(['A','B'] as $letter) {
 $company=$companies->create('Upgrade company '.$letter,'UPGRADE','Active');
 $user=$users->createUser('upgrade-'.$letter,password_hash('synthetic-upgrade-password', PASSWORD_DEFAULT),$letter);
 $assignments->assign($user,$company); $context->select($company);
 $account=(new App\Service\AccountService($accounts,$context))->create(['name'=>'Upgrade account '.$letter,'initial_balance'=>'123.45']);
 (new App\Service\TransactionService($transactions,$accounts,$context))->create(['account_id'=>$account,'transaction_date'=>'2026-10-26','label'=>'Upgrade transaction '.$letter,'amount'=>'-7.50']);
 (new App\Service\RecurringService(new App\Repository\RecurringRuleRepository($p),$accounts,$transactions,$context,$p))->create(['account_id'=>$account,'type'=>'expense','label'=>'Upgrade recurring '.$letter,'amount'=>'2.00','recurrence'=>'monthly','next_execution_date'=>'2026-11-01']);
}
(new App\Service\InstallationState(__DIR__))->write(['app_name'=>'Upgrade fixture','db_host'=>'db','db_name'=>'accountplus_test','db_username'=>'compte','db_password'=>getenv('TEST_DB_PASSWORD')]);
copy('.htaccess','app/storage/app/apache.htaccess');
(new App\Service\IpRestrictionService(__DIR__,null,__DIR__.'/app/storage/app/apache.htaccess'))->update('blacklist','','203.0.113.99','192.0.2.1');
if (!is_dir('app/storage/exports')) mkdir('app/storage/exports',0700,true);
if (file_put_contents('app/storage/exports/preserved.compte.zip','synthetic preserved archive') === false) throw new RuntimeException('Could not seed archive fixture');
'''
        docker('run', '--rm', '-i', '--network', PREFIX, '--env-file', tests_env, '--entrypoint', 'php',
            '-v', str(original) + ':/workspace:ro', '-v', storage_volume + ':/workspace/app/storage',
            '-w', '/workspace', old_app, data=fixture.encode())
        before = snapshot(old_name)
        dump = docker('exec', old_name, 'sh', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mariadb-dump -uroot --single-transaction --routines --events --triggers --hex-blob accountplus_test').stdout
        dump_path = Path(directory) / 'database.sql'
        dump_path.write_bytes(dump)
        dump_path.chmod(0o600)
        docker('stop', old_name)
        copy_volume(old_volume, backup_volume)
        copy_volume(old_volume, upgrade_volume)
        check('previous code seeds two companies users transactions recurrences and a verified cold backup')
        new_name = PREFIX + '-new'
        docker('run', '-d', '--name', new_name, '--network', PREFIX, '--network-alias', 'db',
            '--env-file', db_env, '-v', upgrade_volume + ':/var/lib/mysql', 'accountplus-security-db')
        CREATED.append(new_name)
        wait_db(new_name)
        assert snapshot(new_name) == before, 'Data changed during physical upgrade'
        assert docker('exec', new_name, 'accountplus-db-healthcheck').returncode == 0
        # Establish separate accounts explicitly on an EXISTING database. Initializer never rotates these.
        sql(new_name, "CREATE USER 'compte_migration'@'%' IDENTIFIED BY '" + MIGRATION_PASSWORD + "'; "
            "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON accountplus_test.* TO 'compte_migration'@'%'; "
            "REVOKE ALL PRIVILEGES ON accountplus_test.* FROM 'compte'@'%'; "
            "GRANT SELECT, INSERT, UPDATE, DELETE ON accountplus_test.* TO 'compte'@'%';")
        cli_env = write_env(directory, 'cli.env', {'DB_MIGRATION_USERNAME': 'compte_migration', 'DB_MIGRATION_PASSWORD': MIGRATION_PASSWORD})
        docker('run', '--rm', '--network', PREFIX, '--env-file', cli_env, '--entrypoint', 'php',
            '-v', storage_volume + ':/var/www/html/app/storage', 'accountplus-security-app', 'app/bin/console.php', 'db:migrate')
        assert snapshot(new_name) == before, 'Explicit migration changed existing records'
        # Logical restoration to a separate empty database verifies the second backup path.
        sql(new_name, 'CREATE DATABASE accountplus_restore CHARACTER SET utf8mb4;')
        docker('exec', '-i', new_name, 'sh', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mariadb -uroot accountplus_restore', data=dump)
        assert sql(new_name, 'SELECT COUNT(*), SUM(amount) FROM accountplus_restore.transactions;') == sql(new_name, 'SELECT COUNT(*), SUM(amount) FROM transactions;')
        check('10.11 Alpine data upgrades in place to official 10.11 with all row hashes preserved')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        app_env = write_env(directory, 'app.env', {'DB_HOST': 'db', 'DB_DATABASE': 'accountplus_test',
            'DB_USERNAME': 'compte', 'DB_PASSWORD': PASSWORD})
        app_name = PREFIX + '-app'
        docker('run', '-d', '--name', app_name, '--network', PREFIX, '--env-file', app_env,
            '-v', storage_volume + ':/var/www/html/app/storage', '-p', f'127.0.0.1:{port}:80', 'accountplus-security-app')
        CREATED.append(app_name)
        client = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        for attempt in range(30):
            try:
                response = client.open(f'http://127.0.0.1:{port}/login', timeout=2)
                assert response.code == 200
                break
            except OSError:
                time.sleep(1)
        else:
            raise RuntimeError('Upgraded application login unavailable')
        check_source = r'''<?php
require 'app/src/Support/Autoloader.php'; App\Support\Autoloader::register(__DIR__.'/app');
$rules=(new App\Service\IpRestrictionService(__DIR__))->read();
if ($rules['blacklist'] !== ['203.0.113.99']) exit(1);
if (file_get_contents('app/storage/exports/preserved.compte.zip') !== 'synthetic preserved archive') exit(1);
'''
        docker('exec', '-i', app_name, 'php', data=check_source.encode())
        docker('exec', app_name, 'php', 'app/bin/console.php', 'export:run')
        check('persistent config archives IP rules CLI exports and login survive the upgrade')
        docker('stop', app_name)
        docker('stop', new_name)
        copy_volume(backup_volume, rollback_volume)
        rollback_name = PREFIX + '-rollback'
        docker('run', '-d', '--name', rollback_name, '--network', PREFIX, '--env-file', db_env,
            '-v', rollback_volume + ':/var/lib/mysql', old_db)
        CREATED.append(rollback_name)
        wait_db(rollback_name)
        assert snapshot(rollback_name) == before, 'Cold rollback did not restore original records'
        check('rollback restores the cold backup using the original image without downgrading the upgraded datadir')
        print('All physical upgrade and rollback checks passed. Retained test volume prefix: ' + PREFIX, flush=True)
    finally:
        for name in reversed(CREATED):
            docker('stop', name, check=False)
