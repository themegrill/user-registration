<?php
/**
 * URMembership CoreFunctions.
 *
 * General core functions available on both the front-end and admin.
 *
 * @author   WPEverest
 * @category Core
 * @package  URMembership/Handler
 * @version  1.0.0
 */

use WPEverest\URMembership\Admin\Repositories\MembershipRepository;

if ( ! function_exists( 'ur_membership_get_all_roles' ) ) {
	/**
	 * Retrieves all the roles available in the WordPress system.
	 *
	 * This function checks if the WP_Roles class exists and returns an array containing all the roles and their names.
	 *
	 * @return array An associative array where the keys are the role keys and the values are the role names.
	 * @global WP_Roles $wp_roles The global WP_Roles object.
	 */
	function ur_membership_get_all_roles() {
		global $wp_roles; // phpcs:ignore

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

		return $all_roles;
	}
}

if ( ! function_exists( 'ur_membership_remove_unrelated_notices' ) ) {
	/**
	 * Removes unrelated notices from the WordPress admin.
	 *
	 * This function iterates over the 'user_admin_notices', 'admin_notices', and 'all_admin_notices'
	 * hooks and removes all notices that are not related to the 'user_registration_' plugin.
	 *
	 * @return void
	 * @global array $wp_filter The global WordPress filter
	 */
	function ur_membership_remove_unrelated_notices() {
		global $wp_filter;
		foreach ( array( 'user_admin_notices', 'admin_notices', 'all_admin_notices' ) as $wp_notice ) {
			if ( ! empty( $wp_filter[ $wp_notice ]->callbacks ) && is_array( $wp_filter[ $wp_notice ]->callbacks ) ) {
				foreach ( $wp_filter[ $wp_notice ]->callbacks as $priority => $hooks ) {
					foreach ( $hooks as $name => $arr ) {
						// Remove all notices except user registration plugins notices.
						if ( ! strstr( $name, 'user_registration_' ) ) {
							unset( $wp_filter[ $wp_notice ]->callbacks[ $priority ][ $name ] );
						}
					}
				}
			}
		}
	}
}

if ( ! function_exists( 'ur_membership_verify_nonce' ) ) {
	/**
	 * Verify the nonce for AJAX requests.
	 *
	 * This function checks the AJAX referer nonce to ensure that the request is valid.
	 * If the nonce is invalid, an error response is sent back to the client.
	 *
	 * @param string $nonce The nonce value to verify.
	 *
	 * @return void
	 * @throws WP_Error If the nonce is invalid.
	 */
	function ur_membership_verify_nonce( $nonce ) {
		if ( ! check_ajax_referer( $nonce, 'security' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Nonce error please reload.', 'user-registration' ),
				)
			);
		}
	}
}

if ( ! function_exists( 'ur_membership_get_privileged_capabilities' ) ) {
	/**
	 * Capabilities that make a role unsafe to grant automatically through a membership.
	 *
	 * The stock editor role holds unfiltered_html and WooCommerce's shop_manager holds list_users,
	 * so neither is listed here: both are roles a site may legitimately attach to a plan.
	 *
	 * @since 5.2.8
	 *
	 * @return array List of capability names.
	 */
	function ur_membership_get_privileged_capabilities() {
		/**
		 * Filters the capabilities that bar a role from being granted through a membership.
		 *
		 * @since 5.2.8
		 *
		 * @param array $capabilities List of capability names.
		 */
		return apply_filters(
			'user_registration_membership_privileged_capabilities',
			array(
				'manage_options',
				'promote_users',
				'edit_users',
				'create_users',
				'delete_users',
				'remove_users',
				'install_plugins',
				'activate_plugins',
				'update_plugins',
				'edit_plugins',
				'install_themes',
				'switch_themes',
				'edit_themes',
				'edit_files',
				'edit_dashboard',
			)
		);
	}
}

