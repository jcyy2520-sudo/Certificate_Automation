#!/usr/bin/env python3
"""Install a verified code-only ZIP in disposable local isolation, never a server."""

import argparse
import importlib.util
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import time
import zipfile

sys.dont_write_bytecode = True
ROOT = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("ec2_package", ROOT / "deploy/prepare-ec2-package.py")
PACKAGE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PACKAGE)


def rehearse(archive):
    archive = archive.resolve()
    verification = PACKAGE.verify(archive)
    php = shutil.which("php")
    composer = shutil.which("composer")
    if not php or not composer:
        raise RuntimeError("Local PHP and Composer are required.")
    phar = pathlib.Path(composer).with_suffix(".phar")
    composer_command = [php, str(phar)] if phar.is_file() else [composer]
    evidence = {"archive": archive.name, "archive_sha256": verification["sha256"], "passed": False,
                "scope": "Disposable extracted code ZIP; production-only locked Composer; SQLite :memory:/log mail; no migrations, workers or provider calls",
                "steps": [], "production_approved": False, "linux_host_verified": False}
    log = []
    started = time.monotonic()
    try:
        with tempfile.TemporaryDirectory(prefix="webinar-ec2-production-install-") as temporary:
            sandbox = pathlib.Path(temporary).resolve()
            if sandbox.parent != pathlib.Path(tempfile.gettempdir()).resolve():
                raise RuntimeError("Unexpected disposable workspace location.")
            with zipfile.ZipFile(archive) as package:
                package.extractall(sandbox)  # verified allowlist rejects traversal/symlinks
            environment = dict(os.environ)
            environment.update({
                "APP_ENV": "testing", "APP_DEBUG": "false", "APP_URL": "https://preparation.example.test",
                "APP_KEY": "base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=",
                "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:", "DB_URL": "", "DATABASE_URL": "",
                "CACHE_STORE": "array", "SESSION_DRIVER": "array", "QUEUE_CONNECTION": "sync",
                "TRANSACTIONAL_EMAIL_PROVIDER": "log", "MAIL_MAILER": "array", "BREVO_API_KEY": "",
                "AWS_ACCESS_KEY_ID": "", "AWS_SECRET_ACCESS_KEY": "", "COMPOSER_AUTH": "{}",
                "COMPOSER_HOME": str(sandbox / "composer-home"),
                "APP_CONFIG_CACHE": "bootstrap/cache/config.php", "APP_ROUTES_CACHE": "bootstrap/cache/routes.php",
                "APP_PACKAGES_CACHE": "bootstrap/cache/packages.php", "APP_SERVICES_CACHE": "bootstrap/cache/services.php",
            })
            commands = [
                ("Composer validate", composer_command + ["validate", "--no-check-publish"]),
                ("Locked production-only install", composer_command + ["install", "--no-dev", "--prefer-dist", "--no-interaction", "--no-progress"]),
                ("Production platform requirements", composer_command + ["check-platform-reqs", "--no-dev"]),
                ("Config cache", [php, "artisan", "config:cache"]),
                ("Route cache", [php, "artisan", "route:cache"]),
                ("View cache", [php, "artisan", "view:cache"]),
            ]
            probe = r'''require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            if (!$app->environment('testing') || config('database.default')!=='sqlite'
                || config('database.connections.sqlite.database')!==':memory:'
                || config('webinar.email.provider')!=='log' || filled(config('services.brevo.key'))) {
                throw new RuntimeException('Isolation check failed.');
            }
            $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
            foreach (['/'=>302,'/admin/login'=>200,'/admin'=>302,'/preparation-404'=>404] as $path=>$expected) {
                $request=Illuminate\Http\Request::create('https://preparation.example.test'.$path);
                $response=$kernel->handle($request);
                if($response->getStatusCode()!==$expected){throw new RuntimeException('HTTP failed: '.$path);}
                $kernel->terminate($request,$response);
            }
            echo 'Cached root/login/guest/404 pass; SQLite memory/log isolation intact.';'''
            commands.append(("Cached HTTP and isolation", [php, "-r", probe]))
            for label, command in commands:
                completed = subprocess.run(command, cwd=sandbox, env=environment, stdout=subprocess.PIPE,
                                           stderr=subprocess.STDOUT, text=True, encoding="utf-8", errors="replace", timeout=300)
                log.append(label + "\n" + completed.stdout)
                evidence["steps"].append({"step": label, "exit_code": completed.returncode})
                if completed.returncode:
                    raise RuntimeError("Rehearsal failed: " + label)
            if (sandbox / ".env").exists() or any((sandbox / name).exists() for name in ("vendor/phpunit", "vendor/laravel/pint", "vendor/laravel/sail")):
                raise RuntimeError("Unexpected runtime environment or development dependency in disposable install.")
            installed = json.loads((sandbox / "vendor/composer/installed.json").read_text(encoding="utf-8"))
            evidence["production_packages"] = len(installed["packages"])
            evidence["steps"].append({"step": "No .env or development vendor installed", "exit_code": 0})
            evidence["passed"] = True
    except Exception as error:
        evidence["error"] = str(error)
        raise
    finally:
        evidence["elapsed_seconds"] = round(time.monotonic() - started, 3)
        (archive.parent / "production-install-rehearsal.log").write_text("\n".join(log), encoding="utf-8")
        (archive.parent / "production-install-rehearsal.json").write_text(json.dumps(evidence, indent=2), encoding="utf-8")
    return evidence


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("archive", type=pathlib.Path)
    arguments = parser.parse_args()
    print(json.dumps(rehearse(arguments.archive), indent=2))
