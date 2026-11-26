<?php
/**
 * Service report page.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This template is included inside Backhe_YIS_Admin_Menu::render_view(),
 * so the variables below are local to that method and not globals.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$plugin_instance = Backhe_YIS_Media::get_instance();
$report          = $plugin_instance->report;
$settings        = Backhe_YIS_Report::get_settings();
$state           = Backhe_YIS_Report::get_state();
$option          = Backhe_YIS_Report::OPTION;
$recipients      = array_pad( (array) $settings['recipients'], 3, '' );

// Preview of the next report.
$next_end     = $report->get_next_period_end( $settings );
$last_end     = ! empty( $state['last_period_end'] ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $state['last_period_end'], wp_timezone() ) : null;
$next_summary = '';

if ( $next_end ) {
	$next_start   = $report->get_period_start( $settings, $next_end, $last_end ? $last_end : null );
	$send_time    = $report->get_send_time( $next_end, $settings );
	$next_summary = sprintf(
		/* translators: 1: Report period, 2: Date and time the report is sent. */
		__( 'Next report: %1$s, sent on %2$s.', 'admin-backend-and-update-helper' ),
		$report->format_period( $next_start, $next_end ),
		wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $send_time->getTimestamp() )
	);
}

$manual_from = wp_date( 'Y-m-d', strtotime( '-29 days' ) );
$manual_to   = wp_date( 'Y-m-d' );
?>
<div class="wrap backhe-yis-admin">
	<h1><?php esc_html_e( 'Service Report', 'admin-backend-and-update-helper' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Sends a summary of updates, backups, changes and additional services at the end of each period, e.g. for website maintenance clients.', 'admin-backend-and-update-helper' ); ?></p>

	<?php settings_errors(); ?>
	<?php Backhe_YIS_Admin_Actions::render_message(); ?>

	<div class="backhe-card backhe-card-full">
		<h2><?php esc_html_e( 'Automatic report', 'admin-backend-and-update-helper' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'backhe_yis_report_group' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'admin-backend-and-update-helper' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $option ); ?>[enabled]" value="0">
						<input type="checkbox" id="backhe-report-enabled" name="<?php echo esc_attr( $option ); ?>[enabled]" value="1" <?php checked( $settings['enabled'], '1' ); ?>>
						<label for="backhe-report-enabled"><?php esc_html_e( 'Send the report automatically', 'admin-backend-and-update-helper' ); ?></label>
						<?php if ( '1' === $settings['enabled'] && '' !== $next_summary ) : ?>
							<p class="description"><?php echo esc_html( $next_summary ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Recipients', 'admin-backend-and-update-helper' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Recipients', 'admin-backend-and-update-helper' ); ?></legend>
							<?php foreach ( $recipients as $index => $recipient ) : ?>
								<p>
									<label for="backhe-report-recipient-<?php echo esc_attr( $index ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: Number of the recipient (1-3). */
												__( 'Email address %d', 'admin-backend-and-update-helper' ),
												$index + 1
											)
										);
										?>
									</label><br>
									<input type="email" id="backhe-report-recipient-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $option ); ?>[recipients][]" value="<?php echo esc_attr( $recipient ); ?>" class="regular-text" autocomplete="email">
								</p>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="backhe-report-label"><?php esc_html_e( 'Website name in the report', 'admin-backend-and-update-helper' ); ?></label></th>
					<td>
						<input type="text" id="backhe-report-label" name="<?php echo esc_attr( $option ); ?>[site_label]" value="<?php echo esc_attr( $settings['site_label'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" aria-describedby="backhe-report-label-desc">
						<p class="description" id="backhe-report-label-desc"><?php esc_html_e( 'Leave empty to use the site title.', 'admin-backend-and-update-helper' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Period ends', 'admin-backend-and-update-helper' ); ?></th>
					<td>
						<fieldset aria-describedby="backhe-send-rule-desc">
							<legend class="screen-reader-text"><?php esc_html_e( 'Period ends', 'admin-backend-and-update-helper' ); ?></legend>
							<?php foreach ( Backhe_YIS_Report::get_send_rules() as $rule_key => $rule_label ) : ?>
								<p>
									<input type="radio" id="backhe-send-rule-<?php echo esc_attr( $rule_key ); ?>" name="<?php echo esc_attr( $option ); ?>[send_rule]" value="<?php echo esc_attr( $rule_key ); ?>" <?php checked( $settings['send_rule'], $rule_key ); ?>>
									<label for="backhe-send-rule-<?php echo esc_attr( $rule_key ); ?>"><?php echo esc_html( $rule_label ); ?></label>
								</p>
								<?php if ( 'monthly_day' === $rule_key ) : ?>
									<p class="backhe-indent">
										<label for="backhe-month-day"><?php esc_html_e( 'Day of the month (1-28)', 'admin-backend-and-update-helper' ); ?></label>
										<input type="number" id="backhe-month-day" name="<?php echo esc_attr( $option ); ?>[month_day]" value="<?php echo esc_attr( $settings['month_day'] ); ?>" min="1" max="28" class="small-text">
									</p>
								<?php elseif ( 'interval' === $rule_key ) : ?>
									<p class="backhe-indent">
										<label for="backhe-interval-days"><?php esc_html_e( 'Every how many days', 'admin-backend-and-update-helper' ); ?></label>
										<input type="number" id="backhe-interval-days" name="<?php echo esc_attr( $option ); ?>[interval_days]" value="<?php echo esc_attr( $settings['interval_days'] ); ?>" min="1" max="365" class="small-text">
									</p>
								<?php endif; ?>
							<?php endforeach; ?>
						</fieldset>
						<p class="description" id="backhe-send-rule-desc"><?php esc_html_e( 'The chosen day is the last day of the period. The report is sent on the following day. Example: with day 15 the report covers the 16th of the previous month to the 15th and is sent on the 16th.', 'admin-backend-and-update-helper' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Period starts', 'admin-backend-and-update-helper' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Period starts', 'admin-backend-and-update-helper' ); ?></legend>
							<?php foreach ( Backhe_YIS_Report::get_period_rules() as $rule_key => $rule_label ) : ?>
								<p>
									<input type="radio" id="backhe-period-rule-<?php echo esc_attr( $rule_key ); ?>" name="<?php echo esc_attr( $option ); ?>[period_rule]" value="<?php echo esc_attr( $rule_key ); ?>" <?php checked( $settings['period_rule'], $rule_key ); ?>>
									<label for="backhe-period-rule-<?php echo esc_attr( $rule_key ); ?>"><?php echo esc_html( $rule_label ); ?></label>
								</p>
								<?php if ( 'last_days' === $rule_key ) : ?>
									<p class="backhe-indent">
										<label for="backhe-period-days"><?php esc_html_e( 'Number of days, e.g. 28 for four weeks', 'admin-backend-and-update-helper' ); ?></label>
										<input type="number" id="backhe-period-days" name="<?php echo esc_attr( $option ); ?>[period_days]" value="<?php echo esc_attr( $settings['period_days'] ); ?>" min="1" max="365" class="small-text">
									</p>
								<?php endif; ?>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="backhe-send-hour"><?php esc_html_e( 'Send time', 'admin-backend-and-update-helper' ); ?></label></th>
					<td>
						<select id="backhe-send-hour" name="<?php echo esc_attr( $option ); ?>[send_hour]">
							<?php for ( $hour = 0; $hour < 24; $hour++ ) : ?>
								<option value="<?php echo esc_attr( $hour ); ?>" <?php selected( (int) $settings['send_hour'], $hour ); ?>><?php echo esc_html( sprintf( '%02d:00', $hour ) ); ?></option>
							<?php endfor; ?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="backhe-retention"><?php esc_html_e( 'Keep log entries', 'admin-backend-and-update-helper' ); ?></label></th>
					<td>
						<input type="number" id="backhe-retention" name="<?php echo esc_attr( $option ); ?>[retention_months]" value="<?php echo esc_attr( $settings['retention_months'] ); ?>" min="1" max="120" class="small-text" aria-describedby="backhe-retention-desc">
						<span id="backhe-retention-desc"><?php esc_html_e( 'months', 'admin-backend-and-update-helper' ); ?></span>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save settings', 'admin-backend-and-update-helper' ) ); ?>
		</form>
	</div>

	<div class="backhe-card backhe-card-full">
		<h2><?php esc_html_e( 'Report for any period', 'admin-backend-and-update-helper' ); ?></h2>
		<p><?php esc_html_e( 'Preview the report or send it now to the recipients above.', 'admin-backend-and-update-helper' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="backhe-log-filters" target="_self">
			<?php wp_nonce_field( 'backhe_yis_report_manual' ); ?>
			<p class="backhe-field">
				<label for="backhe-report-from"><?php esc_html_e( 'From', 'admin-backend-and-update-helper' ); ?></label>
				<input type="date" id="backhe-report-from" name="from" value="<?php echo esc_attr( $manual_from ); ?>" required>
			</p>
			<p class="backhe-field">
				<label for="backhe-report-to"><?php esc_html_e( 'To', 'admin-backend-and-update-helper' ); ?></label>
				<input type="date" id="backhe-report-to" name="to" value="<?php echo esc_attr( $manual_to ); ?>" required>
			</p>
			<p class="backhe-field">
				<button type="submit" name="action" value="backhe_yis_report_preview" class="button" formmethod="get" formtarget="_blank">
					<?php esc_html_e( 'Preview', 'admin-backend-and-update-helper' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'admin-backend-and-update-helper' ); ?></span>
				</button>
				<button type="submit" name="action" value="backhe_yis_report_send" class="button button-primary" <?php disabled( empty( $settings['recipients'] ) ); ?>>
					<?php esc_html_e( 'Send now', 'admin-backend-and-update-helper' ); ?>
				</button>
			</p>
			<?php if ( empty( $settings['recipients'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'Save at least one recipient to send the report.', 'admin-backend-and-update-helper' ); ?></p>
			<?php endif; ?>
		</form>
	</div>
</div>
