<?php
/**
 * UserRegistration Modules
 *
 * Functions for the modules.
 *
 * @package  UserRegistration/Functions
 * @version  4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! function_exists( 'ur_check_module_activation' ) ) {
	/**
	 * Check if provided module is activated or not.
	 *
	 * @param string Module key to check.
	 *
	 * @return bool
	 */
	function ur_check_module_activation( $module ) {
		$enabled_features = get_option( 'user_registration_enabled_features', array() );

		if ( is_array( $enabled_features ) ) {
			return in_array( 'user-registration-' . $module, $enabled_features, true ) ? true : false;
		} else {
			return false;
		}
	}
}

if ( ! function_exists( 'ur_payment_integration_get_currencies' ) ) {
	/**
	 * Get supported currencies.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	function ur_payment_integration_get_currencies() {

		$currencies = array(
			'USD' => array(
				'name'                => esc_html__( 'U.S. Dollar', 'user-registration' ),
				'symbol'              => '&#36;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'GBP' => array(
				'name'                => esc_html__( 'Pound Sterling', 'user-registration' ),
				'symbol'              => '&pound;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'EUR' => array(
				'name'                => esc_html__( 'Euro', 'user-registration' ),
				'symbol'              => '&euro;',
				'symbol_pos'          => 'right',
				'thousands_separator' => '.',
				'decimal_separator'   => ',',
				'decimals'            => 2,
			),
			'AUD' => array(
				'name'                => esc_html__( 'Australian Dollar', 'user-registration' ),
				'symbol'              => '&#36;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'BRL' => array(
				'name'                => esc_html__( 'Brazilian Real', 'user-registration' ),
				'symbol'              => 'R$',
				'symbol_pos'          => 'left',
				'thousands_separator' => '.',
				'decimal_separator'   => ',',
				'decimals'            => 2,
			),
			'CAD' => array(
				'name'                => esc_html__( 'Canadian Dollar', 'user-registration' ),
				'symbol'              => '&#36;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'CZK' => array(
				'name'                => esc_html__( 'Czech Koruna', 'user-registration' ),
				'symbol'              => '&#75;&#269;',
				'symbol_pos'          => 'right',
				'thousands_separator' => '.',
				'decimal_separator'   => ',',
				'decimals'            => 2,
			),
			'DKK' => array(
				'name'                => esc_html__( 'Danish Krone', 'user-registration' ),
				'symbol'              => 'kr.',
				'symbol_pos'          => 'right',
				'thousands_separator' => '.',
				'decimal_separator'   => ',',
				'decimals'            => 2,
			),
			'HKD' => array(
				'name'                => esc_html__( 'Hong Kong Dollar', 'user-registration' ),
				'symbol'              => '&#36;',
				'symbol_pos'          => 'right',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'HUF' => array(
				'name'                => esc_html__( 'Hungarian Forint', 'user-registration' ),
				'symbol'              => 'Ft',
				'symbol_pos'          => 'right',
				'thousands_separator' => '.',
				'decimal_separator'   => ',',
				'decimals'            => 2,
			),
			'ILS' => array(
				'name'                => esc_html__( 'Israeli New Sheqel', 'user-registration' ),
				'symbol'              => '&#8362;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'MYR' => array(
				'name'                => esc_html__( 'Malaysian Ringgit', 'user-registration' ),
				'symbol'              => '&#82;&#77;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'MXN' => array(
				'name'                => esc_html__( 'Mexican Peso', 'user-registration' ),
				'symbol'              => '&#36;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'NOK' => array(
				'name'                => esc_html__( 'Norwegian Krone', 'user-registration' ),
				'symbol'              => 'Kr',
				'symbol_pos'          => 'left',
				'thousands_separator' => '.',
				'decimal_separator'   => ',',
				'decimals'            => 2,
			),
			'NZD' => array(
				'name'                => esc_html__( 'New Zealand Dollar', 'user-registration' ),
				'symbol'              => '&#36;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'PHP' => array(
				'name'                => esc_html__( 'Philippine Peso', 'user-registration' ),
				'symbol'              => 'Php',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'PLN' => array(
				'name'                => esc_html__( 'Polish Zloty', 'user-registration' ),
				'symbol'              => '&#122;&#322;',
				'symbol_pos'          => 'left',
				'thousands_separator' => '.',
				'decimal_separator'   => ',',
				'decimals'            => 2,
			),
			'RUB' => array(
				'name'                => esc_html__( 'Russian Ruble', 'user-registration' ),
				'symbol'              => 'pyб',
				'symbol_pos'          => 'right',
				'thousands_separator' => ' ',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'SGD' => array(
				'name'                => esc_html__( 'Singapore Dollar', 'user-registration' ),
				'symbol'              => '&#36;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'ZAR' => array(
				'name'                => esc_html__( 'South African Rand', 'user-registration' ),
				'symbol'              => 'R',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'SEK' => array(
				'name'                => esc_html__( 'Swedish Krona', 'user-registration' ),
				'symbol'              => 'Kr',
				'symbol_pos'          => 'right',
				'thousands_separator' => '.',
				'decimal_separator'   => ',',
				'decimals'            => 2,
			),
			'CHF' => array(
				'name'                => esc_html__( 'Swiss Franc', 'user-registration' ),
				'symbol'              => 'CHF',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'TWD' => array(
				'name'                => esc_html__( 'Taiwan New Dollar', 'user-registration' ),
				'symbol'              => '&#36;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'THB' => array(
				'name'                => esc_html__( 'Thai Baht', 'user-registration' ),
				'symbol'              => '&#3647;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'INR' => array(
				'name'                => esc_html__( 'Indian Rupees', 'user-registration' ),
				'symbol'              => '&#8377;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'JPY' => array(
				'name'                => esc_html__( 'Japanese Yen', 'user-registration' ),
				'symbol'              => '&yen;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 0,
			),
			'BGN' => array(
				'name'                => esc_html__( 'Bulgarian Lev', 'user-registration' ),
				'symbol'              => 'лв',
				'symbol_pos'          => 'right',
				'thousands_separator' => ' ',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'NGN' => array(
				'name'                => esc_html__( 'Nigerian Naira', 'user-registration' ),
				'symbol'              => '&#8358;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'KWD' => array(
				'name'                => esc_html__( 'Kuwaiti Dinar', 'user-registration' ),
				'symbol'              => 'د.ك',
				'symbol_pos'          => 'right',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 3,
			),
			'BAM' => array(
				'name'                => esc_html__( 'Bosnian Convertible Mark', 'user-registration' ),
				'symbol'              => 'KM',
				'symbol_pos'          => 'right',
				'thousands_separator' => '.',
				'decimal_separator'   => ',',
				'decimals'            => 2,
			),
			'UGX' => array(
				'name'                => esc_html__( 'Ugandan Shilling', 'user-registration' ),
				'symbol'              => 'USh',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 0,
			),
			'KES' => array(
				'name'                => esc_html__( 'Kenyan Shilling', 'user-registration' ),
				'symbol'              => 'KSh',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'GEL' => array(
				'name'                => esc_html__( 'Georgian Lari', 'user-registration' ),
				'symbol'              => '₾',
				'symbol_pos'          => 'right',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'CNY' => array(
				'name'                => esc_html__( 'Chinese Yuan', 'user-registration' ),
				'symbol'              => '&yen;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			),
			'TRY' => array(
				'name'                => esc_html__( 'Turkish Lira', 'user-registration' ),
				'symbol'              => '&#8378;',
				'symbol_pos'          => 'left',
				'thousands_separator' => '.',
				'decimal_separator'   => ',',
				'decimals'            => 2,
			),
		);

		return apply_filters( 'user_registration_payments_currencies', $currencies );
	}
}

if ( ! function_exists( 'paypal_supported_currencies_list' ) ) {
	/**
	 * Paypal Supported Currencies list.
	 * From https://developer.paypal.com/docs/reports/reference/paypal-supported-currencies/
	 *
	 * @since 1.4.3
	 */
	function paypal_supported_currencies_list() {
		return array(
			'AUD',
			'BRL',
			'CAD',
			'CNY',
			'CZK',
			'DKK',
			'EUR',
			'HKD',
			'HUF',
			'ILS',
			'JPY',
			'MYR',
			'MXN',
			'TWD',
			'NZD',
			'NOK',
			'PHP',
			'PLN',
			'GBP',
			'RUB',
			'SGD',
			'SEK',
			'CHF',
			'THB',
			'USD',
		);
	}
}

