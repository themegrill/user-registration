<?php
/**
 * User Registration Membership Table List
 *
 * @version 1.0.0
 */

namespace WPEverest\URMembership\Payment\Admin;

use UR_Base_Layout;
use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\TableList;

if ( ! class_exists( 'UR_List_Table' ) ) {
	include_once dirname( UR_PLUGIN_FILE ) . '/includes/abstracts/abstract-ur-list-table.php';
}
if ( ! class_exists( 'UR_Base_Layout' ) ) {
	include_once dirname( UR_PLUGIN_FILE ) . '/includes/admin/class-ur-admin-base-layout.php';
}

/**
 * Orders table list class.
 */
class OrdersListTable extends \UR_List_Table {

	protected $orders_repository;

	protected $is_membership_active;

	/**
	 * Initialize the Orders table list.
	 */
	public function __construct() {

		$this->page                 = 'member-payment-history';
		$this->per_page_option      = 'user_registration_membership_per_page';
		$this->addnew_action        = 'add_new_orders';
		$this->sort_by              = array(
			'title' => array( 'title', false ),
		);
		$this->is_membership_active = ur_check_module_activation( 'membership' );
		$this->orders_repository    = ( $this->is_membership_active ) ? new OrdersRepository() : '';

		parent::__construct(
			array(
				'singular' => 'order',
				'plural'   => 'orders',
				'ajax'     => true,
			)
		);
	}


	/**
	 * Prepares the items for display in the list table.
	 *
	 * This function prepares the column headers, sets the items per page, gets the current page number,
	 * determines the payment for parameter, prepares the query arguments, retrieves the total items,
	 * sets the items, and sets the pagination arguments.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->prepare_column_headers();
		$per_page     = $this->get_items_per_page( $this->per_page_option );
		$current_page = $this->get_pagenum();
		$payment_for  = isset( $_REQUEST['payment_for'] ) && ! empty( $_REQUEST['payment_for'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['payment_for'] ) ) : '';

		$total_items = array();
		if ( '' === $payment_for ) {
			$args_forms       = $this->prepare_query_args( 'forms', 999999, 1 );
			$form_items       = $this->get_user_payments( $args_forms );
			$membership_items = array();
			if ( $this->is_membership_active && $this->orders_repository ) {
				$args_memberships = $this->prepare_query_args( 'memberships', 999999, 1 );
				$membership_items = $this->orders_repository->get_all( $args_memberships );
			}
			$total_items = array_merge( $form_items, is_array( $membership_items ) ? $membership_items : array() );
			usort(
				$total_items,
				function ( $a, $b ) {
					$t_a = ! empty( $a['created_at'] ) ? strtotime( $a['created_at'] ) : 0;
					$t_b = ! empty( $b['created_at'] ) ? strtotime( $b['created_at'] ) : 0;
					return $t_b - $t_a;
				}
			);
			$total_count = count( $total_items );
			$this->items = array_slice( $total_items, ( $current_page - 1 ) * $per_page, $per_page );
			$this->set_pagination_args(
				array(
					'total_items' => $total_count,
					'per_page'    => $per_page,
					'total_pages' => ceil( $total_count / $per_page ),
				)
			);
			return;
		}

		$args = $this->prepare_query_args( $payment_for, $per_page, $current_page );

		if ( $payment_for === 'memberships' ) {
			$total_items = $this->orders_repository->get_all( $args );
		} else {
			$total_items = $this->get_user_payments( $args );
		}

		$this->items = $total_items;

		$this->set_pagination_args(
			array(
				'total_items' => count( $total_items ),
				'per_page'    => $per_page,
				'total_pages' => ceil( count( $total_items ) / $per_page ),
			)
		);
	}

	/**
	 * @param $payment_for
	 * @param $per_page
	 * @param $current_page
	 *
	 * @return array
	 */
	private function prepare_query_args( $payment_for, $per_page, $current_page ) {
		$args = array(
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
		);

		$params_mapping = array(
			'status'         => 'ur_payment_status',
			'membership_id'  => 'membership_id',
			'payment_method' => 'ur_payment_method',
			'form_id'        => 'ur_form_id',
			's'              => 'search',
		);

		foreach ( $params_mapping as $request_param => $query_param ) {
			if ( ! empty( $_REQUEST[ $request_param ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_REQUEST[ $request_param ] ) );
				if ( $payment_for === 'memberships' ) {
					$args[ $request_param ] = $value;
				} elseif ( $query_param === 'search' ) {
						$args['search']         = '*' . esc_attr( $value ) . '*';
						$args['search_columns'] = array( 'display_name', 'user_email' );
				} else {
					$args['meta_query'][] = array(
						'key'     => $query_param,
						'value'   => $value,
						'compare' => 'LIKE',
					);
				}
			}
		}

		$orderby         = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		$args['orderby'] = in_array( $orderby, array( 'created_at', 'status' ) ) ? $orderby : 'created_at';

		$order         = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
		$args['order'] = in_array( $order, array( 'ASC', 'DESC' ) ) ? $order : 'DESC';

		return $args;
	}

