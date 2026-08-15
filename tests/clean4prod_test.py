"""Exercise deployment cleanup only on temporary, synthetic project copies."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

SCRIPT = Path(__file__).resolve().parents[1] / 'clean4prod.sh'
DEVELOPMENT = (
    '.git/HEAD', '.github/workflows/security.yml', '.gitignore', '.gitattributes',
    'README.md', 'SECURITY.md', 'AGENTS.md', '.editorconfig', '.DS_Store',
    '.idea/workspace.xml', '.vscode/settings.json', '.agents/instructions.md',
    '.codex/config.toml', '.index.php.swp', '.index.php.swo', 'chart-check.html',
    'tools/security-check.py', 'tools/__pycache__/security-check.pyc',
    'docs/security/remediation-report.md', 'tests/fixtures/upgrade/baseline.json',
    'tests/fixtures/upgrade/accountplus-legacy-83f7d77.tar.gz',
)
DOCKER = (
    'Dockerfile', 'docker-compose.yml', '.dockerignore', '.env.example',
    'docker/entrypoint.sh', 'docker/apache-accountplus.conf',
    'docker/mariadb/Dockerfile', 'docker/mariadb/entrypoint.sh',
    'docker/mariadb/gosu', 'docker/mariadb/healthcheck.sh',
    'docker/mariadb/10-runtime-privileges.sh', 'app/bin/prepare-apache.php',
)
PRESERVED = (
    'index.php', '.htaccess', 'app/src/Application/AppKernel.php',
    'assets/css/app.css', 'assets/js/security-ui.js', 'assets/vendor/chart.js/LICENSE.md',
    'LICENSE', 'NOTICE', 'app/bin/console.php', 'app/bin/check-runtime.php',
    'app/config/database.php', 'app/database/migrations/001_create_core_schema.php',
    'config.php', 'app/storage/app/config.php', 'app/storage/app/installed.lock',
    'app/storage/app/apache.htaccess', 'app/storage/imports/accounting.compte.zip',
    'app/storage/exports/accounting.compte.zip', 'app/storage/logs/application.log',
    'app/storage/analysis/analysis.json', '.env', '.env.production',
    'secrets/database-password', 'secrets/setup-token', 'server.key', 'custom-file.txt',
) + tuple('app/lang/' + locale + '.php' for locale in ['en', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'pl'])


def project(root):
    root.mkdir()
    for name in DEVELOPMENT + DOCKER + PRESERVED:
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text('Synthetic fixture: ' + name + '\n')
    shutil.copyfile(SCRIPT, root / 'clean4prod.sh')
    (root / 'app/bin/console.php').chmod(0o755)
    for name in ['config.php', 'app/storage/app/config.php', '.env', 'secrets/database-password']:
        (root / name).chmod(0o600)
    return root


def snapshot(root):
    result = {}
    for path in root.rglob('*'):
        name = str(path.relative_to(root))
        if path.is_symlink():
            result[name] = ('link', os.readlink(path))
        elif path.is_file():
            result[name] = (path.read_bytes(), path.stat().st_mode & 0o777)
        elif path.is_dir():
            result[name] = ('directory', path.stat().st_mode & 0o777)
    return result


def run(root, *args, target=True):
    command = ['bash', str(SCRIPT), *args]
    if target:
        command += ['--target', str(root)]
    return subprocess.run(command, capture_output=True, text=True)


def check(name):
    print('ok   ' + name, flush=True)


with tempfile.TemporaryDirectory(prefix='accountplus-cleanup-test-') as directory:
    work = Path(directory)
    for mode in ['--hosting', '--docker']:
        root = project(work / mode[2:])
        before = snapshot(root)
        preview = run(root, mode, '--dry-run')
        assert preview.returncode == 0, preview.stderr
        assert snapshot(root) == before, 'Dry run changed deployment files'
        assert '[would remove] tools' in preview.stdout
        assert '[would remove] SECURITY.md' in preview.stdout
        assert ('[would remove] .env.example' in preview.stdout) == (mode == '--hosting')
        check(mode + ' preview reports added development files without changing anything')

        cleaned = run(root, mode)
        assert cleaned.returncode == 0, cleaned.stderr
        after = snapshot(root)
        for name in PRESERVED + ('clean4prod.sh',):
            assert after.get(name) == before[name], 'Configuration/data/runtime file changed: ' + name
        for name in DEVELOPMENT:
            assert name not in after, 'Development file survived cleanup: ' + name
        for name in DOCKER:
            if mode == '--docker':
                assert after.get(name) == before[name], 'Docker build file changed: ' + name
            else:
                assert name not in after, 'Docker-only file survived hosting cleanup: ' + name
        check(mode + ' removes development files and preserves runtime, secrets, data, languages and licenses')
        again = run(root, mode)
        assert again.returncode == 0, again.stderr
        assert snapshot(root) == after, 'Repeated cleanup changed preserved files'
        check(mode + ' cleanup is idempotent')

    root = project(work / 'default')
    assert run(root).returncode == 0
    assert not (root / 'Dockerfile').exists() and not (root / '.env.example').exists()
    assert (root / '.env').exists() and (root / 'app/bin/check-runtime.php').exists()
    check('default mode applies hosting cleanup and retains runtime validation')

    root = project(work / 'conflict')
    before = snapshot(root)
    result = run(root, '--hosting', '--docker')
    assert result.returncode != 0
    assert snapshot(root) == before
    invalid = work / 'not-accountplus'
    invalid.mkdir()
    (invalid / 'README.md').write_text('preserve this unrelated file')
    before_invalid = snapshot(invalid)
    assert run(invalid, '--hosting').returncode != 0
    assert snapshot(invalid) == before_invalid
    check('conflicting modes and unrelated directories fail without deleting files')

    root = project(work / 'symlinks')
    outside = work / 'outside'
    outside.mkdir()
    (outside / 'keep.txt').write_text('preserve outside data')
    shutil.rmtree(root / 'tools')
    (root / 'tools').symlink_to(outside, target_is_directory=True)
    (root / '.htaccess').unlink()
    (root / '.htaccess').symlink_to('app/storage/app/apache.htaccess')
    before = snapshot(root)
    assert run(root, '--docker').returncode == 0
    assert not (root / 'tools').is_symlink()
    assert (outside / 'keep.txt').read_text() == 'preserve outside data'
    assert snapshot(root)['.htaccess'] == before['.htaccess']
    assert snapshot(root)['app/storage/app/apache.htaccess'] == before['app/storage/app/apache.htaccess']
    check('cleanup never follows development symlinks or replaces persistent Apache rules')

print('All deployment cleanup checks passed.')