if ( ! function_exists( 'user_registration_sanitize_amount' ) ) {
	/**
	 * Sanitize Amount.
	 *
	 * Returns a sanitized amount by stripping out thousands separators.
	 *
	 * @param string $amount Amount.
	 * @param string $currency Currency.
	 *
	 * @return string $amount
	 * @link https://github.com/easydigitaldownloads/easy-digital-downloads/blob/master/includes/formatting.php#L24
	 *
	 * @since 1.0.0
	 */
	function user_registration_sanitize_amount( $amount, $currency = 'USD' ) {

		$currency      = strtoupper( $currency );
		$currencies    = ur_payment_integration_get_currencies();
		$thousands_sep = ! empty( $currencies[ $currency ]['thousands_separator'] ) ? $currencies[ $currency ]['thousands_separator'] : ',';
		$decimal_sep   = ! empty( $currencies[ $currency ]['decimal_separator'] ) ? $currencies[ $currency ]['decimal_separator'] : '.';
		$is_negative   = false;

		// Sanitize the amount.
		if ( ',' === $decimal_sep && false !== ( strpos( $amount, $decimal_sep ) ) ) {
			if ( ( '.' === $thousands_sep || ' ' === $thousands_sep ) && false !== ( strpos( $amount, $thousands_sep ) ) ) {
				$amount = str_replace( $thousands_sep, '', $amount );
			} elseif ( empty( $thousands_sep ) && false !== ( strpos( $amount, '.' ) ) ) {
				$amount = str_replace( '.', '', $amount );
			}
			$amount = str_replace( $decimal_sep, '.', $amount );
		} elseif ( ',' === $thousands_sep && false !== ( strpos( $amount, $thousands_sep ) ) ) {
			$amount = str_replace( $thousands_sep, '', $amount );
		}

		if ( $amount < 0 ) {
			$is_negative = true;
		}

		$amount   = preg_replace( '/[^0-9\.]/', '', $amount );
		$decimals = apply_filters( 'user_registration_sanitize_amount_decimals', 2, $amount );
		$amount   = number_format( (float) $amount, $decimals, '.', '' );

		if ( $is_negative ) {
			$amount *= - 1;
		}

		return $amount;
	}
}


