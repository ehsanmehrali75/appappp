"""Build a code-only deployment ZIP, preserving host credentials, data and uploads.

Usage: python tools/build_release.py --output /tmp/maarksho-release.zip
The source can be a dirty worktree; release.json records that fact and file hashes.
"""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PRIVATE_DIRS = {'.git', '.github', 'db', 'uploads', 'logs', 'tmp', 'cache', '__pycache__', 'node_modules', 'tests', 'tools'}
PRIVATE_SUFFIXES = {'.sqlite', '.sqlite3', '.db', '.sql', '.log', '.cache', '.lock', '.pyc'}


def deployable(path):
    name = path.name.lower()
    return not (set(path.parts) & PRIVATE_DIRS or
                (name.startswith('.env') and name != '.env.example') or
                name.endswith('.local.php') or path.suffix.lower() in PRIVATE_SUFFIXES or
                name in {'.ds_store', 'release.json'})


def build(output):
    files = subprocess.check_output(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], cwd=ROOT).decode().split('\0')
    output = output.resolve()
    if output.is_relative_to(ROOT):
        raise ValueError('Write the release outside the source tree')
    manifest = {'commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
                'dirty': bool(subprocess.check_output(['git', 'status', '--porcelain'], cwd=ROOT)),
                'runtime_data_included': False, 'sha256': {}}
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        for value in sorted(set(files)):
            path = Path(value)
            source = ROOT / path
            if not value or not deployable(path) or not source.is_file() or source.is_symlink():
                continue
            content = source.read_bytes()
            manifest['sha256'][value] = hashlib.sha256(content).hexdigest()
            archive.writestr(value, content)
        archive.writestr('release.json', json.dumps(manifest, ensure_ascii=False, indent=2))
    with zipfile.ZipFile(output) as archive:
        if any(not deployable(Path(name)) for name in archive.namelist() if name != 'release.json'):
            raise RuntimeError('Private/runtime file found in release')
        if archive.testzip():
            raise RuntimeError('Release ZIP verification failed')
    print(f'Validated code-only release: {output} ({len(manifest["sha256"])} files)')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    build(parser.parse_args().output)
