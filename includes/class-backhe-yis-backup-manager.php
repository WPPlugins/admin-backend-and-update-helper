<?php
/**
 * Backup manager.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs backups as resumable jobs, stores them in a protected directory and
 * handles listing, downloading, deleting and retention.
 *
 * A job is processed in steps of a few seconds each, so it also works on
 * shared hosting with short execution time limits. Steps are triggered by
 * the admin page via AJAX (manual backups) and by single WP-Cron events
 * (scheduled backups, and as a fallback when the admin page is closed).
 *
 * Streaming backup files requires direct file access; the WP_Filesystem API
 * offers no streaming or appending.
 *
 * Exception messages are caught internally, stored and escaped on output.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped
 *
 * @since 1.1.0
 */
class Backhe_YIS_Backup_Manager {

	/**
	 * Option name of the settings.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OPTION = 'backhe_yis_backup_settings';

	/**
	 * Option name of the current job.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const JOB_OPTION = 'backhe_yis_backup_job';

	/**
	 * Option name of the random directory key.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const DIR_KEY_OPTION = 'backhe_yis_backup_dir_key';

	/**
	 * Option name of the step lock.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const LOCK_OPTION = 'backhe_yis_backup_lock';

	/**
	 * Seconds of work per step.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const STEP_SECONDS = 15;

	/**
	 * Seconds without progress after which a running job counts as stalled.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const STALE_SECONDS = 900;

	/**
	 * Minimum free disk space in bytes required to start a backup.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const MIN_FREE_SPACE = 104857600;

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

		add_action( 'init', array( $this, 'register_settings' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'schedule_from_settings' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'schedule_from_settings' ) );

		add_action( 'backhe_yis_backup_scheduled', array( $this, 'start_scheduled_backup' ) );
		add_action( 'backhe_yis_backup_continue', array( $this, 'continue_job' ) );

		add_action( 'wp_ajax_backhe_yis_backup_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_backhe_yis_backup_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_backhe_yis_backup_cancel', array( $this, 'ajax_cancel' ) );

		add_action( 'admin_post_backhe_yis_backup_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_backhe_yis_backup_delete', array( $this, 'handle_delete' ) );
	}

	/**
	 * Register the settings.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'backhe_yis_backup_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::get_defaults(),
			)
		);
	}

	/**
	 * Get the default settings.
	 *
	 * @since 1.1.0
	 *
	 * @return array Default settings.
	 */
	public static function get_defaults() {
		return array(
			'schedule' => 'off',
			'hour'     => 3,
			'scope'    => 'full',
			'keep'     => 5,
		);
	}

	/**
	 * Get the settings merged with the defaults.
	 *
	 * @since 1.1.0
	 *
	 * @return array Settings.
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_defaults() );
	}

	/**
	 * Sanitize the settings.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $input Raw settings.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::get_defaults();
		$schedule = isset( $input['schedule'] ) ? sanitize_key( $input['schedule'] ) : '';
		$scope    = isset( $input['scope'] ) ? sanitize_key( $input['scope'] ) : '';
		$hour     = isset( $input['hour'] ) && is_numeric( $input['hour'] ) ? (int) $input['hour'] : $defaults['hour'];
		$keep     = isset( $input['keep'] ) && is_numeric( $input['keep'] ) ? (int) $input['keep'] : $defaults['keep'];

		return array(
			'schedule' => array_key_exists( $schedule, self::get_schedules() ) ? $schedule : $defaults['schedule'],
			'hour'     => min( 23, max( 0, $hour ) ),
			'scope'    => array_key_exists( $scope, self::get_scopes() ) ? $scope : $defaults['scope'],
			'keep'     => min( 50, max( 1, $keep ) ),
		);
	}

	/**
	 * Get the available schedules.
	 *
	 * @since 1.1.0
	 *
	 * @return string[] Labels keyed by schedule.
	 */
	public static function get_schedules() {
		return array(
			'off'     => __( 'No automatic backups', 'admin-backend-and-update-helper' ),
			'daily'   => __( 'Daily', 'admin-backend-and-update-helper' ),
			'weekly'  => __( 'Weekly', 'admin-backend-and-update-helper' ),
			'monthly' => __( 'Monthly', 'admin-backend-and-update-helper' ),
		);
	}