	/**
	 * @param $args
	 *
	 * @return array
	 */
	private function get_user_payments( $args ) {
		$args['meta_key']               = 'ur_payment_status';
		$args['meta_compare']           = 'EXISTS';
		$args['meta_query']['relation'] = 'AND';

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
					'post_title'     => $this->get_invoice_product_label( $meta_value[0] ?? array() ),
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

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'transaction_id':
				return isset( $item['transaction_id'] ) && ! empty( $item['transaction_id'] ) ? $item['transaction_id'] : ( $item['order_id'] ?? '' );
			case 'username':
				return esc_html( $item['display_name'] );
			case 'membership_type':
				return $this->show_column_membership_type( $item );
			case 'status':
				return $this->show_column_status( $item );
			case 'created_at':
				return date_i18n( get_option( 'date_format' ), strtotime( $item[ $column_name ] ) );
			case 'post_title':
			case 'payment_method':
				return esc_html( ucfirst( $item[ $column_name ] ) );
			case 'payer_email':
				return esc_html( $item['user_email'] );
			default:
				return print_r( $item, true ); // Fallback output for unknown columns
		}
	}

	public function column_transaction_id( $row ) {
		return sprintf(
			'<strong><div class="ur-edit-title"><a href="%s" class="row-title">%s</a></div></strong>%s',
			esc_url( isset( $row['order_id'] ) ? admin_url( "admin.php?page=member-payment-history&action=edit&id={$row['order_id']}" ) : '' ),
			esc_html( isset( $row['transaction_id'] ) && ! empty( $row['transaction_id'] ) ? $row['transaction_id'] : ( $row['order_id'] ?? '' ) ),
			$this->row_actions( $this->get_row_actions( $row ) )
		);
	}

	/**
	 * No items found text.
	 */
	public function no_items() {
		UR_Base_Layout::no_items( 'Payments' );
	}

	/**
	 * Get list columns.
	 *
	 * @return array
	 */

	/**
	 * Product name for a payment record.
	 *
	 * Only Stripe subscriptions set invoice_plan; PayPal, Mollie and one-time
	 * payments do not, so fall back to the labels stored in the cart items.
	 *
	 * @param array $invoice First entry of ur_payment_invoices.
	 * @return string
	 */
	private function get_invoice_product_label( $invoice ) {
		if ( ! empty( $invoice['invoice_plan'] ) ) {
			return $invoice['invoice_plan'];
		}

		$items = isset( $invoice['invoice_item'] ) ? $invoice['invoice_item'] : '';

		// ur_cart_items is read without the single flag, so it arrives wrapped in an array.
		if ( is_array( $items ) ) {
			$items = reset( $items );
		}

		if ( is_string( $items ) ) {
			$items = json_decode( $items );
		}

		if ( empty( $items ) || ! is_array( $items ) ) {
			return '';
		}

		$labels = array();

		foreach ( $items as $item ) {
			// Choice based fields store the selection as label => amount.
			if ( isset( $item->value ) && ( is_array( $item->value ) || is_object( $item->value ) ) ) {
				$labels = array_merge( $labels, array_keys( (array) $item->value ) );
				continue;
			}

			if ( ! empty( $item->label ) ) {
				$labels[] = $item->label;
			}
		}

		return implode( ', ', array_unique( array_filter( $labels ) ) );
	}

	public function get_columns() {
		return array(
			'cb'              => '<input type="checkbox" />',
			'transaction_id'  => __( 'Transaction ID', 'user-registration' ),
			'username'        => __( 'Username', 'user-registration' ),
			'post_title'      => __( 'Product', 'user-registration' ),
			'membership_type' => __( 'Type', 'user-registration' ),
			'payment_method'  => __( 'Gateway', 'user-registration' ),
			'payer_email'     => __( 'Payer Email', 'user-registration' ),
			'status'          => __( 'Status', 'user-registration' ),
			'total'           => __( 'Total', 'user-registration' ),
			'created_at'      => __( 'Payment Date', 'user-registration' ),
		);
	}


	/**
	 * Post Edit Link.
	 *
	 * @param object $row
	 *
	 * @return string
	 */
	public function get_edit_links( $row ) {
		return admin_url( 'admin.php?post_id=' . $row['order_id'] ?? 0 . '&action=' . $this->addnew_action . '&page=' . $this->page );
	}

	/**
	 * Post Duplicate Link.
	 *
	 * @param object $row
	 *
	 * @return string
	 */
	public function get_duplicate_link( $row ) {
		return admin_url( 'post.php?post=' . $row->ID . '&action=edit' );
	}

	/**
	 * @param $order
	 *
	 * @return array
	 */
	public function get_row_actions( $order ) {
		$order_id  = $order['order_id'] ?? 0;
		$user_id   = $order['user_id'] ?? 0;
		$edit_id   = $order_id ? $order_id : $user_id;
		$edit_type = $order_id ? 'order' : 'form';

		$actions = array(
			'id'     => sprintf(
				/* translators: %d: Item id */
				__( 'ID: %d', 'user-registration' ),
				$order_id ?: $user_id
			),
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=member-payment-history&action=edit&id=' . $edit_id . '&type=' . $edit_type ) ),
				esc_html__( 'Edit', 'user-registration' )
			),
			'delete' => '<a data-user-id=' . esc_attr( $user_id ) . ' data-order-id = ' . esc_attr( $order_id ) . ' class="single-delete-order" style="cursor:pointer" >' . esc_html__( 'Trash', 'user-registration' ) . '</a>',
		);

		if ( $order_id && ur_check_module_activation( 'pdf-invoice' ) ) {
			$actions['download_invoice'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=ur_admin_download_invoice&order_id=' . $order_id ),
						'ur_admin_download_invoice'
					)
				),
				esc_html__( 'Download Invoice', 'user-registration' )
			);
		}

		return $actions;
	}

	public function get_delete_links( $row ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'   => $this->page,
					'screen' => $this->get_screen(),
					'action' => 'delete',
					'id'     => $row['order_id'] ?? 0,
				),
				admin_url( 'admin.php' )
			),
			'delete'
		);
	}

	/**
	 * @param $order
	 *
	 * @return string
	 */
	public function show_column_status( $order ) {

		$order_id     = $order['order_id'] ?? $order['user_id'];
		$status_class = 'user-payment-secondary';
		if ( 'completed' === $order['status'] ) {
			$status_class = 'user-payment-completed';
		} elseif ( 'pending' === $order['status'] ) {
			$status_class = 'user-payment-pending';
		} elseif ( 'failed' === $order['status'] ) {
			$status_class = 'user-subscription-failed';
		}

		return sprintf( '<span id="ur-order-%d"  class="payment-status-btn %s">%s</span>', absint( $order_id ), $status_class, esc_html( ucfirst( $order['status'] ) ) );
	}

	/**
	 * @param $orders
	 *
	 * @return string
	 */
	public function show_column_membership_type( $orders ) {

		if ( isset( $orders['order_id'] ) ) {
			// Use the order_type stored at payment time so it never changes if the
			// membership type is later edited. Fall back to post_content only when missing.
			if ( ! empty( $orders['order_type'] ) ) {
				$type = $orders['order_type'];
			} else {
				$data = json_decode( wp_unslash( $orders['post_content'] ), true );
				$type = $data['type'] ?? '';
			}
		} else {
			$type = $orders['type'];
		}
		$status_class = ( $type == 'free' ? 'user-registration-badge user-registration-badge--success-subtle' : ( $type == 'paid' ? 'user-registration-badge user-registration-badge--secondary-subtle' : 'user-registration-badge user-registration-badge--danger-subtle' ) );

		return sprintf( '<span class="%s">%s</span>', $status_class, esc_html( ucfirst( $type ) ) );
	}

	public function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at' ),
			'status'     => array( 'status' ),
		);
	}

	public function column_created_at( $item ) {
		global $wpdb;
		$orders_meta_table = TableList::order_meta_table();
		$payment_date      = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$orders_meta_table} WHERE meta_key=%s AND order_id=%d LIMIT 1",
				'payment_date',
				$item['order_id'] ?? 0
			)
		);
		$payment_date      = ! empty( $payment_date ) ? $payment_date : $item['created_at'];
		return ( new \DateTime( $payment_date ) )->format( 'F j, Y' );
	}

	public function column_total( $item ) {
		$total_amount        = $item['total_amount'] ?? 0;
		$currency            = get_option( 'user_registration_payment_currency', 'USD' );
		$currencies          = ur_payment_integration_get_currencies();
		$currency_info       = isset( $currencies[ $currency ] ) ? $currencies[ $currency ] : $currencies['USD'];
		$symbol              = isset( $currency_info['symbol'] ) ? $currency_info['symbol'] : '$';
		$symbol_pos          = isset( $currency_info['symbol_pos'] ) ? $currency_info['symbol_pos'] : 'left';
		$thousands_separator = isset( $currency_info['thousands_separator'] ) ? $currency_info['thousands_separator'] : ',';
		$decimal_separator   = isset( $currency_info['decimal_separator'] ) ? $currency_info['decimal_separator'] : '.';
		$decimals            = isset( $currency_info['decimals'] ) ? (int) $currency_info['decimals'] : 2;
		$order_id            = $item['order_id'] ?? 0;
		if ( ! empty( $order_id ) && $this->orders_repository ) {
			$order_detail         = $this->orders_repository->get_order_detail( $order_id );
			$item['trial_status'] = $order_detail['trial_status'] ?? 'off';
			$order_repository     = new OrdersRepository();
			$local_currency       = ! empty( $order_detail['order_id'] ) ? $order_repository->get_order_meta_by_order_id_and_meta_key( $order_detail['order_id'], 'local_currency' ) : null;
			if ( ! empty( $local_currency['meta_value'] ) ) {
				$currency = $local_currency['meta_value'];
			}
		} elseif ( ! empty( $item['currency'] ) ) {
			$currency = $item['currency'];
		}
		$symbol       = ur_get_currency_symbol( $currency );
		$trial_status = $item['trial_status'] ?? 'off';

		if ( 'on' === $trial_status ) {
			$formatted_amount = number_format( 0, $decimals, $decimal_separator, $thousands_separator );
			return 'right' === $symbol_pos ? $formatted_amount . ' ' . $symbol : $symbol . $formatted_amount;
		}

		$coupon_discount = 0;
		if ( isset( $item['subscription_id'] ) ) {
			$subscription = ( new MembersSubscriptionRepository() )->get_subscription_by_subscription_id( absint( $item['subscription_id'] ) );
			if ( ! empty( $subscription ) && ! empty( $subscription['coupon'] ) ) {
				$coupon = ur_get_coupon_details( $subscription['coupon'] );
				// if ( ! empty( $coupon ) ) {
				// $discount_value = null;
				// $discount_type  = 'fixed';

				// if ( isset( $coupon['coupon_discount'] ) && isset( $coupon['coupon_discount_type'] ) ) {
				// $discount_value = (float) $coupon['coupon_discount'];
				// $discount_type  = $coupon['coupon_discount_type'];
				// } elseif ( isset( $coupon['discount'] ) ) {
				// $discount_value = (float) $coupon['discount'];
				// $discount_type  = isset( $coupon['discount_type'] ) ? $coupon['discount_type'] : ( isset( $coupon['coupon_discount_type'] ) ? $coupon['coupon_discount_type'] : 'fixed' );
				// }

				// if ( null !== $discount_value && $total_amount ) {
				// if ( 'percent' === $discount_type ) {
				// $coupon_discount = $total_amount * ( $discount_value / 100 );
				// } else {
				// $coupon_discount = $discount_value;
				// }
				// }
				// }
			}
		}

		$total_amount     = max( $total_amount - $coupon_discount, 0 );
		$formatted_amount = number_format( $total_amount, $decimals, $decimal_separator, $thousands_separator );
		return 'right' === $symbol_pos ? $formatted_amount . ' ' . $symbol : $symbol . $formatted_amount;
	}

	/**
	 * Render the list table page, including header, notices, status filters and table.
	 */
	public function display_page() {
		UR_Base_Layout::render_layout(
			$this,
			array(
				'page'           => $this->page,
				'title'          => esc_html__( 'Payments', 'user-registration' ),
				'add_new_action' => 'add_new_payment',
				'search_id'      => 'user-registration-payment-history-search',
				'skip_query_key' => 'add-new-membership',
				'form_id'        => 'ur-membership-payment-history-form',
			)
		);
	}

	public function display() {
		$this->display_tablenav( 'top' );
		$this->screen->render_screen_reader_content( 'heading_list' );
		?>
		<table class="wp-list-table <?php echo implode( ' ', array_map( 'esc_attr', $this->get_table_classes() ) ); ?>">
			<thead>
				<tr>
					<?php $this->print_column_headers(); ?>
				</tr>
			</thead>
			<tbody id="the-list">
				<?php $this->display_rows_or_placeholder(); ?>
			</tbody>
		</table>
		<?php
		$this->display_tablenav( 'bottom' );
	}

	/**
	 * Generates the table navigation above or below the table
	 *
	 * @since 4.1
	 *
	 * @param string $which
	 */
	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			wp_nonce_field( 'bulk-' . $this->_args['plural'] );
		}
		?>
			<div class="tablenav <?php echo esc_attr( $which ); ?>">

			<?php if ( $this->has_items() && 'top' === $which ) : ?>
					<div>
						<div class="alignleft actions bulkactions">
							<?php $this->bulk_actions( $which ); ?>
						</div>
						<?php $this->extra_tablenav( $which ); ?>
					</div>
					<?php
				endif;
			if ( 'top' === $which ) :
				?>
				<div class="user-registration-payments-filters">
				<?php $this->display_advance_filter(); ?>
				</div>
					<?php
				endif;
			if ( 'bottom' === $which ) :
				?>
				<div class="alignleft">
				<?php $this->footer_text(); ?>
				</div>
					<?php
					$this->pagination( $which );
			endif;
			?>
		</div>
		<?php
	}

	/**
	 * Displays the search box.
	 *
	 * @since 4.1
	 */
	public function display_search_box( $input_id ) {
		?>
			<div id="user-registration-list-search-form">
				<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php _admin_search_query(); ?>" placeholder="<?php esc_html_e( 'Search Member', 'user-registration' ); ?>" />
				<button type="submit" id="search-submit">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
						<path fill="#000" fill-rule="evenodd" d="M4 11a7 7 0 1 1 12.042 4.856 1.012 1.012 0 0 0-.186.186A7 7 0 0 1 4 11Zm12.618 7.032a9 9 0 1 1 1.414-1.414l3.675 3.675a1 1 0 0 1-1.414 1.414l-3.675-3.675Z" clip-rule="evenodd"/>
					</svg>
				</button>
			</div>
		<?php
	}


	/**
	 * Displays advance filter.
	 *
	 * @since 4.1
	 */
	public function display_advance_filter() {
		$payment_for_request = isset( $_REQUEST['payment_for'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['payment_for'] ) ) : '';
		$show_membership     = ( 'memberships' === $payment_for_request );
		?>

		<div class="module" style="display: flex; gap: 10px">
			<select name="payment_for" id="user-registration-pro-payment-type-filters">
				<option value="" <?php echo ( '' === $payment_for_request ) ? 'selected=selected' : ''; ?>><?php echo esc_html__( 'All Payments', 'user-registration' ); ?></option>
				<option value="forms"
					<?php echo 'forms' === $payment_for_request ? 'selected=selected' : ''; ?>>
					<?php echo esc_html__( 'Forms', 'user-registration' ); ?></option>
				<?php
				if ( $this->is_membership_active ) :
					?>
				<option value="memberships"
					<?php echo 'memberships' === $payment_for_request ? 'selected=selected' : ''; ?>>
					<?php echo esc_html__( 'Membership', 'user-registration' ); ?></option>
					<?php
								endif;
				?>
			</select>
		</div>
		<!--		membership dropdown-->
		<div class="module-box" id="user-registration-pro-memberships-filters-container"
			style="display:<?php echo $show_membership ? 'flex' : 'none'; ?>; gap: 10px;">
			<select name="membership_id" id="user-registration-pro-memberships-filter">
				<option value=""><?php echo esc_html__( 'All Membership', 'user-registration' ); ?></option>
				<?php
				foreach ( $this->get_all_memberships() as $id => $form ) {
					$selected = isset( $_REQUEST['membership_id'] ) && $id == $_REQUEST['membership_id'] ? 'selected=selected' : '';
					?>
				<option value='<?php echo esc_attr( $id ); ?>' <?php echo esc_attr( $selected ); ?>>
					<?php echo esc_html( $form ); ?></option>
					<?php
				}
				?>
			</select>
		</div>

		<div class="module-box" id="user-registration-pro-forms-filters-container"
			style="display:<?php echo ! $show_membership && 'forms' === $payment_for_request ? 'flex' : 'none'; ?>; gap: 10px;">
			<select name="form_id" id="user-registration-pro-forms-filter">
				<option value=""><?php echo esc_html__( 'All Forms', 'user-registration' ); ?></option>
				<?php
				foreach ( ur_get_all_user_registration_form() as $id => $form ) {
					$selected = isset( $_REQUEST['form_id'] ) && $id == $_REQUEST['form_id'] ? 'selected=selected' : '';
					?>
				<option value='<?php echo esc_attr( $id ); ?>' <?php echo esc_attr( $selected ); ?>>
					<?php echo esc_html( $form ); ?></option>
					<?php
				}
				?>
			</select>
		</div>

		<div class="" id="user-registration-pro-members-filters" style="display: flex; gap: 10px">
			<select name="payment_method" id="user_registration_pro_users_form_filter">
				<option value=""><?php echo esc_html__( 'All Gateway', 'user-registration' ); ?></option>
				<?php
								$options = ( 'memberships' === $payment_for_request ) ? get_option( 'ur_membership_payment_gateways' ) : get_option( 'ur_payment_gateways' );

				foreach ( $options as $id => $form ) {
					$selected = isset( $_REQUEST['payment_method'] ) && $id == $_REQUEST['payment_method'] ? 'selected=selected' : '';
					?>
				<option value='<?php echo esc_attr( $id ); ?>' <?php echo esc_attr( $selected ); ?>>
					<?php echo esc_html( $form ); ?></option>
					<?php
				}
				?>
			</select>
		</div>
		<div class="payment-status" id="user-registration-pro-members-filters" style="display: flex; gap: 10px">
			<select name="status" id="user_registration_pro_users_form_filter">
				<option value=""><?php echo esc_html__( 'All Status', 'user-registration' ); ?></option>
				<?php
				foreach ( array( 'completed', 'pending', 'failed', 'refunded' ) as $id => $status ) {
					$selected = isset( $_REQUEST['status'] ) && $status == $_REQUEST['status'] ? 'selected=selected' : '';
					?>
				<option value='<?php echo esc_attr( $status ); ?>' <?php echo esc_attr( $selected ); ?>>
					<?php echo esc_html( ucfirst( $status ) ); ?></option>
					<?php
				}
				?>
			</select>
		</div>
		<div class="user-registration-users-filter-btns">
			<button type="submit" name="ur_users_filter" id="user-registration-users-filter-btn" class="button ur-button-primary">
				<?php esc_html_e( 'Filter', 'user-registration' ); ?>
			</button>
			<button type="reset"  id="user-registration-payments-filter-reset-btn" class="" title="<?php _e( 'Reset', 'user-registration' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
					<path fill="#000" fill-rule="evenodd" d="M12 2h-.004a10.75 10.75 0 0 0-7.431 3.021l-.012.012L4 5.586V3a1 1 0 1 0-2 0v5a.997.997 0 0 0 1 1h5a1 1 0 0 0 0-2H5.414l.547-.547A8.75 8.75 0 0 1 12.001 4 8 8 0 1 1 4 12a1 1 0 1 0-2 0A10 10 0 1 0 12 2Z" clip-rule="evenodd"/>
				</svg>
			</button>
		</div>
		<?php
	}

	/**
	 * @return array
	 */
	public function get_all_memberships() {
		$posts        = get_posts(
			array(
				'post_type'   => 'ur_membership',
				'numberposts' => - 1,
			)
		);
		$active_posts = array_filter(
			json_decode( json_encode( $posts ), true ),
			function ( $item ) {
				$content = json_decode( wp_unslash( $item['post_content'] ), true );

				return ! empty( $content['status'] );
			}
		);

		return wp_list_pluck( $active_posts, 'post_title', 'ID' );
	}

	public function get_all_forms() {
		$posts        = get_posts(
			array(
				'post_type'   => 'ur_membership',
				'numberposts' => - 1,
			)
		);
		$active_posts = array_filter(
			json_decode( json_encode( $posts ), true ),
			function ( $item ) {
				$content = json_decode( wp_unslash( $item['post_content'] ), true );

				return ! empty( $content['status'] );
			}
		);

		return wp_list_pluck( $active_posts, 'post_title', 'ID' );
	}

	/**
	 * Generates the table navigation above or below the table
	 *
	 * @param string $which
	 *
	 * @since 3.1.0
	 */
	protected function bulk_actions( $which = '' ) {

		if ( is_null( $this->_actions ) ) {
			$this->_actions = $this->get_bulk_actions();

			/**
			 * Filters the items in the bulk actions menu of the list table.
			 *
			 * The dynamic portion of the hook name, `$this->screen->id`, refers
			 * to the ID of the current screen.
			 *
			 * @param array $actions An array of the available bulk actions.
			 *
			 * @since 5.6.0 A bulk action can now contain an array of options in order to create an optgroup.
			 *
			 * @since 3.1.0
			 */
			$this->_actions = apply_filters( "bulk_actions-{$this->screen->id}", $this->_actions ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

			$two = '';
		} else {
			$two = '2';
		}

		if ( empty( $this->_actions ) ) {
			return;
		}
		echo '<select name="action' . esc_attr( $two ) . '" id="bulk-action-selector-' . esc_attr( $which ) . '">' . "\n";
		echo '<option value="-1">' . esc_html__( 'Bulk actions', 'user-registration' ) . "</option>\n";

		foreach ( $this->_actions as $key => $value ) {
			if ( is_array( $value ) ) {
				echo "\t" . '<optgroup label="' . esc_attr( $key ) . '">' . "\n";

				foreach ( $value as $name => $title ) {
					$class = ( 'edit' === $name ) ? ' class="hide-if-no-js"' : '';

					echo "\t\t" . '<option value="' . esc_attr( $name ) . '"' . $class . '>' . esc_html( $title ) . "</option>\n";
				}
				echo "\t</optgroup>\n";
			} else {
				$class = ( 'edit' === $key ) ? ' class="hide-if-no-js"' : '';

				echo "\t" . '<option value="' . esc_attr( $key ) . '"' . $class . '>' . esc_html( $value ) . "</option>\n";
			}
		}

		echo "</select>\n";

		submit_button( __( 'Apply', 'user-registration' ), 'action', '', false, array( 'id' => "doaction-orders$two" ) );
		echo "\n";
	}

	/**
	 * @return array
	 */
	protected function get_bulk_actions() {
		$actions = array();
		if ( is_multisite() ) {
			if ( current_user_can( 'remove_users' ) ) {
				$actions['remove'] = __( 'Remove', 'user-registration' );
			}
		} elseif ( current_user_can( 'delete_users' ) ) {
			$actions['delete'] = __( 'Delete', 'user-registration' );
		}

		return $actions;
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="order_id[]" value="%1$s" data-user-id="%2$s" /><span class="spinner"></span>', esc_attr( $item['order_id'] ?? '' ), esc_attr( $item['user_id'] ?? '' ) );
	}

	/**
	 * Displays the pagination.
	 *
	 * @since 3.1.0
	 *
	 * @param string $which The location of the pagination: Either 'top' or 'bottom'.
	 */
	protected function pagination( $which ) {
		if ( empty( $this->_pagination_args['total_items'] ) ) {
			return;
		}

		$total_items     = $this->_pagination_args['total_items'];
		$total_pages     = $this->_pagination_args['total_pages'];
		$infinite_scroll = false;
		if ( isset( $this->_pagination_args['infinite_scroll'] ) ) {
			$infinite_scroll = $this->_pagination_args['infinite_scroll'];
		}

		if ( 'top' === $which && $total_pages > 1 ) {
			$this->screen->render_screen_reader_content( 'heading_pagination' );
		}

		$current              = $this->get_pagenum();
		$removable_query_args = wp_removable_query_args();

		$current_url = set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );

		$current_url = remove_query_arg( $removable_query_args, $current_url );

		$page_links = array();

		$total_pages_before = '<span class="paging-input">';
		$total_pages_after  = '</span></span>';

		$disable_first = false;
		$disable_last  = false;
		$disable_prev  = false;
		$disable_next  = false;

		if ( 1 === $current ) {
			$disable_first = true;
			$disable_prev  = true;
		}
		if ( $total_pages === $current ) {
			$disable_last = true;
			$disable_next = true;
		}

		if ( $disable_first ) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='first-page button' href='%s'>" .
					"<span class='screen-reader-text'>%s</span>" .
					"<span aria-hidden='true'>%s</span>" .
				'</a>',
				esc_url( remove_query_arg( 'paged', $current_url ) ),
				/* translators: Hidden accessibility text. */
				__( 'First page', 'user-registration' ),
				'&laquo;'
			);
		}

		if ( $disable_prev ) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='prev-page button' href='%s'>" .
					"<span class='screen-reader-text'>%s</span>" .
					"<span aria-hidden='true'>%s</span>" .
				'</a>',
				esc_url( add_query_arg( 'paged', max( 1, $current - 1 ), $current_url ) ),
				/* translators: Hidden accessibility text. */
				__( 'Previous page', 'user-registration' ),
				'&lsaquo;'
			);
		}

		if ( 'bottom' === $which ) {
			$html_current_page  = $current;
			$total_pages_before = sprintf(
				'<span class="screen-reader-text">%s</span>' .
				'<span id="table-paging" class="paging-input">' .
				'<span class="tablenav-paging-text">',
				/* translators: Hidden accessibility text. */
				__( 'Current Page', 'user-registration' )
			);
		} else {
			$html_current_page = sprintf(
				'<label for="current-page-selector" class="screen-reader-text">%s</label>' .
				"<input class='current-page' id='current-page-selector' type='text'
					name='paged' value='%s' size='%d' aria-describedby='table-paging' />" .
				"<span class='tablenav-paging-text'>",
				/* translators: Hidden accessibility text. */
				__( 'Current Page', 'user-registration' ),
				$current,
				strlen( $total_pages )
			);
		}

		$html_total_pages = sprintf( "<span class='total-pages'>%s</span>", number_format_i18n( $total_pages ) );

		$page_links[] = $total_pages_before . sprintf(
			/* translators: 1: Current page, 2: Total pages. */
			_x( '%1$s of %2$s', 'paging', 'user-registration' ),
			$html_current_page,
			$html_total_pages
		) . $total_pages_after;

		if ( $disable_next ) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='next-page button' href='%s'>" .
					"<span class='screen-reader-text'>%s</span>" .
					"<span aria-hidden='true'>%s</span>" .
				'</a>',
				esc_url( add_query_arg( 'paged', min( $total_pages, $current + 1 ), $current_url ) ),
				/* translators: Hidden accessibility text. */
				__( 'Next page', 'user-registration' ),
				'&rsaquo;'
			);
		}

		if ( $disable_last ) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='last-page button' href='%s'>" .
					"<span class='screen-reader-text'>%s</span>" .
					"<span aria-hidden='true'>%s</span>" .
				'</a>',
				esc_url( add_query_arg( 'paged', $total_pages, $current_url ) ),
				/* translators: Hidden accessibility text. */
				__( 'Last page', 'user-registration' ),
				'&raquo;'
			);
		}

		$pagination_links_class = 'pagination-links';
		if ( ! empty( $infinite_scroll ) ) {
			$pagination_links_class .= ' hide-if-js';
		}
		$output = "\n<span class='$pagination_links_class'>" . implode( "\n", $page_links ) . '</span>';

		if ( $total_pages ) {
			$page_class = $total_pages < 2 ? ' one-page' : '';
		} else {
			$page_class = ' no-pages';
		}
		$this->_pagination = "<div class='tablenav-pages{$page_class}'>$output</div>";

		echo $this->_pagination;
	}
}
