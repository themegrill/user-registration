<?php
/**
 * Plugin Updater
 *
 * @package  UserRegistration/Admin
 * @version  1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UR_AddOn_Updater', false ) ) {
	include_once __DIR__ . '/admin/updater/class-ur-addon-updater.php';
}

if ( ! class_exists( 'UR_Plugin_Updates', false ) ) {
	include_once __DIR__ . '/admin/updater/class-ur-plugin-updates.php';
}

/**
 * UR_Plugin_Updater Class.
 */
class UR_Plugin_Updater extends UR_Plugin_Updates {

	/**
	 * Plugin File.
	 *
	 * @var string
	 */
	private $plugin_file = '';

	/**
	 * Plugin Name.
	 *
	 * @var string
	 */
	private $plugin_name = '';

	/**
	 * Plugin Slug.
	 *
	 * @var string
	 */
	private $plugin_slug = '';

	/**
	 * Plugins data.
	 *
	 * @var array of strings
	 */
	private $plugin_data = array();

	/**
	 * Validation errors.
	 *
	 * @var array of strings
	 */
	private $errors = array();
	/**
	 * Plugin Api Key.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_updates( UR_PLUGIN_FILE );
	}

	/**
	 * Init the updater.
	 *
	 * @param string $_plugin_file Plugin File.
	 */
	public function init_updates( $_plugin_file ) {
		$this->plugin_file = $_plugin_file;
		$this->plugin_slug = str_replace( '.php', '', basename( $this->plugin_file ) );
		$this->plugin_name = basename( dirname( $this->plugin_file ) ) . '/' . $this->plugin_slug . '.php';

		register_activation_hook( $this->plugin_name, array( $this, 'plugin_activation' ), 10 );
		register_deactivation_hook( $this->plugin_name, array( $this, 'plugin_deactivation' ), 10 );

		add_filter( 'block_local_requests', '__return_false' );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		// Include required files.
		include_once __DIR__ . '/admin/updater/class-ur-plugin-updater-api.php';
	}

	/**
	 * Run on admin init.
	 */
	public function admin_init() {
		$this->load_errors();

		add_action( 'shutdown', array( $this, 'store_errors' ) );

		$this->api_key     = get_option( $this->plugin_slug . '_license_key' );
		$this->plugin_data = get_plugin_data( $this->plugin_file );

		$this->plugin_requests();
		// Check if pro is activated to display license notices.
		if ( ( file_exists( WP_PLUGIN_DIR . '/user-registration-pro/user-registration.php' ) && is_plugin_active( 'user-registration-pro/user-registration.php' ) ) && current_user_can( 'update_plugins' ) ) {
			add_action( 'in_admin_header', array( $this, 'user_registration_upgrade_to_pro_notice' ) );
		}
		$this->plugin_license_view();

		$message = get_option( 'user_registration_failed_installing_extensions_message', '' );

		if ( $message ) {
			add_action( 'admin_notices', array( $this, 'user_registration_failed_extension_install' ) );
		}
	}

	/**
	 * Process plugin requests.
	 */
	private function plugin_requests() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( false !== get_option( 'user-registration_license_key', false ) && ! empty( $_REQUEST['download_user_registration_pro'] ) ) {
			$this->install_extension();
			wp_redirect( remove_query_arg( array( 'deactivated_license', $this->plugin_slug . '_deactivate_license' ), add_query_arg( 'activated_license', $this->plugin_slug ) ) );
			exit;
		}