	/**
	 * Get the available backup scopes.
	 *
	 * @since 1.1.0
	 *
	 * @return string[] Labels keyed by scope.
	 */
	public static function get_scopes() {
		return array(
			'full'     => __( 'Database and files (wp-content)', 'admin-backend-and-update-helper' ),
			'database' => __( 'Database only', 'admin-backend-and-update-helper' ),
		);
	}

	/**
	 * Check whether file backups are possible on this server.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if the zip extension is available.
	 */
	public static function can_backup_files() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * (Re)schedule the automatic backup according to the settings.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function schedule_from_settings() {
		wp_clear_scheduled_hook( 'backhe_yis_backup_scheduled' );

		$settings    = self::get_settings();
		$recurrences = array(
			'daily'   => 'daily',
			'weekly'  => 'weekly',
			'monthly' => 'backhe_yis_monthly',
		);

		if ( ! isset( $recurrences[ $settings['schedule'] ] ) ) {
			return;
		}

		$next = new DateTimeImmutable( 'today', wp_timezone() );
		$next = $next->setTime( (int) $settings['hour'], 0 );

		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}

		wp_schedule_event( $next->getTimestamp(), $recurrences[ $settings['schedule'] ], 'backhe_yis_backup_scheduled' );
	}

	/**
	 * Get the backup directory, creating and protecting it if needed.
	 *
	 * @since 1.1.0
	 *
	 * @return string|WP_Error Absolute path without trailing slash, or error.
	 */
	public static function get_backup_dir() {
		$path = self::get_backup_dir_path( true );

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return new WP_Error( 'backhe_yis_backup_dir', __( 'The backup directory could not be created. Please check the permissions of the uploads directory.', 'admin-backend-and-update-helper' ) );
		}

		$protection = array(
			'.htaccess'  => "# Deny direct access to backups.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);

		foreach ( $protection as $file => $content ) {
			if ( ! file_exists( $path . '/' . $file ) ) {
				file_put_contents( $path . '/' . $file, $content );
			}
		}

		return $path;
	}

	/**
	 * Get the path of the backup directory.
	 *
	 * The directory name contains a random key so that backups cannot be
	 * guessed on servers that ignore .htaccess files (e.g. nginx).
	 *
	 * @since 1.1.0
	 *
	 * @param bool $create_key Whether to create the key if missing.
	 * @return string Absolute path without trailing slash; empty if no key exists and none was created.
	 */
	public static function get_backup_dir_path( $create_key = false ) {
		$key = get_option( self::DIR_KEY_OPTION );

		if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9]{20}$/', $key ) ) {
			if ( ! $create_key ) {
				return '';
			}

			$key = strtolower( wp_generate_password( 20, false, false ) );
			update_option( self::DIR_KEY_OPTION, $key, false );
		}

		$uploads = wp_upload_dir( null, false );

		return untrailingslashit( $uploads['basedir'] ) . '/backhe-yis-backups-' . $key;
	}

	/**
	 * Get the current job.
	 *
	 * @since 1.1.0
	 *
	 * @return array|null Job, null if none.
	 */
	public static function get_job() {
		$job = get_option( self::JOB_OPTION );

		return is_array( $job ) ? $job : null;
	}

	/**
	 * Check whether a job is running.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if a job is running.
	 */
	public static function is_running() {
		$job = self::get_job();

		return null !== $job && 'running' === $job['status'];
	}

	/**
	 * Start a backup job.
	 *
	 * @since 1.1.0
	 *
	 * @param string $scope   "full" or "database".
	 * @param string $trigger "manual" or "scheduled".
	 * @return array|WP_Error Job, or error.
	 */
	public function start( $scope, $trigger ) {
		$this->fail_stalled_job();

		if ( self::is_running() ) {
			return new WP_Error( 'backhe_yis_backup_running', __( 'A backup is already running.', 'admin-backend-and-update-helper' ) );
		}

		if ( 'full' === $scope && ! self::can_backup_files() ) {
			return new WP_Error( 'backhe_yis_backup_zip', __( 'File backups need the PHP extension "zip", which is not available on this server. Please create a database backup or ask your host to enable the extension.', 'admin-backend-and-update-helper' ) );
		}

		$dir = self::get_backup_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$free = function_exists( 'disk_free_space' ) ? disk_free_space( $dir ) : false;

		if ( false !== $free && $free < self::MIN_FREE_SPACE ) {
			return new WP_Error(
				'backhe_yis_backup_space',
				sprintf(
					/* translators: %s: Free disk space, e.g. "80 MB". */
					__( 'There is not enough free disk space for a backup (%s free).', 'admin-backend-and-update-helper' ),
					size_format( $free )
				)
			);
		}

		$job = array(
			'id'         => 'backup-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) ),
			'scope'      => 'full' === $scope ? 'full' : 'database',
			'trigger'    => 'scheduled' === $trigger ? 'scheduled' : 'manual',
			'user_id'    => get_current_user_id(),
			'status'     => 'running',
			'phase'      => 'database',
			'started_at' => time(),
			'updated_at' => time(),
			'files'      => array(),
			'error'      => '',
		);

		$job = Backhe_YIS_Backup_Database::init( $job, $dir );

		if ( 'full' === $job['scope'] ) {
			$job = Backhe_YIS_Backup_Files::init( $job, $dir );
		}

		update_option( self::JOB_OPTION, $job, false );
		$this->schedule_continue();

		return $job;
	}

	/**
	 * Process the current job for a few seconds.
	 *
	 * @since 1.1.0
	 *
	 * @return array|null Job after the step, null if there is none.
	 */
	public function run_step() {
		$this->fail_stalled_job();

		$job = self::get_job();

		if ( null === $job || 'running' !== $job['status'] ) {
			return $job;
		}

		if ( ! $this->acquire_lock() ) {
			return $job;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( self::STEP_SECONDS * 4 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A step may need more than the host's default limit; it is bounded by STEP_SECONDS.
		}

		$deadline = microtime( true ) + self::STEP_SECONDS;
		$dir      = self::get_backup_dir();

		if ( is_wp_error( $dir ) ) {
			$job = $this->fail( $job, $dir->get_error_message() );
		} else {
			try {
				while ( 'running' === $job['status'] && microtime( true ) < $deadline ) {
					$job = $this->process_phase( $job, $dir, $deadline );
				}
			} catch ( Exception $e ) {
				$job = $this->fail( $job, $e->getMessage() );
			}
		}

		// Do not overwrite a cancellation that happened during this step.
		$stored = self::get_job();

		if ( null !== $stored && $stored['id'] === $job['id'] && 'cancelled' === $stored['status'] ) {
			$this->release_lock();
			return $stored;
		}

		$job['updated_at'] = time();
		update_option( self::JOB_OPTION, $job, false );
		$this->release_lock();

		if ( 'running' === $job['status'] ) {
			$this->schedule_continue();
		}

		return $job;
	}

	/**
	 * Cron callback: start the scheduled backup.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function start_scheduled_backup() {
		$settings = self::get_settings();
		$job      = $this->start( $settings['scope'], 'scheduled' );

		if ( is_wp_error( $job ) && 'backhe_yis_backup_running' !== $job->get_error_code() ) {
			$this->log_failure( $settings['scope'], 'scheduled', $job->get_error_message() );
		}
	}

	/**
	 * Cron callback: continue the current job.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function continue_job() {
		$this->run_step();
	}

	/**
	 * Cancel the current job and remove its partial files.
	 *
	 * @since 1.1.0
	 *
	 * @return array|null Cancelled job, null if none was running.
	 */
	public function cancel() {
		$job = self::get_job();

		if ( null === $job || 'running' !== $job['status'] ) {
			return null;
		}

		$job['status']     = 'cancelled';
		$job['updated_at'] = time();
		update_option( self::JOB_OPTION, $job, false );

		self::delete_job_files( $job );
		wp_clear_scheduled_hook( 'backhe_yis_backup_continue' );

		return $job;
	}

	/**
	 * Abort a running job, e.g. on deactivation, and log it as failed.
	 *
	 * @since 1.1.0
	 *
	 * @param string $reason Reason shown in the log.
	 * @return void
	 */
	public static function abort_running_job( $reason ) {
		if ( ! self::is_running() ) {
			return;
		}

		$plugin = Backhe_YIS_Media::get_instance();
		$plugin->backup_manager->fail( self::get_job(), $reason );
	}

	/**
	 * AJAX: start a manual backup.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function ajax_start() {
		$this->verify_ajax_request();

		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'full'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax_request().
		$job   = $this->start( 'database' === $scope ? 'database' : 'full', 'manual' );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		wp_send_json_success( self::describe_job( $job ) );
	}

	/**
	 * AJAX: process the current job for a few seconds and report the progress.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function ajax_step() {
		$this->verify_ajax_request();

		$job = $this->run_step();

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'There is no backup running.', 'admin-backend-and-update-helper' ) ) );
		}

		wp_send_json_success( self::describe_job( $job ) );
	}

	/**
	 * AJAX: cancel the current job.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function ajax_cancel() {
		$this->verify_ajax_request();

		$job = $this->cancel();

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'There is no backup running.', 'admin-backend-and-update-helper' ) ) );
		}

		wp_send_json_success( self::describe_job( $job ) );
	}

	/**
	 * Describe a job for the admin page.
	 *
	 * @since 1.1.0
	 *
	 * @param array $job Job.
	 * @return array Status, progress (0-100), message and error.
	 */
	public static function describe_job( array $job ) {
		$progress = 0;
		$message  = '';

		switch ( $job['phase'] ) {
			case 'database':
				$tables   = max( 1, count( $job['db']['tables'] ) );
				$share    = 'full' === $job['scope'] ? 30 : 95;
				$progress = (int) floor( $share * $job['db']['table_index'] / $tables );
				$message  = sprintf(
					/* translators: 1: Current table number, 2: Number of tables. */
					__( 'Exporting the database: table %1$d of %2$d', 'admin-backend-and-update-helper' ),
					min( $tables, $job['db']['table_index'] + 1 ),
					$tables
				);
				break;

			case 'files_scan':
				$progress = 30;
				$message  = sprintf(
					/* translators: %s: Number of files found so far. */
					__( 'Collecting files: %s found', 'admin-backend-and-update-helper' ),
					number_format_i18n( $job['fs']['count'] )
				);
				break;

			case 'files_zip':
				$total    = max( 1, $job['fs']['count'] );
				$progress = 35 + (int) floor( 60 * $job['fs']['zipped'] / $total );
				$message  = sprintf(
					/* translators: 1: Number of compressed files, 2: Number of files. */
					__( 'Compressing files: %1$s of %2$s', 'admin-backend-and-update-helper' ),
					number_format_i18n( $job['fs']['zipped'] ),
					number_format_i18n( $total )
				);
				break;

			case 'finalize':
				$progress = 98;
				$message  = __( 'Finishing the backup…', 'admin-backend-and-update-helper' );
				break;
		}

		if ( 'done' === $job['status'] ) {
			$progress = 100;
			$message  = __( 'Backup completed.', 'admin-backend-and-update-helper' );
		} elseif ( 'failed' === $job['status'] ) {
			$message = __( 'Backup failed.', 'admin-backend-and-update-helper' );
		} elseif ( 'cancelled' === $job['status'] ) {
			$message = __( 'Backup cancelled.', 'admin-backend-and-update-helper' );
		}

		return array(
			'status'   => $job['status'],
			'progress' => min( 100, max( 0, $progress ) ),
			'message'  => $message,
			'error'    => (string) $job['error'],
		);
	}

	/**
	 * List the completed backups, newest first.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Manifests.
	 */
	public static function list_backups() {
		$dir = self::get_backup_dir_path();

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$backups = array();

		foreach ( (array) glob( $dir . '/backup-*.json' ) as $file ) {
			$manifest = json_decode( (string) file_get_contents( $file ), true );

			if ( is_array( $manifest ) && isset( $manifest['id'], $manifest['files'] ) && self::is_valid_id( $manifest['id'] ) ) {
				$backups[] = $manifest;
			}
		}

		usort(
			$backups,
			static function ( $a, $b ) {
				return (int) $b['created_at'] - (int) $a['created_at'];
			}
		);

		return $backups;
	}

	/**
	 * Delete a backup and its files.
	 *
	 * @since 1.1.0
	 *
	 * @param string $id Backup ID.
	 * @return bool True if the backup existed and was deleted.
	 */
	public static function delete_backup( $id ) {
		$dir = self::get_backup_dir_path();

		if ( '' === $dir || ! self::is_valid_id( $id ) || ! file_exists( $dir . '/' . $id . '.json' ) ) {
			return false;
		}

		foreach ( self::list_backups() as $backup ) {
			if ( $backup['id'] !== $id ) {
				continue;
			}

			foreach ( $backup['files'] as $file ) {
				if ( isset( $file['name'] ) && self::is_valid_file_name( $file['name'] ) ) {
					wp_delete_file( $dir . '/' . $file['name'] );
				}
			}
		}

		wp_delete_file( $dir . '/' . $id . '.json' );

		return true;
	}

	/**
	 * Admin action: stream a backup file to the browser.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function handle_download() {
		if ( ! Backhe_YIS_Admin_Menu::user_can_access() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'admin-backend-and-update-helper' ), 403 );
		}

		check_admin_referer( 'backhe_yis_backup_download' );

		// sanitize_file_name() would alter the double extension ".sql.gz"; the name is validated against a strict pattern instead.
		$name = isset( $_GET['file'] ) ? basename( sanitize_text_field( wp_unslash( $_GET['file'] ) ) ) : '';
		$dir  = self::get_backup_dir_path();
		$path = $dir . '/' . $name;

		if ( '' === $dir || ! self::is_valid_file_name( $name ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The backup file could not be found.', 'admin-backend-and-update-helper' ), 404 );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		$handle = fopen( $path, 'rb' );

		if ( false !== $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file download.
				flush();
			}

			fclose( $handle );
		}

		exit;
	}

	/**
	 * Admin action: delete a backup.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function handle_delete() {
		if ( ! Backhe_YIS_Admin_Menu::user_can_access() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'admin-backend-and-update-helper' ), 403 );
		}

		check_admin_referer( 'backhe_yis_backup_delete' );

		$id      = isset( $_POST['backup_id'] ) ? sanitize_key( wp_unslash( $_POST['backup_id'] ) ) : '';
		$deleted = self::delete_backup( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'backhe_yis_backups',
					'backhe_message' => $deleted ? 'backup_deleted' : 'backup_delete_failed',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Get the download URL of a backup file.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name File name.
	 * @return string URL.
	 */
	public static function get_download_url( $name ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'backhe_yis_backup_download',
					'file'   => rawurlencode( $name ),
				),
				admin_url( 'admin-post.php' )
			),
			'backhe_yis_backup_download'
		);
	}

	/**
	 * Delete the whole backup directory, used on uninstall.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function delete_backup_directory() {
		$dir = self::get_backup_dir_path();

		if ( '' !== $dir && is_dir( $dir ) ) {
			global $wp_filesystem;

			require_once ABSPATH . 'wp-admin/includes/file.php';

			if ( WP_Filesystem() && $wp_filesystem ) {
				$wp_filesystem->rmdir( $dir, true );
			}
		}

		delete_option( self::DIR_KEY_OPTION );
	}

	/**
	 * Process the current phase of a job.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $job      Job.
	 * @param string $dir      Backup directory.
	 * @param float  $deadline Microtime until which work may continue.
	 * @return array Updated job.
	 * @throws RuntimeException If a phase fails.
	 */
	private function process_phase( array $job, $dir, $deadline ) {
		switch ( $job['phase'] ) {
			case 'database':
				$job = Backhe_YIS_Backup_Database::run( $job, $dir, $deadline );

				if ( ! empty( $job['db']['done'] ) ) {
					$job['phase'] = 'full' === $job['scope'] ? 'files_scan' : 'finalize';
				}
				break;

			case 'files_scan':
				$job = Backhe_YIS_Backup_Files::scan( $job, $dir, $deadline );

				if ( ! empty( $job['fs']['scan_done'] ) ) {
					$job['phase'] = 'files_zip';
				}
				break;

			case 'files_zip':
				$job = Backhe_YIS_Backup_Files::zip( $job, $dir, $deadline );

				if ( ! empty( $job['fs']['zip_done'] ) ) {
					$job['phase'] = 'finalize';
				}
				break;

			case 'finalize':
				$job = $this->finalize( $job, $dir );
				break;

			default:
				throw new RuntimeException( 'Unknown backup phase.' );
		}

		return $job;
	}

	/**
	 * Write the manifest, log the backup and apply the retention.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $job Job.
	 * @param string $dir Backup directory.
	 * @return array Completed job.
	 * @throws RuntimeException If the manifest cannot be written.
	 */
	private function finalize( array $job, $dir ) {
		if ( 'full' === $job['scope'] ) {
			Backhe_YIS_Backup_Files::cleanup( $job, $dir );
		}

		$files = array();
		$size  = 0;

		foreach ( $job['files'] as $file ) {
			$path = $dir . '/' . $file['name'];

			if ( ! file_exists( $path ) ) {
				continue;
			}

			$file['size'] = (int) filesize( $path );
			$size        += $file['size'];
			$files[]      = $file;
		}

		$manifest = array(
			'id'         => $job['id'],
			'created_at' => $job['started_at'],
			'scope'      => $job['scope'],
			'trigger'    => $job['trigger'],
			'duration'   => time() - $job['started_at'],
			'size'       => $size,
			'wp_version' => get_bloginfo( 'version' ),
			'site_url'   => home_url( '/' ),
			'files'      => $files,
		);

		if ( false === file_put_contents( $dir . '/' . $job['id'] . '.json', wp_json_encode( $manifest ) ) ) {
			throw new RuntimeException( __( 'The backup could not be written to disk.', 'admin-backend-and-update-helper' ) );
		}

		$scopes = self::get_scopes();

		$this->log->add(
			array(
				'event_type'  => 'backup_completed',
				'object_type' => 'backup',
				'object_name' => $scopes[ $job['scope'] ],
				'user_id'     => 'scheduled' === $job['trigger'] ? 0 : (int) $job['user_id'],
				'details'     => array(
					'size'        => $size,
					'scope'       => $job['scope'],
					'scope_label' => 'scheduled' === $job['trigger'] ? __( 'automatic', 'admin-backend-and-update-helper' ) : __( 'manual', 'admin-backend-and-update-helper' ),
					'duration'    => $manifest['duration'],
					'source'      => 'backend_helper',
				),
			)
		);

		$job['status'] = 'done';
		$job['phase']  = 'done';

		$this->apply_retention();

		return $job;
	}

	/**
	 * Mark a job as failed, remove its files and log the failure.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $job     Job.
	 * @param string $message Error message.
	 * @return array Failed job.
	 */
	public function fail( array $job, $message ) {
		$job['status']     = 'failed';
		$job['error']      = (string) $message;
		$job['updated_at'] = time();

		update_option( self::JOB_OPTION, $job, false );
		self::delete_job_files( $job );
		wp_clear_scheduled_hook( 'backhe_yis_backup_continue' );

		$this->log_failure( $job['scope'], $job['trigger'], $message, (int) $job['user_id'] );

		return $job;
	}

	/**
	 * Write a failed backup to the activity log.
	 *
	 * @since 1.1.0
	 *
	 * @param string $scope   Backup scope.
	 * @param string $trigger "manual" or "scheduled".
	 * @param string $message Error message.
	 * @param int    $user_id User who started the backup.
	 * @return void
	 */
	private function log_failure( $scope, $trigger, $message, $user_id = 0 ) {
		$scopes = self::get_scopes();

		$this->log->add(
			array(
				'event_type'  => 'backup_failed',
				'object_type' => 'backup',
				'object_name' => isset( $scopes[ $scope ] ) ? $scopes[ $scope ] : $scope,
				'user_id'     => 'scheduled' === $trigger ? 0 : $user_id,
				'details'     => array( 'error' => (string) $message ),
			)
		);
	}

	/**
	 * Fail a running job that made no progress for a long time.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function fail_stalled_job() {
		$job = self::get_job();

		if ( null !== $job && 'running' === $job['status'] && time() - (int) $job['updated_at'] > self::STALE_SECONDS ) {
			$this->fail( $job, __( 'The backup stopped making progress, e.g. because the server ended the process.', 'admin-backend-and-update-helper' ) );
		}
	}

	/**
	 * Delete backups beyond the number to keep.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function apply_retention() {
		$settings = self::get_settings();
		$backups  = self::list_backups();

		foreach ( array_slice( $backups, (int) $settings['keep'] ) as $backup ) {
			self::delete_backup( $backup['id'] );
		}
	}

	/**
	 * Delete all files of an unfinished job.
	 *
	 * @since 1.1.0
	 *
	 * @param array $job Job.
	 * @return void
	 */
	private static function delete_job_files( array $job ) {
		$dir = self::get_backup_dir_path();

		if ( '' === $dir || empty( $job['id'] ) || ! self::is_valid_id( $job['id'] ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/' . $job['id'] . '*' ) as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Schedule the fallback event that continues a job in the background.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function schedule_continue() {
		if ( ! wp_next_scheduled( 'backhe_yis_backup_continue' ) ) {
			wp_schedule_single_event( time() + 30, 'backhe_yis_backup_continue' );
		}
	}

	/**
	 * Acquire the step lock so that two steps never run at the same time.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if the lock was acquired.
	 */
	private function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		// Take over a lock left behind by a step that was killed.
		$locked_at = (int) get_option( self::LOCK_OPTION );

		if ( time() - $locked_at > self::STEP_SECONDS * 6 ) {
			update_option( self::LOCK_OPTION, time(), false );
			return true;
		}

		return false;
	}

	/**
	 * Release the step lock.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Verify nonce and capability of a backup AJAX request.
	 *
	 * @since 1.1.0
	 *
	 * @return void Sends a JSON error and exits on failure.
	 */
	private function verify_ajax_request() {
		check_ajax_referer( 'backhe_yis_backup', 'nonce' );

		if ( ! Backhe_YIS_Admin_Menu::user_can_access() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'admin-backend-and-update-helper' ) ) );
		}
	}

	/**
	 * Check a backup ID.
	 *
	 * @since 1.1.0
	 *
	 * @param string $id Backup ID.
	 * @return bool True if valid.
	 */
	private static function is_valid_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^backup-\d{8}-\d{6}-[a-z0-9]{6}$/', $id );
	}

	/**
	 * Check a backup file name.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name File name.
	 * @return bool True if valid.
	 */
	private static function is_valid_file_name( $name ) {
		return is_string( $name ) && (bool) preg_match( '/^backup-\d{8}-\d{6}-[a-z0-9]{6}-(database\.sql(\.gz)?|files-\d{1,4}\.zip)$/', $name );
	}
}
