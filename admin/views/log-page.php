<?php
/**
 * Activity log page.
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
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.

$plugin_instance = Backhe_YIS_Media::get_instance();
$sections        = Backhe_YIS_Report::get_sections();

$filters = array(
	'section' => isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '',
	'from'    => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
	'to'      => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
);

if ( ! isset( $sections[ $filters['section'] ] ) ) {
	$filters['section'] = '';
}

foreach ( array( 'from', 'to' ) as $date_key ) {
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters[ $date_key ] ) ) {
		$filters[ $date_key ] = '';
	}
}

// Entry being edited, if any.
$edit_id    = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$edit_entry = $edit_id > 0 ? $plugin_instance->activity_log->get( $edit_id ) : null;

if ( null !== $edit_entry && 'manual' !== $edit_entry['source'] ) {
	$edit_entry = null;
}

$form_date     = null !== $edit_entry ? get_date_from_gmt( $edit_entry['created_at'], 'Y-m-d' ) : wp_date( 'Y-m-d' );
$form_text     = null !== $edit_entry ? $edit_entry['object_name'] : '';
$form_duration = null !== $edit_entry && null !== $edit_entry['duration_minutes'] ? $edit_entry['duration_minutes'] : '';

$list_table = new Backhe_YIS_Log_List_Table( $plugin_instance->activity_log, $filters );
$list_table->prepare_items();
?>
<div class="wrap backhe-yis-admin">
	<h1><?php esc_html_e( 'Activity Log', 'admin-backend-and-update-helper' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Changes to this website are recorded automatically. Add services that WordPress cannot record, e.g. support work or checks of hosting backups.', 'admin-backend-and-update-helper' ); ?></p>

	<?php Backhe_YIS_Admin_Actions::render_message(); ?>

	<div class="backhe-card backhe-card-full">
		<h2 id="backhe-manual-heading">
			<?php echo null !== $edit_entry ? esc_html__( 'Edit service', 'admin-backend-and-update-helper' ) : esc_html__( 'Add a service', 'admin-backend-and-update-helper' ); ?>
		</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="backhe-manual-form" aria-labelledby="backhe-manual-heading">
			<input type="hidden" name="action" value="backhe_yis_log_save_manual">
			<input type="hidden" name="entry_id" value="<?php echo esc_attr( null !== $edit_entry ? $edit_entry['id'] : 0 ); ?>">
			<?php wp_nonce_field( 'backhe_yis_log_save_manual' ); ?>

			<p class="backhe-field">
				<label for="backhe-entry-date"><?php esc_html_e( 'Date', 'admin-backend-and-update-helper' ); ?></label>
				<input type="date" id="backhe-entry-date" name="entry_date" value="<?php echo esc_attr( $form_date ); ?>" required>
			</p>
			<p class="backhe-field backhe-field-wide">
				<label for="backhe-entry-description"><?php esc_html_e( 'Service', 'admin-backend-and-update-helper' ); ?></label>
				<input type="text" id="backhe-entry-description" name="description" value="<?php echo esc_attr( $form_text ); ?>" class="large-text" maxlength="255" required aria-describedby="backhe-entry-description-desc">
				<span class="description" id="backhe-entry-description-desc"><?php esc_html_e( 'For example: "Contact form adjusted" or "Hosting backup checked".', 'admin-backend-and-update-helper' ); ?></span>
			</p>
			<p class="backhe-field">
				<label for="backhe-entry-duration"><?php esc_html_e( 'Duration in minutes (optional)', 'admin-backend-and-update-helper' ); ?></label>
				<input type="number" id="backhe-entry-duration" name="duration" value="<?php echo esc_attr( (string) $form_duration ); ?>" min="0" max="100000" step="1" class="small-text">
			</p>
			<p class="backhe-field">
				<?php submit_button( null !== $edit_entry ? __( 'Update service', 'admin-backend-and-update-helper' ) : __( 'Add service', 'admin-backend-and-update-helper' ), 'primary', 'submit', false ); ?>
				<?php if ( null !== $edit_entry ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=backhe_yis_log' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'admin-backend-and-update-helper' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
	</div>

	<h2><?php esc_html_e( 'Entries', 'admin-backend-and-update-helper' ); ?></h2>
	<form method="get" class="backhe-log-filters">
		<input type="hidden" name="page" value="backhe_yis_log">
		<p class="backhe-field">
			<label for="backhe-filter-section"><?php esc_html_e( 'Area', 'admin-backend-and-update-helper' ); ?></label>
			<select id="backhe-filter-section" name="section">
				<option value=""><?php esc_html_e( 'All areas', 'admin-backend-and-update-helper' ); ?></option>
				<?php foreach ( $sections as $section_key => $section ) : ?>
					<option value="<?php echo esc_attr( $section_key ); ?>" <?php selected( $filters['section'], $section_key ); ?>><?php echo esc_html( $section['title'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="backhe-field">
			<label for="backhe-filter-from"><?php esc_html_e( 'From', 'admin-backend-and-update-helper' ); ?></label>
			<input type="date" id="backhe-filter-from" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>">
		</p>
		<p class="backhe-field">
			<label for="backhe-filter-to"><?php esc_html_e( 'To', 'admin-backend-and-update-helper' ); ?></label>
			<input type="date" id="backhe-filter-to" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>">
		</p>
		<p class="backhe-field">
			<?php submit_button( __( 'Filter', 'admin-backend-and-update-helper' ), 'secondary', '', false ); ?>
		</p>
	</form>

	<?php $list_table->display(); ?>

	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: Number of months. */
				_n( 'Entries are kept for %d month.', 'Entries are kept for %d months.', Backhe_YIS_Activity_Log::get_retention_months(), 'admin-backend-and-update-helper' ),
				Backhe_YIS_Activity_Log::get_retention_months()
			)
		);
		?>
	</p>
</div>
