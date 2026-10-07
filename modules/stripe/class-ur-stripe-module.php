<?php
/**
 * User_Registration_Stripe_Module
 *
 * @package  User_Registration_Stripe_Module
 * @since  4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class User_Registration_Stripe_Module
 */
class User_Registration_Stripe_Module {

	/**
	 * User_Registration_Stripe_Module Constructor
	 */
	public function __construct() {

		// Stripe Settings Hooks.
		if ( is_admin() ) {
			// Filter for global settings.
			add_filter( 'user_registration_payment_settings', array( $this, 'get_stripe_settings' ) );
			add_filter(
				'urm_validate_stripe_payment_section_before_update',
				array(
					$this,
					'validate_stripe_section',
				)
			);
			add_action( 'urm_save_stripe_payment_section', array( $this, 'save_section_settings' ), 10, 1 );
		}
	}

	/**
	 * Raw Settings.
	 *
	 * @return array
	 */
	public function raw_settings() {
		$stripe_enabled = get_option( 'user_registration_stripe_enabled', '' );

		// Determine default toggle value based on urm_is_new_installation option.
		$stripe_toggle_default = ur_string_to_bool( get_option( 'urm_is_new_installation', false ) );

		return array(
			'id'           => 'stripe',
			'title'        => __( 'Stripe Settings', 'user-registration' ),
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
					'default'  => ( $stripe_enabled ) ? $stripe_enabled : $stripe_toggle_default,
					'class'    => 'urm_toggle_pg_status',
				),
				array(
					'title'    => __( 'Test Publishable key', 'user-registration' ),
					'desc'     => __( 'Stripe test publishable  key.', 'user-registration' ),
					'id'       => 'user_registration_stripe_test_publishable_key',
					'type'     => 'text',
					'css'      => 'min-width: 350px',
					'desc_tip' => true,
					'default'  => '',
				),
				array(
					'title'    => __( 'Test Secret key', 'user-registration' ),
					'desc'     => __( 'Stripe test secret key.', 'user-registration' ),
					'id'       => 'user_registration_stripe_test_secret_key',
					'type'     => 'password',
					'css'      => 'min-width: 350px',
					'desc_tip' => true,
					'default'  => '',
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
					'title'    => __( 'Live Publishable Key', 'user-registration' ),
					'desc'     => __( 'Stripe live publishable key.', 'user-registration' ),
					'id'       => 'user_registration_stripe_live_publishable_key',
					'type'     => 'text',
					'css'      => 'min-width: 350px',
					'desc_tip' => true,
					'default'  => '',
				),
				array(
					'title'    => __( 'Live Secret key', 'user-registration' ),
					'desc'     => __( 'Stripe live secret key.', 'user-registration' ),
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

	/**
	 * Get Stripe Global Settings.
	 *
	 * @param array $settings settings.
	 */
	public function get_stripe_settings( $settings ) {
		$stripe_settings                        = $this->raw_settings();
		$settings['sections']['stripe_options'] = $stripe_settings;

		return $settings;
	}

	/**
	 * Validate stripe keys.
	 *
	 * @param array $form_data Form data with stripe creds.
	 *
	 * @return true[]
	 * @throws \Stripe\Exception\ApiErrorException Api Error Exception.
	 */
	public function validate_stripe_section( $form_data ) {
		$changed  = false;
		$response = array(
			'status'    => true,
			'connected' => true,
		);
		if ( isset( $form_data['user_registration_stripe_enabled'] ) && ! $form_data['user_registration_stripe_enabled'] ) {
			return $response;
		}
		foreach ( $form_data as $k => $data ) {
			$last_data = get_option( $k );
			if ( $last_data !== $data ) {
				$changed = true;
				break;
			}
		}

		if ( $changed ) {
			$mode            = isset( $form_data['user_registration_stripe_test_mode'] ) ? ( ( true === $form_data['user_registration_stripe_test_mode'] ) ? 'test' : 'live' ) : 'test';
			$publishable_key = $form_data[ sprintf( 'user_registration_stripe_%s_publishable_key', $mode ) ];
			$secret_key      = $form_data[ sprintf( 'user_registration_stripe_%s_secret_key', $mode ) ];

			if ( empty( $secret_key ) ) {
				$response['status']  = false;
				$response['message'] = esc_html__( 'Stripe secret key is missing.', 'user-registration' );
				return $response;
			}

			// Validate publishable key is present.
			if ( empty( $publishable_key ) ) {
				$response['status']  = false;
				$response['message'] = esc_html__( 'Stripe publishable key is missing.', 'user-registration' );
				return $response;
			}

			// Validate publishable key prefix matches the selected mode.
			$expected_pk_prefix = ( 'test' === $mode ) ? 'pk_test_' : 'pk_live_';
			if ( strpos( $publishable_key, $expected_pk_prefix ) !== 0 ) {
				$response['status']  = false;
				$response['message'] = ( 'test' === $mode )
					? esc_html__( 'Invalid Stripe test publishable key. It must start with pk_test_.', 'user-registration' )
					: esc_html__( 'Invalid Stripe live publishable key. It must start with pk_live_.', 'user-registration' );
				return $response;
			}

			// Verify the publishable key against the Stripe API.
			// POST /v1/tokens is a publishable-key-accessible endpoint:
			//   HTTP 401 → key does not exist in Stripe (invalid)
			//   HTTP 400 → key is valid, request just lacks required card fields
			$stripe_pk_check = wp_remote_post(
				'https://api.stripe.com/v1/tokens',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $publishable_key,
					),
					'body'    => array(),
					'timeout' => 10,
				)
			);

			if ( ! is_wp_error( $stripe_pk_check ) ) {
				$pk_response_code = wp_remote_retrieve_response_code( $stripe_pk_check );
				if ( 401 === $pk_response_code ) {
					$response['status']    = false;
					$response['connected'] = false;
					$response['message']   = esc_html__( 'Invalid Stripe publishable key. Please verify the key and try again.', 'user-registration' );
					return $response;
				}
			}

			// Detect mode from key.
			if ( strpos( $secret_key, 'sk_test_' ) === 0 ) {
				if ( 'live' === $mode ) {
					$response['status']  = false;
					$response['message'] = esc_html__( 'Test key used while Live mode is selected.', 'user-registration' );
					return $response;
				}
			}

			\Stripe\Stripe::setApiKey( $secret_key );

			try {
				$customers = \Stripe\Customer::all( array( 'limit' => 1 ) );
			} catch ( \Stripe\Exception\AuthenticationException $e ) {
				$response['status']    = false;
				$response['connected'] = false;
				$response['message']   = esc_html__( 'Invalid Stripe secret key. Please verify the key and try again.', 'user-registration' );
			}
		}

		return $response;
	}

	/**
	 * Save stripe section settings.
	 *
	 * @param array $form_data Form data.
	 * @return void
	 */
	public function save_section_settings( $form_data ) {
		$section = $this->raw_settings();
		ur_save_settings_options( $section, $form_data );
		if ( ! class_exists( 'WPEverest\URMembership\Admin\Services\Stripe\StripeService' ) ) {
			return;
		}
		foreach ( array( 'test', 'live' ) as $mode ) {
			$secret = get_option( 'user_registration_stripe_' . $mode . '_secret_key', '' );
			if ( empty( $secret ) ) {
				continue;
			}
			$result = \WPEverest\URMembership\Admin\Services\Stripe\StripeService::create_webhook( $mode );
			if ( ! empty( $result['success'] ) && class_exists( 'WPEverest\URMembership\Admin\Services\PaymentGatewayLogging' ) ) {
				\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
					'stripe',
					'Webhook created or verified for ' . $mode . ' mode',
					'notice',
					array(
						'event_type' => 'webhook_save',
						'mode'       => $mode,
					)
				);
			}
		}

		do_action( 'user_registration_after_stripe_settings_updated', $form_data );
	}
}

new User_Registration_Stripe_Module();
