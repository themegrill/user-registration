<?php
/**
 * Admin View: Page - Status Logs
 *
 * @package UserRegistration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>
<?php if ( $logs ) : ?>
	<div id="log-viewer-select">
		<div class="alignleft">
			<h2>
				<?php echo esc_html( $viewed_log ); ?>

				<?php if ( 1 < count( $logs ) ) : ?>
					<?php
					$delete_all_url = wp_nonce_url(
						add_query_arg(
							array( 'handle_all' => sanitize_title( 'delete-all-logs' ) ),
							admin_url( 'admin.php?page=user-registration-status&tab=logs' )
						),
						'remove_all_logs'
					);
					?>
					<a
						class="button page-title-action page-title-action-all"
						style="border-color: #F25656; background: #F25656; color: #ffffff; font-size: 14px; line-height: 20px; padding: 8px 14px; font-weight: 500;"
						href="<?php echo esc_url( $delete_all_url ); ?>"
					>
						<?php esc_html_e( 'Delete all logs', 'user-registration' ); ?>
					</a>
				<?php endif; ?>

				<?php if ( ! empty( $viewed_log ) ) : ?>
					<?php
					$delete_log_url = wp_nonce_url(
						add_query_arg(
							array( 'handle' => sanitize_title( $viewed_log ) ),
							admin_url( 'admin.php?page=user-registration-status&tab=logs' )
						),
						'remove_log'
					);
					?>
					<a
						class="button page-title-action"
						href="<?php echo esc_url( $delete_log_url ); ?>"
					>
						<?php esc_html_e( 'Delete log', 'user-registration' ); ?>
					</a>
				<?php endif; ?>
			</h2>
		</div>

		<div class="alignright">
			<form action="<?php echo esc_url( admin_url( 'admin.php?page=user-registration-status' ) ); ?>" method="post">
				<select name="log_file" style="vertical-align: inherit;">
					<?php foreach ( $logs as $log_key => $log_file ) : ?>
						<?php
						$file_date = date_i18n(
							get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
							filemtime( UR_LOG_DIR . $log_file )
						);
						?>
						<option value="<?php echo esc_attr( $log_key ); ?>" <?php selected( sanitize_title( $viewed_log ), $log_key ); ?>>
							<?php echo esc_html( $log_file ); ?> (<?php echo esc_html( $file_date ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
				<input type="submit" class="button" value="<?php esc_attr_e( 'View', 'user-registration' ); ?>" />
			</form>
		</div>

		<div class="clear"></div>
	</div>

	<style>
		/* Log filenames are long enough to run over the dropdown arrow, which
		   sits in the padding WordPress leaves on the right. */
		#log-viewer-select select {
			max-width: 450px;
			padding-right: 30px;
			text-overflow: ellipsis;
		}

		#log-viewer .log-level-info    { color: #2271b1; font-weight: bold; }
		#log-viewer .log-level-notice  { color: #00a0d2; font-weight: bold; }
		#log-viewer .log-level-warning { color: #f0b849; font-weight: bold; }
		#log-viewer .log-level-error   { color: #dc3232; font-weight: bold; }
		#log-viewer .log-level-debug   { color: #7e57c2; font-weight: bold; }
		#log-viewer .log-level-success { color: #46b450; font-weight: bold; }

		#log-viewer .log-highlight {
			font-weight: bold;
		}

		#log-viewer details.log-payload {
			margin: 4px 0 8px 0;
		}

		#log-viewer details.log-payload summary {
			display:none;
			cursor: pointer;
			font-weight: 600;
		}

		#log-viewer .payload-box {
			margin-top: 6px;
			background: #f6f8f887;
			/* border: 1px solid #dcdcde; */
			border-radius: 4px;
			padding: 10px 12px;
			white-space: pre-wrap;
			overflow-x: auto;
		}
	</style>

	<div id="log-viewer">
		<?php
		$log_content    = file_get_contents( UR_LOG_DIR . $viewed_log );
		$is_payment_log = ( strpos( $viewed_log, 'urm-pg-' ) === 0 );

		echo '<pre>';
		$lines = explode( "\n", $log_content );

		$json_buffer   = array();
		$in_json_block = false;
		$brace_balance = 0;

		$render_payload = function ( $buffer ) {
			if ( empty( $buffer ) ) {
				return;
			}

			$payload = trim( implode( "\n", $buffer ) );

			if ( '' === $payload ) {
				return;
			}

			echo '</pre>';
			echo '<details class="log-payload" open>';
			echo '<summary>' . esc_html__( 'View', 'user-registration' ) . '</summary>';
			echo '<div class="payload-box">' . esc_html( $payload ) . '</div>';
			echo '</details>';
			echo '<pre>';
		};

	foreach ( $lines as $line ) {
		$trimmed = trim( $line );

		if ( $in_json_block ) {
			$json_buffer[] = $line;

			$brace_balance += substr_count( $line, '{' );
			$brace_balance += substr_count( $line, '[' );
			$brace_balance -= substr_count( $line, '}' );
			$brace_balance -= substr_count( $line, ']' );

			if ( $brace_balance <= 0 ) {
				$render_payload( $json_buffer );
				$json_buffer   = array();
				$in_json_block = false;
				$brace_balance = 0;
			}

			continue;
		}

		// Start View data block for JSON/array lines.
		if ( '' !== $trimmed && in_array( $trimmed[0], array( '{', '[' ), true ) ) {
			$in_json_block = true;
			$json_buffer[] = $line;

			$brace_balance += substr_count( $line, '{' );
			$brace_balance += substr_count( $line, '[' );
			$brace_balance -= substr_count( $line, '}' );
			$brace_balance -= substr_count( $line, ']' );

			if ( $brace_balance <= 0 ) {
				$render_payload( $json_buffer );
				$json_buffer   = array();
				$in_json_block = false;
				$brace_balance = 0;
			}

			continue;
		}

		// Match log pattern: timestamp LEVEL message
		$pattern = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})\s+(INFO|NOTICE|WARNING|ERROR|DEBUG|SUCCESS)\s+(.+)$/s';

		if ( preg_match( $pattern, $line, $matches ) ) {
			$timestamp = $matches[1];
			$level     = $matches[2];
			$message   = $matches[3];

			// Format timestamp to be more readable using WordPress timezone
			try {
				$date        = new DateTime( $timestamp );
				$wp_timezone = wp_timezone();
				$date->setTimezone( $wp_timezone );
				$formatted_time = $date->format( 'M d, Y g:i:s A' );
			} catch ( Exception $e ) {
				$formatted_time = $timestamp;
			}

			$safe_message = esc_html( $message );
			$safe_message = preg_replace(
				'/(\[[^\]]+\])/',
				'<span class="log-highlight">$1</span>',
				$safe_message
			);

			$safe_message = preg_replace(
				'/\*\*\*(.*?)\*\*\*/',
				'<span class="log-highlight">$1</span>',
				$safe_message
			);

			echo esc_html( $formatted_time ) . ' ';
			echo '<span class="log-level-' . esc_attr( strtolower( $level ) ) . '">' . esc_html( $level ) . '</span> ';
			echo wp_kses( $safe_message, array( 'span' => array( 'class' => true ) ) ) . "\n";
		} else {
			echo esc_html( $line ) . "\n";
		}
	}

	if ( ! empty( $json_buffer ) ) {
		$render_payload( $json_buffer );
	}

		echo '</pre>';
	?>
	</div>

<?php else : ?>
	<div class="updated user-registration-message inline">
		<p><?php esc_html_e( 'There are currently no logs to view.', 'user-registration' ); ?></p>
	</div>
<?php endif; ?>
