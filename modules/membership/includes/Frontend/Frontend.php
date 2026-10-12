<?php
/**
 * URMembership Frontend.
 *
 * @class    Frontend
 * @package  URMembership/Frontend
 * @category Frontend
 * @author   WPEverest
 */

namespace WPEverest\URMembership\Frontend;

use WPEverest\URMembership\Admin\Services\MembershipService;
use WPEverest\URMembership\Admin\Services\Stripe\StripeService;
use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend Class
 */
class Frontend {

	/**
	 * Membership type stored for plans that need no payment.
	 */
	private const FREE_MEMBERSHIP_TYPE = 'free';

	/**
	 * Hook in tabs.
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'wp_enqueue_membership_scripts', array( $this, 'load_scripts' ), 10, 2 );

		add_action( 'template_redirect', array( $this, 'set_thank_you_transient' ) );
		add_action( 'wp_loaded', array( $this, 'clear_upgrade_data' ) );
		add_action( 'user_registration_before_register_user_action', array( $this, 'validate_stripe_card_before_register' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'reconcile_membership_role' ), 10, 2 );
	}

	// UR-4710: Grant a deferred paid-membership role once the member logs in with an active subscription.
	public function reconcile_membership_role( $user_login, $user ) {
		if ( $user instanceof \WP_User ) {
			( new \WPEverest\URMembership\Admin\Services\MembersService() )->maybe_grant_pending_role( $user->ID );
		}
	}

	/**
	 * Delete Account insert after helper.
	 *
	 * @param mixed $items Items.
	 * @param mixed $new_items New items.
	 * @param mixed $before Before item.
	 */
	public function delete_account_insert_before_helper( $items, $new_items, $before ) {

		// Search for the item position.
		$position = array_search( $before, array_keys( $items ), true );

		// Insert the new item.
		$return_items  = array_slice( $items, 0, $position, true );
		$return_items += $new_items;
		$return_items += array_slice( $items, $position, count( $items ) - $position, true );

		return $return_items;
	}

	/**
	 * Membership tab content.
	 */
	public function user_registration_membership_tab_endpoint_content( $membership_data ) {

		if ( ur_check_module_activation( 'membership' ) ) {
			$this->load_scripts();
		}

		$current_page = 1;

		if ( isset( $_GET['paged'] ) && intval( $_GET['paged'] ) > 0 ) {
			$current_page = intval( $_GET['paged'] );
		} else {
			$request_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
			$segments     = explode( '/', $request_path );

			$page_index = array_search( 'page', $segments );
			if ( false !== $page_index && isset( $segments[ $page_index + 1 ] ) ) {
				$current_page = max( 1, intval( $segments[ $page_index + 1 ] ) );
			}
		}

		$per_page = 10;

		$total_count = count( $membership_data );
		$page        = max( 1, intval( $current_page ) );
		$per_page    = max( 1, intval( $per_page ) );
		$offset      = ( $page - 1 ) * $per_page;
		$items       = array_slice( $membership_data, $offset, $per_page );

		$layout = get_option( 'user_registration_my_account_layout', 'vertical' );

		if ( 'vertical' === $layout && isset( ur_get_account_menu_items()['ur-membership'] ) ) {
			?>
			<div class="user-registration-MyAccount-content__header">
				<h1><?php echo wp_kses_post( ur_get_account_menu_items()['ur-membership'] ); ?></h1>
			</div>
			<?php
		}

		$membership_service = new MembershipService();

		ur_get_template(
			'myaccount/membership.php',
			array(
				'membership_data'    => $items,
				'membership_service' => $membership_service,
				'total_items'        => $total_count,
				'page'               => $page,
				'per_page'           => $per_page,
				'total_pages'        => ( $per_page > 0 ) ? (int) ceil( $total_count / $per_page ) : 1,
			)
		);
	}

