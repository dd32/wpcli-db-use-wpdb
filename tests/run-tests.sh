#!/usr/bin/env bash
# Integration tests for the wpdb-backed wp db fallback.
#
# Prerequisites: wp-env must already be running.
#   npm run env:start
#   npm test

set -euo pipefail

PASS=0
FAIL=0
TABLE="wp_cli_db_use_wpdb_test"

WP() {
    npx wp-env run cli env WP_CLI_DB_USE_WPDB=always wp "$@" 2>&1 \
        | sed -e '/^ℹ Starting /d' -e 's/✔ Ran `.*$//'
}

check() {
    local name="$1"
    local expected="$2"
    local actual="$3"

    if echo "$actual" | grep -qF -- "$expected"; then
        echo "✓  $name"
        PASS=$(( PASS + 1 ))
    else
        echo "✗  $name"
        echo "   expected to contain: $expected"
        printf '   actual output:\n'
        echo "$actual" | sed 's/^/     /'
        FAIL=$(( FAIL + 1 ))
    fi
}

not_check() {
    local name="$1"
    local unexpected="$2"
    local actual="$3"

    if echo "$actual" | grep -qF -- "$unexpected"; then
        echo "✗  $name"
        echo "   expected NOT to contain: $unexpected"
        printf '   actual output:\n'
        echo "$actual" | sed 's/^/     /'
        FAIL=$(( FAIL + 1 ))
    else
        echo "✓  $name"
        PASS=$(( PASS + 1 ))
    fi
}

check_line() {
    local name="$1"
    local expected="$2"
    local actual="$3"

    if echo "$actual" | grep -qxF -- "$expected"; then
        echo "✓  $name"
        PASS=$(( PASS + 1 ))
    else
        echo "✗  $name"
        echo "   expected line: $expected"
        printf '   actual output:\n'
        echo "$actual" | sed 's/^/     /'
        FAIL=$(( FAIL + 1 ))
    fi
}

cleanup() {
    WP db query "DROP TABLE IF EXISTS ${TABLE}" --skip-column-names >/dev/null || true
}

trap cleanup EXIT

echo ""
echo "=== wp db use-wpdb integration tests ==="
echo ""

out=$(WP help db || true)
check "fallback db command is registered" "Performs database operations through wpdb" "$out"
check "fallback runs before full WordPress bootstrap" "before_wp_load" "$out"

out=$(WP help db query || true)
check "query help shows compatibility passthrough" "--<field>=<value>" "$out"
check "query help shows batch option" "--batch" "$out"

out=$(WP db prefix || true)
check_line "prefix reads table prefix from wp-config.php" "wp_" "$out"

cleanup

out=$(WP db query "CREATE TABLE ${TABLE} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, label VARCHAR(64) NOT NULL, PRIMARY KEY (id))" || true)
not_check "create table has no SQL error" "Error:" "$out"

out=$(WP db query "INSERT INTO ${TABLE} (label) VALUES ('alpha'), ('beta')" || true)
not_check "insert rows has no SQL error" "Error:" "$out"

out=$(WP db query "SELECT COUNT(*) FROM ${TABLE}" --skip-column-names || true)
check_line "select count returns inserted rows" "2" "$out"

out=$(WP db tables "${TABLE}" || true)
check_line "tables command finds created table" "${TABLE}" "$out"

out=$(WP db tables --all-tables-with-prefix --format=csv || true)
check "tables csv includes created table" "${TABLE}" "$out"

out=$(WP db columns "${TABLE}" --format=json || true)
check "columns command returns id column" "\"Field\":\"id\"" "$out"
check "columns command returns label column" "\"Field\":\"label\"" "$out"

out=$(WP db export - --tables="${TABLE}" --no-data --single-transaction --quick || true)
check "export stdout contains create table" "CREATE TABLE" "$out"
check "export stdout contains table name" "${TABLE}" "$out"

out=$(WP db search alpha "${TABLE}" --format=count || true)
check_line "search finds inserted value" "1" "$out"

cleanup

echo ""
echo "Results: ${PASS} passed, ${FAIL} failed."
echo ""

[ "$FAIL" -eq 0 ]
