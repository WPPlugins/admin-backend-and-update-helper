<?php
/**
 * Admin menu.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's admin menu and sub pages.
 *
 * @since 1.0.0
 * @since 1.1.0 Added the activity log, report and backup sub pages.
 */
class Backhe_YIS_Admin_Menu {

	/**
	 * Capability required for all plugin pages.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Hook suffixes of the registered pages, keyed by page slug.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	private $page_hooks = array();

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
	}

	/**
	 * Add the top-level menu and its sub pages.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		if ( ! self::user_can_access() ) {
			return;
		}

		$this->page_hooks['backhe_yis_admin'] = add_menu_page(
			__( 'Backend Helper', 'admin-backend-and-update-helper' ),
			__( 'Backend Helper', 'admin-backend-and-update-helper' ),
			self::CAPABILITY,
			'backhe_yis_admin',
			array( $this, 'render_admin_page' ),
			'dashicons-shield-alt',
			2
		);

		$pages = array(
			'backhe_yis_admin'   => array(
				'title'    => __( 'Overview', 'admin-backend-and-update-helper' ),
				'callback' => array( $this, 'render_admin_page' ),
			),
			'backhe_yis_log'     => array(
				'title'    => __( 'Activity Log', 'admin-backend-and-update-helper' ),
				'callback' => array( $this, 'render_log_page' ),
			),
			'backhe_yis_report'  => array(
				'title'    => __( 'Service Report', 'admin-backend-and-update-helper' ),
				'callback' => array( $this, 'render_report_page' ),
			),
			'backhe_yis_backups' => array(
				'title'    => __( 'Backups', 'admin-backend-and-update-helper' ),
				'callback' => array( $this, 'render_backup_page' ),
			),
		);

		foreach ( $pages as $slug => $page ) {
			$hook = add_submenu_page(
				'backhe_yis_admin',
				$page['title'],
				$page['title'],
				self::CAPABILITY,
				$slug,
				$page['callback']
			);

			if ( $hook ) {
				$this->page_hooks[ $slug ] = $hook;
			}
		}
	}

	/**
	 * Check whether the current user may use the plugin.
	 *
	 * Requires the "manage_options" capability and, in addition, one of the
	 * roles allowed in the plugin settings. Super admins always have access.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if the user may use the plugin.
	 */
	public static function user_can_access() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}

		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		$settings = get_option( 'backhe_yis_settings', array() );
		$allowed  = isset( $settings['allowed_roles'] ) ? (array) $settings['allowed_roles'] : array( 'administrator' );

		return (bool) array_intersect( (array) wp_get_current_user()->roles, $allowed );
	}

	/**
	 * Get the hook suffixes of all plugin pages.
	 *
	 * @since 1.1.0
	 *
	 * @return string[] Hook suffixes keyed by page slug.
	 */
	public function get_page_hooks() {
		return $this->page_hooks;
	}

	/**
	 * Render the overview page (updates, system, notifications, settings).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_admin_page() {
		$this->render_view( 'admin-page.php' );
	}

	/**
	 * Render the activity log page.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function render_log_page() {
		$this->render_view( 'views/log-page.php' );
	}

	/**
	 * Render the service report page.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function render_report_page() {
		$this->render_view( 'views/report-page.php' );
	}

	/**
	 * Render the backup page.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function render_backup_page() {
		$this->render_view( 'views/backup-page.php' );
	}

	/**
	 * Include a view file from the admin directory.
	 *
	 * @since 1.1.0
	 *
	 * @param string $view View path relative to the admin directory.
	 * @return void
	 */
	private function render_view( $view ) {
		if ( ! self::user_can_access() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'admin-backend-and-update-helper' ) );
		}

		$file = __DIR__ . '/' . $view;

		if ( file_exists( $file ) ) {
			include $file;
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'The admin page could not be found.', 'admin-backend-and-update-helper' ) . '</p></div>';
	}
}
