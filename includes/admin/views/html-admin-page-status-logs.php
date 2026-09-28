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
<?php if ( empty( $sources ) ) : ?>
	<div class="user-registration-card">
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
	<div class="user-registration-card ur-log-card">
		<div class="user-registration-card__header ur-log-card-header">
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
		<div class="user-registration-card__body ur-log-card-body">
			<input type="hidden" name="page" value="user-registration-settings" />
			<input type="hidden" name="tab" value="tools" />
			<input type="hidden" name="section" value="logs" />
			<?php
			foreach ( array( 'orderby', 'order' ) as $ur_sort_key ) {
				if ( isset( $_GET[ $ur_sort_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					echo '<input type="hidden" name="' . esc_attr( $ur_sort_key ) . '" value="' . esc_attr( sanitize_key( wp_unslash( $_GET[ $ur_sort_key ] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification
				}
			}

			?>
			<div class="ur-list-table-wrapper">
				<?php $table->display(); ?>
			</div>
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

	<div class="user-registration-card ur-log-card">
		<div class="user-registration-card__header ur-log-card-header ur-log-single-header">
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
									. ' · ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $file['mtime'] )
									. ' · ' . UR_Log_List_Table::format_size( $file['size'] )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<input type="submit" class="button button-tertiary" value="<?php esc_attr_e( 'View', 'user-registration' ); ?>" />
				<?php endif; ?>
				<a class="button button-tertiary ur-log-delete-link" href="<?php echo esc_url( $delete_log_url ); ?>" data-name="<?php echo esc_attr( $info['name'] ); ?>" data-files="<?php echo esc_attr( count( $source['files'] ) ); ?>" data-type="single">
					<?php esc_html_e( 'Delete log', 'user-registration' ); ?>
				</a>
			</div>
		</div>

		<div class="user-registration-card__body ur-log-card-body">
			<div id="log-viewer" dir="ltr" tabindex="0">
				<?php
				$log_content = file_get_contents( trailingslashit( UR_LOG_DIR ) . $viewed_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				if ( false === $log_content ) {
					echo '<p>' . esc_html__( 'This log couldn’t be opened. It may have been removed. Go back to All logs and choose another.', 'user-registration' ) . '</p>';
				} else {
					$lines       = explode( "\n", $log_content );
					$total_lines = count( $lines );
					$show_all    = ! empty( $_GET['show_all'] ); // phpcs:ignore WordPress.Security.NonceVerification

					if ( $total_lines > 500 && ! $show_all ) {
						$earlier_url = add_query_arg( 'show_all', '1' );
						echo '<p class="ur-log-load-earlier">' . sprintf(
							/* translators: 1: number of earlier lines, 2: link open tag, 3: link close tag */
							esc_html__( 'Showing the latest 500 lines of %1$d. %2$sLoad earlier lines%3$s.', 'user-registration' ),
							(int) $total_lines,
							'<a href="' . esc_url( $earlier_url ) . '">',
							'</a>'
						) . '</p>';
						$lines = array_slice( $lines, -500 );
					}

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

						echo '<details class="log-payload" open>';
						echo '<summary>' . esc_html__( 'View payload', 'user-registration' ) . '</summary>';
						echo '<pre class="payload-box">' . esc_html( $payload ) . '</pre>';
						echo '</details>';
					};

					foreach ( $lines as $line ) {
						$trimmed = trim( $line );

						if ( $in_json_block ) {
							$json_buffer[]  = $line;
							$brace_balance += substr_count( $line, '{' ) + substr_count( $line, '[' ) - substr_count( $line, '}' ) - substr_count( $line, ']' );

							if ( $brace_balance <= 0 ) {
								$render_payload( $json_buffer );
								$json_buffer   = array();
								$in_json_block = false;
								$brace_balance = 0;
							}
							continue;
						}

						if ( '' !== $trimmed && in_array( $trimmed[0], array( '{', '[' ), true ) ) {
							$in_json_block  = true;
							$json_buffer[]  = $line;
							$brace_balance += substr_count( $line, '{' ) + substr_count( $line, '[' ) - substr_count( $line, '}' ) - substr_count( $line, ']' );

							if ( $brace_balance <= 0 ) {
								$render_payload( $json_buffer );
								$json_buffer   = array();
								$in_json_block = false;
								$brace_balance = 0;
							}
							continue;
						}

						$pattern = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})\s+(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG|SUCCESS)\s+(.+)$/s';

						if ( preg_match( $pattern, $line, $matches ) ) {
							$timestamp = $matches[1];
							$level     = $matches[2];
							$message   = $matches[3];

							try {
								$date = new DateTime( $timestamp );
								$date->setTimezone( wp_timezone() );
								$formatted_time = $date->format( 'M j, Y g:i:s A' );
							} catch ( Exception $e ) {
								$formatted_time = $timestamp;
							}

							$safe_message = esc_html( $message );
							$safe_message = preg_replace( '/(\[[^\]]+\])/', '<span class="log-highlight">$1</span>', $safe_message );
							$safe_message = preg_replace( '/\*\*\*(.*?)\*\*\*/', '<span class="log-highlight">$1</span>', $safe_message );

							echo '<div class="ur-log-line">';
							echo '<span class="ur-log-ts">' . esc_html( $formatted_time ) . '</span>';
							echo '<span class="ur-log-level ur-log-level--' . esc_attr( strtolower( $level ) ) . '">' . esc_html( $level ) . '</span>';
							echo '<span class="ur-log-msg">' . wp_kses( $safe_message, array( 'span' => array( 'class' => true ) ) ) . '</span>';
							echo '</div>';
						} elseif ( '' !== $trimmed ) {
							echo '<div class="ur-log-raw-line">' . esc_html( $line ) . '</div>';
						}
					}

					if ( ! empty( $json_buffer ) ) {
						$render_payload( $json_buffer );
					}
				}
				?>
			</div>
		</div>
	</div>
<?php endif; ?>

<script>
	( function () {
		/**
		 * Escapes plain text for safe inclusion in an HTML sink.
		 *
		 * @param {string} str Untrusted text.
		 * @return {string} HTML-escaped string.
		 */
		function escapeHTML( str ) {
			var div = document.createElement( 'div' );
			div.textContent = str;
			return div.innerHTML;
		}

		/**
		 * Triggers SweetAlert2 delete confirmation modal.
		 *
		 * @param {string} title Modal title text.
		 * @param {string} html Modal HTML body.
		 * @param {Function} onConfirm Callback when user confirms.
		 */
		function showDeleteModal( title, html, onConfirm ) {
			if ( typeof Swal === 'undefined' ) {
				var tempEl = document.createElement( 'div' );
				tempEl.innerHTML = html;
				if ( window.confirm( tempEl.textContent || tempEl.innerText || title ) ) {
					onConfirm();
				}
				return;
			}

			var trashIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f25656" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6"/></svg>';

			Swal.fire( {
				title: trashIcon + '<span>' + title + '</span>',
				html: html,
				showCancelButton: true,
				focusCancel: true,
				confirmButtonText: '<?php echo esc_js( __( 'Delete', 'user-registration' ) ); ?>',
				cancelButtonText: '<?php echo esc_js( __( 'Cancel', 'user-registration' ) ); ?>',
				buttonsStyling: false,
				customClass: {
					popup: 'ur-tools-delete-modal',
					header: 'ur-tools-delete-modal__header',
					title: 'ur-tools-delete-modal__title',
					htmlContainer: 'ur-tools-delete-modal__content',
					actions: 'ur-tools-delete-modal__actions',
					cancelButton: 'ur-tools-delete-modal__cancel',
					confirmButton: 'ur-tools-delete-modal__confirm'
				}
			} ).then( function ( result ) {
				if ( result.value || result.isConfirmed ) {
					onConfirm();
				}
			} );
		}

		// Single or row action delete.
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '.ur-log-delete-link' ) : null;
			if ( ! link ) {
				return;
			}

			event.preventDefault();
			var rawName = link.getAttribute( 'data-name' ) || '<?php echo esc_js( __( 'this log', 'user-registration' ) ); ?>';
			var name = escapeHTML( rawName );
			var files = parseInt( link.getAttribute( 'data-files' ) || '1', 10 );
			var fileStr = files === 1 ? '1 <?php echo esc_js( __( 'file', 'user-registration' ) ); ?>' : files + ' <?php echo esc_js( __( 'files', 'user-registration' ) ); ?>';

			var title = '<?php echo esc_js( __( 'Delete log', 'user-registration' ) ); ?>';
			var html = '<?php echo esc_js( __( 'Are you sure you want to delete the', 'user-registration' ) ); ?> <b>' + name + '</b> (' + fileStr + ') <?php echo esc_js( __( 'permanently?', 'user-registration' ) ); ?>';

			showDeleteModal( title, html, function () {
				window.location.href = link.href;
			} );
		} );

		// Header "Delete all logs".
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '.ur-log-delete-all' ) : null;
			if ( ! link ) {
				return;
			}

			event.preventDefault();
			var files = parseInt( link.getAttribute( 'data-files' ) || '0', 10 );
			var fileStr = files > 0 ? files + ' ' : '';

			var title = '<?php echo esc_js( __( 'Delete all logs', 'user-registration' ) ); ?>';
			var html = '<?php echo esc_js( __( 'Are you sure you want to delete', 'user-registration' ) ); ?> <b><?php echo esc_js( __( 'all', 'user-registration' ) ); ?> ' + fileStr + '<?php echo esc_js( __( 'log files', 'user-registration' ) ); ?></b> <?php echo esc_js( __( 'permanently? This can\'t be undone.', 'user-registration' ) ); ?>';

			showDeleteModal( title, html, function () {
				window.location.href = link.href;
			} );
		} );

		// Bulk delete.
		var form = document.getElementById( 'mainform' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var top = form.querySelector( 'select[name="action"]' );
				var bottom = form.querySelector( 'select[name="action2"]' );
				var chosen = ( top && 'delete' === top.value ) || ( bottom && 'delete' === bottom.value );
				var checked = form.querySelectorAll( 'input[name="sources[]"]:checked' ).length;

				if ( chosen && checked > 0 ) {
					event.preventDefault();

					var title = '<?php echo esc_js( __( 'Delete logs', 'user-registration' ) ); ?>';
					var html = '<?php echo esc_js( __( 'Are you sure you want to delete these', 'user-registration' ) ); ?> <b>' + checked + ' <?php echo esc_js( __( 'logs', 'user-registration' ) ); ?></b> <?php echo esc_js( __( 'permanently?', 'user-registration' ) ); ?>';

					showDeleteModal( title, html, function () {
						form.submit();
					} );
				}
			} );
		}

		// Synchronize Reset button state with category dropdown and search input.
		var categorySelect = document.getElementById( 'ur-log-category' );
		var searchInput    = document.getElementById( 'ur-log-search-input' );
		var resetBtn       = document.getElementById( 'ur-log-filter-reset-btn' );

		function updateResetState() {
			if ( ! resetBtn ) {
				return;
			}

			var urlParams   = new URLSearchParams( window.location.search );
			var urlFiltered = ( urlParams.get( 'log_category' ) && urlParams.get( 'log_category' ) !== '' )
				|| ( urlParams.get( 's' ) && urlParams.get( 's' ).trim() !== '' );
			var dirty       = ( categorySelect && categorySelect.value !== '' )
				|| ( searchInput && searchInput.value.trim() !== '' );

			if ( dirty || urlFiltered ) {
				resetBtn.disabled = false;
				resetBtn.classList.remove( 'disabled' );
				resetBtn.removeAttribute( 'aria-disabled' );
			} else {
				resetBtn.disabled = true;
				resetBtn.classList.add( 'disabled' );
				resetBtn.setAttribute( 'aria-disabled', 'true' );
			}
		}

		if ( categorySelect ) {
			categorySelect.addEventListener( 'change', function () {
				var url = new URL( window.location.href );
				if ( categorySelect.value ) {
					url.searchParams.set( 'log_category', categorySelect.value );
				} else {
					url.searchParams.delete( 'log_category' );
				}
				url.searchParams.delete( 'paged' );
				window.location.href = url.toString();
			} );
		}
		if ( searchInput ) {
			searchInput.addEventListener( 'input', updateResetState );
		}

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( resetBtn.disabled ) {
					return;
				}
				if ( categorySelect ) {
					categorySelect.selectedIndex = 0;
				}
				if ( searchInput ) {
					searchInput.value = '';
				}

				var urlParams = new URLSearchParams( window.location.search );
				if ( urlParams.has( 'log_category' ) || urlParams.has( 's' ) || urlParams.has( 'paged' ) ) {
					window.location.href = '<?php echo esc_js( admin_url( 'admin.php?page=user-registration-settings&tab=tools&section=logs' ) ); ?>';
				} else {
					updateResetState();
				}
			} );
		}
	}() );
</script>
