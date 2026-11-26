<?php
/**
 * Admin form handlers and notices.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the forms for manual services and shows result notices.
 *
 * @since 1.1.0
 */
class Backhe_YIS_Admin_Actions {

	/**
	 * Activity log.
	 *
	 * @since 1.1.0
	 * @var Backhe_YIS_Activity_Log
	 */
	private $log;

	/**
	 * Register hooks.
	 *
	 * @since 1.1.0
	 *
	 * @param Backhe_YIS_Activity_Log $log Activity log.
	 */
	public function __construct( Backhe_YIS_Activity_Log $log ) {
		$this->log = $log;

		add_action( 'admin_post_backhe_yis_log_save_manual', array( $this, 'handle_save_manual' ) );
		add_action( 'admin_post_backhe_yis_log_delete_manual', array( $this, 'handle_delete_manual' ) );
	}

	/**
	 * Save a new or edited manual service.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function handle_save_manual() {
		$this->check_capability();
		check_admin_referer( 'backhe_yis_log_save_manual' );

		$id          = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
		$date_input  = isset( $_POST['entry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_date'] ) ) : '';
		$duration    = isset( $_POST['duration'] ) && '' !== $_POST['duration'] ? min( 100000, absint( $_POST['duration'] ) ) : null;
		$date        = DateTimeImmutable::createFromFormat( '!Y-m-d', $date_input, wp_timezone() );

		if ( '' === $description || ! $date || $date->format( 'Y-m-d' ) !== $date_input ) {
			$this->redirect( 'backhe_yis_log', 'entry_invalid' );
		}

		// Manual services are stored at noon of the chosen day, so time zone changes keep the date.
		$entry = array(
			'created_at'       => $date->setTime( 12, 0 )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'object_name'      => $description,
			'duration_minutes' => $duration,
		);

		if ( $id > 0 ) {
			$saved = $this->log->update_manual( $id, $entry );
		} else {
			$saved = (bool) $this->log->add(
				array_merge(
					$entry,
					array(
						'event_type'  => 'manual',
						'object_type' => 'service',
						'source'      => 'manual',
					)
				)
			);
		}

		$this->redirect( 'backhe_yis_log', $saved ? 'entry_saved' : 'entry_failed' );
	}

	/**
	 * Delete a manual service.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function handle_delete_manual() {
		$this->check_capability();

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		check_admin_referer( 'backhe_yis_log_delete_' . $id );

		$this->redirect( 'backhe_yis_log', $this->log->delete_manual( $id ) ? 'entry_deleted' : 'entry_failed' );
	}

	/**
	 * Print the notice for the "backhe_message" query argument.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function render_message() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a predefined message.
		$code     = isset( $_GET['backhe_message'] ) ? sanitize_key( wp_unslash( $_GET['backhe_message'] ) ) : '';
		$messages = array(
			'entry_saved'          => array( 'success', __( 'The service was saved.', 'admin-backend-and-update-helper' ) ),
			'entry_deleted'        => array( 'success', __( 'The entry was deleted.', 'admin-backend-and-update-helper' ) ),
			'entry_invalid'        => array( 'error', __( 'Please enter a description and a valid date.', 'admin-backend-and-update-helper' ) ),
			'entry_failed'         => array( 'error', __( 'The entry could not be saved.', 'admin-backend-and-update-helper' ) ),
			'report_sent'          => array( 'success', __( 'The report was sent.', 'admin-backend-and-update-helper' ) ),
			'report_failed'        => array( 'error', __( 'The report could not be sent. Please check the recipients and the mail configuration of the server.', 'admin-backend-and-update-helper' ) ),
			'backup_deleted'       => array( 'success', __( 'The backup was deleted.', 'admin-backend-and-update-helper' ) ),
			'backup_delete_failed' => array( 'error', __( 'The backup could not be deleted.', 'admin-backend-and-update-helper' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Stop if the current user may not use the plugin.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function check_capability() {
		if ( ! Backhe_YIS_Admin_Menu::user_can_access() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'admin-backend-and-update-helper' ), 403 );
		}
	}

	/**
	 * Redirect to a plugin page with a message and exit.
	 *
	 * @since 1.1.0
	 *
	 * @param string $page    Page slug.
	 * @param string $message Message code.
	 * @return void
	 */
	private function redirect( $page, $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => $page,
					'backhe_message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
