<?php

namespace WPEverest\URMembership\Admin\Subscriptions;

use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;

defined( 'ABSPATH' ) || exit;

class Subscriptions {

	public function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		add_filter( 'user_registration_notice_excluded_pages', array( $this, 'add_excluded_page' ) );
		add_action( 'admin_init', array( $this, 'delete_subscription' ) );
	}

	public function delete_subscription() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : null;
		if ( 'user-registration-subscriptions' !== $page ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : null;
		if ( 'delete' !== $action ) {
			return;
		}

		check_admin_referer( isset( $_GET['bulk_action'] ) ? 'bulk-subscriptions' : 'ur_subscription_delete' );
		$ids = array();

		if ( isset( $_GET['bulk_action'] ) ) {
			$ids = array_map( 'absint', $_GET['subscription'] ?? array() );
		} else {
			$ids = array( absint( wp_unslash( $_GET['id'] ?? 0 ) ) );
		}

		$repo = new SubscriptionRepository();
		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			// Cancel at the gateway first (force for PayPal/Stripe) so delete does not leave live billing.
			// send_email=false: admin cleanup should not email members. Ignore cancel result so
			// already-canceled / bank / missing gateway id rows can still be removed locally.
			$repo->cancel_subscription_by_id( $id, false, false, true );
			$repo->delete( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=user-registration-subscriptions&deleted=1' ) );
	}

	public function add_excluded_page( $excluded_pages ) {
		$excluded_pages[] = 'user-registration-subscriptions';
		return $excluded_pages;
	}

	public function add_menu() {

			$page = add_submenu_page(
				'user-registration',
				__( 'Subscriptions', 'user-registration' ),
				__( 'Subscriptions', 'user-registration' ),
				'manage_options',
				'user-registration-subscriptions',
				array( $this, 'render_subscriptions_page' ),
				6
			);

			add_action( "load-$page", array( $this, 'enqueue_scripts_styles' ) );
	}

	public function render_subscriptions_page() {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'create':
				$users            = get_users();
				$membership_plans = ( new MembershipRepository() )->get_all_membership();
				include_once __DIR__ . '/../Views/subscription-create.php';
				break;
			case 'edit': // phpcs:ignore PSR2.ControlStructures.SwitchDeclaration.TerminatingComment
				$id           = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$subscription = ( new SubscriptionRepository() )->retrieve( $id );
				if ( $subscription ) {
					include_once __DIR__ . '/../Views/subscription-edit.php';
					break;
				}
			default:
				echo user_registration_plugin_main_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$table_list = new ListTable();
				$table_list->display_page();
				break;
		}
	}

	public function enqueue_scripts_styles() {
		if ( ! wp_style_is( 'ur-snackbar', 'registered' ) ) {
			wp_register_style(
				'ur-snackbar',
				UR()->plugin_url() . '/assets/css/ur-snackbar/ur-snackbar.css',
				array(),
				UR_VERSION
			);
		}
		wp_enqueue_style( 'ur-snackbar' );

		wp_enqueue_style( 'sweetalert2' );

		if ( ! wp_style_is( 'ur-core-builder-style', 'registered' ) ) {
			wp_register_style(
				'ur-core-builder-style',
				UR()->plugin_url() . '/assets/css/admin.css',
				array(),
				UR_VERSION
			);
		}
		wp_enqueue_style( 'ur-core-builder-style' );

		wp_enqueue_style( 'select2', UR()->plugin_url() . '/assets/css/select2/select2.css', array(), UR_VERSION );

		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script( 'ur-snackbar', UR()->plugin_url() . '/assets/js/ur-snackbar/ur-snackbar' . $suffix . '.js', array(), UR_VERSION, true );
		wp_enqueue_script( 'ur-snackbar' );

		wp_enqueue_script( 'ur-enhanced-select' );

		wp_enqueue_script( 'sweetalert2' );
		wp_enqueue_style( 'sweetalert2' );

		wp_register_style(
			'ur-subscription',
			UR()->plugin_url() . '/assets/css/modules/membership/user-registration-membership-subscription.css',
			array(),
			UR_VERSION
		);

		wp_register_script(
			'ur-subscription',
			UR()->plugin_url() . '/assets/js/modules/membership/admin/subscription' . $suffix . '.js',
			array( 'jquery', 'ur-enhanced-select' ),
			UR_VERSION,
			true
		);

		wp_enqueue_script( 'ur-subscription' );
		wp_enqueue_style( 'ur-subscription' );

		wp_localize_script(
			'ur-subscription',
			'ur_subscription_data',
			array(
				'ajax_url'                       => admin_url( 'admin-ajax.php' ),
				'subscriptions_url'              => admin_url( 'admin.php?page=user-registration-subscriptions' ),
				'_nonce'                         => wp_create_nonce( 'ur_membership_subscription' ),
				'delete_icon'                    => plugins_url( 'assets/images/users/delete-user-red.svg', UR_PLUGIN_FILE ),
				'i18n_create_success'            => __( 'Subscription created successfully.', 'user-registration' ),
				'i18n_update_success'            => __( 'Subscription updated successfully.', 'user-registration' ),
				'i18n_error'                     => __( 'An error occurred. Please try again.', 'user-registration' ),
				'i18n_network_error'             => __( 'Network error. Please check your connection.', 'user-registration' ),
				'i18n_required_fields'           => __( 'Please fill in all required fields.', 'user-registration' ),
				'payment_gateways'               => get_option( 'ur_membership_payment_gateways', array() ),
				'i18n_prompt_delete_title'       => __( 'Delete Subscription', 'user-registration' ),
				'i18n_prompt_delete_description' => __( 'Are you sure you want to delete this subscription? This will also cancel it at the payment gateway when possible.', 'user-registration' ),
				'i18n_prompt_delete_cancel'      => __( 'Cancel', 'user-registration' ),
				'i18n_prompt_delete_confirm'     => __( 'Delete', 'user-registration' ),
			)
		);
	}
}
