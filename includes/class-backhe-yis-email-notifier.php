<?php
/**
 * Email notifier.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and sends the plugin's notification emails.
 *
 * @since 1.0.0
 * @since 1.1.0 Shared HTML layout, English source strings.
 */
class Backhe_YIS_Email_Notifier {

	/**
	 * Send the "updates available" notification.
	 *
	 * @since 1.0.0
	 *
	 * @param array $updates Updates from Backhe_YIS_Update_Checker::check_all_updates().
	 * @return bool True if all emails were sent, false otherwise.
	 */
	public function send_notification( $updates ) {
		$settings = get_option( 'backhe_yis_settings', array() );

		if ( empty( $settings['email_enabled'] ) || '1' !== $settings['email_enabled'] ) {
			return false;
		}

		if ( 0 === $this->count_updates( $updates ) ) {
			return false;
		}

		return $this->send_html(
			$this->get_recipient_emails( $settings ),
			$this->get_email_subject( $updates ),
			$this->get_email_message( $updates )
		);
	}

	/**
	 * Send the "updates installed" notification.
	 *
	 * @since 1.0.0
	 *
	 * @param array $options Hook data from "upgrader_process_complete".
	 * @return bool True if all emails were sent, false otherwise.
	 */
	public function send_update_completion_notification( $options ) {
		$settings = get_option( 'backhe_yis_settings', array() );

		if ( empty( $settings['email_enabled'] ) || '1' !== $settings['email_enabled'] ) {
			return false;
		}

		$type         = isset( $options['type'] ) ? $options['type'] : '';
		$updates_info = array();

		if ( 'plugin' === $type ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			foreach ( Backhe_YIS_Event_Tracker::get_items( (array) $options, 'plugin' ) as $plugin ) {
				$file = WP_PLUGIN_DIR . '/' . $plugin;

				if ( ! file_exists( $file ) ) {
					continue;
				}

				$plugin_data    = get_plugin_data( $file, false );
				$updates_info[] = array( __( 'Plugin', 'admin-backend-and-update-helper' ), $plugin_data['Name'], $plugin_data['Version'] );
			}
		} elseif ( 'theme' === $type ) {
			foreach ( Backhe_YIS_Event_Tracker::get_items( (array) $options, 'theme' ) as $theme ) {
				$theme_data     = wp_get_theme( $theme );
				$updates_info[] = array( __( 'Theme', 'admin-backend-and-update-helper' ), $theme_data->get( 'Name' ), $theme_data->get( 'Version' ) );
			}
		} elseif ( 'core' === $type ) {
			$updates_info[] = array( __( 'WordPress core', 'admin-backend-and-update-helper' ), 'WordPress', get_bloginfo( 'version' ) );
		}

		if ( empty( $updates_info ) ) {
			return false;
		}

		$count   = count( $updates_info );
		$content = '<p>' . esc_html(
			sprintf(
				/* translators: %d: Number of installed updates. */
				_n( '%d update was installed:', '%d updates were installed:', $count, 'admin-backend-and-update-helper' ),
				$count
			)
		) . '</p>';

		$content .= $this->render_table(
			array(
				__( 'Type', 'admin-backend-and-update-helper' ),
				__( 'Name', 'admin-backend-and-update-helper' ),
				__( 'Version', 'admin-backend-and-update-helper' ),
			),
			$updates_info
		);

		return $this->send_html(
			$this->get_recipient_emails( $settings ),
			'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . __( 'Updates installed', 'admin-backend-and-update-helper' ),
			$this->render_layout( __( 'Updates installed', 'admin-backend-and-update-helper' ), $content )
		);
	}

	/**
	 * Get the recipient addresses from the settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Plugin settings.
	 * @return string[] Email addresses.
	 */
	public function get_recipient_emails( $settings ) {
		$recipients     = array();
		$recipient_type = isset( $settings['recipient_type'] ) ? $settings['recipient_type'] : 'admin';

		switch ( $recipient_type ) {
			case 'user':
				$user_id = isset( $settings['selected_user'] ) ? absint( $settings['selected_user'] ) : 0;
				$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

				if ( $user ) {
					$recipients[] = $user->user_email;
				}
				break;

			case 'custom':
				foreach ( array( 'custom_email', 'custom_email_2' ) as $key ) {
					$email = isset( $settings[ $key ] ) ? sanitize_email( $settings[ $key ] ) : '';

					if ( is_email( $email ) ) {
						$recipients[] = $email;
					}
				}
				break;

			case 'admin':
			default:
				$recipients[] = get_option( 'admin_email' );
				break;
		}

		return array_values( array_filter( $recipients ) );
	}

