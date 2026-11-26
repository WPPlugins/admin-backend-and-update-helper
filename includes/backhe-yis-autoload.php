<?php
/**
 * Class autoloader.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoload the plugin classes.
 *
 * PHP class names are case-insensitive, so the class names used in 1.0.0
 * (e.g. backhe_yis_update_Checker) still resolve to the renamed classes.
 *
 * @since 1.0.0
 * @since 1.1.0 Named function with an explicit class map instead of a closure.
 *
 * @param string $class_name Name of the class to load.
 * @return void
 */
function backhe_yis_autoload( $class_name ) {
	$class_map = array(
		'backhe_yis_activity_log'   => 'includes/class-backhe-yis-activity-log.php',
		'backhe_yis_admin_actions'  => 'admin/class-backhe-yis-admin-actions.php',
		'backhe_yis_admin_menu'     => 'admin/class-backhe-yis-admin-menu.php',
		'backhe_yis_email_notifier' => 'includes/class-backhe-yis-email-notifier.php',
		'backhe_yis_event_tracker'  => 'includes/class-backhe-yis-event-tracker.php',
		'backhe_yis_legacy_backups' => 'includes/class-backhe-yis-legacy-backups.php',
		'backhe_yis_log_list_table' => 'admin/class-backhe-yis-log-list-table.php',
		'backhe_yis_report'         => 'includes/class-backhe-yis-report.php',
		'backhe_yis_system_info'    => 'includes/class-backhe-yis-system-info.php',
		'backhe_yis_update_checker' => 'includes/class-backhe-yis-update-checker.php',
	);

	$key = strtolower( $class_name );

	if ( isset( $class_map[ $key ] ) ) {
		require_once BACKHE_YIS_PLUGIN_DIR . $class_map[ $key ];
	}
}
spl_autoload_register( 'backhe_yis_autoload' );
