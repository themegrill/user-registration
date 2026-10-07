<?php

namespace WPEverest\URMembership\Admin\Subscriptions;

use UR_Base_Layout;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;

if ( ! class_exists( 'UR_List_Table' ) ) {
	include_once dirname( UR_PLUGIN_FILE ) . '/includes/abstracts/abstract-ur-list-table.php';
}

if ( ! class_exists( 'UR_Base_Layout' ) ) {
	include_once dirname( UR_PLUGIN_FILE ) . '/includes/admin/class-ur-admin-base-layout.php';
}

class ListTable extends \UR_List_Table {

	public function __construct() {
		$this->post_type       = 'ur_membership';
		$this->page            = 'user-registration-subscriptions';
		$this->per_page_option = 'user_registration_subscriptions_per_page';
		$this->addnew_action   = 'add_new_subscription';
		$this->sort_by         = array(
			'subscription_id'   => array( 'subscription_id', false ),
			'user_id'           => array( 'user_id', false ),
			'item_id'           => array( 'item_id', false ),
			'start_date'        => array( 'start_date', true ),
			'next_billing_date' => array( 'next_billing_date', false ),
			'status'            => array( 'status', false ),
		);

		parent::__construct(
			array(
				'singular' => 'subscription',
				'plural'   => 'subscriptions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * No items found text.
	 */
	public function no_items() {
		UR_Base_Layout::no_items( 'Subscriptions' );
	}

	public function get_duplicate_link( $subscription ) {
		return '';
	}

	public function get_edit_links( $subscription ) {
		return admin_url( "admin.php?page={$this->page}&action=edit&id={$subscription->ID}" );
	}

	public function get_columns() {
		return apply_filters(
			'ur_membership_subscriptions_list_table_columns',
			array(
				'cb'         => '<input type="checkbox" />',
				'id'         => __( 'Subscription ID', 'user-registration' ),
				'user_id'    => __( 'Member', 'user-registration' ),
				'item_id'    => __( 'Membership', 'user-registration' ),
				'start_date' => __( 'Created At', 'user-registration' ),
				'status'     => __( 'Status', 'user-registration' ),
			)
		);
	}

	public function get_hidden_columns() {
		return array();
	}

	public function column_id( $subscription ) {
		$edit_link = $this->get_edit_links( $subscription );
		foreach ( $this->get_row_actions( $subscription ) as $action => $link ) {
			$row_actions[] = sprintf( '<span class="%s">%s</span>', esc_attr( $action ), $link );
		}
		return sprintf(
			'<strong><div class="ur-edit-title"><a href="%s" class="row-title">#%s</a></div></strong><div class="row-actions">%s</div>',
			esc_url( $edit_link ),
			esc_html( $subscription->ID ),
			implode( ' | ', $row_actions )
		);
	}

	public function column_user_id( $subscription ) {
		$user              = get_user( $subscription->user_id );
		$first_name        = get_user_meta( $subscription->user_id, 'first_name', true );
		$last_name         = get_user_meta( $subscription->user_id, 'last_name', true );
		$user_display_name = $user->user_login;
		if ( $first_name || $last_name ) {
			$user_display_name = trim( $first_name . ' ' . $last_name );
		} elseif ( ! empty( $user->display_name ) ) {
			$user_display_name = $user->display_name;
		} elseif ( ! empty( $user->nickname ) ) {
			$user_display_name = $user->nickname;
		}
		$member_edit_url = add_query_arg(
			array(
				'action'   => 'view',
				'user_id'  => $subscription->user_id,
				'_wpnonce' => wp_create_nonce( 'bulk-users' ),
			),
			admin_url( 'admin.php?page=user-registration-users&view_user' ),
		);
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $member_edit_url ),
			esc_html( $user_display_name )
		);
	}

	public function column_item_id( $subscription ) {
		$membership = ( new MembershipRepository() )->get_single_membership_by_ID( $subscription->item_id );
		if ( ! $membership ) {
			return '-';
		}
		return sprintf(
			'<a href="%s">%s</a>',
			admin_url( "admin.php?post_id={$subscription->item_id}&action=add_new_membership&page=user-registration-membership" ),
			$membership['post_title']
		);
	}

	public function column_start_date( $subscription ) {
		return date_i18n( get_option( 'date_format' ), strtotime( $subscription->start_date ) );
	}

	public function column_next_billing_date( $subscription ) {
		return date_i18n( get_option( 'date_format' ), strtotime( $subscription->next_billing_date ) );
	}

	public function column_status( $subscription ) {
		$pending_cancel_date = get_user_meta( $subscription->user_id, 'urm_pending_cancel_' . $subscription->ID, true );

		if ( $pending_cancel_date ) {
			$clock_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
			return sprintf(
				'<span class="subscription-status user-subscription-canceling">%s%s</span>',
				$clock_icon,
				esc_html(
					sprintf(
						/* translators: %s: cancellation date */
						__( 'Cancels %s', 'user-registration' ),
						date_i18n( get_option( 'date_format' ), strtotime( $pending_cancel_date ) )
					)
				)
			);
		}

		$status_class = 'user-subscription-secondary';
		if ( 'active' === $subscription->status ) {
			$status_class = 'user-subscription-active';
		} elseif ( 'pending' === $subscription->status ) {
			$status_class = 'user-subscription-pending';
		} elseif ( 'expired' === $subscription->status || 'canceled' === $subscription->status ) {
			$status_class = 'user-subscription-expired';
		}

		return sprintf(
			'<span class="subscription-status %s">%s</span>',
			$status_class,
			ucfirst( $subscription->status )
		);
	}

	public function prepare_items() {
		$this->prepare_column_headers();

		$per_page     = $this->get_items_per_page( $this->get_items_per_page_key(), 20 );
		$current_page = $this->get_pagenum();
		$orderby      = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'ID';
		$order        = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'DESC';

		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$membership = isset( $_GET['membership'] ) ? absint( wp_unslash( $_GET['membership'] ) ) : 0;
		$status     = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

		$args = array(
			'page'     => $current_page,
			'per_page' => $per_page,
			'orderby'  => $orderby,
			'order'    => $order,
			'search'   => $search,
			'item_id'  => $membership,
			'status'   => $status,
		);

		$subscription_repository = new SubscriptionRepository();
		$result                  = $subscription_repository->query( $args );

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $result['per_page'],
				'total_pages' => $result['total_pages'],
			)
		);
	}

	/**
	 * Render the list table page, including header, notices, status filters and table.
	 */
	public function display_page() {
		UR_Base_Layout::render_layout(
			$this,
			array(
				'page'           => $this->page,
				'title'          => esc_html__( 'Subscriptions', 'user-registration' ),
				'add_new_action' => 'create',
				'search_id'      => 'subscriptions-list-search-input',
				'form_id'        => 'user-registration-list-search-form',
			)
		);
	}

	/**
	 * Display Search Box
	 *
	 * @param $search_id
	 *
	 * @return void
	 */
	public function display_search_box( $search_id ) {
		?>
			<p class="search-box">
			</p>
			<div id="user-registration-list-search-form">
				<?php
				$placeholder = __( 'Search member or email', 'user-registration' );
				UR_Base_Layout::display_search_field( $search_id, $placeholder );
				?>
				</div>
			<p></p>
		<?php
	}

	public function get_row_actions( $row ) {
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=user-registration-subscriptions&action=delete&id=' . $row->ID ),
			'ur_subscription_delete'
		);
		return array(
			'id'     => sprintf(
				/* translators: %d: Item id */
				esc_html__( 'ID: %d', 'user-registration' ),
				$row->ID
			),
			'edit'   => '<a href="' . esc_url( $this->get_edit_links( $row ) ) . '">' . __( 'Edit', 'user-registration' ) . '</a>',
			'delete' => '<a class="submitdelete" aria-label="' . esc_attr__( 'Delete this item permanently', 'user-registration' ) . '" href="' . $delete_url . '">' . esc_html__( 'Delete', 'user-registration' ) . '</a>',
		);
	}

	protected function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'user-registration' ),
		);
	}


	/**
	 * Displays the table.
	 *
	 * @since 3.1.0
	 */
	public function display() {
		$singular = $this->_args['singular'];

		$this->display_tablenav( 'top' );

		$this->screen->render_screen_reader_content( 'heading_list' );
		?>
		<table class="wp-list-table <?php echo implode( ' ', $this->get_table_classes() ); ?>">
				<?php $this->print_table_description(); ?>
			<thead>
			<tr>
				<?php $this->print_column_headers(); ?>
			</tr>
			</thead>

			<tbody id="the-list"
				<?php
				if ( $singular ) {
					echo " data-wp-lists='list:$singular'";
				}
				?>
				>
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
