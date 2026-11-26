<?php
/**
 * Uninstall routine: removes all data of the plugin.
 *
 * Deletes the options, cron events, the activity log table and the backup
 * directory including all backups of every site.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/backhe-yis-autoload.php';

if ( ! defined( 'BACKHE_YIS_PLUGIN_DIR' ) ) {
	define( 'BACKHE_YIS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Remove the plugin data of the current site.
 *
 * @since 1.1.0
 *
 * @return void
 */
function backhe_yis_uninstall_site() {
	$options = array(
		'backhe_yis_settings',
		'backhe_yis_last_check',
		'backhe_yis_plugin_version',
		'backhe_yis_db_version',
		'backhe_yis_report_settings',
		'backhe_yis_report_state',
		'backhe_yis_backup_settings',
		'backhe_yis_backup_job',
		'backhe_yis_backup_lock',
	);

	$cron_hooks = array(
		'backhe_yis_scheduled_check',
		'backhe_yis_log_cleanup',
		'backhe_yis_report_check',
		'backhe_yis_backup_scheduled',
		'backhe_yis_backup_continue',
	);

	foreach ( $cron_hooks as $cron_hook ) {
		wp_clear_scheduled_hook( $cron_hook );
	}

	// Needs the directory key option, so it runs before the options are deleted.
	Backhe_YIS_Backup_Manager::delete_backup_directory();
	Backhe_YIS_Activity_Log::drop_table();

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $backhe_yis_site_id ) {
		switch_to_blog( $backhe_yis_site_id );
		backhe_yis_uninstall_site();
		restore_current_blog();
	}
} else {
	backhe_yis_uninstall_site();
}
