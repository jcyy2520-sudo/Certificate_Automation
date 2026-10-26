"""Exercise Nginx temporary-directory provisioning without app data or Docker."""

from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
APP_ROOT = PurePosixPath("/var/www/html")
BASH = next(
    (str(p) for p in [Path("C:/Program Files/Git/bin/bash.exe")] if p.exists()), None
) or shutil.which("bash")


@unittest.skipUnless(BASH, "Bash is required to execute directory provisioning")
class NginxTemporaryStorageTest(unittest.TestCase):
    def temporary_paths(self):
        source = (ROOT / "docker/nginx.conf").read_text()
        paths = {}
        for directive in ["client_body_temp_path", "fastcgi_temp_path"]:
            match = re.search(r"^\s*" + directive + r"\s+(\S+)(?:\s+\d+)*\s*;", source, re.MULTILINE)
            self.assertIsNotNone(match, f"{directive} must not inherit Alpine's nginx-only directory")
            path = PurePosixPath(match[1])
            self.assertTrue(path.is_relative_to(APP_ROOT / "storage/framework"), str(path))
            self.assertNotIn("..", path.parts)
            paths[directive] = path.relative_to(APP_ROOT)
        self.assertEqual(len(paths), len(set(paths.values())), "Request and response temporary files need separate directories")
        return paths

    def assert_provisioned(self, commands):
        paths = self.temporary_paths()
        worker = re.search(r"^user\s+(\S+)\s*;", (ROOT / "docker/nginx.conf").read_text(), re.MULTILINE)[1]
        with tempfile.TemporaryDirectory(prefix="webinar-nginx-storage-") as temporary:
            sandbox = Path(temporary)
            script = sandbox / "provision.sh"
            # Execute the actual mkdir commands. Record ownership operations:
            # Windows cannot apply Linux UIDs; the container HTTP test does that.
            script.write_text(
                "#!/bin/sh\nset -eu\n"
                "chown() { printf '%s\\n' \"$*\" >> ownership.log; }\n"
                "chmod() { :; }\n" + commands,
                encoding="utf-8", newline="\n",
            )
            result = subprocess.run([BASH, script.as_posix()], cwd=sandbox, capture_output=True, text=True, timeout=15)
            self.assertEqual(0, result.returncode, result.stderr)
            ownership = (sandbox / "ownership.log").read_text().splitlines()
            owned_roots = []
            for call in ownership:
                arguments = call.split()
                if len(arguments) >= 3 and arguments[:2] == ["-R", f"{worker}:{worker}"]:
                    owned_roots.extend(PurePosixPath(path) for path in arguments[2:])
            for directive, path in paths.items():
                with self.subTest(directive=directive):
                    self.assertTrue((sandbox / path).is_dir(), f"{path} was not created")
                    self.assertTrue(any(path.is_relative_to(root) for root in owned_roots), f"{path} is not assigned to {worker}")

    def test_startup_creates_private_request_and_response_temporary_directories(self):
        source = (ROOT / "docker/entrypoint.sh").read_text()
        writable_state = source.split("# Writable state", 1)[1].split("# Fail fast on missing configuration", 1)[0]
        self.assert_provisioned(writable_state)

    def test_image_build_creates_the_same_worker_owned_temporary_directories(self):
        source = (ROOT / "Dockerfile").read_text().replace("\\\n", " ")
        provisioning = next(
            line.removeprefix("RUN ") for line in source.splitlines()
            if line.startswith("RUN mkdir ") and "chown" in line and "storage" in line
        )
        self.assert_provisioned(provisioning)

    def test_temporary_request_and_response_files_are_excluded_from_source_and_images(self):
        for filename in [".gitignore", ".dockerignore"]:
            with self.subTest(filename=filename):
                rules = (ROOT / filename).read_text().splitlines()
                self.assertIn("storage/framework/nginx", [rule.strip("/") for rule in rules])


if __name__ == "__main__":
    unittest.main()
