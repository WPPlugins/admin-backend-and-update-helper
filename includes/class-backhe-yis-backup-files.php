<?php
/**
 * File backup.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archives the wp-content directory into ZIP parts in resumable steps.
 *
 * Phase 1 walks the directory tree (with a persisted stack, so it can be
 * resumed) and writes the file list to a text file. Phase 2 adds the listed
 * files to ZIP archives of about PART_BYTES each; every step closes the
 * archive, so a step never rewrites more than one part.
 *
 * Reading the file list and directory tree requires direct file access.
 *
 * Exception messages are caught internally, stored and escaped on output.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped
 *
 * @since 1.1.0
 */
class Backhe_YIS_Backup_Files {

	/**
	 * Approximate uncompressed size of one ZIP part.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const PART_BYTES = 104857600;

	/**
	 * Maximum uncompressed bytes added per step, so closing the archive stays fast.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const STEP_BYTES = 52428800;

	/**
	 * Maximum number of files added per step.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const STEP_FILES = 2000;

	/**
	 * Prepare the file part of a job.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $job Job.
	 * @param string $dir Backup directory.
	 * @return array Job with the "fs" state.
	 */
	public static function init( array $job, $dir ) {
		$job['fs'] = array(
			'list_file'  => $job['id'] . '-files.txt',
			'stack'      => array( '' ),
			'excludes'   => self::get_excludes( $dir ),
			'count'      => 0,
			'bytes'      => 0,
			'scan_done'  => false,
			'position'   => 0,
			'zipped'     => 0,
			'part'       => 1,
			'part_bytes' => 0,
			'zip_done'   => false,
		);

		return $job;
	}

	/**
	 * Walk the directory tree and write the file list.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $job      Job.
	 * @param string $dir      Backup directory.
	 * @param float  $deadline Microtime until which work may continue.
	 * @return array Updated job.
	 * @throws RuntimeException If the file list cannot be written.
	 */
	public static function scan( array $job, $dir, $deadline ) {
		$state  = $job['fs'];
		$root   = untrailingslashit( WP_CONTENT_DIR );
		$handle = fopen( $dir . '/' . $state['list_file'], 'ab' );

		if ( false === $handle ) {
			throw new RuntimeException( __( 'The file list for the backup could not be written.', 'admin-backend-and-update-helper' ) );
		}

		while ( ! empty( $state['stack'] ) && microtime( true ) < $deadline ) {
			$relative = array_pop( $state['stack'] );
			$absolute = '' === $relative ? $root : $root . '/' . $relative;
			$entries  = is_readable( $absolute ) ? scandir( $absolute ) : false;

			if ( false === $entries ) {
				continue;
			}

			$lines = '';

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$entry_relative = '' === $relative ? $entry : $relative . '/' . $entry;
				$entry_absolute = $root . '/' . $entry_relative;

				// Symlinks may point outside wp-content or create loops.
				if ( is_link( $entry_absolute ) || in_array( $entry_relative, $state['excludes'], true ) ) {
					continue;
				}

				if ( is_dir( $entry_absolute ) ) {
					$state['stack'][] = $entry_relative;
				} elseif ( is_file( $entry_absolute ) && is_readable( $entry_absolute ) && false === strpos( $entry_relative, "\n" ) ) {
					$lines .= $entry_relative . "\n";
					++$state['count'];
					$state['bytes'] += (int) filesize( $entry_absolute );
				}
			}

			if ( '' !== $lines && false === fwrite( $handle, $lines ) ) {
				fclose( $handle );
				throw new RuntimeException( __( 'The file list for the backup could not be written. The disk may be full.', 'admin-backend-and-update-helper' ) );
			}
		}

		fclose( $handle );

		if ( empty( $state['stack'] ) ) {
			$state['scan_done'] = true;
		}

		$job['fs'] = $state;

