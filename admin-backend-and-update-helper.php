<?php
/**
 * Plugin Name: Admin Backend and Update Helper
 * Description: Update notifications, system information, activity log and service reports for WordPress maintenance.
 * Version: 1.2.0
 * Author: YIS Media
 * Author URI: https://yis-media.com/
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: admin-backend-and-update-helper
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (C) 2025-2026 YIS Media
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation version 2.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BACKHE_YIS_VERSION', '1.2.0' );
define( 'BACKHE_YIS_PLUGIN_FILE', __FILE__ );
define( 'BACKHE_YIS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BACKHE_YIS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Deprecated mixed-case constant names from version 1.0.0, kept so that
 * third-party code using them keeps working. Use the uppercase names above.
 */
// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ConstantNotUpperCase -- Backward compatibility.
define( 'backhe_yis_VERSION', BACKHE_YIS_VERSION );
define( 'backhe_yis_PLUGIN_FILE', BACKHE_YIS_PLUGIN_FILE );
define( 'backhe_yis_PLUGIN_DIR', BACKHE_YIS_PLUGIN_DIR );
define( 'backhe_yis_PLUGIN_URL', BACKHE_YIS_PLUGIN_URL );
// phpcs:enable Generic.NamingConventions.UpperCaseConstantName.ConstantNotUpperCase

require_once BACKHE_YIS_PLUGIN_DIR . 'includes/backhe-yis-autoload.php';

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
class Backhe_YIS_Media {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var Backhe_YIS_Media|null
	 */
	public static $instance;

	/**
	 * Admin menu handler.
	 *
	 * @since 1.0.0
	 * @var Backhe_YIS_Admin_Menu
	 */
	public $admin_menu;

	/**
	 * Email notifier.
	 *
	 * @since 1.0.0
	 * @var Backhe_YIS_Email_Notifier
	 */
	public $email_notifier;

	/**
	 * System information provider.
	 *
	 * @since 1.0.0
	 * @var Backhe_YIS_System_Info
	 */
	public $system_info;

	/**
	 * Update checker.
	 *
	 * @since 1.0.0
	 * @var Backhe_YIS_Update_Checker
	 */
	public $update_checker;

	/**
	 * Activity log storage.
	 *
	 * @since 1.1.0
	 * @var Backhe_YIS_Activity_Log
	 */
	public $activity_log;

	/**
	 * Tracks site changes into the activity log.
	 *
	 * @since 1.1.0
	 * @var Backhe_YIS_Event_Tracker
	 */
	public $event_tracker;

	/**
	 * Service report.
	 *
	 * @since 1.1.0
	 * @var Backhe_YIS_Report
	 */
	public $report;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Backhe_YIS_Media
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Set up the components and register all hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->system_info    = new Backhe_YIS_System_Info();
		$this->update_checker = new Backhe_YIS_Update_Checker();
		$this->email_notifier = new Backhe_YIS_Email_Notifier();
		$this->activity_log   = new Backhe_YIS_Activity_Log();
		$this->event_tracker  = new Backhe_YIS_Event_Tracker( $this->activity_log );
		$this->report         = new Backhe_YIS_Report( $this->activity_log, $this->email_notifier );
		$this->admin_menu     = new Backhe_YIS_Admin_Menu();