		if ( isset( $_POST['ur_license_nonce'] ) ) {
			if ( ! wp_verify_nonce( $_POST['ur_license_nonce'], '_ur_license_nonce' ) ) {
				return;
			}

			$this->activate_license_request();
		}
		if ( isset( $_GET['_wpnonce'] ) ) {
			if ( ! wp_verify_nonce( $_GET['_wpnonce'], '_ur_license_nonce' ) ) {
				return;
			}
			if ( ! empty( $_GET[ $this->plugin_slug . '_deactivate_license' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->deactivate_license_request();
			} elseif ( ! empty( $_GET[ 'dismiss-' . sanitize_title( $this->plugin_slug ) ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				update_option( $this->plugin_slug . '_hide_key_notice', 1 );
			} elseif ( ! empty( $_GET['activated_license'] ) && $_GET['activated_license'] === $this->plugin_slug ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->add_notice( array( $this, 'activated_key_notice' ) );
			} elseif ( ! empty( $_GET['deactivated_license'] ) && $_GET['deactivated_license'] === $this->plugin_slug ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->add_notice( array( $this, 'deactivated_key_notice' ) );
			}
		}
	}

	/**
	 * Activate a license request.
	 */
	private function activate_license_request() {
		$license_key = sanitize_text_field( $_POST[ $this->plugin_slug . '_license_key' ] ); // phpcs:ignore

		if ( $this->activate_license( $license_key ) ) {
			if ( ! is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
				setcookie( 'urm_license_status', 'license_activated', time() + 300, '/', '', is_ssl(), false );
			}
			wp_redirect( remove_query_arg( array( 'deactivated_license', $this->plugin_slug . '_deactivate_license' ), add_query_arg( 'activated_license', $this->plugin_slug ) ) );
			exit;
		} else {
			wp_redirect( remove_query_arg( array( 'activated_license', 'deactivated_license', $this->plugin_slug . '_deactivate_license' ) ) );
			exit;
		}
	}

	/**
	 * Install Extensions Action.
	 */
	public function install_extension() {

		$status = ur_install_extensions( 'User Registration &amp; Membership (Pro)', 'user-registration-pro' );

		if ( $status['success'] ) {
			add_action( 'admin_notices', array( $this, 'user_registration_extension_download_success_notice' ) );
		} else {
			add_option(
				'user_registration_failed_installing_extensions_message',
				$status['message']
			);
		}
	}

	/**
	 * Deactivate a license request
	 */
	private function deactivate_license_request() {
		$this->deactivate_license();
		wp_redirect( remove_query_arg( array( 'activated_license', $this->plugin_slug . '_deactivate_license' ), add_query_arg( 'deactivated_license', $this->plugin_slug ) ) );
		exit;
	}

	/**
	 * Display plugin license view.
	 */
	private function plugin_license_view() {
		if ( is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
			add_filter( 'plugin_action_links_' . $this->plugin_name, array( $this, 'plugin_action_links' ) );
		}
		add_action( 'admin_notices', array( $this, 'user_registration_error_notices' ) );
	}

	/**
	 * Add notices
	 *
	 * @param string $callback Callback function.
	 */
	private function add_notice( $callback ) {
		add_action( 'admin_notices', $callback );
		add_action( 'network_admin_notices', $callback );
	}

	/**
	 * Add an error message
	 *
	 * @param string $message Your error message.
	 * @param string $type    Type of error message.
	 */
	public function add_error( $message, $type = '' ) {
		foreach ( $this->errors as $key => $errors ) {
			if ( 'Error code: 403' === $errors ) {
				unset( $this->errors[ $key ] );
			}
		}

		if ( $type ) {
			$this->errors[ $type ] = $message;
		} else {
			$this->errors[] = $message;
		}
	}

	/**
	 * Load errors from option
	 */
	public function load_errors() {
		$this->errors = get_option( $this->plugin_slug . '_errors', array() );
	}

	/**
	 * Store errors in option
	 */
	public function store_errors() {
		if ( count( $this->errors ) > 0 ) {
			update_option( $this->plugin_slug . '_errors', $this->errors );
		} else {
			delete_option( $this->plugin_slug . '_errors' );
		}
	}

	/**
	 * Deprecation function for error notices.
	 *
	 * @deprecated 2.0.6
	 */
	public function error_notices() {
		ur_deprecated_function( 'UR_Admin_Profile::error_notices', '1.4.1', 'UR_Plugin_Updater::user_registration_error_notices' );
	}

	/**
	 * Output errors
	 */
	public function user_registration_error_notices() {
		if ( ! empty( $this->errors ) ) {
			foreach ( $this->errors as $key => $error ) {
				if ( $key === 'activation_error' ) {
					UR_Admin_Settings::add_error( $error, $key );
					unset( $this->errors[ $key ] );
				} else {
					include __DIR__ . '/admin/notifications/views/html-notice-error.php';
					if ( 'invalid_key' !== $key && did_action( 'all_admin_notices' ) ) {
						unset( $this->errors[ $key ] );
					}
				}
			}
		}
	}
	/**
	 * Ran on plugin-activation.
	 */
	public function plugin_activation() {
		delete_option( $this->plugin_slug . '_hide_key_notice' );
	}

	/**
	 * Ran on plugin-deactivation.
	 */
	public function plugin_deactivation() {
		delete_option( 'user_registration_activated' );
//		$this->deactivate_license();
	}

	/**
	 * Show the input form for the license key.
	 *
	 * @param string $plugin_file Plugin File.
	 */
	public function plugin_license_form( $plugin_file ) {

		$slug = $this->plugin_slug;

		if ( is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
			$slug .= '-pro';
		}

		if ( strtolower( basename( dirname( $plugin_file ) ) ) === strtolower( $slug ) ) {
			include_once __DIR__ . '/admin/views/html-license-form.php';
		}
	}

	/**
	 * Display action links in the Plugins list table.
	 *
	 * @param  array $actions Actions.
	 * @return array
	 */
	public function plugin_action_links( $actions ) {
		$new_actions = array();

		if ( ! $this->api_key ) {
			$new_actions['activate_license_settings'] = '<a href="' . esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=license' ) ) . '">' . __( 'Activate License', 'user-registration' ) . '</a>';
		} else {
			$new_actions['deactivate_license'] = '<a href="' . wp_nonce_url( remove_query_arg( array( 'deactivated_license', 'activated_license' ), add_query_arg( $this->plugin_slug . '_deactivate_license', 1 ) ), '_ur_license_nonce' ) . '" class="deactivate-license" style="color: #a00;" title="' . esc_attr( __( 'Deactivate License Key', 'user-registration' ) ) . '">' . __( 'Deactivate License', 'user-registration' ) . '</a>';
		}

		return array_merge( $actions, $new_actions );
	}

	/**
	 * Try to activate a license.
	 *
	 * @param string $license_key License Key.
	 *
	 * @throws Exception Throws license activation failed error message.
	 */
	public function activate_license( $license_key ) {
		try {
			if ( empty( $license_key ) ) {
				throw new Exception( 'Please enter your license key.' );
			}

			$activate_results = json_decode(
				UR_Updater_Key_API::activate(
					array(
						'license' => sanitize_text_field( $license_key ),
					)
				)
			);

			// Update activate results.
			update_option( $this->plugin_slug . '_license_active', $activate_results );

			if ( ! empty( $activate_results ) && is_object( $activate_results ) ) {

				if ( isset( $activate_results->error_code ) ) {
					throw new Exception( sanitize_text_field( $activate_results->error ) );

				} elseif ( false === $activate_results->success ) {
					switch ( $activate_results->error ) {
						case 'expired':
							$error_msg = wp_kses_post( sprintf( __( 'The provided license key expired on %1$s. Please <a href="%2$s" rel="noreferrer noopener" target="_blank">renew your license key</a>.', 'user-registration' ), esc_html( date_i18n( get_option( 'date_format' ) ), esc_html( strtotime( $activate_results->expires, current_time( 'timestamp' ) ) ) ), esc_url( ur_utm_url( 'https://wpeverest.com/checkout/?edd_license_key=' . $license_key, array( 'source' => 'licenses', 'medium' => 'notice', 'content' => 'expired' ) ) ) ) ); // phpcs:ignore.
							break;

						case 'revoked':
							/* translators: %s: Contact Support URL */
							$error_msg = wp_kses_post( sprintf( __( 'The provided license key has been disabled. Please <a href="%s" rel="noreferrer noopener" target="_blank">contact support</a> for more information.', 'user-registration' ), esc_url( ur_utm_url( 'https://wpeverest.com/contact', array( 'source' => 'licenses', 'medium' => 'notice', 'content' => 'revoked' ) ) ) ) );
							break;

						case 'missing':
							/* translators: %s: Account Page URL */
							$error_msg = wp_kses_post( sprintf( __( 'The provided license is invalid. Please <a href="%s" rel="noreferrer noopener" target="_blank">visit your account page</a> and verify it.', 'user-registration' ), esc_url( ur_utm_url( 'https://wpeverest.com/my-account', array( 'source' => 'licenses', 'medium' => 'notice', 'content' => 'missing' ) ) ) ) );
							break;

						case 'invalid':
						case 'site_inactive':
							/* translators: %s: Account Page URL */
							$error_msg = wp_kses_post( sprintf( __( 'The provided license is not active for this URL. Please <a href="%s" rel="noreferrer noopener" target="_blank">visit your account page</a> to manage your license key URLs.', 'user-registration' ), esc_url( ur_utm_url( 'https://wpeverest.com/my-account', array( 'source' => 'licenses', 'medium' => 'notice', 'content' => 'missing' ) ) ) ) );
							break;

						case 'invalid_item_id':
						case 'item_name_mismatch':
							/* translators: %s: Plugin Name */
							$error_msg = wp_kses_post( sprintf( __( 'This appears to be an invalid license key for <strong>%s</strong>.', 'user-registration' ), esc_html( $this->plugin_data['Name'] ) ) );
							break;

						case 'no_activations_left':
							/* translators: %s: Pricing Page URL */
							$error_msg = wp_kses_post( sprintf( __( 'The provided license key has reached its activation limit. Please <a href="%s" rel="noreferrer noopener" target="_blank">View possible upgrades</a> now.', 'user-registration' ), 'https://wpeverest.com/my-account/' ) );
							break;

						case 'license_not_activable':
							$error_msg = esc_html__( 'The key you entered belongs to a bundle, please use the product specific license key.', 'user-registration' );
							break;

						default:
							/* translators: %s: Contact Support URL */
							$error_msg = wp_kses_post( sprintf( __( 'The provided license key could not be found. Please <a href="%s" rel="noreferrer noopener" target="_blank">contact support</a> for more information.', 'user-registration' ), 'https://wpeverest.com/contact/' ) );
							break;
					}

					/* translators: %s: Error Message */
					throw new Exception( wp_kses_post( sprintf( __( '<strong>Activation error:</strong> %s', 'user-registration' ), wp_kses_post( $error_msg ) ) ) );

				} elseif ( 'valid' === $activate_results->license ) {
					$this->api_key = sanitize_text_field( $license_key );
					$this->errors  = array();

					update_option( $this->plugin_slug . '_license_key', $this->api_key );
					delete_option( $this->plugin_slug . '_errors' );

					$license_data = json_decode(
						UR_Updater_Key_API::check(
							array(
								'license' => $this->api_key,
							)
						)
					);

					if ( ! empty( $license_data->item_name ) ) {
						$license_data->item_plan = sanitize_text_field(
							trim( strtolower( str_replace( 'LifeTime', '', str_replace( 'User Registration', '', $license_data->item_name ) ) ) )
						);
						set_transient( 'ur_pro_license_plan', $license_data, WEEK_IN_SECONDS );
					}

					return true;
				}

				throw new Exception( 'License could not activate. Please contact support.' );
			} else {
				throw new Exception( 'Connection failed to the License Key API server - possible server issue.' );
			}
		} catch ( Exception $e ) {
			$this->add_error( $e->getMessage(), 'activation_error' );
			return false;
		}
	}

	/**
	 * Deactivate a license.
	 */
	public function deactivate_license() {
		$reset = UR_Updater_Key_API::deactivate(
			array(
				'license' => $this->api_key,
			)
		);

		delete_option( $this->plugin_slug . '_errors' );
		delete_option( $this->plugin_slug . '_license_key' );
		delete_option( $this->plugin_slug . '_license_active' );
		delete_transient( 'ur_pro_license_plan' );

		// Reset huh?
		$this->errors  = array();
		$this->api_key = '';
	}

	/**
	 * Show a notice prompting the user to update.
	 */
	public function key_notice() {
		if ( count( $this->errors ) === 0 && ! get_option( $this->plugin_slug . '_hide_key_notice' ) ) {
			include __DIR__ . '/admin/notifications/views/html-notice-key-unvalidated.php';
		}
	}

	/**
	 * Activation success notice.
	 */
	public function activated_key_notice() {
		include __DIR__ . '/admin/notifications/views/html-notice-key-activated.php';
	}

	/**
	 * Dectivation success notice.
	 */
	public function deactivated_key_notice() {
		include __DIR__ . '/admin/notifications/views/html-notice-key-deactivated.php';
	}

	/**
	 * Display error message when extension installation fails.
	 *
	 * @since 2.0.6
	 */
	public function user_registration_failed_extension_install() {
		$ur_pro_plugins_path = WP_PLUGIN_DIR . '/user-registration-pro/user-registration.php';
		$message             = get_option( 'user_registration_failed_installing_extensions_message', '' );

		if ( ! file_exists( $ur_pro_plugins_path ) ) {
			$message = $message . esc_html__( ' Please manually download <strong>User Registration & Membership PRO</strong>.', 'user-registration' );

			echo '<div class="error updated notice is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';

		} elseif ( ! is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
			$message = esc_html__( ' Please manually activate <strong>User Registration & Membership PRO</strong>.', 'user-registration' );

			echo '<div class="error updated notice is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';

		}

		delete_option( 'user_registration_failed_installing_extensions_message' );
	}

	/**
	 * Display upgrade to PRO notice.
	 *
	 * @since 2.1.0
	 */
	public function user_registration_upgrade_to_pro_notice() {

		// Donot show notice on form builder page.
		if ( isset( $_REQUEST['page'] ) && 'add-new-registration' === $_REQUEST['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$license_key         = get_option( $this->plugin_slug . '_license_key' );
		$ur_pro_plugins_path = WP_PLUGIN_DIR . '/user-registration-pro/user-registration.php';

		$link    = '';
		$content = '';

		if ( $license_key ) {
			$content .= sprintf( __( '<strong>If you have active premium license of User Registration & Membership</strong>, please click button below to install and activate <strong>User Registration & Membership Pro</strong>. Going forward <strong>User Registration & Membership Pro</strong> is necessary for smooth running of premium addons of User Registration & Membership that you are currently using.', 'user-registration' ) );
			$link    .= '<input name="ur_license_nonce" id="ur_license_nonce" type="hidden" value="' . wp_create_nonce( '_ur_license_nonce' ) . '"/>';
			$link    .= '<button class="button button-primary" type="text" name="download_user_registration_pro" value="download_user_registration_pro"><span class="dashicons dashicons-external"></span>' . __( 'Install and Activate User Registration & Membership Pro', 'user-registration' ) . '</button>';
		} else {
			$content .= sprintf( '<p class="extra-pad"><strong>%1$s</strong>, %2$s</p>', __( 'If you already have an active license key.', 'user-registration' ), __( 'please activate the key.', 'user-registration' ) );
			$content .= sprintf( '<p class="extra-pad"><strong>%1$s</strong>, %2$s</p>', __( 'If you do not have active premium license of User Registration & Membership', 'user-registration' ), __( 'please purchase premium license. Going forward active premium license will be vital for smooth running of premium addons of User Registration that you are currently using.', 'user-registration' ) );
			$link    .= '<li><a class="button button-primary" href="' . esc_url_raw( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'user-dashboard', 'medium' => 'notice', 'content' => 'pro-3-0-0' ) ) ) . '" rel="noreferrer noopener" target="_blank"><span class="dashicons dashicons-external"></span>' . __( 'Purchase Premium License', 'user-registration' ) . '</a></li>';
			$link    .= '<li><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=license' ) ) . '" rel="noreferrer noopener" target="_blank"><span class="dashicons dashicons-external"></span>' . __( 'Activate License Key', 'user-registration' ) . '</a></li>';
		}

		// If Pro is active do not show upgrade to pro notice but show update addons notice if not upto date.
		if ( is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
			$updated_addons_list = array(
				'user-registration-advanced-fields/user-registration-advanced-fields.php'           => array(
					'title'       => 'User Registration Advanced Fields',
					'version'     => '1.4.7',
					'notice_slug' => 'user_registration_advanced_fields_admin_notice',
				),
				'user-registration-conditional-logic/user-registration-conditional-logic.php'       => array(
					'title'       => 'User Registration Conditional Logic',
					'version'     => '1.3.0',
					'notice_slug' => 'user_registration_conditional_logic_admin_notice',
				),
				'user-registration-customize-my-account/user-registration-customize-my-account.php' => array(
					'title'       => 'User Registration Customize My Account',
					'version'     => '1.1.4',
					'notice_slug' => 'user_registration_customize_my_account_admin_notice',
				),
				'user-registration-email-templates/user-registration-email-templates.php'           => array(
					'title'       => 'User Registration Email Templates',
					'version'     => '1.0.4',
					'notice_slug' => 'user_registration_email_templates_admin_notice',
				),
				'user-registration-file-upload/user-registration-file-upload.php'                   => array(
					'title'       => 'User Registration File Upload',
					'version'     => '1.2.4',
					'notice_slug' => 'user_registration_file_upload_admin_notice',
				),
				'user-registration-mailchimp/user-registration-mailchimp.php'                       => array(
					'title'       => 'User Registration MailChimp',
					'version'     => '1.3.0',
					'notice_slug' => 'urmc_admin_notices',
				),
				'user-registration-pdf-form-submission/user-registration-pdf-form-submission.php'   => array(
					'title'       => 'User Registration PDF Form Submission',
					'version'     => '1.0.8',
					'notice_slug' => 'user_registration_pdf_admin_notice',
				),
				'user-registration-social-connect/user-registration-social-connect.php'             => array(
					'title'       => 'User Registration Social Connect',
					'version'     => '1.3.7',
					'notice_slug' => 'user_registration_social_connect_admin_notice',
				),
				'user-registration-woocommerce/user-registration-woocommerce.php'                   => array(
					'title'       => 'User Registration WooCommerce',
					'version'     => '1.2.7',
					'notice_slug' => 'user_registration_woocommerce_admin_notice',
				),
			);

			$plugins     = get_plugins();
			$show_notice = false;

			// Remove user registration required notice in outdated version of addon when pro is installed.
			global $wp_filter;
			$update_addon_content = '<p>Please update all the listed addons to the latest version.</p><ol style="margin-top:0px; font-size:12px;">';
			foreach ( $updated_addons_list as $addon_file => $addon_detail ) {
				if ( is_plugin_active( $addon_file ) && $plugins[ $addon_file ]['Version'] < $addon_detail['version'] ) {
					$show_notice = true;

					$update_addon_content .= '<li>' . $addon_detail['title'] . ' <strong>v( ' . $addon_detail['version'] . ' )</strong></li>';
					if ( ! empty( $wp_filter['admin_notices']->callbacks ) && is_array( $wp_filter['admin_notices']->callbacks ) ) {
						foreach ( $wp_filter['admin_notices']->callbacks as $priority => $hooks ) {
							if ( ! empty( $wp_filter['admin_notices']->callbacks[ $priority ][ $addon_detail['notice_slug'] ] ) ) {
								unset( $wp_filter['admin_notices']->callbacks[ $priority ][ $addon_detail['notice_slug'] ] );
							}
						}
					}
				}
			}

			$update_addon_content .= '</ol>';

			// Display update addons notice.
			if ( $show_notice ) {
				?>
				<div id="user-registration-upgrade-notice" class="notice notice-error user-registration-notice" data-purpose="review">
					<div class="user-registration-notice-thumbnail">
						<img src="<?php echo esc_url_raw( UR()->plugin_url() . '/assets/images/UR-Logo.png' ); ?>" alt="">
					</div>
					<div class="user-registration-notice-text">
						<div class="user-registration-notice-header">
							<h3 class="ur-error extra-pad"><?php echo wp_kses_post( sprintf( __( '<strong> Update all addons of User Registration & Membership!!</strong>', 'user-registration' ) ) ); ?></h3>
						</div>
						<p class="extra-pad"><?php echo wp_kses_post( sprintf( __( 'It seems some of the <strong>User Registration & Membership</strong> Addons are outdated. Please update the outdated addons to the latest version for the <strong>User Registration & Membership Pro</strong> plugin to work correctly.<br>', 'user-registration' ) ) ); ?></p>
						<?php echo wp_kses_post( $update_addon_content ); ?>
						<div class="user-registration-notice-links">
							<ul class="user-registration-notice-ul">
								<li><a href="<?php echo esc_url_raw( 'https://wpuserregistration.com/support/' ); ?>" class="button button-secondary notice-have-query" rel="noreferrer noopener" target="_blank"><span class="dashicons dashicons-testimonial"></span><?php esc_html_e( 'I have a query', 'user-registration' ); ?></a></li>
							</ul>
						</div>
					</div>
				</div>
				<?php
			}
		} elseif ( ! file_exists( $ur_pro_plugins_path ) || ! is_plugin_active( 'user-registration-pro/user-registration.php' ) || ! $license_key ) {
			?>
				<div id="user-registration-upgrade-notice" class="notice notice-error user-registration-notice" data-purpose="review">
					<div class="user-registration-notice-thumbnail">
						<img src="<?php echo esc_url_raw( UR()->plugin_url() . '/assets/images/UR-Logo.png' ); ?>" alt="">
					</div>
					<div class="user-registration-notice-text">
						<div class="user-registration-notice-header">
							<h3 class="ur-error extra-pad"><?php echo wp_kses_post( sprintf( __( '<strong> Upgrade To PRO!!</strong>', 'user-registration' ) ) ); ?></h3>
						</div>

						<p class="extra-pad"><?php echo wp_kses_post( sprintf( __( 'It seems you are using some premium addons of User Registration & Membership plugin. <br>', 'user-registration' ) ) ); ?></p>
						<?php echo esc_html( $license_key ) ? '<p class="extra-pad">' . wp_kses_post( $content ) . '</p>' : wp_kses_post( $content ); ?>
						<div class="user-registration-notice-links">
							<ul class="user-registration-notice-ul">
								<?php echo esc_html( $license_key ) ? '<li><form method="post">' . wp_kses_post( $link ) . '</form></li>' : wp_kses_post( $link ); ?>
								<li><a href="<?php echo esc_url_raw( 'https://wpuserregistration.com/support/' ); ?>" class="button button-secondary notice-have-query" rel="noreferrer noopener" target="_blank"><span class="dashicons dashicons-testimonial" ></span><?php esc_html_e( 'I have a query', 'user-registration' ); ?></a></li>
							</ul>
						</div>
					</div>
				</div>
			<?php
		}
	}

	/**
	 * Success notice on PRO installation.
	 *
	 * @since 2.0.0
	 */
	public function user_registration_extension_download_success_notice() {
		$notice_html = __( 'User Registration & Membership Pro has been installed successfully.', 'user-registration' );
		include __DIR__ . '/admin/notifications/views/html-notice-key-activated.php';
	}
}

new UR_Plugin_Updater();