if ( ! function_exists( 'ur_membership_install_required_pages' ) ) {

	/**
	 * Install required membership pages.
	 */
	function ur_membership_install_required_pages() {
		include_once untrailingslashit( plugin_dir_path( UR_PLUGIN_FILE ) ) . '/includes/admin/functions-ur-admin.php';

		WPEverest\URMembership\Admin\Database\Database::create_tables();
		$membership_field_name = 'membership_field_' . ur_get_random_number();
		update_option( 'ur_membership_default_membership_field_name', $membership_field_name );

		$membership_id = UR_Install::create_default_membership();
		// $membership_group_id =  ::create_default_membership_group( array( array( 'ID' => "$membership_id" ) ) );

		$pages                = apply_filters( 'user_registration_create_pages', array() );
		$default_form_page_id = 0;

		$post_content = '[[[{"field_key":"user_login","general_setting":{"label":"Username","description":"","field_name":"user_login","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":"","username_length":"","username_character":"1"},"icon":"ur-icon ur-icon-user"}],[{"field_key":"user_email","general_setting":{"label":"User Email","description":"","field_name":"user_email","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-email"}]],[[{"field_key":"user_pass","general_setting":{"label":"User Password","description":"","field_name":"user_pass","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password"}],[{"field_key":"user_confirm_password","general_setting":{"label":"Confirm Password","description":"","field_name":"user_confirm_password","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password-confirm"}]],[[{"field_key":"membership","general_setting":{"membership_group":"0","label":"Membership","description":"","field_name":"' . $membership_field_name . '","hide_label":"false","membership_listing_option":"all"},"advance_setting":{},"icon":"ur-icon ur-icon-membership-field"}]]]';

		// Insert default form.
		$default_form_page_id = wp_insert_post(
			array(
				'post_type'      => 'user_registration',
				'post_title'     => esc_html__( ' Registration Form', 'user-registration' ),
				'post_content'   => $post_content,
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		$pages['membership_registration'] = array(
			'name'    => _x( 'membership-registration', 'Page slug', 'user-registration' ),
			'title'   => _x( 'Membership Registration', 'Page title', 'user-registration' ),
			'content' => '[' . apply_filters( 'user_registration_form_shortcode_tag', 'user_registration_form' ) . ' id="' . esc_attr( $default_form_page_id ) . '"]',
			'option'  => 'user_registration_member_registration_page_id',
		);

		$pages['membership_pricing']  = array(
			'name'    => _x( 'membership-pricing', 'Page slug', 'user-registration' ),
			'title'   => _x( 'Membership Pricing', 'Page title', 'user-registration' ),
			'content' => '[user_registration_groups]',
			'option'  => '',
		);
		$pages['membership_thankyou'] = array(
			// Same slug the setup wizard uses, so the thank-you URL does not depend on which flow created the page.
			'name'    => _x( 'thankyou', 'Page slug', 'user-registration' ),
			'title'   => _x( 'Membership ThankYou', 'Page title', 'user-registration' ),
			'content' => '[user_registration_membership_thank_you]',
			'option'  => 'user_registration_thank_you_page_id',
		);

		foreach ( $pages as $key => $page ) {
			$post_id = ur_create_page( esc_sql( $page['name'] ), 'user_registration_' . $key . '_page_id', wp_kses_post( ( $page['title'] ) ), wp_kses_post( $page['content'] ) );
			if ( ! empty( $page['option'] ) ) {
				update_option( $page['option'], $post_id );
			}
		}
		$enabled_features = get_option( 'user_registration_enabled_features', array() );
		array_push( $enabled_features, 'user-registration-membership' );
		array_push( $enabled_features, 'user-registration-payment-history' );
		array_push( $enabled_features, 'user-registration-content-restriction' );
		update_option( 'user_registration_enabled_features', $enabled_features );
		update_option( 'user_registration_membership_installed_flag', true );
	}
}


if ( ! function_exists( 'ur_get_all_roles' ) ) {

	/**
	 * Retrieve list of roles.
	 */
	function ur_get_all_roles() {
		global $wp_roles;

		if ( ! class_exists( 'WP_Roles' ) ) {
			return;
		}

		$roles = array();
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}
		$roles = $wp_roles->roles;

		$all_roles = array();

		foreach ( $roles as $role_key => $role ) {

			$all_roles[ $role_key ] = $role['name'];
		}

		return apply_filters( 'user_registration_all_roles', $all_roles );
	}
}


if ( ! function_exists( 'check_membership_field_in_form' ) ) {
	/**
	 * check_membership_field_in_form
	 *
	 * @return bool
	 */
	function check_membership_field_in_form( $form_id ) {

		$payment_fields       = ur_get_form_fields( $form_id );
		$has_membership_field = false;
		foreach ( $payment_fields as $k => $field ) {
			if ( 'membership' === $field->field_key ) {
				$has_membership_field = true;
			}
		}

		return $has_membership_field;
	}
}

if ( ! function_exists( 'urm_get_membership_process' ) ) {
	/**
	 * Get or initialize the membership process structure for a user.
	 *
	 * @param int $user_id
	 * @return array
	 */
	function urm_get_membership_process( $user_id ) {
		$process = get_user_meta( $user_id, 'urm_membership_process', true );

		// If nothing is stored, initialize fresh structure
		if ( empty( $process ) || ! is_array( $process ) ) {
			$process = array(
				'upgrade'  => array(),
				'renew'    => array(),
				'multiple' => array(),
			);
			update_user_meta( $user_id, 'urm_membership_process', $process );
			return $process;
		}

		// Ensure all keys exist
		if ( ! isset( $process['upgrade'] ) || ! is_array( $process['upgrade'] ) ) {
			$process['upgrade'] = array();
		}

		if ( ! isset( $process['renew'] ) || ! is_array( $process['renew'] ) ) {
			$process['renew'] = array();
		}

		if ( ! isset( $process['multiple'] ) || ! is_array( $process['multiple'] ) ) {
			$process['multiple'] = array();
		}

		update_user_meta( $user_id, 'urm_membership_process', $process );

		return $process;
	}
}


if ( ! function_exists( 'urm_check_user_membership_has_access' ) ) {
	/**
	 * Function to check if user has active membership that is required by restriction rule.
	 *
	 * @param array $allowed_memberships Allowed memberships for access.
	 */
	function urm_check_user_membership_has_access( $allowed_memberships ) {
		if ( is_super_admin() ) {
			return true;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$members_subscription = new \WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository();

		$user_memberships = $members_subscription->get_member_subscription( wp_get_current_user()->ID );

		if ( empty( $user_memberships ) ) {
			return false;
		}

		$active_memberships = array_filter(
			array_map(
				function ( $user_memberships ) {
					if ( ! empty( $user_memberships['status'] ) && 'active' === $user_memberships['status'] ) {
						return $user_memberships['item_id'];
					}
				},
				$user_memberships
			)
		);

		$common = array_intersect( $allowed_memberships, $active_memberships );
		return ! empty( $common );
	}
}

if ( ! function_exists( 'urm_get_form_user_payments' ) ) {

	/**
	 * Function to get all user form payments.
	 *
	 * @param array $args Arguments.
	 */
	function urm_get_form_user_payments( $args ) {
		if ( ! isset( $args['meta_query'] ) ) {
			$args['meta_query'] = array( 'relation' => 'AND' );
		}
		$args['meta_query'][] = array(
			'key'     => 'ur_payment_status',
			'compare' => 'EXISTS',
		);

		$user_query = new \WP_User_Query( $args );
		$users      = $user_query->get_results();

		$total_items = array();
		if ( ! empty( $users ) ) {
			foreach ( $users as $user ) {
				$meta_value    = get_user_meta( $user->ID, 'ur_payment_invoices', true );
				$total_items[] = array(
					'user_id'        => $user->ID,
					'display_name'   => $user->user_login,
					'user_email'     => $user->user_email,
					'transaction_id' => $meta_value[0]['invoice_no'] ?? '',
					'post_title'     => $meta_value[0]['invoice_plan'] ?? '',
					'status'         => get_user_meta( $user->ID, 'ur_payment_status', true ),
					'created_at'     => $meta_value[0]['invoice_date'] ?? '',
					'type'           => get_user_meta( $user->ID, 'ur_payment_type', true ),
					'payment_method' => str_replace( '_', ' ', get_user_meta( $user->ID, 'ur_payment_method', true ) ),
					'total_amount'   => $meta_value[0]['invoice_amount'] ?? 0,
					'currency'       => $meta_value[0]['invoice_currency'] ?? '',
				);
			}
		}

		return $total_items;
	}
}

if ( ! function_exists( 'urwc_is_same_page_url' ) ) {
	/**
	 * Compare two URLs by host and path only, ignoring query strings, fragments, and protocol.
	 *
	 * Used to detect redirect-to-self loops on the WooCommerce shop page.
	 *
	 * @since x.x.x
	 *
	 * @param string $url_a First URL.
	 * @param string $url_b Second URL.
	 *
	 * @return bool True if both URLs point to the same host+path.
	 */
	function urwc_is_same_page_url( $url_a, $url_b ) {
		$parsed_a = wp_parse_url( $url_a );
		$parsed_b = wp_parse_url( $url_b );

		if ( ! $parsed_a || ! $parsed_b ) {
			return false;
		}

		$host_a = strtolower( untrailingslashit( isset( $parsed_a['host'] ) ? $parsed_a['host'] : '' ) );
		$host_b = strtolower( untrailingslashit( isset( $parsed_b['host'] ) ? $parsed_b['host'] : '' ) );
		$path_a = untrailingslashit( isset( $parsed_a['path'] ) ? $parsed_a['path'] : '/' );
		$path_b = untrailingslashit( isset( $parsed_b['path'] ) ? $parsed_b['path'] : '/' );

		return $host_a === $host_b && $path_a === $path_b;
	}
}

/**
 * Handle WooCommerce shop page content restriction.
 *
 * The WC shop page is a product archive and does not render $post->post_content,
 * so it needs its own restriction approach via WC action hooks.
 *
 * @since x.x.x
 */
add_filter( 'urcr_restrict_page', 'urwc_handle_shop_restriction', 10, 3 );

if ( ! function_exists( 'urwc_handle_shop_restriction' ) ) {
	/**
	 * Handle WooCommerce shop page content restriction.
	 *
	 * @since x.x.x
	 *
	 * @param bool  $handled     Whether the restriction has already been handled.
	 * @param array $access_rule The access rule configuration.
	 * @param mixed $post        The current post object.
	 *
	 * @return bool
	 */
	function urwc_handle_shop_restriction( $handled, $access_rule, $post ) {

		if ( ! function_exists( 'is_shop' ) || ( ! is_shop() && ! is_post_type_archive( 'product' ) ) ) {
			return $handled;
		}

		$action_type = isset( $access_rule['actions'][0]['type'] ) ? $access_rule['actions'][0]['type'] : 'message';

		if ( 'redirect' === $action_type ) {
			$redirect_url = trim( ! empty( $access_rule['actions'][0]['redirect_url'] ) ? $access_rule['actions'][0]['redirect_url'] : admin_url() );
			$redirect_url = urldecode( $redirect_url );

			if ( ! preg_match( '#^https?://#i', $redirect_url ) ) {
				$redirect_url = 'https://' . $redirect_url;
			}

			$redirect_url = esc_url_raw( $redirect_url );
			if ( empty( $redirect_url ) ) {
				$redirect_url = home_url();
			}

			$wc_shop_url = function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'shop' ) ) : '';
			if ( ! $wc_shop_url || ! urwc_is_same_page_url( $redirect_url, $wc_shop_url ) ) {
				wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
		} elseif ( 'redirect_to_local_page' === $action_type ) {
			$page_id         = ! empty( $access_rule['actions'][0]['local_page'] ) ? absint( $access_rule['actions'][0]['local_page'] ) : null;
			$wc_shop_page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;

			if ( ! ( $page_id && $wc_shop_page_id && (int) $page_id === (int) $wc_shop_page_id ) ) {
				if ( $page_id ) {
					$page_url = get_page_link( $page_id );
					if ( $page_url ) {
						wp_safe_redirect( $page_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
						exit;
					}
				}
				wp_safe_redirect( home_url() );
				exit;
			}
		}

		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->posts         = array();
			$wp_query->post_count    = 0;
			$wp_query->found_posts   = 0;
			$wp_query->max_num_pages = 0;
		}

		remove_all_actions( 'woocommerce_archive_description' );
		remove_all_actions( 'woocommerce_before_shop_loop' );
		remove_all_actions( 'woocommerce_before_shop_loop_item' );
		remove_all_actions( 'woocommerce_before_shop_loop_item_title' );
		remove_all_actions( 'woocommerce_shop_loop_item_title' );
		remove_all_actions( 'woocommerce_after_shop_loop_item_title' );
		remove_all_actions( 'woocommerce_after_shop_loop_item' );
		remove_all_actions( 'woocommerce_after_shop_loop' );

		add_action( 'woocommerce_no_products_found', 'ob_start', 0 );
		add_action( 'woocommerce_no_products_found', 'ob_end_clean', PHP_INT_MAX, 0 );

		add_action(
			'wp_head',
			function () {
				echo '<style>.wp-block-woocommerce-product-collection-no-results{display:none!important}</style>';
			}
		);

		$message = ! empty( $access_rule['actions'][0]['message'] )
			? urldecode( $access_rule['actions'][0]['message'] )
			: get_option( 'user_registration_content_restriction_message', '' );

		$message = apply_filters( 'user_registration_process_smart_tags', $message );

		$message = do_shortcode( $message );

		$message_to_display = $message;

		add_action(
			'woocommerce_before_main_content',
			function () use ( $message_to_display ) {
				$login_page_id        = get_option( 'user_registration_login_page_id' );
				$registration_page_id = get_option( 'user_registration_member_registration_page_id' );
				$login_url            = $login_page_id ? get_permalink( $login_page_id ) : wp_login_url();
				$signup_url           = $registration_page_id
					? get_permalink( $registration_page_id )
					: ( $login_page_id ? get_permalink( $login_page_id ) : wp_registration_url() );

				if ( function_exists( 'urcr_get_template' ) ) {
					urcr_get_template(
						'base-restriction-template.php',
						array(
							'message'    => $message_to_display,
							'login_url'  => $login_url,
							'signup_url' => $signup_url,
						)
					);
				} else {
					echo '<div class="urcr-restrict-msg">' . wp_kses_post( $message_to_display ) . '</div>';
				}
			},
			20
		);

		return true;
	}
}

if ( ! function_exists( 'ur_membership_parse_display_date' ) ) {
	/**
	 * Parse a membership date string that SubscriptionService formats as 'Y, F d'
	 * (e.g. "2027, July 02") back into a timestamp.
	 *
	 * strtotime() can't reliably parse that format — it silently drops the year —
	 * so this tries the exact format first and falls back to strtotime() for any
	 * other date string shape.
	 *
	 * @param string $date_string Date string to parse.
	 *
	 * @return int|false Timestamp on success, false on failure.
	 */
	function ur_membership_parse_display_date( $date_string ) {
		$parsed = DateTime::createFromFormat( 'Y, F d', $date_string );

		return $parsed ? $parsed->getTimestamp() : strtotime( $date_string );
	}
}


/**
 * Deprecating function code start
 *
 * @deprecated
 */
$modules = array(
	'coupons'             => 'ur_pro_is_coupons_addon_activated',
	'payments'            => 'ur_pro_is_paypal_activated',
	'sms-integration'     => 'ur_pro_is_sms_integration_activated',
	'content-restriction' => 'ur_pro_is_content_restriction_activated',
	'payment-history'     => 'ur_pro_is_payment_history_activated',
);

foreach ( $modules as $module_key => $function_name ) {
	if ( ! function_exists( $function_name ) ) {
		eval(
			"
		        function $function_name() {
		            return ur_check_module_activation('$module_key');
		        }
        "
		);
	}
}
// deprecating function code ends
