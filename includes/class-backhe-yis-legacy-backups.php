<?php
/**
 * Removal of the backups created by version 1.1.0.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes the backup directory, options and cron events of the backup feature
 * that existed in version 1.1.0. Backups are made with UpdraftPlus since 1.2.0.
 *
 * Database dumps contain password hashes, so they are not left behind.
 *
 * @since 1.2.0
 */
class Backhe_YIS_Legacy_Backups {

	/**
	 * Options used by the former backup feature.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const OPTIONS = array(
		'backhe_yis_backup_settings',
		'backhe_yis_backup_job',
		'backhe_yis_backup_lock',
		'backhe_yis_backup_dir_key',
	);

	/**
	 * Cron hooks used by the former backup feature.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const CRON_HOOKS = array(
		'backhe_yis_backup_scheduled',
		'backhe_yis_backup_continue',
	);

	/**
	 * Remove the backup directory, options and cron events, if present.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function remove() {
		foreach ( self::CRON_HOOKS as $cron_hook ) {
			wp_clear_scheduled_hook( $cron_hook );
		}

		$key     = get_option( 'backhe_yis_backup_dir_key' );
		$removed = true;

		if ( is_string( $key ) && preg_match( '/^[a-z0-9]{20}$/', $key ) ) {
			$uploads = wp_upload_dir( null, false );
			$dir     = untrailingslashit( $uploads['basedir'] ) . '/backhe-yis-backups-' . $key;

			if ( is_dir( $dir ) ) {
				global $wp_filesystem;

				require_once ABSPATH . 'wp-admin/includes/file.php';

				$removed = WP_Filesystem() && $wp_filesystem && $wp_filesystem->rmdir( $dir, true );
			}
		}

		foreach ( self::OPTIONS as $option ) {
			// Keep the directory key if the directory could not be removed (e.g. FTP-only file access), so a later run can retry.
			if ( 'backhe_yis_backup_dir_key' === $option && ! $removed ) {
				continue;
			}

			delete_option( $option );
		}
	}
}