		return $job;
	}

	/**
	 * Add listed files to the ZIP parts.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $job      Job.
	 * @param string $dir      Backup directory.
	 * @param float  $deadline Microtime until which work may continue.
	 * @return array Updated job.
	 * @throws RuntimeException If an archive cannot be written.
	 */
	public static function zip( array $job, $dir, $deadline ) {
		$state  = $job['fs'];
		$root   = untrailingslashit( WP_CONTENT_DIR );
		$handle = fopen( $dir . '/' . $state['list_file'], 'rb' );

		if ( false === $handle || 0 !== fseek( $handle, (int) $state['position'] ) ) {
			throw new RuntimeException( __( 'The file list for the backup could not be read.', 'admin-backend-and-update-helper' ) );
		}

		$zip         = self::open_part( $job, $dir, $state['part'] );
		$step_bytes  = 0;
		$step_files  = 0;
		$added_parts = array( $state['part'] );

		while ( microtime( true ) < $deadline && $step_bytes < self::STEP_BYTES && $step_files < self::STEP_FILES ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				$state['zip_done'] = true;
				break;
			}

			$relative = rtrim( $line, "\n" );
			$absolute = $root . '/' . $relative;

			$state['position'] = ftell( $handle );
			++$state['zipped'];

			// Files deleted since the scan are skipped.
			if ( '' === $relative || ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
				continue;
			}

			$size = (int) filesize( $absolute );

			// Start a new part when this one is full.
			if ( $state['part_bytes'] > 0 && $state['part_bytes'] + $size > self::PART_BYTES ) {
				self::close_part( $zip );
				++$state['part'];
				$state['part_bytes'] = 0;
				$zip                 = self::open_part( $job, $dir, $state['part'] );
				$added_parts[]       = $state['part'];
			}

			if ( ! $zip->addFile( $absolute, 'wp-content/' . $relative ) ) {
				fclose( $handle );
				throw new RuntimeException(
					sprintf(
						/* translators: %s: File path. */
						__( 'The file %s could not be added to the backup.', 'admin-backend-and-update-helper' ),
						'wp-content/' . $relative
					)
				);
			}

			$state['part_bytes'] += $size;
			$step_bytes          += $size;
			++$step_files;
		}

		fclose( $handle );
		self::close_part( $zip );

		foreach ( $added_parts as $part ) {
			$name = self::get_part_name( $job, $part );

			if ( ! in_array( $name, wp_list_pluck( $job['files'], 'name' ), true ) && file_exists( $dir . '/' . $name ) ) {
				$job['files'][] = array(
					'name' => $name,
					'type' => 'files',
				);
			}
		}

		$job['fs'] = $state;

		return $job;
	}

	/**
	 * Remove the temporary file list.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $job Job.
	 * @param string $dir Backup directory.
	 * @return void
	 */
	public static function cleanup( array $job, $dir ) {
		if ( ! empty( $job['fs']['list_file'] ) ) {
			wp_delete_file( $dir . '/' . $job['fs']['list_file'] );
		}
	}

	/**
	 * Get the paths relative to wp-content that are not backed up.
	 *
	 * @since 1.1.0
	 *
	 * @param string $dir Backup directory.
	 * @return string[] Relative paths.
	 */
	private static function get_excludes( $dir ) {
		$root     = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$excludes = array(
			'cache',
			'upgrade',
			'upgrade-temp-backup',
			'updraft',
			'ai1wm-backups',
			'backups-dup-lite',
			'backups-dup-pro',
			'wpvividbackups',
			'et-cache',
			'litespeed',
			'debug.log',
		);

		$backup_dir = wp_normalize_path( $dir );

		if ( 0 === strpos( $backup_dir, $root ) ) {
			$excludes[] = substr( $backup_dir, strlen( $root ) );
		}

		/**
		 * Filters the paths relative to wp-content that are excluded from file backups.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $excludes Relative paths, e.g. "cache" or "uploads/my-big-folder".
		 */
		$excludes = apply_filters( 'backhe_yis_backup_excludes', $excludes );

		return array_values( array_unique( array_map( 'strval', (array) $excludes ) ) );
	}

	/**
	 * Open a ZIP part for appending.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $job  Job.
	 * @param string $dir  Backup directory.
	 * @param int    $part Part number.
	 * @return ZipArchive Archive.
	 * @throws RuntimeException If the archive cannot be opened.
	 */
	private static function open_part( array $job, $dir, $part ) {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $dir . '/' . self::get_part_name( $job, $part ), ZipArchive::CREATE ) ) {
			throw new RuntimeException( __( 'The backup archive could not be created.', 'admin-backend-and-update-helper' ) );
		}

		return $zip;
	}

	/**
	 * Close a ZIP part, which writes the added files.
	 *
	 * @since 1.1.0
	 *
	 * @param ZipArchive $zip Archive.
	 * @return void
	 * @throws RuntimeException If the archive cannot be written.
	 */
	private static function close_part( ZipArchive $zip ) {
		// An archive without entries is not written at all; that is fine.
		if ( 0 === $zip->numFiles ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			$zip->close();
			return;
		}

		if ( ! $zip->close() ) {
			throw new RuntimeException( __( 'The backup archive could not be written. The disk may be full or a file changed during the backup.', 'admin-backend-and-update-helper' ) );
		}
	}

	/**
	 * Get the file name of a ZIP part.
	 *
	 * @since 1.1.0
	 *
	 * @param array $job  Job.
	 * @param int   $part Part number.
	 * @return string File name.
	 */
	private static function get_part_name( array $job, $part ) {
		return $job['id'] . '-files-' . (int) $part . '.zip';
	}
}
