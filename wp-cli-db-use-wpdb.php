<?php
/**
 * Plugin Name: WP-CLI DB Use WPDB
 * Description: Runs WP-CLI db commands through wpdb when MySQL client tooling is unavailable.
 * Version: 0.1.0
 * Author: dd32
 * License: MIT
 */

/**
 * WP-CLI DB Use WPDB - entry point.
 *
 * Loaded automatically by WP-CLI when this package is installed via Composer,
 * or explicitly via `--require` / a `wp-cli.yml` require entry.
 *
 * @package dd32/wpcli-db-use-wpdb
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

require_once __DIR__ . '/src/DB_Use_WPDB_Command.php';

if ( WP_CLI_DB_Use_WPDB_Command::should_register() ) {
	WP_CLI_DB_Use_WPDB_Command::register_when_ready();
}
