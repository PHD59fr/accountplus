"""Upgrade fixture regressions using only the Python standard library."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('upgrade_baseline', ROOT / 'tests/Integration/upgrade_baseline.py')
baseline = importlib.util.module_from_spec(spec)
spec.loader.exec_module(baseline)


def check(name):
    print('ok   ' + name, flush=True)


def reject(destination, fixture, message):
    try:
        baseline.prepare_baseline(destination, fixture)
    except RuntimeError as error:
        assert message in str(error), str(error)
    else:
        raise AssertionError('Invalid baseline fixture accepted')


with tempfile.TemporaryDirectory(prefix='accountplus-baseline-test-') as directory:
    work = Path(directory)
    output = work / 'valid'
    metadata = baseline.prepare_baseline(output)
    assert (output / 'Dockerfile').read_text().startswith('FROM alpine:3.20\n')
    assert (output / 'docker/mariadb/Dockerfile').read_text().startswith('FROM alpine:3.20\n')
    assert all((output / path).is_file() for path in baseline.REQUIRED_FILES)
    assert not (output / '.git').exists()
    # Track content fidelity across every file, not just the paths the loader requires.
    with tarfile.open(baseline.FIXTURE / metadata['archive']) as archive:
        for member in archive:
            if member.isfile():
                assert (output / member.name).read_bytes() == archive.extractfile(member).read(), member.name
    check('frozen legacy source is extracted byte for byte without Git metadata')

    copy = work / 'gitless'
    integration = copy / 'tests/Integration'
    integration.mkdir(parents=True)
    for name in ['upgrade_rehearsal.py', 'upgrade_baseline.py']:
        shutil.copyfile(ROOT / 'tests/Integration' / name, integration / name)
    shutil.copytree(baseline.FIXTURE, copy / 'tests/fixtures/upgrade')
    environment = {**os.environ, 'PATH': '', 'PYTHONNOUSERSITE': '1'}
    result = subprocess.run([sys.executable, str(integration / 'upgrade_rehearsal.py'), '--check-baseline'],
                            cwd=copy, env=environment, capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    assert metadata['source_revision'] in result.stdout
    check('rehearsal preflight runs with no repository and neither Git nor Docker on PATH')

    fixture = work / 'damaged'
    shutil.copytree(baseline.FIXTURE, fixture)
    (fixture / metadata['archive']).write_bytes(b'corrupt fixture')
    reject(work / 'checksum', fixture, 'checksum mismatch')
    assert not (work / 'checksum').exists()
    (fixture / metadata['archive']).unlink()
    reject(work / 'missing', fixture, 'Cannot prepare')
    check('corrupt or missing baselines fail before extraction instead of skipping the test')

    destination = work / 'existing'
    destination.mkdir()
    (destination / 'keep.txt').write_text('preserve')
    reject(destination, baseline.FIXTURE, 'must be empty')
    assert (destination / 'keep.txt').read_text() == 'preserve'
    check('existing destination files are never overwritten')

    for index, (name, kind) in enumerate([('../escape', 'file'), ('/absolute', 'file'), ('link', 'symlink'), ('device', 'device')]):
        archive_path = fixture / metadata['archive']
        with tarfile.open(archive_path, 'w:gz') as archive:
            member = tarfile.TarInfo(name)
            if kind == 'file':
                member.size = 1
                archive.addfile(member, io.BytesIO(b'x'))
            else:
                member.type = tarfile.SYMTYPE if kind == 'symlink' else tarfile.CHRTYPE
                member.linkname = '/outside'
                archive.addfile(member)
        updated = {**metadata, 'sha256': hashlib.sha256(archive_path.read_bytes()).hexdigest()}
        (fixture / 'baseline.json').write_text(json.dumps(updated))
        reject(work / ('unsafe-' + str(index)), fixture, 'Unsafe baseline archive member')
    assert not (work / 'escape').exists()
    check('absolute paths traversal symlinks and special archive entries are refused')

    (fixture / 'baseline.json').write_text('[]')
    reject(work / 'invalid-manifest', fixture, 'Invalid baseline manifest')
    check('invalid baseline manifests fail explicitly')

print('All upgrade baseline checks passed.')
