<?php
/**
 * Admin View: Page - System info
 *
 * @since x.x.x
 */

defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'WP_Debug_Data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
}
?>

<div class="user-registration-system-info-setting">
	<div class="user-registration-settings-header">
		<div class="user-registration-options-header--top__left">
	<span class="user-registration-options-header--top__left--icon">
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 18 18">
	<path fill-rule="evenodd"
		d="M1.667 4.167a2.5 2.5 0 0 1 2.5-2.5h11.666a2.5 2.5 0 0 1 2.5 2.5v11.666a2.5 2.5 0 0 1-2.5 2.5H4.167a2.5 2.5 0 0 1-2.5-2.5V4.167Zm2.5-.834a.833.833 0 0 0-.834.834v11.666c0 .46.373.834.834.834h11.666c.46 0 .834-.373.834-.834V4.167a.833.833 0 0 0-.834-.834H4.167Z"
		clip-rule="evenodd"/>
	<path fill-rule="evenodd"
		d="M6.11 10.486c.268 0 .486.218.486.486v3.403a.486.486 0 1 1-.972 0v-3.403c0-.268.217-.486.486-.486Zm0-5.347c.268 0 .486.218.486.486v3.403a.486.486 0 0 1-.972 0V5.625c0-.268.217-.486.486-.486ZM10 9.514c.268 0 .486.218.486.486v4.375a.486.486 0 0 1-.972 0V10c0-.268.218-.486.486-.486Zm0-4.375c.268 0 .486.218.486.486v2.43a.486.486 0 0 1-.972 0v-2.43c0-.268.218-.486.486-.486Zm3.89 6.319c.269 0 .487.218.487.486v2.431a.486.486 0 0 1-.973 0v-2.43c0-.269.218-.487.486-.487Zm0-6.319c.269 0 .487.218.487.486V10a.486.486 0 0 1-.973 0V5.625c0-.268.218-.486.486-.486Z"
		clip-rule="evenodd"/>
	<path fill-rule="evenodd"
		d="M4.654 10.972c0-.268.218-.486.486-.486h1.945a.486.486 0 1 1 0 .972H5.14a.486.486 0 0 1-.486-.486ZM8.54 8.056c0-.269.218-.487.486-.487h1.945a.486.486 0 1 1 0 .973H9.026a.486.486 0 0 1-.486-.486Zm3.89 3.888c0-.268.218-.486.487-.486h1.944a.486.486 0 0 1 0 .973h-1.944a.486.486 0 0 1-.486-.486Z"
		clip-rule="evenodd"/>
