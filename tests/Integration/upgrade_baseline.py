"""Load the frozen legacy application fixture without consulting Git history."""
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import tarfile

FIXTURE = Path(__file__).resolve().parents[1] / 'fixtures' / 'upgrade'
REQUIRED_FILES = (
    'Dockerfile', '.dockerignore', '.htaccess',
    'docker/mariadb/Dockerfile', 'docker/mariadb/entrypoint.sh',
    'tests/Integration/run.php', 'tests/Integration/login_worker.php',
    'app/src/Support/Database.php',
)


def prepare_baseline(destination, fixture=FIXTURE):
    """Verify and unpack the versioned baseline, or fail before any Docker work."""
    fixture = Path(fixture)
    destination = Path(destination)
    try:
        metadata = json.loads((fixture / 'baseline.json').read_text())
        if (not isinstance(metadata, dict) or metadata.get('format_version') != 1
                or not isinstance(metadata.get('archive'), str)
                or not isinstance(metadata.get('source_revision'), str)
                or re.fullmatch('[0-9a-f]{40}', metadata['source_revision']) is None
                or not isinstance(metadata.get('sha256'), str)
                or re.fullmatch('[0-9a-f]{64}', metadata['sha256']) is None):
            raise ValueError('Invalid baseline manifest.')
        name = metadata['archive']
        if not name or Path(name).name != name or '\\' in name:
            raise ValueError('Invalid baseline archive path.')
        archive = fixture / name
        if hashlib.sha256(archive.read_bytes()).hexdigest() != metadata['sha256']:
            raise ValueError('Baseline archive checksum mismatch.')
        if destination.exists() and any(destination.iterdir()):
            raise ValueError('Baseline destination must be empty.')
        with tarfile.open(archive, 'r:gz') as source:
            members = source.getmembers()
            if len(members) > 2000 or sum(member.size for member in members) > 16 * 1024 * 1024:
                raise ValueError('Baseline archive exceeds fixture limits.')
            for member in members:
                path = PurePosixPath(member.name)
                if (path.is_absolute() or not path.parts or '..' in path.parts
                        or '\\' in member.name or not (member.isfile() or member.isdir())):
                    raise ValueError('Unsafe baseline archive member.')
            names = {member.name for member in members if member.isfile()}
            if not set(REQUIRED_FILES).issubset(names):
                raise ValueError('Baseline archive is incomplete.')
            destination.mkdir(parents=True, exist_ok=True)
            for member in members:
                path = destination.joinpath(*PurePosixPath(member.name).parts)
                if member.isdir():
                    path.mkdir(parents=True, exist_ok=True)
                    continue
                path.parent.mkdir(parents=True, exist_ok=True)
                with source.extractfile(member) as content, path.open('xb') as output:
                    output.write(content.read())
                path.chmod(0o644 | (member.mode & 0o111))
        return metadata
    except (OSError, ValueError, TypeError, tarfile.TarError) as error:
        raise RuntimeError('Cannot prepare the frozen upgrade baseline: ' + str(error)) from error