if ( ! function_exists( 'ur_membership_is_privileged_role' ) ) {
	/**
	 * Whether a role holds a capability that makes it unsafe to grant through a membership.
	 *
	 * @since 5.2.8
	 *
	 * @param string $role Role slug.
	 * @return bool True when the role is too privileged to grant automatically.
	 */
	function ur_membership_is_privileged_role( $role ) {
		$role_object = wp_roles()->get_role( sanitize_key( $role ) );

		if ( ! $role_object ) {
			return false;
		}

		foreach ( ur_membership_get_privileged_capabilities() as $capability ) {
			if ( ! empty( $role_object->capabilities[ $capability ] ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'ur_membership_get_safe_role' ) ) {
	/**
	 * Constrain the role a membership grants, as a backstop at the point of assignment.
	 *
	 * A plan's role is administrator-authored data, so a privileged value is honoured when the
	 * plan was authored by someone entitled to assign roles, and refused otherwise. That keeps a
	 * plan injected by a lower role from granting itself anything, without overriding a choice an
	 * administrator deliberately made. Every refusal is logged, because a silent downgrade reads
	 * as the membership simply not working.
	 *
	 * @since 5.2.8
	 *
	 * @param string $role          Role slug taken from the membership data.
	 * @param int    $membership_id Membership post ID the role came from.
	 * @param string $fallback      Role used when the requested one is missing or refused.
	 * @return string Role slug safe to grant.
	 */
	function ur_membership_get_safe_role( $role, $membership_id = 0, $fallback = 'subscriber' ) {
		$role = sanitize_key( $role );
		$safe = $role;

		if ( empty( $role ) || ! wp_roles()->is_role( $role ) ) {
			$safe = $fallback;
		} elseif ( ur_membership_is_privileged_role( $role ) ) {
			$author_id = $membership_id ? (int) get_post_field( 'post_author', absint( $membership_id ) ) : 0;

			if ( ! $author_id || ! user_can( $author_id, 'promote_users' ) ) {
				$safe = $fallback;

				ur_get_logger()->warning(
					sprintf(
						/* translators: 1: requested role slug, 2: membership ID, 3: role granted instead. */
						'Refused to grant privileged role "%1$s" from membership %2$d because its author cannot assign roles; granted "%3$s" instead.',
						$role,
						absint( $membership_id ),
						$fallback
					),
					array( 'source' => 'user-registration-membership' )
				);
			}
		}

		/**
		 * Filters the role a membership grants, after the privilege backstop has run.
		 *
		 * @since 5.2.8
		 *
		 * @param string $safe          Role slug that will be granted.
		 * @param string $role          Role slug requested by the membership data.
		 * @param int    $membership_id Membership post ID the role came from.
		 */
		return apply_filters( 'user_registration_membership_safe_role', $safe, $role, $membership_id );
	}
}

if ( ! function_exists( 'ur_membership_get_currencies' ) ) {
	/**
	 * ur_membership_get_currencies
	 *
	 * This function returns a list of currencies of different countries, this is a copy of a function in payment gateways
	 *
	 * @return mixed|void
	 */
	function ur_membership_get_currencies() {

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
		);

		return apply_filters( 'user_registration_membership_payments_currencies', $currencies );
	}
}

if ( ! function_exists( 'ur_membership_redirect_to_thank_you_page' ) ) {
	/**
	 * Redirect to thank you page
	 *
	 * @param $member_id
	 * @param $member_order
	 *
	 * @return void
	 */
	function ur_membership_redirect_to_thank_you_page( $member_id, $member_order ) {

		$thank_you_page = urm_get_thank_you_page();
		$user           = get_userdata( $member_id );
		$params         = array(
			'username'       => $user->user_login,
			'transaction_id' => empty( $member_order['transaction_id'] ) ? $member_order['ID'] : $member_order['transaction_id'],
			'payment_type'   => isset( $member_order['order_type'] ) ? $member_order['order_type'] : 'paid',
		);
		$url            = $thank_you_page . '?' . http_build_query( $params );

		wp_redirect( $url );
		exit;
	}
}
if ( ! function_exists( 'ur_membership_redirect_now' ) ) {
	/**
	 * Redirect to thank you page
	 *
	 * @param $member_id
	 * @param $member_order
	 *
	 * @return void
	 */
	function ur_membership_redirect_now( $url, $params ) {
		$url = $url . '?' . http_build_query( $params );
		wp_redirect( $url );
		exit;
	}
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'build_membership_list_frontend', 'build_membership_list_frontend', 10, 1 );
}

if ( ! function_exists( 'build_membership_list_frontend' ) ) {
	/**
	 * Builds the frontend membership list.
	 *
	 * This function takes an array of memberships and transforms it into a new array
	 * with specific properties for each membership. The properties include:
	 * - ID: The ID of the membership.
	 * - title: The title of the membership.
	 * - type: The type of the membership.
	 * - amount: The amount of the membership.
	 * - period: The period of the membership, calculated based on the type.
	 * - active_payment_gateways: An array of active payment gateways for the membership.
	 *
	 * @param array $memberships The array of memberships.
	 *
	 * @return array The transformed membership list.
	 */
	function build_membership_list_frontend( $memberships ) {
		$currency                = get_option( 'user_registration_payment_currency', 'USD' );
		$currencies              = ur_payment_integration_get_currencies();
		$symbol                  = $currencies[ $currency ]['symbol'];
		$new_mem                 = array();
		$active_payment_gateways = array();
		$is_new_installation     = ur_string_to_bool( get_option( 'urm_is_new_installation', '' ) );

		foreach ( $memberships as $k => $membership ) {

			$membership_id         = ! empty( $membership['ID'] ) ? $membership['ID'] : '';
			$membership_meta_value = ! empty( $membership['meta_value'] ) ? $membership['meta_value'] : '';
			$membership_type       = ( ! empty( $membership_meta_value ) && ! empty( $membership_meta_value['type'] ) ) ? $membership_meta_value['type'] : '';
			$amount                = ! empty( $membership['meta_value']['amount'] ) ? number_format( (float) $membership['meta_value']['amount'], 2 ) : 0;
			$symbol_pos            = isset( $currencies[ $currency ]['symbol_pos'] ) ? $currencies[ $currency ]['symbol_pos'] : 'left';
			$membership_cur_amount = ! empty( $amount ) ? ( 'right' === $symbol_pos ? $amount . $symbol : $symbol . $amount ) : '';
			$duration_label        = '';
			if ( ! empty( $membership['meta_value']['subscription']['duration'] ) ) {
				$duration_key    = isset( $membership['meta_value']['subscription']['duration'] ) ? strtolower( $membership['meta_value']['subscription']['duration'] ) : '';
				$duration_labels = array(
					'day'   => __( 'Day', 'user-registration' ),
					'week'  => __( 'Week', 'user-registration' ),
					'month' => __( 'Month', 'user-registration' ),
					'year'  => __( 'Year', 'user-registration' ),
				);
				$duration_label  = $duration_labels[ $duration_key ] ?? ucfirst( $duration_key );
			}

			$subscription_period = $membership_cur_amount;
			if ( ! empty( $membership_meta_value ) && 'subscription' === $membership_meta_value['type'] && ! empty( $duration_label ) ) {
				$sub_value = (int) $membership['meta_value']['subscription']['value'];
				if ( 1 === $sub_value ) {
					$subscription_period = $membership_cur_amount . ' ' . __( 'every', 'user-registration' ) . ' ' . $duration_label;
				} else {
					$subscription_period = $membership_cur_amount . ' ' . __( 'every', 'user-registration' ) . ' ' . number_format( $sub_value ) . ' ' . ucfirst( $duration_label ) . __( 's', 'user-registration' );
				}
			}

			$new_mem[ $k ] = array(
				'ID'                => $membership_id,
				'title'             => ! empty( $membership['post_title'] ) ? $membership['post_title'] : '',
				'description'       => ! empty( $membership['post_content']['description'] ) ? $membership['post_content']['description'] : get_post_meta( $membership_id, 'ur_membership_description', true ),
				'type'              => $membership_type,
				'amount'            => ! empty( $membership_meta_value ) ? $membership['meta_value']['amount'] : 0,
				'currency_symbol'   => $symbol,
				'calculated_amount' => 'free' === $membership_type ? 0 : ( ! empty( $membership_meta_value ) ? (float) $membership_meta_value['amount'] : 0 ),
				'period'            => 'free' === $membership_type ? __( 'Free', 'user-registration' ) : $subscription_period,
				'trial_status'      => ! empty( $membership['meta_value']['trial_status'] ) ? $membership['meta_value']['trial_status'] : 'off',
				'trial_data'        => ( ! empty( $membership['meta_value']['trial_data'] ) && is_array( $membership['meta_value']['trial_data'] ) ) ? $membership['meta_value']['trial_data'] : array(),
			);

			if ( isset( $membership['meta_value']['payment_gateways'] ) ) {

				if ( $is_new_installation ) {
					// Get all active gateways.
					$all_active_gateways = urm_get_all_active_payment_gateways( $membership_type ?: 'paid' );
					foreach ( $all_active_gateways as $key => $label ) {
						$active_payment_gateways[ $key ] = true;
					}
				} else {
					foreach ( $membership['meta_value']['payment_gateways'] as $key => $gateways ) {
						if ( isset( $gateways['status'] ) && 'on' !== $gateways['status'] ) {
							continue;
						}
						$active_payment_gateways[ $key ] = isset( $gateways['status'] ) ? $gateways['status'] : 'off'; // setting users gateway to off for now if no status received.
					}
				}

				$new_mem[ $k ]['active_payment_gateways'] = ( wp_unslash( wp_json_encode( $active_payment_gateways ) ) );
			}
			$active_payment_gateways = array();
			if ( isset( $membership['meta_value']['team_pricing'] ) ) {
				$new_mem[ $k ]['team_pricing'] = $membership['meta_value']['team_pricing'];
			}
		}

		// Sort memberships by saved order if available
		$saved_order = get_option( 'ur_membership_order', array() );
		if ( ! empty( $saved_order ) && ! empty( $new_mem ) ) {
			$order_map = array_flip( $saved_order );

			$ordered_memberships   = array();
			$unordered_memberships = array();

			foreach ( $new_mem as $membership ) {
				$membership_id = isset( $membership['ID'] ) ? (int) $membership['ID'] : 0;
				if ( isset( $order_map[ $membership_id ] ) ) {
					$ordered_memberships[ $order_map[ $membership_id ] ] = $membership;
				} else {
					// Membership is not in saved order, append at end
					$unordered_memberships[] = $membership;
				}
			}

			ksort( $ordered_memberships );

			// Merge ordered and unordered memberships
			$new_mem = array_values( $ordered_memberships );
			$new_mem = array_merge( $new_mem, $unordered_memberships );
		}

		return $new_mem;
	}
}

if ( ! function_exists( 'get_membership_menus' ) ) {
	/**
	 * get_memberhsip_menus
	 *
	 * @return array[]
	 */
	function get_membership_menus() {
		return array(
			'memberships'       => array(
				'label'  => __( 'Memberships', 'user-registration' ),
				'url'    => admin_url( 'admin.php?page=user-registration-membership' ),
				'active' => isset( $_GET['page'] ) &&
							$_GET['page'] === 'user-registration-membership' &&
							( isset( $_GET['action'] ) ? ! in_array(
								$_GET['action'],
								array(
									'list_groups',
									'add_groups',
								)
							) : true ),
			),
			'membership_groups' => array(
				'label'  => __( 'Membership Groups', 'user-registration' ),
				'url'    => admin_url( 'admin.php?page=user-registration-membership&action=list_groups' ),
				'active' => isset( $_GET['page'], $_GET['action'] ) &&
							$_GET['page'] === 'user-registration-membership' &&
							in_array( $_GET['action'], array( 'list_groups', 'add_groups' ) ),
			),
			'members'           => array(
				'label'  => __( 'Members', 'user-registration' ),
				'url'    => admin_url( 'admin.php?page=user-registration-members' ),
				'active' => isset( $_GET['page'] ) && $_GET['page'] === 'user-registration-members',
			),
			'settings'          => array(
				'label'  => __( 'Settings', 'user-registration' ),
				'url'    => admin_url( 'admin.php?page=user-registration-settings&tab=membership' ),
				'active' => false,
			),
		);
	}
}

if ( ! function_exists( 'get_active_membership_id_name' ) ) {
	/**
	 * get_active_membership_id_name
	 *
	 * @return array
	 */
	function get_active_membership_id_name() {
		$new_membership     = array();
		$membership_service = new MembershipRepository();
		$memberships        = $membership_service->get_all_membership();
		foreach ( $memberships as $membership ) {
			$new_membership[ $membership['ID'] ] = $membership['post_title'];
		}

		return $new_membership;
	}
}

if ( ! function_exists( 'urm_is_divi_active' ) ) {
	/**
	 * Check divi is active or not.
	 *
	 * @return bool
	 */
	function urm_is_divi_active() {
		$active_theme_details = wp_get_theme();
		$theme_name           = $active_theme_details->Name;

		return 'Divi' === $theme_name;
	}
}

if ( ! function_exists( 'urm_is_divi5_active' ) ) {
	/**
	 * Check if Divi 5 (Visual Builder 5) is active.
	 *
	 * Divi 5 defines ET_BUILDER_5_DIR; Divi 4 does not.
	 *
	 * @return bool
	 */
	function urm_is_divi5_active() {
		return urm_is_divi_active() && defined( 'ET_BUILDER_5_DIR' );
	}
}


if ( ! function_exists( 'convert_to_days' ) ) {
	/**
	 * convert_to_days
	 *
	 * @param $value
	 * @param $unit
	 *
	 * @return float|int|mixed
	 */
	function convert_to_days( $value, $unit ) {
		switch ( strtolower( $unit ) ) {
			case 'year':
			case 'years':
				return $value * 365;
			case 'month':
			case 'months':
				return $value * 30;
			case 'week':
			case 'weeks':
				return $value * 7;
			case 'day':
			case 'days':
			default:
				return $value;
		}
	}
}

if ( ! function_exists( 'urm_get_thank_you_page' ) ) {
	/**
	 * Get Thank Yu page url
	 *
	 * @return array
	 */
	function urm_get_thank_you_page() {
		$thank_you_page_id = get_option( 'user_registration_thank_you_page_id' );
		$thank_you_page    = get_permalink( $thank_you_page_id );
		if ( ! empty( $_GET['urm_uuid'] ) ) {
			$uuid           = sanitize_text_field( $_GET['urm_uuid'] );
			$transient_id   = "uuid_{$uuid}_thank_you";
			$thank_you_page = get_transient( $transient_id );

		}

		return $thank_you_page;
	}
}
if ( ! function_exists( 'urm_get_date_at_percent_interval' ) ) {
	function urm_get_date_at_percent_interval( $startDateStr, $endDateStr, $percent ) {

		$startDate = new \DateTime( $startDateStr );
		$endDate   = new \DateTime( $endDateStr );
		if ( $percent < 0 || $percent > 100 ) {
			throw new InvalidArgumentException( 'Percent must be between 0 and 100.' );
		}

		$durationSeconds = $endDate->getTimestamp() - $startDate->getTimestamp();
		$offsetSeconds   = $durationSeconds * ( $percent / 100 );
		$targetTimestamp = $startDate->getTimestamp() + (int) $offsetSeconds;
		$targetDate      = new \DateTime();
		$targetDate->setTimestamp( $targetTimestamp );

		return $targetDate->format( 'Y-m-d 00:00:00' );
	}
}

if ( ! function_exists( 'urm_get_plan_description' ) ) {
	/**
	 * Get plan description based on type and period.
	 *
	 * @param string $plan_type Plan type.
	 * @param string $plan_period Plan period.
	 *
	 * @return string
	 */
	function urm_get_plan_description( $plan_type, $plan_period ) {
		if ( 'free' === $plan_type ) {
			return __( 'Completely Free', 'user-registration' );
		}

		$period_lower = strtolower( $plan_period );
		if ( false !== strpos( $period_lower, 'month' ) ) {
			return __( 'This will be billed every month', 'user-registration' );
		}

		if ( false !== strpos( $period_lower, 'lifetime' ) ) {
			return __( 'This is a one-time payment', 'user-registration' );
		}

		return '';
	}
}

if ( ! function_exists( 'urm_get_gateway_image_url' ) ) {
	/**
	 * Get payment gateway image URL.
	 *
	 * @param string $gateway_key Gateway key.
	 * @param array  $gateway_images Gateway images mapping.
	 * @param string $plugin_url Plugin URL.
	 *
	 * @return string
	 */
	function urm_get_gateway_image_url( $gateway_key, $gateway_images, $plugin_url ) {
		$image_file = isset( $gateway_images[ $gateway_key ] ) ? $gateway_images[ $gateway_key ] : '';

		if ( empty( $image_file ) ) {
			return '';
		}

		return esc_url( $plugin_url . '/assets/images/settings-icons/membership-field/' . $image_file );
	}
}

if ( ! function_exists( 'urm_get_active_gateways_for_plan' ) ) {
	/**
	 * Parse active payment gateways from membership option.
	 *
	 * @param array $option Membership option data.
	 * @param array $payment_gateways Available payment gateways.
	 *
	 * @return array
	 */
	function urm_get_active_gateways_for_plan( $option, $payment_gateways ) {
		$active_gateways = array();

		if ( empty( $option['active_payment_gateways'] ) ) {
			return $active_gateways;
		}

		$gateways_json = is_string( $option['active_payment_gateways'] )
			? json_decode( $option['active_payment_gateways'], true )
			: $option['active_payment_gateways'];

		if ( ! is_array( $gateways_json ) ) {
			return $active_gateways;
		}

		foreach ( $gateways_json as $gateway => $status ) {
			if ( 'on' === $status && isset( $payment_gateways[ $gateway ] ) ) {
				$active_gateways[ $gateway ] = $payment_gateways[ $gateway ];
			}
		}

		return $active_gateways;
	}
}

if ( ! function_exists( 'urm_get_all_active_payment_gateways' ) ) {
	/**
	 * Get all payment gateways that are configured/setup.
	 *
	 * @param string $membership_type Optional. The membership type ('paid' or 'subscription')
	 *
	 * @return array
	 */
	function urm_get_all_active_payment_gateways( $membership_type = 'paid' ) {
		// Get all available payment gateways.
		$payment_gateways = get_option(
			'ur_membership_payment_gateways',
			array(
				'paypal' => __( 'PayPal', 'user-registration' ),
				'stripe' => __( 'Stripe', 'user-registration' ),
				'bank'   => __( 'Bank', 'user-registration' ),
			)
		);

		if ( empty( $payment_gateways ) || ! is_array( $payment_gateways ) ) {
			return array();
		}

		$active_gateways = array();

		foreach ( $payment_gateways as $gateway_key => $gateway_label ) {
			// Check if payment gateway is configured.
			if ( urm_is_payment_gateway_configured( $gateway_key, $membership_type ) ) {
				$active_gateways[ $gateway_key ] = $gateway_label;
			}
		}

		/**
		 * Filters the list of active payment gateways.
		 *
		 * @param array $active_gateways Active payment gateways.
		 * @param string $membership_type Membership type.
		 *
		 * @return array
		 */
		return apply_filters( 'urm_active_payment_gateways', $active_gateways, $membership_type );
	}
}

if ( ! function_exists( 'urm_is_payment_gateway_configured' ) ) {
	/**
	 * Check if a payment gateway is configured (has settings).
	 *
	 * This function checks if a payment gateway has the required settings configured,
	 * without validating if they are correct or complete.
	 *
	 * @param string $gateway_key Payment gateway key (e.g., 'paypal', 'stripe', 'bank').
	 * @param string $membership_type Optional. Membership type for PayPal check. Default 'paid'.
	 *
	 * @return bool True if gateway is configured, false otherwise.
	 */
	function urm_is_payment_gateway_configured( $gateway_key, $membership_type = 'paid' ) {
		$is_configured       = false;
		$is_new_installation = ur_string_to_bool( get_option( 'urm_is_new_installation', '' ) );

		// First check if the gateway is enabled
		$enabled_option = '';
		switch ( $gateway_key ) {
			case 'paypal':
				$enabled_option = get_option( 'user_registration_paypal_enabled', '' );
				break;
			case 'stripe':
				$enabled_option = get_option( 'user_registration_stripe_enabled', '' );
				break;
			case 'authorize':
				$enabled_option = get_option( 'user_registration_authorize-net_enabled', '' );
				break;
			case 'mollie':
				$enabled_option = get_option( 'user_registration_mollie_enabled', '' );
				break;
			case 'bank':
				$enabled_option = get_option( 'user_registration_bank_enabled', '' );
				break;
		}

		if ( empty( $enabled_option ) ) {
			$is_enabled = ! $is_new_installation;
		} else {
			$is_enabled = ur_string_to_bool( $enabled_option );
		}

		if ( ! $is_enabled ) {
			return false;
		}

		switch ( $gateway_key ) {
			case 'paypal':
				$mode                 = get_option( 'user_registration_global_paypal_mode', 'test' ) == 'test' ? 'test' : 'live';
				$paypal_email         = get_option( sprintf( 'user_registration_global_paypal_%s_email_address', $mode ), get_option( 'user_registration_global_paypal_email_address' ) );
				$paypal_client_id     = get_option( sprintf( 'user_registration_global_paypal_%s_client_id', $mode ), get_option( 'user_registration_global_paypal_client_id' ) );
				$paypal_client_secret = get_option( sprintf( 'user_registration_global_paypal_%s_client_secret', $mode ), get_option( 'user_registration_global_paypal_client_secret' ) );

				if ( ur_is_paypal_old_installation() ) {
					$is_configured = ! empty( $paypal_email ) || ! empty( $paypal_client_id ) || ! empty( $paypal_client_secret );
				} else {
					$is_configured = ! empty( $paypal_client_id ) && ! empty( $paypal_client_secret );
				}
				break;

			case 'stripe':
				$mode            = get_option( 'user_registration_stripe_test_mode', false ) ? 'test' : 'live';
				$publishable_key = get_option( sprintf( 'user_registration_stripe_%s_publishable_key', $mode ) );
				$secret_key      = get_option( sprintf( 'user_registration_stripe_%s_secret_key', $mode ) );
				$is_configured   = ! empty( $publishable_key ) && ! empty( $secret_key );
				break;
			case 'bank':
				// For bank and other gateways, check if bank details are configured.
				$bank_details  = get_option( 'user_registration_global_bank_details' );
				$is_configured = ! empty( $bank_details );
				break;
			case 'default':
				break;
		}

		/**
		 * Filters whether the payment gateway is configured.
		 *
		 * @param bool $is_configured Whether the gateway is configured.
		 * @param string $gateway_key Payment gateway key.
		 * @param string $membership_type Membership type.
		 *
		 * @return bool
		 */
		return apply_filters( 'urm_is_payment_gateway_configured', $is_configured, $gateway_key, $membership_type );
	}
}

if ( ! function_exists( 'urcr_build_migration_actions' ) ) {
	/**
	 * Build migration actions array.
	 *
	 * @param string $migration_source Migration source type ('membership' or 'content').
	 * @param int    $timestamp Optional timestamp to use for action IDs. If not provided, generates a new one.
	 *
	 * @return array Actions array.
	 */
	function urcr_build_migration_actions( $migration_source = 'content', $timestamp = null ) {
		if ( null === $timestamp ) {
			$timestamp = time() * 1000;
		}

		if ( $migration_source === 'membership' ) {
			$message = '';
		} else {
			$default_message = '<h3>' . __( 'Membership Required', 'user-registration' ) . '</h3>
<p>' . __( 'This content is available to members only.', 'user-registration' ) . '</p>
<p>' . __( 'Sign up to unlock access or log in if you already have an account.', 'user-registration' ) . '</p>
<p>{{sign_up}} {{log_in}}</p>';
			$message         = get_option( 'user_registration_content_restriction_message', $default_message );

			if ( ! empty( $message ) ) {
				$message = wp_unslash( $message );
				$message = str_replace( array( '\\n', '\\r\\n', '\\r' ), array( "\n", "\n", "\n" ), $message );
				$message = str_replace( array( "\r\n", "\r" ), "\n", $message );
			}
		}

		return array(
			array(
				'id'             => 'x' . ( $timestamp + 200 ),
				'type'           => 'message',
				'label'          => 'Show Message',
				'message'        => $message,
				'redirect_url'   => '',
				'access_control' => 'access',
				'local_page'     => '',
				'ur_form'        => '',
				'shortcode'      => array(
					'tag'  => '',
					'args' => '',
				),
			),
		);
	}
}

if ( ! function_exists( 'urcr_create_membership_rule' ) ) {
	/**
	 * Create a default membership rule.
	 *
	 * @param int    $membership_id The membership ID.
	 * @param string $membership_title Optional membership title.
	 *
	 * @return int|false Rule ID on success, false on failure.
	 */
	function urcr_create_membership_rule( $membership_id, $membership_title = '' ) {
		if ( ! function_exists( 'ur_check_module_activation' ) || ! ur_check_module_activation( 'membership' ) ) {
			return false;
		}

		$existing_rules = get_posts(
			array(
				'post_type'      => 'urcr_access_rule',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => 'urcr_membership_id',
						'value' => $membership_id,
					),
				),
			)
		);

		if ( ! empty( $existing_rules ) ) {
			return false;
		}

		if ( empty( $membership_title ) ) {
			$membership_post = get_post( $membership_id );
			if ( ! $membership_post || 'ur_membership' !== $membership_post->post_type ) {
				return false;
			}
			$membership_title = $membership_post->post_title;
		}

		if ( empty( $membership_title ) ) {
			return false;
		}

		$timestamp = time() * 1000;

		$condition = array(
			'type'  => 'membership',
			'id'    => 'x' . $timestamp,
			'value' => array( strval( $membership_id ) ),
		);

		$logic_map = array(
			'type'       => 'group',
			'id'         => 'x' . ( $timestamp + 1 ),
			'conditions' => array( $condition ),
			'logic_gate' => 'AND',
		);

		$target_contents = array();

		$rule_data = array(
			'enabled'         => true,
			'access_control'  => 'access',
			'logic_map'       => $logic_map,
			'target_contents' => $target_contents,
			'actions'         => urcr_build_migration_actions( 'membership', $timestamp ),
		);

		$rule_title = sprintf( __( '%s Rule', 'user-registration' ), $membership_title );

		$rule_post = array(
			'post_title'   => $rule_title,
			'post_content' => wp_json_encode( $rule_data ),
			'post_type'    => 'urcr_access_rule',
			'post_status'  => 'publish',
		);

		$rule_id = wp_insert_post( $rule_post );

		if ( $rule_id && ! is_wp_error( $rule_id ) ) {
			update_post_meta( $rule_id, 'urcr_rule_type', 'membership' );
			update_post_meta( $rule_id, 'urcr_membership_id', $membership_id );

			$migrated_membership_ids = get_option( 'urcr_migrated_membership_ids', array() );
			if ( ! is_array( $migrated_membership_ids ) ) {
				$migrated_membership_ids = array();
			}
			if ( ! in_array( $membership_id, $migrated_membership_ids, true ) ) {
				$migrated_membership_ids[] = $membership_id;
				update_option( 'urcr_migrated_membership_ids', $migrated_membership_ids );
			}

			return $rule_id;
		}

		return false;
	}
}

