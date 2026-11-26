<?php
/**
 * Service report.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the periodic service report from the activity log and sends it.
 *
 * A report period always ends with a complete day (the "period end day").
 * The report is sent on the following day at the configured hour, e.g. with
 * the period end on the 15th of each month the report covers the 16th of the
 * previous month to the 15th and is sent on the morning of the 16th.
 *
 * All date calculations use the site's time zone.
 *
 * @since 1.1.0
 */
class Backhe_YIS_Report {

	/**
	 * Option name of the settings.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OPTION = 'backhe_yis_report_settings';

	/**
	 * Option name of the internal state (last period, activation date).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const STATE_OPTION = 'backhe_yis_report_state';

	/**
	 * Maximum number of days searched backwards for a period end.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const SEARCH_DAYS = 400;

	/**
	 * Activity log.
	 *
	 * @since 1.1.0
	 * @var Backhe_YIS_Activity_Log
	 */
	private $log;

	/**
	 * Email notifier, used for the layout and sending.
	 *
	 * @since 1.1.0
	 * @var Backhe_YIS_Email_Notifier
	 */
	private $mailer;

	/**
	 * Register hooks.
	 *
	 * @since 1.1.0
	 *
	 * @param Backhe_YIS_Activity_Log   $log    Activity log.
	 * @param Backhe_YIS_Email_Notifier $mailer Email notifier.
	 */
	public function __construct( Backhe_YIS_Activity_Log $log, Backhe_YIS_Email_Notifier $mailer ) {
		$this->log    = $log;
		$this->mailer = $mailer;

		add_action( 'init', array( $this, 'register_settings' ) );
		add_action( 'backhe_yis_report_check', array( $this, 'maybe_send' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'settings_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION, array( $this, 'settings_updated' ), 10, 2 );
		add_action( 'admin_post_backhe_yis_report_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_backhe_yis_report_send', array( $this, 'handle_send_now' ) );
	}

	/**
	 * Schedule the hourly check, if not scheduled yet.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function schedule_check() {
		if ( ! wp_next_scheduled( 'backhe_yis_report_check' ) ) {
			$next_hour = ( (int) floor( time() / HOUR_IN_SECONDS ) + 1 ) * HOUR_IN_SECONDS;

			wp_schedule_event( $next_hour + 5 * MINUTE_IN_SECONDS, 'hourly', 'backhe_yis_report_check' );
		}
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
			'backhe_yis_report_group',
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
			'enabled'          => '0',
			'recipients'       => array(),
			'site_label'       => '',
			'send_rule'        => 'monthly_day',
			'interval_days'    => 30,
			'month_day'        => 15,
			'period_rule'      => 'since_last',
			'period_days'      => 28,
			'send_hour'        => 7,
			'retention_months' => Backhe_YIS_Activity_Log::DEFAULT_RETENTION_MONTHS,
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

		$recipients = array();
		$raw_emails = isset( $input['recipients'] ) ? (array) $input['recipients'] : array();

		foreach ( $raw_emails as $email ) {
			$email = is_scalar( $email ) ? sanitize_email( $email ) : '';

			if ( is_email( $email ) && ! in_array( $email, $recipients, true ) ) {
				$recipients[] = $email;
			}
		}

		$send_rule   = isset( $input['send_rule'] ) ? sanitize_key( $input['send_rule'] ) : '';
		$period_rule = isset( $input['period_rule'] ) ? sanitize_key( $input['period_rule'] ) : '';

		return array(
			'enabled'          => ( isset( $input['enabled'] ) && '1' === (string) $input['enabled'] ) ? '1' : '0',
			'recipients'       => array_slice( $recipients, 0, 3 ),
			'site_label'       => isset( $input['site_label'] ) ? sanitize_text_field( $input['site_label'] ) : '',
			'send_rule'        => array_key_exists( $send_rule, self::get_send_rules() ) ? $send_rule : $defaults['send_rule'],
			'interval_days'    => self::clamp( isset( $input['interval_days'] ) ? $input['interval_days'] : null, 1, 365, $defaults['interval_days'] ),
			'month_day'        => self::clamp( isset( $input['month_day'] ) ? $input['month_day'] : null, 1, 28, $defaults['month_day'] ),
			'period_rule'      => array_key_exists( $period_rule, self::get_period_rules() ) ? $period_rule : $defaults['period_rule'],
			'period_days'      => self::clamp( isset( $input['period_days'] ) ? $input['period_days'] : null, 1, 365, $defaults['period_days'] ),
			'send_hour'        => self::clamp( isset( $input['send_hour'] ) ? $input['send_hour'] : null, 0, 23, $defaults['send_hour'] ),
			'retention_months' => self::clamp( isset( $input['retention_months'] ) ? $input['retention_months'] : null, 1, 120, $defaults['retention_months'] ),
		);
	}

	/**
	 * Get the available rules for the period end day.
	 *
	 * @since 1.1.0
	 *
	 * @return string[] Labels keyed by rule.
	 */
	public static function get_send_rules() {
		return array(
			'monthly_day' => __( 'On a fixed day of each month', 'admin-backend-and-update-helper' ),
			'month_last'  => __( 'On the last day of each month', 'admin-backend-and-update-helper' ),
			'month_first' => __( 'On the first day of each month', 'admin-backend-and-update-helper' ),
			'interval'    => __( 'Every few days', 'admin-backend-and-update-helper' ),
		);
	}

	/**
	 * Get the available rules for the period start.
	 *
	 * @since 1.1.0
	 *
	 * @return string[] Labels keyed by rule.
	 */
	public static function get_period_rules() {
		return array(
			'since_last' => __( 'Since the end of the previous report period', 'admin-backend-and-update-helper' ),
			'last_days'  => __( 'A fixed number of days', 'admin-backend-and-update-helper' ),
		);
	}

	/**
	 * Remember when the report was enabled for the first time.
	 *
	 * @since 1.1.0
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New settings.
	 * @return void
	 */
	public function settings_added( $option, $value ) {
		unset( $option );
		$this->settings_updated( array(), $value );
	}

	/**
	 * Track enabling the report; reports for periods before that are not sent.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public function settings_updated( $old_value, $value ) {
		$was_enabled = is_array( $old_value ) && isset( $old_value['enabled'] ) && '1' === $old_value['enabled'];
		$is_enabled  = is_array( $value ) && isset( $value['enabled'] ) && '1' === $value['enabled'];
		$rule_change = is_array( $old_value ) && is_array( $value )
			&& ( ( $old_value['send_rule'] ?? '' ) !== ( $value['send_rule'] ?? '' ) || (string) ( $old_value['interval_days'] ?? '' ) !== (string) ( $value['interval_days'] ?? '' ) );

		if ( $is_enabled && ( ! $was_enabled || $rule_change ) ) {
			$state                = self::get_state();
			$state['enabled_at']  = time();
			$state['anchor_date'] = $this->today()->format( 'Y-m-d' );
			update_option( self::STATE_OPTION, $state, false );
		}

		self::schedule_check();
	}

	/**
	 * Cron callback: send the report if a period has ended.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function maybe_send() {
		$settings = self::get_settings();

		if ( '1' !== $settings['enabled'] || empty( $settings['recipients'] ) ) {
			return;
		}

		$due = $this->get_due_period( $settings, new DateTimeImmutable( 'now', wp_timezone() ) );

		if ( null === $due ) {
			return;
		}

		if ( $this->send( $due['start'], $due['end'], $settings['recipients'] ) ) {
			$state                    = self::get_state();
			$state['last_period_end'] = $due['end']->format( 'Y-m-d' );
			$state['last_sent_at']    = time();
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/**
	 * Get the period that is due to be reported, if any.
	 *
	 * @since 1.1.0
	 *
	 * @param array             $settings Report settings.
	 * @param DateTimeImmutable $now      Current time in the site's time zone.
	 * @return array|null Array with "start" and "end" (first and last day, both inclusive), null if nothing is due.
	 */
	public function get_due_period( array $settings, DateTimeImmutable $now ) {
		$state = self::get_state();
		$end   = $this->find_latest_period_end( $settings, $now );

		if ( null === $end ) {
			return null;
		}

		$last_end = ! empty( $state['last_period_end'] ) ? $this->date( $state['last_period_end'] ) : null;

		if ( null !== $last_end && $end <= $last_end ) {
			return null;
		}

		// Do not send reports for periods that ended before the report was enabled.
		$enabled_at = isset( $state['enabled_at'] ) ? (int) $state['enabled_at'] : 0;

		if ( $this->get_send_time( $end, $settings )->getTimestamp() < $enabled_at ) {
			return null;
		}

		return array(
			'start' => $this->get_period_start( $settings, $end, $last_end ),
			'end'   => $end,
		);
	}

	/**
	 * Find the most recent period end day whose send time has passed.
	 *
	 * @since 1.1.0
	 *
	 * @param array             $settings Report settings.
	 * @param DateTimeImmutable $now      Current time in the site's time zone.
	 * @return DateTimeImmutable|null Period end day (midnight), null if none.
	 */
	public function find_latest_period_end( array $settings, DateTimeImmutable $now ) {
		$day = $now->setTime( 0, 0 )->modify( '-1 day' );

		for ( $i = 0; $i < self::SEARCH_DAYS; $i++ ) {
			if ( $this->is_period_end( $day, $settings ) && $this->get_send_time( $day, $settings ) <= $now ) {
				return $day;
			}

			$day = $day->modify( '-1 day' );
		}

		return null;
	}

	/**
	 * Get the first day of a period.
	 *
	 * @since 1.1.0
	 *
	 * @param array                  $settings Report settings.
	 * @param DateTimeImmutable      $end      Last day of the period.
	 * @param DateTimeImmutable|null $last_end Last day of the previously sent period.
	 * @return DateTimeImmutable First day of the period.
	 */
	public function get_period_start( array $settings, DateTimeImmutable $end, $last_end = null ) {
		if ( 'last_days' === $settings['period_rule'] ) {
			return $end->modify( '-' . ( (int) $settings['period_days'] - 1 ) . ' days' );
		}

		if ( $last_end instanceof DateTimeImmutable && $last_end < $end ) {
			return $last_end->modify( '+1 day' );
		}

		$day = $end->modify( '-1 day' );

		for ( $i = 0; $i < self::SEARCH_DAYS; $i++ ) {
			if ( $this->is_period_end( $day, $settings ) ) {
				return $day->modify( '+1 day' );
			}

			$day = $day->modify( '-1 day' );
		}

		return $end->modify( '-29 days' );
	}

	/**
	 * Get the next period end day from today on, for display.
	 *
	 * @since 1.1.0
	 *
	 * @param array $settings Report settings.
	 * @return DateTimeImmutable|null Next period end day, null if none within the search range.
	 */
	public function get_next_period_end( array $settings ) {
		$day = $this->today();

		for ( $i = 0; $i < self::SEARCH_DAYS; $i++ ) {
			if ( $this->is_period_end( $day, $settings ) ) {
				return $day;
			}

			$day = $day->modify( '+1 day' );
		}

		return null;
	}

	/**
	 * Check whether a day is the last day of a report period.
	 *
	 * @since 1.1.0
	 *
	 * @param DateTimeImmutable $day      Day (midnight, site time zone).
	 * @param array             $settings Report settings.
	 * @return bool True if the day ends a period.
	 */
	public function is_period_end( DateTimeImmutable $day, array $settings ) {
		switch ( $settings['send_rule'] ) {
			case 'month_last':
				return $day->format( 'j' ) === $day->format( 't' );

			case 'month_first':
				return '1' === $day->format( 'j' );

			case 'interval':
				$state  = self::get_state();
				$anchor = ! empty( $state['anchor_date'] ) ? $this->date( $state['anchor_date'] ) : $this->today();
				$days   = (int) $anchor->diff( $day )->format( '%r%a' );

				return $days > 0 && 0 === $days % max( 1, (int) $settings['interval_days'] );

			case 'monthly_day':
			default:
				return (int) $day->format( 'j' ) === (int) $settings['month_day'];
		}
	}

	/**
	 * Get the time the report for a period is sent: the day after the period end at the configured hour.
	 *
	 * @since 1.1.0
	 *
	 * @param DateTimeImmutable $end      Last day of the period.
	 * @param array             $settings Report settings.
	 * @return DateTimeImmutable Send time.
	 */
	public function get_send_time( DateTimeImmutable $end, array $settings ) {
		return $end->modify( '+1 day' )->setTime( (int) $settings['send_hour'], 0 );
	}

	/**
	 * Build and send the report for a period.
	 *
	 * @since 1.1.0
	 *
	 * @param DateTimeImmutable $start      First day of the period.
	 * @param DateTimeImmutable $end        Last day of the period.
	 * @param string[]          $recipients Email addresses.
	 * @return bool True if the report was sent to all recipients.
	 */
	public function send( DateTimeImmutable $start, DateTimeImmutable $end, array $recipients ) {
		$data    = $this->collect( $start, $end );
		$subject = '[' . wp_specialchars_decode( $this->get_site_label(), ENT_QUOTES ) . '] ' . sprintf(
			/* translators: %s: Report period, e.g. "16/10/2026 – 15/11/2026". */
			__( 'Service report %s', 'admin-backend-and-update-helper' ),
			$this->format_period( $start, $end )
		);

		return $this->mailer->send_html( $recipients, $subject, $this->render( $data ) );
	}

	/**
	 * Collect and group the log entries of a period.
	 *
	 * @since 1.1.0
	 *
	 * @param DateTimeImmutable $start First day of the period.
	 * @param DateTimeImmutable $end   Last day of the period.
	 * @return array Report data.
	 */
	public function collect( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$utc     = new DateTimeZone( 'UTC' );
		$entries = $this->log->query(
			array(
				'from'  => $start->setTime( 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'to'    => $end->modify( '+1 day' )->setTime( 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'order' => 'ASC',
			)
		);

		$groups = array();

		foreach ( self::get_sections() as $section => $section_data ) {
			$groups[ $section ] = array();
		}

		$summary = array(
			'updates'         => 0,
			'management'      => 0,
			'content'         => 0,
			'users'           => 0,
			'backups'         => 0,
			'backups_failed'  => 0,
			'manual'          => 0,
			'manual_minutes'  => 0,
			'updates_by_type' => array(),
		);

		foreach ( $entries as $entry ) {
			$section = $this->get_section_for_event( $entry['event_type'] );

			if ( null === $section ) {
				continue;
			}

			$groups[ $section ][] = $entry;
			$count                = isset( $entry['details']['count'] ) ? max( 1, absint( $entry['details']['count'] ) ) : 1;

			switch ( $section ) {
				case 'updates':
					++$summary['updates'];
					$summary['updates_by_type'][ $entry['event_type'] ] = ( $summary['updates_by_type'][ $entry['event_type'] ] ?? 0 ) + 1;
					break;

				case 'content':
					$summary['content'] += $count;
					break;

				case 'backups':
					if ( 'backup_failed' === $entry['event_type'] ) {
						++$summary['backups_failed'];
					} else {
						++$summary['backups'];
					}
					break;

				case 'manual':
					++$summary['manual'];
					$summary['manual_minutes'] += (int) $entry['duration_minutes'];
					break;

				default:
					++$summary[ $section ];
					break;
			}
		}

		return array(
			'start'   => $start,
			'end'     => $end,
			'summary' => $summary,
			'groups'  => $groups,
		);
	}

	/**
	 * Render the report email.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data Report data from collect().
	 * @return string Email HTML.
	 */
	public function render( array $data ) {
		$summary = $data['summary'];
		$labels  = Backhe_YIS_Event_Tracker::get_event_labels();
		$mailer  = $this->mailer;

		$content = '<p>' . esc_html(
			sprintf(
				/* translators: 1: Website name, 2: Report period. */
				__( 'This report lists the maintenance work on %1$s in the period %2$s.', 'admin-backend-and-update-helper' ),
				$this->get_site_label(),
				$this->format_period( $data['start'], $data['end'] )
			)
		) . '</p>';

		// Summary.
		$summary_rows = array(
			array( __( 'Updates installed', 'admin-backend-and-update-helper' ), (string) $summary['updates'] ),
		);

		foreach ( $summary['updates_by_type'] as $event_type => $count ) {
			$summary_rows[] = array( '– ' . ( $labels[ $event_type ] ?? $event_type ), (string) $count );
		}

		$summary_rows[] = array( __( 'Backups created', 'admin-backend-and-update-helper' ), (string) $summary['backups'] );

		if ( $summary['backups_failed'] > 0 ) {
			$summary_rows[] = array( __( 'Failed backups', 'admin-backend-and-update-helper' ), (string) $summary['backups_failed'] );
		}

		$summary_rows[] = array( __( 'Plugin and theme changes', 'admin-backend-and-update-helper' ), (string) $summary['management'] );
		$summary_rows[] = array( __( 'Content changes', 'admin-backend-and-update-helper' ), (string) $summary['content'] );
		$summary_rows[] = array( __( 'User changes', 'admin-backend-and-update-helper' ), (string) $summary['users'] );
		$summary_rows[] = array(
			__( 'Additional services', 'admin-backend-and-update-helper' ),
			$summary['manual_minutes'] > 0
				? sprintf(
					/* translators: 1: Number of services, 2: Total duration, e.g. "2:30 h". */
					__( '%1$d (%2$s)', 'admin-backend-and-update-helper' ),
					$summary['manual'],
					self::format_duration( $summary['manual_minutes'] )
				)
				: (string) $summary['manual'],
		);

		$content .= $mailer->render_heading( __( 'Summary', 'admin-backend-and-update-helper' ) );
		$content .= $mailer->render_table(
			array(
				__( 'Area', 'admin-backend-and-update-helper' ),
				__( 'Count', 'admin-backend-and-update-helper' ),
			),
			$summary_rows
		);

		// Details per section.
		foreach ( self::get_sections() as $section => $section_data ) {
			if ( empty( $data['groups'][ $section ] ) ) {
				continue;
			}

			$rows = array();

			foreach ( $data['groups'][ $section ] as $entry ) {
				$date = get_date_from_gmt( $entry['created_at'], get_option( 'date_format' ) );

				if ( 'manual' === $section ) {
					$rows[] = array( $date, $entry['object_name'], self::format_duration( (int) $entry['duration_minutes'] ) );
				} else {
					$rows[] = array( $date, $labels[ $entry['event_type'] ] ?? $entry['event_type'], $entry['object_name'], self::describe_details( $entry ) );
				}
			}

			if ( 'manual' === $section ) {
				$headers = array(
					__( 'Date', 'admin-backend-and-update-helper' ),
					__( 'Service', 'admin-backend-and-update-helper' ),
					__( 'Duration', 'admin-backend-and-update-helper' ),
				);
			} else {
				$headers = array(
					__( 'Date', 'admin-backend-and-update-helper' ),
					__( 'Action', 'admin-backend-and-update-helper' ),
					__( 'Item', 'admin-backend-and-update-helper' ),
					__( 'Details', 'admin-backend-and-update-helper' ),
				);
			}

			$content .= $mailer->render_heading( $section_data['title'] );
			$content .= $mailer->render_table( $headers, $rows );
		}

		$total = $summary['updates'] + $summary['backups'] + $summary['backups_failed'] + $summary['management'] + $summary['content'] + $summary['users'] + $summary['manual'];

		if ( 0 === $total ) {
			$content .= '<p>' . esc_html__( 'No changes were recorded in this period.', 'admin-backend-and-update-helper' ) . '</p>';
		}

		return $mailer->render_layout(
			__( 'Service report', 'admin-backend-and-update-helper' ),
			$content,
			$this->get_site_label() . ' · ' . $this->format_period( $data['start'], $data['end'] )
		);
	}

	/**
	 * Admin action: show a preview of the report in the browser.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function handle_preview() {
		if ( ! Backhe_YIS_Admin_Menu::user_can_access() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'admin-backend-and-update-helper' ), 403 );
		}

		check_admin_referer( 'backhe_yis_report_manual' );

		list( $start, $end ) = $this->get_requested_period( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked above.

		header( 'Content-Type: text/html; charset=UTF-8' );
		// The report HTML is built from escaped parts in render() and Backhe_YIS_Email_Notifier.
		echo $this->render( $this->collect( $start, $end ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Admin action: send the report for a chosen period now.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function handle_send_now() {
		if ( ! Backhe_YIS_Admin_Menu::user_can_access() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'admin-backend-and-update-helper' ), 403 );
		}

		check_admin_referer( 'backhe_yis_report_manual' );

		list( $start, $end ) = $this->get_requested_period( $_POST );

		$settings = self::get_settings();
		$sent     = ! empty( $settings['recipients'] ) && $this->send( $start, $end, $settings['recipients'] );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'backhe_yis_report',
					'backhe_message' => $sent ? 'report_sent' : 'report_failed',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Get the label of the website used in the report.
	 *
	 * @since 1.1.0
	 *
	 * @return string Label.
	 */
	public function get_site_label() {
		$settings = self::get_settings();

		return '' !== $settings['site_label'] ? $settings['site_label'] : get_bloginfo( 'name' );
	}

	/**
	 * Get the internal state.
	 *
	 * @since 1.1.0
	 *
	 * @return array State with last_period_end, last_sent_at, enabled_at and anchor_date.
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Format a period for display.
	 *
	 * @since 1.1.0
	 *
	 * @param DateTimeImmutable $start First day.
	 * @param DateTimeImmutable $end   Last day.
	 * @return string Period, e.g. "16/10/2026 – 15/11/2026".
	 */
	public function format_period( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$format = get_option( 'date_format' );

		return wp_date( $format, $start->getTimestamp(), wp_timezone() ) . ' – ' . wp_date( $format, $end->getTimestamp(), wp_timezone() );
	}

	/**
	 * Format a duration in minutes as hours and minutes.
	 *
	 * @since 1.1.0
	 *
	 * @param int $minutes Minutes.
	 * @return string Duration, e.g. "1:30 h"; empty for 0.
	 */
	public static function format_duration( $minutes ) {
		$minutes = absint( $minutes );

		if ( 0 === $minutes ) {
			return '';
		}

		return sprintf(
			/* translators: 1: Hours, 2: Minutes (two digits). */
			__( '%1$d:%2$s h', 'admin-backend-and-update-helper' ),
			intdiv( $minutes, 60 ),
			str_pad( (string) ( $minutes % 60 ), 2, '0', STR_PAD_LEFT )
		);
	}

	/**
	 * Describe the details of an entry in one line.
	 *
	 * @since 1.1.0
	 *
	 * @param array $entry Log entry.
	 * @return string Description (plain text).
	 */
	public static function describe_details( array $entry ) {
		$details = $entry['details'];

		if ( ! empty( $details['new_version'] ) ) {
			return ! empty( $details['old_version'] )
				? $details['old_version'] . ' → ' . $details['new_version']
				: $details['new_version'];
		}

		if ( isset( $details['count'] ) && absint( $details['count'] ) > 1 ) {
			return sprintf(
				/* translators: %d: Number of edits. */
				_n( '%d edit', '%d edits', absint( $details['count'] ), 'admin-backend-and-update-helper' ),
				absint( $details['count'] )
			);
		}

		if ( isset( $details['new_role'] ) ) {
			return implode( ', ', (array) ( $details['old_roles'] ?? array() ) ) . ' → ' . $details['new_role'];
		}

		if ( isset( $details['size'] ) ) {
			return trim( size_format( (int) $details['size'] ) . ( ! empty( $details['scope_label'] ) ? ', ' . $details['scope_label'] : '' ) );
		}

		if ( ! empty( $details['error'] ) ) {
			return (string) $details['error'];
		}

		return '';
	}

	/**
	 * Get the report sections.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Sections with title and event types.
	 */
	public static function get_sections() {
		return array(
			'updates'    => array(
				'title'  => __( 'Updates', 'admin-backend-and-update-helper' ),
				'events' => array( 'core_updated', 'plugin_updated', 'theme_updated', 'translation_updated' ),
			),
			'backups'    => array(
				'title'  => __( 'Backups', 'admin-backend-and-update-helper' ),
				'events' => array( 'backup_completed', 'backup_failed' ),
			),
			'management' => array(
				'title'  => __( 'Plugins and themes', 'admin-backend-and-update-helper' ),
				'events' => array( 'plugin_installed', 'plugin_activated', 'plugin_deactivated', 'plugin_deleted', 'theme_installed', 'theme_switched', 'theme_deleted' ),
			),
			'content'    => array(
				'title'  => __( 'Content', 'admin-backend-and-update-helper' ),
				'events' => array( 'post_published', 'post_updated', 'post_trashed' ),
			),
			'users'      => array(
				'title'  => __( 'Users', 'admin-backend-and-update-helper' ),
				'events' => array( 'user_created', 'user_deleted', 'user_role_changed' ),
			),
			'manual'     => array(
				'title'  => __( 'Additional services', 'admin-backend-and-update-helper' ),
				'events' => array( 'manual' ),
			),
		);
	}

	/**
	 * Get the section an event type belongs to.
	 *
	 * @since 1.1.0
	 *
	 * @param string $event_type Event type.
	 * @return string|null Section key, null if not part of the report.
	 */
	private function get_section_for_event( $event_type ) {
		foreach ( self::get_sections() as $section => $section_data ) {
			if ( in_array( $event_type, $section_data['events'], true ) ) {
				return $section;
			}
		}

		return null;
	}

	/**
	 * Read the period from a request, defaulting to the last 30 days.
	 *
	 * @since 1.1.0
	 *
	 * @param array $request Request data ($_GET or $_POST); the nonce must be checked by the caller.
	 * @return DateTimeImmutable[] First and last day.
	 */
	private function get_requested_period( array $request ) {
		$today = $this->today();
		$from  = isset( $request['from'] ) ? $this->parse_date( sanitize_text_field( wp_unslash( $request['from'] ) ) ) : null;
		$to    = isset( $request['to'] ) ? $this->parse_date( sanitize_text_field( wp_unslash( $request['to'] ) ) ) : null;

		$end   = $to ? $to : $today;
		$start = $from ? $from : $end->modify( '-29 days' );

		if ( $start > $end ) {
			list( $start, $end ) = array( $end, $start );
		}

		return array( $start, $end );
	}

	/**
	 * Parse a Y-m-d date in the site's time zone.
	 *
	 * @since 1.1.0
	 *
	 * @param string $value Date string.
	 * @return DateTimeImmutable|null Date at midnight, null if invalid.
	 */
	private function parse_date( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $date : null;
	}

	/**
	 * Create a date at midnight in the site's time zone.
	 *
	 * @since 1.1.0
	 *
	 * @param string $value Date in Y-m-d format.
	 * @return DateTimeImmutable Date.
	 */
	private function date( $value ) {
		$date = $this->parse_date( (string) $value );

		return $date ? $date : $this->today();
	}

	/**
	 * Get today at midnight in the site's time zone.
	 *
	 * @since 1.1.0
	 *
	 * @return DateTimeImmutable Today.
	 */
	private function today() {
		return new DateTimeImmutable( 'today', wp_timezone() );
	}

	/**
	 * Clamp a numeric setting.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Minimum.
	 * @param int   $max      Maximum.
	 * @param int   $fallback Value used if the input is not numeric.
	 * @return int Clamped value.
	 */
	private static function clamp( $value, $min, $max, $fallback ) {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return min( $max, max( $min, (int) $value ) );
	}
}
