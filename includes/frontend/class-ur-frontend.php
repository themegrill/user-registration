<?php
/**
 * UserRegistration Admin.
 *
 * @class    UR_Frontend
 * @version  1.0.0
 * @package  UserRegistration/Frontend
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles frontend functionality and hooks.
 *
 * @class UR_Frontend
 * @version 1.0.0
 * @package UserRegistration/Frontend
 */

use WPEverest\URMembership\Admin\Repositories\MembersOrderRepository;
use WPEverest\URMembership\Admin\Repositories\MembersRepository;
use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Admin\Services\MembershipService;
use WPEverest\URMembership\Frontend\Frontend;

class UR_Frontend {

	/**
	 * Instance of the class.
	 *
	 * @var UR_Frontend
	 */
	private static $_instance;

	/**
	 * Class Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'includes' ) );
		add_action( 'after_setup_theme', array( $this, 'prevent_admin_access' ) );
		add_action( 'login_init', array( $this, 'prevent_core_login_page' ) );
		add_filter( 'user_registration_my_account_shortcode', array( $this, 'user_registration_my_account_layout' ) );
		add_filter( 'user_registration_before_save_profile_details', array( $this, 'user_registration_before_save_profile_details' ), 10, 3 );
		add_filter( 'user_registration_login_redirect', array( $this, 'login_redirect' ), 10, 2 );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_custom_redirect_host' ) );
		add_filter( 'user_registration_redirect_after_logout', array( $this, 'logout_redirect' ), 10, 1 );
		add_action( 'init', array( $this, 'ur_register_payment_tab_if_eligible' ) );
	}

	/**
	 * Set instance.
	 */
	public static function instance() {
		// If the single instance hasn't been set, set it now.
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Upload Files while edit profile saved.
	 *
	 * @param array $profile Profile Data.
	 * @param int   $user_id User ID.
	 * @param int   $form_id Form ID.
	 */
	public function user_registration_before_save_profile_details( $profile, $user_id, $form_id ) {

		// phpcs:disable WordPress.Security.NonceVerification

		$valid_form_data        = array();
		$previous_attachment_id = get_user_meta( $user_id, 'user_registration_profile_pic_url' );

		$disable_profile_pic = ur_option_checked( 'user_registration_disable_profile_picture', false );

		if ( $disable_profile_pic ) {
			return $profile;
		}

		if ( isset( $_POST['profile_pic_url'] ) || isset( $_POST['profile-pic-url'] ) ) {
			$value = isset( $_POST['profile_pic_url'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_pic_url'] ) ) : ( isset( $_POST['profile-pic-url'] ) ? sanitize_text_field( wp_unslash( $_POST['profile-pic-url'] ) ) : '' );
			if ( ! is_array( $value ) && ! ur_is_valid_url( $value ) ) {
				$valid_form_data['profile_pic_url']        = new stdClass();
				$valid_form_data['profile_pic_url']->value = $value;
			}
		} elseif ( isset( $_POST['form_data'] ) ) {
				$form_data = json_decode( wp_unslash( $_POST['form_data'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			foreach ( $form_data as $data ) {
				// Unrendered fields send field_name only - require value too, or this wipes the picture.
				if ( isset( $data->field_name, $data->value ) && 'user_registration_profile_pic_url' === $data->field_name ) {
					if ( ! is_array( $data->value ) && ! ur_is_valid_url( $data->value ) ) {
						$valid_form_data['profile_pic_url']        = new stdClass();
						$valid_form_data['profile_pic_url']->value = $data->value;
					}
				}
			}
		}
		if ( ! empty( $valid_form_data ) ) {
			/**
			 * Remove previous uploaded profile picture.
			 */
			$removed_attachment_id = isset( $_POST['ur_removed_profile_pic'] ) ?
				(array) json_decode( sanitize_text_field( wp_unslash( $_POST['ur_removed_profile_pic'] ) ) ) :
				array();

			if ( ! empty( $previous_attachment_id ) && ! empty( $removed_attachment_id ) && ! empty( $previous_attachment_id[0] ) ) {
				if ( in_array( $previous_attachment_id[0], $removed_attachment_id ) ) {
					// Verify the attachment belongs to this user before deleting.
					if ( (int) get_post_field( 'post_author', $previous_attachment_id[0] ) !== (int) $user_id ) {
						return $profile;
					}
					unlink( get_attached_file( $previous_attachment_id[0] ) );
					wp_delete_attachment( $previous_attachment_id[0], true );
				}
			}
			ur_upload_profile_pic( $valid_form_data, $user_id );
		}
		if ( isset( $profile['user_registration_profile_pic_url'] ) ) {
			unset( $profile['user_registration_profile_pic_url'] );
		}
		return $profile;

		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Prevent any user who cannot 'edit_posts' from accessing admin.
	 */
	public function prevent_admin_access() {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$user_meta    = get_userdata( $user_id );
			$user_roles   = $user_meta->roles;
			$option_roles = get_option( 'user_registration_general_setting_disabled_user_roles', array( 'subscriber' ) );

			if ( ! is_array( $option_roles ) ) {
				$option_roles = array();
			}

			if ( ! in_array( 'administrator', $user_roles, true ) ) {
				$result = array_intersect( $user_roles, $option_roles );

				if ( count( $result ) > 0 && apply_filters( 'user_registration_prevent_admin_access', true ) ) {
					show_admin_bar( false );
				}
			}
		}
	}

	/**
	 * Includes files.
	 */
	public function includes() {
		include_once UR_ABSPATH . 'includes' . UR_DS . 'frontend' . UR_DS . 'class-ur-frontend-form-handler.php';
	}

	/**
	 * Includes any classes we need within admin.
	 *
	 * @param mixed $field_object Field Object.
	 * @param int   $form_id Form ID.
	 */
	public function user_registration_frontend_form( $field_object, $form_id ) {
		$class_name = ur_load_form_field_class( $field_object->field_key );
		if ( class_exists( $class_name ) ) {
			$instance                   = $class_name::get_instance();
			$setting['general_setting'] = $field_object->general_setting;
			$setting['advance_setting'] = $field_object->advance_setting;
			$setting['icon']            = isset( $field_object->icon ) ? $field_object->icon : '';
			$field_type                 = ur_get_field_type( $field_object->field_key );

			// Force drop the custom class because it has been addressed in prior container.
			if ( ! empty( $setting['advance_setting']->custom_class ) ) {
				unset( $setting['advance_setting']->custom_class );
			}

			$instance->frontend_includes( $form_id, $field_type, $field_object->field_key, $setting );
		}
	}

	/**
	 * My Account layouts(vertical/horizontal) by adding class.
	 *
	 * @param array $attributes Attributes.
	 * @since  1.4.2
	 * @return  $attributes
	 */
	public function user_registration_my_account_layout( $attributes ) {

		if ( is_user_logged_in() ) {
			$layout              = get_option( 'user_registration_my_account_layout', 'vertical' );
			$attributes['class'] = $attributes['class'] . ' user-registration-MyAccount ' . $layout;
		}
		return $attributes;
	}
	public function login_redirect( $redirect, $user ) {
		if ( ! ur_string_to_bool( get_option( 'user_registration_login_options_enable_custom_redirect', false ) ) ) {
			return $redirect;
		}

		$redirect_option = get_option( 'user_registration_login_options_redirect_after_login', 'no-redirection' );

		if ( 'no-redirection' === $redirect_option ) {
			return $redirect;
		}

		if ( 'external-url' === $redirect_option ) {
			$external_url = get_option( 'user_registration_login_options_after_login_redirect_external_url', '' );
			if ( ! empty( $external_url ) && ur_is_valid_url( $external_url ) ) {
				$redirect = esc_url_raw( $external_url );
			} else {
				ur_get_logger()->info( sprintf( 'Invalid external URL %s set for after login redirection.', $external_url ), array( 'source' => 'user-registration' ) );
			}
		} elseif ( 'internal-page' === $redirect_option ) {
			$page_id = get_option( 'user_registration_login_options_after_login_redirect_page', 0 );
			if ( 0 !== absint( $page_id ) ) {
				$redirect = get_permalink( ur_get_translated_page_id( $page_id ) );
			} else {
				ur_get_logger()->info( sprintf( 'Invalid page ID %s set for after login redirection.', $page_id ), array( 'source' => 'user-registration' ) );
			}
		} elseif ( 'previous-page' === $redirect_option ) {
			if ( wp_get_referer() ) {
				$redirect = wp_get_referer();
			}
		}
		return apply_filters( 'user_registration_login_redirect_url', $redirect, $user, $redirect_option );
	}
	/**
	 * Allow the admin-configured external login redirect host through wp_validate_redirect().
	 *
	 * The after-login redirect is validated against the allowed hosts, so the External URL set
	 * in Login Options has to be listed or that setting would fall back to the home page.
	 *
	 * @since 5.2.8
	 *
	 * @param string[] $hosts Allowed redirect host names.
	 * @return string[] Allowed redirect host names.
	 */
	public function allow_custom_redirect_host( $hosts ) {
		if ( ! ur_string_to_bool( get_option( 'user_registration_login_options_enable_custom_redirect', false ) ) ) {
			return $hosts;
		}

		if ( 'external-url' !== get_option( 'user_registration_login_options_redirect_after_login', 'no-redirection' ) ) {
			return $hosts;
		}

		$external_url = get_option( 'user_registration_login_options_after_login_redirect_external_url', '' );

		if ( empty( $external_url ) || ! ur_is_valid_url( $external_url ) ) {
			return $hosts;
		}

		$host = wp_parse_url( $external_url, PHP_URL_HOST );

		if ( ! empty( $host ) && ! in_array( $host, $hosts, true ) ) {
			$hosts[] = $host;
		}

		return $hosts;
	}

	public function logout_redirect( $redirect ) {
		if ( ! ur_string_to_bool( get_option( 'user_registration_login_options_enable_custom_redirect', false ) ) ) {
			return $redirect;
		}
		$redirect_option = get_option( 'user_registration_login_options_redirect_after_logout', 'no-redirection' );

		if ( 'no-redirection' === $redirect_option ) {
			return $redirect;
		}

		if ( 'external-url' === $redirect_option ) {
			$external_url = get_option( 'user_registration_login_options_after_logout_redirect_external_url', '' );
			if ( ! empty( $external_url ) && ur_is_valid_url( $external_url ) ) {
				$redirect = $redirect . '?redirect_to_on_logout=' . $external_url;
			} else {
				ur_get_logger()->info( sprintf( 'Invalid external URL %s set for after logout redirection.', $external_url ), array( 'source' => 'user-registration' ) );
			}
		} elseif ( 'internal-page' === $redirect_option ) {
			$page_id = get_option( 'user_registration_login_options_after_logout_redirect_page', 0 );
			if ( 0 !== absint( $page_id ) ) {
				$redirect = get_permalink( ur_get_translated_page_id( $page_id ) );
			} else {
				ur_get_logger()->info( sprintf( 'Invalid page ID %s set for after logout redirection.', $page_id ), array( 'source' => 'user-registration' ) );
			}
		} elseif ( 'previous-page' === $redirect_option ) {
			if ( wp_get_referer() ) {
				$redirect = wp_get_referer();
			}
		}
		return apply_filters( 'user_registration_logout_redirect_url', $redirect, $redirect_option );
	}
	/**
	 * Prevents Core Login page.
	 *
	 * @since 1.6.0
	 */
	public function prevent_core_login_page() {
		global $action;
		$login_page     = get_post( get_option( 'user_registration_login_options_login_redirect_url', 'unset' ) );
		$myaccount_page = get_post( get_option( 'user_registration_myaccount_page_id' ) );
		$matched        = 0;
		$page_id        = 0;

		if ( ( isset( $_POST['learndash-login-form'] ) || isset( $_POST['learndash-registration-form'] ) ) ) { //phpcs:ignore
			return;
		}

		if ( ! empty( $login_page ) && 'publish' === $login_page->post_status ) {
			$matched = ur_find_my_account_in_page( $login_page->ID );
			if ( $matched > 0 ) {
				$page_id = $login_page->ID;
			}
		} elseif ( ! empty( $myaccount_page ) && 'publish' === $myaccount_page->post_status && 0 !== $page_id ) {
			$matched = ur_find_my_account_in_page( $myaccount_page->ID );
			if ( $matched > 0 ) {
				$page_id = $myaccount_page->ID;
			}
		}

		// Check if the request is for registration action.
		$request_action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
		$current_action = ! empty( $action ) ? $action : $request_action;

		if ( ! empty( $current_action ) && 'register' === $current_action ) {
			$registration_page           = null;
			$member_registration_page_id = get_option( 'user_registration_member_registration_page_id', 0 );
			if ( $member_registration_page_id ) {
				$member_page = get_post( $member_registration_page_id );
				if ( $member_page && 'publish' === $member_page->post_status && ( has_shortcode( $member_page->post_content, 'user_registration_form' ) || strpos( $member_page->post_content, '[user_registration_form id=' ) !== false ) ) {
					$registration_page = $member_page;
				}
			}

			if ( $registration_page ) {
					$redirect_url = apply_filters( 'user_registration_register_redirect_url', get_permalink( $registration_page->ID ), $registration_page->ID );
					wp_safe_redirect( $redirect_url );
					exit;
			} else {
					$url_404 = apply_filters( 'user_registration_register_404_redirect_url', home_url( '/404-not-found/' ) );
					wp_safe_redirect( $url_404, 302 );
					exit;
			}
		}

		if ( ! ( defined( 'UR_DISABLE_PREVENT_CORE_LOGIN' ) && true === UR_DISABLE_PREVENT_CORE_LOGIN ) && ur_option_checked( 'user_registration_login_options_prevent_core_login', false ) && 0 < absint( $matched ) ) {

			// Redirect to core login reset password page on multisite.
			if ( is_multisite() && ( 'lostpassword' === $action || 'resetpass' === $action ) ) {
				return;
			}

			if ( 'resetpass' === $action ) {
				$ur_reset_pass_url = get_permalink( $page_id ) . '?' . sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ?? '' ) ); //phpcs:ignore;
				wp_safe_redirect( $ur_reset_pass_url );
				exit;
			}

			$should_redirect = false;
			if ( 'register' === $action || 'lostpassword' === $action ) {
				$should_redirect = true;
			} elseif ( 'login' === $action ) {
				if ( function_exists( 'ur_is_login_form_markup_rendered' ) ) {
					$target_post = get_post( $page_id );
					if ( ! $target_post || empty( $target_post->post_content ) ) {
						return;
					}

					$rendered_content = apply_filters( 'the_content', $target_post->post_content );

					if ( ! ur_is_login_form_markup_rendered( $rendered_content ) ) {
						return;
					}
				}

				$should_redirect = true;
			}

			if ( $should_redirect ) {
				$myaccount_page = apply_filters( 'user_registration_myaccount_redirect_url', get_permalink( $page_id ), $page_id );
				wp_safe_redirect( $myaccount_page );
				exit;
			}
		}
	}

	/**
	 * Check if can add payment tabs.
	 */
	public function ur_register_payment_tab_if_eligible() {
		// Rewrite rules are global, so the endpoints are always registered regardless of the current user.
		$this->ur_add_payments_tab_endpoint();
		$this->ur_add_membership_tab_endpoint();

		$user_id = get_current_user_id();

		$payment_method = get_user_meta( $user_id, 'ur_payment_method', true );

		$user_source = get_user_meta( $user_id, 'ur_registration_source', true );

		$ur_payment_subscription = get_user_meta( $user_id, 'ur_payment_subscription', true );

		$user = wp_get_current_user();

		$is_admin = in_array( 'administrator', (array) $user->roles, true );

		if ( 'membership' === $user_source || $payment_method || $is_admin ) {
			add_filter( 'user_registration_account_menu_items', array( $this, 'urm_payment_history_tab' ), 10, 1 );
			add_action(
				'user_registration_account_urm-payments_endpoint',
				array(
					$this,
					'user_registration_urm_payments_tab_endpoint_content',
				)
			);
		}

		if ( 'membership' === $user_source || ( ! empty( $payment_method ) && ( ! empty( $ur_payment_subscription ) || 'paypal_standard' === $payment_method ) ) ) {
			add_filter( 'user_registration_account_menu_items', array( $this, 'ur_membership_tab' ), 10, 1 );
			add_action(
				'user_registration_account_ur-membership_endpoint',
				array(
					$this,
					'user_registration_membership_tab_endpoint_content',
				)
			);
		}
	}

	/**
	 * Add the item to $items array.
	 *
	 * @param array $items Items.
	 */
	public function urm_payment_history_tab( $items ) {
		$new_items                 = array();
		$new_items['urm-payments'] = __( 'Payments', 'user-registration' );
		$items                     = array_merge( $items, $new_items );

		return $this->insert_after_helper( $items, $new_items, 'edit-profile' );
	}

	/**
	 * Insert after helper.
	 *
	 * @param mixed $items Items.
	 * @param mixed $new_items New items.
	 * @param mixed $before Before item.
	 */
	public function insert_after_helper( $items, $new_items, $after ) {

		$keys     = array_keys( $items );
		$position = array_search( $after, $keys, true );

		if ( false === $position ) {
			return array_merge( $items, $new_items );
		}

		++$position;

		$return_items  = array_slice( $items, 0, $position, true );
		$return_items += $new_items;
		$return_items += array_slice( $items, $position, null, true );

		return $return_items;
	}


	/**
	 * Membership tab content.
	 */
	public function user_registration_urm_payments_tab_endpoint_content() {

		$user = wp_get_current_user();

		$is_admin = in_array( 'administrator', (array) $user->roles, true );

		if ( $is_admin ) {
			esc_html_e( 'You do not have any payment records', 'user-registration' );
			return;
		}

		do_action( 'user_registration_before_payments_tab_contents' );

		$layout = get_option( 'user_registration_my_account_layout', 'vertical' );

		if ( 'vertical' === $layout && isset( ur_get_account_menu_items()['urm-payments'] ) ) {
			?>
			<div class="user-registration-MyAccount-content__header">
				<h1><?php echo wp_kses_post( ur_get_account_menu_items()['urm-payments'] ); ?></h1>
			</div>
			<?php
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

		ur_get_template(
			'myaccount/payments.php',
			array(
				'orders'       => $this->get_user_payments( $current_page, 10 ),
				'current_page' => $current_page,
			)
		);
		do_action( 'user_registration_after_payments_tab_contents' );
	}

	/**
	 * @param $args
	 *
	 * @return array
	 */
	private function get_user_payments( $page = 1, $per_page = 10 ) {
		$user_id     = get_current_user_id();
		$user_source = get_user_meta( $user_id, 'ur_registration_source', true );
		$total_items = array();

		if ( 'membership' === $user_source ) {
			$order_repository = new MembersOrderRepository();
			$total_items      = $order_repository->get_member_all_orders( $user_id );
		}

		$meta_value = get_user_meta( $user_id, 'ur_payment_invoices', true );
		if ( 'membership' !== $user_source ) {
			if ( ! empty( $meta_value ) && is_array( $meta_value ) ) {
				foreach ( $meta_value as $values ) {
					$total_items[] = array(
						'user_id'        => $user_id,
						'transaction_id' => $values['invoice_no'] ?? '',
						'post_title'     => $values['invoice_plan'] ?? '',
						'status'         => get_user_meta( $user_id, 'ur_payment_status', true ),
						'created_at'     => $values['invoice_date'] ?? '',
						'type'           => get_user_meta( $user_id, 'ur_payment_type', true ),
						'payment_method' => str_replace( '_', ' ', get_user_meta( $user_id, 'ur_payment_method', true ) ),
						'total_amount'   => ( $values['invoice_amount'] ?? '' ),
						'currency'       => ( $values['invoice_currency'] ?? '' ),
					);
				}
			} else {
				$u_data          = get_userdata( $user_id );
				$user_registered = $u_data->user_registered;
				$total_items[]   = array(
					'user_id'        => $user_id,
					'transaction_id' => '',
					'post_title'     => __( 'Product/Service', 'user-registration' ),
					'status'         => get_user_meta( $user_id, 'ur_payment_status', true ),
					'created_at'     => date( 'Y-m-d', strtotime( $user_registered ) ),
					'type'           => 'paid',
					'payment_method' => str_replace( '_', ' ', get_user_meta( $user_id, 'ur_payment_method', true ) ),
					'total_amount'   => get_user_meta( $user_id, 'ur_payment_total_amount', true ),
					'currency'       => get_user_meta( $user_id, 'ur_payment_currency', true ),
				);
			}
		}

		if ( ! empty( $total_items ) ) {
			$total_count = count( $total_items );
			$page        = max( 1, intval( $page ) );
			$per_page    = max( 1, intval( $per_page ) );
			$offset      = ( $page - 1 ) * $per_page;
			$items       = array_slice( $total_items, $offset, $per_page );

			return array(
				'items'       => $items,
				'total_items' => $total_count,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => ( $per_page > 0 ) ? (int) ceil( $total_count / $per_page ) : 1,
			);
		}

		return array();
	}

	/**
	 * Add Membership tab endpoint.
	 */
	public function ur_add_payments_tab_endpoint() {
		$mask = Ur()->query->get_endpoints_mask();

		add_rewrite_endpoint( 'urm-payments', $mask );
		ur_maybe_flush_rewrite_rules( 'urm-payments' );
	}

	/**
	 * Add Membership tab endpoint.
	 */
	public function ur_add_membership_tab_endpoint() {
		$mask = Ur()->query->get_endpoints_mask();

		add_rewrite_endpoint( 'ur-membership', $mask );
		ur_maybe_flush_rewrite_rules( 'ur-membership' );
	}

	/**
	 * Add the item to $items array.
	 *
	 * @param array $items Items.
	 */
	public function ur_membership_tab( $items ) {
		$new_items                  = array();
		$new_items['ur-membership'] = __( 'Subscriptions', 'user-registration' );
		$items                      = array_merge( $items, $new_items );

		return $this->insert_after_helper( $items, $new_items, 'edit-profile' );
	}

	/**
	 * Membership tab content.
	 */
	public function user_registration_membership_tab_endpoint_content() {
		$user_id         = get_current_user_id();
		$user_source     = get_user_meta( $user_id, 'ur_registration_source', true );
		$total_items     = array();
		$membership_data = array();

		$payment_method = get_user_meta( $user_id, 'ur_payment_method', true );

		$user_source = get_user_meta( $user_id, 'ur_registration_source', true );

		$ur_payment_subscription = get_user_meta( $user_id, 'ur_payment_subscription', true );

		if ( 'membership' === $user_source ) {
			$membership_repositories         = new MembersRepository();
			$members_order_repository        = new MembersOrderRepository();
			$members_subscription_repository = new MembersSubscriptionRepository();
			$orders_repository               = new OrdersRepository();
			$memberships                     = $membership_repositories->get_member_memberships_by_id( $user_id );

			if ( ! empty( $memberships ) ) {

				foreach ( $memberships as $membership ) {

					if ( ! empty( $membership['post_content'] ) ) {
						$membership['post_content'] = json_decode( $membership['post_content'], true );
					}
					$membership_service = new MembershipService();
					$membership_details = ( is_array( $membership ) && ! empty( $membership['post_id'] ) ) ? $membership_service->get_membership_details( $membership['post_id'] ) : array();
					$active_gateways    = urm_get_all_active_payment_gateways();

					if ( ! empty( $active_gateways ) ) {
						$active_gateways = array_filter(
							$active_gateways,
							function ( $item, $key ) {
								return in_array( $key, array( 'paypal', 'stripe', 'bank' ) );
							},
							ARRAY_FILTER_USE_BOTH
						);
					}

					$membership['active_gateways'] = $active_gateways;
					$membership_process            = urm_get_membership_process( $user_id );

					$is_upgrading           = ! empty( $membership_process['upgrade'] ) && isset( $membership_process['upgrade'][ $membership['post_id'] ] );
					$is_purchasing_multiple = ! empty( $membership_process['multiple'] ) && in_array( $membership['post_id'], $membership_process['multiple'] );
					$is_renewing            = ! empty( $membership_process['renew'] ) && in_array( $membership['post_id'], $membership_process['renew'] );

					$last_order = $members_order_repository->get_member_orders( $user_id );
					$bank_data  = array();
					if ( ! empty( $last_order ) && $last_order['status'] == 'pending' && $last_order['payment_method'] === 'bank' ) {
						$bank_data = array(
							'show_bank_notice' => true,
							'bank_data'        => get_option( 'user_registration_global_bank_details', '' ),
							'notice_1'         => apply_filters( 'urm_bank_info_notice_1_filter', __( 'Please complete the payment using the bank details provided by the admin. <br> Once the payment is verified, your upgraded membership will be activated. Kindly wait for the admin\'s confirmation.', 'user-registration' ) ),
							'notice_2'         => apply_filters( 'urm_bank_info_notice_2_filter', __( 'Please complete the payment using the bank details provided by the admin. <br> Your membership will be renewed once the payment is verified. Kindly wait for the admin\'s confirmation.', 'user-registration' ) ),
							'notice_3'         => apply_filters( 'urm_bank_info_notice_3_filter', __( 'Please complete the payment using the bank details provided by the admin. <br> Once the payment is verified, your new membership will be activated. Kindly wait for the admin\'s confirmation.', 'user-registration' ) ),
						);
					}
					$subscription_data = $members_subscription_repository->get_subscription_data_by_subscription_id( $membership['subscription_id'] );

					$data = array(
						'membership'             => $membership,
						'is_upgrading'           => $is_upgrading,
						'is_purchasing_multiple' => $is_purchasing_multiple,
						'is_renewing'            => $is_renewing,
						'bank_data'              => $bank_data,
						'renewal_behaviour'      => get_option( 'user_registration_renewal_behaviour', 'automatic' ),
						'subscription_data'      => $subscription_data,
					);

					if ( ! empty( $last_order ) ) {
						$order_meta = $orders_repository->get_order_metas( $last_order['ID'] );

						if ( ! empty( $order_meta ) ) {
							$data['delayed_until'] = $order_meta['meta_value'];
						}
					}

					$currencies = ur_payment_integration_get_currencies();
					$currency   = get_user_meta( $user_id, 'ur_payment_currency', true );
					$currency   = empty( $currency ) ? get_option( 'user_registration_payment_currency', 'USD' ) : $currency;

					$amount = $membership['billing_amount'] ?? '';
					if ( isset( $membership['post_content']['type'] ) && 'subscription' === $membership['post_content']['type'] && isset( $membership['post_content']['amount'] ) ) {
						$amount = (float) $membership['post_content']['amount'];

						$subscription_last_order = $orders_repository->get_order_by_subscription( $membership['subscription_id'] );
						if ( ! empty( $subscription_last_order['ID'] ) ) {
							$tax_order_meta = $orders_repository->get_order_meta_by_order_id_and_meta_key( $subscription_last_order['ID'], 'tax_data' );
							$tax_data       = ! empty( $tax_order_meta['meta_value'] ) ? json_decode( $tax_order_meta['meta_value'], true ) : array();
							$tax_rate       = ! empty( $tax_data['tax_rate'] ) ? (float) $tax_data['tax_rate'] : 0;
							$is_exclusive   = ! empty( $tax_data['tax_calculation_method'] );

							if ( $tax_rate > 0 && $is_exclusive ) {
								$amount += round( $amount * $tax_rate / 100, 2 );
							}
						}

						$amount = number_format( $amount, 2, '.', '' );
					}

					if ( isset( $membership['post_content']['type'] ) && 'subscription' === $membership['post_content']['type'] ) {

						if ( isset( $membership['post_content']['amount'] ) ) {
							$amount = (float) $membership['post_content']['amount'];
						}

						$subscription_last_order = $orders_repository->get_order_by_subscription( $membership['subscription_id'] );
						if ( ! empty( $subscription_last_order['ID'] ) && 'authorize' !== $subscription_last_order['payment_method'] ) {
							$tax_order_meta = $orders_repository->get_order_meta_by_order_id_and_meta_key( $subscription_last_order['ID'], 'tax_data' );
							$tax_data       = ! empty( $tax_order_meta['meta_value'] ) ? json_decode( $tax_order_meta['meta_value'], true ) : array();
							$tax_rate       = ! empty( $tax_data['tax_rate'] ) ? (float) $tax_data['tax_rate'] : 0;
							$is_exclusive   = ! empty( $tax_data['tax_calculation_method'] );

							if ( $tax_rate > 0 && $is_exclusive ) {
								$amount += round( $amount * $tax_rate / 100, 2 );
							}
						}

						$amount = number_format( $amount, 2, '.', '' );
					}

					if ( isset( $currencies[ $currency ]['symbol_pos'] ) && 'right' === $currencies[ $currency ]['symbol_pos'] ) {
						$amount = $amount . '' . $currencies[ $currency ]['symbol'];
					} else {
						$amount = $currencies[ $currency ]['symbol'] . '' . $amount;
					}

					$duration = $membership_details['subscription']['value'] ?? '';
					if ( ! empty( $duration ) && ! empty( $membership['billing_cycle'] ) ) {

						if ( 1 === $duration ) {
							$duration_val = '';
						} else {
							$duration_val = $duration;
						}
						$data['period'] = 'subscription' === $membership['post_content']['type'] ? $amount . ' every ' . $duration_val . ' ' . ucfirst( $membership['billing_cycle'] ) : $amount;
					} else {
						$data['period'] = $amount;
					}

					// UR-4386: Local Currency terms show the full recurring price via the stored converted
					// amount, not total_amount (a coupon discounts the first order, e.g. 100% => 0).
					if ( 'free' !== ( $membership['post_content']['type'] ?? '' ) ) {
						$period_order = $orders_repository->get_order_by_subscription( $membership['subscription_id'] );

						if ( ! empty( $period_order['ID'] ) ) {
							$local_currency_meta = $orders_repository->get_order_meta_by_order_id_and_meta_key( $period_order['ID'], 'local_currency' );
							$lc_full_meta        = $orders_repository->get_order_meta_by_order_id_and_meta_key( $period_order['ID'], 'local_currency_converted_amount' );

							if ( ! empty( $local_currency_meta['meta_value'] ) && ! empty( $lc_full_meta['meta_value'] ) ) {
								$recurring_amount = (float) $lc_full_meta['meta_value'];

								// Re-apply exclusive tax (converted amount is pre-tax).
								$tax_order_meta = $orders_repository->get_order_meta_by_order_id_and_meta_key( $period_order['ID'], 'tax_data' );
								$tax_data       = ! empty( $tax_order_meta['meta_value'] ) ? json_decode( $tax_order_meta['meta_value'], true ) : array();
								if ( ! empty( $tax_data['tax_rate'] ) && ! empty( $tax_data['tax_calculation_method'] ) ) {
									$recurring_amount += round( $recurring_amount * (float) $tax_data['tax_rate'] / 100, 2 );
								}

								$order_symbol         = ur_get_currency_symbol( $local_currency_meta['meta_value'] );
								$order_amount_display = $order_symbol . number_format( $recurring_amount, 2 );

								$duration_suffix = '';
								$every_pos       = strpos( $data['period'], ' every ' );
								if ( false !== $every_pos ) {
									$duration_suffix = substr( $data['period'], $every_pos );
								}

								$data['period'] = $order_amount_display . $duration_suffix;
							}
						}
					}

					$subscription_last_order = $orders_repository->get_order_by_subscription( $membership['subscription_id'] );
					if ( ! empty( $subscription_last_order ) && $subscription_last_order['status'] === 'completed' ) {
						$data = apply_filters( 'user_registration_membership_add_team_data_if_exists', $data, $subscription_last_order );
					}

					array_push( $membership_data, $data );
				}
			}
		}

		if ( 'membership' !== $user_source && '' !== $payment_method && ( '' !== $ur_payment_subscription || 'paypal_standard' === $payment_method ) ) {
			$payment_details               = array();
			$user                          = get_userdata( $user_id );
			$form_id                       = ur_get_form_id_by_userid( $user_id );
			$ur_payment_subscription       = get_user_meta( $user_id, 'ur_payment_subscription', true );
			$payment_details['membership'] = array();

			if ( 'paypal_standard' === $payment_method ) {
				$ur_payment_subscription_status              = get_user_meta( $user_id, 'ur_paypal_subscription_status', true );
				$payment_details['membership']['post_title'] = get_user_meta( $user_id, 'ur_paypal_subscription_plan_name', true );
			} else {
				$ur_payment_subscription_status              = get_user_meta( $user_id, 'ur_payment_subscription_status', true );
				$payment_details['membership']['post_title'] = get_user_meta( $user_id, 'ur_payment_subscription_plan_name', true );
			}

			$payment_details['membership']['subscription_id'] = $ur_payment_subscription;
			$payment_details['membership']['user_id']         = $user_id;
			$payment_details['membership']['cancel_sub']      = get_user_meta( $user_id, 'ur_payment_cancel_sub', true );

			$payment_details['membership']['post_content']       = array(
				'type'   => 'subscription',
				'status' => 'active' === $ur_payment_subscription_status,
			);
			$payment_details['membership']['status']             = $ur_payment_subscription_status;
			$payment_details['membership']['expiry_date']        = get_user_meta( $user_id, 'ur_payment_subscription_expiry', true );
			$payment_details['subscription_data']['expiry_date'] = get_user_meta( $user_id, 'ur_payment_subscription_expiry', true );
			$payment_details['membership']['start_date']         = $user->user_registered;
			$payment_details['subscription_data']['start_date']  = $user->user_registered;
			$payment_details['membership']['next_billing_date']  = get_user_meta( $user_id, 'ur_payment_next_billing_date', true );

			if ( 'paypal_standard' === $payment_method ) {
				$payment_details['membership']['billing_amount'] = get_user_meta( $user_id, 'ur_payment_total_amount', true );
				$payment_details['membership']['billing_cycle']  = get_user_meta( $user_id, 'ur_paypal_interval_count', true ) . ' ' . get_user_meta( $user_id, 'ur_paypal_recurring_period', true );
			} else {
				$payment_details['membership']['billing_amount'] = get_user_meta( $user_id, 'ur_payment_product_amount', true );
				$payment_details['membership']['billing_cycle']  = get_user_meta( $user_id, 'ur_payment_interval', true );
			}

			$payment_details['membership']['currency'] = get_user_meta( $user_id, 'ur_payment_currency', true );

			$payment_details['renewal_behaviour'] = get_option( 'user_registration_renewal_type', 'automatic' );
			$currencies                           = ur_payment_integration_get_currencies();
			$currency                             = get_user_meta( $user_id, 'ur_payment_currency', true );
			$amount                               = $payment_details['membership']['billing_amount'];

			if ( isset( $currencies[ $currency ]['symbol_pos'] ) && 'right' === $currencies[ $currency ]['symbol_pos'] ) {
				$amount = $amount . '' . $currencies[ $currency ]['symbol'];
			} else {
				$amount = $currencies[ $currency ]['symbol'] . '' . $amount;
			}

			$payment_details['period'] = $amount . ' every ' . str_replace( '1 ', '', $payment_details['membership']['billing_cycle'] );
			$buttons                   = array();
			$stripe_is_enabled         = ur_string_to_bool( ur_get_single_post_meta( $form_id, 'user_registration_enable_stripe', false ) );

			if ( ur_string_to_bool( $stripe_is_enabled ) ) {
				$url                  = ( ! empty( $_SERVER['HTTPS'] ) ) ? 'https://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'] : 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
				$url                  = substr( $url, 0, strpos( $url, '?' ) );
				$cancellation_url     = wp_nonce_url( $url . '?stripe_action=cancellation&subscriptionid=' . $ur_payment_subscription, 'ur_stripe_action' );
				$reactivation_url     = wp_nonce_url( $url . '?stripe_action=reactivation&subscriptionid=' . $ur_payment_subscription, 'ur_stripe_action' );
				$recurring_is_enabled = ur_get_single_post_meta( $form_id, 'user_registration_enable_stripe_recurring', '0' );
				if ( ur_string_to_bool( $recurring_is_enabled ) && ( 'active' === $ur_payment_subscription_status || 'cancel_at_end_of_cycle' === $ur_payment_subscription_status ) ) {
					?>
					<br/>
					<div class="ur-payment-actions"	>
						<?php
						if ( 'cancel_at_end_of_cycle' === $ur_payment_subscription_status ) {
							$buttons[] = '<a id="ur_reactivate_payment" class="ur-account-action-link" href="' . esc_url_raw( $reactivation_url ) . '">' . esc_html__( 'Reactivate', 'user-registration' ) . '</a>';
						} else {
							$buttons[] = '<a id="ur_cancel_payment" class="ur-account-action-link" href="' . esc_url_raw( $cancellation_url ) . '">' . esc_html__( 'Cancel', 'user-registration' ) . '</a>';
						}
						$buttons[] = '<a id="ur_change_payment" class="ur-account-action-link" href="#">' . esc_html__( 'Change Payment', 'user-registration' ) . '</a>';
						?>
					</div>
					<?php
				}
			}

			if ( ! empty( $buttons ) ) {
				$payment_details['buttons'] = $buttons;
			}

			if ( ! empty( $payment_details ) ) {
				$payment_details['form_type'] = 'normal';

				if ( 'paypal_standard' === $payment_method ) {
					if ( ur_string_to_bool( get_user_meta( $user_id, 'ur_payment_subscription', true ) ) ) {
						array_push( $membership_data, $payment_details );
					}
				} else {
					array_push( $membership_data, $payment_details );
				}
			}
		}

		$membership_frontend = new Frontend();
		$membership_frontend->user_registration_membership_tab_endpoint_content( $membership_data );
	}
}

return new UR_Frontend();