		if ( is_admin() ) {
			new Backhe_YIS_Admin_Actions( $this->activity_log );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_settings' ) );
		add_action( 'backhe_yis_log_cleanup', array( $this->activity_log, 'purge_expired' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules' ) );
		add_action( 'add_option_backhe_yis_settings', array( $this, 'settings_added' ) );
		add_action( 'update_option_backhe_yis_settings', array( $this, 'settings_updated' ), 10, 2 );

		// AJAX handlers.
		add_action( 'wp_ajax_backhe_yis_manual_check', array( $this, 'ajax_manual_check' ) );
		add_action( 'wp_ajax_backhe_yis_send_test_email', array( $this, 'ajax_send_test_email' ) );

		// Scheduled events.
		add_action( 'backhe_yis_scheduled_check', array( $this, 'scheduled_check' ) );

		// Notification after completed updates.
		add_action( 'upgrader_process_complete', array( $this, 'notify_on_update' ), 10, 2 );

		register_activation_hook( BACKHE_YIS_PLUGIN_FILE, array( 'Backhe_YIS_Media', 'activate' ) );
		register_deactivation_hook( BACKHE_YIS_PLUGIN_FILE, array( 'Backhe_YIS_Media', 'deactivate' ) );
	}

	/**
	 * Load the bundled translations.
	 *
	 * Translations from translate.wordpress.org in wp-content/languages take
	 * precedence; the bundled files in /languages are the fallback.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Loads the bundled translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		// Loads the bundled German translation until it is available on translate.wordpress.org.
		load_plugin_textdomain( 'admin-backend-and-update-helper', false, dirname( plugin_basename( BACKHE_YIS_PLUGIN_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}

	/**
	 * Run the install routine after the plugin was updated.
	 *
	 * Plugin updates do not trigger the activation hook, so the database
	 * table and cron events are checked whenever the stored version differs.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		// Retry removing old backups if an earlier attempt could not delete the directory.
		if ( false !== get_option( 'backhe_yis_backup_dir_key' ) ) {
			Backhe_YIS_Legacy_Backups::remove();
		}

		if ( BACKHE_YIS_VERSION === get_option( 'backhe_yis_plugin_version' ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Create the database table, schedule the cron events and store the version.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private static function install() {
		Backhe_YIS_Activity_Log::install_table();

		if ( ! wp_next_scheduled( 'backhe_yis_log_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'backhe_yis_log_cleanup' );
		}

		Backhe_YIS_Report::schedule_check();
		Backhe_YIS_Legacy_Backups::remove();

		update_option( 'backhe_yis_plugin_version', BACKHE_YIS_VERSION, false );
	}

	/**
	 * Register the plugin settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'backhe_yis_settings_group',
			'backhe_yis_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize the plugin settings.
	 *
	 * The notification tab and the access tab store their fields in the same
	 * option. Each form submits a "section" marker; only the fields of that
	 * section are replaced (unchecked checkboxes count as "0"), all other
	 * stored values are kept. Without a marker, e.g. on a programmatic
	 * update_option() call, only the fields present in the input are updated.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Merges with stored values and validates against whitelists.
	 *
	 * @param mixed $input Raw settings value.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$stored   = get_option( 'backhe_yis_settings', array() );
		$settings = is_array( $stored ) ? $stored : array();
		$section  = isset( $input['section'] ) && is_string( $input['section'] ) ? sanitize_key( $input['section'] ) : '';
		$sections = array(
			'notifications' => array(
				'email_enabled',
				'notify_on_updates',
				'recipient_type',
				'custom_email',
				'custom_email_2',
				'selected_user',
				'notification_frequency',
				'custom_days',
			),
			'access'        => array(
				'allowed_roles',
			),
		);

		if ( isset( $sections[ $section ] ) ) {
			$keys = $sections[ $section ];
		} else {
			$keys = array_intersect(
				array_merge( $sections['notifications'], $sections['access'] ),
				array_keys( $input )
			);
		}

		foreach ( $keys as $key ) {
			$settings[ $key ] = $this->sanitize_setting_field( $key, isset( $input[ $key ] ) ? $input[ $key ] : null );
		}

		return $settings;
	}

	/**
	 * Sanitize and validate a single settings field.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key   Settings key.
	 * @param mixed  $value Raw value, null if the field was not submitted.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_setting_field( $key, $value ) {
		switch ( $key ) {
			case 'email_enabled':
			case 'notify_on_updates':
				return ( is_scalar( $value ) && '1' === (string) $value ) ? '1' : '0';

			case 'recipient_type':
				$value = is_scalar( $value ) ? sanitize_key( $value ) : '';
				return in_array( $value, array( 'admin', 'user', 'custom' ), true ) ? $value : 'admin';

			case 'custom_email':
			case 'custom_email_2':
				return is_scalar( $value ) ? sanitize_email( $value ) : '';

			case 'selected_user':
				$user_id = is_scalar( $value ) ? absint( $value ) : 0;
				return ( $user_id > 0 && get_userdata( $user_id ) ) ? $user_id : 0;

			case 'notification_frequency':
				$value = is_scalar( $value ) ? sanitize_key( $value ) : '';
				return in_array( $value, array( 'hourly', 'twicedaily', 'daily', 'weekly', 'biweekly', 'monthly', 'custom' ), true ) ? $value : 'daily';

			case 'custom_days':
				return self::clamp_custom_days( $value );

			case 'allowed_roles':
				$all_roles = array_keys( wp_roles()->get_names() );
				$roles     = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
				$roles     = array_values( array_intersect( $roles, $all_roles ) );
				$roles     = empty( $roles ) ? array( 'administrator' ) : $roles;

				// Keep the saving user's own roles allowed, so nobody locks themselves out.
				$own_roles = is_user_logged_in() ? array_intersect( (array) wp_get_current_user()->roles, $all_roles ) : array();

				if ( ! empty( $own_roles ) && ! array_intersect( $own_roles, $roles ) ) {
					$roles = array_values( array_unique( array_merge( $roles, $own_roles ) ) );
				}

				return $roles;
		}

		return '';
	}

	/**
	 * Clamp the custom interval to 1-365 days.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value Raw number of days.
	 * @return int Number of days between 1 and 365.
	 */
	private static function clamp_custom_days( $value ) {
		$days = is_scalar( $value ) ? absint( $value ) : 1;

		return min( 365, max( 1, $days ) );
	}

	/**
	 * Enqueue the admin assets on the plugin's own admin page only.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Fixed the asset paths and removed the duplicated inline script.
	 *
	 * @param string $hook Hook suffix of the current admin page.
	 * @return void
	 */
	public function admin_assets( $hook ) {
		if ( ! in_array( $hook, $this->admin_menu->get_page_hooks(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'backhe-yis-admin-style',
			BACKHE_YIS_PLUGIN_URL . 'admin/css/admin-style.css',
			array(),
			BACKHE_YIS_VERSION
		);

		wp_enqueue_script(
			'backhe-yis-admin-script',
			BACKHE_YIS_PLUGIN_URL . 'admin/js/admin-script.js',
			array( 'jquery', 'wp-a11y' ),
			BACKHE_YIS_VERSION,
			true
		);

		wp_localize_script(
			'backhe-yis-admin-script',
			'backheYisAdmin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'adminEmail'       => get_option( 'admin_email' ),
				'nonceManualCheck' => wp_create_nonce( 'backhe_yis_manual_check' ),
				'nonceTestEmail'   => wp_create_nonce( 'backhe_yis_test_email' ),
				'i18n'             => array(
					'checking'        => __( 'Checking…', 'admin-backend-and-update-helper' ),
					'sending'         => __( 'Sending…', 'admin-backend-and-update-helper' ),
					'requestFailed'   => __( 'The request failed. Please reload the page and try again.', 'admin-backend-and-update-helper' ),
					'enterEmail'      => __( 'Please enter an email address.', 'admin-backend-and-update-helper' ),
					'invalidEmail'    => __( 'Please enter a valid email address.', 'admin-backend-and-update-helper' ),
					'invalidEmail2'   => __( 'Please enter a valid second email address.', 'admin-backend-and-update-helper' ),
					'invalidDays'     => __( 'Please enter a number of days between 1 and 365.', 'admin-backend-and-update-helper' ),
					'confirmEntryDel' => __( 'Delete this entry permanently?', 'admin-backend-and-update-helper' ),
				),
			)
		);
	}

	/**
	 * AJAX: run a manual update check.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Added a capability check.
	 *
	 * @return void
	 */
	public function ajax_manual_check() {
		check_ajax_referer( 'backhe_yis_manual_check', 'nonce' );

		if ( ! Backhe_YIS_Admin_Menu::user_can_access() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'admin-backend-and-update-helper' ) ) );
		}

		$updates      = $this->update_checker->check_all_updates();
		$update_count = $this->update_checker->get_update_count( $updates );
		update_option( 'backhe_yis_last_check', time() );

		wp_send_json_success(
			array(
				'message'   => sprintf(
					/* translators: %d: Number of updates found. */
					_n( 'Check complete: %d update found.', 'Check complete: %d updates found.', $update_count, 'admin-backend-and-update-helper' ),
					$update_count
				),
				'timestamp' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			)
		);
	}

