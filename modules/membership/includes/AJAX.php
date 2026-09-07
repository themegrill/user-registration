<?php
/**
 * URMembership AJAX
 *
 * AJAX Event Handler
 *
 * @class    AJAX
 * @package  URMembership/Ajax
 * @category Class
 * @author   WPEverest
 */

namespace WPEverest\URMembership;

use WPEverest\URMembership\Admin\Controllers\MembersController;
use WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\MembersOrderRepository;
use WPEverest\URMembership\Admin\Repositories\MembersRepository;
use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;
use WPEverest\URMembership\Admin\Services\CouponService;
use WPEverest\URMembership\Admin\Services\EmailService;
use WPEverest\URMembership\Admin\Services\MembershipGroupService;
use WPEverest\URMembership\Admin\Services\MembershipService;
use WPEverest\URMembership\Admin\Services\MembersService;
use WPEverest\URMembership\Admin\Services\PaymentGatewayLogging;
use WPEverest\URMembership\Admin\Services\PaymentService;
use WPEverest\URMembership\Admin\Services\Stripe\StripeService;
use WPEverest\URMembership\Admin\Services\SubscriptionService;
use WPEverest\URMembership\Admin\Services\UpgradeMembershipService;
use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX Class
 */
class AJAX {

	/**
	 * Hook in tabs.
	 */
	public function __construct() {
		self::add_ajax_events();
	}

	/**
	 * Hook in methods - uses WordPress ajax handlers (admin-ajax)
	 */
	public static function add_ajax_events() {

		$ajax_events = array(
			'create_membership'            => false,
			'update_membership'            => false,
			'delete_memberships'           => false,
			'delete_membership'            => false,
			'update_membership_status'     => false,
			'create_member'                => false,
			'edit_member'                  => false,
			'delete_members'               => false,
			'confirm_payment'              => true,
			'create_stripe_subscription'   => true,
			'validate_coupon'              => true,
			'cancel_subscription'          => false,
			'reactivate_membership'        => false,
			'renew_membership'             => false,
			'cancel_upcoming_subscription' => false,
			'fetch_upgradable_memberships' => false,
			'get_group_memberships'        => false,
			'get_selected_memberships'     => false,
			'create_membership_group'      => false,
			'delete_membership_group'      => false,
			'delete_membership_groups'     => false,
			'verify_pages'                 => false,
			'validate_pg'                  => false,
			'upgrade_membership'           => false,
			'add_multiple_membership'      => false,
			'get_membership_details'       => false,
			'update_membership_order'      => false,
			'fetch_upgrade_path'           => false,
			'addons_get_lists'             => false,
			'create_subscription'          => false,
			'update_subscription'          => false,
			'validate_payment_currency'    => false,
			'validate_stripe_card_mode'    => true,
		);
		foreach ( $ajax_events as $ajax_event => $nopriv ) {
			add_action( 'wp_ajax_user_registration_membership_' . $ajax_event, array( __CLASS__, $ajax_event ) );
			if ( $nopriv ) {

				add_action(
					'wp_ajax_nopriv_user_registration_membership_' . $ajax_event,
					array(
						__CLASS__,
						$ajax_event,
					)
				);
			}
		}
	}

