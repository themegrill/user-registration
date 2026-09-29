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
$log_enabled = ur_option_checked( 'user_registration_enable_log', false );
?>
<?php if ( empty( $sources ) ) : ?>
	<div class="user-registration-card ur-log-card">
		<div class="user-registration-card__body ur-log-card-body">
			<?php
			UR_Base_Layout::no_items(
				__( 'logs', 'user-registration' ),
				$log_enabled
					? __( 'Logs appear here when an email, payment or fatal error needs your attention.', 'user-registration' )
					: __( 'Logging is currently disabled. Toggle “Enable Logs” in the header above to start recording plugin activity and errors.', 'user-registration' )
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
				<h3 class="user-registration-card__title"><?php esc_html_e( 'All Logs', 'user-registration' ); ?></h3>
				<p class="ur-log-totals">
					<?php
					$sources_str = sprintf(
						/* translators: %d: number of log sources */
						_n( '%d source', '%d sources', count( $sources ), 'user-registration' ),
						count( $sources )
					);
					$files_str = sprintf(
						/* translators: %d: number of log files */
						_n( '%d file', '%d files', $total_files, 'user-registration' ),
						$total_files
					);
					echo esc_html(
						sprintf(
							/* translators: 1: pluralized source count, 2: pluralized file count, 3: total size */
							__( '%1$s · %2$s · %3$s', 'user-registration' ),
							$sources_str,
							$files_str,
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
		<div class="user-registration-list-table-heading ur-log-back-heading">
			<a class="navigator navigator-prev ur-log-back" href="<?php echo esc_url( $ur_logs_url ); ?>">
				<span class="dashicons dashicons-arrow-left-alt2"></span>
			</a>
			<div class="ur-page-title__wrapper">
				<h2><?php esc_html_e( 'All Logs', 'user-registration' ); ?></h2>
			</div>
		</div>
	<?php endif; ?>

	<div class="user-registration-card ur-log-card">
		<div class="user-registration-card__header ur-log-card-header ur-log-single-header">
			<div class="user-registration-card__header-wrapper">
				<h3 class="user-registration-card__title"><?php echo esc_html( $info['name'] ); ?></h3>
				<p class="ur-log-totals">
					<?php
					$last_updated_date = wp_date( _x( 'M j, Y, g:i A', 'log list date format', 'user-registration' ), $source['mtime'] );
					echo esc_html(
						sprintf(
							/* translators: 1: category, 2: number of files, 3: total size, 4: last updated date */
							_n( '%1$s · %2$d file · %3$s · %4$s', '%1$s · %2$d files · %3$s · %4$s', count( $source['files'] ), 'user-registration' ),
							$info['category_label'],
							count( $source['files'] ),
							UR_Log_List_Table::format_size( $source['size'] ),
							$last_updated_date
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
				<a class="button button-tertiary ur-log-delete-link" href="<?php echo esc_url( $delete_log_url ); ?>" data-confirm-html="<?php echo esc_attr( UR_Log_List_Table::get_delete_confirm_html( $info['name'], count( $source['files'] ) ) ); ?>" data-type="single">
					<?php esc_html_e( 'Delete Log', 'user-registration' ); ?>
				</a>
			</div>
		</div>

		<div class="user-registration-card__body ur-log-card-body">
			<div id="log-viewer" dir="ltr" tabindex="0">
				<?php
				$log_content = file_get_contents( trailingslashit( UR_LOG_DIR ) . $viewed_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				if ( false === $log_content ) {
					echo '<p>' . esc_html__( "This log couldn't be opened. It may have been removed. Go back to All Logs and choose another.", 'user-registration' ) . '</p>';
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

					$pattern       = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})\s+(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG|SUCCESS)\s+(.+)$/s';
					$entries       = array();
					$current_entry = null;

					foreach ( $lines as $line ) {
						$trimmed = trim( $line );
						if ( '' === $trimmed && null === $current_entry ) {
							continue;
						}

						if ( preg_match( $pattern, $line, $matches ) ) {
							if ( null !== $current_entry ) {
								$entries[] = $current_entry;
							}
							$current_entry = array(
								'timestamp' => $matches[1],
								'level'     => $matches[2],
								'message'   => $matches[3],
								'extra'     => array(),
								'raw'       => false,
							);
						} elseif ( null !== $current_entry ) {
							$current_entry['extra'][] = $line;
						} elseif ( '' !== $trimmed ) {
							$entries[] = array(
								'raw'  => true,
								'line' => $line,
							);
						}
					}

					if ( null !== $current_entry ) {
						$entries[] = $current_entry;
					}

					// Ensure logs display starting from the last (latest) date first.
					$first_ts = null;
					$last_ts  = null;
					foreach ( $entries as $entry ) {
						if ( ! empty( $entry['timestamp'] ) ) {
							if ( null === $first_ts ) {
								$first_ts = strtotime( $entry['timestamp'] );
							}
							$last_ts = strtotime( $entry['timestamp'] );
						}
					}

					if ( null !== $first_ts && null !== $last_ts && $first_ts < $last_ts ) {
						$entries = array_reverse( $entries );
					}

					foreach ( $entries as $entry ) {
						if ( ! empty( $entry['raw'] ) ) {
							echo '<div class="ur-log-raw-line">' . esc_html( $entry['line'] ) . '</div>';
							continue;
						}

						try {
							$date = new DateTime( $entry['timestamp'] );
							$date->setTimezone( wp_timezone() );
							$formatted_time = $date->format( 'M j, Y g:i:s A' );
						} catch ( Exception $e ) {
							$formatted_time = $entry['timestamp'];
						}

						$safe_message = esc_html( $entry['message'] );
						$safe_message = preg_replace( '/(\[[^\]]+\])/', '<span class="log-highlight">$1</span>', $safe_message );
						$safe_message = preg_replace( '/\*\*\*(.*?)\*\*\*/', '<span class="log-highlight">$1</span>', $safe_message );

						echo '<div class="ur-log-line">';
						echo '<span class="ur-log-ts">' . esc_html( $formatted_time ) . '</span>';
						echo '<span class="ur-log-level ur-log-level--' . esc_attr( strtolower( $entry['level'] ) ) . '">' . esc_html( $entry['level'] ) . '</span>';
						echo '<span class="ur-log-msg">' . wp_kses( $safe_message, array( 'span' => array( 'class' => true ) ) ) . '</span>';
						echo '</div>';

						if ( ! empty( $entry['extra'] ) ) {
							$extra_payload = trim( implode( "\n", $entry['extra'] ) );
							if ( '' !== $extra_payload ) {
								echo '<details class="log-payload" open>';
								echo '<summary>' . esc_html__( 'View payload', 'user-registration' ) . '</summary>';
								echo '<pre class="payload-box">' . esc_html( $extra_payload ) . '</pre>';
								echo '</details>';
							}
						}
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
			var title = '<?php echo esc_js( __( 'Delete Log', 'user-registration' ) ); ?>';
			var html = link.getAttribute( 'data-confirm-html' );

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
			var title = '<?php echo esc_js( __( 'Delete All Logs', 'user-registration' ) ); ?>';
			var html = link.getAttribute( 'data-confirm-html' );

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

					// The checked count only exists client-side, so pluralization
					// can't run through PHP's _n() for this exact number — pick
					// between the two server-supplied templates instead.
					var bulkDeleteI18n = {
						singular: '<?php /* translators: %d: number of logs (singular) */ echo esc_js( _n( 'Are you sure you want to delete <b>this %d log</b> permanently?', 'Are you sure you want to delete <b>these %d logs</b> permanently?', 1, 'user-registration' ) ); ?>',
						plural: '<?php /* translators: %d: number of logs (plural) */ echo esc_js( _n( 'Are you sure you want to delete <b>this %d log</b> permanently?', 'Are you sure you want to delete <b>these %d logs</b> permanently?', 2, 'user-registration' ) ); ?>'
					};
					var title = '<?php echo esc_js( __( 'Delete Logs', 'user-registration' ) ); ?>';
					var template = 1 === checked ? bulkDeleteI18n.singular : bulkDeleteI18n.plural;
					var html = template.replace( '%d', String( checked ) );

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

		// Toggle logging handler.
		var toggleCheckbox = document.getElementById( 'ur-toggle-logging' );
		if ( toggleCheckbox ) {
			toggleCheckbox.addEventListener( 'change', function () {
				var isChecked = this.checked;
				var control   = document.getElementById( 'ur-log-toggle-control' );
				var nonce     = this.getAttribute( 'data-nonce' );

				if ( control ) {
					control.classList.add( 'is-saving' );
				}

				var body = new URLSearchParams();
				body.append( 'action', 'user_registration_toggle_logging' );
				body.append( 'security', nonce );
				body.append( 'enabled', isChecked ? 'true' : 'false' );

				fetch( ajaxurl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: body.toString()
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					if ( control ) {
						control.classList.remove( 'is-saving' );
					}
					if ( ! data.success ) {
						toggleCheckbox.checked = ! isChecked;
					}
				} )
				.catch( function () {
					if ( control ) {
						control.classList.remove( 'is-saving' );
					}
					toggleCheckbox.checked = ! isChecked;
				} );
			} );
		}
	}() );
</script>
