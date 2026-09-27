<?php
/**
 * Admin View: Page - System info
 *
 * One card per section (plugin, WordPress, PHP, web server, MySQL, required
 * pages, plugin settings). The "Copy system info" button in the options
 * header copies every card at once.
 *
 * @package UserRegistration
 * @since   x.x.x
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Debug_Data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
}

$ur_is_pro_active = is_plugin_active( 'user-registration-pro/user-registration.php' );
$ur_plugin_file   = WP_PLUGIN_DIR . ( $ur_is_pro_active ? '/user-registration-pro/user-registration.php' : '/user-registration/user-registration.php' );
$ur_plugin_data   = file_exists( $ur_plugin_file ) ? get_plugin_data( $ur_plugin_file ) : array();
$ur_license_key   = get_option( 'user-registration_license_key' );
$ur_license_data  = get_transient( 'ur_pro_license_plan' );
$ur_is_licensed   = $ur_license_key && $ur_license_data;

/**
 * A row's value is pre-escaped HTML.
 */
$ur_sections = array();

// Plugin.
$ur_plugin_rows = array(
	array( __( 'Version', 'user-registration' ), esc_html( isset( $ur_plugin_data['Version'] ) ? $ur_plugin_data['Version'] : '' ) ),
);

if ( $ur_is_pro_active ) {
	$ur_plugin_title = $ur_is_licensed && isset( $ur_license_data->item_name ) ? $ur_license_data->item_name : __( 'User Registration & Membership PRO', 'user-registration' );
	$ur_plugin_rows  = array_merge(
		$ur_plugin_rows,
		array(
			array( __( 'Edition', 'user-registration' ), esc_html( $ur_is_licensed && isset( $ur_license_data->item_plan ) ? __( 'PRO', 'user-registration' ) : ( $ur_is_licensed ? '-' : __( 'Free', 'user-registration' ) ) ) ),
			array( __( 'License', 'user-registration' ), esc_html( $ur_is_licensed ? ( isset( $ur_license_data->license ) ? __( 'Licensed', 'user-registration' ) : '-' ) : __( 'Unlicensed', 'user-registration' ) ) ),
			array( __( 'License activated', 'user-registration' ), esc_html( $ur_is_licensed ? ( isset( $ur_license_data->success ) ? __( 'Yes', 'user-registration' ) : '-' ) : __( 'No', 'user-registration' ) ) ),
			array( __( 'License expires', 'user-registration' ), esc_html( $ur_is_licensed && isset( $ur_license_data->expires ) ? $ur_license_data->expires : '-' ) ),
		)
	);
} else {
	$ur_plugin_title = __( 'User Registration & Membership', 'user-registration' );
}

$ur_sections[] = array(
	'title' => $ur_plugin_title,
	'rows'  => $ur_plugin_rows,
);

// WordPress.
$ur_active_plugins = array();
$ur_all_plugins    = get_plugins();

foreach ( get_option( 'active_plugins', array() ) as $ur_plugin_basename ) {
	if ( isset( $ur_all_plugins[ $ur_plugin_basename ] ) ) {
		$ur_active_plugins[] = esc_html( $ur_all_plugins[ $ur_plugin_basename ]['Name'] . ' (' . $ur_all_plugins[ $ur_plugin_basename ]['Version'] . ')' );
	}
}

$ur_theme = wp_get_theme();

$ur_wp_min_version = $ur_is_pro_active && ! empty( $ur_plugin_data['RequiresWP'] ) ? ' ' . sprintf( /* translators: %s: minimum WordPress version */ __( '(min %s)', 'user-registration' ), $ur_plugin_data['RequiresWP'] ) : '';

