<?php
/**
 * Activity log storage.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and queries activity log entries in a custom table.
 *
 * All dates are stored in UTC (MySQL DATETIME format).
 *
 * The queries below target the plugin's own table, for which WordPress offers
 * no higher-level API; caching is not used because the data changes with every
 * logged event and is only read on admin pages and when building reports.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * @since 1.1.0
 */
class Backhe_YIS_Activity_Log {

	/**
	 * Version of the table schema.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const DB_VERSION = '1';

	/**
	 * Default number of months entries are kept.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const DEFAULT_RETENTION_MONTHS = 24;

	/**
	 * Get the table name including the site prefix.
	 *
	 * @since 1.1.0
	 *
	 * @return string Table name.
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'backhe_yis_log';
	}

	/**
	 * Create or update the table.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;

		if ( self::DB_VERSION === get_option( 'backhe_yis_db_version' ) && self::table_exists() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  event_type varchar(40) NOT NULL DEFAULT '',
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_name varchar(255) NOT NULL DEFAULT '',
  details longtext NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  duration_minutes int(10) unsigned NULL,
  source varchar(10) NOT NULL DEFAULT 'auto',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY object_lookup (object_type,object_id)
) {$charset_collate};"
		);

		update_option( 'backhe_yis_db_version', self::DB_VERSION, false );
	}

	/**
	 * Check whether the table exists.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if the table exists.
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table_name();

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Drop the table.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table_name() ) );
		delete_option( 'backhe_yis_db_version' );
	}

	/**
	 * Add an entry.
	 *
	 * @since 1.1.0
	 *
	 * @param array $entry {
	 *     Entry data.
	 *
	 *     @type string $event_type       Event key, e.g. "plugin_updated".
	 *     @type string $object_type      Object type, e.g. "plugin".
	 *     @type int    $object_id        Optional object ID (post or user ID).
	 *     @type string $object_name      Human-readable object name.
	 *     @type array  $details          Optional extra data, stored as JSON.
	 *     @type int    $user_id          Optional user ID; defaults to the current user.
	 *     @type int    $duration_minutes Optional duration of manual services.
	 *     @type string $source           "auto" or "manual".
	 *     @type string $created_at       Optional UTC date (Y-m-d H:i:s); defaults to now.
	 * }
	 * @return int|false Entry ID, false on failure.
	 */
	public function add( array $entry ) {
		global $wpdb;

		if ( ! self::table_exists_cached() ) {
			return false;
		}

		$row = $this->prepare_row( $entry );

		$result = $wpdb->insert( self::table_name(), $row, $this->get_formats( $row ) );

		return false === $result ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Update a manual entry.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $id    Entry ID.
	 * @param array $entry Fields to update (created_at, object_name, duration_minutes).
	 * @return bool True on success.
	 */
	public function update_manual( $id, array $entry ) {
		global $wpdb;

		$existing = $this->get( $id );

		if ( null === $existing || 'manual' !== $existing['source'] ) {
			return false;
		}

		$row = array_intersect_key(
			$this->prepare_row( array_merge( $existing, $entry, array( 'source' => 'manual' ) ) ),
			array_flip( array( 'created_at', 'object_name', 'duration_minutes' ) )
		);

		return false !== $wpdb->update( self::table_name(), $row, array( 'id' => (int) $id ), $this->get_formats( $row ), array( '%d' ) );
	}

	/**
	 * Increase the counter of an aggregated entry, e.g. repeated edits of one post per day.
	 *
	 * @since 1.1.0
	 *
	 * @param int $id Entry ID.
	 * @return bool True on success.
	 */
	public function increment_count( $id ) {
		global $wpdb;

		$entry = $this->get( $id );

		if ( null === $entry ) {
			return false;
		}

		$details          = $entry['details'];
		$details['count'] = isset( $details['count'] ) ? absint( $details['count'] ) + 1 : 2;

		return false !== $wpdb->update(
			self::table_name(),
			array( 'details' => wp_json_encode( $details ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a manual entry. Automatic entries cannot be deleted.
	 *
	 * @since 1.1.0
	 *
	 * @param int $id Entry ID.
	 * @return bool True on success.
	 */
	public function delete_manual( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete(
			self::table_name(),
			array(
				'id'     => (int) $id,
				'source' => 'manual',
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Get a single entry.
	 *
	 * @since 1.1.0
	 *
	 * @param int $id Entry ID.
	 * @return array|null Entry, null if not found.
	 */
	public function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), (int) $id ),
			ARRAY_A
		);

		return $row ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Find today's entry for an object, used to aggregate repeated events.
	 *
	 * @since 1.1.0
	 *
	 * @param string $event_type  Event key.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param string $since_utc   Start of the current day in UTC (Y-m-d H:i:s).
	 * @return int Entry ID, 0 if none.
	 */
	public function find_entry_since( $event_type, $object_type, $object_id, $since_utc ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE event_type = %s AND object_type = %s AND object_id = %d AND created_at >= %s ORDER BY id DESC LIMIT 1',
				self::table_name(),
				$event_type,
				$object_type,
				(int) $object_id,
				$since_utc
			)
		);
	}

	/**
	 * Query entries.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type string $from        Inclusive start, UTC (Y-m-d H:i:s).
	 *     @type string $to          Exclusive end, UTC (Y-m-d H:i:s).
	 *     @type string $object_type Restrict to one object type.
	 *     @type string   $source      Restrict to "auto" or "manual".
	 *     @type string[] $event_types Restrict to these event types.
	 *     @type int    $limit       Maximum number of entries; 0 for all.
	 *     @type int    $offset      Offset.
	 *     @type string $order       "DESC" (default) or "ASC".
	 * }
	 * @return array[] Entries.
	 */
	public function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'limit'  => 0,
				'offset' => 0,
				'order'  => 'DESC',
			)
		);

		list( $where, $values ) = $this->build_where( $args );

		$order = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$sql   = 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY created_at ' . $order . ', id ' . $order;

		if ( $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$values[] = (int) $args['limit'];
			$values[] = (int) $args['offset'];
		}

		// The WHERE clause only contains fixed column names and placeholders built in build_where().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::table_name() ), $values ) ), ARRAY_A );

		return array_map( array( $this, 'normalize_row' ), (array) $rows );
	}