	/**
	 * Whether Stripe's script is needed: Stripe is enabled with a publishable key and at least one active paid or subscription plan can be bought.
	 *
	 * @return bool
	 */
	private function is_stripe_script_needed() {
		$stripe_settings = StripeService::get_stripe_settings();

		if ( ! ur_string_to_bool( $stripe_settings['is_stipe_enabled'] ) || empty( $stripe_settings['publishable_key'] ) ) {
			return false;
		}

		foreach ( ( new MembershipRepository() )->get_all_membership() as $membership ) {
			if ( ! empty( $membership['meta_value']['type'] ) && self::FREE_MEMBERSHIP_TYPE !== $membership['meta_value']['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueue scripts
	 *
	 * @since 1.0.0
	 */
	public function load_scripts() {

		// Enqueue frontend scripts here.
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script( 'sweetalert2' );

		wp_register_script( 'user-registration-membership-frontend-script', UR()->plugin_url() . '/assets/js/modules/membership/frontend/user-registration-membership-frontend' . $suffix . '.js', array( 'jquery' ), UR_VERSION, true );
		if ( $this->is_stripe_script_needed() ) {
			wp_enqueue_script( 'user-registration-membership-stripe-v3', 'https://js.stripe.com/v3/', array() );
		}
		wp_enqueue_script( 'user-registration-membership-frontend-script' );
		// Enqueue frontend styles here.
		wp_register_style( 'user-registration-membership-frontend-style', UR()->plugin_url() . '/assets/css/modules/membership/user-registration-membership-frontend.css', array(), UR_VERSION );
		wp_enqueue_style( 'user-registration-membership-frontend-style' );
		$this->localize_scripts();
	}

	/**
	 * Localize the frontend scripts with necessary data.
	 *
	 * This function uses the wp_localize_script function to add a JavaScript object
	 * to the frontend script. The object contains several properties:
	 * - `_nonce`: A WordPress nonce generated using wp_create_nonce function.
	 * - `ajax_url`: The URL of the admin-ajax.php file generated using admin_url function.
	 * - `login_url`: The URL of the login page generated using wp_login_url function.
	 * - `labels`: An array of internationalized labels generated by the get_i18_labels method.
	 *
	 * @return void
	 */
	public function localize_scripts() {
		$currency             = get_option( 'user_registration_payment_currency', 'USD' );
		$currencies           = ur_payment_integration_get_currencies();
		$symbol               = $currencies[ $currency ]['symbol'];
		$registration_page_id = get_option( 'user_registration_member_registration_page_id' );

		$regions                = get_option( 'user_registration_tax_regions_and_rates', array() );
		$tax_calculation_method = get_option( 'user_registration_tax_calculation_during_checkout', 'no' );

		$is_tax_calculation_enabled = ur_check_module_activation( 'taxes' );

		$redirect_page_url = get_permalink( $registration_page_id );

		$thank_you_page  = urm_get_thank_you_page();
		$stripe_settings = \WPEverest\URMembership\Admin\Services\Stripe\StripeService::get_stripe_settings();

		$urm_authorize_net_supported_currencies = function_exists( 'urm_authorize_net_supported_currencies' ) ? urm_authorize_net_supported_currencies() : array();
		$paypal_supported_currencies_list       = function_exists( 'paypal_supported_currencies_list' ) ? paypal_supported_currencies_list() : array();
		$mollie_supported_currencies_list       = (
				class_exists( '\WPEverest\URM\Mollie\Functions\CoreFunctions' ) &&
				method_exists( '\WPEverest\URM\Mollie\Functions\CoreFunctions', 'mollie_supported_currencies_list' )
			)
				? \WPEverest\URM\Mollie\Functions\CoreFunctions::mollie_supported_currencies_list()
				: array();

		$supported_currencies = array(
			'authorize' => $urm_authorize_net_supported_currencies,
			'paypal'    => $paypal_supported_currencies_list,
			'mollie'    => $mollie_supported_currencies_list,
		);

		$membership_endpoint_url = ur_get_my_account_url() . '/ur-membership';

		wp_localize_script(
			'user-registration-membership-frontend-script',
			'ur_membership_frontend_localized_data',
			array(
				'_nonce'                           => wp_create_nonce( 'ur_members_frontend' ),
				'upgrade_membership_nonce'         => wp_create_nonce( 'urm_upgrade_membership' ),
				'renew_membership_nonce'           => wp_create_nonce( 'urm_renew_membership' ),
				'_confirm_payment_nonce'           => wp_create_nonce( 'urm_confirm_payment' ),
				'ajax_url'                         => admin_url( 'admin-ajax.php' ),
				'login_url'                        => wp_login_url(),
				'labels'                           => $this->get_i18_labels(),
				'currency'                         => $currency,
				'currency_symbol'                  => $symbol,
				'curreny_pos'                      => isset( $currencies[ $currency ]['symbol_pos'] ) ? $currencies[ $currency ]['symbol_pos'] : 'left',
				'membership_registration_page_url' => $redirect_page_url,
				'thank_you_page_url'               => '',
				'membership_endpoint_url'          => $membership_endpoint_url,
				'stripe_publishable_key'           => $stripe_settings['publishable_key'],
				'membership_gateways'              => get_option( 'ur_membership_payment_gateways', array() ),
				'urm_hide_stripe_card_postal_code' => apply_filters( 'user_registration_membership_disable_stripe_card_postal_code', false ),
				'gateways_configured'              => urm_get_all_active_payment_gateways( 'paid' ),
				'isEditor'                         => current_user_can( 'edit_post', get_the_ID() ) && isset( $_GET['action'] ) && 'edit' === $_GET['action'],
				'membership_selection_message'     => __( 'Please select at least one membership plan', 'user-registration' ),
				'tax_calculation_method'           => ur_string_to_bool( $tax_calculation_method ),
				'regions_list'                     => $regions,
				'gateways_configured'              => urm_get_all_active_payment_gateways( 'paid' ),
				'local_currencies'                 => ur_get_currencies(),
				'local_currencies_symbol'          => ur_get_currency_symbols(),
				'supported_currencies'             => $supported_currencies,
				'is_tax_calculation_enabled'       => $is_tax_calculation_enabled,
			),
		);
	}

	/**
	 * Retrieves an array of internationalized labels used in the frontend.
	 *
	 * @return array An associative array of internationalized labels.
	 */
	public function get_i18_labels() {

		return array(
			'network_error'                                => __( 'Network error', 'user-registration' ),
			'i18n_field_is_required'                       => __( 'field is required.', 'user-registration' ),
			'i18n_field_password_empty_validation'         => __( 'Password cannot be empty', 'user-registration' ),
			'i18n_field_email_field_validation'            => __( 'Please enter a valid email address.', 'user-registration' ),
			'i18n_field_confirm_password_field_validation' => __( 'Password does not match with confirm password.', 'user-registration' ),
			'i18n_field_password_field_length_validation'  => __( 'Password must be at least 8 characters long', 'user-registration' ),
			'i18n_field_password_field_regex_validation'   => __( 'Password must contain at least one lowercase letter, one uppercase letter, one number, and one special character.', 'user-registration' ),
			'i18n_field_payment_gateway_field_validation'  => __( 'Please select a Payment Gateway.', 'user-registration' ),
			'i18n_field_select_payment_gateway'            => __( 'Select a Payment Gateway.', 'user-registration' ),
			'i18n_thank_you'                               => __( 'Thank You', 'user-registration' ),
			'i18n_sign_in'                                 => __( 'Sign In', 'user-registration' ),
			'i18n_order_successful'                        => __( 'Your order has been successfully placed.', 'user-registration' ),
			'i18n_transaction_id'                          => __( 'Please use this transaction ID for future references.', 'user-registration' ),
			'i18n_membership_required'                     => __( 'Membership field is required.', 'user-registration' ),
			'i18n_coupon_invalid_error'                    => __( 'Coupon is Invalid.', 'user-registration' ),
			'i18n_coupon_discount_message'                 => __( 'discount on membership has been applied.', 'user-registration' ),
			'i18n_coupon_empty_error'                      => __( 'Coupon Field is empty.', 'user-registration' ),
			'i18n_dismiss'                                 => __( 'Dismiss', 'user-registration' ),
			'i18n_coupon_free_membership_error'            => __( 'Invalid membership type (Free).', 'user-registration' ),
			'i18n_incomplete_stripe_setup_error'           => __( 'Stripe Payment stopped. Incomplete Stripe setup.', 'user-registration' ),
			'i18n_bank_details_title'                      => __( 'Bank Details.', 'user-registration' ),
			'i18n_change_membership_title'                 => __( 'Change Membership', 'user-registration' ),
			'i18n_purchasing_multiple_membership_title'    => __( 'Purchase Membership', 'user-registration' ),
			'i18n_change_renew_title'                      => __( 'Renew Membership', 'user-registration' ),
			'i18n_change_plan_required'                    => __( 'At least one Plan must be selected', 'user-registration' ),
			'i18n_error'                                   => __( 'Error', 'user-registration' ),
			'i18n_empty_card_details'                      => __( 'Your card number is incomplete.', 'user-registration' ),
			'i18n_cancel_membership_text'                  => __( 'Cancel Membership', 'user-registration' ),
			'i18n_close'                                   => __( 'Close', 'user-registration' ),
			'i18n_cancel_membership_subtitle'              => __( 'Are you sure you want to cancel this membership permanently?', 'user-registration' ),
			'i18n_sending_text'                            => __( 'Sending ...', 'user-registration' ),
			// Payment processing overlay labels
			'i18n_payment_processing_title'                => __( 'Processing Payment', 'user-registration' ),
			'i18n_payment_processing_message'              => __( 'Please wait while we securely process your payment. Do not close this page.', 'user-registration' ),
			'i18n_payment_completing_title'                => __( 'Completing Payment', 'user-registration' ),
			'i18n_payment_completing_message'              => __( 'Your payment has been verified. Please wait while we complete your registration.', 'user-registration' ),
			'i18n_validating_stripe_card'                  => __( 'Validating payment card, please wait...', 'user-registration' ),
			'i18n_stripe_mode_error'                       => __( 'Card validation failed. Please try again.', 'user-registration' ),
			'i18n_currency_not_supported_by'               => __( 'is not currently supported by', 'user-registration' ),
			'i18n_choose_another_gateway_or_currency'      => __( 'Please choose another payment method or currency.', 'user-registration' ),
			'i18n_no_gateway_available_for_currency'       => __( 'No payment gateway is currently available for %s. Please choose a different currency to continue.', 'user-registration' ),
		);
	}

	public function set_thank_you_transient() {
		if ( ! isset( $_GET['urm_uuid'] ) || ! isset( $_GET['thank_you'] ) ) {
			return;
		}
		$uuid         = $_GET['urm_uuid'] ? sanitize_text_field( $_GET['urm_uuid'] ) : ur_get_random_number();
		$transient_id = "uuid_{$uuid}_thank_you";
		delete_transient( $transient_id );
		$thank_you_page = get_permalink( absint( $_GET['thank_you'] ) );
		set_transient( $transient_id, $thank_you_page, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * clear_upgrade_data
	 * If Paypal payment fails then clear meta's so user can try again
	 *
	 * @return void
	 */
	public function clear_upgrade_data() {
		$user_id              = get_current_user_id();
		$subscription_service = new MembersSubscriptionRepository();
		$user_subscription    = $subscription_service->get_member_subscription( $user_id );
		if ( empty( $user_subscription ) ) {
			return;
		}
		$next_subscription_data = json_decode( get_user_meta( $user_id, 'urm_next_subscription_data', true ), true );
		$prev_subscription_data = json_decode( get_user_meta( $user_id, 'urm_previous_subscription_data', true ), true );

		if ( ! empty( $next_subscription_data ) && empty( $next_subscription_data['delayed_until'] ) && ! empty( $next_subscription_data['payment_method'] ) && ( 'paypal' === $next_subscription_data['payment_method'] ) ) {

			if ( $prev_subscription_data['status'] === 'active' ) {
				$membership_process = urm_get_membership_process( $user_id );

				if ( ! empty( $membership_process ) && isset( $membership_process['upgrade'][ $prev_subscription_data['item_id'] ] ) ) {
					unset( $membership_process['upgrade'][ $prev_subscription_data['item_id'] ] );
					update_user_meta( $user_id, 'urm_membership_process', $membership_process );
				}
			}
		}
	}

	/**
	 * Validate Stripe card mode before user registration.
	 *
	 * Fires on user_registration_before_register_user_action (just before wp_insert_user).
	 * If a Stripe PaymentMethod ID was submitted with the form, validate that its
	 * livemode matches the configured Stripe mode. If not, abort with an error
	 * so the user account is never created.
	 *
	 * @param array $valid_form_data Validated form data.
	 * @param int   $form_id        Form ID.
	 * @return void
	 */
	public function validate_stripe_card_before_register( $valid_form_data, $form_id ) {
		$pm_id_value = isset( $_POST['urm_stripe_pm_id'] ) ? sanitize_text_field( wp_unslash( $_POST['urm_stripe_pm_id'] ) ) : 'NOT_SET'; // phpcs:ignore WordPress.Security.NonceVerification

		\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
			'stripe',
			'validate_stripe_card_before_register fired',
			'info',
			array(
				'urm_stripe_pm_id' => $pm_id_value,
				'post_keys'        => implode( ', ', array_keys( $_POST ) ), // phpcs:ignore WordPress.Security.NonceVerification
			)
		);

		if ( empty( $_POST['urm_stripe_pm_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$pm_id          = sanitize_text_field( wp_unslash( $_POST['urm_stripe_pm_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$stripe_service = new \WPEverest\URMembership\Admin\Services\Stripe\StripeService();
		$result         = $stripe_service->validate_card_mode( $pm_id );

		\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
			'stripe',
			'Stripe card mode validation result',
			'info',
			array(
				'pm_id'   => $pm_id,
				'valid'   => $result['valid'],
				'message' => isset( $result['message'] ) ? $result['message'] : '',
			)
		);

		if ( ! $result['valid'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}
}
