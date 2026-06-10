<?php
/**
 * wpdb-backed replacement for the bundled WP-CLI db command.
 */

use WP_CLI\Utils;

/**
 * Performs database operations through wpdb.
 *
 * This command is intended as a fallback for hosts that provide PHP database
 * access but do not provide mysql, mariadb, mysqldump, or mysqlcheck binaries.
 *
 * @when before_wp_load
 */
class WP_CLI_DB_Use_WPDB_Command {
	const ENV_MODE = 'WP_CLI_DB_USE_WPDB';

	/** @var bool */
	private static $registered = false;

	/** @var wpdb[] */
	private $wpdb_instances = array();

	/**
	 * Decide whether this package should replace the bundled db command.
	 *
	 * Set WP_CLI_DB_USE_WPDB=always to force the fallback, or
	 * WP_CLI_DB_USE_WPDB=never to keep the bundled command.
	 *
	 * @return bool
	 */
	public static function should_register() {
		$mode = strtolower( (string) getenv( self::ENV_MODE ) );

		if ( in_array( $mode, array( '1', 'true', 'yes', 'on', 'always' ), true ) ) {
			return true;
		}

		if ( in_array( $mode, array( '0', 'false', 'no', 'off', 'never' ), true ) ) {
			return false;
		}

		return ! (
			self::binary_exists( array( 'mysql', 'mariadb' ) )
			&& self::binary_exists( array( 'mysqldump', 'mariadb-dump' ) )
			&& self::binary_exists( array( 'mysqlcheck', 'mariadb-check' ) )
		);
	}

	/**
	 * Register the fallback after WP-CLI's bundled db command is present.
	 */
	public static function register_when_ready() {
		WP_CLI::add_hook( 'after_add_command:db', array( __CLASS__, 'register_command' ) );
	}

	/**
	 * Register the command, guarding against after_add_command recursion.
	 */
	public static function register_command() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		WP_CLI::add_command(
			'db',
			__CLASS__,
			array(
				'when'      => 'before_wp_load',
				'shortdesc' => 'Performs database operations through wpdb.',
				'longdesc'  => 'Provides a wpdb-backed fallback for the standard `wp db` commands when external MySQL client tools are unavailable.',
			)
		);

		foreach ( self::subcommands() as $subcommand ) {
			WP_CLI::add_command(
				'db ' . str_replace( '_', '-', $subcommand ),
				array( __CLASS__, $subcommand ),
				array(
					'when' => 'before_wp_load',
				)
			);
		}

