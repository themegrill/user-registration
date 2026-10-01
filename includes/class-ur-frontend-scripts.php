<?php
/**
 * UserRegistration fronted scripts
 *
 * @class    UR_Frontend_Scripts
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UR_Frontend_Scripts Class
 */
class UR_Frontend_Scripts {

	/**
	 * Contains an array of script handles registered by UR.
	 *
	 * @var array
	 */
	private static $scripts = array();

	/**
	 * Contains an array of script handles registered by UR.
	 *
	 * @var array
	 */
	private static $styles = array();

	/**
	 * Contains an array of script handles localized by UR.
	 *
	 * @var array
	 */
	private static $wp_localize_scripts = array();

	/**
	 * Hook in methods.
	 */
	public static function init() {
		add_action( 'user_registration_enqueue_scripts', array( __CLASS__, 'load_scripts' ), 5 );
		add_action( 'user_registration_my_account_enqueue_scripts', array( __CLASS__, 'load_scripts' ), 5 );
		add_action( 'before-user-registration-my-account-shortcode', array( __CLASS__, 'load_my_account_scripts' ) );
		add_action( 'wp_print_scripts', array( __CLASS__, 'localize_printed_scripts' ), 5 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'localize_printed_scripts' ), 5 );
		add_action( 'user_registration_enqueue_scripts', array( __CLASS__, 'localize_scripts_with_form_id' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'output_dynamic_primary_color' ), 100 );
	}

	/**
	 * localize_scripts_with_form_id
	 *
	 * @param $form_data
	 * @param $form_id
	 *
	 * @return void
	 */
	public static function localize_scripts_with_form_id( $form_data, $form_id ) {
		wp_localize_script(
			'user-registration',
			'ur_frontend_params_with_form_id',
			array(
				'custom_password_params' => self::get_custom_password_params( $form_id ),
			)
		);
	}

	/**
	 * Load form custom password params.
	 *
	 * @return array|string
	 */
	public static function get_custom_password_params( $form_id ) {

		$enable_strong_password = ur_string_to_bool( ur_get_single_post_meta( $form_id, 'user_registration_form_setting_enable_strong_password' ) );
		if ( ! $enable_strong_password ) {
			return '';
		}

		$custom_params = array(
			'minimum_uppercase'     => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_minimum_uppercase' ),
			'minimum_digits'        => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_minimum_digits' ),
			'minimum_special_chars' => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_minimum_special_chars' ),
			'minimum_pass_length'   => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_minimum_pass_length' ),
			'no_rep_chars'          => ur_string_to_bool( ur_get_single_post_meta( $form_id, 'user_registration_form_setting_no_repeat_chars' ) ),
			'max_rep_chars'         => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_max_char_repeat_length' ),
		);

		$add_prefix = true;

		/* translators: %d: Minimum password length. */
		$hint = sprintf(
			__( 'The password must have minimum length of %d characters', 'user-registration' ),
			$custom_params['minimum_pass_length']
		);

		if ( $custom_params['minimum_uppercase'] > 0 ) {
			/* translators: %d: Minimum number of uppercase letters required. */
			$hint      .= ' ' . sprintf(
				__( 'and contain at least %d uppercase letters', 'user-registration' ),
				$custom_params['minimum_uppercase']
			);
			$add_prefix = false;
		}

		if ( $custom_params['minimum_digits'] > 0 ) {
			/* translators: %d: Minimum number of digits required. */
			$hint      .= ' ' . sprintf(
				$add_prefix
				? __( 'and contain at least %d number', 'user-registration' )
				: __( 'and contain at least %d number', 'user-registration' ),
				$custom_params['minimum_digits']
			);
			$add_prefix = false;
		}

		if ( $custom_params['minimum_special_chars'] > 0 ) {
			/* translators: %d: Minimum number of special characters required. */
			$hint      .= ' ' . sprintf(
				$add_prefix
				? __( 'and contain at least %d special character', 'user-registration' )
				: __( 'and contain at least %d special characters', 'user-registration' ),
				$custom_params['minimum_special_chars']
			);
			$add_prefix = false;
		}

		if ( $custom_params['no_rep_chars'] ) {
			/* translators: %d: Maximum allowed consecutive repeated characters. */
			$hint .= ' ' . sprintf(
				__( 'and should only have %d repetitive letters at max', 'user-registration' ),
				$custom_params['max_rep_chars']
			);
		}

		$hint .= '.';

		$custom_params['hint'] = esc_html( $hint );

		return $custom_params;
	}

	/**
	 * Register/queue frontend scripts.
	 */
	public static function load_scripts() {
		global $post;

		if ( ! did_action( 'before_user_registration_init' ) ) {
			return;
		}

		self::register_scripts();
		self::register_styles();

		if ( is_ur_lost_password_page() ) {
			self::enqueue_script( 'ur-lost-password' );
			self::enqueue_script( 'ur-common' );
		}

		// CSS Styles.
		if ( $enqueue_styles = self::get_styles() ) { //phpcs:ignore
			foreach ( $enqueue_styles as $handle => $args ) {
				if ( ! isset( $args['has_rtl'] ) ) {
					$args['has_rtl'] = false;
				}

				self::enqueue_style( $handle, $args['src'], $args['deps'], $args['version'], $args['media'], $args['has_rtl'] );
			}
		}

		wp_enqueue_style( 'dashicons' );
	}

	/**
	 * Register all UR scripts.
	 */
	private static function register_scripts() {
		$suffix                = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$recaptcha_site_key_v3 = get_option( 'user_registration_captcha_setting_recaptcha_site_key_v3' );
		$register_scripts      = array(
			'ur-inputmask'               => array(
				'src'     => self::get_asset_url( 'assets/js/inputmask/jquery.inputmask.bundle' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '4.0.0-beta.58',
			),
			'flatpickr'                  => array(
				'src'     => self::get_asset_url( 'assets/js/flatpickr/flatpickr.min.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '4.6.9',
			),
			'ur-jquery-validate'         => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/jquery.validate' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '1.20.0',
			),
			'user-registration'          => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/user-registration' . $suffix . '.js' ),
				'deps'    => array( 'jquery', 'ur-jquery-validate', 'ur-inputmask' ),
				'version' => UR_VERSION,
			),
			'ur-form-validator'          => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/user-registration-form-validator' . $suffix . '.js' ),
				'deps'    => array( 'jquery', 'user-registration' ),
				'version' => UR_VERSION,
			),
			'ur-lost-password'           => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/lost-password' . $suffix . '.js' ),
				'deps'    => array( 'jquery', 'user-registration' ),
				'version' => UR_VERSION,
			),
			'ur-password-strength-meter' => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/password-strength-meter' . $suffix . '.js' ),
				'deps'    => array( 'jquery', 'password-strength-meter' ),
				'version' => UR_VERSION,
			),
			'ur-recaptcha'               => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/user-registration-recaptcha' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => UR_VERSION,
			),
			'ur-google-recaptcha'        => array(
				'src'     => 'https://www.google.com/recaptcha/api.js?onload=onloadURRecaptchaCallback&render=explicit',
				'deps'    => array(),
				'version' => '2.0.0',
			),
			'ur-google-recaptcha-v3'     => array(
				'src'     => 'https://www.google.com/recaptcha/api.js?render=' . $recaptcha_site_key_v3,
				'deps'    => array(),
				'version' => '3.0.0',
			),
			'ur-recaptcha-hcaptcha'      => array(
				// recaptchacompat=off prevents hCaptcha from aliasing window.grecaptcha,
				// which would otherwise collide with Google reCAPTCHA when another plugin
				// (e.g. Gravity Forms reCAPTCHA add-on) loads Google's api.js on the same page.
				'src'     => 'https://hcaptcha.com/1/api.js?onload=onloadURHcaptchaCallback&render=explicit&recaptchacompat=off',
				'deps'    => array(),
				'version' => UR_VERSION,
			),
			'ur-recaptcha-cloudflare'    => array(
				'src'     => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=onloadURTurnstileCallback',
				'deps'    => array(),
				'version' => '',
			),
			'ur-my-account'              => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/my-account' . $suffix . '.js' ),
				'deps'    => array( 'jquery', 'user-registration', 'sweetalert2' ),
				'version' => UR_VERSION,
			),
			'ur-login'                   => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/ur-login' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => UR_VERSION,
			),
			'tooltipster'                => array(
				'src'     => self::get_asset_url( 'assets/js/tooltipster/tooltipster.bundle' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '4.2.8',
			),
			'selectWoo'                  => array(
				'src'     => self::get_asset_url( 'assets/js/selectWoo/selectWoo.full' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '5.0.0',
			),
			'sweetalert2'                => array(
				'src'     => UR()->plugin_url() . '/assets/js/sweetalert2/sweetalert2.min.js',
				'deps'    => array( 'jquery' ),
				'version' => '10.16.7',
			),
			'ur-common'                  => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/ur-common' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => UR_VERSION,
			),
		);
		foreach ( $register_scripts as $name => $props ) {
			self::register_script( $name, $props['src'], $props['deps'], $props['version'] );
		}
	}

	/**
	 * Return asset URL.
	 *
	 * @param string $path Asset Path.
	 *
	 * @return string
	 */
	private static function get_asset_url( $path ) {
		/**
		 * Applies a filter to retrieve the URL of an asset (e.g., stylesheet or script).
		 *
		 * @param string $filter_name The name of the filter hook, 'user_registration_get_asset_url'.
		 * @param string $url The default URL of the asset, generated using plugins_url and the provided path.
		 * @param string $path The relative path to the asset within the plugin.
		 *
		 * @return string The filtered URL of the asset.
		 */
		return apply_filters( 'user_registration_get_asset_url', plugins_url( $path, UR_PLUGIN_FILE ), $path );
	}

	/**
	 * Register a script for use.
	 *
	 * @param string   $handle Script handler.
	 * @param string   $path Script Path.
	 * @param string[] $deps Dependencies.
	 * @param string   $version Version.
	 * @param boolean  $in_footer In Footer Enable/Disable.
	 *
	 * @uses   wp_register_script()
	 */
	private static function register_script( $handle, $path, $deps = array( 'jquery' ), $version = UR_VERSION, $in_footer = true ) {
		self::$scripts[] = $handle;
		wp_register_script( $handle, $path, $deps, $version, $in_footer );
	}

	/**
	 * Register all UR styles.
	 */
	private static function register_styles() {
		$register_styles = array(
			'jquery-ui-css' => array(
				'src'     => self::get_asset_url( 'assets/css/jquery-ui/jquery-ui.css' ),
				'deps'    => '',
				'version' => '1.12.1',
				'media'   => 'all',
				'has_rtl' => false,
			),
			'flatpickr'     => array(
				'src'     => self::get_asset_url( 'assets/css/flatpickr/flatpickr.min.css' ),
				'deps'    => array(),
				'version' => '4.6.9',
				'media'   => 'all',
				'has_rtl' => false,
			),
			'select2'       => array(
				'src'     => self::get_asset_url( 'assets/css/select2/select2.css' ),
				'deps'    => array(),
				'version' => '4.0.6',
				'has_rtl' => false,
			),
		);

		foreach ( $register_styles as $name => $props ) {
			self::register_style( $name, $props['src'], $props['deps'], $props['version'], 'all', $props['has_rtl'] );
		}
	}

	/**
	 * Register a style for use.
	 *
	 * @param string   $handle Script handler.
	 * @param string   $path Script Path.
	 * @param string[] $deps Dependencies.
	 * @param string   $version Version.
	 * @param string   $media Media.
	 * @param boolean  $has_rtl RTL.
	 *
	 * @uses   wp_register_style()
	 */
	private static function register_style( $handle, $path, $deps = array(), $version = UR_VERSION, $media = 'all', $has_rtl = false ) {
		self::$styles[] = $handle;
		wp_register_style( $handle, $path, $deps, $version, $media );

		if ( $has_rtl ) {
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
	}

	/**
	 * Register and enqueue a script for use.
	 *
	 * @param string   $handle Script handler.
	 * @param string   $path Script Path.
	 * @param string[] $deps Dependencies.
	 * @param string   $version Version.
	 * @param boolean  $in_footer In Footer Enable/Disable.
	 *
	 * @uses   wp_enqueue_script()
	 */
	private static function enqueue_script( $handle, $path = '', $deps = array( 'jquery' ), $version = UR_VERSION, $in_footer = true ) {
		if ( ! in_array( $handle, self::$scripts ) && $path ) {
			self::register_script( $handle, $path, $deps, $version, $in_footer );
		}
		wp_enqueue_script( $handle );
	}

	/**
	 * Get styles for the frontend.
	 *
	 * @return array
	 */
	public static function get_styles() {
		/**
		 * Applies filters to enqueue styles for the User Registration plugin.
		 *
		 * @param string $filter_name The name of the filter hook, 'user_registration_enqueue_styles'.
		 * @param array $styles An array containing style information for different components.
		 *                            Each component is identified by a unique key, and its details include:
		 *                            - 'src'     (string) The source URL of the stylesheet.
		 *                            - 'deps'    (string|array) Dependencies for the stylesheet.
		 *                            - 'version' (string) The version of the stylesheet.
		 *                            - 'media'   (string) The media attribute for the stylesheet.
		 *                            - 'has_rtl' (bool) Whether the stylesheet has a right-to-left (RTL) version.
		 *
		 * @return array The filtered array of styles for enqueuing.
		 */
		return apply_filters(
			'user_registration_enqueue_styles',
			array(
				'sweetalert2'               => array(
					'src'     => UR()->plugin_url() . '/assets/css/sweetalert2/sweetalert2.min.css',
					'deps'    => '',
					'version' => '10.16.7',
					'media'   => 'all',
				),
				'user-registration-general' => array(
					'src'     => self::get_asset_url( 'assets/css/user-registration.css' ),
					'deps'    => '',
					'version' => UR_VERSION,
					'media'   => 'all',
					'has_rtl' => true,
				),
				'ltr-support'               => array(
					'src'     => self::get_asset_url( 'assets/css/ltr_only_support.css' ),
					'deps'    => '',
					'version' => UR_VERSION,
					'media'   => 'all',
				),

			)
		);
	}

	/**
	 * Register and enqueue a styles for use.
	 *
	 * @param string   $handle Script handler.
	 * @param string   $path Script Path.
	 * @param string[] $deps Dependencies.
	 * @param string   $version Version.
	 * @param string   $media Media.
	 * @param boolean  $has_rtl RTL.
	 *
	 * @uses   wp_enqueue_style()
	 */
	private static function enqueue_style( $handle, $path = '', $deps = array(), $version = UR_VERSION, $media = 'all', $has_rtl = false ) {
		if ( ! in_array( $handle, self::$styles ) && $path ) {
			self::register_style( $handle, $path, $deps, $version, $media, $has_rtl );
		}
		wp_enqueue_style( $handle );
	}

	/**
	 * Register/queue my-account scripts.
	 */
	public static function load_my_account_scripts() {
		global $post;

		// CSS Styles.
		if ( $enqueue_styles = self::get_my_account_scripts() ) { //phpcs:ignore
			foreach ( $enqueue_styles as $handle => $args ) {
				if ( ! isset( $args['has_rtl'] ) ) {
					$args['has_rtl'] = false;
				}

				self::enqueue_style( $handle, $args['src'], $args['deps'], $args['version'], $args['media'], $args['has_rtl'] );
			}
		}
	}

	public static function get_my_account_scripts() {
		return apply_filters(
			'user_registration_enqueue_my_account_styles',
			array(
				/**
				 * Applies a filter to retrieve the breakpoint for small-screen styles.
				 *
				 * @param string $filter_name The name of the filter hook, 'user_registration_style_smallscreen_breakpoint'.
				 * @param string $breakpoint The default breakpoint value for small screens, in pixels.
				 *
				 * @return string The filtered breakpoint value for small-screen styles.
				 */
				'user-registration-smallscreen'       => array(
					'src'     => self::get_asset_url( 'assets/css/user-registration-smallscreen.css' ),
					'deps'    => '',
					'version' => UR_VERSION,
					'media'   => 'only screen and (max-width: ' . apply_filters( 'user_registration_style_smallscreen_breakpoint', $breakpoint = '768px' ) . ')',
					'has_rtl' => true,
				),
				'user-registration-my-account-layout' => array(
					'src'     => self::get_asset_url( 'assets/css/my-account-layout.css' ),
					'deps'    => '',
					'version' => UR_VERSION,
					'media'   => 'all',
				),
			)
		);
	}

	/**
	 * Localize scripts only when enqueued.
	 */
	public static function localize_printed_scripts() {
		foreach ( self::$scripts as $handle ) {
			self::localize_script( $handle );
		}
	}

	/**
	 * Localize a UR script once.
	 *
	 * @param string $handle Script Handler.
	 */
	private static function localize_script( $handle ) {
		if ( ! in_array( $handle, self::$wp_localize_scripts ) && wp_script_is( $handle ) && ( $data = self::get_script_data( $handle ) ) ) { //phpcs:ignore
			$name                        = str_replace( '-', '_', $handle ) . '_params';
			self::$wp_localize_scripts[] = $handle;
			/**
			 * Applies a filter to customize the localized data before it is passed to a script.
			 *
			 * This filter allows developers to modify the localized data associated with a specific script handle
			 * before it is passed to the corresponding script using wp_localize_script.
			 *
			 * @param string $filter_name The name of the filter hook, dynamically generated based on the script handle.
			 * @param array $data The default localized data obtained using self::get_script_data.
			 *
			 * @return array The filtered localized data to be passed to the script.
			 */
			wp_localize_script( $handle, $name, apply_filters( $name, $data ) );
		}
	}

	/**
	 * Return data for script handles.
	 *
	 * @param string $handle Script handler.
	 *
	 * @return array|bool
	 */
	private static function get_script_data( $handle ) {
		switch ( $handle ) {
			case 'user-registration':
				$regions                = get_option( 'user_registration_tax_regions_and_rates', array() );
				$tax_calculation_method = get_option( 'user_registration_tax_calculation_during_checkout', 'no' );
				$is_tax_calculation_enabled = ur_check_module_activation( 'taxes' );
				$currency               = get_option( 'user_registration_payment_currency', 'USD' );
				$currencies             = ur_payment_integration_get_currencies();
				$symbol                 = $currencies[ $currency ]['symbol'];

				return array(
					'ajax_url'                             => admin_url( 'admin-ajax.php' ),

					'user_registration_update_state_field' => wp_create_nonce( 'user_registration_update_state_field_nonce' ),
					'user_registration_profile_details_save' => wp_create_nonce( 'user_registration_profile_details_save_nonce' ),
					'user_registration_profile_picture_upload_nonce' => wp_create_nonce( 'user_registration_profile_picture_upload_nonce' ),
					'user_registration_profile_picture_remove_nonce' => wp_create_nonce( 'user_registration_profile_picture_remove_nonce' ),
					'form_required_fields'                 => ur_get_required_fields(),
					'login_option'                         => get_option( 'user_registration_general_setting_login_options' ),
					'recaptcha_type'                       => get_option( 'user_registration_captcha_setting_recaptcha_version', 'v2' ),
					'user_registration_profile_picture_uploading' => esc_html__( 'Uploading...', 'user-registration' ),
					'user_registration_profile_picture_removing' => esc_html__( 'Removing...', 'user-registration' ),
					'ajax_submission_on_edit_profile'      => ur_option_checked( 'user_registration_ajax_form_submission_on_edit_profile', false ),
					'message_required_fields'              => get_option( 'user_registration_form_submission_error_message_required_fields', esc_html__( 'This field is required.', 'user-registration' ) ),
					'message_email_fields'                 => get_option( 'user_registration_form_submission_error_message_email', esc_html__( 'Please enter a valid email address.', 'user-registration' ) ),
					'message_url_fields'                   => get_option( 'user_registration_form_submission_error_message_website_URL', esc_html__( 'Please enter a valid URL.', 'user-registration' ) ),
					'message_number_fields'                => get_option( 'user_registration_form_submission_error_message_number', esc_html__( 'Please enter a valid number.', 'user-registration' ) ),
					'message_confirm_password_fields'      => get_option( 'user_registration_form_submission_error_message_confirm_password', esc_html__( 'Password and confirm password not matched.', 'user-registration' ) ),
					'message_min_words_fields'             => get_option( 'user_registration_form_submission_error_message_min_words', esc_html__( 'Please enter at least %qty% words.', 'user-registration' ) ),
					'message_validate_phone_number'        => get_option( 'user_registration_form_submission_error_message_phone_number', esc_html__( 'Please enter a valid phone number.', 'user-registration' ) ),
					'message_username_character_fields'    => get_option( 'user_registration_form_submission_error_message_disallow_username_character', esc_html__( 'Please enter a valid username.', 'user-registration' ) ),
					'message_confirm_email_fields'         => get_option( 'user_registration_form_submission_error_message_confirm_email', esc_html__( 'Email and confirm email not matched.', 'user-registration' ) ),
					'message_confirm_number_field_max'     => esc_html__( 'Please enter a value less than or equal to %qty%.', 'user-registration' ),
					'message_confirm_number_field_min'     => esc_html__( 'Please enter a value greater than or equal to %qty%.', 'user-registration' ),
					'message_confirm_number_field_step'    => esc_html__( 'Please enter a multiple of %qty%.', 'user-registration' ),
					'message_min_length_fields'            => esc_html__( 'Please enter at least %qty% characters.', 'user-registration' ),
					'message_max_length_fields'            => esc_html__( 'Please enter no more than %qty% characters.', 'user-registration' ),
					'ursL10n'                              => array(
						'user_successfully_saved'     => get_option( 'user_registration_successful_form_submission_message_manual_registation', esc_html__( 'User successfully registered.', 'user-registration' ) ),
						'user_under_approval'         => get_option( 'user_registration_successful_form_submission_message_admin_approval', esc_html__( 'User registered. Wait until admin approves your registration.', 'user-registration' ) ),
						'user_email_pending'          => get_option( 'user_registration_successful_form_submission_message_email_confirmation', esc_html__( 'User registered. Verify your email by clicking on the link sent to your email.', 'user-registration' ) ),
						'captcha_error'               => get_option( 'user_registration_form_submission_error_message_recaptcha', esc_html__( 'Captcha code error, please try again.', 'user-registration' ) ),
						'captcha_pending'             => esc_html__( 'Verifying you are human. Please try again in a moment.', 'user-registration' ),
						'hide_password_title'         => esc_html__( 'Hide Password', 'user-registration' ),
						'show_password_title'         => esc_html__( 'Show Password', 'user-registration' ),
						'i18n_total_field_value_zero' => esc_html__( 'Total field value should be greater than zero.', 'user-registration' ),
						'i18n_discount_total_zero'    => esc_html__( 'Discounted amount cannot be less than or equals to Zero. Please adjust your coupon code.', 'user-registration' ),
						'password_strength_error'     => esc_html__( 'Password strength is not strong enough', 'user-registration' ),
					),
					'is_payment_compatible'                => true,
					'ur_hold_data_before_redirection'      => apply_filters( 'user_registration_hold_form_data_before_redirection', false ),
					'ajax_form_submit_error'               => is_admin() ? esc_html__( 'Form submission failed. This may be caused by a server or security setting blocking the request, a plugin conflict, or an unexpected technical issue. Please try again or check your site configuration if the problem persists.', 'user-registration' ) : esc_html__( 'Something went wrong while submitting the form. Please try again. If the issue continues, contact the site administrator for help', 'user-registration' ),
					'logout_popup_text'                    => esc_html__( 'Are you sure you want to logout?', 'user-registration' ),
					'logout_popup_cancel_text'             => esc_html__( 'Cancel', 'user-registration' ),
					'user_registration_checkbox_validation_message' => apply_filters( 'user_registration_checkbox_validation_message', esc_html__( 'Please select no more than {0} options.', 'user-registration' ) ),
					'user_registration_membership_renew_plan_button_text' => apply_filters( 'user_registration_membership_renew_plan_button_text', esc_html__( 'Change', 'user-registration' ) ),
					'network_error'                        => esc_html__( 'Network error', 'user-registration' ),
					'tax_calculation_method'               => ur_string_to_bool( $tax_calculation_method ),
					'regions_list'                         => $regions,
					'currency_symbol'                      => $symbol,
					'currency_pos'                         => isset( $currencies[ $currency ]['symbol_pos'] ) ? $currencies[ $currency ]['symbol_pos'] : 'left',
					'is_tax_calculation_activated'		   => ur_string_to_bool( $is_tax_calculation_enabled ),
				);
				break;

			case 'ur-password-strength-meter':
				return array(
					'home_url'             => home_url(),
					'i18n_password_error'  => esc_attr__( 'Please enter a stronger password.', 'user-registration' ),
					'pwsL10n'              => array(
						'shortpw'  => esc_html__( 'Very Weak', 'user-registration' ),
						'bad'      => esc_html__( 'Weak', 'user-registration' ),
						'good'     => esc_html__( 'Medium', 'user-registration' ),
						'strong'   => esc_html__( 'Strong', 'user-registration' ),
						'mismatch' => esc_html__( 'Password with confirm password not matched.', 'user-registration' ),

					),
					/**
					 * Applies a filter to customize the message displayed for strong password requirements.
					 *
					 * This filter allows developers to modify the default strong password message provided for user guidance.
					 *
					 * @param string $filter_name The name of the filter hook, 'user_registration_strong_password_message'.
					 * @param string $default_message The default message for strong password requirements, obtained using esc_html__().
					 */
					'i18n_password_hint'   => apply_filters( 'user_registration_strong_password_message', esc_html__( 'Hint: To make password stronger, use upper and lower case letters, numbers, and symbols like ! " ? $ % ^ & ).', 'user-registration' ) ),
					'i18n_password_hint_1' => esc_html__( 'Hint: Minimum one uppercase letter and must be 4 characters and no repetitive words or common words', 'user-registration' ),
					'i18n_password_hint_2' => esc_html__( 'Hint: Minimum one uppercase letter, a number, must be 7 characters and no repetitive words or common words', 'user-registration' ),
					'i18n_password_hint_3' => apply_filters( 'user_registration_password_hint3_message', esc_html__( 'Hint: Minimum one uppercase letter, a number, a special character, must be 9 characters and no repetitive words or common words', 'user-registration' ) ),
				);
				break;
			case 'ur-login':
				return array(
					'ajax_url'                    => admin_url( 'admin-ajax.php' ),
					'ur_login_form_save_nonce'    => wp_create_nonce( 'ur_login_form_save_nonce' ),
					'ajax_submission_on_ur_login' => ur_option_checked( 'ur_login_ajax_submission', false ),
					'recaptcha_type'              => get_option( 'user_registration_login_options_configured_captcha_type', get_option( 'user_registration_captcha_setting_recaptcha_version', 'v2' ) ),
					'ajax_form_submit_error'      => esc_html__( 'Something went wrong while submitting form through AJAX request. Please contact site administrator.', 'user-registration' ),
				);
				break;
		}

		return false;
	}

	public static function output_dynamic_primary_color() {
		$primary_color           = get_option( 'user_registration_style_setting_primary_color', '#475bb2' );
		$button_text_color       = get_option(
			'user_registration_style_setting_button_text_colors',
			array(
				'normal' => '#FFFFFF',
				'hover'  => '#FFFFFF',
			)
		);
		$button_background_color = get_option(
			'user_registration_style_setting_button_background_colors',
			array(
				'normal' => '#475bb2',
				'hover'  => '#38488e',
			)
		);

		$css_props = array();

		// Primary color + variants.
		if ( ! empty( $primary_color ) ) {
			$primary_dark  = self::adjust_brightness( $primary_color, -10 );
			$primary_light = self::adjust_brightness( $primary_color, 1300 );

			$css_props[] = sprintf( '--ur-primary-color: %s;', esc_attr( $primary_color ) );
			$css_props[] = sprintf( '--ur-primary-dark: %s;', esc_attr( $primary_dark ) );
			$css_props[] = sprintf( '--ur-primary-light: %s;', esc_attr( $primary_light ) );
		}

		// Button text colors: accept array or serialized/string value.
		if ( ! empty( $button_text_color ) ) {
			if ( ! is_array( $button_text_color ) ) {
				$maybe_unserialized = maybe_unserialize( $button_text_color );
				if ( is_array( $maybe_unserialized ) ) {
					$button_text_color = $maybe_unserialized;
				}
			}

			if ( is_array( $button_text_color ) ) {
				$normal = isset( $button_text_color['normal'] ) ? $button_text_color['normal'] : '';
				$hover  = isset( $button_text_color['hover'] ) ? $button_text_color['hover'] : '';

				if ( '' !== $normal ) {
					$css_props[] = sprintf( '--ur-button-text-normal-color: %s;', esc_attr( $normal ) );
				}
				if ( '' !== $hover ) {
					$css_props[] = sprintf( '--ur-button-text-hover-color: %s;', esc_attr( $hover ) );
				}
			}
		}

		if ( ! empty( $button_background_color ) ) {
			if ( ! is_array( $button_background_color ) ) {
				$maybe_unserialized = maybe_unserialize( $button_background_color );
				if ( is_array( $maybe_unserialized ) ) {
					$button_background_color = $maybe_unserialized;
				}
			}

			if ( is_array( $button_background_color ) ) {
				$bg_normal = isset( $button_background_color['normal'] ) ? $button_background_color['normal'] : '';
				$bg_hover  = isset( $button_background_color['hover'] ) ? $button_background_color['hover'] : '';

				if ( '' !== $bg_normal ) {
					$css_props[] = sprintf( '--ur-button-background-normal-color: %s;', esc_attr( $bg_normal ) );
				}
				if ( '' !== $bg_hover ) {
					$css_props[] = sprintf( '--ur-button-background-hover-color: %s;', esc_attr( $bg_hover ) );
				}
			}
		}

		// Output single style block if any properties were added.
		if ( ! empty( $css_props ) ) {
			$css = ":root {\n\t" . implode( "\n\t", $css_props ) . "\n}";
			echo '<style id="ur-dynamic-colors">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Adjust brightness of a hex color.
	 *
	 * @param string $hex Hex color code.
	 * @param int    $percent Percentage to adjust (-100 to 100).
	 * @return string Adjusted hex color.
	 */
	private static function adjust_brightness( $hex, $percent ) {
		$rgb    = self::hex_to_rgb( $hex );
		$rgb[0] = max( 0, min( 255, $rgb[0] + ( $rgb[0] * $percent / 100 ) ) );
		$rgb[1] = max( 0, min( 255, $rgb[1] + ( $rgb[1] * $percent / 100 ) ) );
		$rgb[2] = max( 0, min( 255, $rgb[2] + ( $rgb[2] * $percent / 100 ) ) );
		return '#' . str_pad( dechex( round( $rgb[0] ) ), 2, '0', STR_PAD_LEFT ) .
				str_pad( dechex( round( $rgb[1] ) ), 2, '0', STR_PAD_LEFT ) .
				str_pad( dechex( round( $rgb[2] ) ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Convert hex color to RGB array.
	 *
	 * @param string $hex Hex color code.
	 * @return array RGB values.
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}

UR_Frontend_Scripts::init();