	/**
	 * Count entries.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args Query arguments, see query().
	 * @return int Number of entries.
	 */
	public function count( array $args = array() ) {
		global $wpdb;

		list( $where, $values ) = $this->build_where( $args );

		// The WHERE clause only contains fixed column names and placeholders built in build_where().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where, array_merge( array( self::table_name() ), $values ) ) );
	}

	/**
	 * Delete entries older than the configured retention period.
	 *
	 * Hooked to the daily "backhe_yis_log_cleanup" cron event.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function purge_expired() {
		global $wpdb;

		if ( ! self::table_exists_cached() ) {
			return;
		}

		$months = self::get_retention_months();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table_name(), $cutoff ) );
	}

	/**
	 * Get the retention period in months.
	 *
	 * @since 1.1.0
	 *
	 * @return int Months (1-120).
	 */
	public static function get_retention_months() {
		$settings = get_option( 'backhe_yis_report_settings', array() );
		$months   = isset( $settings['retention_months'] ) ? absint( $settings['retention_months'] ) : self::DEFAULT_RETENTION_MONTHS;

		return min( 120, max( 1, $months ) );
	}

	/**
	 * Check once per request whether the table exists.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if the table exists.
	 */
	private static function table_exists_cached() {
		static $exists = null;

		if ( null === $exists ) {
			$exists = self::DB_VERSION === get_option( 'backhe_yis_db_version' ) || self::table_exists();
		}

		return $exists;
	}

	/**
	 * Build the WHERE clause and values for query() and count().
	 *
	 * @since 1.1.0
	 *
	 * @param array $args Query arguments.
	 * @return array Array with the WHERE clause and the placeholder values.
	 */
	private function build_where( array $args ) {
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = (string) $args['from'];
		}

		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at < %s';
			$values[] = (string) $args['to'];
		}

		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$values[] = (string) $args['object_type'];
		}

		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$values[] = (string) $args['source'];
		}

		if ( ! empty( $args['event_types'] ) && is_array( $args['event_types'] ) ) {
			$event_types = array_values( array_map( 'strval', $args['event_types'] ) );
			$where[]     = 'event_type IN (' . implode( ',', array_fill( 0, count( $event_types ), '%s' ) ) . ')';
			$values      = array_merge( $values, $event_types );
		}

		return array( implode( ' AND ', $where ), $values );
	}

	/**
	 * Turn entry data into a database row.
	 *
	 * @since 1.1.0
	 *
	 * @param array $entry Entry data, see add().
	 * @return array Row.
	 */
	private function prepare_row( array $entry ) {
		$details = isset( $entry['details'] ) && is_array( $entry['details'] ) ? $entry['details'] : array();
		$source  = isset( $entry['source'] ) && 'manual' === $entry['source'] ? 'manual' : 'auto';

		$row = array(
			'created_at'  => ! empty( $entry['created_at'] ) ? (string) $entry['created_at'] : current_time( 'mysql', true ),
			'event_type'  => isset( $entry['event_type'] ) ? substr( sanitize_key( $entry['event_type'] ), 0, 40 ) : '',
			'object_type' => isset( $entry['object_type'] ) ? substr( sanitize_key( $entry['object_type'] ), 0, 20 ) : '',
			'object_id'   => isset( $entry['object_id'] ) ? absint( $entry['object_id'] ) : 0,
			'object_name' => isset( $entry['object_name'] ) ? mb_substr( wp_strip_all_tags( (string) $entry['object_name'] ), 0, 255 ) : '',
			'details'     => wp_json_encode( $details ),
			'user_id'     => isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : get_current_user_id(),
			'source'      => $source,
		);

		if ( isset( $entry['duration_minutes'] ) && '' !== $entry['duration_minutes'] && null !== $entry['duration_minutes'] ) {
			$row['duration_minutes'] = min( 100000, absint( $entry['duration_minutes'] ) );
		}

		return $row;
	}

	/**
	 * Get the $wpdb formats for a row.
	 *
	 * @since 1.1.0
	 *
	 * @param array $row Row.
	 * @return string[] Formats in the order of the row keys.
	 */
	private function get_formats( array $row ) {
		$int_columns = array( 'object_id', 'user_id', 'duration_minutes' );
		$formats     = array();

		foreach ( array_keys( $row ) as $column ) {
			$formats[] = in_array( $column, $int_columns, true ) ? '%d' : '%s';
		}

		return $formats;
	}

	/**
	 * Decode a database row.
	 *
	 * @since 1.1.0
	 *
	 * @param array $row Raw row.
	 * @return array Entry with decoded details and typed values.
	 */
	public function normalize_row( array $row ) {
		$details = json_decode( (string) $row['details'], true );

		$row['id']               = (int) $row['id'];
		$row['object_id']        = (int) $row['object_id'];
		$row['user_id']          = (int) $row['user_id'];
		$row['duration_minutes'] = null === $row['duration_minutes'] ? null : (int) $row['duration_minutes'];
		$row['details']          = is_array( $details ) ? $details : array();

		return $row;
	}
}
