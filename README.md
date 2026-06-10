# WP-CLI DB Use WPDB

This WP-CLI package replaces `wp db ...` with a fallback implementation that uses WordPress' `wpdb` instead of shelling out to `mysql`, `mariadb`, `mysqldump`, or `mysqlcheck`.

## Install

From this directory:

```sh
wp package install .
```

For one-off usage without installing:

```sh
WP_CLI_DB_USE_WPDB=always wp --require=/path/to/wp-cli-db-use-wpdb.php db query 'SELECT 1'
```

## Activation Mode

By default, the package auto-registers only when one of the standard MySQL client tool groups is missing:

- `mysql` or `mariadb`
- `mysqldump` or `mariadb-dump`
- `mysqlcheck` or `mariadb-check`

Override that behavior with `WP_CLI_DB_USE_WPDB`:

- `always`, `1`, `true`, `yes`, `on`: always replace `wp db`
- `never`, `0`, `false`, `no`, `off`: never replace `wp db`
- unset: auto-detect missing client tools

## Supported Commands

The fallback implements the standard `wp db` subcommands: `check`, `clean`, `cli`, `columns`, `create`, `drop`, `export`, `import`, `optimize`, `prefix`, `query`, `repair`, `reset`, `search`, `size`, and `tables`.

The implementation supports the common WP-CLI flags for table selection, basic formatting, import/export, and destructive confirmations. Arbitrary MySQL client flags are accepted by WP-CLI's parser but may be ignored because no external client process is launched.

The commands run before full WordPress bootstrap. The package uses WP-CLI's wp-config loader to read database constants and `$table_prefix` without including `wp-settings.php`, then loads only the minimal WordPress files needed for `wpdb`.

## Requirements and Limits

This package requires the PHP `mysqli` extension, because `wpdb` itself requires a MySQL-compatible PHP database driver.

Exports are generated in SQL using PHP. They are suitable for normal WordPress tables, but they are not a byte-for-byte replacement for `mysqldump` and may not preserve every server-specific dump option, routine, trigger, event, or tablespace feature.

The `db cli` command provides a small SQL prompt backed by `wpdb`; it is not the full interactive MySQL client.

## Tests

The integration tests run inside `wp-env` and force the fallback with `WP_CLI_DB_USE_WPDB=always`.

```sh
npm install
npm run env:start
npm test
npm run env:stop
```