	/**
	 * Build the subject of the "updates available" email.
	 *
	 * @since 1.0.0
	 *
	 * @param array $updates Updates from Backhe_YIS_Update_Checker::check_all_updates().
	 * @return string Email subject.
	 */
	public function get_email_subject( $updates ) {
		$count = $this->count_updates( $updates );

		return '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . sprintf(
			/* translators: %d: Number of available updates. */
			_n( '%d update available', '%d updates available', $count, 'admin-backend-and-update-helper' ),
			$count
		);
	}

	/**
	 * Build the HTML body of the "updates available" email.
	 *
	 * @since 1.0.0
	 *
	 * @param array $updates Updates from Backhe_YIS_Update_Checker::check_all_updates().
	 * @return string Email HTML.
	 */
	public function get_email_message( $updates ) {
		$count   = $this->count_updates( $updates );
		$content = '<p>' . esc_html(
			sprintf(
				/* translators: %d: Number of available updates. */
				_n( 'There is %d update available for your website.', 'There are %d updates available for your website.', $count, 'admin-backend-and-update-helper' ),
				$count
			)
		) . '</p>';

		$headers = array(
			__( 'Name', 'admin-backend-and-update-helper' ),
			__( 'Installed', 'admin-backend-and-update-helper' ),
			__( 'Available', 'admin-backend-and-update-helper' ),
		);

		$sections = array(
			'core'    => __( 'WordPress core', 'admin-backend-and-update-helper' ),
			'plugins' => __( 'Plugins', 'admin-backend-and-update-helper' ),
			'themes'  => __( 'Themes', 'admin-backend-and-update-helper' ),
		);

		foreach ( $sections as $key => $title ) {
			if ( empty( $updates[ $key ] ) ) {
				continue;
			}

			$rows = array();

			foreach ( $updates[ $key ] as $item ) {
				$rows[] = array(
					isset( $item['name'] ) ? $item['name'] : 'WordPress',
					$item['current_version'],
					$item['new_version'],
				);
			}

			$content .= $this->render_heading( $title . ' (' . count( $rows ) . ')' );
			$content .= $this->render_table( $headers, $rows );
		}

		if ( ! empty( $updates['translations'] ) ) {
			$translation_count = count( $updates['translations'] );

			$content .= $this->render_heading( __( 'Translations', 'admin-backend-and-update-helper' ) );
			$content .= '<p>' . esc_html(
				sprintf(
					/* translators: %d: Number of translation updates. */
					_n( '%d translation update available.', '%d translation updates available.', $translation_count, 'admin-backend-and-update-helper' ),
					$translation_count
				)
			) . '</p>';
		}

		$content .= $this->render_button( admin_url( 'update-core.php' ), __( 'Install updates now', 'admin-backend-and-update-helper' ) );

		return $this->render_layout( __( 'Updates available', 'admin-backend-and-update-helper' ), $content );
	}

	/**
	 * Send a test email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $recipient   First recipient address.
	 * @param string $recipient_2 Optional second recipient address.
	 * @return bool True if all emails were sent, false otherwise.
	 */
	public function send_test_email( $recipient, $recipient_2 = '' ) {
		$recipients = array_values( array_filter( array( $recipient, $recipient_2 ) ) );

		if ( empty( $recipients ) ) {
			return false;
		}

		$content  = '<p>' . esc_html__( 'This is a test email from the Admin Backend and Update Helper plugin. If you can read it, email notifications work.', 'admin-backend-and-update-helper' ) . '</p>';
		$content .= $this->render_table(
			array(
				__( 'Setting', 'admin-backend-and-update-helper' ),
				__( 'Value', 'admin-backend-and-update-helper' ),
			),
			array(
				array( __( 'Recipients', 'admin-backend-and-update-helper' ), implode( ', ', $recipients ) ),
				array( __( 'Sender', 'admin-backend-and-update-helper' ), get_option( 'admin_email' ) ),
			)
		);

		return $this->send_html(
			$recipients,
			'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . __( 'Test email', 'admin-backend-and-update-helper' ),
			$this->render_layout( __( 'Test email', 'admin-backend-and-update-helper' ), $content )
		);
	}

	/**
	 * Send an HTML email to several recipients.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $recipients Email addresses.
	 * @param string   $subject    Subject (plain text).
	 * @param string   $html       HTML body.
	 * @return bool True if the email was sent to all recipients.
	 */
	public function send_html( array $recipients, $subject, $html ) {
		$recipients = array_values( array_filter( $recipients, 'is_email' ) );

		if ( empty( $recipients ) ) {
			return false;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$success = true;

		foreach ( $recipients as $recipient ) {
			if ( ! wp_mail( $recipient, $subject, $html, $headers ) ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Wrap content in the email layout.
	 *
	 * @since 1.1.0
	 *
	 * @param string $title    Title shown in the header (plain text).
	 * @param string $content  Body HTML; must already be escaped.
	 * @param string $subtitle Optional subtitle below the title (plain text); defaults to the site name.
	 * @return string Complete HTML document.
	 */
	public function render_layout( $title, $content, $subtitle = '' ) {
		$site_name = get_bloginfo( 'name' );
		$subtitle  = '' !== $subtitle ? $subtitle : $site_name;
		$language  = get_bloginfo( 'language' );

		$footer = sprintf(
			/* translators: 1: Plugin name, 2: Date and time. */
			__( 'Sent by %1$s on %2$s', 'admin-backend-and-update-helper' ),
			'Admin Backend and Update Helper',
			date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
		);

		return '<!DOCTYPE html>
<html lang="' . esc_attr( $language ) . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . esc_html( $title ) . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1d2327;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f0f1;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background-color:#ffffff;border-top:4px solid #2271b1;">
<tr><td style="padding:28px 32px 8px;">
<h1 style="margin:0;font-size:24px;line-height:1.3;color:#1d2327;">' . esc_html( $title ) . '</h1>
<p style="margin:6px 0 0;font-size:15px;color:#50575e;">' . esc_html( $subtitle ) . '</p>
</td></tr>
<tr><td style="padding:16px 32px 32px;font-size:15px;line-height:1.6;">' . $content . '</td></tr>
<tr><td style="padding:20px 32px;background-color:#f6f7f7;font-size:13px;color:#50575e;">
<p style="margin:0;">' . esc_html( $footer ) . '</p>
<p style="margin:4px 0 0;"><a href="' . esc_url( home_url( '/' ) ) . '" style="color:#2271b1;">' . esc_html( $site_name ) . '</a></p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
	}

	/**
	 * Render a data table for an email.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $headers Column headers (plain text).
	 * @param array[]  $rows    Rows of plain-text cells.
	 * @return string Table HTML.
	 */
	public function render_table( array $headers, array $rows ) {
		$html = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0 20px;font-size:14px;"><thead><tr>';

		foreach ( $headers as $header ) {
			$html .= '<th scope="col" style="text-align:left;padding:8px 10px;border-bottom:2px solid #c3c4c7;color:#1d2327;">' . esc_html( $header ) . '</th>';
		}

		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$html .= '<tr>';

			foreach ( $row as $cell ) {
				$html .= '<td style="padding:8px 10px;border-bottom:1px solid #dcdcde;vertical-align:top;">' . esc_html( (string) $cell ) . '</td>';
			}

			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * Render a section heading for an email.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text Heading text (plain text).
	 * @return string Heading HTML.
	 */
	public function render_heading( $text ) {
		return '<h2 style="margin:24px 0 4px;font-size:18px;color:#1d2327;">' . esc_html( $text ) . '</h2>';
	}

	/**
	 * Render a call-to-action link styled as a button.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url  Link target.
	 * @param string $text Link text (plain text).
	 * @return string Link HTML.
	 */
	public function render_button( $url, $text ) {
		return '<p style="margin:28px 0 8px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background-color:#2271b1;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:3px;font-weight:600;">' . esc_html( $text ) . '</a></p>';
	}

	/**
	 * Count all updates.
	 *
	 * @since 1.1.0
	 *
	 * @param array $updates Updates from Backhe_YIS_Update_Checker::check_all_updates().
	 * @return int Number of updates.
	 */
	private function count_updates( $updates ) {
		$count = 0;

		foreach ( array( 'core', 'plugins', 'themes', 'translations' ) as $key ) {
			if ( isset( $updates[ $key ] ) && is_array( $updates[ $key ] ) ) {
				$count += count( $updates[ $key ] );
			}
		}

		return $count;
	}
}