		self::prune_conflicting_early_invokes();
	}

	/**
	 * Subcommands that need explicit early invocation registration.
	 *
	 * @return string[]
	 */
	private static function subcommands() {
		return array(
			'check',
			'clean',
			'cli',
			'columns',
			'create',
			'drop',
			'export',
			'import',
			'optimize',
			'prefix',
			'query',
			'repair',
			'reset',
			'search',
			'size',
			'tables',
		);
	}

	/**
	 * Remove built-in db early invoke registrations so the fallback hook wins.
	 */
	private static function prune_conflicting_early_invokes() {
		$runner = WP_CLI::get_runner();
		$ref    = new ReflectionObject( $runner );

		if ( ! $ref->hasProperty( 'early_invoke' ) ) {
			return;
		}

		$property = $ref->getProperty( 'early_invoke' );
		$property->setAccessible( true );
		$early_invoke = $property->getValue( $runner );

		$subcommands = array_flip( self::subcommands() );
		$aliases     = array(
			'connect' => true,
			'dump'    => true,
		);

		foreach ( $early_invoke as $hook => $paths ) {
			if ( 'before_wp_load' === $hook ) {
				continue;
			}

			foreach ( $paths as $index => $path ) {
				if ( empty( $path ) || 'db' !== $path[0] ) {
					continue;
				}

				if ( 1 === count( $path ) || isset( $subcommands[ str_replace( '-', '_', $path[1] ) ] ) || isset( $aliases[ $path[1] ] ) ) {
					unset( $early_invoke[ $hook ][ $index ] );
				}
			}

			$early_invoke[ $hook ] = array_values( $early_invoke[ $hook ] );
		}

		$property->setValue( $runner, $early_invoke );
	}

	/**
	 * Check whether any binary in a group exists on PATH.
	 *
	 * @param string[] $names Binary names.
	 * @return bool
	 */
	private static function binary_exists( $names ) {
		$path = getenv( 'PATH' );
		if ( false === $path || '' === $path ) {
			return false;
		}

		$extensions = array( '' );
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$pathext    = getenv( 'PATHEXT' );
			$extensions = $pathext ? explode( PATH_SEPARATOR, strtolower( $pathext ) ) : array( '.exe', '.bat', '.cmd' );
		}

		foreach ( explode( PATH_SEPARATOR, $path ) as $dir ) {
			if ( '' === $dir ) {
				continue;
			}

			foreach ( $names as $name ) {
				foreach ( $extensions as $extension ) {
					$candidate = rtrim( $dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $name . $extension;
					if ( is_file( $candidate ) && is_executable( $candidate ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Executes a SQL query against the database.
	 *
	 * ## OPTIONS
	 *
	 * [<sql>]
	 * : A SQL query. If not passed, the query is read from STDIN.
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--skip-column-names]
	 * : Output row values without column headers.
	 *
	 * [--silent]
	 * : Output tab-separated row values.
	 *
	 * [--batch]
	 * : Output tab-separated row values.
	 *
	 * [--raw]
	 * : Accepted for compatibility. Output is not additionally escaped.
	 *
	 * [--database=<database>]
	 * : Use a specific database. Defaults to DB_NAME.
	 *
	 * [--default-character-set=<character-set>]
	 * : Use a specific character set. Defaults to DB_CHARSET when defined.
	 *
	 * [--<field>=<value>]
	 * : Accepted for compatibility with the bundled db command. Unsupported client-only options are ignored.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 */
	public function query( $args, $assoc_args ) {
		$sql = isset( $args[0] ) ? $args[0] : stream_get_contents( STDIN );
		if ( '' === trim( (string) $sql ) ) {
			WP_CLI::error( 'No SQL query provided.' );
		}

		$this->load_wp_config();

		$wpdb      = $this->get_wpdb( $assoc_args, $this->assoc( $assoc_args, 'database', DB_NAME ) );
		$printed   = false;
		$statement = $this->statement_iterator_from_string( $sql );

		foreach ( $statement as $single_statement ) {
			$rows = $this->execute_sql( $wpdb, $single_statement, $assoc_args );
			if ( is_array( $rows ) ) {
				$this->render_query_rows( $rows, $assoc_args );
				$printed = true;
			}
		}

		if ( $printed ) {
			return;
		}
	}

	/**
	 * Opens a small wpdb SQL console.
	 *
	 * ## OPTIONS
	 *
	 * [--database=<database>]
	 * : Use a specific database. Defaults to DB_NAME.
	 *
	 * [--default-character-set=<character-set>]
	 * : Use a specific character set. Defaults to DB_CHARSET when defined.
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--<field>=<value>]
	 * : Accepted for compatibility with the bundled db command. Unsupported client-only options are ignored.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 *
	 * @alias connect
	 */
	public function cli( $args, $assoc_args ) {
		$this->load_wp_config();

		$wpdb  = $this->get_wpdb( $assoc_args, $this->assoc( $assoc_args, 'database', DB_NAME ) );
		$chunk = '';

		WP_CLI::line( 'wpdb SQL console. End statements with ;. Use quit or exit to leave.' );

		while ( true ) {
			$prompt = '' === $chunk ? 'wpdb> ' : '    > ';
			$line   = function_exists( 'readline' ) ? readline( $prompt ) : $this->readline_fallback( $prompt );

			if ( false === $line ) {
				break;
			}

			if ( '' === trim( $chunk ) && preg_match( '/^\s*(quit|exit)\s*;?\s*$/i', $line ) ) {
				break;
			}

			$chunk .= $line . "\n";
			$statements = iterator_to_array( $this->statement_iterator_from_string( $chunk ) );

			if ( empty( $statements ) || ! $this->sql_ends_with_delimiter( $chunk, ';' ) ) {
				continue;
			}

			foreach ( $statements as $statement ) {
				$rows = $this->execute_sql( $wpdb, $statement, $assoc_args );
				if ( is_array( $rows ) ) {
					$this->render_query_rows( $rows, $assoc_args );
				} else {
					WP_CLI::line( 'Query OK, ' . (int) $wpdb->rows_affected . ' row(s) affected.' );
				}
			}

			$chunk = '';
		}
	}

	/**
	 * Lists the database tables.
	 *
	 * ## OPTIONS
	 *
	 * [<table>...]
	 * : Table wildcard filters, for example 'wp_*_options' or 'wp_post?'.
	 *
	 * [--scope=<scope>]
	 * : Can be all, global, ms_global, blog, or old tables. Defaults to all.
	 *
	 * [--network]
	 * : List all registered multisite tables when possible.
	 *
	 * [--all-tables-with-prefix]
	 * : List all tables matching the current table prefix.
	 *
	 * [--all-tables]
	 * : List all tables in the database.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: list
	 * options:
	 *   - list
	 *   - csv
	 * ---
	 */
	public function tables( $args, $assoc_args ) {
		$tables = $this->get_tables( $assoc_args, $args );
		$format = $this->assoc( $assoc_args, 'format', 'list' );

		if ( 'csv' === $format ) {
			WP_CLI::line( implode( ',', $tables ) );
			return;
		}

		foreach ( $tables as $table ) {
			WP_CLI::line( $table );
		}
	}

	/**
	 * Displays information about a given table.
	 *
	 * ## OPTIONS
	 *
	 * <table>
	 * : Name of the database table.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 */
	public function columns( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please provide a table name.' );
		}

		$wpdb = $this->get_wpdb( $assoc_args );
		$rows  = $this->get_results( $wpdb, 'DESCRIBE ' . $this->quote_qualified_identifier( $args[0] ) );

		Utils\format_items(
			$this->assoc( $assoc_args, 'format', 'table' ),
			$rows,
			array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' )
		);
	}

	/**
	 * Displays the database table prefix.
	 */
	public function prefix( $args, $assoc_args ) {
		$this->load_wp_config();

		global $table_prefix;
		WP_CLI::line( isset( $table_prefix ) ? $table_prefix : '' );
	}

	/**
	 * Creates a new database.
	 *
	 * ## OPTIONS
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 */
	public function create( $args, $assoc_args ) {
		$mysqli = $this->connect_mysqli( $assoc_args );
		$sql    = 'CREATE DATABASE ' . $this->quote_identifier( DB_NAME );

		if ( defined( 'DB_CHARSET' ) && DB_CHARSET ) {
			$sql .= ' DEFAULT CHARACTER SET ' . $this->quote_sql_keyword( DB_CHARSET );
		}

		if ( defined( 'DB_COLLATE' ) && DB_COLLATE ) {
			$sql .= ' COLLATE ' . $this->quote_sql_keyword( DB_COLLATE );
		}

		$this->mysqli_query_or_error( $mysqli, $sql );
		mysqli_close( $mysqli );
		WP_CLI::success( 'Database created.' );
	}

	/**
	 * Deletes the existing database.
	 *
	 * ## OPTIONS
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 */
	public function drop( $args, $assoc_args ) {
		WP_CLI::confirm( 'Are you sure you want to drop the database?', $assoc_args );

		$mysqli = $this->connect_mysqli( $assoc_args );
		$this->mysqli_query_or_error( $mysqli, 'DROP DATABASE ' . $this->quote_identifier( DB_NAME ) );
		mysqli_close( $mysqli );
		WP_CLI::success( 'Database dropped.' );
	}

	/**
	 * Removes all tables from the database.
	 *
	 * ## OPTIONS
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 */
	public function reset( $args, $assoc_args ) {
		WP_CLI::confirm( 'Are you sure you want to reset the database?', $assoc_args );

		$mysqli = $this->connect_mysqli( $assoc_args );
		$this->mysqli_query_or_error( $mysqli, 'DROP DATABASE ' . $this->quote_identifier( DB_NAME ) );

		$sql = 'CREATE DATABASE ' . $this->quote_identifier( DB_NAME );
		if ( defined( 'DB_CHARSET' ) && DB_CHARSET ) {
			$sql .= ' DEFAULT CHARACTER SET ' . $this->quote_sql_keyword( DB_CHARSET );
		}
		if ( defined( 'DB_COLLATE' ) && DB_COLLATE ) {
			$sql .= ' COLLATE ' . $this->quote_sql_keyword( DB_COLLATE );
		}

		$this->mysqli_query_or_error( $mysqli, $sql );
		mysqli_close( $mysqli );
		WP_CLI::success( 'Database reset.' );
	}

	/**
	 * Removes all tables with the current table prefix from the database.
	 *
	 * ## OPTIONS
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 */
	public function clean( $args, $assoc_args ) {
		WP_CLI::confirm( 'Are you sure you want to drop all tables matching the current prefix?', $assoc_args );

		$wpdb  = $this->get_wpdb( $assoc_args );
		$tables = $this->get_tables(
			array_merge(
				$assoc_args,
				array(
					'all-tables-with-prefix' => true,
				)
			)
		);

		$this->drop_tables( $wpdb, $tables );
		WP_CLI::success( 'Tables dropped.' );
	}

	/**
	 * Checks the current status of the database.
	 *
	 * ## OPTIONS
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--<field>=<value>]
	 * : Accepted for compatibility with the bundled db command. Unsupported client-only options are ignored.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 */
	public function check( $args, $assoc_args ) {
		$this->run_table_maintenance( 'CHECK', 'Database checked.', $assoc_args );
	}

	/**
	 * Repairs the database.
	 *
	 * ## OPTIONS
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--<field>=<value>]
	 * : Accepted for compatibility with the bundled db command. Unsupported client-only options are ignored.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 */
	public function repair( $args, $assoc_args ) {
		$this->run_table_maintenance( 'REPAIR', 'Database repaired.', $assoc_args );
	}

	/**
	 * Optimizes the database.
	 *
	 * ## OPTIONS
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--<field>=<value>]
	 * : Accepted for compatibility with the bundled db command. Unsupported client-only options are ignored.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 */
	public function optimize( $args, $assoc_args ) {
		$this->run_table_maintenance( 'OPTIMIZE', 'Database optimized.', $assoc_args );
	}

	/**
	 * Displays the database name and size.
	 *
	 * ## OPTIONS
	 *
	 * [--size_format=<format>]
	 * : Display the size only, as a bare number.
	 *
	 * [--tables]
	 * : Display each table name and size.
	 *
	 * [--human-readable]
	 * : Display sizes in human readable format.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 *
	 * [--scope=<scope>]
	 * : Can be all, global, ms_global, blog, or old tables. Defaults to all.
	 *
	 * [--network]
	 * : Include all registered multisite tables when possible.
	 *
	 * [--all-tables-with-prefix]
	 * : Include all tables matching the current table prefix.
	 *
	 * [--all-tables]
	 * : Include all tables in the database.
	 *
	 * [--decimals=<decimals>]
	 * : Number of digits after decimal point. Defaults to 0.
	 *
	 * [--order=<order>]
	 * : asc or desc. Defaults to asc.
	 *
	 * [--orderby=<orderby>]
	 * : name or size. Defaults to name.
	 */
	public function size( $args, $assoc_args ) {
		$wpdb    = $this->get_wpdb( $assoc_args );
		$decimals = (int) $this->assoc( $assoc_args, 'decimals', 0 );

		if ( $this->flag( $assoc_args, 'tables' ) ) {
			$names = $this->get_tables( $assoc_args );
			$rows  = $this->get_table_sizes( $wpdb, $names );
		} else {
			$total = 0;
			foreach ( $this->get_table_sizes( $wpdb, $this->all_database_tables( $wpdb ) ) as $row ) {
				$total += (int) $row['Bytes'];
			}

			$rows = array(
				array(
					'Name'  => DB_NAME,
					'Bytes' => $total,
				),
			);
		}

		$orderby = $this->assoc( $assoc_args, 'orderby', 'name' );
		$order   = strtolower( $this->assoc( $assoc_args, 'order', 'asc' ) );
		usort(
			$rows,
			function ( $a, $b ) use ( $orderby, $order ) {
				$field = 'size' === $orderby ? 'Bytes' : 'Name';
				$cmp   = 'Bytes' === $field ? ( $a[ $field ] <=> $b[ $field ] ) : strcmp( $a[ $field ], $b[ $field ] );
				return 'desc' === $order ? - $cmp : $cmp;
			}
		);

		if ( isset( $assoc_args['size_format'] ) ) {
			$total = 0;
			foreach ( $rows as $row ) {
				$total += (int) $row['Bytes'];
			}
			WP_CLI::line( $this->format_size_number( $total, $assoc_args['size_format'], $decimals ) );
			return;
		}

		foreach ( $rows as &$row ) {
			$row['Size'] = $this->human_size( (int) $row['Bytes'], $decimals );
			unset( $row['Bytes'] );
		}
		unset( $row );

		Utils\format_items( $this->assoc( $assoc_args, 'format', 'table' ), $rows, array( 'Name', 'Size' ) );
	}

	/**
	 * Exports the database to a file or to STDOUT.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : SQL file to export. If '-', outputs to STDOUT.
	 *
	 * [--tables=<tables>]
	 * : Comma-separated list of tables to export. Wildcards are supported.
	 *
	 * [--exclude_tables=<tables>]
	 * : Comma-separated list of tables to skip. Wildcards are supported.
	 *
	 * [--porcelain]
	 * : Output filename for the exported database.
	 *
	 * [--add-drop-table]
	 * : Include DROP TABLE IF EXISTS before each CREATE TABLE.
	 *
	 * [--no-create-info]
	 * : Do not include CREATE TABLE statements.
	 *
	 * [--no-data]
	 * : Do not include row data.
	 *
	 * [--where=<where>]
	 * : Dump only rows matching the SQL WHERE expression.
	 *
	 * [--single-transaction]
	 * : Accepted for compatibility. Export uses the current wpdb connection.
	 *
	 * [--quick]
	 * : Accepted for compatibility. Rows are exported in PHP batches.
	 *
	 * [--lock-tables=<value>]
	 * : Accepted for compatibility. Table locks are not applied.
	 *
	 * [--skip-lock-tables]
	 * : Accepted for compatibility. Table locks are not applied.
	 *
	 * [--set-gtid-purged=<value>]
	 * : Accepted for compatibility. GTID statements are not emitted.
	 *
	 * [--column-statistics=<value>]
	 * : Accepted for compatibility. Column statistics are not emitted.
	 *
	 * [--hex-blob]
	 * : Accepted for compatibility. Binary values are exported as SQL strings.
	 *
	 * [--routines]
	 * : Accepted for compatibility. Stored routines are not exported.
	 *
	 * [--events]
	 * : Accepted for compatibility. Events are not exported.
	 *
	 * [--triggers]
	 * : Accepted for compatibility. Triggers are not exported separately.
	 *
	 * [--include-tablespaces]
	 * : Accepted for compatibility. Tablespace statements are not emitted.
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--<field>=<value>]
	 * : Accepted for compatibility with the bundled db command. Common dump-only options may be ignored.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 *
	 * @alias dump
	 */
	public function export( $args, $assoc_args ) {
		$wpdb = $this->get_wpdb( $assoc_args );
		$file  = isset( $args[0] ) ? $args[0] : $this->default_export_filename();

		$stream = STDOUT;
		if ( '-' !== $file ) {
			$stream = fopen( $file, 'wb' );
			if ( ! $stream ) {
				WP_CLI::error( "Could not open '{$file}' for writing." );
			}
		}

		$tables = $this->export_tables( $wpdb, $assoc_args );
		$this->write_dump_header( $stream );

		foreach ( $tables as $table ) {
			$this->write_table_dump( $wpdb, $stream, $table, $assoc_args );
		}

		$this->write_dump_footer( $stream );

		if ( '-' !== $file ) {
			fclose( $stream );
			if ( $this->flag( $assoc_args, 'porcelain' ) ) {
				WP_CLI::line( $file );
			} else {
				WP_CLI::success( "Exported to '{$file}'." );
			}
		}
	}

	/**
	 * Imports a database from a file or from STDIN.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : SQL file to import. If '-', reads from STDIN. If omitted, uses DB_NAME.sql.
	 *
	 * [--skip-optimization]
	 * : Do not disable checks and autocommit during import.
	 *
	 * [--default-character-set=<character-set>]
	 * : Use a specific character set. Defaults to DB_CHARSET when defined.
	 *
	 * [--force]
	 * : Accepted for compatibility. SQL errors still stop execution.
	 *
	 * [--dbuser=<value>]
	 * : Username to use. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to use. Defaults to DB_PASSWORD.
	 *
	 * [--<field>=<value>]
	 * : Accepted for compatibility with the bundled db command. Unsupported client-only options are ignored.
	 *
	 * [--defaults]
	 * : Accepted for compatibility. MySQL option files are not loaded by this fallback.
	 */
	public function import( $args, $assoc_args ) {
		$wpdb = $this->get_wpdb( $assoc_args );
		$file  = isset( $args[0] ) ? $args[0] : DB_NAME . '.sql';

		if ( '-' === $file ) {
			$stream = STDIN;
		} else {
			$stream = fopen( $file, 'rb' );
			if ( ! $stream ) {
				WP_CLI::error( "Could not open '{$file}' for reading." );
			}
		}

		if ( ! $this->flag( $assoc_args, 'skip-optimization' ) ) {
			$this->execute_sql( $wpdb, 'SET autocommit = 0', $assoc_args );
			$this->execute_sql( $wpdb, 'SET unique_checks = 0', $assoc_args );
			$this->execute_sql( $wpdb, 'SET foreign_key_checks = 0', $assoc_args );
		}

		$count = 0;
		foreach ( $this->statement_iterator_from_stream( $stream ) as $statement ) {
			$this->execute_sql( $wpdb, $statement, $assoc_args );
			++$count;
		}

		if ( ! $this->flag( $assoc_args, 'skip-optimization' ) ) {
			$this->execute_sql( $wpdb, 'SET foreign_key_checks = 1', $assoc_args );
			$this->execute_sql( $wpdb, 'SET unique_checks = 1', $assoc_args );
			$this->execute_sql( $wpdb, 'COMMIT', $assoc_args );
			$this->execute_sql( $wpdb, 'SET autocommit = 1', $assoc_args );
		}

		if ( '-' !== $file ) {
			fclose( $stream );
		}

		WP_CLI::success( '-' === $file ? 'Imported from STDIN.' : "Imported from '{$file}'." );
		WP_CLI::debug( "Executed {$count} SQL statement(s).", 'db-use-wpdb' );
	}

	/**
	 * Finds a string in the database.
	 *
	 * ## OPTIONS
	 *
	 * <search>
	 * : String or regular expression to search for.
	 *
	 * [<tables>...]
	 * : One or more tables to search.
	 *
	 * [--regex]
	 * : Treat the search as a PCRE regular expression.
	 *
	 * [--regex-flags=<regex-flags>]
	 * : PCRE modifiers to use with --regex.
	 *
	 * [--before_context=<num>]
	 * : Number of characters to display before a match.
	 * ---
	 * default: 40
	 * ---
	 *
	 * [--after_context=<num>]
	 * : Number of characters to display after a match.
	 * ---
	 * default: 40
	 * ---
	 *
	 * [--matches_only]
	 * : Only output matching strings and context.
	 *
	 * [--one_line]
	 * : Output table, column, id, and match on one line.
	 *
	 * [--table_column_once]
	 * : Output the table:column heading once for each matching column.
	 *
	 * [--stats]
	 * : Output search statistics.
	 *
	 * [--fields=<fields>]
	 * : Fields to display for structured output.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 */
	public function search( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please provide a search string.' );
		}

		$start          = microtime( true );
		$search         = $args[0];
		$table_filters  = array_slice( $args, 1 );
		$wpdb           = $this->get_wpdb( $assoc_args );
		$tables         = $this->get_tables( $assoc_args, $table_filters );
		$results        = array();
		$match_count    = 0;
		$column_count   = 0;
		$row_scan_count = 0;

		foreach ( $tables as $table ) {
			$primary_key = $this->primary_key_for_table( $wpdb, $table );
			$columns     = $this->text_columns_for_table( $wpdb, $table );
			$column_count += count( $columns );

			foreach ( $columns as $column ) {
				if ( $this->flag( $assoc_args, 'regex' ) ) {
					$matches = $this->regex_search_column( $wpdb, $table, $column, $primary_key, $search, $assoc_args );
				} else {
					$matches = $this->like_search_column( $wpdb, $table, $column, $primary_key, $search, $assoc_args );
				}

				$row_scan_count += isset( $matches['_rows_scanned'] ) ? $matches['_rows_scanned'] : 0;
				unset( $matches['_rows_scanned'] );

				foreach ( $matches as $match ) {
					++$match_count;
					$results[] = $match;
				}
			}
		}

		if ( isset( $assoc_args['format'] ) ) {
			$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) ) : array( 'table', 'column', 'match', 'primary_key_name', 'primary_key_value' );
			Utils\format_items( $assoc_args['format'], $results, $fields );
		} else {
			$this->render_search_results( $results, $assoc_args );
		}

		if ( $this->flag( $assoc_args, 'stats' ) ) {
			$elapsed = round( microtime( true ) - $start, 3 );
			WP_CLI::success( "Found {$match_count} matches in {$elapsed}s. Searched " . count( $tables ) . " tables, {$column_count} columns, {$row_scan_count} rows." );
		}
	}

	/**
	 * Load a connected wpdb instance.
	 *
	 * @param array       $assoc_args Command arguments.
	 * @param string|null $database Database name.
	 * @return wpdb
	 */
	private function get_wpdb( $assoc_args, $database = null ) {
		$this->assert_db_constants();
		$this->assert_mysqli();
		$this->load_wp_runtime();

		$database = $database ? $database : DB_NAME;
		$user     = $this->assoc( $assoc_args, 'dbuser', DB_USER );
		$password = $this->assoc( $assoc_args, 'dbpass', DB_PASSWORD );
		$host     = DB_HOST;
		$key      = md5( $user . "\0" . $password . "\0" . $database . "\0" . $host );

		if ( isset( $this->wpdb_instances[ $key ] ) ) {
			return $this->wpdb_instances[ $key ];
		}

		$probe = $this->connect_mysqli( $assoc_args );
		if ( ! mysqli_select_db( $probe, $database ) ) {
			$error = mysqli_error( $probe );
			mysqli_close( $probe );
			WP_CLI::error( "Cannot select database '{$database}': {$error}" );
		}
		mysqli_close( $probe );

		if ( ! defined( 'WP_SETUP_CONFIG' ) ) {
			define( 'WP_SETUP_CONFIG', true );
		}

		$wpdb = new wpdb( $user, $password, $database, $host );
		$wpdb->suppress_errors( true );
		$wpdb->hide_errors();

		if ( ! $wpdb->db_connect( false ) ) {
			$message = $wpdb->last_error ? $wpdb->last_error : 'Could not connect to the database.';
			WP_CLI::error( $message );
		}

		global $table_prefix;
		if ( isset( $table_prefix ) ) {
			$prefix_result = $wpdb->set_prefix( $table_prefix );
			if ( is_wp_error( $prefix_result ) ) {
				WP_CLI::error( $prefix_result->get_error_message() );
			}
		}

		$this->wpdb_instances[ $key ] = $wpdb;
		return $wpdb;
	}

	/**
	 * Load the minimal WordPress runtime required by wpdb.
	 */
	private function load_wp_runtime() {
		$this->load_wp_config();

		if ( ! defined( 'ABSPATH' ) ) {
			WP_CLI::error( 'ABSPATH is not defined. Run this command against a WordPress installation.' );
		}

		if ( ! defined( 'WPINC' ) ) {
			define( 'WPINC', 'wp-includes' );
		}

		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', false );
		}

		if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
			define( 'WP_DEBUG_DISPLAY', false );
		}

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
		}

		$optional_files = array(
			ABSPATH . WPINC . '/compat-utf8.php',
			ABSPATH . WPINC . '/compat.php',
		);

		foreach ( $optional_files as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! function_exists( 'is_multisite' ) ) {
			require_once ABSPATH . WPINC . '/load.php';
		}

		if ( ! class_exists( 'WP_Error', false ) ) {
			require_once ABSPATH . WPINC . '/class-wp-error.php';
		}

		if ( ! function_exists( 'apply_filters' ) ) {
			require_once ABSPATH . WPINC . '/plugin.php';
		}

		if ( ! class_exists( 'wpdb', false ) ) {
			$file = ABSPATH . WPINC . '/class-wpdb.php';
			if ( ! file_exists( $file ) ) {
				$file = ABSPATH . WPINC . '/wp-db.php';
			}

			if ( ! file_exists( $file ) ) {
				WP_CLI::error( 'Could not locate the WordPress wpdb class.' );
			}

			require_once $file;
		}
	}

	/**
	 * Load wp-config.php without loading wp-settings.php.
	 */
	private function load_wp_config() {
		static $loaded = false;

		if ( $loaded || defined( 'DB_NAME' ) ) {
			$loaded = true;
			return;
		}

		if ( ! defined( 'ABSPATH' ) ) {
			$this->define_abspath_from_context();
		}

		$wp_config_path = Utils\locate_wp_config();
		if ( ! $wp_config_path ) {
			WP_CLI::error(
				"'wp-config.php' not found.\n" .
				'Either create one manually or use `wp config create`.'
			);
		}

		$wp_cli_original_defined_vars = get_defined_vars();
		eval( WP_CLI::get_runner()->get_wp_config_code( $wp_config_path ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

		foreach ( get_defined_vars() as $key => $var ) {
			if ( array_key_exists( $key, $wp_cli_original_defined_vars ) || 'wp_cli_original_defined_vars' === $key ) {
				continue;
			}

			global ${$key};
			${$key} = $var;
		}

		$loaded = true;
	}

	/**
	 * Define ABSPATH when the command is invoked before WP-CLI has done so.
	 */
	private function define_abspath_from_context() {
		$config = WP_CLI::get_config();
		$path   = ! empty( $config['path'] ) ? $config['path'] : getcwd();
		$path   = realpath( $path );

		if ( ! $path ) {
			WP_CLI::error( 'Could not determine the WordPress path.' );
		}

		$search = $path;
		while ( true ) {
			if ( file_exists( $search . '/wp-load.php' ) || file_exists( $search . '/wp-settings.php' ) || file_exists( $search . '/wp-config.php' ) ) {
				define( 'ABSPATH', rtrim( $search, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
				return;
			}

			$parent = dirname( $search );
			if ( $parent === $search ) {
				break;
			}

			$search = $parent;
		}

		define( 'ABSPATH', rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
	}

	/**
	 * Ensure expected wp-config constants exist.
	 */
	private function assert_db_constants() {
		$this->load_wp_config();

		foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				WP_CLI::error( "{$constant} is not defined in wp-config.php." );
			}
		}
	}

	/**
	 * Ensure PHP has mysqli available.
	 */
	private function assert_mysqli() {
		if ( ! extension_loaded( 'mysqli' ) ) {
			WP_CLI::error( 'The mysqli PHP extension is required for the wpdb-backed db fallback.' );
		}
	}

	/**
	 * Connect to the database server, optionally selecting a database.
	 *
	 * @param array       $assoc_args Command args.
	 * @param string|null $database Database name.
	 * @return mysqli
	 */
	private function connect_mysqli( $assoc_args, $database = null ) {
		$this->assert_db_constants();
		$this->assert_mysqli();
		$this->load_wp_runtime();

		$user      = $this->assoc( $assoc_args, 'dbuser', DB_USER );
		$password  = $this->assoc( $assoc_args, 'dbpass', DB_PASSWORD );
		$host_data = $this->parse_db_host( DB_HOST );
		$host      = $host_data[0];
		$port      = $host_data[1];
		$socket    = $host_data[2];
		$is_ipv6   = $host_data[3];

		if ( $is_ipv6 && extension_loaded( 'mysqlnd' ) ) {
			$host = '[' . $host . ']';
		}

		mysqli_report( MYSQLI_REPORT_OFF );
		$mysqli = mysqli_init();
		if ( ! $mysqli ) {
			WP_CLI::error( 'Could not initialize mysqli.' );
		}

		$flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;
		$ok    = @mysqli_real_connect( $mysqli, $host, $user, $password, $database, $port, $socket, $flags );
		if ( ! $ok ) {
			$error = mysqli_connect_error();
			WP_CLI::error( "Could not connect to database server: {$error}" );
		}

		$charset = $this->assoc( $assoc_args, 'default-character-set', defined( 'DB_CHARSET' ) ? DB_CHARSET : null );
		if ( $charset ) {
			mysqli_set_charset( $mysqli, $charset );
		}

		return $mysqli;
	}

	/**
	 * Parse DB_HOST using wpdb's parser.
	 *
	 * @param string $db_host DB_HOST.
	 * @return array
	 */
	private function parse_db_host( $db_host ) {
		if ( ! defined( 'WP_SETUP_CONFIG' ) ) {
			define( 'WP_SETUP_CONFIG', true );
		}

		$parser = new wpdb( '', '', '', $db_host );
		$parsed = $parser->parse_db_host( $db_host );

		if ( false !== $parsed ) {
			return $parsed;
		}

		return array( $db_host, null, null, false );
	}

	/**
	 * Execute a statement and return rows for row-returning statements.
	 *
	 * @param wpdb  $wpdb wpdb instance.
	 * @param string $sql SQL statement.
	 * @param array  $assoc_args Command args.
	 * @return array|null
	 */
	private function execute_sql( $wpdb, $sql, $assoc_args ) {
		$sql = trim( $sql );
		if ( '' === $sql ) {
			return null;
		}

		if ( $this->statement_returns_rows( $sql ) ) {
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			$this->check_wpdb_error( $wpdb, $sql );
			return $rows ? $rows : array();
		}

		$wpdb->query( $sql );
		$this->check_wpdb_error( $wpdb, $sql );
		return null;
	}

	/**
	 * Execute a SELECT-like query and return rows.
	 *
	 * @param wpdb   $wpdb wpdb instance.
	 * @param string $sql SQL query.
	 * @return array
	 */
	private function get_results( $wpdb, $sql ) {
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$this->check_wpdb_error( $wpdb, $sql );
		return $rows ? $rows : array();
	}

	/**
	 * Check wpdb's last error.
	 *
	 * @param wpdb   $wpdb wpdb instance.
	 * @param string $sql SQL statement.
	 */
	private function check_wpdb_error( $wpdb, $sql ) {
		if ( $wpdb->last_error ) {
			WP_CLI::error( $wpdb->last_error . "\nSQL: " . $sql );
		}
	}

	/**
	 * Render query rows.
	 *
	 * @param array $rows Query rows.
	 * @param array $assoc_args Command args.
	 */
	private function render_query_rows( $rows, $assoc_args ) {
		if ( empty( $rows ) ) {
			return;
		}

		$fields = array_keys( $rows[0] );
		if ( $this->flag( $assoc_args, 'skip-column-names' ) || $this->flag( $assoc_args, 'silent' ) || $this->flag( $assoc_args, 'batch' ) ) {
			foreach ( $rows as $row ) {
				WP_CLI::line( implode( "\t", array_map( array( $this, 'stringify_cell' ), array_values( $row ) ) ) );
			}
			return;
		}

		Utils\format_items( 'table', $rows, $fields );
	}

	/**
	 * Return all relevant tables for a db tables/search/size operation.
	 *
	 * @param array $assoc_args Command args.
	 * @param array $patterns Optional wildcard filters.
	 * @return string[]
	 */
	private function get_tables( $assoc_args, $patterns = array() ) {
		$wpdb = $this->get_wpdb( $assoc_args );

		if ( $this->flag( $assoc_args, 'all-tables' ) ) {
			$tables = $this->all_database_tables( $wpdb );
		} elseif ( $this->flag( $assoc_args, 'all-tables-with-prefix' ) ) {
			$tables = $this->tables_with_prefix( $wpdb, $wpdb->prefix );
		} elseif ( $this->flag( $assoc_args, 'network' ) && is_multisite() ) {
			$tables = $this->network_tables( $wpdb, $this->assoc( $assoc_args, 'scope', 'all' ) );
		} else {
			$scope  = $this->assoc( $assoc_args, 'scope', 'all' );
			$tables = array_values( $wpdb->tables( $scope ) );
		}

		$existing = array_flip( $this->all_database_tables( $wpdb ) );
		$tables   = array_values(
			array_filter(
				array_unique( $tables ),
				function ( $table ) use ( $existing ) {
					return isset( $existing[ $table ] );
				}
			)
		);

		if ( $patterns ) {
			$tables = $this->filter_tables_by_patterns( $tables, $patterns );
		}

		sort( $tables, SORT_NATURAL );
		return $tables;
	}

	/**
	 * Get every table in the active database.
	 *
	 * @param wpdb $wpdb wpdb instance.
	 * @return string[]
	 */
	private function all_database_tables( $wpdb ) {
		$rows = $this->get_results( $wpdb, 'SHOW FULL TABLES WHERE Table_type = "BASE TABLE"' );
		if ( empty( $rows ) ) {
			$rows = $this->get_results( $wpdb, 'SHOW TABLES' );
		}

		$tables = array();
		foreach ( $rows as $row ) {
			$values = array_values( $row );
			if ( isset( $values[0] ) ) {
				$tables[] = $values[0];
			}
		}

		return $tables;
	}

	/**
	 * Get all tables with a literal prefix.
	 *
	 * @param wpdb   $wpdb wpdb instance.
	 * @param string $prefix Table prefix.
	 * @return string[]
	 */
	private function tables_with_prefix( $wpdb, $prefix ) {
		$tables = array();
		foreach ( $this->all_database_tables( $wpdb ) as $table ) {
			if ( 0 === strpos( $table, $prefix ) ) {
				$tables[] = $table;
			}
		}
		return $tables;
	}

	/**
	 * Return registered multisite tables.
	 *
	 * @param wpdb   $wpdb wpdb instance.
	 * @param string $scope Scope.
	 * @return string[]
	 */
	private function network_tables( $wpdb, $scope ) {
		$tables = array_values( $wpdb->tables( in_array( $scope, array( 'blog', 'old' ), true ) ? 'global' : $scope ) );

		$blogs_table = $wpdb->base_prefix . 'blogs';
		$existing    = array_flip( $this->all_database_tables( $wpdb ) );
		if ( ! isset( $existing[ $blogs_table ] ) ) {
			return $tables;
		}

		$blog_ids = $wpdb->get_col( 'SELECT blog_id FROM ' . $this->quote_identifier( $blogs_table ) );
		$this->check_wpdb_error( $wpdb, 'SELECT blog_id FROM ' . $blogs_table );

		foreach ( $blog_ids as $blog_id ) {
			foreach ( $wpdb->tables( 'blog', true, (int) $blog_id ) as $table ) {
				$tables[] = $table;
			}
		}

		return $tables;
	}

	/**
	 * Filter table names by wildcard patterns.
	 *
	 * @param string[] $tables Tables.
	 * @param string[] $patterns Patterns.
	 * @return string[]
	 */
	private function filter_tables_by_patterns( $tables, $patterns ) {
		$filtered = array();
		foreach ( $tables as $table ) {
			foreach ( $patterns as $pattern ) {
				if ( fnmatch( $pattern, $table ) ) {
					$filtered[] = $table;
					break;
				}
			}
		}
		return $filtered;
	}

	/**
	 * Drop tables.
	 *
	 * @param wpdb     $wpdb wpdb instance.
	 * @param string[] $tables Tables to drop.
	 */
	private function drop_tables( $wpdb, $tables ) {
		if ( empty( $tables ) ) {
			return;
		}

		$wpdb->query( 'SET foreign_key_checks = 0' );
		foreach ( $tables as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $table ) );
			$this->check_wpdb_error( $wpdb, 'DROP TABLE ' . $table );
		}
		$wpdb->query( 'SET foreign_key_checks = 1' );
	}

	/**
	 * Run CHECK, REPAIR, or OPTIMIZE against all tables.
	 *
	 * @param string $operation Operation keyword.
	 * @param string $success Success message.
	 * @param array  $assoc_args Command args.
	 */
	private function run_table_maintenance( $operation, $success, $assoc_args ) {
		$wpdb  = $this->get_wpdb( $assoc_args );
		$tables = $this->all_database_tables( $wpdb );

		if ( empty( $tables ) ) {
			WP_CLI::success( $success );
			return;
		}

		$sql = $operation . ' TABLE ' . implode( ', ', array_map( array( $this, 'quote_identifier' ), $tables ) );
		$this->get_results( $wpdb, $sql );
		WP_CLI::success( $success );
	}

	/**
	 * Get table sizes.
	 *
	 * @param wpdb     $wpdb wpdb instance.
	 * @param string[] $tables Tables.
	 * @return array
	 */
	private function get_table_sizes( $wpdb, $tables ) {
		if ( empty( $tables ) ) {
			return array();
		}

		$quoted = array();
		foreach ( $tables as $table ) {
			$quoted[] = $this->sql_string( $wpdb, $table );
		}

		$sql = 'SELECT table_name AS Name, COALESCE(data_length, 0) + COALESCE(index_length, 0) AS Bytes'
			. ' FROM information_schema.TABLES'
			. ' WHERE table_schema = ' . $this->sql_string( $wpdb, DB_NAME )
			. ' AND table_name IN (' . implode( ', ', $quoted ) . ')';

		$rows = $this->get_results( $wpdb, $sql );
		foreach ( $rows as &$row ) {
			$row['Bytes'] = (int) $row['Bytes'];
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Determine tables to export.
	 *
	 * @param wpdb  $wpdb wpdb instance.
	 * @param array $assoc_args Command args.
	 * @return string[]
	 */
	private function export_tables( $wpdb, $assoc_args ) {
		$tables = $this->all_database_tables( $wpdb );

		if ( isset( $assoc_args['tables'] ) && '' !== $assoc_args['tables'] ) {
			$tables = $this->filter_tables_by_patterns( $tables, $this->split_csv_arg( $assoc_args['tables'] ) );
		}

		if ( isset( $assoc_args['exclude_tables'] ) && '' !== $assoc_args['exclude_tables'] ) {
			$exclude = $this->filter_tables_by_patterns( $tables, $this->split_csv_arg( $assoc_args['exclude_tables'] ) );
			$tables  = array_values( array_diff( $tables, $exclude ) );
		}

		sort( $tables, SORT_NATURAL );
		return $tables;
	}

	/**
	 * Write dump header.
	 *
	 * @param resource $stream Output stream.
	 */
	private function write_dump_header( $stream ) {
		fwrite( $stream, "-- WP-CLI wpdb SQL Dump\n" );
		fwrite( $stream, '-- Database: ' . DB_NAME . "\n" );
		fwrite( $stream, '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n" );
		fwrite( $stream, "SET foreign_key_checks = 0;\n" );
		fwrite( $stream, "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n" );
	}

	/**
	 * Write dump footer.
	 *
	 * @param resource $stream Output stream.
	 */
	private function write_dump_footer( $stream ) {
		fwrite( $stream, "SET foreign_key_checks = 1;\n" );
	}

	/**
	 * Write one table dump.
	 *
	 * @param wpdb     $wpdb wpdb instance.
	 * @param resource $stream Output stream.
	 * @param string   $table Table name.
	 * @param array    $assoc_args Command args.
	 */
	private function write_table_dump( $wpdb, $stream, $table, $assoc_args ) {
		fwrite( $stream, "\n-- Table: {$table}\n" );

		if ( $this->flag( $assoc_args, 'add-drop-table' ) ) {
			fwrite( $stream, 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $table ) . ";\n" );
		}

		if ( ! $this->flag( $assoc_args, 'no-create-info' ) ) {
			$create = $this->get_results( $wpdb, 'SHOW CREATE TABLE ' . $this->quote_identifier( $table ) );
			if ( isset( $create[0]['Create Table'] ) ) {
				fwrite( $stream, $create[0]['Create Table'] . ";\n\n" );
			}
		}

		if ( $this->flag( $assoc_args, 'no-data' ) ) {
			return;
		}

		$columns = $this->column_names_for_table( $wpdb, $table );
		if ( empty( $columns ) ) {
			return;
		}

		$where = isset( $assoc_args['where'] ) ? ' WHERE ' . $assoc_args['where'] : '';
		$batch = 500;
		$offset = 0;

		do {
			$sql  = 'SELECT * FROM ' . $this->quote_identifier( $table ) . $where;
			$sql .= ' LIMIT ' . (int) $batch . ' OFFSET ' . (int) $offset;
			$rows = $this->get_results( $wpdb, $sql );

			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $columns as $column ) {
					$values[] = array_key_exists( $column, $row ) ? $this->sql_value( $wpdb, $row[ $column ] ) : 'NULL';
				}

				fwrite(
					$stream,
					'INSERT INTO ' . $this->quote_identifier( $table )
					. ' (' . implode( ', ', array_map( array( $this, 'quote_identifier' ), $columns ) ) . ') VALUES ('
					. implode( ', ', $values ) . ");\n"
				);
			}

			$offset += $batch;
		} while ( count( $rows ) === $batch );

		fwrite( $stream, "\n" );
	}

	/**
	 * Get primary key column for a table.
	 *
	 * @param wpdb   $wpdb wpdb instance.
	 * @param string $table Table name.
	 * @return string
	 */
	private function primary_key_for_table( $wpdb, $table ) {
		foreach ( $this->get_results( $wpdb, 'SHOW COLUMNS FROM ' . $this->quote_identifier( $table ) ) as $column ) {
			if ( isset( $column['Key'] ) && 'PRI' === $column['Key'] ) {
				return $column['Field'];
			}
		}

		$columns = $this->column_names_for_table( $wpdb, $table );
		return $columns ? $columns[0] : '';
	}

	/**
	 * Get column names for a table.
	 *
	 * @param wpdb   $wpdb wpdb instance.
	 * @param string $table Table name.
	 * @return string[]
	 */
	private function column_names_for_table( $wpdb, $table ) {
		$columns = array();
		foreach ( $this->get_results( $wpdb, 'SHOW COLUMNS FROM ' . $this->quote_identifier( $table ) ) as $column ) {
			$columns[] = $column['Field'];
		}
		return $columns;
	}

	/**
	 * Get searchable text-like columns for a table.
	 *
	 * @param wpdb   $wpdb wpdb instance.
	 * @param string $table Table name.
	 * @return string[]
	 */
	private function text_columns_for_table( $wpdb, $table ) {
		$columns = array();
		foreach ( $this->get_results( $wpdb, 'SHOW COLUMNS FROM ' . $this->quote_identifier( $table ) ) as $column ) {
			$type = strtolower( $column['Type'] );
			if ( preg_match( '/char|text|blob|enum|set|json/', $type ) ) {
				$columns[] = $column['Field'];
			}
		}
		return $columns;
	}

	/**
	 * Search a column using SQL LIKE.
	 *
	 * @param wpdb   $wpdb wpdb instance.
	 * @param string $table Table.
	 * @param string $column Column.
	 * @param string $primary_key Primary key column.
	 * @param string $search Search string.
	 * @param array  $assoc_args Command args.
	 * @return array
	 */
	private function like_search_column( $wpdb, $table, $column, $primary_key, $search, $assoc_args ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$sql  = 'SELECT ' . $this->quote_identifier( $primary_key ) . ' AS __pk, ' . $this->quote_identifier( $column ) . ' AS __value';
		$sql .= ' FROM ' . $this->quote_identifier( $table );
		$sql .= ' WHERE ' . $this->quote_identifier( $column ) . ' LIKE ' . $this->sql_string( $wpdb, $like );

		$rows = $this->get_results( $wpdb, $sql );
		$out  = array( '_rows_scanned' => count( $rows ) );
		foreach ( $rows as $row ) {
			$out[] = $this->search_result_row( $table, $column, $primary_key, $row['__pk'], $row['__value'], $search, false, $assoc_args );
		}

		return $out;
	}

	/**
	 * Search a column using PHP regex matching.
	 *
	 * @param wpdb   $wpdb wpdb instance.
	 * @param string $table Table.
	 * @param string $column Column.
	 * @param string $primary_key Primary key column.
	 * @param string $search Regex.
	 * @param array  $assoc_args Command args.
	 * @return array
	 */
	private function regex_search_column( $wpdb, $table, $column, $primary_key, $search, $assoc_args ) {
		$sql  = 'SELECT ' . $this->quote_identifier( $primary_key ) . ' AS __pk, ' . $this->quote_identifier( $column ) . ' AS __value';
		$sql .= ' FROM ' . $this->quote_identifier( $table );

		$rows      = $this->get_results( $wpdb, $sql );
		$out       = array( '_rows_scanned' => count( $rows ) );
		$delimiter = $this->assoc( $assoc_args, 'regex-delimiter', chr( 1 ) );
		$flags     = $this->assoc( $assoc_args, 'regex-flags', '' );
		$pattern   = $delimiter . str_replace( $delimiter, '\\' . $delimiter, $search ) . $delimiter . $flags;

		foreach ( $rows as $row ) {
			if ( preg_match( $pattern, (string) $row['__value'] ) ) {
				$out[] = $this->search_result_row( $table, $column, $primary_key, $row['__pk'], $row['__value'], $search, true, $assoc_args );
			}
		}

		return $out;
	}

	/**
	 * Build a structured search result row.
	 *
	 * @param string $table Table.
	 * @param string $column Column.
	 * @param string $primary_key Primary key.
	 * @param mixed  $primary_value Primary value.
	 * @param mixed  $value Matching value.
	 * @param string $search Search string.
	 * @param bool   $regex Whether regex search was used.
	 * @param array  $assoc_args Command args.
	 * @return array
	 */
	private function search_result_row( $table, $column, $primary_key, $primary_value, $value, $search, $regex, $assoc_args ) {
		return array(
			'table'             => $table,
			'column'            => $column,
			'match'             => $this->match_context( (string) $value, $search, $regex, $assoc_args ),
			'primary_key_name'  => $primary_key,
			'primary_key_value' => $primary_value,
		);
	}

	/**
	 * Render default search output.
	 *
	 * @param array $results Results.
	 * @param array $assoc_args Command args.
	 */
	private function render_search_results( $results, $assoc_args ) {
		$printed_headings = array();
		foreach ( $results as $result ) {
			$heading = $result['table'] . ':' . $result['column'];
			$id_line = $result['primary_key_value'] . ':' . $result['match'];

			if ( $this->flag( $assoc_args, 'matches_only' ) ) {
				WP_CLI::line( $result['match'] );
			} elseif ( $this->flag( $assoc_args, 'one_line' ) ) {
				WP_CLI::line( $heading . ':' . $id_line );
			} elseif ( $this->flag( $assoc_args, 'table_column_once' ) ) {
				if ( ! isset( $printed_headings[ $heading ] ) ) {
					WP_CLI::line( $heading );
					$printed_headings[ $heading ] = true;
				}
				WP_CLI::line( $id_line );
			} else {
				WP_CLI::line( $heading );
				WP_CLI::line( $id_line );
			}
		}
	}

	/**
	 * Build a search match context string.
	 *
	 * @param string $value Value.
	 * @param string $search Search.
	 * @param bool   $regex Regex mode.
	 * @param array  $assoc_args Command args.
	 * @return string
	 */
	private function match_context( $value, $search, $regex, $assoc_args ) {
		$before = (int) $this->assoc( $assoc_args, 'before_context', 40 );
		$after  = (int) $this->assoc( $assoc_args, 'after_context', 40 );

		if ( $regex ) {
			$delimiter = $this->assoc( $assoc_args, 'regex-delimiter', chr( 1 ) );
			$flags     = $this->assoc( $assoc_args, 'regex-flags', '' );
			$pattern   = $delimiter . str_replace( $delimiter, '\\' . $delimiter, $search ) . $delimiter . $flags;
			if ( ! preg_match( $pattern, $value, $matches, PREG_OFFSET_CAPTURE ) ) {
				return $value;
			}
			$offset = $matches[0][1];
			$length = strlen( $matches[0][0] );
		} else {
			$offset = stripos( $value, $search );
			$length = strlen( $search );
			if ( false === $offset ) {
				return $value;
			}
		}

		$start = max( 0, $offset - $before );
		return substr( $value, $start, $before + $length + $after );
	}

	/**
	 * Split SQL from a string.
	 *
	 * @param string $sql SQL.
	 * @return Generator
	 */
	private function statement_iterator_from_string( $sql ) {
		$stream = fopen( 'php://temp', 'r+' );
		fwrite( $stream, $sql );
		rewind( $stream );
		try {
			foreach ( $this->statement_iterator_from_stream( $stream ) as $statement ) {
				yield $statement;
			}
		} finally {
			fclose( $stream );
		}
	}

	/**
	 * Split SQL from a stream.
	 *
	 * @param resource $stream SQL stream.
	 * @return Generator
	 */
	private function statement_iterator_from_stream( $stream ) {
		$delimiter = ';';
		$buffer    = '';
		$quote     = null;
		$escaped   = false;
		$line_comment = false;
		$block_comment = false;

		while ( false !== ( $line = fgets( $stream ) ) ) {
			if ( '' === trim( $buffer ) && preg_match( '/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $matches ) ) {
				$delimiter = $matches[1];
				continue;
			}

			$length = strlen( $line );
			for ( $i = 0; $i < $length; ++$i ) {
				$char = $line[ $i ];
				$next = $i + 1 < $length ? $line[ $i + 1 ] : '';

				if ( $line_comment ) {
					$buffer .= $char;
					if ( "\n" === $char ) {
						$line_comment = false;
					}
					continue;
				}

				if ( $block_comment ) {
					$buffer .= $char;
					if ( '*' === $char && '/' === $next ) {
						$buffer .= $next;
						++$i;
						$block_comment = false;
					}
					continue;
				}

				if ( $quote ) {
					$buffer .= $char;
					if ( $escaped ) {
						$escaped = false;
					} elseif ( '\\' === $char ) {
						$escaped = true;
					} elseif ( $quote === $char ) {
						$quote = null;
					}
					continue;
				}

				if ( '-' === $char && '-' === $next ) {
					$third = $i + 2 < $length ? $line[ $i + 2 ] : '';
					if ( '' === $third || preg_match( '/\s/', $third ) ) {
						$buffer .= $char . $next;
						++$i;
						$line_comment = true;
						continue;
					}
				}

				if ( '#' === $char ) {
					$buffer .= $char;
					$line_comment = true;
					continue;
				}

				if ( '/' === $char && '*' === $next ) {
					$buffer .= $char . $next;
					++$i;
					$block_comment = true;
					continue;
				}

				if ( in_array( $char, array( "'", '"', '`' ), true ) ) {
					$buffer .= $char;
					$quote = $char;
					continue;
				}

				if ( $this->starts_with_at( $line, $delimiter, $i ) ) {
					$statement = trim( $buffer );
					if ( '' !== $statement && ! $this->is_comment_only_sql( $statement ) ) {
						yield $statement;
					}
					$buffer = '';
					$i += strlen( $delimiter ) - 1;
					continue;
				}

				$buffer .= $char;
			}
		}

		$statement = trim( $buffer );
		if ( '' !== $statement && ! $this->is_comment_only_sql( $statement ) ) {
			yield $statement;
		}
	}

	/**
	 * Whether a string starts with a needle at an offset.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle Needle.
	 * @param int    $offset Offset.
	 * @return bool
	 */
	private function starts_with_at( $haystack, $needle, $offset ) {
		return '' !== $needle && substr( $haystack, $offset, strlen( $needle ) ) === $needle;
	}

	/**
	 * Whether SQL text is only comments.
	 *
	 * @param string $sql SQL.
	 * @return bool
	 */
	private function is_comment_only_sql( $sql ) {
		$sql = trim( preg_replace( '/^\s*(--[^\n]*|#[^\n]*|\/\*.*?\*\/)\s*/s', '', $sql ) );
		return '' === $sql;
	}

	/**
	 * Whether SQL likely returns rows.
	 *
	 * @param string $sql SQL.
	 * @return bool
	 */
	private function statement_returns_rows( $sql ) {
		return (bool) preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|CHECK|REPAIR|OPTIMIZE|ANALYZE)\b/i', $sql );
	}

	/**
	 * Whether SQL text ends with an unquoted delimiter.
	 *
	 * @param string $sql SQL.
	 * @param string $delimiter Delimiter.
	 * @return bool
	 */
	private function sql_ends_with_delimiter( $sql, $delimiter ) {
		return substr( rtrim( $sql ), - strlen( $delimiter ) ) === $delimiter;
	}

	/**
	 * Quote an identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	private function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Quote a possibly qualified identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	private function quote_qualified_identifier( $identifier ) {
		return implode( '.', array_map( array( $this, 'quote_identifier' ), explode( '.', $identifier ) ) );
	}

	/**
	 * Quote a charset/collation keyword.
	 *
	 * @param string $keyword Keyword.
	 * @return string
	 */
	private function quote_sql_keyword( $keyword ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $keyword ) ) {
			WP_CLI::error( "Invalid SQL keyword '{$keyword}'." );
		}
		return $keyword;
	}

	/**
	 * SQL string literal.
	 *
	 * @param wpdb  $wpdb wpdb instance.
	 * @param mixed $value Value.
	 * @return string
	 */
	private function sql_string( $wpdb, $value ) {
		return "'" . mysqli_real_escape_string( $this->wpdb_dbh( $wpdb ), (string) $value ) . "'";
	}

	/**
	 * SQL value literal.
	 *
	 * @param wpdb  $wpdb wpdb instance.
	 * @param mixed $value Value.
	 * @return string
	 */
	private function sql_value( $wpdb, $value ) {
		if ( null === $value ) {
			return 'NULL';
		}

		return $this->sql_string( $wpdb, $value );
	}

	/**
	 * Read protected wpdb::dbh.
	 *
	 * @param wpdb $wpdb wpdb instance.
	 * @return mysqli
	 */
	private function wpdb_dbh( $wpdb ) {
		$reader = Closure::bind(
			function () {
				return $this->dbh;
			},
			$wpdb,
			get_class( $wpdb )
		);

		return $reader();
	}

	/**
	 * Run mysqli query or error.
	 *
	 * @param mysqli $mysqli Connection.
	 * @param string $sql SQL.
	 */
	private function mysqli_query_or_error( $mysqli, $sql ) {
		if ( ! mysqli_query( $mysqli, $sql ) ) {
			WP_CLI::error( mysqli_error( $mysqli ) . "\nSQL: " . $sql );
		}
	}

	/**
	 * Format byte count into a human-readable string.
	 *
	 * @param int $bytes Bytes.
	 * @param int $decimals Decimals.
	 * @return string
	 */
	private function human_size( $bytes, $decimals = 0 ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$size  = (float) $bytes;
		$unit  = 0;

		while ( $size >= 1024 && $unit < count( $units ) - 1 ) {
			$size /= 1024;
			++$unit;
		}

		return number_format( $size, $decimals ) . ' ' . $units[ $unit ];
	}

	/**
	 * Format size as a bare number.
	 *
	 * @param int    $bytes Bytes.
	 * @param string $format Format.
	 * @param int    $decimals Decimals.
	 * @return string
	 */
	private function format_size_number( $bytes, $format, $decimals ) {
		$binary_units = array(
			'b'   => 0,
			'kb'  => 1,
			'mb'  => 2,
			'gb'  => 3,
			'tb'  => 4,
			'B'   => 0,
			'KiB' => 1,
			'MiB' => 2,
			'GiB' => 3,
			'TiB' => 4,
		);
		$decimal_units = array(
			'KB' => 1,
			'MB' => 2,
			'GB' => 3,
			'TB' => 4,
		);

		if ( isset( $binary_units[ $format ] ) ) {
			$base  = 1024;
			$power = $binary_units[ $format ];
		} elseif ( isset( $decimal_units[ $format ] ) ) {
			$base  = 1000;
			$power = $decimal_units[ $format ];
		} else {
			WP_CLI::error( "Unknown size format '{$format}'." );
		}

		$value = $bytes / pow( $base, $power );
		return (string) round( $value, $decimals );
	}

	/**
	 * Default export filename.
	 *
	 * @return string
	 */
	private function default_export_filename() {
		return DB_NAME . '-' . gmdate( 'Y-m-d' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.sql';
	}

	/**
	 * Split comma-separated CLI arg.
	 *
	 * @param string $value Value.
	 * @return string[]
	 */
	private function split_csv_arg( $value ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
	}

	/**
	 * Get assoc arg.
	 *
	 * @param array  $assoc_args Assoc args.
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private function assoc( $assoc_args, $key, $default = null ) {
		return array_key_exists( $key, $assoc_args ) ? $assoc_args[ $key ] : $default;
	}

	/**
	 * Get boolean flag value.
	 *
	 * @param array  $assoc_args Assoc args.
	 * @param string $key Key.
	 * @return bool
	 */
	private function flag( $assoc_args, $key ) {
		if ( ! array_key_exists( $key, $assoc_args ) ) {
			return false;
		}

		$value = $assoc_args[ $key ];
		if ( is_bool( $value ) ) {
			return $value;
		}

		return ! in_array( strtolower( (string) $value ), array( '', '0', 'false', 'no', 'off' ), true );
	}

	/**
	 * Stringify a query output cell.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stringify_cell( $value ) {
		return null === $value ? 'NULL' : (string) $value;
	}

	/**
	 * Fallback prompt reader.
	 *
	 * @param string $prompt Prompt.
	 * @return string|false
	 */
	private function readline_fallback( $prompt ) {
		fwrite( STDOUT, $prompt );
		return fgets( STDIN );
	}
}
