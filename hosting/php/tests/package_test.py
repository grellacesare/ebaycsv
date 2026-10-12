"""Run with: python3 hosting/php/tests/package_test.py (standard library only)."""
from pathlib import Path
import importlib.util
import json
import shutil
import tempfile
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location(
    'package_php_hosting', ROOT / 'scripts/package_php_hosting.py'
)
packager = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packager)


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.dist = self.root / 'built-ui'
        fixtures = {
            **{f'hosting/php/public/{name}.php': '<?php require __DIR__."/app/bootstrap.php";'
               for name in ('index', 'api', 'setup', 'logout')},
            'hosting/php/bootstrap.php': '<?php // public bootstrap',
            'hosting/php/src/Example.php': '<?php // application code',
            'hosting/php/vendor/brick/math/src/BigDecimal.php': '<?php // bundled arithmetic',
            'hosting/php/vendor/brick/math/LICENSE': 'MIT',
            'hosting/php/vendor/symfony/normalizer/Normalizer.php': '<?php // bundled Unicode',
            'hosting/php/vendor/symfony/normalizer/LICENSE': 'MIT',
            'hosting/php/vendor/manifest.json': '[]',
            'backend/src/motorworld_csv/resources/ebay_headers.json': '[]',
            'docs/ftp-hosting.md': 'Installation instructions',
            'docs/import-platforms.md': 'Import instructions',
            'built-ui/index.html': '<script src="./assets/app.js"></script>',
            'built-ui/assets/app.js': 'console.log("application")',
            'hosting/php/tests/private.php': '<?php // never distribute tests',
            'hosting/php/public/config.php': 'private-placeholder',
            'hosting/php/vendor/brick/math/composer.json': 'development metadata',
            'var/archive/installation-key.txt': 'private-placeholder',
            'var/archive/listino.csv': 'private-placeholder',
            '.env': 'private-placeholder',
        }
        for name, data in fixtures.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(data, encoding='utf-8')
        self.output = self.root / 'distribution.zip'

    def tearDown(self):
        self.temp.cleanup()

    def test_public_runtime_with_licenses_and_no_private_or_development_files(self):
        packager.create_package(self.root, self.dist, self.output)
        with zipfile.ZipFile(self.output) as archive:
            names = archive.namelist()
            for name in (
                'csvstudio/index.php', 'csvstudio/api.php', 'csvstudio/setup.php',
                'csvstudio/logout.php', 'csvstudio/app/bootstrap.php',
                'csvstudio/app/index.html', 'csvstudio/assets/app.js',
                'csvstudio/app/vendor/brick/math/LICENSE',
                'csvstudio/app/vendor/symfony/normalizer/LICENSE',
            ):
                self.assertIn(name, names)
            self.assertEqual(
                [name for name in names if name.startswith('csvstudio-data/')],
                ['csvstudio-data/', 'csvstudio-data/README.txt'],
            )
            self.assertFalse(any(
                'installation-key' in name or 'listino' in name
                or 'composer.json' in name or '/tests/' in name or '.env' in name
                or name.endswith('config.php')
                for name in names
            ))
            self.assertIsNone(archive.testzip())

    def test_absolute_assets_are_rejected_before_overwriting_an_existing_archive(self):
        self.output.write_bytes(b'previous-package')
        (self.dist / 'index.html').write_text('<script src="/assets/app.js"></script>')
        with self.assertRaises(ValueError):
            packager.create_package(self.root, self.dist, self.output)
        self.assertEqual(self.output.read_bytes(), b'previous-package')

    def test_asset_secret_and_external_symlink_are_rejected(self):
        secret = self.dist / 'assets/.env'
        secret.write_text('private-placeholder')
        with self.assertRaises(ValueError):
            packager.create_package(self.root, self.dist, self.output)
        secret.unlink()
        (self.root / 'hosting/php/src/Outside.php').symlink_to('/etc/passwd')
        with self.assertRaises(ValueError):
            packager.create_package(self.root, self.dist, self.output)

    def test_vendor_directory_cannot_alias_private_files_inside_the_repository(self):
        private = self.root / 'var/private-package'
        private.mkdir(parents=True)
        (private / 'LICENSE').write_text('MIT')
        (private / 'Secret.php').write_text('<?php // private-placeholder')
        vendor = self.root / 'hosting/php/vendor/brick/math'
        shutil.rmtree(vendor)
        vendor.symlink_to(private, target_is_directory=True)
        with self.assertRaises(ValueError):
            packager.create_package(self.root, self.dist, self.output)

    def test_build_manifest_excludes_old_unreferenced_bundles(self):
        (self.dist / 'assets/old-bundle.js').write_text('old application')
        (self.dist / '.vite').mkdir()
        (self.dist / '.vite/manifest.json').write_text(json.dumps({
            'index.html': {'file': 'assets/app.js', 'isEntry': True},
        }))
        packager.create_package(self.root, self.dist, self.output)
        with zipfile.ZipFile(self.output) as archive:
            self.assertIn('csvstudio/assets/app.js', archive.namelist())
            self.assertNotIn('csvstudio/assets/old-bundle.js', archive.namelist())

    def test_public_server_configuration_is_included_explicitly(self):
        configs = {
            '.user.ini': 'upload_max_filesize = 64M\n',
            '.htaccess': 'Options -Indexes\n',
        }
        for name, data in configs.items():
            (self.root / 'hosting/php/public' / name).write_text(data)
        packager.create_package(self.root, self.dist, self.output)
        with zipfile.ZipFile(self.output) as archive:
            for name, data in configs.items():
                self.assertEqual(archive.read('csvstudio/' + name).decode(), data)
            self.assertNotIn('csvstudio/config.php', archive.namelist())


if __name__ == '__main__':
    unittest.main()
