"""Isolated safety tests: python -m unittest discover -s tests/deployment -v"""

import importlib.util
import json
import pathlib
import tempfile
import unittest
import zipfile

SPEC = importlib.util.spec_from_file_location("ec2_package", pathlib.Path(__file__).resolve().parents[2] / "deploy/prepare-ec2-package.py")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class PackageSafetyTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="webinar-package-test-")
        self.parent = pathlib.Path(self.temporary.name)
        self.root = self.parent / "app"
        self.root.mkdir()
        for name in MODULE.REQUIRED:
            self.write(name, b"synthetic source")
        manifest = {entry: {"file": "assets/entry-" + str(number) + ".js"} for number, entry in enumerate(MODULE.ENTRY_POINTS)}
        self.write("public/build/manifest.json", json.dumps(manifest).encode())
        for entry in manifest.values():
            self.write("public/build/" + entry["file"], b"synthetic compiled asset")
        self.write("deploy/ec2/runtime.env.example", b"APP_KEY=\nBREVO_API_KEY=\n")

    def tearDown(self):
        self.temporary.cleanup()

    def write(self, name, contents):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(contents)

    def package(self, release_id="test"):
        return MODULE.build(self.root, self.parent / "out", release_id)

    def test_real_state_is_never_included_and_required_build_and_empty_storage_are_present(self):
        for name in (".env", "auth.json", "database/database.sqlite", "storage/app/private/actual.pdf",
                     "storage/app/private/imports-pending/import-1.csv", "storage/logs/laravel.log",
                     "bootstrap/cache/config.php", "public/hot", "public/_preview/private.html", "vendor/source.php",
                     "deploy/__pycache__/cached.pyc"):
            self.write(name, b"FORBIDDEN_SYNTHETIC_DATA")
        result = self.package()
        self.assertFalse(result["production_approved"])
        with zipfile.ZipFile(result["archive"]) as archive:
            self.assertIn("storage/app/private/.gitignore", archive.namelist())
            self.assertIn("public/build/manifest.json", archive.namelist())
            self.assertFalse(any(b"FORBIDDEN_SYNTHETIC_DATA" in archive.read(name) for name in archive.namelist()))

    def test_a_filled_secret_in_an_example_stops_packaging(self):
        self.write("deploy/ec2/runtime.env.example", b"BREVO_API_KEY=synthetic-secret-must-not-ship\n")
        with self.assertRaisesRegex(ValueError, "Secret field must be blank"):
            self.package()

    def test_missing_compiled_asset_stops_packaging(self):
        (self.root / "public/build/assets/entry-0.js").unlink()
        with self.assertRaisesRegex(ValueError, "built asset is missing"):
            self.package()

    def test_existing_archive_is_not_overwritten_and_output_cannot_be_inside_source(self):
        result = self.package()
        original = pathlib.Path(result["archive"]).read_bytes()
        with self.assertRaises(FileExistsError):
            self.package()
        self.assertEqual(original, pathlib.Path(result["archive"]).read_bytes())
        with self.assertRaisesRegex(ValueError, "outside the app"):
            MODULE.build(self.root, self.root / "out", "bad")

    def test_archive_tampering_is_rejected(self):
        result = self.package()
        changed = self.parent / "changed.zip"
        with zipfile.ZipFile(result["archive"]) as source, zipfile.ZipFile(changed, "w") as target:
            for name in source.namelist():
                target.writestr(name, b"tampered" if name == "artisan" else source.read(name))
        with self.assertRaisesRegex(ValueError, "checksum mismatch"):
            MODULE.verify(changed)

    def test_checksum_sidecar_uses_portable_lf_for_linux_sha256sum(self):
        result = self.package()
        sidecar = pathlib.Path(result["archive"] + ".sha256").read_bytes()
        self.assertNotIn(b"\r", sidecar)
        self.assertTrue(sidecar.endswith(b"\n"))
        self.assertEqual(result["sha256"], sidecar.decode().split()[0])

    def test_traversal_and_unlisted_private_paths_are_rejected(self):
        for name in ("../.env", "/.env", "public/build/../../../.env", "storage/app/private/secret.txt"):
            with self.subTest(name=name), self.assertRaises(ValueError):
                MODULE.safe_name(name)


class PreparationPolicyTest(unittest.TestCase):
    def test_runtime_example_keeps_real_mail_off_and_security_and_backup_gates_honest(self):
        root = pathlib.Path(__file__).resolve().parents[2]
        contents = (root / "deploy/ec2/runtime.env.example").read_text()
        values = {line.split("=", 1)[0]: line.split("=", 1)[1] for line in contents.splitlines() if line and not line.startswith("#") and "=" in line}
        for key in MODULE.SECRET_KEYS:
            self.assertEqual("", values[key], key)
        for key, expected in {"APP_ENV": "production", "APP_DEBUG": "false", "DB_CONNECTION": "pgsql",
                              "DB_HOST": "127.0.0.1", "MANAGED_POSTGRES": "false", "BACKUPS_ENABLED": "false",
                              "TRANSACTIONAL_EMAIL_PROVIDER": "log", "EMAIL_SENDS_PER_DAY": "1",
                              "REDIS_QUEUE_RETRY_AFTER": "2100", "EMAIL_QUEUE_RETRY_AFTER": "120",
                              "SESSION_SECURE_COOKIE": "true", "SECURITY_REQUIRE_ADMIN_TWO_FACTOR": "true",
                              "SECURITY_REQUIRE_SENSITIVE_ACTION_PASSWORD_CONFIRMATION": "true"}.items():
            self.assertEqual(expected, values[key], key)

    def test_single_node_target_has_only_one_mail_worker_and_redis_never_evicts_jobs(self):
        root = pathlib.Path(__file__).resolve().parents[2]
        target = (root / "deploy/ec2/webinar-platform-services.target").read_text()
        requires = next(line for line in target.splitlines() if line.startswith("Requires="))
        self.assertEqual(1, requires.count("webinar-platform-worker-emails@"))
        self.assertIn("webinar-platform-worker-default.service", requires)
        self.assertIn("webinar-platform-scheduler.service", requires)
        redis = (root / "deploy/ec2/redis-local.conf.example").read_text()
        self.assertIn("maxmemory-policy noeviction", redis)
        self.assertIn("appendonly yes", redis)
        self.assertIn("bind 127.0.0.1 ::1", redis)


if __name__ == "__main__":
    unittest.main()