	/**
	 * Create membership from backend
	 *
	 * @return void
	 */
	public static function create_membership() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, You do not have permission to create membership', 'user-registration' ),
				)
			);
		}
		// Developers can override the verify_nonce function.
		ur_membership_verify_nonce( 'ur_membership' );
		$membership = new MembershipService();
		$data       = isset( $_POST['membership_data'] ) ? (array) json_decode( wp_unslash( $_POST['membership_data'] ), true ) : array();

		// Get rule data from POST if available (check both in POST directly and in membership_data)
		$rule_data = null;
		if ( isset( $_POST['urcr_membership_access_rule_data'] ) && ! empty( $_POST['urcr_membership_access_rule_data'] ) ) {
			$rule_data_raw = wp_unslash( $_POST['urcr_membership_access_rule_data'] );
			$rule_data     = json_decode( $rule_data_raw, true );
		} elseif ( isset( $data['urcr_membership_access_rule_data'] ) && ! empty( $data['urcr_membership_access_rule_data'] ) ) {
			$rule_data = is_array( $data['urcr_membership_access_rule_data'] ) ? $data['urcr_membership_access_rule_data'] : json_decode( $data['urcr_membership_access_rule_data'], true );
		}

		$is_stripe_enabled = urm_is_payment_gateway_configured( 'stripe' );
		$data              = $membership->prepare_membership_post_data( $data );

		if ( isset( $data['status'] ) && ! $data['status'] ) {
			wp_send_json_error(
				array(
					'message' => $data['message'],
				)
			);
		}

		$data = apply_filters( 'ur_membership_after_create_membership_data_prepare', $data );

		$new_membership_ID = wp_insert_post( $data['post_data'] );

		if ( $new_membership_ID ) {
			if ( ! empty( $data['post_meta_data'] ) ) {
				foreach ( $data['post_meta_data'] as $datum ) {
					add_post_meta( $new_membership_ID, $datum['meta_key'], $datum['meta_value'] );
				}
			}
			$meta_data = json_decode( $data['post_meta_data']['ur_membership']['meta_value'], true );

			if ( $is_stripe_enabled && 'free' !== $meta_data['type'] ) {
				$stripe_service        = new StripeService();
				$data['membership_id'] = $new_membership_ID;
				$membership_repository = new MembershipRepository();
				$membership            = $membership_repository->get_single_membership_by_ID( $new_membership_ID );
				try {
					$stripe_service->sync_product_and_price_in_stripe( $membership );
				} catch ( \Exception $e ) {
					wp_delete_post( $new_membership_ID, true );
					wp_send_json_error( array( 'message' => $e->getMessage() ) );
				}
			}

			// Create or update content access rule if rule data provided
			if ( $rule_data && function_exists( 'urcr_create_or_update_membership_rule' ) ) {
				$_POST['urcr_membership_access_rule_data'] = wp_unslash( json_encode( $rule_data ) );
				urcr_create_or_update_membership_rule( $new_membership_ID, $rule_data );
			}

			$response = array(
				'membership_id' => $new_membership_ID,
				'message'       => esc_html__( 'Successfully created the membership . ', 'user-registration' ),
			);

			$response = apply_filters( 'ur_membership_before_create_membership_response', $response );
			wp_send_json_success( $response );
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry! There was an unexpected error while saving the membership data . ', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Update membership from backend
	 *
	 * @return void
	 */
	public static function update_membership() {
		if ( empty( $_POST['membership_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field membership_id is required.', 'user-registration' ),
				)
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, You do not have permission to edit membership', 'user-registration' ),
				)
			);
		}
		ur_membership_verify_nonce( 'ur_membership' );

		$membership         = new MembershipService();
		$data               = isset( $_POST['membership_data'] ) ? (array) json_decode( wp_unslash( $_POST['membership_data'] ), true ) : array();
		$membership_details = $membership->get_membership_details( $_POST['membership_id'] );
		// Get rule data from POST if available (check both in POST directly and in membership_data)
		$rule_data = null;
		if ( isset( $_POST['urcr_membership_access_rule_data'] ) && ! empty( $_POST['urcr_membership_access_rule_data'] ) ) {
			$rule_data_raw = wp_unslash( $_POST['urcr_membership_access_rule_data'] );
			$rule_data     = json_decode( $rule_data_raw, true );
		}

		$is_stripe_enabled = urm_is_payment_gateway_configured( 'stripe' );
		$is_mollie_enabled = urm_is_payment_gateway_configured( 'mollie' );

		$data = $membership->prepare_membership_post_data( $data );

		if ( isset( $data['status'] ) && ! $data['status'] ) {
			wp_send_json_error(
				array(
					'message' => $data['message'],
				)
			);
		}

		$data = apply_filters( 'ur_membership_after_create_membership_data_prepare', $data );

		if ( $is_stripe_enabled ) {
			$meta_data_check = json_decode( $data['post_meta_data']['ur_membership']['meta_value'], true );
			if (
				'free' !== $meta_data_check['type'] &&
				isset( $meta_data_check['subscription']['duration'], $meta_data_check['subscription']['value'] ) &&
				'year' === $meta_data_check['subscription']['duration'] &&
				(int) $meta_data_check['subscription']['value'] > 3
			) {
				wp_send_json_error(
					array(
						'message' => __( 'Stripe does not support yearly subscription periods greater than 3 years.', 'user-registration' ),
					)
				);
			}
		}

		$updated_ID = wp_insert_post( $data['post_data'] );

		if ( $updated_ID ) {
			if ( ! empty( $data['post_meta_data'] ) ) {
				foreach ( $data['post_meta_data'] as $datum ) {
					update_post_meta( $updated_ID, $datum['meta_key'], $datum['meta_value'] );
				}
			}

			$meta_data = json_decode( $data['post_meta_data']['ur_membership']['meta_value'], true );

			if ( $is_stripe_enabled && 'free' !== $meta_data['type'] ) {
				$stripe_service = new StripeService();
				try {
					$stripe_result = $stripe_service->sync_product_and_price_in_stripe(
						array(
							'ID'         => $updated_ID,
							'post_title' => $data['post_data']['post_title'],
							'meta_value' => $meta_data,
						)
					);

					if ( empty( $stripe_result['success'] ) ) {
						wp_send_json_error(
							array(
								'message' => $stripe_result['message']
									?? __( 'Could not update product/price in Stripe.', 'user-registration' ),
							)
						);
					}
				} catch ( \Exception $e ) {
					wp_send_json_error(
						array(
							'message' => $e->getMessage(),
						)
					);
				}
			}

			// Create or update content access rule if rule data provided
			if ( $rule_data && function_exists( 'urcr_create_or_update_membership_rule' ) ) {
				urcr_create_or_update_membership_rule( $updated_ID, $rule_data );
			}

			$response = array(
				'membership_id' => $updated_ID,
				'message'       => esc_html__( 'Successfully updated the membership data.', 'user-registration' ),
			);

			$response = apply_filters( 'ur_membership_before_create_membership_response', $response );
			wp_send_json_success( $response );
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry! There was an unexpected error while saving the membership data . ', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Get membership details for a given membership ID.
	 *
	 * @since 5.0.0
	 */
	public static function get_membership_details() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view membership details.', 'user-registration' ) ) );
		}

		$membership_id = isset( $_POST['membership_id'] ) ? sanitize_text_field( wp_unslash( $_POST['membership_id'] ) ) : '';

		if ( empty( $membership_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Membership ID is missing.', 'user-registration' ) ) );
		}

		$membership_details = ur_get_membership_details();

		if ( is_wp_error( $membership_details ) ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong.', 'user-registration' ) ) );
		}

		$membership_detail = array();
		foreach ( $membership_details as $details ) {
			if ( isset( $details['ID'] ) && $membership_id === $details['ID'] ) {
				$date = explode( ' ', $details['period'] );

				$value  = isset( $date[2] ) && is_numeric( $date[2] ) ? (int) $date[2] : '';
				$period = isset( $date[3] ) ? trim( $date[3] ) : '';

				$list_of_period = array(
					'Day'    => 'D',
					'Days'   => 'D',
					'Week'   => 'W',
					'Weeks'  => 'W',
					'Month'  => 'M',
					'Months' => 'M',
					'Year'   => 'Y',
					'Years'  => 'Y',
				);

				$expiration_on = 'N/A';

				if ( ! empty( $period ) && ! empty( $value ) && isset( $list_of_period[ $period ] ) ) {
					$start_date = new \DateTime( date( 'Y-m-d' ) ?? '' );

					$intervalSpec = 'P' . $value . $list_of_period[ $period ];

					$interval = new \DateInterval( $intervalSpec );
					$start_date->add( $interval );
					$expiration_on = $start_date->format( 'F j, Y' );
				}

				$membership_detail['amount']              = html_entity_decode( $details['period'] );
				$membership_detail['subscription_status'] = __( 'Pending', 'user-registration' );
				$membership_detail['expiration_on']       = $expiration_on;

				break;
			}
		}

		wp_send_json_success(
			array(
				'membership_detail' => $membership_detail,
			)
		);
	}

	/**
	 * Delete multiple Memberships
	 *
	 * @return void
	 */
	public static function delete_membership() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission not allowed.', 'user-registration' ),
				),
				403
			);
		}

		ur_membership_verify_nonce( 'ur_membership' );
		if ( empty( $_POST['membership_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field membership_id is required.', 'user-registration' ),
				),
				422
			);
		}
		$membership_id = absint( $_POST['membership_id'] );

		$membership_service = new MembershipService();
		$deleted            = $membership_service->delete_membership( $membership_id );
		if ( $deleted['status'] ) {
			wp_send_json_success(
				array(
					'message' => esc_html__( 'Membership deleted successfully.', 'user-registration' ),
				)
			);
		}
		wp_send_json_error(
			array(
				'message' => $deleted['message'],
			)
		);
	}

	/**
	 * Delete multiple Memberships
	 *
	 * @return void
	 */
	public static function delete_memberships() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission not allowed.', 'user-registration' ),
				),
				403
			);
		}
		ur_membership_verify_nonce( 'ur_membership' );
		if ( ! isset( $_POST ) && ! isset( $_POST['membership_ids'] ) && ! empty( $_POST['membership_ids'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field membership_ids is required.', 'user-registration' ),
				),
				422
			);
		}
		$membership_ids = wp_unslash( $_POST['membership_ids'] );
		$membership_ids = implode( ',', json_decode( $membership_ids, true ) );

		$membership_repository = new MembershipRepository();
		$deleted               = $membership_repository->delete_multiple( $membership_ids );
		if ( $deleted ) {
			wp_send_json_success(
				array(
					'message' => esc_html__( 'Memberships deleted successfully.', 'user-registration' ),
				)
			);
		}
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Sorry! There was an unexpected error while deleting the memberships.', 'user-registration' ),
			)
		);
	}

	/**
	 * Delete multiple Memberships
	 *
	 * @return void
	 */
	public static function delete_membership_group() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission not allowed.', 'user-registration' ),
				),
				403
			);
		}

		ur_membership_verify_nonce( 'ur_membership_group' );
		if ( empty( $_POST['membership_group_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field membership_group_id is required.', 'user-registration' ),
				),
				422
			);
		}
		$membership_group_id   = absint( $_POST['membership_group_id'] );
		$membership_service    = new MembershipGroupService();
		$membership_group_form = $membership_service->check_if_group_used_in_form( $membership_group_id );
		if ( false === $membership_group_form ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry! There was an unexpected error while deleting the membership group.', 'user-registration' ),
				)
			);
		}
		$membership_group_repository = new MembershipGroupRepository();
		$deleted                     = $membership_group_repository->delete( $membership_group_id );
		if ( $deleted ) {
			wp_send_json_success(
				array(
					'message' => esc_html__( 'Membership Group deleted successfully.', 'user-registration' ),
				)
			);
		}
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Sorry! There was an unexpected error while deleting the membership group.', 'user-registration' ),
			)
		);
	}

	/**
	 * Delete multiple Memberships
	 *
	 * @return void
	 */
	public static function delete_membership_groups() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission not allowed.', 'user-registration' ),
				),
				403
			);
		}
		ur_membership_verify_nonce( 'ur_membership_group' );
		if ( ! isset( $_POST ) || empty( $_POST['membership_group_ids'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field membership_group_ids is required.', 'user-registration' ),
				),
				422
			);
		}
		$membership_group_ids = wp_unslash( $_POST['membership_group_ids'] );
		$membership_service   = new MembershipGroupService();
		$membership_group_ids = $membership_service->remove_form_related_groups( json_decode( $membership_group_ids, true ) );

		$membership_group_ids = implode( ',', $membership_group_ids );

		$membership_group_repository = new MembershipGroupRepository();

		$deleted = $membership_group_repository->delete_multiple( $membership_group_ids );
		if ( $deleted ) {
			wp_send_json_success(
				array(
					'message' => esc_html__( 'Memberships Groups deleted successfully.', 'user-registration' ),
				)
			);
		}
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Sorry! There was an unexpected error while deleting the membership groups.', 'user-registration' ),
			)
		);
	}

	/**
	 * Delete multiple Members
	 *
	 * @return void
	 */
	public static function delete_members() {
		if ( ! current_user_can( 'delete_users' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission not allowed.', 'user-registration' ),
				),
				403
			);
		}
		ur_membership_verify_nonce( 'ur_members' );
		if ( ! isset( $_POST ) && ! isset( $_POST['members_ids'] ) && ! empty( $_POST['members_ids'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field members_ids is required.', 'user-registration' ),
				),
				422
			);
		}
		$members_ids        = wp_unslash( $_POST['members_ids'] );
		$members_ids        = array_map( 'absint', (array) json_decode( $members_ids, true ) );
		$members_ids        = implode( ',', $members_ids );
		$members_repository = new MembersRepository();
		$deleted            = $members_repository->delete_multiple( $members_ids );
		if ( $deleted ) {
			wp_send_json_success(
				array(
					'message' => esc_html__( 'Members deleted successfully.', 'user-registration' ),
				)
			);
		}
		wp_send_json_error(
			array(
				'message' => esc_html__( 'Sorry! There was an unexpected error while deleting the selected members.', 'user-registration' ),
			)
		);
	}

	/**
	 * Update just the membership status from list-table.
	 *
	 * @return void
	 */
	public static function update_membership_status() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, You do not have permission to edit membership', 'user-registration' ),
				)
			);
		}
		ur_membership_verify_nonce( 'ur_membership' );

		$data                   = json_decode( wp_unslash( $_POST['membership_data'] ), true );
		$post                   = get_post( $data['ID'] );
		$post_content           = json_decode( wp_unslash( $post->post_content ), true );
		$post_content['status'] = $data['status'];
		unset( $data['status'] );
		$data['post_content'] = wp_json_encode( $post_content );
		$updated_ID           = wp_update_post( $data );
		if ( $updated_ID ) {
			wp_send_json_success(
				array(
					'membership_id' => $updated_ID,
					'message'       => esc_html__( 'Successfully updated the membership status . ', 'user-registration' ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry! There was an unexpected error while saving the membership data . ', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Update membership order.
	 *
	 * @return void
	 */
	public static function update_membership_order() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry, You do not have permission to update membership order', 'user-registration' ),
				)
			);
		}

		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ur_membership_update_order' ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Security check failed. Please refresh the page and try again.', 'user-registration' ),
				)
			);
		}

		// Get membership order array
		if ( ! isset( $_POST['membership_order'] ) || ! is_array( $_POST['membership_order'] ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Invalid membership order data.', 'user-registration' ),
				)
			);
		}

		// Sanitize membership IDs
		$membership_order = array_map( 'absint', $_POST['membership_order'] );

		// Validate that all IDs are valid membership posts
		$valid_ids = array();
		foreach ( $membership_order as $membership_id ) {
			$post = get_post( $membership_id );
			if ( $post && 'ur_membership' === $post->post_type ) {
				$valid_ids[] = $membership_id;
			}
		}

		if ( empty( $valid_ids ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'No valid membership IDs provided.', 'user-registration' ),
				)
			);
		}

		// Save order to WordPress option
		$updated = update_option( 'ur_membership_order', $valid_ids, false );

		if ( $updated !== false ) {
			wp_send_json_success(
				array(
					'message' => esc_html__( 'Membership order updated successfully.', 'user-registration' ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Failed to update membership order.', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Create member from backend.
	 *
	 * @return void
	 */
	public static function create_member() {

		if ( ! current_user_can( 'add_users' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, You do not have permission to create users', 'user-registration' ),
				)
			);
		}
		ur_membership_verify_nonce( 'ur_members' );
		$data               = isset( $_POST['members_data'] ) ? (array) json_decode( wp_unslash( $_POST['members_data'] ), true ) : array();
		$members_controller = new MembersController( new MembersRepository(), new OrdersRepository(), new SubscriptionRepository() );

		$response = $members_controller->create_members_admin( $data );

		if ( $response['status'] ) {
			wp_send_json_success(
				array(
					'member_id' => $response['member_id'],
					'message'   => get_option( 'user_registration_successful_membership_creation_message', esc_html__( 'New member has been successfully created.', 'user-registration' ) ),
				)
			);
		} else {
			$message = isset( $response['message'] ) ? $response['message'] : esc_html__( 'Sorry! There was an unexpected error while saving the members data . ', 'user-registration' );
			wp_send_json_error(
				array(
					'message' => $message,
				)
			);
		}
	}

	/**
	 * Create edit member from backend.
	 *
	 * @return void
	 */
	public static function edit_member() {

		if ( ! current_user_can( 'edit_users' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, You do not have permission to create users', 'user-registration' ),
				)
			);
		}
		ur_membership_verify_nonce( 'ur_edit_members' );
		$data               = isset( $_POST['members_data'] ) ? (array) json_decode( wp_unslash( $_POST['members_data'] ), true ) : array();
		$members_controller = new MembersController( new MembersRepository(), new OrdersRepository(), new SubscriptionRepository() );

		$response = $members_controller->update_members_admin( $data );

		if ( $response['status'] ) {
			wp_send_json_success(
				array(
					'member_id' => $response['member_id'],
					'message'   => esc_html__( 'Member has been successfully updated. ', 'user-registration' ),
				)
			);
		} else {
			$message = isset( $response['message'] ) ? $response['message'] : esc_html__( 'Sorry! There was an unexpected error while updating the members data. ', 'user-registration' );
			wp_send_json_error(
				array(
					'message' => $message,
				)
			);
		}
	}

	/**
	 * Get coupon detail.
	 *
	 * @return void
	 */
	public static function validate_coupon() {
		ur_membership_verify_nonce( 'ur_members_frontend' );

		$rate_limit_key = 'urm_coupon_validate_' . md5( ur_get_ip_address() );
		$attempts       = (int) get_transient( $rate_limit_key );

		if ( $attempts >= 10 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many coupon attempts. Please try again later.', 'user-registration' ),
				),
				429
			);
		}

		set_transient( $rate_limit_key, $attempts + 1, MINUTE_IN_SECONDS );

		$data           = isset( $_POST['coupon_data'] ) ? (array) wp_unslash( $_POST['coupon_data'] ) : array();
		$coupon_service = new CouponService();

		$response = $coupon_service->validate( $data );

		if ( $response['status'] ) {
			wp_send_json_success(
				array(
					'message' => $response['message'],
					'data'    => $response['data'],
				),
				$response['code']
			);
		}
		wp_send_json_error(
			array(
				'message' => $response['message'],
			),
			$response['code']
		);
	}

	/**
	 * Validate Stripe card mode before user registration.
	 * Checks whether the submitted payment method's livemode matches the
	 * configured Stripe mode, preventing test cards in live mode and vice versa.
	 *
	 * @return void
	 */
	public static function validate_stripe_card_mode() {
		ur_membership_verify_nonce( 'ur_members_frontend' );

		$payment_method_id = isset( $_POST['payment_method_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method_id'] ) ) : '';

		if ( empty( $payment_method_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Payment method ID is required.', 'user-registration' ) ) );
		}

		$stripe_service = new StripeService();
		$result         = $stripe_service->validate_card_mode( $payment_method_id );

		if ( $result['valid'] ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * Verify a logged-out payment confirmation belongs to the browser that created
	 * this pending registration: the cookie must match the signup transient, or
	 * (UR-4727) the server-recomputed HMAC when the transient was cleared mid-flow.
	 *
	 * @param int $member_id Pending member/user ID.
	 * @return bool
	 */
	private static function verify_pending_member_session( $member_id ) {
		$cookie_key   = 'urm_pending_login_' . $member_id;
		$cookie_token = isset( $_COOKIE[ $cookie_key ] ) ? wp_unslash( $_COOKIE[ $cookie_key ] ) : '';

		if ( empty( $cookie_token ) ) {
			return false;
		}

		$saved_hash = get_transient( $cookie_key );
		if ( ! empty( $saved_hash ) && hash_equals( (string) $saved_hash, (string) $cookie_token ) ) {
			return true;
		}

		// Fallback: transient gone but the cookie still proves this browser registered.
		$expected_hash = hash_hmac( 'sha256', (string) $member_id, wp_salt( 'auth' ) );

		return hash_equals( $expected_hash, (string) $cookie_token );
	}

	public static function confirm_payment() {

		ur_membership_verify_nonce( 'urm_confirm_payment' );
		if ( empty( $_POST['member_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field member_id is required', 'user-registration' ),
				)
			);
		}
		if ( empty( $_POST['form_response'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field form_response is required', 'user-registration' ),
				)
			);
		}

		$member_id           = absint( $_POST['member_id'] );
		$orders_repository   = new MembersOrderRepository();
		$member_latest_order = $orders_repository->get_member_orders( $member_id );
		$has_pending_order   = ! empty( $member_latest_order ) && 'pending' === ( $member_latest_order['status'] ?? '' );
		$membership_process  = urm_get_membership_process( $member_id );
		$selected_membership_id = isset( $_POST['selected_membership_id'] ) && '' !== $_POST['selected_membership_id'] ? absint( $_POST['selected_membership_id'] ) : 0;
		$current_membership_id  = isset( $_POST['current_membership_id'] ) && '' !== $_POST['current_membership_id'] ? absint( $_POST['current_membership_id'] ) : 0;
		$is_upgrading           = ! empty( $membership_process['upgrade'] ) && isset( $membership_process['upgrade'][ $current_membership_id ] ) && empty( absint( $_POST['team_id'] ) );
		$is_purchasing_multiple = ! empty( $membership_process['multiple'] ) && in_array( $selected_membership_id, $membership_process['multiple'] );
		$is_renewing            = ! empty( $membership_process['renew'] ) && in_array( $current_membership_id, $membership_process['renew'] );

		if ( ! $has_pending_order && ! $is_upgrading && ! $is_renewing && ! $is_purchasing_multiple ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid Request.', 'user-registration' ),
				)
			);
		}
		if ( is_user_logged_in() ) {
			if ( ! current_user_can( 'edit_user', $member_id ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You are not allowed to edit this user.', 'user-registration' ),
					)
				);
			}
		} else {
			// Nopriv: bind to the registration session that created this pending member.
			if ( ! self::verify_pending_member_session( $member_id ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You are not allowed to edit this user.', 'user-registration' ),
					)
				);
			}
		}
		$stripe_service = new StripeService();
		$payment_status = sanitize_text_field( $_POST['payment_status'] );

		$update_stripe_order = $stripe_service->update_order( $_POST );

		if ( $update_stripe_order['status'] ) {

			// UR-4710: payment confirmed — apply any deferred membership role for this in-session member.
			( new MembersService() )->maybe_grant_pending_role( $member_id );

			if ( $is_upgrading ) {
				$next_subscription     = json_decode( get_user_meta( $member_id, 'urm_next_subscription_data', true ), true );
				$previous_subscription = get_user_meta( $member_id, 'urm_previous_subscription_data', true );
				$is_delayed            = ! empty( $next_subscription['delayed_until'] );
				if ( ! empty( $previous_subscription ) && ! $is_delayed ) {
					$previous_subscription = json_decode( $previous_subscription, true );
					$previous_order        = json_decode( get_user_meta( $member_id, 'urm_previous_order_data', true ), true );
					$subscription_service  = new SubscriptionService();
					$subscription_service->cancel_subscription( is_array( $previous_order ) ? $previous_order : array(), $previous_subscription, true );
					delete_user_meta( $member_id, 'urm_next_subscription_data' );
					delete_user_meta( $member_id, 'urm_previous_subscription_data' );
					delete_user_meta( $member_id, 'urm_previous_order_data' );
				}
			}

			$form_response = isset( $_POST['form_response'] ) ? (array) json_decode( wp_unslash( $_POST['form_response'] ), true ) : array();
			$data          = apply_filters( 'user_registration_membership_before_register_member', isset( $_POST['members_data'] ) ? (array) json_decode( wp_unslash( $_POST['members_data'] ), true ) : array() );
			if ( ! empty( $form_response ) && isset( $form_response['auto_login'] ) && $payment_status !== 'failed' ) {
				$members_service = new MembersService();
				$password        = isset( $data['password'] ) ? $data['password'] : '';
				$logged_in       = $members_service->login_member( $member_id, true, $password );
				if ( ! $logged_in ) {
					wp_send_json_error(
						array(
							'message' => isset( $update_stripe_order['message'] ) ? $update_stripe_order['message'] : __( 'Something went wrong when updating users payment status', 'user-registration' ),
						),
						500
					);
				}
			}

			delete_transient( 'urm_pending_login_' . $member_id );
			$response = array(
				'message'                => $update_stripe_order['message'] ?? '',
				'is_upgrading'           => ur_string_to_bool( $is_upgrading ),
				'is_renewing'            => ur_string_to_bool( $is_renewing ),
				'is_purchasing_multiple' => ur_string_to_bool( $is_purchasing_multiple ),
			);
			if ( $is_upgrading ) {
				$response['message'] = __( 'Membership upgraded successfully', 'user-registration' );
				unset( $membership_process['upgrade'][ $current_membership_id ] );
				update_user_meta( $member_id, 'urm_membership_process', $membership_process );
				update_user_meta( $member_id, 'urm_is_user_upgraded', 1 );
			}
			if ( $is_purchasing_multiple ) {
				$response['message'] = __( 'Membership purchased successfully', 'user-registration' );
				unset( $membership_process['multiple'][ array_search( $selected_membership_id, $membership_process['multiple'], true ) ] );
				update_user_meta( $member_id, 'urm_membership_process', $membership_process );
			}

			if ( $is_renewing ) {
				$response['message']            = __( 'Membership has been successfully renewed.', 'user-registration' );
				$subscription_service           = new SubscriptionService();
				$members_subscription_repo      = new MembersSubscriptionRepository();
				$membership_repository          = new MembershipRepository();
				$member_subscription            = $members_subscription_repo->get_subscription_data_by_member_and_membership_id( $member_id, $current_membership_id );
				$membership                     = $membership_repository->get_single_membership_by_ID( $member_subscription['item_id'] );
				$membership_metas               = wp_unslash( json_decode( $membership['meta_value'], true ) );
				$membership_metas['post_title'] = $membership['post_title'];
				$subscription_service->update_subscription_data_for_renewal( $member_subscription, $membership_metas );
			}
			wp_send_json_success(
				$response
			);
		}

		if ( $is_upgrading ) {
			$stripe_service->revert_subscription( $member_id );
		}
		wp_send_json_error(
			array(
				'message' => isset( $update_stripe_order['message'] ) ? $update_stripe_order['message'] : __( 'Something went wrong when updating users payment status', 'user-registration' ),
			),
			500
		);
	}

	/**
	 * cancel_subscription
	 */
	public static function cancel_subscription() {
		$security = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( '' === $security || ! wp_verify_nonce( $security, 'ur_members_frontend' ) ) {
			wp_send_json_error( 'Nonce verification failed' );

			return;
		}

		if ( ! isset( $_POST['subscription_id'] ) ) {
			wp_send_json_error( __( 'Wrong request.', 'user-registration' ) );
		}

		$subscription_id = absint( $_POST['subscription_id'] );

		$subscription_repository = new SubscriptionRepository();
		$user_subscription       = $subscription_repository->retrieve( $subscription_id );
		if ( empty( $user_subscription ) ) {
			wp_send_json_error(
				array(
					'message' => __( "User's subscription not found.", 'user-registration' ),
				)
			);
		}
		$user_id = ! empty( $user_subscription['user_id'] ) ? $user_subscription['user_id'] : '';

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to edit this user.', 'user-registration' ),
				)
			);
		}

		// Get user and order data for logging
		$user              = get_userdata( $user_id );
		$orders_repository = new OrdersRepository();
		$order             = $orders_repository->get_order_by_subscription( $subscription_id );
		$payment_gateway   = ! empty( $order['payment_method'] ) ? $order['payment_method'] : 'unknown';

		// Get membership type for logging
		$membership_repository = new MembershipRepository();
		$membership_type       = 'unknown';
		if ( ! empty( $user_subscription['item_id'] ) ) {
			$membership = $membership_repository->get_single_membership_by_ID( $user_subscription['item_id'] );
			if ( ! empty( $membership ) && ! empty( $membership['meta_value'] ) ) {
				$membership_metas = json_decode( $membership['meta_value'], true );
				$membership_type  = $membership_metas['type'] ?? 'unknown';
			}
		}

		// Log session start with divider
		if ( class_exists( 'WPEverest\URMembership\Admin\Services\PaymentGatewayLogging' ) ) {
			// Add session divider

			\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
				$payment_gateway,
				'=============== CANCELLATION PAYMENT SESSION ===============' . "\n" . wp_json_encode(
					array(
						'timestamp'       => current_time( 'mysql' ),
						'membership_type' => $membership_type,
						'username'        => $user ? $user->user_login : 'unknown',
					),
					JSON_PRETTY_PRINT
				),
				'notice'
			);

			// Log cancellation initiation
			\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
				$payment_gateway,
				'Membership cancellation initiated' . "\n" . wp_json_encode(
					array(
						'event_type'      => 'cancellation_started',
						'member_id'       => $user_id,
						'username'        => $user ? $user->user_login : 'unknown',
						'email'           => $user ? $user->user_email : 'unknown',
						'subscription_id' => $subscription_id,
						'membership_id'   => $user_subscription['item_id'] ?? 'N/A',
						'payment_method'  => $payment_gateway,
						'membership_type' => $membership_type,
					),
					JSON_PRETTY_PRINT
				),
				'info'
			);
		}

		$cancel_status = $subscription_repository->cancel_subscription_by_id( $subscription_id );

		if ( $cancel_status['status'] ) {

			// Prepare data to register subscription cancellation event .
			$payload = array(
				'subscription_id' => $order['subscription_id'],
				'member_id'       => $order['user_id'],
				'event_type'      => 'canceled',
				'meta'            => array(
					'order_id'       => $order ? $order['ID'] : 0,
					'transaction_id' => $order ? $order['transaction_id'] : '',
					'payment_method' => $order['payment_method'],
				),
			);

			do_action( 'ur_membership_subscription_event_triggered', $payload );

			wp_send_json_success(
				array(
					'message' => $cancel_status['message'],
				)
			);
		} else {
			$message = isset( $cancel_status['message'] ) ? $cancel_status['message'] : esc_html__( 'Something went wrong while cancelling your subscription. Please contact support', 'user-registration' );

			$payload = array(
				'subscription_id' => $order['subscription_id'],
				'member_id'       => $order['user_id'],
				'event_type'      => 'cancellation_failed',
				'meta'            => array(
					'order_id'       => $order ? $order['ID'] : 0,
					'payment_method' => $payment_gateway,
				),
			);

			do_action( 'ur_membership_subscription_event_triggered', $payload );

			wp_send_json_error(
				array(
					'message' => $message,
				)
			);

		}
	}

	public static function create_stripe_subscription() {
		ur_membership_verify_nonce( 'urm_confirm_payment' );
		$customer_id       = isset( $_POST['customer_id'] ) ? $_POST['customer_id'] : '';
		$payment_method_id = isset( $_POST['payment_method_id'] ) ? sanitize_text_field( $_POST['payment_method_id'] ) : '';
		$member_id           = absint( wp_unslash( $_POST['member_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$orders_repository   = new MembersOrderRepository();
		$member_latest_order = $orders_repository->get_member_orders( $member_id );
		$has_pending_order   = ! empty( $member_latest_order ) && 'pending' === ( $member_latest_order['status'] ?? '' );

		$membership_process     = urm_get_membership_process( $member_id );
		$selected_membership_id = isset( $_POST['selected_membership_id'] ) && '' !== $_POST['selected_membership_id'] ? absint( $_POST['selected_membership_id'] ) : 0;
		$current_membership_id  = isset( $_POST['current_membership_id'] ) && '' !== $_POST['current_membership_id'] ? absint( $_POST['current_membership_id'] ) : 0;
		$is_purchasing_multiple = ! empty( $membership_process['multiple'] ) && in_array( $selected_membership_id, $membership_process['multiple'] );
		$is_upgrading           = ! empty( $membership_process['upgrade'] ) && isset( $membership_process['upgrade'][ $current_membership_id ] ) && empty( absint( $_POST['team_id'] ) );
		$is_renewing            = ! empty( $membership_process['renew'] ) && in_array( $current_membership_id, $membership_process['renew'] );
		$team_id                = ! empty( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0;

		if ( ! $has_pending_order && ! $is_upgrading && ! $is_renewing && ! $is_purchasing_multiple ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid Request.', 'user-registration' ),
				)
			);
		}
		if ( is_user_logged_in() ) {
			if ( ! current_user_can( 'edit_user', $member_id ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You are not allowed to edit this user.', 'user-registration' ),
					)
				);
			}
		} else {
			// Nopriv: bind to the registration session that created this pending member.
			if ( ! self::verify_pending_member_session( $member_id ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You are not allowed to edit this user.', 'user-registration' ),
					)
				);
			}
		}
		$stripe_service      = new StripeService();
		$form_response       = isset( $_POST['form_response'] ) ? (array) json_decode( wp_unslash( $_POST['form_response'] ), true ) : array();
		$stripe_subscription = $stripe_service->create_subscription( $customer_id, $payment_method_id, $member_id, $is_upgrading, $team_id );

		if ( $stripe_subscription['status'] ) {
			$subscription_status = isset( $stripe_subscription['subscription']->status )
				? $stripe_subscription['subscription']->status
				: '';

			$subscription_is_active = in_array( $subscription_status, array( 'active', 'trialing' ), true );

			$data = apply_filters( 'user_registration_membership_before_register_member', isset( $_POST['members_data'] ) ? (array) json_decode( wp_unslash( $_POST['members_data'] ), true ) : array() );

			if ( $subscription_is_active && ! empty( $form_response ) && isset( $form_response['auto_login'] ) && $form_response['auto_login'] ) {
				$members_service = new MembersService();
				$logged_in       = $members_service->login_member( $member_id, true, isset( $data['password'] ) ? $data['password'] : '' );
				if ( ! $logged_in ) {
					wp_send_json_error(
						array(
							'message' => __( 'Invalid User', 'user-registration' ),
						)
					);
				}
			}
			wp_send_json_success( $stripe_subscription );
		} else {
			if ( ! $is_upgrading && ! $is_renewing && ! $is_purchasing_multiple ) {
				if ( absint( $member_id ) === get_current_user_id() || current_user_can( 'edit_users' ) ) {
					wp_delete_user( absint( $member_id ) );
				}
			}

			wp_send_json_error(
				array(
					'message' => __( 'Something went wrong when updating users payment status', 'user-registration' ),
				)
			);
		}
	}

	public static function create_subscription() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, you do not have permission to create subscription', 'user-registration' ),
				)
			);
		}

		ur_membership_verify_nonce( 'ur_membership_subscription' );

		$subscription_data = $_POST;  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- L:750

		if ( empty( $subscription_data['member'] ) || empty( $subscription_data['plan'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please fill in all required fields.', 'user-registration' ),
				)
			);
		}

		$subscription_repository = new SubscriptionRepository();

		$membership_id   = absint( $subscription_data['plan'] );
		$membership      = get_post( $membership_id );
		$membership_meta = json_decode( wp_unslash( get_post_meta( $membership_id, 'ur_membership', true ) ), true );

		if ( ! $membership || empty( $membership_meta ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid membership plan selected.', 'user-registration' ),
				)
			);
		}

		$is_recurring = isset( $membership_meta['type'] ) && 'subscription' === $membership_meta['type'];
		if ( $is_recurring ) {
			if ( empty( $subscription_data['expiry_date'] ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Expiry date is required for recurring subscription plans.', 'user-registration' ),
					)
				);
			}
		}

		$start_date     = ! empty( $subscription_data['start_date'] ) ? sanitize_text_field( $subscription_data['start_date'] ) : current_time( 'Y-m-d' );
		$status         = isset( $subscription_data['status'] ) ? sanitize_text_field( $subscription_data['status'] ) : 'pending';
		$billing_amount = isset( $subscription_data['billing_amount'] ) ? floatval( $subscription_data['billing_amount'] ) : ( $membership_meta['amount'] ?? 0 );

		if ( $is_recurring ) {
			$expiry_date   = ! empty( $subscription_data['expiry_date'] ) ? sanitize_text_field( $subscription_data['expiry_date'] ) : null;
			$billing_cycle = isset( $subscription_data['billing_cycle'] ) && ! empty( $subscription_data['billing_cycle'] ) ? sanitize_text_field( $subscription_data['billing_cycle'] ) : ( $membership_meta['subscription']['duration'] ?? 'month' );
		} else {
			$expiry_date   = null;
			$billing_cycle = '';
		}

		$data = array(
			'user_id'           => absint( $subscription_data['member'] ),
			'item_id'           => $membership_id,
			'start_date'        => $start_date,
			'expiry_date'       => $expiry_date,
			'next_billing_date' => $expiry_date,
			'billing_cycle'     => $billing_cycle,
			'billing_amount'    => $billing_amount,
			'status'            => $status,
			'cancel_sub'        => $membership_meta['cancel_subscription'] ?? 'immediately',
		);

		if ( ! empty( $subscription_data['subscription_id'] ) ) {
			$data['subscription_id'] = sanitize_text_field( $subscription_data['subscription_id'] );
		}

		$subscription = $subscription_repository->create( $data );

		if ( false === $subscription ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to create subscription.', 'user-registration' ),
				)
			);
		}

		$orders_repository = new OrdersRepository();
		$current_user      = wp_get_current_user();
		$transaction_id    = ! empty( $subscription_data['subscription_id'] ) ? sanitize_text_field( $subscription_data['subscription_id'] ) : 'sub_' . $subscription['ID'] . '_' . time();
		$payment_gateway   = isset( $subscription_data['payment_gateway'] ) && ! empty( $subscription_data['payment_gateway'] ) ? sanitize_text_field( $subscription_data['payment_gateway'] ) : 'manual';

		$order_type = $is_recurring ? 'subscription' : 'one-time';
		$notes_text = $is_recurring
			? sprintf(
				/* translators: %s: Current user display name */
				__( 'Recurring subscription created by %s', 'user-registration' ),
				$current_user->display_name
			)
			: sprintf(
				/* translators: %s: Current user display name */
				__( 'One-time membership created by %s', 'user-registration' ),
				$current_user->display_name
			);

		$order_data = array(
			'orders_data'      => array(
				'user_id'         => absint( $subscription_data['member'] ),
				'item_id'         => $membership_id,
				'subscription_id' => $subscription['ID'],
				'created_by'      => $current_user->ID,
				'transaction_id'  => $transaction_id,
				'payment_method'  => $payment_gateway,
				'total_amount'    => $billing_amount,
				'status'          => 'pending',
				'order_type'      => $order_type,
				'trial_status'    => 'off',
				'notes'           => $notes_text,
			),
			'orders_meta_data' => array(
				array(
					'meta_key'   => 'is_admin_created',
					'meta_value' => true,
				),
			),
		);

		$order = $orders_repository->create( $order_data );

		if ( $is_recurring ) {
			$success_message         = __( 'Recurring subscription and order created successfully.', 'user-registration' );
			$partial_success_message = __( 'Recurring subscription created successfully, but order creation failed.', 'user-registration' );
		} else {
			$success_message         = __( 'One-time membership and order created successfully.', 'user-registration' );
			$partial_success_message = __( 'One-time membership created successfully, but order creation failed.', 'user-registration' );
		}

		if ( false === $order ) {
			wp_send_json_success(
				array(
					'id'      => $subscription['ID'],
					'message' => $partial_success_message,
				)
			);
		} else {
			wp_send_json_success(
				array(
					'id'      => $subscription['ID'],
					'message' => $success_message,
				)
			);
		}
		wp_die();
	}

	/**
	 * Reactivate membership.
	 */
	public static function reactivate_membership() {
		$security = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( '' === $security || ! wp_verify_nonce( $security, 'ur_members_frontend' ) ) {
			wp_send_json_error( 'Nonce verification failed' );

			return;
		}

		if ( ! isset( $_POST['subscription_id'] ) ) {
			wp_send_json_error( __( 'Wrong request.', 'user-registration' ) );
		}
		$subscription_id = absint( $_POST['subscription_id'] );

		$subscription_repository = new SubscriptionRepository();
		$user_subscription       = $subscription_repository->retrieve( $subscription_id );

		$user_id = ! empty( $user_subscription['user_id'] ) ? $user_subscription['user_id'] : '';

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to edit this user.', 'user-registration' ),
				)
			);
		}

		$membership_id      = $user_subscription['item_id'] ?? 0;
		$membership_service = new MembershipService();
		$membership_details = $membership_service->get_membership_details( $membership_id );

		$order_repository                      = new OrdersRepository();
		$order_associated_with_subscription_id = $order_repository->get_order_by_subscription( $subscription_id );

		if ( ! empty( $order_associated_with_subscription_id['order_type'] ) && $order_associated_with_subscription_id['order_type'] === $membership_details['type'] ) {
			if ( isset( $membership_details['type'] ) && 'subscription' !== $membership_details['type'] ) {
				$subscription_repository = new SubscriptionRepository();

				// Only restore to 'active' if the order's payment has actually been approved/completed
				// (e.g. Bank Transfer orders stay 'pending' until an admin approves them); otherwise
				// restore to 'pending' instead of bypassing the manual payment verification flow.
				$reactivated_status = ( 'completed' === ( $order_associated_with_subscription_id['status'] ?? '' ) ) ? 'active' : 'pending';

				$subscription_repository->update(
					$subscription_id,
					array(
						'status' => $reactivated_status,
					)
				);

				delete_user_meta( $user_id, 'urm_pending_cancel_' . $subscription_id );

				// Prepare data to register subscription reactivation event.
				$payload = array(
					'subscription_id' => $subscription_id,
					'member_id'       => $user_id,
					'event_type'      => 'reactivated',
				);

				do_action( 'ur_membership_subscription_event_triggered', $payload );

				wp_send_json_success(
					array(
						'message' => __( 'Membership reactivated successfully.', 'user-registration' ),
					)
				);
			} elseif ( isset( $membership_details['type'] ) && 'subscription' === $membership_details['type'] ) {

				$reactivation_status = $subscription_repository->reactivate_subscription_by_id( $subscription_id );
				if ( $reactivation_status['status'] ) {

					// Ensure pending cancel meta is cleaned up regardless of gateway path.
					delete_user_meta( $user_id, 'urm_pending_cancel_' . $subscription_id );

					// Prepare data to register subscription reactivation event.
					$payload = array(
						'subscription_id' => $subscription_id,
						'member_id'       => $user_id,
						'event_type'      => 'reactivated',
					);

					do_action( 'ur_membership_subscription_event_triggered', $payload );

					wp_send_json_success(
						array(
							'message' => __( 'Membership reactivated successfully.', 'user-registration' ),
						)
					);
				} else {
					$message = ! empty( $reactivation_status['message'] ) ? $reactivation_status['message'] : __( 'Failed to reactivate membership.', 'user-registration' );
					wp_send_json_error(
						array(
							'message' => $message,
						)
					);
				}
			} else {
				wp_send_json_error(
					array(
						'message' => __( 'Membership details not found. Please contact site administrator.', 'user-registration' ),
					)
				);
			}
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'Cannot reactivate this membership. Please contact site administrator.', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * get_group_memberships
	 *
	 * @return void
	 */
	public static function get_group_memberships() {
		ur_membership_verify_nonce( 'ur_membership_group' );
		if ( ! isset( $_POST['group_id'] ) ) {
			wp_send_json_error( __( 'Wrong request.', 'user-registration' ) );
		}
		if ( ! isset( $_POST['list_type'] ) ) {
			wp_send_json_error( __( 'Field list type is required.', 'user-registration' ) );
		}
		$list_type = sanitize_text_field( $_POST['list_type'] );
		$group_id  = absint( $_POST['group_id'] );
		if ( 'group' == $list_type ) {
			$membership_group_service = new MembershipGroupService();
			$membership_plans         = $membership_group_service->get_group_memberships( $group_id );
		} else {
			$membership_service = new MembershipService();
			$membership_plans   = $membership_service->list_active_memberships();
		}

		if ( empty( $membership_plans ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No membership is available for the selected group.', 'user-registration' ),
				)
			);
		}
		wp_send_json_success(
			array(
				'plans' => $membership_plans,
			)
		);
	}

	/**
	 * get_selected_memberships
	 *
	 * Returns membership plans for a list of selected membership IDs.
	 *
	 * @return void
	 */
	public static function get_selected_memberships() {
		ur_membership_verify_nonce( 'ur_membership_group' );

		if ( ! isset( $_POST['membership_ids'] ) || ! is_array( $_POST['membership_ids'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No memberships selected.', 'user-registration' ),
				)
			);
		}

		$raw_ids      = array_map( 'intval', (array) $_POST['membership_ids'] );
		$selected_ids = array_filter( $raw_ids );

		if ( empty( $selected_ids ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No valid memberships selected.', 'user-registration' ),
				)
			);
		}

		$membership_service = new MembershipService();
		$all_memberships    = $membership_service->list_active_memberships();

		$membership_plans = array_values(
			array_filter(
				$all_memberships,
				function ( $m ) use ( $selected_ids ) {
					$id = isset( $m['ID'] ) ? (int) $m['ID'] : ( isset( $m['id'] ) ? (int) $m['id'] : 0 );
					return $id && in_array( $id, $selected_ids, true );
				}
			)
		);

		if ( empty( $membership_plans ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No memberships found for the current selection.', 'user-registration' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'plans' => $membership_plans,
			)
		);
	}

	/**
	 * create_membership_group
	 *
	 * @return void
	 */
	public static function create_membership_group() {
		ur_membership_verify_nonce( 'ur_membership_group' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, You do not have permission to create membership groups.', 'user-registration' ),
				)
			);
		}
		if ( ! isset( $_POST['membership_groups_data'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field membership_groups_data is required', 'user-registration' ),
				)
			);
		}
		$data = $_POST['membership_groups_data'];

		$membership_group_service = new MembershipGroupService();
		$create_groups            = $membership_group_service->create_membership_groups( $data );

		if ( ! $create_groups['status'] ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( $create_groups['message'], 'user-registration' ),
				)
			);
		}
		wp_send_json_success(
			array(
				'membership_group_id' => $create_groups['membership_group_id'],
				'message'             => __( 'Membership Groups saved successfully.', 'user-registration' ),
			)
		);
	}

	/**
	 * Verify if pages selected in membership global settings contain respective content(shortcodes/fields etc.)
	 * verify_pages
	 *
	 * @return void
	 */
	public static function verify_pages() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, You do not have permission to create membership groups.', 'user-registration' ),
				)
			);
		}
		ur_membership_verify_nonce( 'user_registration_validate_page_none' );
		if ( ! isset( $_POST['value'] ) ) {
			wp_send_json_error( __( 'Wrong request.', 'user-registration' ) );
		}
		if ( ! isset( $_POST['type'] ) ) {
			wp_send_json_error( __( 'Wrong request.', 'user-registration' ) );
		}
		if ( ! in_array(
			$_POST['type'],
			array(
				'user_registration_member_registration_page_id',
				'user_registration_thank_you_page_id',
			)
		) ) {
			wp_send_json_error( __( 'Invalid post type', 'user-registration' ) );
		}
		$post_id = absint( $_POST['value'] );
		$type    = sanitize_text_field( $_POST['type'] );

		$membership_service = new MembershipService();
		$response           = $membership_service->verify_page_content( $type, $post_id );
		wp_send_json( $response );
	}

	/**
	 * validate individual payment gateway on click
	 *
	 * @return void
	 */
	public static function validate_pg() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, You do not have permission to create membership groups.', 'user-registration' ),
				)
			);
		}
		ur_membership_verify_nonce( 'ur_membership' );
		if ( ! isset( $_POST['pg'] ) || ! isset( $_POST['membership_type'] ) ) {
			wp_send_json_error( __( 'Wrong request.', 'user-registration' ) );
		}
		$pg                 = sanitize_text_field( $_POST['pg'] );
		$subscription_type  = sanitize_text_field( $_POST['membership_type'] );
		$membership_service = new MembershipService();
		$result             = $membership_service->validate_payment_gateway( array( $pg, $subscription_type ) );

		wp_send_json( $result );
	}

	/**
	 * fetch_upgradable_membership
	 *
	 * @return void
	 */
	public static function fetch_upgradable_memberships() {

		$security = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( '' === $security || ! wp_verify_nonce( $security, 'ur_members_frontend' ) ) {
			wp_send_json_error( 'Nonce verification failed' );
		}
		if ( ! isset( $_POST['membership_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Membership does not exist', 'user-registration' ),
				)
			);
		}
		$membership_id            = absint( $_POST['membership_id'] );
		$member_id                = get_current_user_id();
		$members_order_repository = new MembersOrderRepository();
		$orders_repository        = new OrdersRepository();
		$last_order               = $members_order_repository->get_member_orders( $member_id );

		if ( ! empty( $last_order ) ) {
			$order_meta = $orders_repository->get_order_metas( $last_order['ID'] );
			if ( ! empty( $order_meta ) ) {
				$upcoming_subscription = json_decode( get_user_meta( $member_id, 'urm_next_subscription_data', true ), true );
				$membership            = get_post( $upcoming_subscription['membership'] );
				wp_send_json_error(
					array(
						'message' => apply_filters( 'urm_delayed_plan_exist_notice', __( sprintf( 'You already have a scheduled upgrade to the <b>%s</b> plan at the end of your current subscription cycle (<i><b>%s</b></i>) <br> If you\'d like to cancel this upcoming change, please proceed from my account page.', $membership->post_title, date( 'M d, Y', strtotime( $order_meta['meta_value'] ) ) ), 'user-registration' ), $membership->post_title, $order_meta['meta_value'] ),
					)
				);
			}
		}

		$membership_service     = new MembershipService();
		$upgradable_memberships = $membership_service->get_upgradable_membership( $membership_id );

		if ( empty( $upgradable_memberships ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No upgradable Memberships.', 'user-registration' ),
				),
				404
			);
		}

		wp_send_json_success(
			$upgradable_memberships
		);
	}

	/**
	 * Fetch intended membership details.
	 *
	 * @return void
	 */
	public static function fetch_intended_membership_details() {

		$security = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( '' === $security || ! wp_verify_nonce( $security, 'ur_members_frontend' ) ) {
			wp_send_json_error( 'Nonce verification failed' );
		}
		if ( ! isset( $_POST['membership_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Membership does not exist', 'user-registration' ),
				)
			);
		}
		$membership_id            = absint( $_POST['membership_id'] );
		$member_id                = get_current_user_id();
		$members_order_repository = new MembersOrderRepository();

		$membership_repository       = new MembershipRepository();
		$intended_membership_details = $membership_repository->get_single_membership_by_ID( $membership_id );
		$membership_service          = new MembershipService();
		$intended_membership_details = $membership_service->prepare_single_membership_data( $intended_membership_details );
		$intended_membership_details = apply_filters( 'build_membership_list_frontend', array( (array) $intended_membership_details ) )[0];

		if ( empty( $intended_membership_details ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Selected membership details not found.', 'user-registration' ),
				),
				404
			);
		}
		wp_send_json_success(
			$intended_membership_details
		);
	}

	/**
	 * Upgrade membership ajax request
	 *
	 * @return void
	 */
	public static function upgrade_membership() {

		ur_membership_verify_nonce( 'urm_upgrade_membership' );

		if ( empty( $_POST['current_subscription_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field current_subscription_id is required', 'user-registration' ),
				)
			);
		}
		if ( empty( $_POST['selected_membership_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field selected_membership_id is required', 'user-registration' ),
				)
			);
		}

		if ( isset( $_POST['form_data'] ) && ! empty( $_POST['form_data'] ) ) {
			$single_field = array();
			$form_data    = json_decode( wp_unslash( $_POST['form_data'] ) );
			$user_id      = get_current_user_id();
			$form_id      = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : ur_get_form_id_by_userid( $user_id );
			$profile      = user_registration_form_data( $user_id, $form_id );

			foreach ( $form_data as $data ) {
				$single_field[ 'user_registration_' . $data->field_name ] = isset( $data->value ) ? $data->value : '';
			}

			// Skip validationd for fields ignored in checkout.
			add_filter(
				'user_registration_update_profile_validation_skip_fields',
				function ( $skippable_fields, $form_data ) {
					$skippable_field_types = apply_filters(
						'user_registration_ignorable_checkout_fields',
						array(
							'user_pass',
							'user_confirm_password',
							'user_confirm_email',
							'profile_picture',
							'wysiwyg',
							'select2',
							'multi_select2',
							'range',
							'file',
						)
					);

					$form_skippable_fields = array_filter(
						$form_data,
						function ( $field ) use ( $skippable_field_types ) {
							if ( in_array( $field->field_key, $skippable_field_types, true ) ) {

								if ( 'range' === $field->field_key && ( isset( $field->advance_setting->enable_payment_slider ) && ! ur_string_to_bool( $field->advance_setting->enable_payment_slider ) ) ) {
									return false;
								}

								return true;
							}

							return false;
						}
					);

					$form_skippable_fields = wp_list_pluck( wp_list_pluck( $form_skippable_fields, 'general_setting' ), 'field_name' );

					return array_unique(
						array_merge( $skippable_fields, $form_skippable_fields )
					);
				},
				10,
				2
			);

			[ $profile, $single_field ] = urm_process_profile_fields( $profile, $single_field, $form_data, $form_id, $user_id, false );
			$user                       = get_userdata( $user_id );
			urm_update_user_profile_data( $user, $profile, $single_field, $form_id );

			$logger = ur_get_logger();
			$logger->info(
				__( 'User details added while upgrading.', 'user-registration' ),
				array( 'source' => 'form-save' )
			);
		}

		$ur_authorize_data = isset( $_POST['ur_authorize_data'] ) ? $_POST['ur_authorize_data'] : array();
		$data              = array(
			'current_subscription_id' => absint( $_POST['current_subscription_id'] ),
			'selected_membership_id'  => absint( $_POST['selected_membership_id'] ),
			'current_membership_id'   => absint( $_POST['current_membership_id'] ),
			'selected_pg'             => sanitize_text_field( $_POST['selected_pg'] ),
			'ur_authorize_net'        => $ur_authorize_data,
		);

		// Forward the local-currency zone the member checked out in.
		if ( ! empty( $_POST['switched_currency'] ) && ! empty( $_POST['urm_zone_id'] ) ) {
			$data['switched_currency'] = sanitize_text_field( $_POST['switched_currency'] );
			$data['urm_zone_id']       = sanitize_text_field( $_POST['urm_zone_id'] );
		}

		if ( ! empty( $_POST['coupon'] ) ) {
			$data['coupon'] = sanitize_text_field( $_POST['coupon'] );
		}

		if ( ! empty( $_POST['tax_rate'] ) ) {
			$data['tax_rate']               = max( 0, floatval( $_POST['tax_rate'] ) );
			$data['tax_calculation_method'] = ! empty( $_POST['tax_calculation_method'] ) ? sanitize_text_field( $_POST['tax_calculation_method'] ) : '1';
		}

		$subscription_service = new SubscriptionService();
		$status               = $subscription_service->can_upgrade( $data );

		if ( ! $status['status'] ) {
			wp_send_json_error(
				array(
					'message' => $status['message'],
				)
			);
		}

		$upgrade_membership_response = $subscription_service->upgrade_membership( $data );
		$response                    = $upgrade_membership_response['response'];

		if ( $response['status'] ) {
			$selected_pg = $data['selected_pg'];
			$member_id   = $upgrade_membership_response['extra']['member_id'];
			$member      = get_userdata( $member_id );

			// Get membership type for logging
			$membership_repository = new MembershipRepository();
			$new_membership        = $membership_repository->get_single_membership_by_ID( $data['selected_membership_id'] );
			$membership_metas      = json_decode( $new_membership['meta_value'], true );
			$membership_type       = $membership_metas['type'] ?? 'unknown';

			// Log session start with divider
			if ( class_exists( 'WPEverest\URMembership\Admin\Services\PaymentGatewayLogging' ) ) {
				// Add session divider
				\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
					$selected_pg,
					'========== UPGRADE PAYMENT SESSION ==========',
					'notice',
					array(
						'timestamp'       => current_time( 'mysql' ),
						'membership_type' => $membership_type,
						'username'        => $member ? $member->user_login : 'unknown',
					)
				);

				// Log upgrade initiation
				\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
					$selected_pg,
					'Membership upgrade initiated',
					'info',
					array(
						'event_type'        => 'upgrade_started',
						'member_id'         => $member_id,
						'username'          => $member ? $member->user_login : 'unknown',
						'payment_method'    => $selected_pg,
						'old_membership_id' => $data['current_membership_id'],
						'new_membership_id' => $data['selected_membership_id'],
						'membership_type'   => $membership_type,
					)
				);
			}

			if ( $selected_pg !== 'free' ) {
				$membership_process = urm_get_membership_process( $member_id );
				if ( $membership_process && ! isset( $membership_process['upgrade'][ $data['current_membership_id'] ] ) ) {
					$membership_process['upgrade'][ $data['current_membership_id'] ] = array(
						'from'            => $data['current_membership_id'],
						'to'              => $data['selected_membership_id'],
						'subscription_id' => $data['current_subscription_id'],
					);

					update_user_meta( $member_id, 'urm_membership_process', $membership_process );
				}
			} else {
				// Free upgrade completes immediately

				PaymentGatewayLogging::log_transaction_success(
					'free',
					sprintf( ' [Member ID #%s] Free membership upgrade completed.', $member_id ) . "\n" . wp_json_encode(
						array(
							'event_type'        => 'upgrade_completed',
							'member_id'         => $member_id,
							'new_membership_id' => $data['selected_membership_id'],
							'membership_type'   => $membership_type,
						),
						JSON_PRETTY_PRINT
					) . "\n "
				);

					PaymentGatewayLogging::log_transaction_success(
						'free',
						sprintf( ' [Member ID #%s] Free membership upgrade completed.', $member_id ) . "\n" . wp_json_encode(
							array(
								'event_type'        => 'upgrade_completed',
								'member_id'         => $member_id,
								'new_membership_id' => $data['selected_membership_id'],
								'membership_type'   => $membership_type,
							),
							JSON_PRETTY_PRINT
						) . "\n "
					);
			}
			$message = __( 'Membership upgraded successfully.', 'user-registration' );

			// Prepare data to register subscription upgrade event.
			$members_subscription_repository = new MembersSubscriptionRepository();
			$membership_repository           = new MembershipRepository();

			$current_membership_details = $membership_repository->get_single_membership_by_ID( $data['current_membership_id'] );
			$new_membership_details     = $membership_repository->get_single_membership_by_ID( $data['selected_membership_id'] );

			$from                 = $current_membership_details['post_title'];
			$to                   = $new_membership_details['post_title'];
			$subscription_details = $members_subscription_repository->get_subscription_by_subscription_id( $data['current_subscription_id'] );

			$payload = array(
				'subscription_id' => $data['current_subscription_id'],
				'member_id'       => $member_id,
				'event_type'      => 'upgraded',
				'meta'            => array(
					'transaction_id'    => $upgrade_membership_response['extra']['transaction_id'],
					'order_id'          => $upgrade_membership_response['extra']['order_id'],
					'payment_method'    => $selected_pg,
					'next_billing_date' => $subscription_details['next_billing_date'],
					'from'              => $from,
					'to'                => $to,
				),
			);

			do_action( 'ur_membership_subscription_event_triggered', $payload );

			wp_send_json_success(
				array(
					'is_upgrading'             => true,
					'pg_data'                  => $response,
					'member_id'                => $upgrade_membership_response['extra']['member_id'],
					'username'                 => $upgrade_membership_response['extra']['username'],
					'transaction_id'           => $upgrade_membership_response['extra']['transaction_id'],
					'updated_membership_title' => $upgrade_membership_response['extra']['updated_membership_title'],
					'message'                  => $message,
					'selected_membership_id'   => $data['selected_membership_id'],
					'current_membership_id'    => $data['current_membership_id'],
					'order_id'                 => $upgrade_membership_response['extra']['order_id'],
				)
			);
		}

		$error_message = isset( $response['message'] ) ? $response['message'] : __( 'Something went wrong while upgrading membership.', 'user-registration' );
		wp_send_json_error(
			array(
				'message' => $error_message,
			)
		);
	}

	/**
	 * Add multiple membership ajax request
	 *
	 * @return void
	 */
	public static function add_multiple_membership() {

		ur_membership_verify_nonce( 'urm_upgrade_membership' );

		if ( empty( $_POST['selected_membership_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Field selected_membership_id is required', 'user-registration' ),
				)
			);
		}

		if ( isset( $_POST['form_data'] ) && ! empty( $_POST['form_data'] ) ) {
			$single_field = array();
			$form_data    = json_decode( wp_unslash( $_POST['form_data'] ) );
			$user_id      = get_current_user_id();
			$form_id      = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : ur_get_form_id_by_userid( $user_id );

			if ( isset( $_POST['type'] ) && 'register' === sanitize_text_field( $_POST['type'] ) ) {
				update_user_meta( $user_id, 'ur_form_id', $form_id );
			}

			$profile = user_registration_form_data( $user_id, $form_id );

			foreach ( $form_data as $data ) {
				$single_field[ 'user_registration_' . $data->field_name ] = isset( $data->value ) ? $data->value : '';
			}

			// Skip validationd for fields ignored in checkout.
			add_filter(
				'user_registration_update_profile_validation_skip_fields',
				function ( $skippable_fields, $form_data ) {
					$skippable_field_types = apply_filters(
						'user_registration_ignorable_checkout_fields',
						array(
							'user_pass',
							'user_confirm_password',
							'user_confirm_email',
							'profile_picture',
							'wysiwyg',
							'select2',
							'multi_select2',
							'range',
							'file',
						)
					);

					$form_skippable_fields = array_filter(
						$form_data,
						function ( $field ) use ( $skippable_field_types ) {
							if ( in_array( $field->field_key, $skippable_field_types, true ) ) {

								if ( 'range' === $field->field_key && ( isset( $field->advance_setting->enable_payment_slider ) && ! ur_string_to_bool( $field->advance_setting->enable_payment_slider ) ) ) {
									return false;
								}

								return true;
							}

							return false;
						}
					);

					$form_skippable_fields = wp_list_pluck( wp_list_pluck( $form_skippable_fields, 'general_setting' ), 'field_name' );

					return array_unique(
						array_merge( $skippable_fields, $form_skippable_fields )
					);
				},
				10,
				2
			);
			[ $profile, $single_field ] = urm_process_profile_fields( $profile, $single_field, $form_data, $form_id, $user_id, false );
			$user                       = get_userdata( $user_id );
			urm_update_user_profile_data( $user, $profile, $single_field, $form_id );

			$logger = ur_get_logger();
			$logger->info(
				__( 'User details added while purchasing membership.', 'user-registration' ),
				array( 'source' => 'form-save' )
			);
		}

		$current_user_id     = get_current_user_id();
		$user_membership_ids = array();
		$members_repository  = new MembersRepository();

		if ( $current_user_id ) {
			$user_memberships    = $members_repository->get_member_membership_by_id( $current_user_id );
			$user_membership_ids = array_filter(
				array_map(
					function ( $user_memberships ) {
						return $user_memberships['post_id'];
					},
					$user_memberships
				)
			);
		}

		$ur_authorize_data = isset( $_POST['ur_authorize_data'] ) ? $_POST['ur_authorize_data'] : array();
		$data              = array(
			'selected_membership_id' => absint( $_POST['selected_membership_id'] ),
			'current_membership_ids' => $user_membership_ids,
			'payment_method'         => sanitize_text_field( $_POST['selected_pg'] ),
			'ur_authorize_net'       => $ur_authorize_data,
			'is_purchasing_multiple' => true,
		);

		// Forward the local-currency zone the member checked out in.
		if ( ! empty( $_POST['switched_currency'] ) && ! empty( $_POST['urm_zone_id'] ) ) {
			$data['switched_currency'] = sanitize_text_field( $_POST['switched_currency'] );
			$data['urm_zone_id']       = sanitize_text_field( $_POST['urm_zone_id'] );
		}

		if ( ! empty( $_POST['coupon'] ) ) {
			$data['coupon'] = sanitize_text_field( $_POST['coupon'] );
		}

		if ( ! empty( $_POST['tax_rate'] ) ) {
			$data['tax_rate']               = max( 0, floatval( $_POST['tax_rate'] ) );
			$data['tax_calculation_method'] = ! empty( $_POST['tax_calculation_method'] ) ? sanitize_text_field( $_POST['tax_calculation_method'] ) : '1';
		}

		if ( ! empty( $user_membership_ids ) ) {
			$subscription_service = new SubscriptionService();
			$status               = $subscription_service->can_purchase_multiple( $data );

			if ( ! $status['status'] ) {
				wp_send_json_error(
					array(
						'message' => $status['message'],
					)
				);
			}
		}

		// Get membership type for logging
		$membership_repository = new MembershipRepository();
		$membership_data       = $membership_repository->get_single_membership_by_ID( $data['selected_membership_id'] );
		$membership_meta       = json_decode( wp_unslash( $membership_data['meta_value'] ), true );
		$membership_type       = $membership_meta['type'] ?? 'unknown'; // free, paid, or subscription

		$payment_gateway = $data['payment_method'] ?? 'unknown';
		$member_id       = get_current_user_id();
		$member          = get_userdata( $member_id );

		if ( class_exists( 'WPEverest\URMembership\Admin\Services\PaymentGatewayLogging' ) ) {
			// Add session divider
			\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
				$payment_gateway,
				sprintf( ' [Member ID #%s] ========== ***NEW PAYMENT SESSION*** ==========', $member_id ) . "\n" . wp_json_encode(
					array(
						'timestamp'       => current_time( 'mysql' ),
						'membership_type' => $membership_type,
						'username'        => $member->user_login,
					),
					JSON_PRETTY_PRINT
				),
				'notice'
			);

			// Log form submission
			\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
				$payment_gateway,
				sprintf( ' [Member ID #%s] Membership registration form submitted.', $member_id ) . "\n" . wp_json_encode(
					array(
						'event_type'      => 'form_submission',
						'member_id'       => $member_id,
						'username'        => $member->user_login,
						'email'           => $member->user_email,
						'membership_id'   => $data['membership'] ?? 'N/A',
						'payment_method'  => $payment_gateway,
						'membership_type' => $membership_type,
					),
					JSON_PRETTY_PRINT
				),
				'info'
			);
		}

		$membership_service = new MembershipService();
		$data['membership'] = $data['selected_membership_id'];
		$data['start_date'] = date( 'Y-m-d' );
		$data['username']   = $member->user_login;

		$response = $membership_service->create_membership_order_and_subscription( $data );

		if ( $response['status'] && class_exists( 'WPEverest\URMembership\Admin\Services\PaymentGatewayLogging' ) ) {
			// For free and bank, status is 'active' immediately. For others, it's 'pending'
			$initial_status = ( 'free' === $payment_gateway ) ? 'active' : 'pending';

			\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
				$payment_gateway,
				sprintf( ' [Member ID #%s] Order and subscription created - Status: %s', $member_id, $initial_status ) . "\n" . wp_json_encode(
					array(
						'event_type'      => 'status_change',
						'member_id'       => $member_id,
						'subscription_id' => $response['subscription_id'] ?? 'N/A',
						'transaction_id'  => $response['transaction_id'] ?? 'N/A',
						'status'          => $initial_status,
						'membership_id'   => $data['membership'] ?? 'N/A',
						'membership_type' => $membership_type,
					),
					JSON_PRETTY_PRINT
				),
				'info'
			);

			// Log activation for free and bank immediately
			if ( 'free' === $payment_gateway ) {

				\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
					$payment_gateway,
					sprintf( ' [Member ID #%s] Subscription activated successfully.', $member_id ) . "\n" . wp_json_encode(
						array(
							'member_id'       => $member_id,
							'subscription_id' => $response['subscription_id'] ?? 'N/A',
							'status'          => 'active',
							'payment_method'  => $payment_gateway,
							'membership_type' => $membership_type,
							'auto_activated'  => true,
						),
						JSON_PRETTY_PRINT
					) . "\n ",
					'info'
				);
			}
		}

		$transaction_id          = isset( $response['transaction_id'] ) ? $response['transaction_id'] : 0;
		$data['member_id']       = $member_id;
		$data['subscription_id'] = isset( $response['subscription_id'] ) ? $response['subscription_id'] : 0;
		$data['email']           = $response['member_email'];
		$data['order_id']        = $response['order_id'];
		$pg_data                 = array();
		$response['type']        = isset( $response['type'] ) ? $response['type'] : $membership_type;

		if ( 'free' !== $data['payment_method'] && $response['status'] ) {
			$payment_service = new PaymentService( $data['payment_method'], $data['membership'], $data['email'] );
			$pg_data         = $payment_service->build_response( $data );
		}

		if ( $response['status'] ) {
			$selected_pg            = $data['payment_method'];
			$member_id              = $member_id;
			$member                 = get_userdata( $member_id );
			$member_username        = $member ? $member->user_login : 'unknown';
			$added_membership_title = get_post( $data['selected_membership_id'] )->post_title;

			// Get membership type for logging
			$membership_repository = new MembershipRepository();
			$new_membership        = $membership_repository->get_single_membership_by_ID( $data['selected_membership_id'] );
			$membership_metas      = json_decode( $new_membership['meta_value'], true );
			$membership_type       = $membership_metas['type'] ?? 'unknown';

			if ( $selected_pg !== 'free' ) {
				$membership_process = urm_get_membership_process( $member_id );
				if ( $membership_process && ! in_array( $data['selected_membership_id'], $membership_process['multiple'] ) ) {
					$membership_process['multiple'][] = $data['selected_membership_id'];
					update_user_meta( $member_id, 'urm_membership_process', $membership_process );
				} else {
					wp_send_json_error(
						array(
							'message' => __( 'Membership purchase process already initiated.', 'user-registration' ),
						)
					);
				}
			} else {
				// Free membership updates immediately

				PaymentGatewayLogging::log_transaction_success(
					'free',
					sprintf( ' [Member ID #%s] Free membership addition completed.', $member_id ) . "\n" . wp_json_encode(
						array(
							'event_type'        => 'completed',
							'member_id'         => $member_id,
							'new_membership_id' => $data['selected_membership_id'],
							'membership_type'   => $membership_type,
						),
						JSON_PRETTY_PRINT
					) . "\n "
				);
			}

			$message = __( 'Membership purchased successfully.', 'user-registration' );
			wp_send_json_success(
				array(
					'is_purchasing_multiple'   => true,
					'pg_data'                  => $pg_data,
					'member_id'                => $member_id,
					'username'                 => $member_username,
					'transaction_id'           => $transaction_id,
					'updated_membership_title' => $added_membership_title,
					'message'                  => $message,
					'selected_membership_id'   => $data['selected_membership_id'],
					// Include order_id so the Stripe confirmation step can identify this order
					// (matches the upgrade response); without it the payment stays pending.
					'order_id'                 => $data['order_id'],
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => __( 'Something went wrong while purchasing membership.', 'user-registration' ),
			)
		);
	}

	/**
	 * cancel_upcoming_subscription
	 *
	 * @return void
	 */
	public static function cancel_upcoming_subscription() {
		ur_membership_verify_nonce( 'urm_upgrade_membership' );
		$member_id                = get_current_user_id();
		$user                     = get_userdata( $member_id );
		$members_order_repository = new MembersOrderRepository();
		$order_repository         = new OrdersRepository();
		$last_order               = $members_order_repository->get_member_orders( $member_id );
		$order_detail             = $order_repository->get_order_metas( $last_order['ID'] );
		if ( ! empty( $order_detail ) ) {
			$order_repository->delete( $last_order['ID'] );
			delete_user_meta( $member_id, 'urm_next_subscription_data' );
			delete_user_meta( $member_id, 'urm_previous_subscription_data' );
			delete_user_meta( $member_id, 'urm_next_subscription_data' );

			wp_send_json_success(
				array(
					'message' => __( 'Scheduled membership has been cancelled successfully.', 'user-registration' ),
				)
			);
		}
		wp_send_json_error(
			array(
				'message' => __( 'Something went wrong while cancelling membership.', 'user-registration' ),
			)
		);
	}

	/**
	 * renew_subscription
	 *
	 * @return void
	 */
	public static function renew_membership() {
		ur_membership_verify_nonce( 'urm_renew_membership' );
		$member_id            = get_current_user_id();
		$user                 = get_userdata( $member_id );
		$subscription_service = new SubscriptionService();
		$selected_pg          = sanitize_text_field( $_POST['selected_pg'] );
		$membership_id        = absint( $_POST['membership_id'] );
		$team_id              = ! empty( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0;

		// Get membership type for logging
		$members_subscription_repo = new MembersSubscriptionRepository();
		$membership_repository     = new MembershipRepository();
		$member_subscription       = $members_subscription_repo->get_subscription_data_by_member_and_membership_id( $member_id, $membership_id );
		$membership_type           = 'unknown';
		if ( ! empty( $member_subscription ) && ! empty( $member_subscription['item_id'] ) ) {
			$membership = $membership_repository->get_single_membership_by_ID( $member_subscription['item_id'] );
			if ( ! empty( $membership ) && ! empty( $membership['meta_value'] ) ) {
				$membership_metas = json_decode( $membership['meta_value'], true );
				$membership_type  = $membership_metas['type'] ?? 'unknown';
			}
		}

		if ( isset( $_POST['form_data'] ) && ! empty( $_POST['form_data'] ) ) {
			$single_field = array();
			$form_data    = json_decode( wp_unslash( $_POST['form_data'] ) );
			$user_id      = get_current_user_id();
			$form_id      = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : ur_get_form_id_by_userid( $user_id );
			$profile      = user_registration_form_data( $user_id, $form_id );

			foreach ( $form_data as $data ) {
				$single_field[ 'user_registration_' . $data->field_name ] = isset( $data->value ) ? $data->value : '';
			}

			// Skip validationd for fields ignored in checkout.
			add_filter(
				'user_registration_update_profile_validation_skip_fields',
				function ( $skippable_fields, $form_data ) {
					$skippable_field_types = apply_filters(
						'user_registration_ignorable_checkout_fields',
						array(
							'user_pass',
							'user_confirm_password',
							'user_confirm_email',
							'profile_picture',
							'wysiwyg',
							'select2',
							'multi_select2',
							'range',
							'file',
						)
					);

					$form_skippable_fields = array_filter(
						$form_data,
						function ( $field ) use ( $skippable_field_types ) {
							if ( in_array( $field->field_key, $skippable_field_types, true ) ) {

								if ( 'range' === $field->field_key && ( isset( $field->advance_setting->enable_payment_slider ) && ! ur_string_to_bool( $field->advance_setting->enable_payment_slider ) ) ) {
									return false;
								}

								return true;
							}

							return false;
						}
					);

					$form_skippable_fields = wp_list_pluck( wp_list_pluck( $form_skippable_fields, 'general_setting' ), 'field_name' );

					return array_unique(
						array_merge( $skippable_fields, $form_skippable_fields )
					);
				},
				10,
				2
			);

			[ $profile, $single_field ] = urm_process_profile_fields( $profile, $single_field, $form_data, $form_id, $user_id, false );
			$user                       = get_userdata( $user_id );
			urm_update_user_profile_data( $user, $profile, $single_field, $form_id );

			$logger = ur_get_logger();
			$logger->info(
				__( 'User details added while renewing.', 'user-registration' ),
				array( 'source' => 'form-save' )
			);
		}

		// Log session start with divider
		if ( class_exists( 'WPEverest\URMembership\Admin\Services\PaymentGatewayLogging' ) ) {
			// Add session divider
			\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
				$selected_pg,
				'========== RENEWAL PAYMENT SESSION ==========',
				'notice',
				array(
					'timestamp'       => current_time( 'mysql' ),
					'membership_type' => $membership_type,
					'username'        => $user->user_login,
				)
			);

			// Log renewal initiation
			\WPEverest\URMembership\Admin\Services\PaymentGatewayLogging::log_general(
				$selected_pg,
				'Membership renewal initiated',
				'info',
				array(
					'event_type'      => 'renewal_started',
					'member_id'       => $member_id,
					'username'        => $user->user_login,
					'email'           => $user->user_email,
					'membership_id'   => $member_subscription['item_id'] ?? 'N/A',
					'payment_method'  => $selected_pg,
					'membership_type' => $membership_type,
				)
			);
		}

		$renew_membership = $subscription_service->renew_membership( $user, $selected_pg, $membership_id, $team_id );

		$response = $renew_membership['response'];
		if ( $response['status'] ) {
			$message = __( 'Membership renewed successfully.', 'user-registration' );

			// Prepare data to register subscription renew event.
			$members_subscription_repository = new MembersSubscriptionRepository();
			$subscription_details            = $members_subscription_repository->get_subscription_by_subscription_id( $member_subscription['ID'] );

			$payload = array(
				'subscription_id' => $member_subscription['ID'],
				'member_id'       => $member_id,
				'event_type'      => 'renewed',
				'meta'            => array(
					'order_id'          => $renew_membership['extra']['order_id'],
					'transaction_id'    => $renew_membership['extra']['transaction_id'],
					'payment_method'    => $selected_pg,
					'next_billing_date' => $subscription_details['next_billing_date'],
				),
			);

			do_action( 'ur_membership_subscription_event_triggered', $payload );

			wp_send_json_success(
				array(
					'pg_data'               => $response,
					'member_id'             => $renew_membership['extra']['member_id'],
					'username'              => $renew_membership['extra']['username'],
					'transaction_id'        => $renew_membership['extra']['transaction_id'],
					'message'               => $message,
					'is_renewing'           => true,
					'current_membership_id' => $membership_id,
				)
			);
		}
		wp_send_json_error(
			array(
				'message' => __( 'Something went wrong while cancelling membership.', 'user-registration' ),
			)
		);
	}

	public static function update_subscription() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, you do not have permission to update subscription', 'user-registration' ),
				)
			);
		}

		ur_membership_verify_nonce( 'ur_membership_subscription' );

		$subscription_data = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see L:903
		$subscription_id   = $subscription_data['id'] ?? 0;

		if ( empty( $subscription_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Subscription ID is required.', 'user-registration' ),
				)
			);
		}

		$subscription_repository = new SubscriptionRepository();
		$existing_subscription   = $subscription_repository->retrieve( $subscription_id );

		if ( ! $existing_subscription ) {
			wp_send_json_error(
				array(
					'message' => __( 'Subscription not found.', 'user-registration' ),
				)
			);
		}

		$trial_has_ended = false;
		if ( ! empty( $existing_subscription['trial_end_date'] ) ) {
			$trial_end_timestamp = strtotime( $existing_subscription['trial_end_date'] );
			$current_timestamp   = time();
			$trial_has_ended     = $current_timestamp >= $trial_end_timestamp;
		}

		$original_status = $existing_subscription['status'];

		$update_data = array();

		$expiry_date      = isset( $subscription_data['expiry_date'] ) && ! empty( $subscription_data['expiry_date'] ) ? sanitize_text_field( $subscription_data['expiry_date'] ) : $existing_subscription['expiry_date'];
		$trial_start_date = isset( $subscription_data['trial_start_date'] ) && ! empty( $subscription_data['trial_start_date'] ) ? sanitize_text_field( $subscription_data['trial_start_date'] ) : $existing_subscription['trial_start_date'];
		$trial_end_date   = isset( $subscription_data['trial_end_date'] ) && ! empty( $subscription_data['trial_end_date'] ) ? sanitize_text_field( $subscription_data['trial_end_date'] ) : $existing_subscription['trial_end_date'];

		if ( ! empty( $trial_start_date ) && ! empty( $trial_end_date ) ) {
			$trial_start_timestamp = strtotime( $trial_start_date );
			$trial_end_timestamp   = strtotime( $trial_end_date );

			if ( $trial_start_timestamp > $trial_end_timestamp ) {
				wp_send_json_error(
					array(
						'message' => __( 'Trial start date cannot be after trial end date.', 'user-registration' ),
					)
				);
			}
		}

		if ( ! empty( $trial_end_date ) && ! empty( $expiry_date ) ) {
			$trial_end_timestamp = strtotime( $trial_end_date );
			$expiry_timestamp    = strtotime( $expiry_date );

			if ( $trial_end_timestamp > $expiry_timestamp ) {
				wp_send_json_error(
					array(
						'message' => __( 'Trial end date cannot exceed expiry date.', 'user-registration' ),
					)
				);
			}
		}

		if ( ! empty( $trial_start_date ) && ! empty( $expiry_date ) ) {
			$trial_start_timestamp = strtotime( $trial_start_date );
			$expiry_timestamp      = strtotime( $expiry_date );

			if ( $expiry_timestamp < $trial_start_timestamp ) {
				wp_send_json_error(
					array(
						'message' => __( 'Expiry date cannot be less than trial start date.', 'user-registration' ),
					)
				);
			}
		}

		if ( isset( $subscription_data['expiry_date'] ) ) {
			$update_data['expiry_date'] = ! empty( $subscription_data['expiry_date'] ) ? sanitize_text_field( $subscription_data['expiry_date'] ) : null;
		}

		if ( isset( $subscription_data['status'] ) ) {
			$update_data['status'] = sanitize_text_field( $subscription_data['status'] );
		}

		if ( isset( $subscription_data['subscription_id'] ) ) {
			$update_data['subscription_id'] = sanitize_text_field( $subscription_data['subscription_id'] );
		}

		if ( isset( $subscription_data['trial_start_date'] ) && ! $trial_has_ended ) {
			$update_data['trial_start_date'] = ! empty( $subscription_data['trial_start_date'] ) ? sanitize_text_field( $subscription_data['trial_start_date'] ) : null;
		}

		if ( isset( $subscription_data['trial_end_date'] ) ) {
			$update_data['trial_end_date'] = ! empty( $subscription_data['trial_end_date'] ) ? sanitize_text_field( $subscription_data['trial_end_date'] ) : null;
		}

		$expiry_date_changed = false;
		if ( isset( $update_data['expiry_date'] ) ) {
			$new_expiry_date = $update_data['expiry_date'];
			$old_expiry_date = isset( $existing_subscription['expiry_date'] ) ? $existing_subscription['expiry_date'] : '';

			$new_expiry_normalized = ! empty( $new_expiry_date ) ? date( 'Y-m-d', strtotime( $new_expiry_date ) ) : '';
			$old_expiry_normalized = ! empty( $old_expiry_date ) ? date( 'Y-m-d', strtotime( $old_expiry_date ) ) : '';

			if ( $new_expiry_normalized !== $old_expiry_normalized ) {
				$expiry_date_changed = true;
			}
		}

		$result = $subscription_repository->update( $subscription_id, $update_data );

		if ( false === $result ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to update subscription.', 'user-registration' ),
				)
			);
		} else {
			if ( $expiry_date_changed && ! empty( $existing_subscription['user_id'] ) && ! empty( $existing_subscription['item_id'] ) ) {
				$user_id         = absint( $existing_subscription['user_id'] );
				$membership_id   = absint( $existing_subscription['item_id'] );
				$new_expiry_date = isset( $update_data['expiry_date'] ) ? $update_data['expiry_date'] : '';

				if ( ! empty( $new_expiry_date ) ) {
					update_user_meta( $user_id, 'ur_membership_expiry_date', $new_expiry_date );
				}

				do_action( 'ur_membership_expiry_date_manually_updated', $user_id, $membership_id, $new_expiry_date );
			}

			wp_send_json_success(
				array(
					'id'      => $subscription_id,
					'message' => __( 'Subscription updated successfully.', 'user-registration' ),
				)
			);
		}
		wp_die();
	}

	/**
	 * Get addons list.
	 */
	public static function addons_get_lists() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change membership details.', 'user-registration' ) ) );
		}

		if ( 'user_registration_membership_addons_get_lists' != sanitize_text_field( wp_unslash( $_POST['action'] ) ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change membership details.', 'user-registration' ) ) );
		}

		$addon_name = ! empty( $_POST['addon'] ) ? sanitize_text_field( wp_unslash( $_POST['addon'] ) ) : '';
		$api_key    = ! empty( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		$function_name = 'ur_' . $addon_name . '_get_lists';
		$lists         = $function_name( $api_key );

		if ( is_wp_error( $lists ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'API list not found', 'user-registration' ),
				)
			);
		}

		$render_function = 'ur_' . $addon_name . '_render_list';
		$html            = $render_function( $api_key );
		$data            = array(
			'html' => $html,
		);
		if ( 'mailchimp' === $addon_name && function_exists( 'urmc_render_list_tags' ) ) {
			$data['tag_html'] = urmc_render_list_tags( $api_key );
		}

		wp_send_json_success(
			$data
		);
	}

	/**
	 * Fetch upgrade path for selected memberships in the group.
	 */
	public static function fetch_upgrade_path() {
		ur_membership_verify_nonce( 'ur_membership_group' ); // nonce verification.

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, You do not have permission to set upgrade path.', 'user-registration' ),
				)
			);
		}

		if ( empty( $_POST['membership_ids'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please select memberships.', 'user-registration' ),
				)
			);
		}
		$membership_upgrade_service = new UpgradeMembershipService();
		$membership_ids             = isset( $_POST['membership_ids'] ) ? $_POST['membership_ids'] : '';

		if ( empty( $membership_ids ) ) {
			return wp_send_json_error(
				array(
					'message' => __( 'Please select memberships.', 'user-registration' ),
				)
			);

		}
		$membership_ids        = array_filter( array_map( 'absint', $membership_ids ) );
		$membership_repository = new MembershipRepository();

		$memberships = $membership_repository->get_multiple_membership_by_ID( $membership_ids, false );

		$upgrade_paths = $membership_upgrade_service->fetch_upgrade_paths( $memberships, 'manual' );

		if ( ! empty( $upgrade_paths ) ) {
			$upgrade_paths_order = $membership_upgrade_service->build_upgrade_order( $upgrade_paths );

			wp_send_json_success(
				array(
					'upgrade_paths'       => $upgrade_paths,
					'upgrade_order'       => array_keys( $upgrade_paths ),
					'upgrade_paths_order' => $upgrade_paths_order,
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => __( 'Something went wrong. Please refresh the page and try again.', 'user-registration' ),
			)
		);
	}

	/**
	 * Valid local currency payment.
	 *
	 * @since 6.1.0
	 */
	public static function validate_payment_currency() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Sorry, you do not have permission to do this.', 'user-registration' ),
				)
			);
		}

		ur_membership_verify_nonce( 'validate_payment_currency_nonce' );

		$zone_id = ! empty( sanitize_text_field( wp_unslash( $_POST['zone_id'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['zone_id'] ) ) : '';

		if ( empty( $zone_id ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'Currency is invalid.', 'user-registration' ),
				)
			);
		}

		if ( ! ur_check_module_activation( 'local-currency' ) || ! class_exists( CoreFunctions::class ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'Currency is valid.', 'user-registration' ),
				)
			);
		}

		$zone_data = CoreFunctions::ur_get_pricing_zone_by_id( $zone_id );
		$currency  = ! empty( $zone_data['meta']['ur_local_currency'][0] ) ? $zone_data['meta']['ur_local_currency'][0] : '';

		if ( empty( $currency ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'Currency is invalid.', 'user-registration' ),
				)
			);
		}

		$currency_not_supported_payment_gateways = array();

		// if the currency is not supported by PayPal.
		if ( ! in_array( $currency, paypal_supported_currencies_list() ) ) {
			$currency_not_supported_payment_gateways[] = 'PayPal';
		}

		$currency_not_supported_payment_gateways = apply_filters( 'urm_currency_not_supported_payment_gateways', $currency_not_supported_payment_gateways, $currency );
		if ( ! empty( $currency_not_supported_payment_gateways ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						__( '%1$s is not currently supported by %2$s.', 'user-registration' ),
						$currency,
						implode( ', ', $currency_not_supported_payment_gateways ),
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Currency is valid.', 'user-registration' ),
			)
		);
	}
}
