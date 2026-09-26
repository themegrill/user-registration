<?php
/**
 * Debug/Status page
 *
 * @package     UserRegistration/Admin/System Status
 * @version     1.0.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Admin_Status Class.
 */
class UR_Admin_Status {

	/**
	 * Handles output of the reports page in admin.
	 */
	public static function output() {
		include_once __DIR__ . '/views/html-admin-page-status.php';
	}


	/**
	 * Show the logs page.
	 */
	public static function status_logs() {
		self::status_logs_file();
	}


	/**
	 * Show the log page contents for file log handler.
	 */
	public static function status_logs_file() {
		include_once __DIR__ . '/class-ur-log-list-table.php';

		if ( ! empty( $_REQUEST['handle'] ) ) {
			self::remove_log();
		}
		if ( ! empty( $_REQUEST['handle_all'] ) ) {
			self::remove_all_logs();
		}
		if ( 'delete' === self::get_bulk_action() && ! empty( $_REQUEST['sources'] ) ) {
			self::remove_selected_logs();
		}

		$sources = UR_Log_List_Table::scan_sources( true );

		$viewed_handle = '';
		$viewed_file   = '';

		$requested_handle = isset( $_REQUEST['log'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$requested_file   = isset( $_REQUEST['log_file'] ) ? sanitize_title( wp_unslash( $_REQUEST['log_file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		foreach ( $sources as $source_handle => $source ) {
			foreach ( $source['files'] as $file ) {
				if ( '' !== $requested_file && sanitize_title( $file['filename'] ) === $requested_file ) {
					$viewed_handle = $source_handle;
					$viewed_file   = $file['filename'];
					break 2;
				}
			}
		}

		if ( '' === $viewed_handle && '' !== $requested_handle && isset( $sources[ $requested_handle ] ) ) {
			$viewed_handle = $requested_handle;
		}

		if ( '' === $viewed_handle && 1 === count( $sources ) ) {
			$viewed_handle = (string) key( $sources );
		}

		if ( '' !== $viewed_handle && '' === $viewed_file ) {
			$viewed_file = $sources[ $viewed_handle ]['files'][0]['filename'];
		}

		include_once 'views/html-admin-page-status-logs.php';
	}

	/**
	 * Current bulk action from the list table's top or bottom selector.
	 *
	 * @return string Action slug, or '' when none is chosen.
	 */
	public static function get_bulk_action() {
		foreach ( array( 'action', 'action2' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && '-1' !== $_REQUEST[ $key ] && '' !== $_REQUEST[ $key ] ) { // phpcs:ignore WordPress.Security.NonceVerification
				return sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}

		return '';
	}

	/**
	 * Badge classes for a log level, reusing the plugin's existing badge component.
	 *
	 * @param string $level Log level, any case.
	 * @return string
	 */
	public static function get_level_badge_class( $level ) {
		$variants = array(
			'emergency' => 'danger',
			'alert'     => 'danger',
			'critical'  => 'danger',
			'error'     => 'danger-subtle',
			'warning'   => 'warning-subtle',
			'notice'    => 'primary-subtle',
			'success'   => 'success-subtle',
			'info'      => 'info-subtle',
			'debug'     => 'secondary-subtle',
		);

		$variant = isset( $variants[ strtolower( $level ) ] ) ? $variants[ strtolower( $level ) ] : 'secondary-subtle';

		return 'user-registration-badge user-registration-badge--' . $variant;
	}

	/**
	 * Delete every file (current and rotated) of the given log sources.
	 *
	 * Only handles that exist on disk are acted on, and each file is removed
	 * through the file handler's own realpath-guarded remove().
	 *
	 * @param string[] $handles Source handles.
	 */
	public static function delete_sources( $handles ) {
		$sources     = UR_Log_List_Table::scan_sources();
		$log_handler = new UR_Log_Handler_File();

		foreach ( $handles as $handle ) {
			if ( ! isset( $sources[ $handle ] ) ) {
				continue;
			}

			foreach ( $sources[ $handle ]['files'] as $file ) {
				$log_handler->remove( sanitize_title( $file['filename'] ) );
			}
		}
	}

	/**
	 * Bulk-delete the log sources ticked in the list table.
	 */
	public static function remove_selected_logs() {
		if ( ! current_user_can( 'manage_user_registration' ) || empty( $_REQUEST['ur_logs_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['ur_logs_nonce'] ) ), 'bulk-logs' ) ) {
			wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'user-registration' ) );
		}

		$handles = array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['sources'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		self::delete_sources( $handles );
		?>
		<script>
		window.location.href = <?php echo wp_json_encode( esc_url_raw( admin_url( 'admin.php?page=user-registration-settings&tab=tools&section=logs' ) ) ); ?>;
		</script>
		<?php
	}


	/**
	 * Retrieve metadata from a file. Based on WP Core's get_file_data function.
	 *
	 * @since  2.1.1
	 *
	 * @param  string $file Path to the file.
	 *
	 * @return string
	 */
	public static function get_file_version( $file ) {

		// Avoid notices if file does not exist.
		if ( ! file_exists( $file ) ) {
			return '';
		}

		// We don't need to write to the file, so just open for reading.
		$fp = fopen( $file, 'r' );

		// Pull only the first 8kiB of the file in.
		$file_data = fread( $fp, 8192 );

		// PHP will close file handle, but we are good citizens.
		fclose( $fp );

		// Make sure we catch CR-only line endings.
		$file_data = str_replace( "\r", "\n", $file_data );
		$version   = '';

		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( '@version', '/' ) . '(.*)$/mi', $file_data, $match ) && $match[1] ) {
			$version = _cleanup_header_comment( $match[1] );
		}

		return $version;
	}

	/**
	 * Return the log file handle.
	 *
	 * @param string $filename Filename.
	 *
	 * @return string
	 */
	public static function get_log_file_handle( $filename ) {
		return substr( $filename, 0, strlen( $filename ) > 37 ? strlen( $filename ) - 37 : strlen( $filename ) - 4 );
	}

	/**
	 * Scan the template files.
	 *
	 * @param  string $template_path Template Path.
	 *
	 * @return array
	 */
	public static function scan_template_files( $template_path ) {

		$files  = @scandir( $template_path );
		$result = array();

		if ( ! empty( $files ) ) {

			foreach ( $files as $key => $value ) {

				if ( ! in_array( $value, array( '.', '..' ) ) ) {

					if ( is_dir( $template_path . DIRECTORY_SEPARATOR . $value ) ) {
						$sub_files = self::scan_template_files( $template_path . DIRECTORY_SEPARATOR . $value );

						foreach ( $sub_files as $sub_file ) {
							$result[] = $value . DIRECTORY_SEPARATOR . $sub_file;
						}
					} else {
						$result[] = $value;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Scan the log files.
	 *
	 * @return array
	 */
	public static function scan_log_files() {
		$files  = @scandir( UR_LOG_DIR );
		$result = array();

		if ( ! empty( $files ) ) {

			foreach ( $files as $key => $value ) {

				if ( ! in_array( $value, array( '.', '..' ) ) && null !== $value ) {
					if ( ! is_dir( $value ) && strstr( $value, '.log' ) ) {
						$result[ sanitize_title( $value ) ] = $value;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Remove/delete the chosen file.
	 */
	public static function remove_log() {

		if ( ! current_user_can( 'manage_user_registration' ) || empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'remove_log' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'user-registration' ) );
		}

		if ( ! empty( $_REQUEST['handle'] ) ) {
			$handle  = sanitize_text_field( wp_unslash( $_REQUEST['handle'] ) );
			$sources = UR_Log_List_Table::scan_sources();

			if ( isset( $sources[ $handle ] ) ) {
				self::delete_sources( array( $handle ) );
			} else {
				$log_handler = new UR_Log_Handler_File();
				$log_handler->remove( $handle );
			}
		}
		?>
		<script>
		var redirect = '<?php echo esc_url( admin_url( 'admin.php?page=user-registration-status&tab=logs' ) ); ?>';
		window.setTimeout( function () {
			window.location.href = redirect;
		})
		</script>
		<?php
	}

	/**
	 * Remove/delete all logs.
	 */
	public static function remove_all_logs() {
		if ( ! current_user_can( 'manage_user_registration' ) || empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'remove_all_logs' ) ) {
			wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'user-registration' ) );
		}

		if ( ! empty( $_REQUEST['handle_all'] ) ) {
			$log_handler = new UR_Log_Handler_File();
			$log_handler->remove_all();
		}

		?>
		<script>
		var redirect = '<?php echo esc_url( admin_url( 'admin.php?page=user-registration-status&tab=logs' ) ); ?>';
		window.setTimeout( function () {
			window.location.href = redirect;
		})
		</script>
		<?php
	}


	/**
	 * Displays the system information admin page.
	 *
	 * This method includes the system info page template
	 * to show relevant system details in the WordPress admin panel.
	 *
	 * @return void
	 */
	public static function system_info() {
		include_once __DIR__ . '/views/html-admin-page-system-info.php';
	}
}