$ur_sections[] = array(
	'title' => __( 'WordPress', 'user-registration' ),
	'rows'  => array(
		array( __( 'Version', 'user-registration' ), esc_html( get_bloginfo( 'version' ) . $ur_wp_min_version ) ),
		array( __( 'Multisite', 'user-registration' ), esc_html( is_multisite() ? __( 'Yes', 'user-registration' ) : __( 'No', 'user-registration' ) ) ),
		array( __( 'Home URL', 'user-registration' ), esc_html( home_url() ) ),
		array( __( 'Site URL', 'user-registration' ), esc_html( site_url() ) ),
		array( __( 'Theme', 'user-registration' ), isset( $ur_theme->name, $ur_theme->version ) ? esc_html( $ur_theme->name . ' (' . $ur_theme->version . ')' ) : '' ),
		array( __( 'Plugins', 'user-registration' ), implode( '<br>', $ur_active_plugins ) ),
		array( __( 'Max upload size', 'user-registration' ), esc_html( wp_max_upload_size() / 1024 / 1024 . ' MB' ) ),
	),
);

// PHP.
$ur_php_min_version = $ur_is_pro_active && ! empty( $ur_plugin_data['RequiresPHP'] ) ? ' ' . sprintf( /* translators: %s: minimum PHP version */ __( '(min %s)', 'user-registration' ), $ur_plugin_data['RequiresPHP'] ) : '';

$ur_sections[] = array(
	'title' => __( 'PHP', 'user-registration' ),
	'rows'  => array(
		array( __( 'Version', 'user-registration' ), esc_html( phpversion() . $ur_php_min_version ) ),
		array( __( 'Default timezone', 'user-registration' ), esc_html( date_default_timezone_get() ) ),
		array( __( 'Max execution time', 'user-registration' ), esc_html( ini_get( 'max_execution_time' ) ) ),
		array( __( 'Memory limit', 'user-registration' ), esc_html( ini_get( 'memory_limit' ) ) ),
		array( __( 'Max upload size', 'user-registration' ), esc_html( ini_get( 'upload_max_filesize' ) ) ),
		array( __( 'Max input variables', 'user-registration' ), esc_html( ini_get( 'max_input_vars' ) ) ),
		array( __( 'SMTP hostname', 'user-registration' ), esc_html( ini_get( 'SMTP' ) ) ),
		array( __( 'SMTP port', 'user-registration' ), esc_html( ini_get( 'smtp_port' ) ) ),
	),
);

// Web server.
$ur_sections[] = array(
	'title' => __( 'Web Server', 'user-registration' ),
	'rows'  => array(
		array( __( 'Name', 'user-registration' ), esc_html( isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '' ) ),
		array( __( 'IP', 'user-registration' ), esc_html( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) ),
	),
);

// MySQL.
global $wpdb;
$ur_max_packet = WP_Debug_Data::get_mysql_var( 'max_allowed_packet' );

$ur_sections[] = array(
	'title' => __( 'MySQL', 'user-registration' ),
	'rows'  => array(
		array( __( 'Version', 'user-registration' ), esc_html( $wpdb->db_version() ) ),
		array( __( 'Max allowed packet', 'user-registration' ), esc_html( '' !== (string) $ur_max_packet ? $ur_max_packet / 1024 / 1024 . ' MB' : '' ) ),
	),
);

// Required pages.
$ur_plugin_pages = array(
	'user_registration_login_page_id'          => __( 'Login Page', 'user-registration' ),
	'user_registration_lost_password_page_id'  => __( 'Lost Password Page', 'user-registration' ),
	'user_registration_reset_password_page_id' => __( 'Reset Password Page', 'user-registration' ),
	'user_registration_myaccount_page_id'      => __( 'My Account Page', 'user-registration' ),
);

if ( ur_check_module_activation( 'membership' ) ) {
	$ur_plugin_pages['user_registration_member_registration_page_id'] = __( 'Membership Registration Page', 'user-registration' );
	$ur_plugin_pages['user_registration_thank_you_page_id']           = __( 'Thank You Page', 'user-registration' );
}

/**
 * Markup for a page's link, ID and live status; "Not Setup" if unpublished.
 *
 * @param int|string $page_id Page ID.
 * @param string     $source  Optional muted source note, e.g. "[Redirect]".
 * @return string Escaped HTML.
 */
