<?php
/**
 * Class UR_Settings_Payment
 *
 * Handles the payment related settings for the User Registration & Membership plugin.
 *
 * This class is responsible for:
 *
 * @package   UserRegistration\Admin
 * @version   5.0.0
 * @since     5.0.0
 */
if ( ! class_exists( 'UR_Settings_Payment' ) ) {
	/**
	 * UR_Settings_Payment Class
	 */
	class UR_Settings_Payment extends UR_Settings_Page {
		private static $_instance = null;
		/**
		 * Constructor.
		 */
		private function __construct() {
			$this->id    = 'payment';
			$this->label = __( 'Payment', 'user-registration' );
			parent::__construct();
			$this->handle_hooks();
		}
		public static function get_instance() {
			if ( null === self::$_instance ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}
		/**
		 * Register hooks for submenus and section UI.
		 *
		 * @return void
		 */
		public function handle_hooks() {
			add_filter( "user_registration_get_sections_{$this->id}", array( $this, 'get_sections_callback' ), 1, 1 );
			add_filter( "user_registration_get_settings_{$this->id}", array( $this, 'get_settings_callback' ), 1, 1 );

			add_filter(
				'urm_validate_bank_payment_section_before_update',
				array(
					$this,
					'validate_bank_section',
				)
			);

			add_action( 'urm_save_bank_payment_section', array( $this, 'save_section_settings' ), 10, 1 );
		}

		public function validate_bank_section( $form_data ) {
			$response = array(
				'status' => true,
			);
			if ( isset( $form_data['user_registration_bank_enabled'] ) && ! $form_data['user_registration_bank_enabled'] ) {
				return $response;
			}
			if ( empty( $form_data['user_registration_global_bank_details'] ) ) {
				$response['status']  = false;
				$response['message'] = 'Bank details cannot be empty';
				return $response;
			}

			return $response;
		}

		public function save_section_settings( $form_data ) {
			$section = $this->get_bank_transfer_settings();
			ur_save_settings_options( $section, $form_data );
		}

		/**
		 * Filter to provide sections submenu for payment settings.
		 */
		public function get_sections_callback( $sections ) {
			$sections['payment-method'] = __( 'Payment Method', 'user-registration' );
			$sections['store']          = __( 'Store', 'user-registration' );
			return $sections;
		}
		/**
		 * Filter to provide sections UI for payment settings.
		 */
		public function get_settings_callback( $settings ) {
			global $current_section;
			if ( ! in_array( $current_section, array( 'payment-method', 'store', 'tax-vat', 'invoices', 'payment-retry' ) ) ) {
				return;
			}
			$general_settings       = $this->get_general_settings();
			$paypal_settings        = $this->get_paypal_settings();
			$stripe_settings        = $this->get_stripe_settings();
			$bank_transfer_settings = $this->get_bank_transfer_settings();
			if ( ! UR_PRO_ACTIVE ) {
				$authorize_net_free_settings = $this->get_free_authorize_net_transfer_settings();
				$mollie_free_settings        = $this->get_free_mollie_transfer_settings();
			}
			if ( 'payment-method' === $current_section ) {
				add_filter( 'user_registration_settings_hide_save_button', '__return_true' );
				$settings = array(
					'title'    => '',
					'sections' => array(
						'paypal_options'        => $paypal_settings,
						'stripe_options'        => $stripe_settings,
						'bank_transfer_options' => $bank_transfer_settings,
					),
				);
				if ( ! UR_PRO_ACTIVE ) {
					$settings['sections']['mollie_free_options']        = $mollie_free_settings;
					$settings['sections']['authorize_net_free_options'] = $authorize_net_free_settings;
				}
				/* Backward compatibility */
				$settings = apply_filters( 'user_registration_payment_settings', $settings );
			} elseif ( 'store' === $current_section ) {
				add_filter( 'user_registration_settings_hide_save_button', '__return_true' );
				$settings = array(
					'title'    => '',
					'sections' => array(
						'general_options' => $general_settings,
					),
				);
			} else {
				$settings = $this->upgrade_to_pro_setting();
			}
			return $settings;
		}
		/**
		 * Function to get general Settings
		 */
		public function get_general_settings() {
			$currencies      = ur_payment_integration_get_currencies();
			$currencies_list = array();

			// Break and concatenate the currency symbol and code.
			foreach ( $currencies as $code => $currency ) {
				$currencies_list[ $code ] = $currency['name'] . ' ( ' . $code . ' ' . $currency['symbol'] . ' )';
			}

			$settings = array(
				'id'          => 'payment-settings',
				'title'       => esc_html__( 'Store', 'user-registration' ),
				'type'        => 'card',
				'desc'        => '',
				'show_status' => false,
				'show_logo'   => false,
				'settings'    => array(
					array(
						'title'    => __( 'Currency', 'user-registration' ),
						'desc'     => __( 'This option lets you choose currency for payments.', 'user-registration' ),
						'id'       => 'user_registration_payment_currency',
						'default'  => 'USD',
						'type'     => 'select',
						'class'    => 'ur-enhanced-select',
						'css'      => '',
						'desc_tip' => true,
						'options'  => $currencies_list,
					),
					array(
						'title' => __( 'Save', 'user-registration' ),
						'id'    => 'user_registration_payment_save_settings',
						'type'  => 'button',
						'class' => 'payment-settings-btn',
					),
				),
			);
			return apply_filters( 'user_registration_payment_settings', $settings );
		}

		public function get_paypal_settings() {

			$test_admin_email   = get_option( 'user_registration_global_paypal_test_admin_email', '' );
			$test_client_id     = get_option( 'user_registration_global_paypal_test_client_id', '' );
			$test_client_secret = get_option( 'user_registration_global_paypal_test_client_secret', '' );

			$live_admin_email   = get_option( 'user_registration_global_paypal_live_admin_email', '' );
			$live_client_id     = get_option( 'user_registration_global_paypal_live_client_id', '' );
			$live_client_secret = get_option( 'user_registration_global_paypal_live_client_secret', '' );

			$paypal_mode    = get_option( 'user_registration_global_paypal_mode', '' );
			$paypal_enabled = get_option( 'user_registration_paypal_enabled', '' );

			if ( false === get_option( 'urm_global_paypal_settings_migrated_', false ) ) {
				// runs for backward compatibility, could be removed in future versions.
				if ( 'test' === $paypal_mode ) {
					$test_admin_email   = get_option( 'user_registration_global_paypal_email_address', '' );
					$test_client_id     = get_option( 'user_registration_global_paypal_client_id', '' );
					$test_client_secret = get_option( 'user_registration_global_paypal_client_secret', '' );
				} else {
					$live_admin_email   = get_option( 'user_registration_global_paypal_email_address', '' );
					$live_client_id     = get_option( 'user_registration_global_paypal_client_id', '' );
					$live_client_secret = get_option( 'user_registration_global_paypal_client_secret', '' );
				}
			}

			$is_old_paypal_install = ur_is_paypal_old_installation();

			return array(
				'title'        => __( 'PayPal', 'user-registration' ),
				'type'         => 'accordian',
				'id'           => 'paypal',
				'desc'         => '',
				'is_connected' => get_option( 'urm_paypal_connection_status', false ),
				'settings'     => array_merge(
					array(
						array(
							'type'     => 'toggle',
							'title'    => __( 'Enable PayPal', 'user-registration' ),
							'desc'     => __( 'Enable PayPal payment gateway.', 'user-registration' ),
							'id'       => 'user_registration_paypal_enabled',
							'desc_tip' => true,
							'default'  => ( $paypal_enabled ) ? $paypal_enabled : $is_old_paypal_install,
							'class'    => 'urm_toggle_pg_status',
						),
						array(
							'id'       => 'user_registration_global_paypal_mode',
							'type'     => 'select',
							'title'    => __( 'Mode', 'user-registration' ),
							'desc'     => __( 'Select a mode to run paypal.', 'user-registration' ),
							'desc_tip' => true,
							'options'  => array(
								'production' => __( 'Production', 'user-registration' ),
								'test'       => __( 'Test/Sandbox', 'user-registration' ),
							),
							'class'    => 'ur-enhanced-select',
							'default'  => $paypal_mode,
						),
					),
					$is_old_paypal_install ? array(
						array(
							'type'        => 'text',
							'title'       => __( 'Cancel Url', 'user-registration' ),
							'desc'        => __( 'Endpoint set for handling paypal cancel api.', 'user-registration' ),
							'desc_tip'    => true,
							'id'          => 'user_registration_global_paypal_cancel_url',
							'default'     => get_option( 'user_registration_global_paypal_cancel_url' ),
							'placeholder' => esc_url( home_url() ),
						),
						array(
							'type'        => 'text',
							'title'       => __( 'Return Url', 'user-registration' ),
							'desc'        => __( 'Redirect url after the payment process, also used as notify_url for Paypal IPN.', 'user-registration' ),
							'desc_tip'    => true,
							'id'          => 'user_registration_global_paypal_return_url',
							'default'     => get_option( 'user_registration_global_paypal_return_url' ),
							'placeholder' => esc_url( wp_login_url() ),
						),
						array(
							'type'        => 'text',
							'title'       => __( 'PayPal Email Address', 'user-registration' ),
							'desc'        => __( 'Enter your PayPal email address in sandbox/test mode.', 'user-registration' ),
							'desc_tip'    => true,
							'id'          => 'user_registration_global_paypal_test_email_address',
							'default'     => $test_admin_email,
							'placeholder' => $test_admin_email,
						),
						array(
							'type'        => 'text',
							'title'       => __( 'PayPal Email Address', 'user-registration' ),
							'desc'        => __( 'Enter your PayPal email address.', 'user-registration' ),
							'desc_tip'    => true,
							'id'          => 'user_registration_global_paypal_live_email_address',
							'default'     => $live_admin_email,
							'placeholder' => $live_admin_email,
						),
					) : array(),
					array(
						array(
							'type'     => 'text',
							'title'    => __( 'Client ID', 'user-registration' ),
							'desc'     => __( 'Client ID for PayPal in sandbox/test mode.', 'user-registration' ),
							'desc_tip' => true,
							'id'       => 'user_registration_global_paypal_test_client_id',
							'default'  => $test_client_id,
						),
						array(
							'type'     => 'password',
							'title'    => __( 'Client Secret', 'user-registration' ),
							'desc'     => __( 'Client Secret for PayPal in sandbox/test mode.', 'user-registration' ),
							'desc_tip' => true,
							'id'       => 'user_registration_global_paypal_test_client_secret',
							'default'  => $test_client_secret,
						),
						array(
							'type'     => 'text',
							'title'    => __( 'Client ID', 'user-registration' ),
							'desc'     => __( 'Your client_id, Required for subscription related operations.', 'user-registration' ),
							'desc_tip' => true,
							'id'       => 'user_registration_global_paypal_live_client_id',
							'default'  => $live_client_id,
						),
						array(
							'type'     => 'password',
							'title'    => __( 'Client Secret', 'user-registration' ),
							'desc'     => __( 'Your client_secret, Required for subscription related operations.', 'user-registration' ),
							'desc_tip' => true,
							'id'       => 'user_registration_global_paypal_live_client_secret',
							'default'  => $live_client_secret,
						),
						array(
							'title' => __( 'Save', 'user-registration' ),
							'id'    => 'user_registration_paypal_save_settings',
							'type'  => 'button',
							'class' => 'payment-settings-btn',
						),
					)
				),
			);
		}
		public function get_stripe_settings() {
			$stripe_enabled = get_option( 'user_registration_stripe_enabled', '' );

			// Determine default toggle value based on urm_is_new_installation option
			$stripe_toggle_default = ur_string_to_bool( get_option( 'urm_is_new_installation', false ) );

			return array(
				'id'           => 'stripe',
				'title'        => __( 'Stripe', 'user-registration' ),
				'type'         => 'accordian',
				'show_status'  => false,
				'desc'         => '',
				'is_connected' => get_option( 'urm_stripe_connection_status', false ),
				'settings'     => array(
					array(
						'type'     => 'toggle',
						'title'    => __( 'Enable Stripe', 'user-registration' ),
						'desc'     => __( 'Enable Stripe payment gateway.', 'user-registration' ),
						'id'       => 'user_registration_stripe_enabled',
						'desc_tip' => true,
						'default'  => ( $stripe_enabled ) ? $stripe_enabled : ! $stripe_toggle_default,
						'class'    => 'urm_toggle_pg_status',
					),
					array(
						'type'     => 'toggle',
						'title'    => __( 'Enable Test Mode', 'user-registration' ),
						'desc'     => __( 'Check if using test mode.', 'user-registration' ),
						'id'       => 'user_registration_stripe_test_mode',
						'desc_tip' => true,
						'default'  => '',
					),
					array(
						'title'    => __( 'Test Publishable key', 'user-registration' ),
						'desc'     => __( 'Stripe publishable key in test mode.', 'user-registration' ),
						'id'       => 'user_registration_stripe_test_publishable_key',
						'type'     => 'text',
						'css'      => 'min-width: 350px',
						'desc_tip' => true,
						'default'  => '',
					),
					array(
						'title'    => __( 'Test Secret key', 'user-registration' ),
						'desc'     => __( 'Stripe secret key in test mode.', 'user-registration' ),
						'id'       => 'user_registration_stripe_test_secret_key',
						'type'     => 'password',
						'css'      => 'min-width: 350px',
						'desc_tip' => true,
						'default'  => '',
					),
					array(
						'title'    => __( 'Live Publishable Key', 'user-registration' ),
						'desc'     => __( 'Stripe publishable key in live mode.', 'user-registration' ),
						'id'       => 'user_registration_stripe_live_publishable_key',
						'type'     => 'text',
						'css'      => 'min-width: 350px',
						'desc_tip' => true,
						'default'  => '',
					),
					array(
						'title'    => __( 'Live Secret key', 'user-registration' ),
						'desc'     => __( 'Stripe secret key in live mode.', 'user-registration' ),
						'id'       => 'user_registration_stripe_live_secret_key',
						'type'     => 'password',
						'css'      => 'min-width: 350px',
						'desc_tip' => true,
						'default'  => '',
					),
					array(
						'title' => __( 'Save', 'user-registration' ),
						'id'    => 'user_registration_stripe_save_settings',
						'type'  => 'button',
						'class' => 'payment-settings-btn',
					),
				),
			);
		}

		public function get_bank_transfer_settings() {
			$bank_transfer_enabled = get_option( 'user_registration_bank_enabled', '' );

			// Determine default toggle value based on urm_is_new_installation option
			$bank_toggle_default = ur_string_to_bool( get_option( 'urm_is_new_installation', false ) );

			return array(
				'id'           => 'bank',
				'title'        => __( 'Bank Transfer', 'user-registration' ),
				'type'         => 'accordian',
				'desc'         => '',
				'is_connected' => get_option( 'urm_bank_connection_status', false ),
				'settings'     => array(
					array(
						'type'     => 'toggle',
						'title'    => __( 'Enable Bank Transfer', 'user-registration' ),
						'desc'     => __( 'Enable Bank Transfer payment gateway.', 'user-registration' ),
						'id'       => 'user_registration_bank_enabled',
						'desc_tip' => true,
						'default'  => ( $bank_transfer_enabled ) ? $bank_transfer_enabled : ! $bank_toggle_default,
						'class'    => 'urm_toggle_pg_status',
					),
					array(
						'title'    => __( 'Enter your details', 'user-registration' ),
						'desc'     => __( 'Field to add necessary bank details which will be shown to users after successful payment using the bank option during checkout.', 'user-registration' ),
						'id'       => 'user_registration_global_bank_details',
						'type'     => 'tinymce',
						'default'  => get_option( 'user_registration_global_bank_details' ),
						'css'      => '',
						'desc_tip' => true,
					),
					array(
						'title' => __( 'Save', 'user-registration' ),
						'id'    => 'user_registration_bank_save_settings',
						'type'  => 'button',
						'class' => 'payment-settings-btn',
					),
				),
			);
		}

		public function get_free_mollie_transfer_settings() {

			return array(
				'id'           => 'free-mollie',
				'title'        => __( 'Mollie', 'user-registration' ),
				'type'         => 'accordian',
				'show_status'  => true,
				'class'        => 'urm-mollie-settings',
				'desc'         => '',
				'is_connected' => get_option( 'urm_mollie_connection_status', false ),
				'settings'     => array(),
			);
		}

		public function get_free_authorize_net_transfer_settings() {

			return array(
				'id'           => 'free-authorize-net',
				'title'        => __( 'Authorize.net', 'user-registration' ),
				'type'         => 'accordian',
				'show_status'  => true,
				'class'        => 'urm-mollie-settings',
				'desc'         => '',
				'is_connected' => get_option( 'urm_authorize_net_connection_status', false ),
				'settings'     => array(),
			);
		}
	}
}

// Backward Compatibility.
return method_exists( 'UR_Settings_Payment', 'get_instance' ) ? UR_Settings_Payment::get_instance() : new UR_Settings_Payment();
