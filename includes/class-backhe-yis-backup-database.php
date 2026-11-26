<?php
/**
 * Database backup.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports the site's database tables to an SQL file in resumable steps.
 *
 * Only tables with the site's table prefix are exported. Table names are
 * passed with the %i placeholder and checked against the actual table list.
 * The export reads arbitrary tables, so direct queries are required, and
 * the dump file is written with direct file access because WP_Filesystem
 * cannot append to files.
 *
 * Exception messages are caught internally, stored and escaped on output.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped
 *
 * @since 1.1.0
 */
class Backhe_YIS_Backup_Database {

	/**
	 * Rows read per query.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const ROWS_PER_QUERY = 500;

	/**
	 * Rows per INSERT statement.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const ROWS_PER_INSERT = 100;

	/**
	 * Prepare the database part of a job.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $job Job.
	 * @param string $dir Backup directory.
	 * @return array Job with the "db" state.
	 */
	public static function init( array $job, $dir ) {
		$compressed = function_exists( 'gzopen' );
		$name       = $job['id'] . '-database.sql' . ( $compressed ? '.gz' : '' );

		$job['db'] = array(
			'tables'      => self::get_tables(),
			'table_index' => 0,
			'offset'      => 0,
			'file'        => $name,
			'compressed'  => $compressed,
			'started'     => false,
			'done'        => false,
		);

		$job['files'][] = array(
			'name' => $name,
			'type' => 'database',
		);

		unset( $dir );

		return $job;
	}

	/**
	 * Export tables until the deadline is reached or the export is complete.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $job      Job.
	 * @param string $dir      Backup directory.
	 * @param float  $deadline Microtime until which work may continue.
	 * @return array Updated job.
	 * @throws RuntimeException If the dump file cannot be written.
	 */
	public static function run( array $job, $dir, $deadline ) {
		global $wpdb;

		$state  = $job['db'];
		$path   = $dir . '/' . $state['file'];
		$handle = $state['compressed'] ? gzopen( $path, 'ab6' ) : fopen( $path, 'ab' );

		if ( false === $handle ) {
			throw new RuntimeException( __( 'The database backup file could not be written.', 'admin-backend-and-update-helper' ) );
		}

		if ( ! $state['started'] ) {
			self::write( $handle, $state['compressed'], self::get_header() );
			$state['started'] = true;
		}

		$table_count = count( $state['tables'] );

		while ( $state['table_index'] < $table_count && microtime( true ) < $deadline ) {
			$table = $state['tables'][ $state['table_index'] ];

			if ( 0 === $state['offset'] ) {
				self::write( $handle, $state['compressed'], self::get_table_structure( $table ) );
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $table, $state['offset'], self::ROWS_PER_QUERY ),
				ARRAY_N
			);

			if ( '' !== $wpdb->last_error ) {
				self::close( $handle, $state['compressed'] );
				throw new RuntimeException(
					sprintf(
						/* translators: 1: Table name, 2: Database error. */
						__( 'Table %1$s could not be read: %2$s', 'admin-backend-and-update-helper' ),
						$table,
						$wpdb->last_error
					)
				);
			}

			if ( ! empty( $rows ) ) {
				self::write( $handle, $state['compressed'], self::get_inserts( $table, $rows ) );
				$state['offset'] += count( $rows );
			}

			if ( count( $rows ) < self::ROWS_PER_QUERY ) {
				++$state['table_index'];
				$state['offset'] = 0;
			}
		}

		if ( $state['table_index'] >= $table_count ) {
			self::write( $handle, $state['compressed'], "\nSET FOREIGN_KEY_CHECKS = 1;\n" );
			$state['done'] = true;
		}

		self::close( $handle, $state['compressed'] );

		$job['db'] = $state;

