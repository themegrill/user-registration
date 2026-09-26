<?php
/**
 * Admin View: Page - Status Logs
 *
 * Expects: $sources (handle => grouped files), $viewed_handle, $viewed_file.
 *
 * @package UserRegistration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$ur_logs_url = admin_url( 'admin.php?page=user-registration-settings&tab=tools&section=logs' );
?>
<style>
	.user-registration-options-header--top {
		display: flex;
		justify-content: space-between;
		align-items: center;
	}

	.ur-log-card-header {
		display: flex;
		flex-wrap: wrap;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
	}

	.ur-log-card-header .user-registration-card__header-wrapper {
		display: flex;
		align-items: baseline;
		gap: 12px;
	}

	.ur-log-totals {
		margin: 0;
		font-size: 13px;
		color: #6b6b6b;
	}

	.ur-log-search {
		position: relative;
		width: 275px;
	}

	.ur-log-search input[type="search"] {
		width: 100%;
		min-height: 38px;
		height: 38px;
		padding: 0 35px 0 12px;
		border: 1px solid #e1e1e1;
		border-radius: 4px;
	}

	.ur-log-search button {
		position: absolute;
		top: 50%;
		right: 12px;
		transform: translateY(-50%);
		display: flex;
		width: 18px;
		height: 18px;
		padding: 0;
		border: 0;
		background: transparent;
		cursor: pointer;
	}

	.ur-log-search button svg path {
		fill: #a1a4b9;
	}

	.ur-logs-table th,
	.ur-logs-table td {
		padding: 12px 16px;
		font-size: 14px;
		line-height: 24px;
		color: #383838;
	}

	.ur-logs-table thead th {
		font-weight: 600;
		white-space: nowrap;
	}

	.ur-logs-table .column-files,
	.ur-logs-table .column-size {
		text-align: right;
	}

	.ur-logs-table .row-title {
		font-weight: 500;
		color: #475bb2;
	}

	.ur-logs-table .row-actions {
		font-size: 13px;
		color: #6b6b6b;
	}

	.ur-logs-table .ur-log-handle {
		display: block;
		padding: 0;
		background: none;
		font: 12px/18px Consolas, Monaco, monospace;
		color: #6b6b6b;
	}

	.user-registration-card__body .tablenav .actions {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.user-registration-card__body .tablenav .actions select {
		max-width: 220px;
	}

	.user-registration-card__body .empty-list-table-container {
		text-align: center;
		padding: 40px 24px 44px;
	}

	.user-registration-card__body .empty-list-table-container img {
		display: block;
		width: 256px;
		max-width: 100%;
		height: auto;
		margin: 0 auto 12px;
	}

	@media (max-width: 782px) {
		body {
			min-width: 0;
		}

		.ur-log-search {
			width: 100%;
		}

		.ur-log-card-header .user-registration-card__header-wrapper {
			flex-wrap: wrap;
		}
	}

</style>
<?php if ( empty( $sources ) ) : ?>
	<div class="user-registration-card ur-mt-4 ur-border-0">
		<div class="user-registration-card__body">
			<?php
			UR_Base_Layout::no_items(
				__( 'logs', 'user-registration' ),
				__( 'Logs appear here when an email, payment or fatal error needs your attention.', 'user-registration' )
			);
			?>
		</div>
	</div>
<?php elseif ( '' === $viewed_handle ) : ?>
	<?php
	$table = new UR_Log_List_Table();
	$table->prepare_items();

	$total_files = 0;
	$total_size  = 0;
	foreach ( $sources as $source ) {
		$total_files += count( $source['files'] );
		$total_size  += $source['size'];
	}
	?>
	<div class="user-registration-card ur-mt-4 ur-border-0">
		<div class="user-registration-card__header ur-border-0 ur-log-card-header">
			<div class="user-registration-card__header-wrapper">
				<h3 class="user-registration-card__title"><?php esc_html_e( 'All logs', 'user-registration' ); ?></h3>
				<p class="ur-log-totals">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of sources, 2: number of files, 3: total size */
							__( '%1$d sources · %2$d files · %3$s', 'user-registration' ),
							count( $sources ),
							$total_files,
							UR_Log_List_Table::format_size( $total_size )
						)
					);
					?>
				</p>
			</div>
			<?php if ( $table->should_show_search() ) : ?>
				<div class="ur-log-search">
					<label class="screen-reader-text" for="ur-log-search-input"><?php esc_html_e( 'Search logs', 'user-registration' ); ?></label>
					<input type="search" id="ur-log-search-input" name="s" value="<?php echo esc_attr( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification ?>" placeholder="<?php esc_attr_e( 'Search logs…', 'user-registration' ); ?>" />
					<button type="submit" aria-label="<?php esc_attr_e( 'Search logs', 'user-registration' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="#000" fill-rule="evenodd" d="M4 11a7 7 0 1 1 12.042 4.856 1.012 1.012 0 0 0-.186.186A7 7 0 0 1 4 11Zm12.618 7.032a9 9 0 1 1 1.414-1.414l3.675 3.675a1 1 0 0 1-1.414 1.414l-3.675-3.675Z" clip-rule="evenodd"/></svg>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<div class="pt-0 pb-0 user-registration-card__body">
			<input type="hidden" name="page" value="user-registration-settings" />
			<input type="hidden" name="tab" value="tools" />
			<input type="hidden" name="section" value="logs" />
			<?php
			foreach ( array( 'orderby', 'order' ) as $ur_sort_key ) {
				if ( isset( $_GET[ $ur_sort_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					echo '<input type="hidden" name="' . esc_attr( $ur_sort_key ) . '" value="' . esc_attr( sanitize_key( wp_unslash( $_GET[ $ur_sort_key ] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification
				}
			}

			$table->display();
			?>
		</div>
	</div>
<?php else : ?>
	<?php
	$source = $sources[ $viewed_handle ];
	$info   = UR_Log_List_Table::describe_handle( $viewed_handle );

	$delete_log_url = wp_nonce_url(
		add_query_arg( array( 'handle' => $viewed_handle ), $ur_logs_url ),
		'remove_log'
	);
	?>
	<?php if ( count( $sources ) > 1 ) : ?>
		<a class="ur-log-back" href="<?php echo esc_url( $ur_logs_url ); ?>">&lsaquo; <?php esc_html_e( 'All logs', 'user-registration' ); ?></a>
	<?php endif; ?>

	<div class="user-registration-card ur-mt-4 ur-border-0">
		<div class="user-registration-card__header ur-border-0" style="display:flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
			<div class="user-registration-card__header-wrapper">
				<h3 class="user-registration-card__title"><?php echo esc_html( $info['name'] ); ?></h3>
				<p class="ur-log-totals">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: category, 2: number of files, 3: total size */
							_n( '%1$s · %2$d file · %3$s', '%1$s · %2$d files · %3$s', count( $source['files'] ), 'user-registration' ),
							$info['category_label'],
							count( $source['files'] ),
							UR_Log_List_Table::format_size( $source['size'] )
						)
					);
					?>
				</p>
			</div>
			<div class="ur-log-actions">
				<?php if ( count( $source['files'] ) > 1 ) : ?>
					<input type="hidden" name="page" value="user-registration-settings" />
					<input type="hidden" name="tab" value="tools" />
					<input type="hidden" name="section" value="logs" />
					<input type="hidden" name="log" value="<?php echo esc_attr( $viewed_handle ); ?>" />
					<label class="screen-reader-text" for="ur-log-part"><?php esc_html_e( 'Log file', 'user-registration' ); ?></label>
					<select name="log_file" id="ur-log-part">
						<?php foreach ( $source['files'] as $file ) : ?>
							<option value="<?php echo esc_attr( sanitize_title( $file['filename'] ) ); ?>" <?php selected( $viewed_file, $file['filename'] ); ?>>
								<?php
								echo esc_html(
									( 'current' === $file['part'] ? __( 'Current', 'user-registration' ) : sprintf( /* translators: %d: rotation number */ __( 'Part %d', 'user-registration' ), $file['part'] + 1 ) )
									. ' · ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $file['mtime'] )
									. ' · ' . UR_Log_List_Table::format_size( $file['size'] )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<input type="submit" class="button button-tertiary" value="<?php esc_attr_e( 'View', 'user-registration' ); ?>" />
				<?php endif; ?>
				<a class="button button-tertiary ur-log-delete-link" href="<?php echo esc_url( $delete_log_url ); ?>" data-confirm="<?php esc_attr_e( 'Delete this log permanently?', 'user-registration' ); ?>">
					<?php esc_html_e( 'Delete log', 'user-registration' ); ?>
				</a>
			</div>
		</div>

		<style>
			#log-viewer .log-highlight { font-weight: bold; }
			#log-viewer .user-registration-badge { font-size: 12px; font-weight: 600; margin: 0 4px; }
			#log-viewer details.log-payload { margin: 4px 0 8px 0; }
			#log-viewer details.log-payload summary { display: none; }
			#log-viewer .payload-box {
				margin-top: 6px;
				background: #f6f8f887;
				border-radius: 4px;
				padding: 10px 12px;
				white-space: pre-wrap;
				overflow-x: auto;
			}
		</style>

		<div class="pt-0 pb-0 user-registration-card__body">
			<div id="log-viewer">
				<?php
				$log_content = file_get_contents( trailingslashit( UR_LOG_DIR ) . $viewed_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				if ( false === $log_content ) {
					echo '<p>' . esc_html__( 'This log couldn’t be opened. It may have been removed. Go back to All logs and choose another.', 'user-registration' ) . '</p>';
				} else {
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

						// Match log pattern: timestamp LEVEL message.
						$pattern = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})\s+(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG|SUCCESS)\s+(.+)$/s';

						if ( preg_match( $pattern, $line, $matches ) ) {
							$timestamp = $matches[1];
							$level     = $matches[2];
							$message   = $matches[3];

							// Format timestamp to be more readable using WordPress timezone.
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
							echo '<span class="' . esc_attr( UR_Admin_Status::get_level_badge_class( $level ) ) . '">' . esc_html( $level ) . '</span> ';
							echo wp_kses( $safe_message, array( 'span' => array( 'class' => true ) ) ) . "\n";
						} else {
							echo esc_html( $line ) . "\n";
						}
					}

					if ( ! empty( $json_buffer ) ) {
						$render_payload( $json_buffer );
					}

					echo '</pre>';
				}
				?>
			</div>
		</div>
	</div>
<?php endif; ?>

<script>
	( function () {
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '.ur-log-delete-link' ) : null;
			if ( link && ! window.confirm( link.getAttribute( 'data-confirm' ) ) ) {
				event.preventDefault();
			}
		} );

		var form = document.getElementById( 'mainform' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var top = form.querySelector( 'select[name="action"]' );
				var bottom = form.querySelector( 'select[name="action2"]' );
				var chosen = ( top && 'delete' === top.value ) || ( bottom && 'delete' === bottom.value );
				var checked = form.querySelectorAll( 'input[name="sources[]"]:checked' ).length;

				if ( chosen && checked && ! window.confirm( '<?php echo esc_js( __( 'Delete the selected logs permanently?', 'user-registration' ) ); ?>' ) ) {
					event.preventDefault();
				}
			} );
		}
	}() );
</script>
