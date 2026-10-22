"""Exercise the actual startup guards in temporary files, without Docker/data."""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
BASH = shutil.which("bash") or next(
    (str(p) for p in [Path("C:/Program Files/Git/bin/bash.exe")] if p.exists()), None
)


@unittest.skipUnless(BASH, "Bash is required to execute the container startup guards")
class RenderStartupTest(unittest.TestCase):
    def startup(self, extra):
        with tempfile.TemporaryDirectory(prefix="webinar-render-guards-") as temporary:
            sandbox = Path(temporary)
            nginx = sandbox / "nginx.conf"
            fpm = sandbox / "fpm.conf"
            nginx.write_text((ROOT / "docker/nginx.conf").read_text(), encoding="utf-8")
            fpm.write_text((ROOT / "docker/php-fpm.conf").read_text(), encoding="utf-8")
            source = (ROOT / "docker/entrypoint.sh").read_text()
            # Execute the real port/FPM/Render guards. Stop before any writable
            # app state, configuration caching, database or worker operation.
            source = source.split("# Writable state", 1)[0]
            source = source.replace("/etc/nginx/nginx.conf", nginx.as_posix())
            source = source.replace("/usr/local/etc/php-fpm.d/zz-webinar.conf", fpm.as_posix())
            script = sandbox / "startup.sh"
            script.write_text(source, encoding="utf-8", newline="\n")
            environment = dict(os.environ)
            for key in ["RENDER", "RENDER_EXTERNAL_URL", "APP_URL", "RENDER_EDGE_TLS", "PHP_FPM_MAX_CHILDREN", "PORT"]:
                environment.pop(key, None)
            environment.update(extra)
            result = subprocess.run([BASH, script.as_posix()], env=environment, capture_output=True, text=True, timeout=15)
            return result, nginx.read_text(), fpm.read_text()

    def test_other_hosts_keep_listener_tls_and_bounded_on_demand_fpm(self):
        result, nginx, fpm = self.startup({})
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("fastcgi_param HTTPS $https if_not_empty;", nginx)
        self.assertIn("listen 8080 default_server", nginx)
        self.assertIn("pm = ondemand", fpm)
        self.assertIn("pm.max_children = 2", fpm)

    def test_render_https_profile_uses_platform_transport_without_trusting_headers(self):
        result, nginx, fpm = self.startup({
            "RENDER": "true", "RENDER_EDGE_TLS": "true",
            "RENDER_EXTERNAL_URL": "https://synthetic.onrender.com", "PHP_FPM_MAX_CHILDREN": "1", "PORT": "10000",
        })
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("fastcgi_param HTTPS on;", nginx)
        self.assertNotIn("$http_x_forwarded_proto", nginx)
        self.assertIn("listen 10000 default_server", nginx)
        self.assertIn("pm.max_children = 1", fpm)

    def test_forged_platform_insecure_url_wrong_host_and_invalid_fpm_fail_closed(self):
        base = {"RENDER": "true", "RENDER_EDGE_TLS": "true", "RENDER_EXTERNAL_URL": "https://synthetic.onrender.com"}
        for change in [
            {"RENDER": "false"},
            {"RENDER_EXTERNAL_URL": "http://synthetic.onrender.com"},
            {"APP_URL": "https://another.example.test"},
            {"PHP_FPM_MAX_CHILDREN": "0"},
            {"PHP_FPM_MAX_CHILDREN": "1; exit 0"},
        ]:
            with self.subTest(change=change):
                result, _, _ = self.startup({**base, **change})
                self.assertNotEqual(0, result.returncode)
                self.assertIn("FATAL:", result.stdout)


if __name__ == "__main__":
    unittest.main()
