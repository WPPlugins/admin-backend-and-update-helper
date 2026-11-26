<?php
/**
 * Update Checker Class
 *
 * Handles checking for WordPress core, plugin, theme, and translation updates
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Backhe_YIS_Update_Checker
 *
 * Provides methods to check for various types of WordPress updates
 */
class Backhe_YIS_Update_Checker {

	/**
	 * Check all available updates
	 *
	 * Forces WordPress to check for updates and returns comprehensive update information
	 *
	 * @since 1.0.0
	 * @return array Array containing all update information categorized by type
	 */
	public function check_all_updates() {
		// Force WordPress to check for updates.
		wp_update_plugins();
		wp_update_themes();
		wp_version_check();

		// Compile all update information.
		$updates = array(
			'core'         => $this->check_core_updates(),
			'plugins'      => $this->check_plugin_updates(),
			'themes'       => $this->check_theme_updates(),
			'translations' => $this->check_translation_updates(),
		);

		return $updates;
	}

	/**
	 * Check for WordPress core updates
	 *
	 * @since 1.0.0
	 * @return array Array of core update information
	 */
	public function check_core_updates() {
		$core_updates = get_core_updates();
		$updates      = array();

		// Check if updates are available and valid.
		if ( empty( $core_updates ) || is_wp_error( $core_updates ) ) {
			return $updates;
		}

		// Process each core update.
		foreach ( $core_updates as $update ) {
			// Only include actual upgrades, not development or automatic updates.
			if ( 'upgrade' === $update->response ) {
				$updates[] = array(
					'current_version' => get_bloginfo( 'version' ),
					'new_version'     => $update->version,
					'package'         => isset( $update->package ) ? $update->package : '',
					'locale'          => isset( $update->locale ) ? $update->locale : get_locale(),
					'php_version'     => isset( $update->php_version ) ? $update->php_version : '',
					'mysql_version'   => isset( $update->mysql_version ) ? $update->mysql_version : '',
				);
			}
		}

		return $updates;
	}

	/**
	 * Check for plugin updates
	 *
	 * @since 1.0.0
	 * @return array Array of plugin update information
	 */
	public function check_plugin_updates() {
		$plugin_updates = get_plugin_updates();
		$updates        = array();

		// Return empty array if no updates.
		if ( empty( $plugin_updates ) ) {
			return $updates;
		}

		// Process each plugin update.
		foreach ( $plugin_updates as $plugin_file => $plugin_data ) {
			// Ensure update data exists.
			if ( ! isset( $plugin_data->update ) || empty( $plugin_data->update->new_version ) ) {
				continue;
			}

			$updates[] = array(
				'name'            => $plugin_data->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Plugin header field set by WordPress.
				'slug'            => dirname( $plugin_file ),
				'plugin_file'     => $plugin_file,
				'current_version' => $plugin_data->Version, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Plugin header field set by WordPress.
				'new_version'     => $plugin_data->update->new_version,
				'author'          => $plugin_data->Author, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Plugin header field set by WordPress.
				'requires_php'    => isset( $plugin_data->update->requires_php ) ? $plugin_data->update->requires_php : '',
				'requires_wp'     => isset( $plugin_data->update->requires ) ? $plugin_data->update->requires : '',
				'tested_up_to'    => isset( $plugin_data->update->tested ) ? $plugin_data->update->tested : '',
				'package'         => isset( $plugin_data->update->package ) ? $plugin_data->update->package : '',
			);
		}

		return $updates;
	}

	/**
	 * Check for theme updates
	 *
	 * @since 1.0.0
	 * @return array Array of theme update information
	 */
	public function check_theme_updates() {
		$theme_updates = get_theme_updates();
		$updates       = array();

		// Return empty array if no updates.
		if ( empty( $theme_updates ) ) {
			return $updates;
		}

		// Process each theme update.
		foreach ( $theme_updates as $stylesheet => $theme_data ) {
			// Ensure update data exists.
			if ( ! isset( $theme_data->update ) || empty( $theme_data->update['new_version'] ) ) {
				continue;
			}

			$updates[] = array(
				'name'            => $theme_data->get( 'Name' ),
				'slug'            => $stylesheet,
				'stylesheet'      => $stylesheet,
				'current_version' => $theme_data->get( 'Version' ),
				'new_version'     => $theme_data->update['new_version'],
				'author'          => $theme_data->get( 'Author' ),
				'template'        => $theme_data->get( 'Template' ),
				'requires_php'    => isset( $theme_data->update['requires_php'] ) ? $theme_data->update['requires_php'] : '',
				'requires_wp'     => isset( $theme_data->update['requires'] ) ? $theme_data->update['requires'] : '',
				'package'         => isset( $theme_data->update['package'] ) ? $theme_data->update['package'] : '',
			);
		}

		return $updates;
	}