if ( ! function_exists( 'urcr_create_or_update_membership_rule' ) ) {
	/**
	 * Create or update membership rule with data from UI.
	 *
	 * @param int   $membership_id The membership ID.
	 * @param array $rule_data Optional rule data from UI (access_rule_data structure).
	 *
	 * @return int|false Rule ID on success, false on failure.
	 */
	function urcr_create_or_update_membership_rule( $membership_id, $rule_data = null ) {
		if ( ! ur_check_module_activation( 'membership' ) ) {
			return false;
		}

		$existing_rules = get_posts(
			array(
				'post_type'      => 'urcr_access_rule',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => 'urcr_membership_id',
						'value' => $membership_id,
					),
				),
			)
		);

		$existing_rule = ! empty( $existing_rules ) ? $existing_rules[0] : null;

		if ( $rule_data && isset( $rule_data['access_rule_data'] ) ) {
			$access_rule_data = $rule_data['access_rule_data'];

			$membership_condition = array(
				'type'  => 'membership',
				'id'    => 'x' . ( time() * 1000 ),
				'value' => array( strval( $membership_id ) ),
			);

			$existing_conditions = array();
			if ( isset( $access_rule_data['logic_map']['conditions'] ) && is_array( $access_rule_data['logic_map']['conditions'] ) ) {
				$existing_conditions = $access_rule_data['logic_map']['conditions'];
			}

			$has_membership_condition = false;
			foreach ( $existing_conditions as $key => $condition ) {
				if ( isset( $condition['type'] ) && $condition['type'] === 'membership' ) {
					$has_membership_condition             = true;
					$existing_conditions[ $key ]['value'] = array( strval( $membership_id ) );
					break;
				}
			}

			if ( ! $has_membership_condition ) {
				array_unshift( $existing_conditions, $membership_condition );
			}

			$access_rule_data['logic_map']['conditions'] = $existing_conditions;

			$membership_post  = get_post( $membership_id );
			$membership_title = $membership_post ? $membership_post->post_title : '';
			if ( ! empty( $membership_title ) ) {
				$rule_title = sprintf( __( '%s Rule', 'user-registration' ), $membership_title );
			} else {
				$rule_title = isset( $rule_data['title'] ) && ! empty( $rule_data['title'] ) ? $rule_data['title'] : __( 'Membership Access Rule', 'user-registration' );
			}

			if ( $membership_post && ! empty( $membership_post->post_content ) ) {
				$membership_content = json_decode( $membership_post->post_content, true );
				if ( isset( $membership_content['status'] ) ) {
					$access_rule_data['enabled'] = ur_string_to_bool( $membership_content['status'] );
				}
			}

			$access_rule_data = wp_unslash( $access_rule_data );
			$rule_content     = wp_json_encode( $access_rule_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$rule_content     = wp_slash( $rule_content );
		} else {
			return urcr_create_membership_rule( $membership_id );
		}

		if ( $existing_rule ) {
			$rule_post = array(
				'ID'           => $existing_rule->ID,
				'post_title'   => $existing_rule->post_title,
				'post_content' => $rule_content,
			);
			$rule_id   = wp_update_post( $rule_post );
		} else {
			$rule_post = array(
				'post_title'   => $rule_title,
				'post_content' => $rule_content,
				'post_type'    => 'urcr_access_rule',
				'post_status'  => 'publish',
			);
			$rule_id   = wp_insert_post( $rule_post );
		}

		if ( $rule_id && ! is_wp_error( $rule_id ) ) {
			update_post_meta( $rule_id, 'urcr_rule_type', 'membership' );
			update_post_meta( $rule_id, 'urcr_membership_id', $membership_id );

			return $rule_id;
		}

		return false;
	}

	if ( ! function_exists( 'ur_check_if_membership_is_team' ) ) {

		/**
		 * ur_check_if_membership_is_team
		 *
		 * @return mixed|null
		 */
		function ur_check_if_membership_is_team( $membership_id ) {
			$membership = get_post_meta( $membership_id, 'ur_membership', true );
			if ( empty( $membership ) ) {
				return false;
			}

			$membership_data = json_decode( $membership, true );
			if ( ! is_array( $membership_data ) ) {
				return false;
			}

			if ( ! empty( $membership_data['team_pricing'] ) && is_array( $membership_data['team_pricing'] ) ) {
				return true;
			}

			return false;
		}
	}

	if ( ! function_exists( 'ur_check_if_member_is_team_leader' ) ) {

		/**
		 * ur_check_if_member_is_team_leader
		 *
		 * @return mixed|null
		 */
		function ur_check_if_member_is_team_leader( $membership_id ) {

			// Check if current user is a team leader for this membership
			$current_user_id = get_current_user_id();
			$teams_id        = get_user_meta( $current_user_id, 'urm_team_ids', true );

			if ( ! empty( $teams_id ) && is_array( $teams_id ) ) {
				foreach ( $teams_id as $team_id ) {
					$team_data = get_post_meta( $team_id, 'urm_team_leader_id', true );
					if ( $team_data ) {
						if ( $team_data == $current_user_id ) {
							return true;
						}
					}
				}
			}

			return false;
		}
	}
}
