<?php
/**
 * URMembership MembersListTable.
 *
 * @package  URMembership/MembersListTable
 * @category Class
 * @author   WPEverest
 */

namespace WPEverest\URMembership\Admin\Members;

use Exception;
use WP_Query;
use WP_User;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\MembersOrderRepository;
use WPEverest\URMembership\Admin\Repositories\MembersRepository;
use WPEverest\URMembership\TableList;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


if ( ! class_exists( 'MembersListTable' ) ) {

	_get_list_table( 'WP_Users_List_Table' );

	/**
	 * Membership table list class.
	 */
	class MembersListTable extends \WP_Users_List_Table {

		/**
		 * Prepare the users list for display.
		 *
		 * @since 4.1
		 *
		 * @global string $role
		 * @global string $usersearch
		 */
		public function prepare_items() {
			global $role, $usersearch, $wpdb;

			$usersearch = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( trim( $_REQUEST['s'] ) ) ) : ''; //phpcs:ignore

			$users_per_page = $this->get_items_per_page( 'user_registration-membership_page_user_registration_users_per_page' );

			$paged = $this->get_pagenum();

			$args = array(
				'number' => $users_per_page,
				'offset' => ( $paged - 1 ) * $users_per_page,
				'search' => $usersearch,
				'fields' => 'all_with_meta',
			);
			if ( isset( $_REQUEST['membership_id'] ) ) {
				$membership_id = sanitize_text_field( wp_unslash( $_REQUEST['membership_id'] ) );

				if ( ! empty( $membership_id ) && in_array( $membership_id, array_keys( $this->get_all_memberships() ), false ) ) {
					$subscription_table = TableList::subscriptions_table();
					$valid_users        = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT user_id FROM $subscription_table WHERE item_id = %d",
							$membership_id
						),
						ARRAY_A
					);
					$valid_users        = wp_list_pluck( $valid_users, 'user_id' );
					$args['include']    = ! empty( $valid_users ) ? $valid_users : array( 999999999 );
				}
			}

			if ( isset( $_REQUEST['orderby'] ) ) {
				$args['orderby'] = sanitize_key( wp_unslash( $_REQUEST['orderby'] ) );
			}

			if ( isset( $_REQUEST['order'] ) ) {
				$order         = strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) );
				$args['order'] = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'ASC';
			}

			/**
			 * Filters the query arguments used to retrieve users for the current users list table.
			 *
			 * @param array $args Arguments passed to WP_User_Query to retrieve items for the current
			 *                    users list table.
			 *
			 * @since 4.1
			 */
			$args               = apply_filters( 'ur_pro_users_list_table_query_args', $args );
			$members_repository = new MembersRepository();

			$this->items = $members_repository->get_all_members( $args );

			$this->set_pagination_args(
				array(
					'total_items' => count( $this->items ),
					'per_page'    => $users_per_page,
				)
			);
		}

		/**
		 * No items found text.
		 */
		public function no_items() {
			$image_url = esc_url( plugin_dir_url( UR_PLUGIN_FILE ) . 'assets/images/empty-table.png' );
			?>
			<div class="empty-list-table-container">
				<img src="<?php echo $image_url; ?>" alt="">
				<h3><?php echo __( 'You don\'t have any Members yet.', 'user-registration' ); ?></h3>
				<p><?php echo __( 'Please add Members and you are good to go.', 'user-registration' ); ?></p>
			</div>
			<?php
		}

		/**
		 * Handle what to show on each row for the list-table.
		 *
		 * @param object $user_object user object.
		 * @param mixed  $style style params.
		 * @param mixed  $role role params.
		 * @param mixed  $numposts num-posts.
		 *
		 * @return string
		 * @throws Exception exception type.
		 */
		public function single_row( $user_object, $style = '', $role = '', $numposts = 0 ) {
			$user_id = $user_object['ID'];

			// Check if the user for this row is editable.
			if ( current_user_can( 'list_users' ) ) {
				// Set up the user editing link.
				$edit_link = add_query_arg(
					array(
						'action'    => 'edit',
						'member_id' => $user_id,
						'_wpnonce'  => wp_create_nonce( 'bulk-users' ),
					),
					admin_url( 'admin.php?page=user-registration-members' ),
				);

				$member_view_url = add_query_arg(
					array(
						'action'   => 'view',
						'user_id'  => $user_id,
						'_wpnonce' => wp_create_nonce( 'bulk-users' ),
					),
					admin_url( 'admin.php?page=user-registration-users&view_user' ),
				);

				// Add a link to the user's author archive, if not empty.
				$actions['view'] = sprintf(
					'<a href="%s" target="_blank">%s</a>',
					esc_url( $member_view_url ),
					__( 'View', 'user-registration' )
				);

				if ( current_user_can( 'edit_user', $user_id ) ) {
					$actions['edit'] = '<a href="' . $edit_link . '" target="_blank">' . __( 'Edit', 'user-registration' ) . '</a>';
				}

				/**
				 * Filters the action links displayed under each user in the Users list table.
				 *
				 * @param string[] $actions An array of action links to be displayed.
				 *                              Default 'Edit', 'Delete' for single site, and
				 *                              'Edit', 'Remove' for Multisite.
				 * @param WP_User $user_object WP_User object for the currently listed user.
				 *
				 * @since 4.1
				 */
				$actions = apply_filters( 'ur_pro_user_row_actions', $actions, $user_object );

				// Role classes.

				// Set up the checkbox (because the user is editable, otherwise it's empty).
				$checkbox = sprintf(
					'<label class="screen-reader-text" for="user_%1$s">%2$s</label>' .
					'<input type="checkbox" name="users[]" id="user_%1$s" class="" value="%1$s" />',
					$user_id,
					/* translators: Hidden accessibility text. %s: User login. */
					sprintf( __( 'Select %s', 'user-registration' ), $user_object['user_login'] )
				);

			}
			$avatar = get_avatar( $user_id, 32 );
			$email  = $user_object['user_email'];

			$row = "<tr id='user-$user_id'>";
			list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();

			foreach ( $columns as $column_name => $column_display_name ) {

				$classes = "$column_name column-$column_name";

				if ( $primary === $column_name ) {
					$classes .= ' has-row-actions column-primary';
				}

				if ( in_array( $column_name, $hidden, true ) ) {
					$classes .= ' hidden';
				}
				$data = 'data-colname="' . esc_attr( wp_strip_all_tags( $column_display_name ) ) . '"';

				$attributes = "class='$classes' $data";

				if ( 'cb' === $column_name ) {
					$row .= "<th scope='row' class='check-column'>$checkbox</th>";
				} else {
					$row .= "<td $attributes>";
					switch ( $column_name ) {
						case 'username':
							$row .= "$avatar " . '<p>' . $user_object['user_login'] . '</p>';
							break;
						case 'email':
							$row .= "<a href='" . esc_url( "mailto:$email" ) . "'>" . esc_html( $email ) . '</a>';
							break;
						case 'membership':
							$row .= $user_object['subscriptions'] ?? '';
							break;
						case 'subscription_status':
							$status       = $user_object['status'] ?? '';
							$status_class = 'user-registration-badge user-registration-badge--secondary-subtle';
							if ( $status == 'active' ) {
								$status_class = 'user-registration-badge user-registration-badge--success-subtle';
							} elseif ( $status == 'pending' ) {
								$status_class = 'user-registration-badge user-registration-badge--warning';
							}

							$pending_cancel_date = get_user_meta( $user_id, 'urm_pending_cancel_' . ( $user_object['subscription_id'] ?? '' ), true );

							if ( $pending_cancel_date ) {
								$clock_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
								$row .= sprintf(
									'<span class="user-registration-badge user-registration-badge--danger-subtle urm-canceling-badge">%s%s</span>',
									$clock_icon,
									sprintf(
										/* translators: %s: cancellation date */
										esc_html__( 'Cancels %s', 'user-registration' ),
										esc_html( date_i18n( get_option( 'date_format' ), strtotime( $pending_cancel_date ) ) )
									)
								);
							} else {
								$row .= sprintf( '<span id="" class="user-registration-badge %s">%s</span>', $status_class, ucfirst( $status ) );
							}
							break;
						case 'user_registered':
							$row .= date_i18n( 'F j, Y h:i A', strtotime( $user_object['user_registered'] ) );

							break;
						case 'actions':
							$row .= $this->row_actions( $actions, true );
							break;
						default:
							/**
							 * Filters the display output of custom columns in the Users list table.
							 *
							 * @param string $output Custom column output. Default empty.
							 * @param string $column_name Column name.
							 * @param int $user_id ID of the currently-listed user.
							 *
							 * @since 4.1
							 */
							$row .= apply_filters( 'ur_pro_manage_members_custom_column', '', $column_name, $user_id );
					}
					$row .= '</td>';
				}
			}
			$row .= '</tr>';

			return $row;
		}

		/**
		 * Displays the search box.
		 *
		 * @since 4.1
		 */
		public function display_search_box() {

			$input_id = 'user-registration-users-search-input';

			if ( ! empty( $_REQUEST['orderby'] ) ) {
				echo '<input type="hidden" name="orderby" value="' . esc_attr( wp_unslash( $_REQUEST['orderby'] ) ) . '" />';
			}
			if ( ! empty( $_REQUEST['order'] ) ) {
				echo '<input type="hidden" name="order" value="' . esc_attr( wp_unslash( $_REQUEST['order'] ) ) . '" />';
			}
			?>

			<div style="position: relative">
				<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s"
						value="<?php _admin_search_query(); ?>"
						placeholder="<?php esc_html_e( 'Search Members ...', 'user-registration' ); ?>"/>
				<?php wp_nonce_field( 'user-registration-pro-filter-members' ); ?>
				<button type="submit" id="search-submit">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
						<path fill="#000" fill-rule="evenodd"
								d="M4 11a7 7 0 1 1 12.042 4.856 1.012 1.012 0 0 0-.186.186A7 7 0 0 1 4 11Zm12.618 7.032a9 9 0 1 1 1.414-1.414l3.675 3.675a1 1 0 0 1-1.414 1.414l-3.675-3.675Z"
								clip-rule="evenodd"></path>
					</svg>
				</button>
			</div>

			<div class="" id="user-registration-pro-members-filters" style="display: flex; gap: 10px">
				<select name="membership_id" id="user_registration_pro_users_form_filter" class="ur-enhanced-select">
					<option
						value=""><?php echo esc_html__( 'All Membership', 'user-registration' ); ?></option>
					<?php
					foreach ( $this->get_all_memberships() as $id => $form ) {
						$selected = isset( $_REQUEST['membership_id'] ) && $id == $_REQUEST['membership_id'] ? 'selected=selected' : '';
						?>

						<option
							value='<?php echo esc_attr( $id ); ?>' <?php echo esc_attr( $selected ); ?>><?php echo esc_html( $form ); ?></option>
						<?php
					}
					?>
				</select>
			</div>
			<button type="submit" id="user-registration-users-filter-btn"
					class="button ur-button-primary">
				<?php esc_html_e( 'Filter', 'user-registration' ); ?>
			</button>
			<?php
		}

		/**
		 * Returns a list of all registration forms.
		 *
		 * @return array
		 * @since 4.1
		 */
		protected function get_all_membership_types() {
			$forms = array(
				'0' => __( 'All Plans', 'user-registration' ),
				'1' => __( 'Free', 'user-registration' ),
				'2' => __( 'Paid', 'user-registration' ),
				'3' => __( 'Subscription', 'user-registration' ),
			);

			return $forms;
		}

		/**
		 * Returns a list of sortable columns.
		 *
		 * @return array
		 * @since 4.1
		 */
		protected function get_sortable_columns() {
			return apply_filters(
				'user_registration_pro_users_table_sortable_columns',
				array(
					'username'            => 'user_login',
					'email'               => 'user_email',
					'user_registered'     => 'user_registered',
					'membership'          => 'post_title',
					'subscription_status' => 'status',
				)
			);
		}

		/**
		 * Displays the table.
		 *
		 * @since 4.1
		 */
		public function display() {
			$this->display_tablenav( 'top' );
			$this->screen->render_screen_reader_content( 'heading_list' );
			?>
			<table class="wp-list-table <?php echo implode( ' ', $this->get_table_classes() ); ?>">
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
		 * @param string $which top or bottom nav.
		 *
		 * @since 4.1
		 */
		protected function display_tablenav( $which ) {
			if ( 'top' === $which ) {
				wp_nonce_field( 'bulk-' . $this->_args['plural'] );
			}
			?>
			<div class="tablenav <?php echo esc_attr( $which ); ?>">
				<?php if ( $this->has_items() && 'top' === $which ) : ?>
					<div class="alignleft actions bulkactions">
						<?php
						$this->bulk_actions( $which );
						?>
					</div>
					<?php
					$this->pagination( $which );
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
				<br class="clear"/>
			</div>
			<?php
		}

		/**
		 * Output footer text.
		 *
		 * @return void
		 */
		protected function footer_text() {
			$total_items    = $this->_pagination_args['total_items'];
			$current        = $this->get_pagenum();
			$users_per_page = $this->_pagination_args['per_page'];

			echo esc_html(
				sprintf(
					'Showing results %d-%d of %d users',
					( ( $current - 1 ) * $users_per_page ) + 1,
					min( ( $current ) * $users_per_page, $total_items ),
					$total_items
				)
			);
		}

		/**
		 * get_bulk_actions
		 *
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

		/**
		 * get_roles
		 *
		 * @return string[]
		 */
		public function get_roles() {
			$roles = wp_roles()->role_names;

			return $roles;
		}

		/**
		 * get_all_memberships
		 *
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
				json_decode( wp_json_encode( $posts ), true ),
				function ( $item ) {
					$content = json_decode( wp_unslash( $item['post_content'] ), true );

					return $content['status'];
				}
			);

			return wp_list_pluck( $active_posts, 'post_title', 'ID' );
		}

		/**
		 * Get user membership by user id.
		 *
		 * @param $userId
		 *
		 * @return array|object|\stdClass|void|null
		 */
		public function get_user_membership( $userId ) {
			$members_repository = new MembersRepository();

			return $members_repository->get_member_membership_by_id( $userId );
		}
	}
}