		return $job;
	}

	/**
	 * Get the site's base tables.
	 *
	 * @since 1.1.0
	 *
	 * @return string[] Table names.
	 */
	private static function get_tables() {
		global $wpdb;

		$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_N );
		$tables = array();

		foreach ( (array) $rows as $row ) {
			if ( isset( $row[0], $row[1] ) && 'BASE TABLE' === $row[1] ) {
				$tables[] = $row[0];
			}
		}

		sort( $tables );

		return $tables;
	}

	/**
	 * Get the DROP and CREATE statements of a table.
	 *
	 * @since 1.1.0
	 *
	 * @param string $table Table name.
	 * @return string SQL.
	 * @throws RuntimeException If the structure cannot be read.
	 */
	private static function get_table_structure( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reads the table definition; nothing is changed.
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );

		if ( ! is_array( $row ) || empty( $row[1] ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: Table name. */
					__( 'The structure of table %s could not be read.', 'admin-backend-and-update-helper' ),
					$table
				)
			);
		}

		$quoted = self::quote_identifier( $table );

		return "\n--\n-- Table " . $quoted . "\n--\n\nDROP TABLE IF EXISTS " . $quoted . ";\n" . $row[1] . ";\n\n";
	}

	/**
	 * Build INSERT statements for rows.
	 *
	 * @since 1.1.0
	 *
	 * @param string  $table Table name.
	 * @param array[] $rows  Rows as numeric arrays.
	 * @return string SQL.
	 */
	private static function get_inserts( $table, array $rows ) {
		global $wpdb;

		$sql = '';

		foreach ( array_chunk( $rows, self::ROWS_PER_INSERT ) as $chunk ) {
			$values = array();

			foreach ( $chunk as $row ) {
				$cells = array();

				foreach ( $row as $cell ) {
					// esc_sql() adds placeholder escapes for "%", which must not end up in the dump.
					$cells[] = null === $cell ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( esc_sql( (string) $cell ) ) . "'";
				}

				$values[] = '(' . implode( ',', $cells ) . ')';
			}

			$sql .= 'INSERT INTO ' . self::quote_identifier( $table ) . " VALUES\n" . implode( ",\n", $values ) . ";\n";
		}

		return $sql;
	}

	/**
	 * Get the header of the dump file.
	 *
	 * @since 1.1.0
	 *
	 * @return string SQL.
	 */
	private static function get_header() {
		global $wpdb;

		return '-- Admin Backend and Update Helper database backup' . "\n"
			. '-- Site: ' . home_url( '/' ) . "\n"
			. '-- Date (UTC): ' . gmdate( 'Y-m-d H:i:s' ) . "\n"
			. '-- WordPress: ' . get_bloginfo( 'version' ) . "\n"
			. '-- Table prefix: ' . $wpdb->prefix . "\n\n"
			. 'SET NAMES ' . $wpdb->charset . ";\n"
			. "SET FOREIGN_KEY_CHECKS = 0;\n"
			. "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
	}

	/**
	 * Quote a table name for the dump file.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name Table name.
	 * @return string Quoted name.
	 */
	private static function quote_identifier( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Write to the dump file.
	 *
	 * @since 1.1.0
	 *
	 * @param resource $handle     File handle.
	 * @param bool     $compressed Whether the file is gzip-compressed.
	 * @param string   $data       Data.
	 * @return void
	 * @throws RuntimeException If writing fails.
	 */
	private static function write( $handle, $compressed, $data ) {
		$written = $compressed ? gzwrite( $handle, $data ) : fwrite( $handle, $data );

		if ( false === $written || ( '' !== $data && 0 === $written ) ) {
			self::close( $handle, $compressed );
			throw new RuntimeException( __( 'The database backup file could not be written. The disk may be full.', 'admin-backend-and-update-helper' ) );
		}
	}

	/**
	 * Close the dump file.
	 *
	 * @since 1.1.0
	 *
	 * @param resource $handle     File handle.
	 * @param bool     $compressed Whether the file is gzip-compressed.
	 * @return void
	 */
	private static function close( $handle, $compressed ) {
		if ( $compressed ) {
			gzclose( $handle );
		} else {
			fclose( $handle );
		}
	}
}
