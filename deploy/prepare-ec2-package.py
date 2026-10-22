#!/usr/bin/env python3
"""Build a code-only preparation ZIP. Never bootstrap Laravel or read live state."""

import argparse
import datetime as dt
import hashlib
import json
import pathlib
import re
import subprocess
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
TREES = ("app", "config", "database/migrations", "resources", "routes", "deploy", "public/build", "public/fonts")
ROOT_FILES = (
    "artisan", "composer.json", "composer.lock", "package.json", "package-lock.json", "vite.config.js",
    ".env.example", "bootstrap/app.php", "bootstrap/providers.php", "public/index.php", "public/robots.txt",
    "public/favicon.ico", "DEPLOYMENT_CHECKLIST.md", "PRODUCTION_READINESS_REVIEW.md", "SECURITY.md",
)
REQUIRED = (
    "artisan", "composer.json", "composer.lock", "bootstrap/app.php", "bootstrap/providers.php",
    "public/index.php", "public/build/manifest.json", "public/fonts/DejaVuSans.ttf",
    "deploy/ec2/runtime.env.example", "deploy/ec2/PREPARATION.md",
)
PLACEHOLDERS = (
    "bootstrap/cache/.gitignore", "storage/app/private/.gitignore", "storage/app/public/.gitignore",
    "storage/framework/cache/data/.gitignore", "storage/framework/sessions/.gitignore",
    "storage/framework/testing/.gitignore", "storage/framework/views/.gitignore", "storage/logs/.gitignore",
)
ENTRY_POINTS = ("resources/css/app.css", "resources/js/app.js", "resources/js/standalone.js")
SECRET_KEYS = {"APP_KEY", "DB_PASSWORD", "REDIS_PASSWORD", "BREVO_API_KEY", "AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY"}
IGNORED_PARTS = {"__pycache__", ".pytest_cache", ".git", "node_modules", "vendor"}
MANIFEST = "PREPARATION-MANIFEST.json"


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe_name(name):
    path = pathlib.PurePosixPath(name)
    if not name or "\\" in name or path.is_absolute() or any(p in ("..", ".") for p in path.parts) or ":" in name:
        raise ValueError("Unsafe archive path: " + name)
    if path.name in {".env", "auth.json", "runtime.env", "hot"} or (path.name.startswith(".env.") and path.name != ".env.example"):
        raise ValueError("Secret/runtime file is forbidden: " + name)
    if path.suffix.lower() in {".sqlite", ".db", ".pdf", ".pem", ".key", ".p12", ".pfx", ".csv", ".xlsx"}:
        raise ValueError("Data/key file is forbidden: " + name)
    allowed = (name in ROOT_FILES or name == MANIFEST or name in PLACEHOLDERS
               or any(name.startswith(tree + "/") for tree in TREES))
    if not allowed:
        raise ValueError("Path is outside the code allowlist: " + name)
    return name


def check_example(name, contents):
    if name not in (".env.example", "deploy/ec2/runtime.env.example"):
        return
    for line in contents.decode("utf-8-sig").splitlines():
        key, separator, value = line.partition("=")
        if separator and key.strip() in SECRET_KEYS and value.strip().strip("\"'"):
            raise ValueError("Secret field must be blank in packaged example: " + key.strip())


def check_assets(files):
    manifest = json.loads(files["public/build/manifest.json"])
    if any(entry not in manifest for entry in ENTRY_POINTS):
        raise ValueError("The built Vite manifest is missing an application entry point.")
    for entry in manifest.values():
        for key in entry.get("imports", []) + entry.get("dynamicImports", []):
            if key not in manifest:
                raise ValueError("The built manifest has a missing imported chunk.")
        for asset in [entry["file"]] + entry.get("css", []) + entry.get("assets", []):
            name = safe_name("public/build/" + asset)
            if name not in files:
                raise ValueError("A built asset is missing: " + name)


def inventory(root):
    paths = set()
    for tree in TREES:
        directory = root / tree
        if directory.is_symlink():
            raise ValueError("Symlinked source tree is forbidden: " + tree)
        if directory.exists():
            paths.update(p for p in directory.rglob("*") if (p.is_file() or p.is_symlink())
                         and not IGNORED_PARTS.intersection(p.relative_to(root).parts))
    paths.update(root / name for name in ROOT_FILES if (root / name).exists())
    files = {}
    for path in sorted(paths):
        relative = path.relative_to(root).as_posix()
        safe_name(relative)
        if path.is_symlink() or any(parent.is_symlink() for parent in path.parents if parent != root.parent):
            raise ValueError("Symlinked source file is forbidden: " + relative)
        contents = path.read_bytes()
        check_example(relative, contents)
        files[relative] = contents
    for name in PLACEHOLDERS:
        files[name] = b"*\n!.gitignore\n"
    missing = set(REQUIRED) - set(files)
    if missing:
        raise ValueError("Required code/build files missing: " + ", ".join(sorted(missing)))
    check_assets(files)
    return files


