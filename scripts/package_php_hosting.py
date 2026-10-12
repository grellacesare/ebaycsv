#!/usr/bin/env python3
"""Build the public PHP/React FTP distribution without copying runtime data."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
ASSET_EXTENSIONS = {'.js', '.css', '.png', '.svg', '.jpg', '.jpeg', '.webp', '.gif', '.avif', '.ico', '.woff', '.woff2', '.ttf', '.otf'}
RESOURCE_EXTENSIONS = {'.json', '.css', '.j2', '.png', '.svg', '.jpg', '.jpeg', '.webp'}
PRIVATE_README = '''Questa cartella deve essere FUORI dal webroot pubblico reale.
Se csvstudio si trova in public_html/csvstudio, collocare csvstudio-data
accanto a public_html, non al suo interno. private_html può essere pubblico.
Il programma creerà qui la chiave iniziale, le credenziali e l'archivio.
Non pubblicare questa cartella, i suoi backup o la chiave di installazione.
'''.encode('utf-8')


def create_package(root: Path, frontend_dist: Path, output: Path) -> dict[str, str]:
    """Collect only production code/resources, then atomically publish the ZIP."""
    root, frontend_dist, output = Path(root).resolve(), Path(frontend_dist).resolve(), Path(output)
    entries: dict[str, bytes] = {}

    def read_public_file(source: Path, allowed_root: Path = root) -> bytes:
        aliased = any(path.is_symlink() for path in (source, *source.parents) if path.is_relative_to(allowed_root))
        if aliased or not source.resolve().is_relative_to(allowed_root):
            raise ValueError(f'Symlink or file outside the public source tree: {source}')
        if not source.is_file():
            raise FileNotFoundError(f'Required public package input is missing: {source}')
        return source.read_bytes()

    def add(source: Path, target: str, allowed_root: Path = root) -> None:
        entries[target] = read_public_file(source, allowed_root)

    php = root / 'hosting/php'
    for name in ('index', 'api', 'setup', 'logout'):
        add(php / f'public/{name}.php', f'csvstudio/{name}.php')
    for name in ('.user.ini', '.htaccess'):
        config = php / 'public' / name
        if config.exists():
            add(config, f'csvstudio/{name}')
    add(php / 'bootstrap.php', 'csvstudio/app/bootstrap.php')
    privacy_guard = php / 'public/app/.htaccess'
    if privacy_guard.exists():
        add(privacy_guard, 'csvstudio/app/.htaccess')
    sources = sorted((php / 'src').rglob('*.php'))
    if not sources:
        raise ValueError('PHP application source directory is empty')
    for path in sources:
        add(path, 'csvstudio/app/src/' + path.relative_to(php / 'src').as_posix())
    for library in ('brick/math', 'symfony/normalizer'):
        directory = php / 'vendor' / library
        add(directory / 'LICENSE', f'csvstudio/app/vendor/{library}/LICENSE')
        for path in sorted(directory.rglob('*.php')):
            add(path, 'csvstudio/app/vendor/' + path.relative_to(php / 'vendor').as_posix())
    add(php / 'vendor/manifest.json', 'csvstudio/app/vendor/manifest.json')

    resources = root / 'backend/src/motorworld_csv/resources'
    if not resources.is_dir():
        raise FileNotFoundError('Builder resource directory is missing')
    for path in sorted(resources.rglob('*')):
        if path.is_file():
            if path.suffix not in RESOURCE_EXTENSIONS:
                raise ValueError(f'Unexpected non-public resource: {path.name}')
            add(path, 'csvstudio/app/resources/' + path.relative_to(resources).as_posix())

    add(frontend_dist / 'index.html', 'csvstudio/app/index.html', frontend_dist)
    html = entries['csvstudio/app/index.html'].decode('utf-8')
    if re.search(r'''(?:src|href)\s*=\s*["']/assets/''', html):
        raise ValueError('Frontend assets must be relative; rebuild with --base=./')
    build_manifest = frontend_dist / '.vite/manifest.json'
    if build_manifest.exists():
        metadata = json.loads(read_public_file(build_manifest, frontend_dist))
        names = set()
        for entry in metadata.values():
            names.add(entry['file'])
            names.update(entry.get('css', []))
            names.update(entry.get('assets', []))
        if any(not name.startswith('assets/') or '..' in Path(name).parts for name in names):
            raise ValueError('Build manifest references assets outside the public asset directory')
        assets = [frontend_dist / name for name in sorted(names)]
    else:
        assets = sorted((frontend_dist / 'assets').rglob('*'))
    if not assets:
        raise ValueError('Compiled frontend assets are missing')
    for path in assets:
        if path.is_file():
            if path.suffix not in ASSET_EXTENSIONS:
                raise ValueError(f'Unexpected non-public frontend asset: {path.name}')
            add(path, 'csvstudio/assets/' + path.relative_to(frontend_dist / 'assets').as_posix(), frontend_dist)

    add(root / 'docs/ftp-hosting.md', 'LEGGIMI-FTP.md')
    add(root / 'docs/import-platforms.md', 'import-platforms.md')
    entries['csvstudio-data/README.txt'] = PRIVATE_README
    hashes = {name: hashlib.sha256(data).hexdigest() for name, data in sorted(entries.items())}
    entries['MANIFEST-SHA256.txt'] = ''.join(f'{digest}  {name}\n' for name, digest in hashes.items()).encode('utf-8')

    output.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=output.name + '.', suffix='.tmp', dir=output.parent)
    os.close(descriptor)
    try:
        with zipfile.ZipFile(temporary, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
            info = zipfile.ZipInfo('csvstudio-data/')
            info.create_system = 3
            info.external_attr = (stat.S_IFDIR | 0o700) << 16 | 0x10
            archive.writestr(info, b'')
            for name, data in sorted(entries.items()):
                info = zipfile.ZipInfo(name)
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | (0o600 if name.startswith('csvstudio-data/') else 0o644)) << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(info, data)
        with zipfile.ZipFile(temporary) as archive:
            if archive.testzip() is not None:
                raise ValueError('Generated package failed ZIP integrity validation')
        os.replace(temporary, output)
    finally:
        Path(temporary).unlink(missing_ok=True)
    return hashes


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'var/deployment/motorworld-csvstudio-ftp.zip')
    parser.add_argument('--frontend-dist', type=Path, default=ROOT / 'var/deployment/php-frontend')
    parser.add_argument('--skip-build', action='store_true', help='Reuse a previously built relative-asset frontend')
    args = parser.parse_args()
    dist = args.frontend_dist.resolve()
    if not args.skip_build:
        subprocess.run(['npm', 'run', 'build', '--', '--base=./', '--manifest', '--outDir', str(dist)], cwd=ROOT / 'frontend', check=True)
    manifest = create_package(ROOT, dist, args.output)
    digest = hashlib.sha256(args.output.read_bytes()).hexdigest()
    args.output.with_suffix(args.output.suffix + '.sha256').write_text(f'{digest}  {args.output.name}\n')
    print(f'Package: {args.output.resolve()}')
    print(f'Public runtime files: {len(manifest)}; bytes: {args.output.stat().st_size}')
    print(f'SHA-256: {digest}')


if __name__ == '__main__':
    main()
