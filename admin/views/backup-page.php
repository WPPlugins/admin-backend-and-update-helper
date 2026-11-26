<?php
/**
 * Backup page.
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

$settings    = Backhe_YIS_Backup_Manager::get_settings();
$option      = Backhe_YIS_Backup_Manager::OPTION;
$backups     = Backhe_YIS_Backup_Manager::list_backups();
$job         = Backhe_YIS_Backup_Manager::get_job();
$running     = null !== $job && 'running' === $job['status'];
$can_files   = Backhe_YIS_Backup_Manager::can_backup_files();
$scopes      = Backhe_YIS_Backup_Manager::get_scopes();
$next_backup = wp_next_scheduled( 'backhe_yis_backup_scheduled' );
$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<div class="wrap backhe-yis-admin">
	<h1><?php esc_html_e( 'Backups', 'admin-backend-and-update-helper' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Backups of the database and the wp-content directory are stored on this server in a protected directory. Download them regularly and keep copies in a second location.', 'admin-backend-and-update-helper' ); ?></p>

	<?php settings_errors(); ?>
	<?php Backhe_YIS_Admin_Actions::render_message(); ?>

	<?php if ( ! $can_files ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'The PHP extension "zip" is not available on this server. Only database backups are possible.', 'admin-backend-and-update-helper' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( null !== $job && 'failed' === $job['status'] && ! empty( $job['error'] ) ) : ?>
		<div class="notice notice-error inline">
			<p>
				<strong><?php esc_html_e( 'The last backup failed:', 'admin-backend-and-update-helper' ); ?></strong>
				<?php echo esc_html( $job['error'] ); ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="backhe-card backhe-card-full">
		<h2><?php esc_html_e( 'Create a backup', 'admin-backend-and-update-helper' ); ?></h2>
		<div id="backhe-backup-controls">
			<p class="backhe-field">
				<label for="backhe-backup-scope"><?php esc_html_e( 'Scope', 'admin-backend-and-update-helper' ); ?></label>
				<select id="backhe-backup-scope">
					<?php foreach ( $scopes as $scope_key => $scope_label ) : ?>
						<option value="<?php echo esc_attr( $scope_key ); ?>" <?php selected( $settings['scope'], $scope_key ); ?> <?php disabled( 'full' === $scope_key && ! $can_files ); ?>><?php echo esc_html( $scope_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="backhe-field">
				<button type="button" id="backhe-backup-start" class="button button-primary" <?php disabled( $running ); ?>><?php esc_html_e( 'Create backup now', 'admin-backend-and-update-helper' ); ?></button>
				<button type="button" id="backhe-backup-cancel" class="button" <?php echo $running ? '' : 'hidden'; ?>><?php esc_html_e( 'Cancel backup', 'admin-backend-and-update-helper' ); ?></button>
			</p>
		</div>

		<div id="backhe-backup-progress" class="backhe-backup-progress" <?php echo $running ? 'data-running="1"' : 'hidden'; ?>>
			<label for="backhe-backup-progress-bar" id="backhe-backup-progress-label"><?php esc_html_e( 'Backup progress', 'admin-backend-and-update-helper' ); ?></label>
			<progress id="backhe-backup-progress-bar" max="100" value="<?php echo esc_attr( $running ? Backhe_YIS_Backup_Manager::describe_job( $job )['progress'] : 0 ); ?>"></progress>
			<p id="backhe-backup-status" role="status" aria-live="polite"><?php echo $running ? esc_html( Backhe_YIS_Backup_Manager::describe_job( $job )['message'] ) : ''; ?></p>
		</div>
	</div>

	<div class="backhe-card backhe-card-full">
		<h2><?php esc_html_e( 'Available backups', 'admin-backend-and-update-helper' ); ?></h2>
		<?php if ( empty( $backups ) ) : ?>
			<p><?php esc_html_e( 'There are no backups yet.', 'admin-backend-and-update-helper' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'admin-backend-and-update-helper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Scope', 'admin-backend-and-update-helper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'admin-backend-and-update-helper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Files', 'admin-backend-and-update-helper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'admin-backend-and-update-helper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $backups as $backup ) : ?>
						<?php $backup_date = wp_date( $date_format, (int) $backup['created_at'] ); ?>
						<tr>
							<th scope="row">
								<?php echo esc_html( $backup_date ); ?>
								<br><span class="description"><?php echo 'scheduled' === $backup['trigger'] ? esc_html__( 'automatic', 'admin-backend-and-update-helper' ) : esc_html__( 'manual', 'admin-backend-and-update-helper' ); ?></span>
							</th>
							<td><?php echo esc_html( $scopes[ $backup['scope'] ] ?? $backup['scope'] ); ?></td>
							<td><?php echo esc_html( size_format( (int) $backup['size'] ) ); ?></td>
							<td>
								<ul class="backhe-file-list">
									<?php foreach ( $backup['files'] as $file ) : ?>
										<li>
											<a href="<?php echo esc_url( Backhe_YIS_Backup_Manager::get_download_url( $file['name'] ) ); ?>">
												<?php
												echo esc_html(
													'database' === $file['type']
														? __( 'Database', 'admin-backend-and-update-helper' )
														: sprintf(
															/* translators: %s: Part number from the file name, e.g. "files-2.zip". */
															__( 'Files (%s)', 'admin-backend-and-update-helper' ),
															preg_replace( '/^.*-(files-\d+\.zip)$/', '$1', $file['name'] )
														)
												);
												?>
												<span class="screen-reader-text">
													<?php
													echo esc_html(
														sprintf(
															/* translators: %s: Backup date. */
															__( 'download, backup from %s', 'admin-backend-and-update-helper' ),
															$backup_date
														)
													);
													?>
												</span>
											</a>
											<span class="description">(<?php echo esc_html( size_format( (int) $file['size'] ) ); ?>)</span>
										</li>
									<?php endforeach; ?>
								</ul>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="backhe-delete-backup-form">
									<input type="hidden" name="action" value="backhe_yis_backup_delete">
									<input type="hidden" name="backup_id" value="<?php echo esc_attr( $backup['id'] ); ?>">
									<?php wp_nonce_field( 'backhe_yis_backup_delete' ); ?>
									<button type="submit" class="button button-link-delete">
										<?php esc_html_e( 'Delete', 'admin-backend-and-update-helper' ); ?>
										<span class="screen-reader-text"><?php echo esc_html( $backup_date ); ?></span>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="backhe-card backhe-card-full">
		<h2><?php esc_html_e( 'Automatic backups', 'admin-backend-and-update-helper' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'backhe_yis_backup_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="backhe-backup-schedule"><?php esc_html_e( 'Schedule', 'admin-backend-and-update-helper' ); ?></label></th>
					<td>
						<select id="backhe-backup-schedule" name="<?php echo esc_attr( $option ); ?>[schedule]" aria-describedby="backhe-backup-schedule-desc">
							<?php foreach ( Backhe_YIS_Backup_Manager::get_schedules() as $schedule_key => $schedule_label ) : ?>
								<option value="<?php echo esc_attr( $schedule_key ); ?>" <?php selected( $settings['schedule'], $schedule_key ); ?>><?php echo esc_html( $schedule_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" id="backhe-backup-schedule-desc">
							<?php
							if ( $next_backup ) {
								echo esc_html(
									sprintf(
										/* translators: %s: Date and time of the next backup. */
										__( 'Next automatic backup: %s.', 'admin-backend-and-update-helper' ),
										wp_date( $date_format, $next_backup )
									)
								);
								echo ' ';
							}
							esc_html_e( 'WP-Cron only runs when the website is visited. For reliable backups, set up a real cron job at your host.', 'admin-backend-and-update-helper' );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="backhe-backup-hour"><?php esc_html_e( 'Time', 'admin-backend-and-update-helper' ); ?></label></th>
					<td>
						<select id="backhe-backup-hour" name="<?php echo esc_attr( $option ); ?>[hour]">
							<?php for ( $hour = 0; $hour < 24; $hour++ ) : ?>
								<option value="<?php echo esc_attr( $hour ); ?>" <?php selected( (int) $settings['hour'], $hour ); ?>><?php echo esc_html( sprintf( '%02d:00', $hour ) ); ?></option>
							<?php endfor; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="backhe-backup-default-scope"><?php esc_html_e( 'Scope', 'admin-backend-and-update-helper' ); ?></label></th>
					<td>
						<select id="backhe-backup-default-scope" name="<?php echo esc_attr( $option ); ?>[scope]">
							<?php foreach ( $scopes as $scope_key => $scope_label ) : ?>
								<option value="<?php echo esc_attr( $scope_key ); ?>" <?php selected( $settings['scope'], $scope_key ); ?>><?php echo esc_html( $scope_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="backhe-backup-keep"><?php esc_html_e( 'Backups to keep', 'admin-backend-and-update-helper' ); ?></label></th>
					<td>
						<input type="number" id="backhe-backup-keep" name="<?php echo esc_attr( $option ); ?>[keep]" value="<?php echo esc_attr( $settings['keep'] ); ?>" min="1" max="50" class="small-text" aria-describedby="backhe-backup-keep-desc">
						<p class="description" id="backhe-backup-keep-desc"><?php esc_html_e( 'Older backups are deleted automatically after a new backup was created.', 'admin-backend-and-update-helper' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'admin-backend-and-update-helper' ) ); ?>
		</form>
	</div>

	<div class="backhe-card backhe-card-full">
		<h2><?php esc_html_e( 'Good to know', 'admin-backend-and-update-helper' ); ?></h2>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'Restoring is not done by the plugin. To restore, import the database file (e.g. with phpMyAdmin) and upload the contents of the ZIP files to the wp-content directory.', 'admin-backend-and-update-helper' ); ?></li>
			<li><?php esc_html_e( 'Cache directories and the backup directories of other backup plugins are not included.', 'admin-backend-and-update-helper' ); ?></li>
			<li><strong><?php esc_html_e( 'When the plugin is deleted, all backups stored by it are deleted as well.', 'admin-backend-and-update-helper' ); ?></strong></li>
		</ul>
	</div>
</div>
