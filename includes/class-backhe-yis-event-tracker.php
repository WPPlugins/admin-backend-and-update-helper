<?php
/**
 * Event tracker.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to WordPress hooks and writes the changes to the activity log.
 *
 * Tracked: core, plugin, theme and translation updates (including automatic
 * updates), plugin and theme management, published content, user changes and
 * completed UpdraftPlus backups.
 *
 * @since 1.1.0
 */
class Backhe_YIS_Event_Tracker {

	/**
	 * Activity log.
	 *
	 * @since 1.1.0
	 * @var Backhe_YIS_Activity_Log
	 */
	private $log;

	/**
	 * Versions before an update, keyed by "plugin:<file>" or "theme:<stylesheet>".
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	private $previous_versions = array();

	/**
	 * WordPress version before a core update.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private $previous_core_version = '';

	/**
	 * Names of plugins/themes about to be deleted, keyed like $previous_versions.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	private $deleted_names = array();

	/**
	 * Register hooks.
	 *
	 * @since 1.1.0
	 *
	 * @param Backhe_YIS_Activity_Log $log Activity log.
	 */
	public function __construct( Backhe_YIS_Activity_Log $log ) {
		$this->log = $log;

		// Updates and installations.
		add_filter( 'upgrader_pre_download', array( $this, 'remember_core_version' ), 10, 4 );
		add_filter( 'upgrader_pre_install', array( $this, 'remember_version' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'log_upgrade' ), 20, 2 );
		add_action( '_core_updated_successfully', array( $this, 'log_core_update' ) );

		// Plugin and theme management.
		add_action( 'activated_plugin', array( $this, 'log_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( $this, 'log_plugin_deactivated' ) );
		add_action( 'delete_plugin', array( $this, 'remember_plugin_name' ) );
		add_action( 'deleted_plugin', array( $this, 'log_plugin_deleted' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'log_theme_switched' ), 10, 3 );
		add_action( 'delete_theme', array( $this, 'remember_theme_name' ) );
		add_action( 'deleted_theme', array( $this, 'log_theme_deleted' ), 10, 2 );

		// Content.
		add_action( 'transition_post_status', array( $this, 'log_post_status' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'log_post_updated' ), 10, 3 );

		// Users.
		add_action( 'user_register', array( $this, 'log_user_created' ) );
		add_action( 'deleted_user', array( $this, 'log_user_deleted' ), 10, 3 );
		add_action( 'set_user_role', array( $this, 'log_user_role_changed' ), 10, 3 );

		// Backups created by UpdraftPlus (filter applied once a backup finished without errors).
		add_filter( 'updraftplus_backup_complete', array( $this, 'log_updraftplus_backup' ) );
	}

	/**
	 * Get the human-readable labels of all event types.
	 *
	 * @since 1.1.0
	 *
	 * @return string[] Labels keyed by event type.
	 */
	public static function get_event_labels() {
		return array(
			'core_updated'        => __( 'WordPress updated', 'admin-backend-and-update-helper' ),
			'plugin_updated'      => __( 'Plugin updated', 'admin-backend-and-update-helper' ),
			'theme_updated'       => __( 'Theme updated', 'admin-backend-and-update-helper' ),
			'translation_updated' => __( 'Translation updated', 'admin-backend-and-update-helper' ),
			'plugin_installed'    => __( 'Plugin installed', 'admin-backend-and-update-helper' ),
			'plugin_activated'    => __( 'Plugin activated', 'admin-backend-and-update-helper' ),
			'plugin_deactivated'  => __( 'Plugin deactivated', 'admin-backend-and-update-helper' ),
			'plugin_deleted'      => __( 'Plugin deleted', 'admin-backend-and-update-helper' ),
			'theme_installed'     => __( 'Theme installed', 'admin-backend-and-update-helper' ),
			'theme_switched'      => __( 'Theme switched', 'admin-backend-and-update-helper' ),
			'theme_deleted'       => __( 'Theme deleted', 'admin-backend-and-update-helper' ),
			'post_published'      => __( 'Content published', 'admin-backend-and-update-helper' ),
			'post_updated'        => __( 'Content edited', 'admin-backend-and-update-helper' ),
			'post_trashed'        => __( 'Content moved to trash', 'admin-backend-and-update-helper' ),
			'user_created'        => __( 'User created', 'admin-backend-and-update-helper' ),
			'user_deleted'        => __( 'User deleted', 'admin-backend-and-update-helper' ),
			'user_role_changed'   => __( 'User role changed', 'admin-backend-and-update-helper' ),
			'backup_completed'    => __( 'Backup created', 'admin-backend-and-update-helper' ),
			'backup_failed'       => __( 'Backup failed', 'admin-backend-and-update-helper' ),
			'manual'              => __( 'Manual service', 'admin-backend-and-update-helper' ),
		);
	}

	/**
	 * Remember the WordPress version before a core update.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed       $reply      Filtered download reply, passed through unchanged.
	 * @param string      $package    Package URL.
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra Extra arguments.
	 * @return mixed Unchanged reply.
	 */
	public function remember_core_version( $reply, $package, $upgrader, $hook_extra ) {
		if ( $upgrader instanceof Core_Upgrader && '' === $this->previous_core_version ) {
			$this->previous_core_version = get_bloginfo( 'version' );
		}

		unset( $package, $hook_extra );

		return $reply;
	}

	/**
	 * Remember the installed version before a plugin or theme is replaced.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $response   Filtered response, passed through unchanged.
	 * @param array $hook_extra Extra arguments with "plugin" or "theme".
	 * @return mixed Unchanged response.
	 */
	public function remember_version( $response, $hook_extra ) {
		if ( ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$this->previous_versions[ 'plugin:' . $hook_extra['plugin'] ] = $this->get_plugin_version( $hook_extra['plugin'] );
		} elseif ( ! empty( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) ) {
			$theme = wp_get_theme( $hook_extra['theme'] );

			if ( $theme->exists() ) {
				$this->previous_versions[ 'theme:' . $hook_extra['theme'] ] = $theme->get( 'Version' );
			}
		}

		return $response;
	}

	/**
	 * Log plugin, theme and translation updates and installations.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Hook data with "action" and "type".
	 * @return void
	 */
	public function log_upgrade( $upgrader, $options ) {
		$action = isset( $options['action'] ) ? $options['action'] : '';
		$type   = isset( $options['type'] ) ? $options['type'] : '';

		if ( 'install' === $action ) {
			$this->log_install( $upgrader, $type );
			return;
		}

		if ( 'update' !== $action ) {
			return;
		}

		if ( 'plugin' === $type ) {
			$this->log_plugin_updates( self::get_items( $options, 'plugin' ) );
		} elseif ( 'theme' === $type ) {
			$this->log_theme_updates( self::get_items( $options, 'theme' ) );
		} elseif ( 'translation' === $type ) {
			$this->log_translation_updates( isset( $options['translations'] ) ? (array) $options['translations'] : array() );
		}
	}

	/**
	 * Get the updated plugins or themes from the "upgrader_process_complete" data.
	 *
	 * Bulk updates pass a list ("plugins"/"themes"), single updates pass one
	 * item ("plugin"/"theme").
	 *
	 * @since 1.1.0
	 *
	 * @param array  $options Hook data.
	 * @param string $type    "plugin" or "theme".
	 * @return string[] Plugin files or theme stylesheets.
	 */
	public static function get_items( array $options, $type ) {
		$items = array();

		if ( isset( $options[ $type . 's' ] ) ) {
			$items = (array) $options[ $type . 's' ];
		} elseif ( isset( $options[ $type ] ) ) {
			$items = array( $options[ $type ] );
		}

		return array_values( array_filter( array_map( 'strval', $items ) ) );
	}

	/**
	 * Log a successful core update.
	 *
	 * @since 1.1.0
	 *
	 * @param string $new_version New WordPress version.
	 * @return void
	 */
	public function log_core_update( $new_version ) {
		$this->log->add(
			array(
				'event_type'  => 'core_updated',
				'object_type' => 'core',
				'object_name' => 'WordPress',
				'details'     => array(
					'old_version' => $this->previous_core_version,
					'new_version' => (string) $new_version,
				),
			)
		);
	}

	/**
	 * Log a plugin activation.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin Plugin file.
	 * @return void
	 */
	public function log_plugin_activated( $plugin ) {
		$this->log_plugin_event( 'plugin_activated', $plugin );
	}

	/**
	 * Log a plugin deactivation.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin Plugin file.
	 * @return void
	 */
	public function log_plugin_deactivated( $plugin ) {
		$this->log_plugin_event( 'plugin_deactivated', $plugin );
	}

	/**
	 * Remember the name of a plugin before it is deleted.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin Plugin file.
	 * @return void
	 */
	public function remember_plugin_name( $plugin ) {
		$this->deleted_names[ 'plugin:' . $plugin ] = $this->get_plugin_name( $plugin );
	}

	/**
	 * Log a deleted plugin.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin  Plugin file.
	 * @param bool   $deleted Whether the plugin was deleted.
	 * @return void
	 */
	public function log_plugin_deleted( $plugin, $deleted ) {
		if ( ! $deleted ) {
			return;
		}

		$key  = 'plugin:' . $plugin;
		$name = isset( $this->deleted_names[ $key ] ) ? $this->deleted_names[ $key ] : $plugin;

		$this->log->add(
			array(
				'event_type'  => 'plugin_deleted',
				'object_type' => 'plugin',
				'object_name' => $name,
				'details'     => array( 'file' => $plugin ),
			)
		);
	}

	/**
	 * Log a theme switch.
	 *
	 * @since 1.1.0
	 *
	 * @param string   $new_name  Name of the new theme.
	 * @param WP_Theme $new_theme New theme.
	 * @param WP_Theme $old_theme Previous theme.
	 * @return void
	 */
	public function log_theme_switched( $new_name, $new_theme, $old_theme ) {
		$this->log->add(
			array(
				'event_type'  => 'theme_switched',
				'object_type' => 'theme',
				'object_name' => $new_name,
				'details'     => array(
					'previous' => $old_theme instanceof WP_Theme ? $old_theme->get( 'Name' ) : '',
					'version'  => $new_theme instanceof WP_Theme ? $new_theme->get( 'Version' ) : '',
				),
			)
		);
	}

	/**
	 * Remember the name of a theme before it is deleted.
	 *
	 * @since 1.1.0
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return void
	 */
	public function remember_theme_name( $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );

		$this->deleted_names[ 'theme:' . $stylesheet ] = $theme->exists() ? $theme->get( 'Name' ) : $stylesheet;
	}

	/**
	 * Log a deleted theme.
	 *
	 * @since 1.1.0
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @param bool   $deleted    Whether the theme was deleted.
	 * @return void
	 */
	public function log_theme_deleted( $stylesheet, $deleted ) {
		if ( ! $deleted ) {
			return;
		}

		$key = 'theme:' . $stylesheet;

		$this->log->add(
			array(
				'event_type'  => 'theme_deleted',
				'object_type' => 'theme',
				'object_name' => isset( $this->deleted_names[ $key ] ) ? $this->deleted_names[ $key ] : $stylesheet,
			)
		);
	}

	/**
	 * Log published and trashed content.
	 *
	 * @since 1.1.0
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public function log_post_status( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! $this->is_tracked_post( $post ) ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			$event = 'post_published';
		} elseif ( 'trash' === $new_status ) {
			$event = 'post_trashed';
		} else {
			return;
		}

		$this->log->add( $this->get_post_entry( $event, $post ) );
	}

	/**
	 * Log edits of published content, aggregated to one entry per post and day.
	 *
	 * @since 1.1.0
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post after the update.
	 * @param WP_Post $post_before Post before the update.
	 * @return void
	 */
	public function log_post_updated( $post_id, $post_after, $post_before ) {
		if ( ! $this->is_tracked_post( $post_after ) ) {
			return;
		}

		// Only edits of content that was and stays published; status changes are logged separately.
		if ( 'publish' !== $post_after->post_status || 'publish' !== $post_before->post_status ) {
			return;
		}

		$start_of_day = ( new DateTimeImmutable( 'today', wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$existing_id  = $this->log->find_entry_since( 'post_updated', $post_after->post_type, $post_id, $start_of_day->format( 'Y-m-d H:i:s' ) );

		if ( $existing_id > 0 ) {
			$this->log->increment_count( $existing_id );
			return;
		}

		$this->log->add( $this->get_post_entry( 'post_updated', $post_after ) );
	}

	/**
	 * Log a new user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function log_user_created( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$this->log->add(
			array(
				'event_type'  => 'user_created',
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $user->user_login,
				'details'     => array( 'roles' => array_values( $user->roles ) ),
			)
		);
	}

	/**
	 * Log a deleted user.
	 *
	 * @since 1.1.0
	 *
	 * @param int          $user_id  User ID.
	 * @param int|null     $reassign ID of the user who received the content, if any.
	 * @param WP_User|null $user     Deleted user (WordPress 5.5+).
	 * @return void
	 */
	public function log_user_deleted( $user_id, $reassign = null, $user = null ) {
		unset( $reassign );

		$this->log->add(
			array(
				'event_type'  => 'user_deleted',
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $user instanceof WP_User ? $user->user_login : '#' . $user_id,
			)
		);
	}

	/**
	 * Log a changed user role. The initial role of new users is not logged.
	 *
	 * @since 1.1.0
	 *
	 * @param int      $user_id   User ID.
	 * @param string   $role      New role.
	 * @param string[] $old_roles Previous roles.
	 * @return void
	 */
	public function log_user_role_changed( $user_id, $role, $old_roles ) {
		if ( empty( $old_roles ) || in_array( $role, (array) $old_roles, true ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		$this->log->add(
			array(
				'event_type'  => 'user_role_changed',
				'object_type' => 'user',
				'object_id'   => $user_id,
				'object_name' => $user ? $user->user_login : '#' . $user_id,
				'details'     => array(
					'old_roles' => array_values( (array) $old_roles ),
					'new_role'  => (string) $role,
				),
			)
		);
	}

	/**
	 * Log a backup completed by UpdraftPlus.
	 *
	 * Hooked to the "updraftplus_backup_complete" filter, which UpdraftPlus
	 * applies when a backup finished without errors (verified in 1.26.16).
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $delete_jobdata Filtered value, passed through unchanged.
	 * @return mixed Unchanged value.
	 */
	public function log_updraftplus_backup( $delete_jobdata ) {
		global $updraftplus;

		$label = __( 'Backup by UpdraftPlus', 'admin-backend-and-update-helper' );

		if ( is_object( $updraftplus ) && method_exists( $updraftplus, 'jobdata_get' ) ) {
			$has_db    = self::is_updraftplus_part_finished( $updraftplus->jobdata_get( 'backup_database' ) );
			$has_files = self::is_updraftplus_part_finished( $updraftplus->jobdata_get( 'backup_files' ) );

			if ( $has_db && $has_files ) {
				$label = __( 'Database and files (UpdraftPlus)', 'admin-backend-and-update-helper' );
			} elseif ( $has_db ) {
				$label = __( 'Database (UpdraftPlus)', 'admin-backend-and-update-helper' );
			} elseif ( $has_files ) {
				$label = __( 'Files (UpdraftPlus)', 'admin-backend-and-update-helper' );
			}
		}

		$this->log->add(
			array(
				'event_type'  => 'backup_completed',
				'object_type' => 'backup',
				'object_name' => $label,
				'user_id'     => 0,
				'details'     => array( 'source' => 'updraftplus' ),
			)
		);

		return $delete_jobdata;
	}

	/**
	 * Check whether a part of an UpdraftPlus job finished.
	 *
	 * UpdraftPlus stores "finished" as a string for files and as an array per
	 * database (e.g. array( 'wp' => array( 'status' => 'finished' ) )).
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value Job data value.
	 * @return bool True if finished.
	 */
	private static function is_updraftplus_part_finished( $value ) {
		if ( is_string( $value ) ) {
			return 'finished' === $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $part ) {
				if ( 'finished' === $part || ( is_array( $part ) && isset( $part['status'] ) && 'finished' === $part['status'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Log plugin or theme installations.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param string      $type     "plugin" or "theme".
	 * @return void
	 */
	private function log_install( $upgrader, $type ) {
		if ( 'plugin' === $type && $upgrader instanceof Plugin_Upgrader ) {
			$plugin = $upgrader->plugin_info();

			if ( $plugin ) {
				$this->log->add(
					array(
						'event_type'  => 'plugin_installed',
						'object_type' => 'plugin',
						'object_name' => $this->get_plugin_name( $plugin ),
						'details'     => array( 'new_version' => $this->get_plugin_version( $plugin ) ),
					)
				);
			}
		} elseif ( 'theme' === $type && $upgrader instanceof Theme_Upgrader ) {
			$theme = $upgrader->theme_info();

			if ( $theme instanceof WP_Theme ) {
				$this->log->add(
					array(
						'event_type'  => 'theme_installed',
						'object_type' => 'theme',
						'object_name' => $theme->get( 'Name' ),
						'details'     => array( 'new_version' => $theme->get( 'Version' ) ),
					)
				);
			}
		}
	}

	/**
	 * Log updated plugins whose version changed.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $plugins Plugin files.
	 * @return void
	 */
	private function log_plugin_updates( array $plugins ) {
		foreach ( $plugins as $plugin ) {
			$key         = 'plugin:' . $plugin;
			$old_version = isset( $this->previous_versions[ $key ] ) ? $this->previous_versions[ $key ] : '';
			$new_version = $this->get_plugin_version( $plugin );

			if ( '' === $new_version || $old_version === $new_version ) {
				continue;
			}

			$this->log->add(
				array(
					'event_type'  => 'plugin_updated',
					'object_type' => 'plugin',
					'object_name' => $this->get_plugin_name( $plugin ),
					'details'     => array(
						'old_version' => $old_version,
						'new_version' => $new_version,
					),
				)
			);

			unset( $this->previous_versions[ $key ] );
		}
	}

	/**
	 * Log updated themes whose version changed.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $themes Theme stylesheets.
	 * @return void
	 */
	private function log_theme_updates( array $themes ) {
		foreach ( $themes as $stylesheet ) {
			$key   = 'theme:' . $stylesheet;
			$theme = wp_get_theme( $stylesheet );

			// The theme object may be cached from before the update.
			if ( method_exists( $theme, 'cache_delete' ) ) {
				$theme->cache_delete();
				$theme = wp_get_theme( $stylesheet );
			}

			$old_version = isset( $this->previous_versions[ $key ] ) ? $this->previous_versions[ $key ] : '';
			$new_version = $theme->exists() ? (string) $theme->get( 'Version' ) : '';

			if ( '' === $new_version || $old_version === $new_version ) {
				continue;
			}

			$this->log->add(
				array(
					'event_type'  => 'theme_updated',
					'object_type' => 'theme',
					'object_name' => $theme->get( 'Name' ),
					'details'     => array(
						'old_version' => $old_version,
						'new_version' => $new_version,
					),
				)
			);

			unset( $this->previous_versions[ $key ] );
		}
	}

	/**
	 * Log updated translations.
	 *
	 * @since 1.1.0
	 *
	 * @param array[] $translations Translation data with type, slug, language and version.
	 * @return void
	 */
	private function log_translation_updates( array $translations ) {
		foreach ( $translations as $translation ) {
			if ( ! is_array( $translation ) ) {
				continue;
			}

			$slug     = isset( $translation['slug'] ) ? (string) $translation['slug'] : '';
			$language = isset( $translation['language'] ) ? (string) $translation['language'] : '';
			$type     = isset( $translation['type'] ) ? (string) $translation['type'] : '';

			$this->log->add(
				array(
					'event_type'  => 'translation_updated',
					'object_type' => 'translation',
					'object_name' => trim( ( 'core' === $type ? 'WordPress' : $slug ) . ' (' . $language . ')' ),
					'details'     => array(
						'type'    => $type,
						'version' => isset( $translation['version'] ) ? (string) $translation['version'] : '',
					),
				)
			);
		}
	}

	/**
	 * Log an event for a plugin.
	 *
	 * @since 1.1.0
	 *
	 * @param string $event  Event type.
	 * @param string $plugin Plugin file.
	 * @return void
	 */
	private function log_plugin_event( $event, $plugin ) {
		$this->log->add(
			array(
				'event_type'  => $event,
				'object_type' => 'plugin',
				'object_name' => $this->get_plugin_name( $plugin ),
				'details'     => array( 'version' => $this->get_plugin_version( $plugin ) ),
			)
		);
	}

	/**
	 * Build a log entry for a post.
	 *
	 * @since 1.1.0
	 *
	 * @param string  $event Event type.
	 * @param WP_Post $post  Post.
	 * @return array Entry data.
	 */
	private function get_post_entry( $event, $post ) {
		$post_type = get_post_type_object( $post->post_type );
		$title     = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'admin-backend-and-update-helper' );

		return array(
			'event_type'  => $event,
			'object_type' => $post->post_type,
			'object_id'   => $post->ID,
			'object_name' => $title,
			'details'     => array(
				'post_type_label' => $post_type ? $post_type->labels->singular_name : $post->post_type,
				'count'           => 1,
			),
		);
	}

	/**
	 * Check whether changes to a post should be logged.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $post Post object.
	 * @return bool True for public post types, excluding attachments, revisions and autosaves.
	 */
	private function is_tracked_post( $post ) {
		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type ) {
			return false;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return false;
		}

		$post_type = get_post_type_object( $post->post_type );

		/**
		 * Filters whether changes to a post are written to the activity log.
		 *
		 * @since 1.1.0
		 *
		 * @param bool    $tracked Whether the post is tracked. Default: true for public post types.
		 * @param WP_Post $post    Post.
		 */
		return (bool) apply_filters( 'backhe_yis_track_post', $post_type && $post_type->public, $post );
	}

	/**
	 * Get the name of a plugin from its header.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin Plugin file.
	 * @return string Plugin name, or the file if unknown.
	 */
	private function get_plugin_name( $plugin ) {
		$data = $this->get_plugin_header( $plugin );

		return '' !== $data['Name'] ? $data['Name'] : $plugin;
	}

	/**
	 * Get the version of a plugin from its header.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin Plugin file.
	 * @return string Version, empty if unknown.
	 */
	private function get_plugin_version( $plugin ) {
		$data = $this->get_plugin_header( $plugin );

		return (string) $data['Version'];
	}

	/**
	 * Read the header of a plugin file.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin Plugin file relative to the plugins directory.
	 * @return array Header data with at least "Name" and "Version".
	 */
	private function get_plugin_header( $plugin ) {
		$file = WP_PLUGIN_DIR . '/' . $plugin;

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! file_exists( $file ) ) {
			return array(
				'Name'    => '',
				'Version' => '',
			);
		}

		return get_plugin_data( $file, false, false );
	}
}