$ur_page_status = function ( $page_id, $source = '' ) {
	$page = get_post( $page_id );
	$note = '' !== $source ? ' <small class="ur-source-note">' . esc_html( $source ) . '</small>' : '';

	if ( $page && 'publish' === $page->post_status ) {
		return '<a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank" class="ur-page-link">' . esc_html( $page->post_title ) . '</a> <small class="ur-page-id">(ID: ' . esc_html( $page_id ) . ')</small> - <span class="ur-status-live">' . esc_html__( 'Live', 'user-registration' ) . '</span>' . $note;
	}

	return '<span class="ur-status-not-setup">' . esc_html__( 'Not Setup', 'user-registration' ) . '</span>' . $note;
};

$ur_page_rows = array();

foreach ( $ur_plugin_pages as $ur_option => $ur_label ) {
	if ( 'user_registration_login_page_id' === $ur_option ) {
		$ur_login_info = ur_get_login_page_info();

		if ( $ur_login_info['login_page_id_set'] ) {
			$ur_value = $ur_page_status( get_option( 'user_registration_login_page_id' ) );
		} elseif ( $ur_login_info['login_redirect_url_set'] ) {
			$ur_redirect = get_option( 'user_registration_login_options_login_redirect_url' );

			if ( is_numeric( $ur_redirect ) ) {
				$ur_value = $ur_page_status( $ur_redirect, __( '[Redirect]', 'user-registration' ) );
			} else {
				$ur_value = '<a href="' . esc_url( $ur_redirect ) . '" target="_blank" class="ur-page-link">' . esc_html( $ur_redirect ) . '</a> <small class="ur-source-note">' . esc_html__( '[External URL]', 'user-registration' ) . '</small>';
			}
		} elseif ( $ur_login_info['has_login_pages'] ) {
			$ur_login_pages = $ur_login_info['login_pages_with_functionality'];
			$ur_value       = $ur_page_status( $ur_login_pages[0]->ID, __( '[Auto-detected]', 'user-registration' ) );

			if ( count( $ur_login_pages ) > 1 ) {
				/* translators: %d: number of additional pages */
				$ur_value .= '<br><small class="ur-additional-pages">' . esc_html( sprintf( __( '+%d more pages with login functionality', 'user-registration' ), count( $ur_login_pages ) - 1 ) ) . '</small>';
			}
		} else {
			$ur_value = '<span class="ur-status-not-setup">' . esc_html__( 'No Login Page Found', 'user-registration' ) . '</span>';
		}
	} else {
		$ur_page_id = get_option( $ur_option );
		$ur_value   = $ur_page_id ? $ur_page_status( $ur_page_id ) : '<span class="ur-status-not-setup">' . esc_html__( 'Not Setup', 'user-registration' ) . '</span>';
	}

	$ur_page_rows[] = array( $ur_label, $ur_value );
}

$ur_sections[] = array(
	'title' => __( 'Required Pages', 'user-registration' ),
	'rows'  => $ur_page_rows,
);

// Plugin settings (JSON), collapsed but always part of the copy.
$ur_global_settings = array();

foreach ( ur_setting_keys() as $ur_product => $ur_product_settings ) {
	foreach ( $ur_product_settings as $ur_setting_array ) {
		$ur_setting_key     = $ur_setting_array[0];
		$ur_setting_default = $ur_setting_array[1];
		$ur_value           = get_option( $ur_setting_key, 'NOT_SET' );

		// Set boolean values for certain settings.
		if ( isset( $ur_setting_array[2] ) && 'NOT_SET' !== $ur_value && $ur_setting_default !== $ur_value ) {
			$ur_value = 1;
		}

		if ( 'NOT_SET' !== $ur_value ) {
			$ur_global_settings[ $ur_product ][ $ur_setting_key ] = array( 'value' => $ur_value );
		}
	}
}
?>