	/**
	 * Check for translation updates
	 *
	 * @since 1.0.0
	 * @return array Array of translation update information
	 */
	public function check_translation_updates() {
		$translation_updates = wp_get_translation_updates();
		$updates             = array();

		// Return empty array if no updates.
		if ( empty( $translation_updates ) ) {
			return $updates;
		}

		// Process each translation update.
		foreach ( $translation_updates as $update ) {
			$updates[] = array(
				'type'       => isset( $update->type ) ? $update->type : 'unknown',
				'slug'       => isset( $update->slug ) ? $update->slug : '',
				'language'   => isset( $update->language ) ? $update->language : '',
				'version'    => isset( $update->version ) ? $update->version : '',
				'updated'    => isset( $update->updated ) ? $update->updated : '',
				'package'    => isset( $update->package ) ? $update->package : '',
				'autoupdate' => isset( $update->autoupdate ) ? $update->autoupdate : false,
			);
		}

		return $updates;
	}

	/**
	 * Get total update count across all categories
	 *
	 * @since 1.0.0
	 * @param array $updates Array of updates from check_all_updates().
	 * @return int Total number of updates available
	 */
	public function get_update_count( $updates ) {
		$count = 0;

		// Validate input.
		if ( ! is_array( $updates ) ) {
			return $count;
		}

		// Count core updates.
		if ( isset( $updates['core'] ) && is_array( $updates['core'] ) ) {
			$count += count( $updates['core'] );
		}

		// Count plugin updates.
		if ( isset( $updates['plugins'] ) && is_array( $updates['plugins'] ) ) {
			$count += count( $updates['plugins'] );
		}

		// Count theme updates.
		if ( isset( $updates['themes'] ) && is_array( $updates['themes'] ) ) {
			$count += count( $updates['themes'] );
		}

		// Count translation updates.
		if ( isset( $updates['translations'] ) && is_array( $updates['translations'] ) ) {
			$count += count( $updates['translations'] );
		}

		return $count;
	}

	/**
	 * Get updates by category
	 *
	 * Helper method to get updates for a specific category
	 *
	 * @since 1.0.0
	 * @param string $category Category name (core, plugins, themes, translations).
	 * @return array Array of updates for the specified category
	 */
	public function get_updates_by_category( $category ) {
		$all_updates = $this->check_all_updates();

		if ( isset( $all_updates[ $category ] ) && is_array( $all_updates[ $category ] ) ) {
			return $all_updates[ $category ];
		}

		return array();
	}

	/**
	 * Check if any updates are available
	 *
	 * Quick method to check if there are any pending updates
	 *
	 * @since 1.0.0
	 * @return bool True if updates are available, false otherwise
	 */
	public function has_updates() {
		$updates = $this->check_all_updates();
		$count   = $this->get_update_count( $updates );

		return $count > 0;
	}

	/**
	 * Get update summary
	 *
	 * Returns a summary of available updates by category
	 *
	 * @since 1.0.0
	 * @return array Array with counts for each update category
	 */
	public function get_update_summary() {
		$updates = $this->check_all_updates();

		return array(
			'core'         => isset( $updates['core'] ) ? count( $updates['core'] ) : 0,
			'plugins'      => isset( $updates['plugins'] ) ? count( $updates['plugins'] ) : 0,
			'themes'       => isset( $updates['themes'] ) ? count( $updates['themes'] ) : 0,
			'translations' => isset( $updates['translations'] ) ? count( $updates['translations'] ) : 0,
			'total'        => $this->get_update_count( $updates ),
		);
	}
}