def git_state(root):
    def run(*arguments):
        try:
            return subprocess.check_output(["git", "-C", str(root), *arguments], stderr=subprocess.DEVNULL).decode().strip()
        except (OSError, subprocess.CalledProcessError):
            return None
    status = run("status", "--porcelain")
    return {"head": run("rev-parse", "HEAD"), "dirty": None if status is None else bool(status)}


def verify(archive):
    with zipfile.ZipFile(archive) as package:
        names = package.namelist()
        if len(names) != len(set(names)):
            raise ValueError("Duplicate ZIP paths are forbidden.")
        for item in package.infolist():
            safe_name(item.filename)
            if (item.external_attr >> 16) & 0o170000 == 0o120000:
                raise ValueError("Symlink ZIP entries are forbidden.")
        metadata = json.loads(package.read(MANIFEST))
        if metadata.get("production_approved") is not False or metadata.get("contains_real_data") is not False:
            raise ValueError("Package must explicitly be unapproved preparation without real data.")
        expected = metadata["files"]
        if set(names) != set(expected) | {MANIFEST} or not set(REQUIRED).issubset(expected):
            raise ValueError("Archive inventory does not match the declared code files.")
        files = {}
        for name, fingerprint in expected.items():
            contents = package.read(name)
            if digest(contents) != fingerprint["sha256"] or len(contents) != fingerprint["bytes"]:
                raise ValueError("Archive checksum mismatch: " + name)
            check_example(name, contents)
            files[name] = contents
        check_assets(files)
        return {"files": len(expected), "sha256": digest(pathlib.Path(archive).read_bytes()),
                "source_digest": metadata["source_digest"], "production_approved": False, "contains_real_data": False}


def build(root, output_directory, release_id):
    root, output_directory = pathlib.Path(root).resolve(), pathlib.Path(output_directory).resolve()
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,79}", release_id):
        raise ValueError("Release ID must be a simple filename token.")
    if output_directory.is_relative_to(root) or not output_directory.is_relative_to(root.parent):
        raise ValueError("Output must be outside the app and within its parent workspace.")
    files = inventory(root)
    fingerprints = {name: {"sha256": digest(contents), "bytes": len(contents)} for name, contents in sorted(files.items())}
    source_digest = digest(json.dumps(fingerprints, sort_keys=True).encode())
    metadata = {"kind": "ec2-code-preparation", "release_id": release_id, "created_at_utc": dt.datetime.now(dt.timezone.utc).isoformat(),
                "production_approved": False, "contains_real_data": False, "git": git_state(root),
                "source_digest": source_digest, "files": fingerprints,
                "not_included": ["runtime secrets", "databases", "uploads", "certificates", "logs/cache/sessions", "vendor/node_modules"],
                "remaining_gates": ["eligible AWS Free plan", "clean approved release", "production dependency install", "PostgreSQL/Redis", "restore", "TLS/workers", "authorized mail", "production smoke"]}
    output_directory.mkdir(parents=True, exist_ok=True)
    archive = output_directory / ("webinar-ec2-preparation-" + release_id + ".zip")
    # Exclusive creation never overwrites a previous package or existing state.
    with zipfile.ZipFile(archive, "x", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as package:
        for name, contents in sorted(files.items()):
            entry = zipfile.ZipInfo(name)
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = (0o100755 if name.endswith(".sh") else 0o100644) << 16
            package.writestr(entry, contents)
        package.writestr(MANIFEST, json.dumps(metadata, indent=2))
    # Recheck the live SOURCE allowlist only; never inspect any private state.
    if inventory(root) != files:
        raise ValueError("Source changed while packaging; do not use this archive.")
    result = verify(archive)
    # GNU sha256sum on EC2 treats a Windows CR as part of the filename.
    (output_directory / (archive.name + ".sha256")).write_text(result["sha256"] + "  " + archive.name + "\n", encoding="utf-8", newline="\n")
    (output_directory / (archive.stem + ".json")).write_text(json.dumps(metadata, indent=2), encoding="utf-8")
    return {"archive": str(archive), **result}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--verify", type=pathlib.Path)
    parser.add_argument("--output-directory", type=pathlib.Path, default=ROOT.parent / "deployment-preparation")
    parser.add_argument("--release-id", default=dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ"))
    arguments = parser.parse_args()
    try:
        result = verify(arguments.verify) if arguments.verify else build(ROOT, arguments.output_directory, arguments.release_id)
        print(json.dumps(result, indent=2))
    except (OSError, ValueError, KeyError, zipfile.BadZipFile) as error:
        parser.exit(1, "Preparation failed: " + str(error) + "\n")


if __name__ == "__main__":
    main()
