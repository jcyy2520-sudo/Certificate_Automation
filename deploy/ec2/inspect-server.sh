#!/usr/bin/env bash
# Read-only prerequisites only: no app bootstrap, secrets, DB connection,
# package installation, migration, worker, scheduler, or service mutation.
set -uo pipefail
failures=0
require_command() {
    if command -v "$1" >/dev/null 2>&1; then
        printf 'PASS command %s\n' "$1"
    else
        printf 'FAIL command %s is missing\n' "$1"
        failures=$((failures + 1))
    fi
}
for executable in php php-fpm8.3 composer psql pg_dump redis-cli apache2ctl systemctl unzip; do
    require_command "$executable"
done
if command -v php >/dev/null 2>&1; then
    php -r '
        $missing = [];
        foreach (["pdo_pgsql", "gd", "mbstring", "openssl", "fileinfo", "intl", "bcmath", "zip", "dom", "xmlreader", "redis", "pcntl"] as $extension) {
            if (!extension_loaded($extension)) { $missing[] = $extension; }
        }
        printf("PHP CLI %s\n", PHP_VERSION);
        if (PHP_VERSION_ID < 80300 || $missing) {
            printf("FAIL CLI PHP 8.3+ extensions; missing: %s\n", implode(", ", $missing));
            exit(1);
        }
        echo "PASS CLI PHP version/extensions\n";
    ' || failures=$((failures + 1))
fi
if command -v php-fpm8.3 >/dev/null 2>&1; then
    # Module inventory only; this does not start FPM or inspect runtime.env.
    fpm_modules="$(php-fpm8.3 -m 2>/dev/null)"
    for extension in pdo_pgsql gd mbstring openssl fileinfo intl bcmath zip dom xmlreader redis; do
        if printf '%s\n' "$fpm_modules" | grep -Fxiq -- "$extension"; then
            printf 'PASS FPM extension %s\n' "$extension"
        else
            printf 'FAIL FPM extension %s\n' "$extension"
            failures=$((failures + 1))
        fi
    done
fi
printf '\nResource observations (not a capacity certification):\n'
command -v free >/dev/null 2>&1 && free -m
command -v df >/dev/null 2>&1 && df -h /
printf '\nNo application, database, provider or service action was run.\n'
printf '%s prerequisite failure(s). Production acceptance still requires the runbook.\n' "$failures"
if (( failures > 0 )); then exit 1; fi