</svg>
</span>
			<h3><?php esc_html_e( 'System Info', 'user-registration' ); ?></h3>
		</div>
	</div>
	<button type="button" class="button button-primary user-registration-system-info-setting-copy" data-tip="<?php esc_attr_e( 'Copy system info', 'user-registration' ); ?>" data-copied="<?php esc_attr_e( 'Copied!', 'user-registration' ); ?>">
		<svg width="16" height="16" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
			<path d="M20 8H10C8.89543 8 8 8.89543 8 10V20C8 21.1046 8.89543 22 10 22H20C21.1046 22 22 21.1046 22 20V10C22 8.89543 21.1046 8 20 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			<path d="M4 16C2.9 16 2 15.1 2 14V4C2 2.9 2.9 2 4 2H14C15.1 2 16 2.9 16 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
		</svg>
		<?php esc_html_e( 'Copy system info', 'user-registration' ); ?>
	</button>
	<span id="ur-system-info-copy-status" class="screen-reader-text" role="status" aria-live="polite"></span>
	<table>
		<?php
		$license_key = get_option( 'user-registration_license_key' );

		if ( is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
			?>
			<tr>
				<th colspan="2">
					<?php
					$license_data = get_transient( 'ur_pro_license_plan' );
					if ( $license_key && $license_data ) {
						$name = isset( $license_data->item_name ) ? esc_html( $license_data->item_name ) : '-';
					} else {
						$name = esc_html__( 'User Registration & Membership PRO', 'user-registration' );
					}
					echo esc_html( $name );
					?>
				</th>

			</tr>
			<tr>
				<th><?php esc_html_e( 'Version', 'user-registration' ); ?></th>
				<td>
					<?php
					$plugin_file = WP_PLUGIN_DIR . '/user-registration-pro/user-registration.php';

					if ( file_exists( $plugin_file ) ) {
						$plugin_data = get_plugin_data( $plugin_file, array( 'Version' => 'Version' ) );
						if ( ! empty( $plugin_data['Version'] ) ) {
							$plugin_version = $plugin_data['Version'];
							echo esc_html( $plugin_version ) . ' ';
						}
					} else {
						$plugin_version = null;
					}
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Edition', 'user-registration' ); ?></th>
				<td>
					<?php
					$license_data = get_transient( 'ur_pro_license_plan' );

					if ( $license_key && $license_data ) {
						$edition = isset( $license_data->item_plan ) ? esc_html__( 'PRO', 'user-registration' ) : '-';
						echo esc_html( $edition );
					} else {
						echo esc_html__( 'Free', 'user-registration' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'License Key', 'user-registration' ); ?></th>
				<td>
					<?php
					$license_data = get_transient( 'ur_pro_license_plan' );

					if ( $license_key && $license_data ) {
						$license_key = isset( $license_data->license ) ? esc_html__( 'Licensed', 'user-registration' ) : '-';
						echo esc_html( $license_key );
					} else {
						echo esc_html__( 'Unlicensed', 'user-registration' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'License Activated', 'user-registration' ); ?></th>
				<td>
					<?php
					$license_data = get_transient( 'ur_pro_license_plan' );

					if ( $license_key && $license_data ) {
						$license_status = isset( $license_data->success ) ? esc_html__( 'Yes', 'user-registration' ) : '-';
						echo esc_html( $license_status );
					} else {
						echo esc_html__( 'No', 'user-registration' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'License Expires', 'user-registration' ); ?></th>
				<td>
					<?php
					$license_data = get_transient( 'ur_pro_license_plan' );
					if ( $license_key && $license_data ) {
						$expires = isset( $license_data->expires ) ? esc_html( $license_data->expires ) : '-';
						echo esc_html( $expires );
					} else {
						echo esc_html__( '-', 'user-registration' );
					}
					?>
				</td>
			</tr>
			<?php
		} elseif ( is_plugin_active( 'user-registration/user-registration.php' ) ) {
			?>
			<tr>
				<th colspan="2">
					<?php
					$plugin_name = esc_html__( 'User Registration & Membership', 'user-registration' );
					echo esc_html( $plugin_name );
					?>
				</th>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Version', 'user-registration' ); ?></th>
				<td>
					<?php
					$plugin_file = WP_PLUGIN_DIR . '/user-registration/user-registration.php';

					if ( file_exists( $plugin_file ) ) {
						$plugin_data = get_plugin_data( $plugin_file, array( 'Version' => 'Version' ) );
						if ( ! empty( $plugin_data['Version'] ) ) {
							$plugin_version = $plugin_data['Version'];
							echo esc_html( $plugin_version );
						}
					} else {
						$plugin_version = null;
					}
					?>
				</td>
			</tr>
			<?php
		}
		?>
		<!-- WordPress -->
		<tr>
			<th colspan="2">
				<?php
				esc_html_e( 'WordPress', 'user-registration' );
				?>
			</th>
		</tr>
		<tr>
			<th>
				<?php
				$require_wp     = get_plugin_data( $plugin_file, array( 'RequiresWP' => 'Requires WP' ) );
				$min_version_wp = $require_wp['RequiresWP'];
				esc_html_e( 'Version', 'user-registration' );
				if ( is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
					echo esc_html( '(Min:' . $min_version_wp . ')' );
				} elseif ( is_plugin_active( 'user-registration/user-registration.php' ) ) {
					echo ' ';
				}
				?>
			</th>
			<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'MultiSite Enabled', 'user-registration' ); ?></th>
			<td><?php echo esc_html( is_multisite() ? 'Yes' : 'No' ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Home URL', 'user-registration' ); ?></th>
			<td><?php echo esc_html( home_url() ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Site URL', 'user-registration' ); ?></th>
			<td><?php echo esc_html( site_url() ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Theme', 'user-registration' ); ?></th>
			<td>
				<?php
				$theme = wp_get_theme();
				echo isset( $theme->name ) && isset( $theme->version ) ? esc_html( $theme->name ) . ' (' . esc_html( $theme->version ) . ')' : '';
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Plugins', 'user-registration' ); ?></th>
			<td>
				<?php
				$all_plugins    = get_plugins();
				$active_plugins = get_option( 'active_plugins', array() );

				foreach ( $active_plugins as $pf ) {
					if ( isset( $all_plugins[ $pf ] ) ) {
						$plugin_data = $all_plugins[ $pf ];
						echo esc_html( $plugin_data['Name'] . ' (' . $plugin_data['Version'] . ')' ) . '<br>';
					}
				}
				?>
			</td>
		</tr>
		<tr class="ur-general-settings-hide">
			<th><?php esc_html_e( 'User Registration & Membership Global Settings ', 'user-registration' ); ?></th>
			<td>
				<?php
				$global_settings = array();
				$settings        = ur_setting_keys();
				$send_all        = false;
				$send_default    = false;

				foreach ( $settings as $product => $product_settings ) {
					foreach ( $product_settings as $setting_array ) {
						$setting_key     = $setting_array[0];
						$setting_default = $setting_array[1];
						$value           = get_option( $setting_key, 'NOT_SET' );

						// Set boolean values for certain settings.
						if ( isset( $setting_array[2] ) && 'NOT_SET' !== $value && $setting_default !== $value ) {
							$value = 1;
						}

						if ( 'NOT_SET' !== $value || $send_all ) {
							$setting_content = array(
								'value' => $value //phpcs:ignore
							);

							if ( $send_default ) {
								$setting_content['default'] = $setting_default;
							}

							$global_settings[ $product ][ $setting_key ] = $setting_content;
						}
					}
				}
				echo wp_json_encode( $global_settings );
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Max Upload Size', 'user-registration' ); ?></th>
			<td>
				<?php
				$max_upload_size_bytes = wp_max_upload_size();
				$max_upload_size_mb    = $max_upload_size_bytes / 1024 / 1024;
				echo esc_html( $max_upload_size_mb ) . ' MB';
				?>
			</td>
		</tr>
		<!-- PHP -->
		<tr>
			<th colspan="2"><?php esc_html_e( 'PHP', 'user-registration' ); ?></th>
		</tr>
		<tr>
			<th>
				<?php
				$plugin_data     = get_plugin_data( $plugin_file, array( 'RequiresPHP' => 'Requires PHP' ) );
				$min_version_php = $plugin_data['RequiresPHP'];
				esc_html_e( 'Version', 'user-registration' );
				if ( is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
					echo esc_html( '(Min:' . $min_version_php . ')' );
				} elseif ( is_plugin_active( 'user-registration/user-registration.php' ) ) {
					echo ' ';
				}
				?>
			</th>
			<td><?php echo esc_html( phpversion() ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Default Timezone', 'user-registration' ); ?></th>
			<td><?php echo esc_html( date_default_timezone_get() ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Max Execution Time', 'user-registration' ); ?></th>
			<td><?php echo esc_html( ini_get( 'max_execution_time' ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Memory Limit', 'user-registration' ); ?></th>
			<td><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Max Upload Size', 'user-registration' ); ?></th>
			<td><?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Max Input Variables', 'user-registration' ); ?></th>
			<td><?php echo esc_html( ini_get( 'max_input_vars' ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'SMTP Hostname', 'user-registration' ); ?></th>
			<td><?php echo esc_html( ini_get( 'SMTP' ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'SMTP Port', 'user-registration' ); ?></th>
			<td><?php echo esc_html( ini_get( 'smtp_port' ) ); ?></td>
		</tr>
		<!-- Web Server -->
		<tr>
			<th colspan="2"><?php esc_html_e( 'Web Server', 'user-registration' ); ?></th>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Name', 'user-registration' ); ?></th>
			<td>
				<?php
				$remote_addr = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
				echo esc_html( $remote_addr );
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'IP', 'user-registration' ); ?></th>
			<td>
				<?php
				$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
				echo esc_html( $remote_addr );
				?>
			</td>
		</tr>
		<!-- MySQL -->
		<tr>
			<th colspan="2"><?php esc_html_e( 'MySQL', 'user-registration' ); ?></th>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Version', 'user-registration' ); ?></th>
			<td>
				<?php
				global $wpdb;
				echo esc_html( $wpdb->db_version() );
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Max Allowed Packet', 'user-registration' ); ?></th>
			<td>
				<?php
				$max_packet_size_bytes = array(
					'label' => __( 'Max allowed packet size', 'user-registration' ),
					'value' => WP_Debug_Data::get_mysql_var( 'max_allowed_packet' ),
				);

				$info['wp-database']['fields']['max_allowed_packet'] = $max_packet_size_bytes;

				$maxp_mb = isset( $max_packet_size_bytes['value'] ) ? $max_packet_size_bytes['value'] / 1024 / 1024 : '';
				echo esc_html( $maxp_mb ) . ' MB';

				?>
			</td>
		</tr>

		<!-- Plugin Pages Information -->
		<tr>
			<th colspan="2"><?php esc_html_e( 'Required Pages', 'user-registration' ); ?></th>
		</tr>
		<?php
		$plugin_pages = array(
			'user_registration_login_page_id'          => __( 'Login Page', 'user-registration' ),
			'user_registration_lost_password_page_id'  => __( 'Lost Password Page', 'user-registration' ),
			'user_registration_reset_password_page_id' => __( 'Reset Password Page', 'user-registration' ),
			'user_registration_myaccount_page_id'      => __( 'My Account Page', 'user-registration' ),
		);
		if ( ur_check_module_activation( 'membership' ) ) {
			$plugin_pages['user_registration_member_registration_page_id'] = __( 'Membership Registration Page', 'user-registration' );
			$plugin_pages['user_registration_thank_you_page_id']           = __( 'Thank You Page', 'user-registration' );
		}

		foreach ( $plugin_pages as $option => $label ) {
			echo '<tr>';
			echo '<th>' . esc_html( $label ) . '</th>';

			// Special handling for login page
			if ( $option === 'user_registration_login_page_id' ) {
				$login_page_info = ur_get_login_page_info();
				if ( $login_page_info['login_page_id_set'] ) {
					// Check user_registration_login_page_id first
					$page_id = get_option( 'user_registration_login_page_id' );
					$page    = get_post( $page_id );
					if ( $page && $page->post_status === 'publish' ) {
						echo '<td><a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank" class="ur-page-link">' . esc_html( $page->post_title ) . '</a> <small class="ur-page-id">(ID: ' . $page_id . ')</small> - <span class="ur-status-live">' . esc_html__( 'Live', 'user-registration' ) . '</span></td>';
					} else {
						echo '<td><span class="ur-status-not-setup">' . esc_html__( 'Not Setup', 'user-registration' ) . '</span></td>';
					}
				} elseif ( $login_page_info['login_redirect_url_set'] ) {
					// Check user_registration_login_options_login_redirect_url
					$login_redirect_url = get_option( 'user_registration_login_options_login_redirect_url' );
					if ( is_numeric( $login_redirect_url ) ) {
						$page = get_post( $login_redirect_url );
						if ( $page && $page->post_status === 'publish' ) {
							echo '<td><a href="' . esc_url( get_permalink( $login_redirect_url ) ) . '" target="_blank" class="ur-page-link">' . esc_html( $page->post_title ) . '</a> <small class="ur-page-id">(ID: ' . $login_redirect_url . ')</small> - <span class="ur-status-live">' . esc_html__( 'Live', 'user-registration' ) . '</span> <small class="ur-source-redirect">[Redirect]</small></td>';
						} else {
							echo '<td><span class="ur-status-not-setup">' . esc_html__( 'Not Setup', 'user-registration' ) . '</span> <small class="ur-source-redirect">[Redirect]</small></td>';
						}
					} else {
						echo '<td><a href="' . esc_url( $login_redirect_url ) . '" target="_blank" class="ur-page-link">' . esc_html( $login_redirect_url ) . '</a> <small class="ur-source-redirect">[External URL]</small></td>';
					}
				} elseif ( $login_page_info['has_login_pages'] ) {
					// Show pages with login functionality
					$login_pages = $login_page_info['login_pages_with_functionality'];
					$first_page  = $login_pages[0];
					$page_id     = $first_page->ID;

					if ( $first_page->post_status === 'publish' ) {
						echo '<td><a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank" class="ur-page-link">' . esc_html( $first_page->post_title ) . '</a> <small class="ur-page-id">(ID: ' . $page_id . ')</small> - <span class="ur-status-live">' . esc_html__( 'Live', 'user-registration' ) . '</span> <small class="ur-source-auto">[Auto-detected]</small></td>';
					} else {
						echo '<td><span class="ur-status-not-setup">' . esc_html__( 'Not Setup', 'user-registration' ) . '</span> <small class="ur-source-auto">[Auto-detected]</small></td>';
					}

					// Show additional pages if more than one
					if ( count( $login_pages ) > 1 ) {
						echo '<br><small class="ur-additional-pages">+' . ( count( $login_pages ) - 1 ) . ' more pages with login functionality</small>';
					}
				} else {
					echo '<td><span class="ur-status-not-setup">' . esc_html__( 'No Login Page Found', 'user-registration' ) . '</span></td>';
				}
			} else {
				// Regular handling for other pages
				$page_id = get_option( $option );
				if ( $page_id ) {
					$page = get_post( $page_id );
					if ( $page && $page->post_status === 'publish' ) {
						echo '<td><a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank" class="ur-page-link">' . esc_html( $page->post_title ) . '</a> <small class="ur-page-id">(ID: ' . $page_id . ')</small> - <span class="ur-status-live">' . esc_html__( 'Live', 'user-registration' ) . '</span></td>';
					} else {
						echo '<td><span class="ur-status-not-setup">' . esc_html__( 'Not Setup', 'user-registration' ) . '</span></td>';
					}
				} else {
					echo '<td><span class="ur-status-not-setup">' . esc_html__( 'Not Setup', 'user-registration' ) . '</span></td>';
				}
			}

			echo '</tr>';
		}
		?>
	</table>
</div>

<style>
	#wpfooter {
		position: relative;
	}

	.user-registration-system-info-setting-copy.button-primary {
		width: auto;
		height: auto;
		padding: 0 16px;
		gap: 6px;
	}
</style>

<script>
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.user-registration-system-info-setting-copy' ) : null;
		var status = document.getElementById( 'ur-system-info-copy-status' );

		if ( button && status ) {
			status.textContent = '<?php echo esc_js( __( 'System info copied', 'user-registration' ) ); ?>';
		}
	} );
</script>