<div class="user-registration-system-info-setting" id="ur-system-info">
	<div class="ur-si-notice"></div>
	<?php foreach ( $ur_sections as $ur_section ) : ?>
		<div class="user-registration-card ur-si-card">
			<div class="user-registration-card__header">
				<div class="user-registration-card__header-wrapper">
					<h3 class="user-registration-card__title"><?php echo esc_html( $ur_section['title'] ); ?></h3>
				</div>
			</div>
			<div class="user-registration-card__body">
				<table class="ur-si-table">
					<tbody>
					<?php foreach ( $ur_section['rows'] as $ur_row ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $ur_row[0] ); ?></th>
							<td><?php echo $ur_row[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endforeach; ?>

	<div class="user-registration-card ur-si-card">
		<div class="user-registration-card__header">
			<div class="user-registration-card__header-wrapper">
				<h3 class="user-registration-card__title"><?php esc_html_e( 'Plugin settings', 'user-registration' ); ?></h3>
			</div>
		</div>
		<div class="user-registration-card__body">
			<details>
				<summary><?php esc_html_e( 'Show settings (JSON). Included when you copy', 'user-registration' ); ?></summary>
				<pre class="ur-si-json" data-ur-si-json><?php echo esc_html( wp_json_encode( $ur_global_settings ) ); ?></pre>
			</details>
		</div>
	</div>
</div>

<style>
	#wpfooter {
		position: relative;
	}
</style>

<script>
	( function () {
		var root = document.getElementById( 'ur-system-info' );

		if ( ! root ) {
			return;
		}

		var i18n = {
			copied: <?php echo wp_json_encode( __( 'Copied!', 'user-registration' ) ); ?>,
			announce: <?php echo wp_json_encode( __( 'System info copied', 'user-registration' ) ); ?>,
			blocked: <?php echo wp_json_encode( __( 'Your browser blocked copying. The system info below is selected: press Ctrl/⌘ + C to copy it.', 'user-registration' ) ); ?>
		};

		function collectText() {
			var lines = [];

			root.querySelectorAll( '.ur-si-card' ).forEach( function ( card ) {
				var title = card.querySelector( '.user-registration-card__title' );
				lines.push( title ? title.textContent.trim() : '' );

				card.querySelectorAll( '.ur-si-table tr' ).forEach( function ( row ) {
					lines.push( row.children[0].textContent.trim() + '\t' + row.children[1].innerText.replace( /\s*\n\s*/g, ', ' ).trim() );
				} );

				var json = card.querySelector( '[data-ur-si-json]' );
				if ( json ) {
					lines.push( json.textContent.trim() );
				}

				lines.push( '' );
			} );

			return lines.join( '\n' ).trim();
		}

		function legacyCopy( text ) {
			var area = document.createElement( 'textarea' );
			area.className = 'ur-si-fallback';
			area.value = text;
			area.setAttribute( 'readonly', 'readonly' );
			area.setAttribute( 'aria-label', <?php echo wp_json_encode( __( 'System info', 'user-registration' ) ); ?> );
			root.querySelector( '.ur-si-notice' ).appendChild( area );
			area.select();

			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}

			return { ok: ok, area: area };
		}

		function showResult( button, ok, area ) {
			var tip = document.querySelector( '.ur-copied-tip' );
			var status = document.getElementById( 'ur-system-info-copy-status' );

			if ( ok ) {
				if ( area ) {
					area.remove();
				}
				if ( tip ) {
					tip.textContent = i18n.copied;
					tip.classList.add( 'is-visible' );
					window.setTimeout( function () {
						tip.classList.remove( 'is-visible' );
					}, 2000 );
				}
				if ( status ) {
					status.textContent = i18n.announce;
				}
				return;
			}

			if ( status ) {
				status.textContent = i18n.blocked;
			}
			var notice = root.querySelector( '.ur-si-notice' );
			var message = document.createElement( 'p' );
			message.className = 'notice notice-warning inline';
			message.textContent = i18n.blocked;
			notice.insertBefore( message, notice.firstChild );
		}

		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '.ur-system-info-copy' ) : null;

			if ( ! button ) {
				return;
			}

			var text = collectText();

			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then(
					function () {
						showResult( button, true );
					},
					function () {
						var result = legacyCopy( text );
						showResult( button, result.ok, result.area );
					}
				);
				return;
			}

			var result = legacyCopy( text );
			showResult( button, result.ok, result.area );
		} );
	}() );
</script>
