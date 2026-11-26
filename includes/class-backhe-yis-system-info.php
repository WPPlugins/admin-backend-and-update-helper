<?php
/**
 * System Info Class
 *
 * Gibt Systemstatus-Informationen als Array zurück
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects server, PHP, database, WordPress, theme and plugin information.
 *
 * @since 1.0.0
 */
class Backhe_YIS_System_Info {

	/**
	 * Collect all system information.
	 *
	 * @since 1.0.0
	 *
	 * @return array System information grouped by area.
	 */
	public function get_all_info() {
		global $wpdb;

		$theme   = wp_get_theme();
		$plugins = get_plugins();
		$active  = get_option( 'active_plugins', array() );

		$extensions = array();
		foreach ( array( 'curl', 'gd', 'mbstring', 'xml', 'json', 'openssl', 'zip', 'dom', 'simplexml' ) as $ext ) {
			$extensions[ $ext ] = extension_loaded( $ext );
		}

		// The functions may be disabled via disable_functions; they return false on failure.
		$disk_total      = function_exists( 'disk_total_space' ) ? disk_total_space( ABSPATH ) : false;
		$disk_free       = function_exists( 'disk_free_space' ) ? disk_free_space( ABSPATH ) : false;
		$disk_total      = false === $disk_total ? 0 : $disk_total;
		$disk_free       = false === $disk_free ? 0 : $disk_free;
		$used            = $disk_total - $disk_free;
		$used_percentage = $disk_total > 0 ? round( ( $used / $disk_total ) * 100 ) : 0;

		// Calculate the website size.
		$website_size = $this->get_directory_size( ABSPATH );

		// PHP version check.
		$current_php = phpversion();

		/**
		 * Filters the latest stable PHP version shown on the system page.
		 *
		 * The plugin makes no external requests, so the value is shipped with
		 * each plugin release and can be overridden with this filter.
		 *
		 * @since 1.1.0
		 *
		 * @param string $version Latest stable PHP version.
		 */
		$latest_stable_php = (string) apply_filters( 'backhe_yis_latest_stable_php', '8.6.0' );

		/**
		 * Filters the PHP version below which an update is recommended.
		 *
		 * Defaults to the oldest PHP branch that still receives active support.
		 *
		 * @since 1.1.0
		 *
		 * @param string $version Minimum recommended PHP version.
		 */
		$recommended_php    = (string) apply_filters( 'backhe_yis_recommended_php', '8.3.0' );
		$update_recommended = version_compare( $current_php, $recommended_php, '<' );

		/*
		 * Database information. WordPress has no API for these values, and they
		 * are only read on the plugin's system page.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$db_size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) 
         FROM information_schema.TABLES 
         WHERE table_schema = %s',
				DB_NAME
			)
		);

		$table_count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) 
         FROM information_schema.TABLES 
         WHERE table_schema = %s',
				DB_NAME
			)
		);

		$max_allowed_packet = $wpdb->get_var( "SHOW VARIABLES LIKE 'max_allowed_packet'", 1 );
		$max_connections    = $wpdb->get_var( "SHOW VARIABLES LIKE 'max_connections'", 1 );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'server'     => array(
				'software'     => isset( $_SERVER['SERVER_SOFTWARE'] )
					? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
					: __( 'Unknown', 'admin-backend-and-update-helper' ),
				'os'           => php_uname(),
				'hostname'     => php_uname( 'n' ),
				'architecture' => php_uname( 'm' ),
			),
			'php'        => array(
				'version'             => $current_php,
				'latest_stable'       => $latest_stable_php,
				'update_recommended'  => $update_recommended,
				'memory_limit'        => ini_get( 'memory_limit' ),
				'max_execution_time'  => ini_get( 'max_execution_time' ),
				'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
				'post_max_size'       => ini_get( 'post_max_size' ),
				'max_input_vars'      => ini_get( 'max_input_vars' ),
				'display_errors'      => (bool) ini_get( 'display_errors' ),
				'allow_url_fopen'     => (bool) ini_get( 'allow_url_fopen' ),
			),
			'database'   => array(
				'type'               => 'MySQL',
				'version'            => $wpdb->db_version(),
				'charset'            => $wpdb->charset,
				'collation'          => $wpdb->collate,
				'prefix'             => $wpdb->prefix,
				'max_allowed_packet' => $max_allowed_packet ? size_format( $max_allowed_packet ) : __( 'Unknown', 'admin-backend-and-update-helper' ),
				'max_connections'    => $max_connections ? $max_connections : __( 'Unknown', 'admin-backend-and-update-helper' ),
				'table_count'        => $table_count,
				'database_size'      => $db_size ? size_format( $db_size ) : __( 'Unknown', 'admin-backend-and-update-helper' ),
			),
			'wordpress'  => array(
				'version'             => get_bloginfo( 'version' ),
				'multisite'           => is_multisite(),
				'language'            => get_locale(),
				'debug_mode'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_memory_limit'     => WP_MEMORY_LIMIT,
				'wp_max_memory_limit' => WP_MAX_MEMORY_LIMIT,
				'home_url'            => home_url(),
				'site_url'            => site_url(),
			),
			'theme'      => array(
				'active' => array(
					'name'       => $theme->get( 'Name' ),
					'version'    => $theme->get( 'Version' ),
					'author'     => $theme->get( 'Author' ),
					'template'   => $theme->get( 'Template' ),
					'stylesheet' => $theme->get_stylesheet(),
				),
				'all'    => $this->get_all_themes(),
			),
			'plugins'    => array(
				'total'    => count( $plugins ),
				'active'   => count( $active ),
				'inactive' => count( $plugins ) - count( $active ),
				'list'     => $this->get_all_plugins(),
			),
			'extensions' => $extensions,
			'disk'       => array(
				'total_formatted' => size_format( $disk_total ),
				'free_formatted'  => size_format( $disk_free ),
				'used_formatted'  => size_format( $used ),
				'used_percentage' => $used_percentage,
				'website_size'    => size_format( $website_size ),
			),
		);
	}

	/**
	 * Calculate the size of a directory recursively.
	 *
	 * Stops after 5 seconds and returns the size counted so far.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Directory path.
	 * @return int Size in bytes.
	 */
	private function get_directory_size( $path ) {
		$total_size = 0;
		$timeout    = time() + 5; // 5 second timeout.

		if ( is_dir( $path ) ) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $files as $file ) {
				if ( time() > $timeout ) {
					break; // Timeout reached.
				}

				if ( $file->isFile() ) {
					$total_size += $file->getSize();
				}
			}
		}

		return $total_size;
	}

	/**
	 * Get all installed themes.
	 *
	 * @since 1.0.0
	 *
	 * @return array[] List with name, version, author and active state.
	 */
	private function get_all_themes() {
		$all_themes    = wp_get_themes();
		$current_theme = wp_get_theme();
		$themes_list   = array();

		foreach ( $all_themes as $theme_slug => $theme ) {
			$themes_list[] = array(
				'name'      => $theme->get( 'Name' ),
				'version'   => $theme->get( 'Version' ),
				'author'    => wp_strip_all_tags( $theme->get( 'Author' ) ),
				'is_active' => ( $current_theme->get_stylesheet() === $theme_slug ),
			);
		}

		return $themes_list;
	}

	/**
	 * Get all installed plugins.
	 *
	 * @since 1.0.0
	 *
	 * @return array[] List with name, version, author and active state.
	 */
	private function get_all_plugins() {
		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$plugins_list   = array();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$plugins_list[] = array(
				'name'      => $plugin_data['Name'],
				'version'   => $plugin_data['Version'],
				'author'    => wp_strip_all_tags( $plugin_data['Author'] ),
				'is_active' => in_array( $plugin_file, $active_plugins, true ),
			);
		}

		return $plugins_list;
	}
}