	/**
	 * AJAX: send a test email.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Added a capability check and address validation.
	 *
	 * @return void
	 */
	public function ajax_send_test_email() {
		check_ajax_referer( 'backhe_yis_test_email', 'nonce' );

		if ( ! Backhe_YIS_Admin_Menu::user_can_access() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'admin-backend-and-update-helper' ) ) );
		}

		$recipient   = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
		$recipient_2 = isset( $_POST['recipient_2'] ) ? sanitize_email( wp_unslash( $_POST['recipient_2'] ) ) : '';

		if ( ! is_email( $recipient ) || ( '' !== $recipient_2 && ! is_email( $recipient_2 ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'admin-backend-and-update-helper' ) ) );
		}

		if ( ! $this->email_notifier->send_test_email( $recipient, $recipient_2 ) ) {
			wp_send_json_error( array( 'message' => __( 'The test email could not be sent. Please check the mail configuration of the server.', 'admin-backend-and-update-helper' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Test email sent.', 'admin-backend-and-update-helper' ) ) );
	}

	/**
	 * Cron callback: check for updates and send the notification email.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function scheduled_check() {
		$updates      = $this->update_checker->check_all_updates();
		$update_count = $this->update_checker->get_update_count( $updates );
		if ( $update_count > 0 ) {
			$this->email_notifier->send_notification( $updates );
		}
		update_option( 'backhe_yis_last_check', time() );
	}

	/**
	 * Send a notification after updates were installed.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Upgrader $upgrader_object Upgrader instance (unused).
	 * @param array       $options         Details about the completed update.
	 * @return void
	 */
	public function notify_on_update( $upgrader_object, $options ) {
		$settings = get_option( 'backhe_yis_settings', array() );

		if ( empty( $settings['notify_on_updates'] ) || '1' !== $settings['notify_on_updates'] ) {
			return;
		}

		$this->email_notifier->send_update_completion_notification( $options );
	}

	/**
	 * Activation: schedule the recurring update check.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function activate() {
		$settings  = get_option( 'backhe_yis_settings', array() );
		$frequency = $settings['notification_frequency'] ?? 'daily';

		self::schedule_event( $frequency, $settings );
		self::install();
	}

	/**
	 * (Re)schedule the recurring update check.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Uses permanently registered, prefixed schedules. The number
	 *              of custom days is read from the stored settings.
	 *
	 * @param string $frequency Frequency key from the settings.
	 * @param array  $settings  Unused, kept for backward compatibility.
	 * @return void
	 */
	public static function schedule_event( $frequency, $settings = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Kept for backward compatibility.
		wp_clear_scheduled_hook( 'backhe_yis_scheduled_check' );

		$recurrences = array(
			'hourly'     => 'hourly',
			'twicedaily' => 'twicedaily',
			'daily'      => 'daily',
			'weekly'     => 'weekly',
			'biweekly'   => 'backhe_yis_biweekly',
			'monthly'    => 'backhe_yis_monthly',
			'custom'     => 'backhe_yis_custom_days',
		);

		$recurrence = isset( $recurrences[ $frequency ] ) ? $recurrences[ $frequency ] : 'daily';

		wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, 'backhe_yis_scheduled_check' );
	}

	/**
	 * Register the plugin's cron schedules.
	 *
	 * Hooked permanently to "cron_schedules", so WordPress knows the
	 * intervals on every request, not only during activation.
	 *
	 * @since 1.1.0
	 *
	 * @param array $schedules Registered cron schedules.
	 * @return array Cron schedules including the plugin's intervals.
	 */
	public static function add_cron_schedules( $schedules ) {
		// "weekly" is only part of core since WordPress 5.4.
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once weekly', 'admin-backend-and-update-helper' ),
			);
		}

		$schedules['backhe_yis_biweekly'] = array(
			'interval' => 2 * WEEK_IN_SECONDS,
			'display'  => __( 'Every two weeks', 'admin-backend-and-update-helper' ),
		);

		$schedules['backhe_yis_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Monthly (every 30 days)', 'admin-backend-and-update-helper' ),
		);

		$settings = get_option( 'backhe_yis_settings', array() );
		$days     = self::clamp_custom_days( isset( $settings['custom_days'] ) ? $settings['custom_days'] : 1 );

		$schedules['backhe_yis_custom_days'] = array(
			'interval' => $days * DAY_IN_SECONDS,
			'display'  => sprintf(
				/* translators: %d: Number of days. */
				_n( 'Every %d day', 'Every %d days', $days, 'admin-backend-and-update-helper' ),
				$days
			),
		);

		return $schedules;
	}

	/**
	 * Schedule the update check when the settings are saved for the first time.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function settings_added() {
		$this->reschedule_from_settings( get_option( 'backhe_yis_settings', array() ) );
	}

	/**
	 * Reschedule the update check when the frequency settings change.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public function settings_updated( $old_value, $value ) {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$value     = is_array( $value ) ? $value : array();

		foreach ( array( 'notification_frequency', 'custom_days' ) as $key ) {
			$old = isset( $old_value[ $key ] ) ? (string) $old_value[ $key ] : '';
			$new = isset( $value[ $key ] ) ? (string) $value[ $key ] : '';

			if ( $old !== $new ) {
				$this->reschedule_from_settings( $value );
				return;
			}
		}
	}

	/**
	 * Reschedule the update check based on the given settings.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $settings Plugin settings.
	 * @return void
	 */
	private function reschedule_from_settings( $settings ) {
		$settings  = is_array( $settings ) ? $settings : array();
		$frequency = isset( $settings['notification_frequency'] ) ? $settings['notification_frequency'] : 'daily';

		self::schedule_event( $frequency, $settings );
	}

	/**
	 * Deactivation: remove the scheduled update check.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( self::get_cron_hooks() as $cron_hook ) {
			wp_clear_scheduled_hook( $cron_hook );
		}

		// Re-run the install routine on the next activation.
		delete_option( 'backhe_yis_plugin_version' );
	}

	/**
	 * Get all cron hooks used by the plugin.
	 *
	 * @since 1.1.0
	 *
	 * @return string[] Cron hook names.
	 */
	public static function get_cron_hooks() {
		return array(
			'backhe_yis_scheduled_check',
			'backhe_yis_log_cleanup',
			'backhe_yis_report_check',
		);
	}
}

Backhe_YIS_Media::get_instance();
