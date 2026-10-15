"""Build/check the desktop ZIP from an explicit source list; never bundle user output."""
import argparse
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / 'app'
PACKAGE = APP / 'tanedorost-fast.zip'
FILES = (
    'app.py', 'crawler/__init__.py', 'crawler/scraper.py', 'requirements.txt',
    'README.md', 'README-fa.md', 'verify_local.py', 'verify_size_guide.py',
    'verify_ui.py', 'verification.json', 'reference/original_scraper.py',
)
PREFIX = 'tanedorost-fast/'


def check():
    with zipfile.ZipFile(PACKAGE) as archive:
        expected = {PREFIX + name for name in FILES}
        if set(archive.namelist()) != expected or archive.testzip():
            raise RuntimeError('Desktop ZIP file list/integrity differs from the source package')
        for name in FILES:
            if archive.read(PREFIX + name) != (APP / name).read_bytes():
                raise RuntimeError('Desktop ZIP has stale source: ' + name)
    print(f'Desktop package verified: {len(FILES)} files match source; no user output included')


def build():
    temporary = PACKAGE.with_suffix('.zip.tmp')
    try:
        with zipfile.ZipFile(temporary, 'w', zipfile.ZIP_DEFLATED) as archive:
            for name in FILES:
                info = zipfile.ZipInfo(PREFIX + name, date_time=(2026, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                archive.writestr(info, (APP / name).read_bytes())
        temporary.replace(PACKAGE)
    finally:
        temporary.unlink(missing_ok=True)
    check()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    if parser.parse_args().check:
        check()
    else:
        build()
