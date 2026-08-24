#!/usr/bin/env bash
set -Eeuo pipefail

required=(SOURCE_PGSERVICE RESTORE_PGSERVICE RESTORE_CONFIRM_DATABASE RESTORE_REFERENCE PGSSLROOTCERT)

for name in "${required[@]}"; do
    if [[ -z "${!name:-}" ]]; then
        echo "Missing required environment variable: ${name}" >&2
        exit 2
    fi
done

if [[ ! "$SOURCE_PGSERVICE" =~ ^[A-Za-z0-9_.-]+$ || ! "$RESTORE_PGSERVICE" =~ ^[A-Za-z0-9_.-]+$ ]]; then
    echo 'PGSERVICE names may contain only letters, numbers, dots, underscores, and hyphens.' >&2
    exit 2
fi

if [[ ! "$RESTORE_REFERENCE" =~ ^[A-Za-z0-9._:/-]+$ ]]; then
    echo 'RESTORE_REFERENCE must be a ticket/reference identifier without whitespace.' >&2
    exit 2
fi

if [[ ! -r "$PGSSLROOTCERT" ]]; then
    echo 'PGSSLROOTCERT must point to a readable provider CA bundle.' >&2
    exit 2
fi

if [[ ! "$RESTORE_CONFIRM_DATABASE" =~ ^restore_drill_[A-Za-z0-9_]+$ ]]; then
    echo 'RESTORE_CONFIRM_DATABASE must use the restore_drill_ prefix.' >&2
    exit 2
fi

command -v psql >/dev/null || { echo 'psql is required.' >&2; exit 2; }
command -v sha256sum >/dev/null || { echo 'sha256sum is required.' >&2; exit 2; }

source_conn="service=${SOURCE_PGSERVICE} sslmode=verify-full sslrootcert=${PGSSLROOTCERT}"
restore_conn="service=${RESTORE_PGSERVICE} sslmode=verify-full sslrootcert=${PGSSLROOTCERT}"

query() {
    local connection="$1"
    local sql="$2"
    psql "$connection" --no-psqlrc --set=ON_ERROR_STOP=1 --tuples-only --no-align --command="$sql"
}

source_identity="$(query "$source_conn" "select current_database() || '|' || coalesce(inet_server_addr()::text, '') || '|' || inet_server_port();")"
restore_identity="$(query "$restore_conn" "select current_database() || '|' || coalesce(inet_server_addr()::text, '') || '|' || inet_server_port();")"
restore_database="$(query "$restore_conn" 'select current_database();')"

if [[ "$source_identity" == "$restore_identity" ]]; then
    echo 'The restore target resolves to the source database; aborting.' >&2
    exit 3
fi

if [[ "$restore_database" != "$RESTORE_CONFIRM_DATABASE" ]]; then
    echo 'RESTORE_CONFIRM_DATABASE does not match the connected restore target.' >&2
    exit 3
fi

for connection in "$source_conn" "$restore_conn"; do
    tls="$(query "$connection" 'select ssl::text from pg_stat_ssl where pid = pg_backend_pid();')"
    if [[ "$tls" != 'true' ]]; then
        echo 'A PostgreSQL connection was not protected by TLS.' >&2
        exit 4
    fi
done

required_tables=(migrations users webinars forms participants submissions certificates jobs)
for table in "${required_tables[@]}"; do
    exists="$(query "$restore_conn" "select to_regclass('public.${table}') is not null;")"
    if [[ "$exists" != 't' ]]; then
        echo "Restored database is missing public.${table}." >&2
        exit 5
    fi

    source_count="$(query "$source_conn" "select count(*) from public.${table};")"
    restore_count="$(query "$restore_conn" "select count(*) from public.${table};")"
    if [[ "$source_count" != "$restore_count" ]]; then
        echo "Restored row count differs for public.${table}." >&2
        exit 5
    fi
done

migration_sql="select migration from migrations order by migration"
source_migrations="$(query "$source_conn" "$migration_sql")"
restore_migrations="$(query "$restore_conn" "$migration_sql")"

if [[ "$source_migrations" != "$restore_migrations" ]]; then
    echo 'The restored migration ledger does not match production.' >&2
    exit 6
fi

schema_sql="select table_name || '|' || ordinal_position || '|' || column_name || '|' || data_type || '|' || is_nullable from information_schema.columns where table_schema = 'public' order by table_name, ordinal_position"
source_schema_hash="$(query "$source_conn" "$schema_sql" | sha256sum | cut -d' ' -f1)"
restore_schema_hash="$(query "$restore_conn" "$schema_sql" | sha256sum | cut -d' ' -f1)"

if [[ "$source_schema_hash" != "$restore_schema_hash" ]]; then
    echo 'The restored public schema does not match production.' >&2
    exit 7
fi

verified_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
echo 'Restore rehearsal verified successfully.'
echo "BACKUP_LAST_RESTORE_AT=${verified_at}"
echo "BACKUP_RESTORE_REFERENCE=${RESTORE_REFERENCE}"
echo "Schema fingerprint: ${restore_schema_hash}"
