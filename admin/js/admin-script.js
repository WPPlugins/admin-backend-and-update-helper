/**
 * Admin scripts of the Admin Backend and Update Helper plugin.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.0.0
 */

/* global backheYisAdmin, wp */

( function ( $ ) {
	'use strict';

	const config = window.backheYisAdmin || {};
	const i18n = config.i18n || {};
	const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	/**
	 * Show a dismissible WordPress notice and announce it to screen readers.
	 *
	 * @since 1.1.0
	 *
	 * @param {string} message Message text.
	 * @param {string} type    Notice type: success, error, warning or info.
	 */
	function showNotice( message, type ) {
		const $container = $( '#backhe-yis-notices' );
		const $notice = $( '<div class="notice is-dismissible"><p></p></div>' );

		$notice.addClass( 'notice-' + ( type || 'info' ) );
		$notice.find( 'p' ).text( message );

		if ( $container.length ) {
			$container.empty().append( $notice );
		} else {
			$( '.backhe-yis-admin h1' ).first().after( $notice );
		}

		$( document ).trigger( 'wp-updates-notice-added' );

		if ( wp && wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message, 'error' === type ? 'assertive' : 'polite' );
		}
	}

	/**
	 * Get the error message of a failed AJAX response.
	 *
	 * @since 1.1.0
	 *
	 * @param {Object} response JSON response.
	 * @return {string} Message.
	 */
	function errorMessage( response ) {
		return response && response.data && response.data.message ? response.data.message : i18n.requestFailed;
	}

	/**
	 * Put a button into the busy state, or restore it.
	 *
	 * @since 1.1.0
	 *
	 * @param {jQuery}  $button Button.
	 * @param {boolean} busy    Whether the button is busy.
	 * @param {string}  label   Label while busy.
	 */
	function setBusy( $button, busy, label ) {
		if ( busy ) {
			$button.data( 'backhe-label', $button.html() );
			$button.prop( 'disabled', true ).attr( 'aria-busy', 'true' ).text( label );
		} else {
			$button.prop( 'disabled', false ).removeAttr( 'aria-busy' ).html( $button.data( 'backhe-label' ) );
		}
	}

	/**
	 * Post an AJAX request.
	 *
	 * @since 1.1.0
	 *
	 * @param {Object} data Request data.
	 * @return {jQuery.jqXHR} Request.
	 */
	function post( data ) {
		return $.post( config.ajaxUrl, data );
	}

	/* Notifications tab: show the fields that belong to the chosen options. */

	function updateRecipientFields() {
		const type = $( 'input[name="backhe_yis_settings[recipient_type]"]:checked' ).val();

		$( '#user-select-wrapper' ).prop( 'hidden', 'user' !== type );
		$( '#custom-email-wrapper' ).prop( 'hidden', 'custom' !== type );
	}

	function updateFrequencyFields() {
		$( '#custom-days-wrapper' ).prop( 'hidden', 'custom' !== $( '#notification-frequency' ).val() );
	}

	/* Updates tab. */

	function manualCheck() {
		const $button = $( this );

		setBusy( $button, true, i18n.checking );

		post( {
			action: 'backhe_yis_manual_check',
			nonce: config.nonceManualCheck,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					showNotice( response.data.message, 'success' );
					$( '#last-check-time' ).text( response.data.timestamp );
					window.setTimeout( function () {
						window.location.reload();
					}, 1500 );
				} else {
					showNotice( errorMessage( response ), 'error' );
				}
			} )
			.fail( function () {
				showNotice( i18n.requestFailed, 'error' );
			} )
			.always( function () {
				setBusy( $button, false );
			} );
	}

	function installUpdate() {
		const $button = $( this );

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( i18n.confirmUpdate ) ) {
			return;
		}

		setBusy( $button, true, i18n.updating );

		post( {
			action: 'backhe_yis_update_single',
			nonce: config.nonceUpdate,
			type: $button.data( 'type' ),
			slug: $button.data( 'slug' ),
		} )
			.done( function ( response ) {
				if ( response.success ) {
					showNotice( response.data.message, 'success' );
					window.setTimeout( function () {
						window.location.reload();
					}, 1500 );
				} else {
					showNotice( errorMessage( response ), 'error' );
					setBusy( $button, false );
				}
			} )
			.fail( function () {
				showNotice( i18n.requestFailed, 'error' );
				setBusy( $button, false );
			} );
	}

	function installAllUpdates() {
		const $button = $( this );

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( i18n.confirmUpdateAll ) ) {
			return;
		}

		setBusy( $button, true, i18n.updating );

		post( {
			action: 'backhe_yis_update_all',
			nonce: config.nonceUpdate,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					showNotice( response.data.message, 'success' );
					window.setTimeout( function () {
						window.location.reload();
					}, 2000 );
				} else {
					showNotice( errorMessage( response ), 'error' );
					setBusy( $button, false );
				}
			} )
			.fail( function () {
				showNotice( i18n.requestFailed, 'error' );
				setBusy( $button, false );
			} );
	}

	/* Notifications tab: test email and validation. */

	function getTestRecipients() {
		const type = $( 'input[name="backhe_yis_settings[recipient_type]"]:checked' ).val();

		if ( 'user' === type ) {
			return [ $( '#backhe-selected-user option:selected' ).data( 'email' ) || '', '' ];
		}

		if ( 'custom' === type ) {
			return [ $( '#backhe-custom-email' ).val(), $( '#backhe-custom-email-2' ).val() ];
		}

		return [ config.adminEmail, '' ];
	}

	function sendTestEmail() {
		const $button = $( this );
		const recipients = getTestRecipients();

		if ( ! recipients[ 0 ] ) {
			showNotice( i18n.enterEmail, 'error' );
			return;
		}

		if ( ! emailPattern.test( recipients[ 0 ] ) ) {
			showNotice( i18n.invalidEmail, 'error' );
			return;
		}

		if ( recipients[ 1 ] && ! emailPattern.test( recipients[ 1 ] ) ) {
			showNotice( i18n.invalidEmail2, 'error' );
			return;
		}

		setBusy( $button, true, i18n.sending );

		post( {
			action: 'backhe_yis_send_test_email',
			nonce: config.nonceTestEmail,
			recipient: recipients[ 0 ],
			recipient_2: recipients[ 1 ],
		} )
			.done( function ( response ) {
				showNotice( response.success ? response.data.message : errorMessage( response ), response.success ? 'success' : 'error' );
			} )
			.fail( function () {
				showNotice( i18n.requestFailed, 'error' );
			} )
			.always( function () {
				setBusy( $button, false );
			} );
	}

	function validateSettings( event ) {
		const emailEnabled = $( '#backhe-email-enabled' ).is( ':checked' ) || $( '#backhe-notify-on-updates' ).is( ':checked' );
		const type = $( 'input[name="backhe_yis_settings[recipient_type]"]:checked' ).val();
		let message = '';
		let $field = null;

		if ( emailEnabled && 'custom' === type ) {
			const first = $( '#backhe-custom-email' ).val();
			const second = $( '#backhe-custom-email-2' ).val();

			if ( ! first || ! emailPattern.test( first ) ) {
				message = i18n.invalidEmail;
				$field = $( '#backhe-custom-email' );
			} else if ( second && ! emailPattern.test( second ) ) {
				message = i18n.invalidEmail2;
				$field = $( '#backhe-custom-email-2' );
			}
		}

		if ( ! message && 'custom' === $( '#notification-frequency' ).val() ) {
			const days = parseInt( $( '#backhe-custom-days' ).val(), 10 );

			if ( ! days || days < 1 || days > 365 ) {
				message = i18n.invalidDays;
				$field = $( '#backhe-custom-days' );
			}
		}

		if ( message ) {
			event.preventDefault();
			showNotice( message, 'error' );
			$field.attr( 'aria-invalid', 'true' ).trigger( 'focus' );
		}
	}

	/* Backups page. */

	const backup = {
		running: false,

		start() {
			const $button = $( '#backhe-backup-start' );

			$button.prop( 'disabled', true );
			backup.setStatus( 0, i18n.backupStarting );

			post( {
				action: 'backhe_yis_backup_start',
				nonce: config.nonceBackup,
				scope: $( '#backhe-backup-scope' ).val(),
			} )
				.done( function ( response ) {
					if ( response.success ) {
						backup.handle( response.data );
					} else {
						backup.finish( errorMessage( response ), 'error' );
					}
				} )
				.fail( function () {
					backup.finish( i18n.requestFailed, 'error' );
				} );
		},

		step() {
			post( {
				action: 'backhe_yis_backup_step',
				nonce: config.nonceBackup,
			} )
				.done( function ( response ) {
					if ( response.success ) {
						backup.handle( response.data );
					} else {
						backup.finish( errorMessage( response ), 'error' );
					}
				} )
				.fail( function () {
					// A step may hit a server time limit; the job resumes on the next request.
					window.setTimeout( backup.step, 5000 );
				} );
		},

		cancel() {
			post( {
				action: 'backhe_yis_backup_cancel',
				nonce: config.nonceBackup,
			} ).always( function () {
				backup.finish( i18n.backupCancelled, 'warning' );
			} );
		},

		handle( job ) {
			backup.setStatus( job.progress, job.message );

			if ( 'running' === job.status ) {
				backup.running = true;
				$( '#backhe-backup-cancel' ).prop( 'hidden', false );
				window.setTimeout( backup.step, 300 );
			} else if ( 'done' === job.status ) {
				backup.finish( i18n.backupDone, 'success', true );
			} else if ( 'failed' === job.status ) {
				backup.finish( i18n.backupFailed + ( job.error ? ' ' + job.error : '' ), 'error' );
			} else {
				backup.finish( job.message, 'warning' );
			}
		},

		setStatus( progress, message ) {
			$( '#backhe-backup-progress' ).prop( 'hidden', false );
			$( '#backhe-backup-progress-bar' ).val( progress );
			$( '#backhe-backup-status' ).text( message );
		},

		finish( message, type, reload ) {
			backup.running = false;
			$( '#backhe-backup-start' ).prop( 'disabled', false );
			$( '#backhe-backup-cancel' ).prop( 'hidden', true );
			$( '#backhe-backup-status' ).text( message );
			showNotice( message, type );

			if ( reload ) {
				window.setTimeout( function () {
					window.location.reload();
				}, 1500 );
			}
		},
	};

	$( function () {
		// Overview page.
		updateRecipientFields();
		updateFrequencyFields();
		$( 'input[name="backhe_yis_settings[recipient_type]"]' ).on( 'change', updateRecipientFields );
		$( '#notification-frequency' ).on( 'change', updateFrequencyFields );
		$( '#backhe-manual-check-btn' ).on( 'click', manualCheck );
		$( '.backhe-update-single-btn' ).on( 'click', installUpdate );
		$( '#backhe-update-all-btn' ).on( 'click', installAllUpdates );
		$( '#backhe-test-email-btn' ).on( 'click', sendTestEmail );
		$( '#backhe-settings-form' ).on( 'submit', validateSettings );

		// Activity log and backups: confirm deletions.
		$( '.backhe-delete-entry' ).on( 'click', function ( event ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( i18n.confirmEntryDel ) ) {
				event.preventDefault();
			}
		} );

		$( '.backhe-delete-backup-form' ).on( 'submit', function ( event ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( i18n.confirmBackupDel ) ) {
				event.preventDefault();
			}
		} );

		// Backups page.
		$( '#backhe-backup-start' ).on( 'click', backup.start );
		$( '#backhe-backup-cancel' ).on( 'click', backup.cancel );

		if ( '1' === String( $( '#backhe-backup-progress' ).data( 'running' ) ) ) {
			backup.running = true;
			backup.step();
		}

		$( window ).on( 'beforeunload', function ( event ) {
			if ( backup.running ) {
				event.preventDefault();
				event.returnValue = i18n.backupLeaveWarn;
				return i18n.backupLeaveWarn;
			}
		} );
	} );
}( jQuery ) );
