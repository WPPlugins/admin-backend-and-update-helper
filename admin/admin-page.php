<?php
/**
 * Overview page: updates, system information, notifications and access settings.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.0.0
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

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch, validated against a whitelist below.
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'updates';
$page_tabs  = array(
	'updates'       => __( 'Updates', 'admin-backend-and-update-helper' ),
	'system'        => __( 'Server & website', 'admin-backend-and-update-helper' ),
	'notifications' => __( 'Notifications', 'admin-backend-and-update-helper' ),
	'settings'      => __( 'Settings', 'admin-backend-and-update-helper' ),
);

if ( ! array_key_exists( $active_tab, $page_tabs ) ) {
	$active_tab = 'updates';
}

$settings = wp_parse_args(
	get_option( 'backhe_yis_settings', array() ),
	array(
		'email_enabled'          => '0',
		'recipient_type'         => 'admin',
		'custom_email'           => '',
		'custom_email_2'         => '',
		'selected_user'          => get_current_user_id(),
		'notification_frequency' => 'daily',
		'custom_days'            => 1,
		'notify_on_updates'      => '0',
		'allowed_roles'          => array( 'administrator' ),
	)
);

$yes_label = __( 'Yes', 'admin-backend-and-update-helper' );
$no_label  = __( 'No', 'admin-backend-and-update-helper' );
?>
<div class="wrap backhe-yis-admin">
	<h1><?php esc_html_e( 'Admin Backend and Update Helper', 'admin-backend-and-update-helper' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Update notifications and system information for your WordPress website.', 'admin-backend-and-update-helper' ); ?></p>

	<?php settings_errors(); ?>
	<div id="backhe-yis-notices" class="backhe-yis-notices"></div>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Overview sections', 'admin-backend-and-update-helper' ); ?>">
		<?php foreach ( $page_tabs as $tab_key => $tab_label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_key, admin_url( 'admin.php?page=backhe_yis_admin' ) ) ); ?>"
				class="nav-tab<?php echo $tab_key === $active_tab ? ' nav-tab-active' : ''; ?>"
				<?php echo $tab_key === $active_tab ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="backhe-tab-content">

		<?php
		if ( 'updates' === $active_tab ) :
			$updates      = $plugin_instance->update_checker->check_all_updates();
			$update_count = $plugin_instance->update_checker->get_update_count( $updates );
			$last_check   = (int) get_option( 'backhe_yis_last_check', 0 );
			$sections     = array(
				'core'    => array(
					'title' => __( 'WordPress core', 'admin-backend-and-update-helper' ),
					'type'  => 'core',
				),
				'plugins' => array(
					'title' => __( 'Plugins', 'admin-backend-and-update-helper' ),
					'type'  => 'plugin',
				),
				'themes'  => array(
					'title' => __( 'Themes', 'admin-backend-and-update-helper' ),
					'type'  => 'theme',
				),
			);
			?>
			<div class="backhe-card backhe-card-full">
				<div class="backhe-card-header">
					<h2><?php esc_html_e( 'Available updates', 'admin-backend-and-update-helper' ); ?></h2>
					<button type="button" id="backhe-manual-check-btn" class="button button-primary">
						<?php esc_html_e( 'Check now', 'admin-backend-and-update-helper' ); ?>
					</button>
				</div>

				<div class="backhe-update-summary">
					<p class="backhe-update-badge <?php echo $update_count > 0 ? 'backhe-badge-warning' : 'backhe-badge-success'; ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: Number of available updates. */
								_n( '%s update available', '%s updates available', $update_count, 'admin-backend-and-update-helper' ),
								number_format_i18n( $update_count )
							)
						);
						?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Last check:', 'admin-backend-and-update-helper' ); ?>
						<strong id="last-check-time"><?php echo $last_check ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_check ) ) : esc_html__( 'Never', 'admin-backend-and-update-helper' ); ?></strong>
					</p>
				</div>

				<?php foreach ( $sections as $section_key => $section ) : ?>
					<?php
					if ( empty( $updates[ $section_key ] ) ) {
						continue;
					}
					?>
					<div class="backhe-update-section">
						<h3><?php echo esc_html( $section['title'] . ' (' . count( $updates[ $section_key ] ) . ')' ); ?></h3>
						<table class="widefat striped">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Name', 'admin-backend-and-update-helper' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Installed', 'admin-backend-and-update-helper' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Available', 'admin-backend-and-update-helper' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Action', 'admin-backend-and-update-helper' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $updates[ $section_key ] as $item ) : ?>
									<?php
									$item_name = isset( $item['name'] ) ? $item['name'] : 'WordPress';

									if ( 'plugin' === $section['type'] ) {
										$item_slug = $item['plugin_file'];
									} elseif ( 'theme' === $section['type'] ) {
										$item_slug = $item['stylesheet'];
									} else {
										$item_slug = 'core';
									}
									?>
									<tr>
										<th scope="row"><?php echo esc_html( $item_name ); ?></th>
										<td><?php echo esc_html( $item['current_version'] ); ?></td>
										<td><strong class="backhe-version-new"><?php echo esc_html( $item['new_version'] ); ?></strong></td>
										<td>
											<button type="button" class="button backhe-update-single-btn"
												data-type="<?php echo esc_attr( $section['type'] ); ?>"
												data-slug="<?php echo esc_attr( $item_slug ); ?>">
												<?php esc_html_e( 'Install update', 'admin-backend-and-update-helper' ); ?>
												<span class="screen-reader-text"><?php echo esc_html( $item_name ); ?></span>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endforeach; ?>

				<?php if ( ! empty( $updates['translations'] ) ) : ?>
					<div class="backhe-update-section">
						<h3><?php esc_html_e( 'Translations', 'admin-backend-and-update-helper' ); ?></h3>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: Number of translation updates. */
									_n( '%s translation update available.', '%s translation updates available.', count( $updates['translations'] ), 'admin-backend-and-update-helper' ),
									number_format_i18n( count( $updates['translations'] ) )
								)
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( 0 === $update_count ) : ?>
					<p class="backhe-success-message"><span aria-hidden="true">✓</span> <?php esc_html_e( 'Everything is up to date.', 'admin-backend-and-update-helper' ); ?></p>
				<?php else : ?>
					<p>
						<button type="button" id="backhe-update-all-btn" class="button button-primary button-large">
							<?php esc_html_e( 'Install all updates', 'admin-backend-and-update-helper' ); ?>
						</button>
					</p>
				<?php endif; ?>
			</div>

			<?php
		elseif ( 'system' === $active_tab ) :
			$system_info = $plugin_instance->system_info->get_all_info();
			$info_tables = array(
				array(
					'title' => __( 'Server', 'admin-backend-and-update-helper' ),
					'rows'  => array(
						array( __( 'Server software', 'admin-backend-and-update-helper' ), $system_info['server']['software'] ),
						array( __( 'Operating system', 'admin-backend-and-update-helper' ), $system_info['server']['os'] ),
						array( __( 'Host name', 'admin-backend-and-update-helper' ), $system_info['server']['hostname'] ),
						array( __( 'Architecture', 'admin-backend-and-update-helper' ), $system_info['server']['architecture'] ),
					),
				),
				array(
					'title' => __( 'PHP', 'admin-backend-and-update-helper' ),
					'rows'  => array(
						array( __( 'PHP version', 'admin-backend-and-update-helper' ), $system_info['php']['version'] ),
						array( __( 'Latest stable PHP version', 'admin-backend-and-update-helper' ), $system_info['php']['latest_stable'] ),
						array( __( 'Memory limit', 'admin-backend-and-update-helper' ), $system_info['php']['memory_limit'] ),
						array( __( 'Max execution time', 'admin-backend-and-update-helper' ), $system_info['php']['max_execution_time'] ),
						array( __( 'Upload max filesize', 'admin-backend-and-update-helper' ), $system_info['php']['upload_max_filesize'] ),
						array( __( 'Post max size', 'admin-backend-and-update-helper' ), $system_info['php']['post_max_size'] ),
						array( __( 'Max input vars', 'admin-backend-and-update-helper' ), $system_info['php']['max_input_vars'] ),
						array( __( 'Display errors', 'admin-backend-and-update-helper' ), $system_info['php']['display_errors'] ? $yes_label : $no_label ),
						array( __( 'Allow URL fopen', 'admin-backend-and-update-helper' ), $system_info['php']['allow_url_fopen'] ? $yes_label : $no_label ),
					),
				),
				array(
					'title' => __( 'Database', 'admin-backend-and-update-helper' ),
					'rows'  => array(
						array( __( 'Database version', 'admin-backend-and-update-helper' ), $system_info['database']['version'] ),
						array( __( 'Charset', 'admin-backend-and-update-helper' ), $system_info['database']['charset'] ),
						array( __( 'Collation', 'admin-backend-and-update-helper' ), $system_info['database']['collation'] ),
						array( __( 'Table prefix', 'admin-backend-and-update-helper' ), $system_info['database']['prefix'] ),
						array( __( 'Max allowed packet', 'admin-backend-and-update-helper' ), $system_info['database']['max_allowed_packet'] ),
						array( __( 'Max connections', 'admin-backend-and-update-helper' ), $system_info['database']['max_connections'] ),
						array( __( 'Number of tables', 'admin-backend-and-update-helper' ), $system_info['database']['table_count'] ),
						array( __( 'Database size', 'admin-backend-and-update-helper' ), $system_info['database']['database_size'] ),
					),
				),
				array(
					'title' => __( 'WordPress', 'admin-backend-and-update-helper' ),
					'rows'  => array(
						array( __( 'WordPress version', 'admin-backend-and-update-helper' ), $system_info['wordpress']['version'] ),
						array( __( 'Multisite', 'admin-backend-and-update-helper' ), $system_info['wordpress']['multisite'] ? $yes_label : $no_label ),
						array( __( 'Language', 'admin-backend-and-update-helper' ), $system_info['wordpress']['language'] ),
						array( __( 'Debug mode', 'admin-backend-and-update-helper' ), $system_info['wordpress']['debug_mode'] ? $yes_label : $no_label ),
						array( __( 'WP memory limit', 'admin-backend-and-update-helper' ), $system_info['wordpress']['wp_memory_limit'] ),
						array( __( 'WP max memory limit', 'admin-backend-and-update-helper' ), $system_info['wordpress']['wp_max_memory_limit'] ),
						array( __( 'Home URL', 'admin-backend-and-update-helper' ), $system_info['wordpress']['home_url'] ),
						array( __( 'Site URL', 'admin-backend-and-update-helper' ), $system_info['wordpress']['site_url'] ),
					),
				),
			);
			?>
			<div class="backhe-grid">
				<?php if ( ! empty( $system_info['php']['update_recommended'] ) ) : ?>
					<div class="notice notice-warning inline backhe-card-full">
						<p>
							<strong><?php esc_html_e( 'A newer PHP version is recommended.', 'admin-backend-and-update-helper' ); ?></strong>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: Current PHP version, 2: Latest stable PHP version. */
									__( 'Your PHP version: %1$s. Latest stable version: %2$s.', 'admin-backend-and-update-helper' ),
									$system_info['php']['version'],
									$system_info['php']['latest_stable']
								)
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php foreach ( $info_tables as $info_table ) : ?>
					<div class="backhe-card backhe-card-full">
						<h2><?php echo esc_html( $info_table['title'] ); ?></h2>
						<table class="widefat striped backhe-info-table">
							<tbody>
								<?php foreach ( $info_table['rows'] as $info_row ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $info_row[0] ); ?></th>
										<td><?php echo esc_html( (string) $info_row[1] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endforeach; ?>

				<div class="backhe-card backhe-card-full">
					<h2><?php esc_html_e( 'Themes', 'admin-backend-and-update-helper' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Theme', 'admin-backend-and-update-helper' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Version', 'admin-backend-and-update-helper' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'admin-backend-and-update-helper' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $system_info['theme']['all'] as $theme_item ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $theme_item['name'] ); ?></th>
									<td><?php echo esc_html( $theme_item['version'] ); ?></td>
									<td><?php echo $theme_item['is_active'] ? esc_html__( 'Active', 'admin-backend-and-update-helper' ) : esc_html__( 'Inactive', 'admin-backend-and-update-helper' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="backhe-card backhe-card-full">
					<h2><?php esc_html_e( 'Plugins', 'admin-backend-and-update-helper' ); ?></h2>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: Total number of plugins, 2: Active plugins, 3: Inactive plugins. */
								__( '%1$d installed, %2$d active, %3$d inactive', 'admin-backend-and-update-helper' ),
								$system_info['plugins']['total'],
								$system_info['plugins']['active'],
								$system_info['plugins']['inactive']
							)
						);
						?>
					</p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Plugin', 'admin-backend-and-update-helper' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Version', 'admin-backend-and-update-helper' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Author', 'admin-backend-and-update-helper' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'admin-backend-and-update-helper' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $system_info['plugins']['list'] as $plugin_item ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $plugin_item['name'] ); ?></th>
									<td><?php echo esc_html( $plugin_item['version'] ); ?></td>
									<td><?php echo esc_html( $plugin_item['author'] ); ?></td>
									<td><?php echo $plugin_item['is_active'] ? esc_html__( 'Active', 'admin-backend-and-update-helper' ) : esc_html__( 'Inactive', 'admin-backend-and-update-helper' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="backhe-card backhe-card-full">
					<h2><?php esc_html_e( 'PHP extensions', 'admin-backend-and-update-helper' ); ?></h2>
					<ul class="backhe-extensions-grid">
						<?php foreach ( $system_info['extensions'] as $extension => $loaded ) : ?>
							<li class="backhe-extension-badge <?php echo $loaded ? 'backhe-ext-loaded' : 'backhe-ext-missing'; ?>">
								<span aria-hidden="true"><?php echo $loaded ? '✓' : '✗'; ?></span>
								<?php echo esc_html( $extension ); ?>
								<span class="screen-reader-text"><?php echo $loaded ? esc_html__( '(available)', 'admin-backend-and-update-helper' ) : esc_html__( '(missing)', 'admin-backend-and-update-helper' ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="backhe-card backhe-card-full">
					<h2><?php esc_html_e( 'Disk space', 'admin-backend-and-update-helper' ); ?></h2>
					<table class="widefat striped backhe-info-table">
						<tbody>
							<tr><th scope="row"><?php esc_html_e( 'Website size', 'admin-backend-and-update-helper' ); ?></th><td><?php echo esc_html( $system_info['disk']['website_size'] ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Total space', 'admin-backend-and-update-helper' ); ?></th><td><?php echo esc_html( $system_info['disk']['total_formatted'] ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Used', 'admin-backend-and-update-helper' ); ?></th><td><?php echo esc_html( $system_info['disk']['used_formatted'] ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Free', 'admin-backend-and-update-helper' ); ?></th><td><?php echo esc_html( $system_info['disk']['free_formatted'] ); ?></td></tr>
						</tbody>
					</table>
					<p>
						<label for="backhe-disk-usage"><?php esc_html_e( 'Disk usage:', 'admin-backend-and-update-helper' ); ?></label>
						<progress id="backhe-disk-usage" class="backhe-progress" max="100" value="<?php echo esc_attr( $system_info['disk']['used_percentage'] ); ?>"></progress>
						<strong><?php echo esc_html( $system_info['disk']['used_percentage'] . ' %' ); ?></strong>
					</p>
				</div>
			</div>

			<?php
		elseif ( 'notifications' === $active_tab ) :
			$users       = get_users( array( 'role__in' => array( 'administrator', 'editor' ) ) );
			$frequencies = array(
				'hourly'     => __( 'Hourly', 'admin-backend-and-update-helper' ),
				'twicedaily' => __( 'Twice daily', 'admin-backend-and-update-helper' ),
				'daily'      => __( 'Daily', 'admin-backend-and-update-helper' ),
				'weekly'     => __( 'Weekly', 'admin-backend-and-update-helper' ),
				'biweekly'   => __( 'Every two weeks', 'admin-backend-and-update-helper' ),
				'monthly'    => __( 'Monthly', 'admin-backend-and-update-helper' ),
				'custom'     => __( 'Custom number of days', 'admin-backend-and-update-helper' ),
			);
			?>
			<div class="backhe-card backhe-card-full">
				<h2><?php esc_html_e( 'Email notifications', 'admin-backend-and-update-helper' ); ?></h2>
				<form method="post" action="options.php" id="backhe-settings-form">
					<?php settings_fields( 'backhe_yis_settings_group' ); ?>
					<input type="hidden" name="backhe_yis_settings[section]" value="notifications">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Available updates', 'admin-backend-and-update-helper' ); ?></th>
							<td>
								<input type="checkbox" id="backhe-email-enabled" name="backhe_yis_settings[email_enabled]" value="1" aria-describedby="backhe-email-enabled-desc" <?php checked( $settings['email_enabled'], '1' ); ?>>
								<label for="backhe-email-enabled"><?php esc_html_e( 'Send an email when updates are available', 'admin-backend-and-update-helper' ); ?></label>
								<p class="description" id="backhe-email-enabled-desc"><?php esc_html_e( 'The check runs in the background at the frequency below.', 'admin-backend-and-update-helper' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Installed updates', 'admin-backend-and-update-helper' ); ?></th>
							<td>
								<input type="checkbox" id="backhe-notify-on-updates" name="backhe_yis_settings[notify_on_updates]" value="1" <?php checked( $settings['notify_on_updates'], '1' ); ?>>
								<label for="backhe-notify-on-updates"><?php esc_html_e( 'Send an email after updates were installed', 'admin-backend-and-update-helper' ); ?></label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Recipient', 'admin-backend-and-update-helper' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Recipient', 'admin-backend-and-update-helper' ); ?></legend>
									<p>
										<input type="radio" id="backhe-recipient-admin" name="backhe_yis_settings[recipient_type]" value="admin" <?php checked( $settings['recipient_type'], 'admin' ); ?>>
										<label for="backhe-recipient-admin">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %s: Administration email address. */
													__( 'Administration email address (%s)', 'admin-backend-and-update-helper' ),
													get_option( 'admin_email' )
												)
											);
											?>
										</label>
									</p>
									<p>
										<input type="radio" id="backhe-recipient-user" name="backhe_yis_settings[recipient_type]" value="user" <?php checked( $settings['recipient_type'], 'user' ); ?>>
										<label for="backhe-recipient-user"><?php esc_html_e( 'A WordPress user', 'admin-backend-and-update-helper' ); ?></label>
									</p>
									<div id="user-select-wrapper" class="backhe-conditional">
										<label for="backhe-selected-user"><?php esc_html_e( 'User', 'admin-backend-and-update-helper' ); ?></label>
										<select id="backhe-selected-user" name="backhe_yis_settings[selected_user]">
											<?php foreach ( $users as $user ) : ?>
												<option value="<?php echo esc_attr( $user->ID ); ?>" data-email="<?php echo esc_attr( $user->user_email ); ?>" <?php selected( (int) $settings['selected_user'], $user->ID ); ?>>
													<?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
									<p>
										<input type="radio" id="backhe-recipient-custom" name="backhe_yis_settings[recipient_type]" value="custom" <?php checked( $settings['recipient_type'], 'custom' ); ?>>
										<label for="backhe-recipient-custom"><?php esc_html_e( 'Custom email addresses', 'admin-backend-and-update-helper' ); ?></label>
									</p>
									<div id="custom-email-wrapper" class="backhe-conditional">
										<p>
											<label for="backhe-custom-email"><?php esc_html_e( 'First email address', 'admin-backend-and-update-helper' ); ?></label><br>
											<input type="email" id="backhe-custom-email" name="backhe_yis_settings[custom_email]" value="<?php echo esc_attr( $settings['custom_email'] ); ?>" class="regular-text" autocomplete="email">
										</p>
										<p>
											<label for="backhe-custom-email-2"><?php esc_html_e( 'Second email address (optional)', 'admin-backend-and-update-helper' ); ?></label><br>
											<input type="email" id="backhe-custom-email-2" name="backhe_yis_settings[custom_email_2]" value="<?php echo esc_attr( $settings['custom_email_2'] ); ?>" class="regular-text" autocomplete="email">
										</p>
									</div>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="notification-frequency"><?php esc_html_e( 'Check frequency', 'admin-backend-and-update-helper' ); ?></label></th>
							<td>
								<select name="backhe_yis_settings[notification_frequency]" id="notification-frequency" aria-describedby="backhe-frequency-desc">
									<?php foreach ( $frequencies as $frequency_key => $frequency_label ) : ?>
										<option value="<?php echo esc_attr( $frequency_key ); ?>" <?php selected( $settings['notification_frequency'], $frequency_key ); ?>><?php echo esc_html( $frequency_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<div id="custom-days-wrapper" class="backhe-conditional">
									<label for="backhe-custom-days"><?php esc_html_e( 'Number of days', 'admin-backend-and-update-helper' ); ?></label>
									<input type="number" id="backhe-custom-days" name="backhe_yis_settings[custom_days]" value="<?php echo esc_attr( $settings['custom_days'] ); ?>" min="1" max="365" class="small-text">
								</div>
								<p class="description" id="backhe-frequency-desc"><?php esc_html_e( 'How often should the plugin check for updates and send notifications?', 'admin-backend-and-update-helper' ); ?></p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<?php submit_button( __( 'Save settings', 'admin-backend-and-update-helper' ), 'primary', 'submit', false ); ?>
						<button type="button" id="backhe-test-email-btn" class="button button-secondary"><?php esc_html_e( 'Send test email', 'admin-backend-and-update-helper' ); ?></button>
					</p>
				</form>
			</div>

			<?php
		elseif ( 'settings' === $active_tab ) :
			$all_roles = wp_roles()->get_names();
			?>
			<div class="backhe-card backhe-card-full">
				<h2><?php esc_html_e( 'Plugin settings', 'admin-backend-and-update-helper' ); ?></h2>
				<form method="post" action="options.php">
					<?php settings_fields( 'backhe_yis_settings_group' ); ?>
					<input type="hidden" name="backhe_yis_settings[section]" value="access">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Access for user roles', 'admin-backend-and-update-helper' ); ?></th>
							<td>
								<fieldset aria-describedby="backhe-roles-desc">
									<legend class="screen-reader-text"><?php esc_html_e( 'Access for user roles', 'admin-backend-and-update-helper' ); ?></legend>
									<?php foreach ( $all_roles as $role_key => $role_name ) : ?>
										<p>
											<input type="checkbox" id="backhe-role-<?php echo esc_attr( $role_key ); ?>" name="backhe_yis_settings[allowed_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $settings['allowed_roles'], true ) ); ?>>
											<label for="backhe-role-<?php echo esc_attr( $role_key ); ?>"><?php echo esc_html( translate_user_role( $role_name ) ); ?></label>
										</p>
									<?php endforeach; ?>
								</fieldset>
								<p class="description" id="backhe-roles-desc"><?php esc_html_e( 'Choose which user roles may use the plugin. Users additionally need the "manage_options" capability.', 'admin-backend-and-update-helper' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save settings', 'admin-backend-and-update-helper' ) ); ?>
				</form>
			</div>
		<?php endif; ?>

	</div>
</div>
