<?php

namespace WPEverest\URMembership\Payment;

use WPEverest\URMembership\Admin\Members\MembersListTable;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Payment\Admin\OrdersListTable;
use WPEverest\URMembership\Admin\Services\MembershipService;
use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;
use WPEverest\URMembership\TableList;
use WPEverest\URTeamMembership\Admin\TeamRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orders {
	protected $page;

	public function __construct() {
		$this->page = 'member-payment-history';
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// add_action( 'admin_menu', array( $this, 'add_orders_menu' ), 40 );
		add_action( 'in_admin_header', array( __CLASS__, 'hide_unrelated_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'init', array( $this, 'add_payment_gateway_options' ) );
		add_action( 'admin_post_ur_admin_download_invoice', array( $this, 'handle_admin_invoice_download' ) );
		$this->includes();
	}

	/**
	 * Remove Notices.
	 */
	public static function hide_unrelated_notices() {
		if ( empty( $_REQUEST['page'] ) || 'member-payment-history' !== $_REQUEST['page'] ) {
			return;
		}
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

	public function add_orders_menu() {

		$orders_repository = new OrdersRepository();
		$args              = array(
			'orderby' => 'order_id',
			'order'   => 'ASC',
		);

		$total_membership_items = $orders_repository->get_all( $args );
		$total_form_items       = urm_get_form_user_payments( $args );

		$total_items = array_merge( $total_membership_items, $total_form_items );

			$orders_page = add_submenu_page(
				'user-registration',
				__( 'Payments', 'user-registration' ), // page title
				__( 'Payments', 'user-registration' ),
				'manage_user_registration', // Capability required to access
				$this->page, // Menu slug
				array(
					$this,
					'render_payment_history_page',
				),
				5
			);
			add_action( 'load-' . $orders_page, array( $this, 'orders_initialization' ) );
	}


	public function orders_initialization() {
		if ( isset( $_GET['page'] ) && $this->page === $_GET['page'] ) {

			$action_page = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
			switch ( $action_page ) {
				case 'add_new_membership':
					break;
				default:
					global $orders_list_table;
					$orders_list_table = new OrdersListTable();
					$orders_list_table->process_actions();
					break;
			}
		}
	}

	/**
	 * Enqueue scripts
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		if ( empty( $_GET['page'] ) || 'member-payment-history' !== $_GET['page'] ) {
			return;
		}
		$suffix = defined( 'SCRIPT_DEBUG' ) ? '' : '.min';
		if ( ! wp_script_is( 'ur-snackbar', 'reqistered' ) ) {
			wp_register_script( 'ur-snackbar', UR()->plugin_url() . '/assets/js/ur-snackbar/ur-snackbar' . $suffix . '.js', array(), UR_VERSION, true );
			wp_enqueue_script( 'ur-snackbar' );
		}
		wp_enqueue_script( 'sweetalert2' );
		wp_register_script( 'payment-history', UR()->plugin_url() . '/assets/js/modules/membership/admin/payment-history' . $suffix . '.js', array( 'jquery', 'ur-enhanced-select' ), UR_VERSION, true );
		wp_enqueue_script( 'payment-history' );

		$this->localize_scripts();
	}

	/**
	 * Enqueue Styles
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( empty( $_GET['page'] ) || 'member-payment-history' !== $_GET['page'] ) {
			return;
		}
		wp_register_style( 'payment-history-css', UR()->plugin_url() . '/assets/css/modules/payment-history/user-registration-payment-history.css', array(), UR_VERSION );
		wp_register_style( 'ur-core-builder-style', UR()->plugin_url() . '/assets/css/admin.css', array(), UR_VERSION );
		wp_enqueue_style( 'ur-core-builder-style' );
		wp_register_style( 'ur-membership-admin-style', UR()->plugin_url() . '/assets/css/modules/membership/user-registration-membership-admin.css', array(), UR_VERSION );
		wp_enqueue_style( 'ur-membership-admin-style' );
		if ( ! wp_style_is( 'ur-snackbar', 'reqistered' ) ) {
			wp_register_style( 'ur-snackbar', UR()->plugin_url() . '/assets/css/ur-snackbar/ur-snackbar.css', array(), UR_VERSION );
		}
		wp_enqueue_style( 'payment-history-css' );
		wp_enqueue_style( 'sweetalert2' );
		wp_enqueue_style( 'ur-snackbar' );
		wp_enqueue_style( 'select2', UR()->plugin_url() . '/assets/css/select2/select2.css', array(), UR_VERSION );
	}

	/**
	 * @return void
	 */
	public function render_payment_history_page() {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';

		switch ( $action ) {
			case 'add_new_payment':
				$this->render_add_new_payment_history();
				break;
			case 'edit': // phpcs:ignore PSR2.ControlStructures.SwitchDeclaration.TerminatingComment
				$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
				$type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'order';

				$order            = array();
				$order_repository = new OrdersRepository();

				if ( 'form' === $type ) {
					$order_service            = new \WPEverest\URMembership\Payment\Admin\OrderService();
					$invoice_index            = isset( $_GET['invoice'] ) ? absint( $_GET['invoice'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view index, no state change.
					$order                    = $order_service->get_user_form_order_detail( $id, $invoice_index );
					$order['order_id']        = 0;
					$order['is_form_payment'] = true;
				} else {
					$order                    = $order_repository->get_order_detail( $id );
					$order['is_form_payment'] = false;
				}

				$order           = apply_filters( 'ur_membership_payment_history_order', $order );
				$order_meta_data = ! empty( $order['order_id'] ) ? $order_repository->get_order_meta_by_order_id_and_meta_key( $order['order_id'], 'urm_team_id' ) : '';
				$team_id         = ! empty( $order_meta_data['meta_value'] ) ? $order_meta_data['meta_value'] : '';
				$team            = '';
				if ( $team_id ) {
					$team_repository = new TeamRepository();
					$team            = $team_repository->get_single_team_by_ID( $team_id );
				}

				if ( ! empty( $order ) ) {
					include_once __DIR__ . '/Views/payment-edit.php';
					break;
				}
			default:
				$this->render_payment_history_list();
				break;
		}
	}

	/**
	 * Renders add new payment history form.
	 *
	 * @return void
	 */
	public function render_add_new_payment_history_scratch() {
		global $orders_list_table;

		if ( ! $orders_list_table ) {
			return;
		}
		$enable_members_button = true;
		?>
<style>
.navigator {
	padding: 8px 14px;
	background-color: #f0f0f0;
	border-radius: 4px;
	cursor: pointer;
}
</style>
<div class="ur-membership-header ur-d-flex ur-mr-0 ur-p-3 ur-align-items-center" id=""
	style="margin-left: -20px; background:white; gap: 20px; position: sticky; top: 32px; z-index: 700">
	<img style="max-width: 30px" src="<?php echo UR()->plugin_url() . '/assets/images/logo.svg'; ?>" alt="">
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->page ) ); ?>"
		class="<?php echo esc_attr( ( $_GET['page'] == $this->page ) ? 'row-title' : '' ); ?>"
		style="text-decoration: none">
		<?php esc_html_e( 'Payment History', 'user-registration' ); ?>
	</a>
</div>

<div id="user-registration-list-table-page">
	<div class="user-registration-list-table-heading" id="ur-users-page-topnav" style="gap: 14px;">
		<div class="navigator navigator-prev" onclick="window.history.back();"><span
				class="dashicons dashicons-arrow-left-alt2"></span></div>
		<div class="ur-page-title__wrapper">
			<h1>
				<?php esc_html_e( 'Add Manual Payment', 'user-registration' ); ?>
			</h1>
		</div>
	</div>
	<hr>
	<form method="get" id="ur-membership-payment-history-form">
		<input type="hidden" name="page" value="<?php echo esc_attr( $this->page ); ?>" />
		<input type="hidden" name="action" value="<?php echo isset( $_GET['action'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) : ''; ?>" />
		<div>
			<strong>Important Note:</strong>
			This form is intended only to record missed payments for tracking purposes. Adding a payment here does not
			renew the next billing cycle or assign any new plan to the user.
		</div>

	</form>
</div>
		<?php
	}

	public function render_add_new_payment_history() {
		global $wpdb;
		$subscription_table = \WPEverest\URMembership\TableList::subscriptions_table();
		$users              = $wpdb->get_results(
			"
						SELECT s.user_id, s.item_id, u.user_login, u.user_email
						FROM $subscription_table AS s
						INNER JOIN {$wpdb->users} AS u
							ON s.user_id = u.ID
						",
			ARRAY_A
		);
		$users              = array_filter(
			$users,
			function ( $user ) {
				$post = get_post(
					$user['item_id'],
					array(
						'post_type'   => 'ur_memberships',
						'post_status' => 'publish',
					)
				);
				if ( $post && ! empty( $post->post_content ) ) {
					$membership = json_decode( wp_unslash( $post->post_content ), true );
					return isset( $membership['type'] ) && $membership['type'] !== 'free';
				}
				return false;
			}
		);
		$memberships        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title as title FROM {$wpdb->posts} WHERE post_type = %s AND post_status=%s",
				'ur_membership',
				'publish',
			),
			ARRAY_A
		);
		$membership_plans   = array();
		foreach ( $memberships as $membership ) {
			$membership_details = json_decode( wp_unslash( ur_get_single_post_meta( $membership['ID'], 'ur_membership' ) ), true );
			$membership_plans[] = array_merge( $membership, array( 'amount' => $membership_details['amount'] ) );
		}
		$payment_methods = get_option( 'ur_membership_payment_gateways', array() );
		include __DIR__ . '/Views/orders-create.php';
	}
	/**
	 * render_payment_history_list
	 *
	 * @return void
	 */
	public function render_payment_history_list() {
		global $orders_list_table;

		if ( ! $orders_list_table ) {
			return;
		}
		$enable_members_button = true;
		?>
		<hr class="wp-header-end">
				<?php echo user_registration_plugin_main_header(); ?>
		<div id="payment-detail-modal" class="modal">
			<div class="modal-content">
				<div class="modal-header">
					<span class="close-button">&times;</span>
					<h2><?php echo __( 'Transaction Details', 'user-registration' ); ?></h2>
					<hr>
				</div>
				<div class="modal-body">
				</div>
			</div>
		</div>
		<?php
		$orders_list_table->display_page();
		?>
		<?php
	}

	/**
	 * includes
	 *
	 * @return void
	 */
	public function includes() {
		new AJAX();
	}

	/**
	 * localize_scripts
	 *
	 * @return void
	 */
	public function localize_scripts() {

		$memberships = get_posts(
			array(
				'post_type'   => 'ur_membership',
				'numberposts' => - 1,
			)
		);
		$memberships = array_filter(
			json_decode( json_encode( $memberships ), true ),
			function ( $item ) {
				$content = json_decode( wp_unslash( $item['post_content'] ), true );

				return ! empty( $content['status'] );
			}
		);
		$memberships = wp_list_pluck( $memberships, 'post_title', 'ID' );

		wp_localize_script(
			'payment-history',
			'urm_orders_localized_data',
			array(
				'_nonce'                 => wp_create_nonce( 'ur_member_orders' ),
				'ajax_url'               => admin_url( 'admin-ajax.php' ),
				'labels'                 => $this->get_i18_labels(),
				'membership_page_url'    => admin_url( 'admin.php?page=user-registration-membership&action=add_new_membership' ),
				'ur_forms'               => ur_get_all_user_registration_form(),
				'memberships'            => $memberships,
				'delete_icon'            => plugins_url( 'assets/images/users/delete-user-red.svg', UR_PLUGIN_FILE ),
				'add_manual_payment_url' => admin_url( 'admin.php?page=member-payment-history&action=add_new_payment' ),
				'payment_history_url'    => admin_url( 'admin.php?page=member-payment-history' ),
			)
		);
	}

	/**
	 * get_i18_labels
	 *
	 * @return array
	 */
	public function get_i18_labels() {
		return array(
			'network_error'                 => esc_html__( 'Network error', 'user-registration' ),
			'i18n_prompt_title'             => __( 'Delete Orders', 'user-registration' ),
			'i18n_prompt_bulk_subtitle'     => __( 'Are you sure you want to delete these orders permanently?', 'user-registration' ),
			'i18n_prompt_single_subtitle'   => __( 'Are you sure you want to delete this order permanently?', 'user-registration' ),
			'i18n_prompt_delete'            => __( 'Delete', 'user-registration' ),
			'i18n_prompt_cancel'            => __( 'Cancel', 'user-registration' ),
			'i18n_payment_completed'        => __( 'Completed', 'user-registration' ),
			'i18n_prompt_no_order_selected' => __( 'Please select at least one order.', 'user-registration' ),
		);
	}

	/**
	 * Adds the membership options to the database.
	 *
	 * This function adds the payment gateways for the membership plugin to the
	 * WordPress options table. The payment gateways are stored in the 'ur_payment_gateways'
	 * option and are an array containing the strings 'Paypal', 'Stripe', and 'Bank'.
	 *
	 * @return void
	 */
	public function add_payment_gateway_options() {

		$payment_gateways = array(
			'paypal'      => __( 'Paypal', 'user-registration' ),
			'stripe'      => __( 'Stripe', 'user-registration' ),
			'credit_card' => __( 'Stripe (Credit Card)', 'user-registration' ),
			'bank'        => __( 'Bank', 'user-registration' ),
		);
		/**
		 * Filters that hold the list of payment gateway for payment orders.
		 *
		 *@param array $payment_gateways
		*/
		$payment_gateways = apply_filters( 'user_registration_payment_gateways', $payment_gateways );

		update_option( 'ur_payment_gateways', $payment_gateways );
		/**
		 * Filters that hold the list of payment gateway for payment orders.
		 *
		 *@param array $payment_gateways
		*/
		$payment_gateways = apply_filters( 'user_registration_payment_gateways', $payment_gateways );

		update_option( 'ur_payment_gateways', $payment_gateways );
	}

	/**
	 * Generate and stream an invoice PDF for a membership order from the admin payments list.
	 *
	 * Hooked to admin_post_ur_admin_download_invoice. Mirrors the data-assembly logic of
	 * User_Registration_Payments_Frontend::download_invoice_pdf() but works directly from
	 * order_id instead of requiring a frontend session.
	 *
	 * @return void
	 */
	public function handle_admin_invoice_download() {
		if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ur_admin_download_invoice' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'user-registration' ) );
		}

		if ( ! current_user_can( 'manage_user_registration' ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_die( esc_html__( 'Permission denied.', 'user-registration' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_die( esc_html__( 'Invalid order.', 'user-registration' ) );
		}

		if ( ! function_exists( 'ur_pro_generate_pdf_file' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/pro/functions-payments.php';
		}

		$order_repository = new OrdersRepository();
		$order            = $order_repository->get_order_detail( $order_id );

		if ( empty( $order ) ) {
			wp_die( esc_html__( 'Order not found.', 'user-registration' ) );
		}

		$user_id            = $order['user_id'] ?? 0;
		$post_id            = $order['post_id'] ?? 0;
		$membership_service = new MembershipService();
		$membership         = $membership_service->get_membership_details( $post_id );
		$membership_info    = $membership_service->get_membership_title_and_description( $post_id );

		// Billing period.
		$period = __( 'All Time', 'user-registration' );
		if ( ! empty( $order['subscription_start_date'] ) && ! empty( $order['next_billing_date'] ) ) {
			$period = gmdate( 'M d, Y', strtotime( $order['subscription_start_date'] ) )
				. ' to '
				. gmdate( 'M d, Y', strtotime( $order['next_billing_date'] ) );
		}

		// Invoice number — admin downloads do not increment the shared counter.
		$invoice_format           = get_option( 'urm_invoice_format', '' );
		$invoice_starts_from      = get_option( 'urm_invoice_starts_from', 1 );
		$total_invoices_generated = get_option( 'urm_total_invoices_generated', 0 );
		if ( ! empty( $invoice_format ) ) {
			$current_invoice_number   = intval( $invoice_starts_from ) + intval( $total_invoices_generated );
			$generated_invoice_number = str_replace( array( '{{year}}', '{year}' ), gmdate( 'Y' ), $invoice_format );
			$generated_invoice_number = str_replace( array( '{{id}}', '{id}' ), $order_id, $generated_invoice_number );
			$generated_invoice_number = str_replace( array( '{{counter}}', '{counter}' ), $current_invoice_number, $generated_invoice_number );
		} else {
			$generated_invoice_number = 'INV-' . gmdate( 'Y' ) . '-' . $order_id;
		}

		// Currency, symbol, and position.
		$local_currency_meta = $order_repository->get_order_meta_by_order_id_and_meta_key( $order_id, 'local_currency' );
		$currency            = ! empty( $local_currency_meta['meta_value'] ) ? $local_currency_meta['meta_value'] : get_option( 'user_registration_payment_currency', 'USD' );
		$currencies          = ur_payment_integration_get_currencies();
		$symbol              = html_entity_decode( ur_get_currency_symbol( $currency ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$symbol_pos          = isset( $currencies[ $currency ]['symbol_pos'] ) ? $currencies[ $currency ]['symbol_pos'] : 'left';

		// Amounts and tax.
		$raw_total   = floatval( $order['total_amount'] );
		$is_trial    = isset( $order['trial_status'] ) && 'on' === $order['trial_status'];
		$plan_amount      = ! empty( $membership['amount'] ) ? floatval( $membership['amount'] ) : $raw_total;
		$plan_amount_base = $plan_amount;
		$fx_ratio         = 1.0;

		// Convert $plan_amount into $currency for the "Original Plan Amount" row; $fx_ratio also scales the coupon below.
		if (
			$currency !== get_option( 'user_registration_payment_currency', 'USD' ) &&
			! empty( $membership['local_currency'] ) &&
			ur_string_to_bool( $membership['local_currency']['is_enable'] ) &&
			class_exists( CoreFunctions::class )
		) {
			$plan_zone_id = CoreFunctions::ur_get_zone_id_by_currency( $membership['local_currency'], $currency );

			if ( ! empty( $plan_zone_id ) ) {
				$plan_pricing_data = CoreFunctions::ur_get_pricing_zone_by_id( $plan_zone_id );
				$plan_amount       = CoreFunctions::ur_get_amount_after_conversion( $plan_amount, $currency, $plan_pricing_data, $membership['local_currency'], $plan_zone_id );
				$fx_ratio          = $plan_amount_base > 0 ? ( $plan_amount / $plan_amount_base ) : 1.0;
			}
		}

		$order_meta_data = $order_repository->get_order_meta_by_order_id_and_meta_key( $order_id, 'tax_data' );
		$tax_data        = ! empty( $order_meta_data['meta_value'] ) ? json_decode( $order_meta_data['meta_value'], true ) : array();
		$tax_amount      = ! empty( $tax_data['tax_amount'] ) ? floatval( $tax_data['tax_amount'] ) : 0;
		$tax_rate        = ! empty( $tax_data['tax_rate'] ) ? $tax_data['tax_rate'] : 0;
		$tax_label       = 'Tax ( ' . $tax_rate . ' %)';

		$invoice_pre_tax  = $tax_amount > 0 ? $raw_total - $tax_amount : $raw_total;
		$invoice_amount   = number_format( $invoice_pre_tax, 2, '.', '' );
		$total_amount_str = number_format( $raw_total, 2, '.', '' );

		// Coupon discount — applied to the post-proration amount, back-calculated from
		// what was actually charged (invoice_pre_tax = post_proration − coupon).
		$coupon_code    = $order['coupon'] ?? '';
		$coupon_label   = '';
		$coupon_raw     = 0.0;
		$discount_value = null;
		$discount_type  = 'fixed';

		if ( ! empty( $coupon_code ) ) {
			$coupon_data = ur_get_coupon_details( $coupon_code );
			if ( ! empty( $coupon_data ) ) {
				if ( isset( $coupon_data['coupon_discount'], $coupon_data['coupon_discount_type'] ) ) {
					$discount_value = (float) $coupon_data['coupon_discount'];
					$discount_type  = $coupon_data['coupon_discount_type'];
				} elseif ( isset( $coupon_data['discount'] ) ) {
					$discount_value = (float) $coupon_data['discount'];
					$discount_type  = $coupon_data['discount_type'] ?? $coupon_data['coupon_discount_type'] ?? 'fixed';
				}
			}
		}

		// Back-calculate post-proration amount and coupon from what was charged.
		if ( null !== $discount_value && $discount_value > 0 ) {
			if ( 'percent' === $discount_type ) {
				$pct                   = min( (float) $discount_value, 100 );
				$post_proration_amount = $pct < 100 ? round( $invoice_pre_tax / ( 1 - $pct / 100 ), 4 ) : $plan_amount;
				$coupon_raw            = round( $post_proration_amount * $pct / 100, 2 );
				$coupon_label          = 'Coupon ( ' . $pct . '% )';
			} else {
				// $discount_value is fixed in the base currency — scale by $fx_ratio before mixing with $invoice_pre_tax.
				$coupon_raw            = round( $discount_value * $fx_ratio, 2 );
				$post_proration_amount = round( $invoice_pre_tax + $coupon_raw, 4 );
				$coupon_name           = ! empty( $coupon_data['coupon_name'] ) ? $coupon_data['coupon_name'] : $coupon_code;
				$coupon_label          = 'Coupon ( ' . $coupon_name . ' )';
			}
		} else {
			$post_proration_amount = $invoice_pre_tax;
		}

		// Proration = plan price minus post-proration amount.
		$prorate_discount = ( ! $is_trial && $raw_total > 0.005 && $plan_amount > $post_proration_amount + 0.005 )
			? max( round( $plan_amount - $post_proration_amount, 2 ), 0 )
			: 0;

		$fmt_tax_bare    = number_format( $tax_amount, 2, '.', '' );
		$fmt_item        = $invoice_amount;
		$fmt_tax         = $fmt_tax_bare;
		$fmt_subtotal    = $invoice_amount;
		$fmt_total       = $total_amount_str;
		$fmt_plan        = ( $prorate_discount > 0 || $coupon_raw > 0 ) ? number_format( $plan_amount, 2, '.', '' ) : '';
		$fmt_prorate     = $prorate_discount > 0 ? number_format( $prorate_discount, 2, '.', '' ) : '';
		$fmt_coupon_bare = $coupon_raw > 0 ? number_format( $coupon_raw, 2, '.', '' ) : '';
		$fmt_coupon      = $fmt_coupon_bare;

		if ( ! empty( $symbol ) ) {
			if ( 'right' === $symbol_pos ) {
				$fmt_item     = $invoice_amount . ' ' . $symbol;
				$fmt_tax      = $fmt_tax_bare . ' ' . $symbol;
				$fmt_subtotal = $invoice_amount . ' ' . $symbol;
				$fmt_total    = $total_amount_str . ' ' . $symbol;
				if ( '' !== $fmt_plan ) {
					$fmt_plan    = $fmt_plan . ' ' . $symbol;
					$fmt_prorate = '' !== $fmt_prorate ? $fmt_prorate . ' ' . $symbol : '';
					$fmt_coupon  = '' !== $fmt_coupon ? $fmt_coupon . ' ' . $symbol : '';
				}
			} else {
				$fmt_item     = $symbol . $invoice_amount;
				$fmt_tax      = $symbol . $fmt_tax_bare;
				$fmt_subtotal = $symbol . $invoice_amount;
				$fmt_total    = $symbol . $total_amount_str;
				if ( '' !== $fmt_plan ) {
					$fmt_plan    = $symbol . $fmt_plan;
					$fmt_prorate = '' !== $fmt_prorate ? $symbol . $fmt_prorate : '';
					$fmt_coupon  = '' !== $fmt_coupon ? $symbol . $fmt_coupon : '';
				}
			}
		}

		// Customer info.
		$user          = get_user_by( 'ID', $user_id );
		$first_name    = get_user_meta( $user_id, 'first_name', true );
		$last_name     = get_user_meta( $user_id, 'last_name', true );
		$customer_name = trim( $first_name . ' ' . $last_name );

		$smart_tag_context = array(
			'email'   => $user ? $user->data->user_email : '',
			'user_id' => $user_id,
		);

		$customer_detail = apply_filters( 'user_registration_process_smart_tags', ur_string_translation( 0, 'urm_invoice_customer_info', get_option( 'urm_invoice_customer_info' ) ), $smart_tag_context, array() );

		$footer_notes = apply_filters(
			'user_registration_process_smart_tags',
			ur_string_translation(
				0,
				'urm_invoice_footer_content',
				get_option(
					'urm_invoice_footer_content',
					'<p style="margin: 0 0 12px 0; color: #6c757d; font-size: 13px; line-height: 1.5;">Thank you for your purchase!</p>'
					. '<p style="margin: 0; font-size: 14px; line-height: 1.6;"><a href="{{home_url}}" style="color: #4A90E2; text-decoration: none; font-weight: 500;">{{blog_info}} Team</a></p>'
				)
			),
			$smart_tag_context,
			array()
		);

		$context_ssl_off = array(
			'ssl' => array(
				'verify_peer'      => false,
				'verify_peer_name' => false,
			),
		);
		stream_context_set_default( $context_ssl_off );

		$args = array(
			'html'                 => '',
			'company_name'         => get_option( 'urm_business_name', get_bloginfo( 'name' ) ),
			'company_email'        => get_option( 'urm_business_email', get_option( 'admin_email' ) ),
			'invoice_number'       => $generated_invoice_number,
			'invoice_date'         => gmdate( 'M d, Y', strtotime( $order['created_at'] ) ),
			'invoice_due_date'     => '',
			'invoice_status'       => $is_trial ? 'TRIAL' : ( 'completed' === $order['status'] ? 'PAID' : strtoupper( $order['status'] ) ),
			'customer_name'        => $customer_name,
			'customer_email'       => $order['user_email'] ?? '',
			'customer_detail'      => $customer_detail,
			'company_detail'       => '',
			'item_amount'          => $fmt_item,
			'subtotal'             => $fmt_subtotal,
			'original_plan_amount' => $fmt_plan,
			'prorate_discount'     => $fmt_prorate,
			'coupon_label'         => $coupon_label,
			'coupon_discount'      => $fmt_coupon,
			'coupon_discount_raw'  => $fmt_coupon_bare,
			'tax_label'            => $tax_label,
			'tax_amount'           => $fmt_tax,
			'tax_amount_raw'       => $fmt_tax_bare,
			'total_amount'         => $fmt_total,
			'footer_notes'         => $footer_notes,
			'footer_thanks'        => __( 'Thank you for your membership!', 'user-registration' ),
			'footer_support'       => sprintf(
				/* translators: %s: support email */
				__( 'Questions? Contact us at %s', 'user-registration' ),
				get_option( 'urm_business_email', get_option( 'admin_email' ) )
			),
			'is_trial'             => $is_trial,
			'is_tax_enabled'       => ur_check_module_activation( 'taxes' ),
			'item_description'     => $membership_info['item_title'] ?? ( $order['post_title'] ?? '' ),
			'item_detail_text'     => $membership_info['item_description'] ?? ( $order['post_content'] ?? '' ),
			'item_period'          => $period,
		);

		$file_name = ! empty( $user ) ? $user->user_login : 'invoice-' . $order_id;

		if ( ob_get_level() ) {
			ob_end_clean();
		}
		$tcpdf = ur_pro_generate_pdf_file( $args );
		$tcpdf->output( $file_name . '.pdf', 'D' );

		stream_context_set_default(
			array(
				'ssl' => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
				),
			)
		);
		exit;
	}
}

new Orders();
