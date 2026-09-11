<?php
/**
 * UserRegistration Members ListTable class.
 *
 * @package  UserRegistration/Admin
 * @author   WPEverest
 *
 * @since 4.5.0
 */

use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URTeamMembership\Admin\TeamRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'User_Registration_Members_ListTable' ) ) {

	_get_list_table( 'WP_Users_List_Table' );

	/**
	 * User_Registration_Pro_Members_List_Table class.
	 */
	class User_Registration_Members_List_Table extends WP_Users_List_Table {


		public function __construct() {
			parent::__construct();

			add_filter( 'ur_manage_users_custom_column', array( $this, 'output_custom_column_data' ), 10, 3 );
			add_action( 'pre_user_query', array( $this, 'urm_search_user_on_name' ) );
		}

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

			$usersearch = isset( $_REQUEST['s'] ) ? wp_unslash( trim( $_REQUEST['s'] ) ) : '';

			$role = isset( $_REQUEST['role'] ) ? sanitize_text_field( $_REQUEST['role'] ) : '';

			$users_per_page = $this->get_items_per_page( 'user_registration-membership_page_user_registration_users_per_page' );

			$paged = $this->get_pagenum();

			$args = array(
				'number' => $users_per_page,
				'offset' => ( $paged - 1 ) * $users_per_page,
				'search' => $usersearch ? '*' . $usersearch . '*' : '',
				'fields' => 'all_with_meta',
			);

			if ( $role !== 'all' ) {
				$args['role'] = $role;
			}

			if ( ! empty( $_REQUEST['form_filter'] ) ) {
				$form_filter = sanitize_text_field( wp_unslash( $_REQUEST['form_filter'] ) );

				if ( array_key_exists( $form_filter, $this->get_all_registration_forms() ) ) {
					$args['meta_query'] = array(
						array(
							'key'     => 'ur_form_id',
							'value'   => (string) $form_filter,
							'compare' => '=',
						),
					);
				}
			}

			if ( ! empty( $_REQUEST['user_status'] ) ) {
				$status_filter = sanitize_text_field( wp_unslash( $_REQUEST['user_status'] ) );

				if ( in_array( $status_filter, array( 'approved', 'pending', 'denied', 'pending_email' ) ) ) {
					$args['meta_query'][] = $this->get_user_meta_query_by_user_status( $status_filter );
				}
			}

			// Date Range Filter.

			$start_date = gmdate( 'Y-m-d', strtotime( '-5 years' ) );
			$end_date   = gmdate( 'Y-m-d' );

			if ( ! empty( $_REQUEST['date_range'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification
				$date_range = sanitize_text_field( wp_unslash( $_REQUEST['date_range'] ) );

				switch ( $date_range ) {
					case 'day':
						$start_date = strtotime( date( 'm/d/Y' ) . '00:00:00' );
						$start_date = date( 'Y-m-d H:i:s', $start_date );
						break;

					case 'week':
						$start_date = current_time( 'timestamp' ) - WEEK_IN_SECONDS;
						$start_date = date( 'Y-m-d H:i:s', $start_date );
						break;

					case 'month':
						$start_date = current_time( 'timestamp' ) - MONTH_IN_SECONDS;
						$start_date = date( 'Y-m-d H:i:s', $start_date );
						break;

					case 'year':
						break;

					case 'custom':
						if ( ! empty( $_REQUEST['start_date'] ) && strtotime( $_REQUEST['start_date'] ) ) {
							$start_date = sanitize_text_field( wp_unslash( $_REQUEST['start_date'] ) );
						}

						if ( ! empty( $_REQUEST['end_date'] ) && strtotime( $_REQUEST['end_date'] ) ) {
							$end_date = sanitize_text_field( wp_unslash( $_REQUEST['end_date'] ) );
						}
						break;
				}
			}

			if ( ! empty( $start_date ) && ! empty( $end_date ) && empty( $usersearch ) ) {
				$args['date_query'] = array(
					array(
						'after'     => $start_date,
						'before'    => $end_date,
						'inclusive' => true,
					),
				);
			}

			// Date Range Filter End.

			if ( ! empty( $args['meta_query'] ) && 1 < count( $args['meta_query'] ) ) {
				$args['meta_query']['relation'] = 'AND';
			}

			if ( $this->is_site_users ) {
				$args['blog_id'] = $this->site_id;
			}

			$args['orderby'] = 'user_registered';
			$args['order']   = 'desc';

			$allowed_orderby = array( 'user_registered', 'user_login', 'user_email' );

			if ( isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], $allowed_orderby, true ) ) {
				$args['orderby'] = $_REQUEST['orderby'];
			}

			if ( isset( $_REQUEST['order'] ) && in_array( strtoupper( $_REQUEST['order'] ), array( 'ASC', 'DESC' ), true ) ) {
				$args['order'] = strtoupper( $_REQUEST['order'] );
			}

			$subscription_table = $wpdb->prefix . 'ur_membership_subscriptions';

			if ( ! empty( $_REQUEST['membership_id'] ) ) {
				$membership_id   = intval( $_REQUEST['membership_id'] );
				$all_memberships = $this->get_all_memberships();

				if ( array_key_exists( $membership_id, $all_memberships ) ) {
					$valid_users     = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT user_id FROM $subscription_table WHERE item_id = %d",
							$membership_id
						)
					);
					$args['include'] = ! empty( $valid_users ) ? $valid_users : array( 999999999 );
				}
			}

			/**
			 * Filters the query arguments used to retrieve users for the current users list table.
			 *
			 * @since 4.1
			 *
			 * @param array $args Arguments passed to WP_User_Query to retrieve items for the current
			 *                    users list table.
			 */
			$args = apply_filters( 'ur_pro_users_list_table_query_args', $args );

			// Query the user IDs for this page.
			$wp_user_search = new WP_User_Query( $args );

			$this->items = $wp_user_search->get_results();

			$user_ids = wp_list_pluck( $this->items, 'ID' );

			if ( empty( $user_ids ) ) {
				$this->items = array();
				$this->set_pagination_args(
					array(
						'total_items' => 0,
						'per_page'    => $users_per_page,
					)
				);
				return;
			}

			$results = array();

			foreach ( $this->items as $user ) {
				$results[] = $user->to_array();
			}

			$total_users = $wp_user_search->total_users;

			if ( ur_check_module_activation( 'membership' ) ) {
				$user_ids_in    = implode( ',', array_map( 'intval', $user_ids ) );
				$orders_table   = $wpdb->prefix . 'ur_membership_orders';
				$posts_table    = $wpdb->posts;
				$usermeta_table = $wpdb->usermeta;

				$sql = "
					SELECT wpu.ID,
						wums.ID AS subscription_id,
						wpp.post_title AS membership_title,
						wpu.user_login,
						wpu.user_email,
						wums.status,
						wums.billing_cycle,
						wpu.user_registered,
						wums.expiry_date,
						wumo_latest.payment_method,
						wum_team.meta_value AS team_ids
					FROM {$wpdb->users} wpu
					LEFT JOIN {$subscription_table} wums
						ON wpu.ID = wums.user_id
					LEFT JOIN {$orders_table} wumo_latest
						ON wumo_latest.ID = (
							SELECT ID
							FROM {$orders_table} sub
							WHERE sub.user_id = wpu.ID
							ORDER BY sub.created_at DESC
							LIMIT 1
						)
					LEFT JOIN {$posts_table} wpp ON wums.item_id = wpp.ID  AND wpp.post_status = 'publish'
					LEFT JOIN {$usermeta_table} wum_team
						ON wpu.ID = wum_team.user_id
						AND wum_team.meta_key = 'urm_team_ids'
					WHERE wpu.ID IN ($user_ids_in)
					ORDER BY FIELD(wpu.ID, $user_ids_in)
				";

				$results = $wpdb->get_results( $sql, ARRAY_A );
			}

			$user_id_indexed = array();

			foreach ( $results as $row ) {
				$user_id = $row['ID'];

				if ( ! isset( $user_id_indexed[ $user_id ] ) ) {
					$user_id_indexed[ $user_id ] = array(
						'ID'              => $user_id,
						'user_login'      => $row['user_login'],
						'user_email'      => $row['user_email'],
						'user_registered' => $row['user_registered'],
						'payment_method'  => $row['payment_method'] ?? '',
						'subscriptions'   => array(),
					);
				}

				if ( ! empty( $row['subscription_id'] ) ) {
					$user_id_indexed[ $user_id ]['subscriptions'][] = array(
						'subscription_id'  => $row['subscription_id'],
						'membership_title' => $row['membership_title'],
						'status'           => $row['status'],
						'expiry_date'      => $row['expiry_date'],
						'billing_cycle'    => $row['billing_cycle'],
					);
				}

				if ( ! empty( $row['team_ids'] ) && UR_PRO_ACTIVE && ur_check_module_activation( 'team' ) ) {
					$row['team_ids'] = maybe_unserialize( $row['team_ids'] );
					$team_name       = '';
					$subscription_id = '';
					$team_repository = new TeamRepository();
					foreach ( $row['team_ids'] as $team_id ) {
						$team = $team_repository->get_single_team_by_ID( $team_id );
						if ( $team ) {
							$team_name                              = $team['team_name'] ?? '';
							$subscription_id                        = $team['meta']['urm_subscription_id'];
							$user_id_indexed[ $user_id ]['teams'][] = $team_name;

							if ( $user_id !== $team['meta']['urm_team_leader_id'] ) {
								if ( $subscription_id ) {
									$subscription_repository = new MembersSubscriptionRepository();
									$subscription            = $subscription_repository->get_subscription_by_subscription_id( $subscription_id );
									if ( $subscription ) {
										$membership       = $subscription_repository->get_membership_by_subscription_id( $subscription_id );
										$membership_title = '';
										if ( $membership ) {
											$membership_title = $membership['post_title'];
										}
										$status        = $subscription['status'];
										$expiry_date   = $subscription['expiry_date'];
										$billing_cycle = $subscription['billing_cycle'];

										$user_id_indexed[ $user_id ]['subscriptions'][] = array(
											'subscription_id' => $subscription_id,
											'membership_title' => $membership_title,
											'status'      => $status,
											'expiry_date' => $expiry_date,
											'billing_cycle' => $billing_cycle,
										);
									}
								}
							}
						}
					}
				}
			}

			$this->items = $user_id_indexed;

			$this->set_pagination_args(
				array(
					'total_items' => $total_users,
					'per_page'    => $users_per_page,
				)
			);
		}

		/**
		 * No items found text.
		 */
		public function no_items() {
			UR_Base_Layout::no_items( 'Members' );
		}
		/**
		 * Returns a combined meta query array for different user statuses.
		 *
		 * @since 4.1.3
		 *
		 * @param string $userStatus The user status ('approved', 'pending', 'denied', 'pending_email').
		 *
		 * @return array
		 */
		private function get_user_meta_query_by_user_status( $user_status ) {
			switch ( $user_status ) {
				case 'approved':
					return array(
						'relation' => 'OR',
						array(
							'relation' => 'AND',
							array(
								'key'     => 'ur_user_status',
								'compare' => 'NOT EXISTS',
								'value'   => '',
							),
							array(
								'key'     => 'ur_confirm_email',
								'compare' => 'NOT EXISTS',
								'value'   => '',
							),
						),
						array(
							'relation' => 'AND',
							array(
								'key'   => 'ur_user_status',
								'value' => '1',
							),
							array(
								'key'     => 'ur_admin_approval_after_email_confirmation',
								'compare' => 'NOT EXISTS',
								'value'   => '',
							),
						),
						array(
							'relation' => 'AND',
							array(
								'key'   => 'ur_user_status',
								'value' => '1',
							),
							array(
								'key'   => 'ur_admin_approval_after_email_confirmation',
								'value' => true,
							),
						),
					);
				case 'pending':
					return array(
						'relation' => 'AND',
						array(
							'key'     => 'ur_user_status',
							'value'   => '0',
							'compare' => '=',
						),
						array(
							'relation' => 'AND',
							array(
								'relation' => 'OR',
								array(
									'key'     => 'ur_confirm_email',
									'value'   => '0',
									'compare' => '!=',
								),
								array(
									'key'     => 'ur_confirm_email',
									'compare' => 'NOT EXISTS',
								),
							),
							array(
								'relation' => 'OR',
								array(
									'key'     => 'ur_admin_approval_after_email_confirmation',
									'value'   => 'false',
									'compare' => '=',
								),
								array(
									'key'     => 'ur_admin_approval_after_email_confirmation',
									'compare' => 'NOT EXISTS',
								),
							),
						),
					);
				case 'denied':
					return array(
						'relation' => 'OR',
						array(
							'key'     => 'ur_user_status',
							'value'   => '-1',
							'compare' => '=',
						),
						array(
							'key'     => 'ur_confirm_email',
							'value'   => '-1',
							'compare' => '=',
						),
					);
				case 'pending_email':
					return array(
						'relation' => 'AND',
						array(
							'relation' => 'OR',
							array(
								'key'     => 'ur_user_status',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => 'ur_user_status',
								'value'   => '0',
								'compare' => '=',
							),
						),
						array(
							'key'     => 'ur_confirm_email',
							'value'   => '0',
							'compare' => '=',
						),
					);
				default:
					return array(); // Default to an empty array or handle as needed.
			}
		}

		/**
		 * Output the controls to allow user roles to be changed in bulk.
		 *
		 * @since 4.1
		 *
		 * @param string $which Whether this is being invoked above ("top")
		 *                      or below the table ("bottom").
		 */
		protected function extra_tablenav( $which ) {
			$id        = 'bottom' === $which ? 'new_role2' : 'new_role';
			$button_id = 'bottom' === $which ? 'changeit2' : 'changeit';
			?>
			<div class="alignleft actions">
			<?php if ( current_user_can( 'promote_users' ) && $this->has_items() ) : ?>
			<label class="screen-reader-text" for="<?php echo $id; ?>">
				<?php
				/* translators: Hidden accessibility text. */
				_e( 'Change role to&hellip;', 'user-registration' );
				?>
			</label>
			<select name="<?php echo $id; ?>" id="<?php echo $id; ?>">
				<option value=""><?php _e( 'Change role to&hellip;', 'user-registration' ); ?></option>
				<?php wp_dropdown_roles(); ?>
				<option value="none"><?php _e( '&mdash; No role for this site &mdash;', 'user-registration' ); ?></option>
			</select>
				<?php
				submit_button( __( 'Change', 'user-registration' ), '', $button_id, false );
			endif;

			/**
			 * Fires just before the closing div containing the bulk role-change controls
			 * in the Users list table.
			 *
			 * @since 4.1
			 *
			 * @param string $which The location of the extra table nav markup: 'top' or 'bottom'.
			 */
			do_action( 'ur_pro_restrict_manage_users', $which );
			?>
			</div>
			<?php
			/**
			 * Fires immediately following the closing "actions" div in the tablenav for the users
			 * list table.
			 *
			 * @since 4.1
			 *
			 * @param string $which The location of the extra table nav markup: 'top' or 'bottom'.
			 */
			do_action( 'ur_pro_manage_users_extra_tablenav', $which );
		}

		/**
		 * Generate HTML for a single row on the users.php admin panel.
		 *
		 * @since 4.1
		 *
		 * @param WP_User $user_object The current user object.
		 * @param string  $style       Deprecated. Not used.
		 * @param string  $role        Deprecated. Not used.
		 * @param int     $numposts    Optional. Post count to display for this user. Defaults
		 *                             to zero, as in, a new user has made zero posts.
		 * @return string Output for a single row.
		 */
		public function single_row( $user_object, $style = '', $role = '', $numposts = 0 ) {
			if ( ! ( $user_object instanceof WP_User ) ) {
				$user_id         = $user_object['ID'];
				$new_user_object = get_userdata( (int) $user_id );
			}
			$new_user_object->filter        = 'display';
			$email                          = $new_user_object->user_email;
			$new_user_object->subscriptions = $user_object['subscriptions'] ?? '';
			$new_user_object->teams         = $user_object['teams'] ?? '';

			$user_manager = new UR_Admin_User_Manager( $new_user_object );

			if ( $this->is_site_users ) {
				$url = "site-users.php?id={$this->site_id}&amp;";
			} else {
				$url = 'users.php?';
			}

			$user_roles = $this->get_role_list( $new_user_object );

			// Set up the hover actions for this user.
			$actions     = array();
			$checkbox    = '';
			$super_admin = '';

			if ( is_multisite() && current_user_can( 'manage_network_users' ) ) {
				if ( in_array( $new_user_object->user_login, get_super_admins(), true ) ) {
					$super_admin = ' &mdash; ' . __( 'Super Admin', 'user-registration' );
				}
			}

			// Check if the user for this row is editable.
			if ( current_user_can( 'list_users' ) || current_user_can( 'manage_user_registration' ) ) {
				$actions['id'] = "ID: $new_user_object->ID";

				// Set up the user editing link.
				$edit_link = add_query_arg(
					array(
						'action'   => 'edit',
						'user_id'  => $new_user_object->ID,
						'_wpnonce' => wp_create_nonce( 'bulk-users' ),
					),
					admin_url( 'admin.php?page=user-registration-users&view_user' ),
				);

				// Add a link to the user's author archive, if not empty.

				$view_link = add_query_arg(
					array(
						'action'   => 'view',
						'user_id'  => $new_user_object->ID,
						'_wpnonce' => wp_create_nonce( 'bulk-users' ),
					),
					admin_url( 'admin.php?page=user-registration-users&view_user' ),
				);

				$delete_link = add_query_arg(
					array(
						'action'   => 'delete',
						'user_id'  => $new_user_object->ID,
						'_wpnonce' => wp_create_nonce( 'bulk-users' ),
					),
					admin_url( 'admin.php?page=user-registration-users' ),
				);

				$wp_delete_url = add_query_arg(
					array(
						'user'     => $new_user_object->ID,
						'_wpnonce' => wp_create_nonce( 'bulk_users' ),
					),
					admin_url( 'users.php?action=delete' )
				);

				if ( current_user_can( 'edit_user', $new_user_object->ID ) ) {
					$actions['edit'] = '<a href="' . $edit_link . '" rel="noreferrer noopener" target="_blank" class="ur-row-actions">' . __( 'Edit', 'user-registration' ) . '</a>';
				}
				if ( current_user_can( 'delete_users', $new_user_object->ID ) ) {
					$actions['delete'] = sprintf(
						'<a href="%s" data-wp-delete-url="%s" class="user-registration-member-action-delete">%s</a>',
						$delete_link,
						esc_url_raw( $wp_delete_url ),
						__( 'Delete', 'user-registration' )
					);              }
				if ( current_user_can( 'edit_user', $new_user_object->ID ) ) {
					$user_id = $new_user_object->ID;
					ur_check_is_auto_enable_user( $user_id );
					$is_auto_enable = get_user_meta( $user_id, 'ur_auto_enable_time', true );
					$is_disabled    = get_user_meta( $user_id, 'ur_disable_users', true );
					if ( $is_disabled ) {
						$enable_link             = add_query_arg(
							array(
								'action'   => 'enable_user',
								'user_id'  => $user_id,
								'_wpnonce' => wp_create_nonce( 'bulk-users' ),
							),
							admin_url( 'admin.php?page=user-registration-users' ),
						);
						$actions['disable_user'] = sprintf(
							'<a href="%s" class="ur-row-actions">%s </a>',
							$enable_link,
							__( 'Enable', 'user-registration' )
						);
					} elseif ( $user_id !== get_current_user_id() ) {

							$actions['disable_user'] = sprintf(
								'<a class="ur-row-actions">
									<span style="cursor:pointer;" id="disable-user-link-%d" class="disable-user-link" data-nonce="%s">
										<span>%s</span>
									</span>
								</a>',
								$user_id,
								wp_create_nonce( 'bulk-users' ),
								__( 'Disable', 'user-registration' ),
							);
					}
				}

				/**
				 * Filters the action links displayed under each user in the Users list table.
				 *
				 * @since 4.1
				 *
				 * @param string[] $actions     An array of action links to be displayed.
				 *                              Default 'Edit', 'Delete' for single site, and
				 *                              'Edit', 'Remove' for Multisite.
				 * @param WP_User  $new_user_object WP_User object for the currently listed user.
				 */
				$actions = apply_filters( 'ur_pro_user_row_actions', $actions, $new_user_object );

				// Role classes.
				$role_classes = esc_attr( implode( ' ', array_keys( $user_roles ) ) );

				// Set up the checkbox (because the user is editable, otherwise it's empty).
				$checkbox = sprintf(
					'<label class="screen-reader-text" for="user_%1$s">%2$s</label>' .
					'<input type="checkbox" name="users[]" id="user_%1$s" class="%3$s" value="%1$s" />',
					$new_user_object->ID,
					/* translators: Hidden accessibility text. %s: User login. */
					sprintf( __( 'Select %s', 'user-registration' ), $new_user_object->user_login ),
					$role_classes
				);

			}

			$avatar = get_avatar( $new_user_object->ID, 32 );

			$profile_picture_url = get_user_meta( $new_user_object->ID, 'user_registration_profile_pic_url', true );

			// Comma-separated list of user roles.
			$roles_list = implode( ', ', $user_roles );

			$row = "<tr id='user-$new_user_object->ID'>";

			list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();

			foreach ( $columns as $column_name => $column_display_name ) {
				$classes = "$column_name column-$column_name";
				if ( $primary === $column_name ) {
					$classes .= ' has-row-actions column-primary';
				}
				if ( 'posts' === $column_name ) {
					$classes .= ' num'; // Special case for that column.
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
							$row .= "$avatar <a href='" . $edit_link . "'>$new_user_object->user_login</a>";
							if ( ! empty( $actions ) ) {
								$row .= $this->row_actions( $actions );
							}
							break;
						case 'email':
							$row .= "<a href='" . esc_url( "mailto:$email" ) . "'>$email</a>";
							break;
						case 'role':
							$row .= esc_html( $roles_list );
							break;
						case 'user_status':
							$user_id = $new_user_object->ID;
							ur_check_is_auto_enable_user( $user_id );

							$is_disabled = get_user_meta( $user_id, 'ur_disable_users', true );
							if ( $is_disabled ) {
								$row .= sprintf(
									'<span class="%s">%s</span>',
									esc_attr( 'user-status-denied' ),
									__( 'Disabled', 'user-registration' )
								);
								break;
							}

							$status = $user_manager->get_user_status();
							if ( ! empty( $status ) ) {
								if ( in_array( $status['login_option'], array( 'email_confirmation', 'admin_approval_after_email_confirmation' ), true ) ) {
									if ( '0' == $status['approval_status'] || '' == $status['approval_status'] ) {
										if ( 0 == $status['email_status'] || 'false' == $status['email_status'] ) {
											$status_label = __( 'Awaiting Email Confirmation', 'user-registration' );
											$status_class = 'pending';
										} elseif ( 1 == $status['email_status'] ) {
											if ( 1 == $status['user_status'] ) {
												$status_label = UR_Admin_User_Manager::get_status_label( '1' );
												$status_class = $status_label;
											} else {

												$status_label = UR_Admin_User_Manager::get_status_label( '0' );
												$status_class = $status_label;
											}
										}
									} elseif ( '-1' == $status['approval_status'] || '1' == $status['approval_status'] ) {
										$status_label = UR_Admin_User_Manager::get_status_label( $status['approval_status'] );
										$status_class = $status_label;
									}
								} else {
									$user_status  = $status['user_status'];
									$status_label = UR_Admin_User_Manager::get_status_label( $user_status );
									$status_class = $status_label;
								}

								$user_source = get_user_meta( $user_id, 'ur_registration_source', true );

								if ( $user_source === 'membership' && 'approved' === strtolower( $status_label ) ) {

									$order_status = apply_filters( 'user_registration_check_user_order_status', $user_id );
									if ( ! empty( $order_status ) && 'pending' === $order_status ) {
										$status_label = __( 'Payment Pending', 'user-registration' );
										$status_class = 'pending';
									}
								}
								$row .= sprintf(
									'<span class="%s">%s</span>',
									esc_attr( 'user-status-' . str_replace( ' ', '', strtolower( $status_class ) ) ),
									esc_html( $status_label )
								);
							}

							break;
						case 'user_registered':
							$row .= $new_user_object->user_registered;
							break;
						case 'membership':
							if ( count( $new_user_object->subscriptions ) > 1 ) {
								$all_subs          = $new_user_object->subscriptions;
								$membership_titles = wp_list_pluck( $all_subs, 'membership_title' );
								$row              .= implode( ', ', $membership_titles );
							} else {
								$user_subs_object = $new_user_object->subscriptions[0] ?? '';
								$row             .= ( ! empty( $user_subs_object['membership_title'] ) ? $user_subs_object['membership_title'] : '-' );
							}
							break;
						case 'subscription_status':
							if ( count( $new_user_object->subscriptions ) > 1 ) {
								$subscriptions = $new_user_object->subscriptions;

								foreach ( $subscriptions as $key => $sub ) {
									if ( empty( $sub['expiry_date'] ) ) {
										continue;
									}

									$expiry_date = new \DateTime( $sub['expiry_date'] );

									if ( ! empty( $sub['billing_cycle'] ) && date( 'Y-m-d' ) > $expiry_date->format( 'Y-m-d' ) ) {
										$subscriptions[ $key ]['status'] = 'expired';
									}
								}

								$statuses       = array_column( $subscriptions, 'status' );
								$status_counts  = array_count_values( $statuses );
								$known_statuses = array( 'active', 'pending', 'expired', 'canceled' );

								foreach ( $known_statuses as $status ) {
									if ( ! isset( $status_counts[ $status ] ) ) {
										$status_counts[ $status ] = 0;
									}
								}

								$total_subs = count( $subscriptions );
								$all_set    = false;
								foreach ( $known_statuses as $status ) {
									if ( $status_counts[ $status ] === $total_subs && $total_subs > 0 ) {
										$status_class = 'user-subscription-secondary';
										if ( $status == 'active' ) {
											$status_class = 'user-subscription-active';
										} elseif ( $status == 'pending' ) {
											$status_class = 'user-subscription-pending';
										} else {
											$status_class = 'user-subscription-expired';
										}

										$row    .= sprintf( '<span id="" class="user-subscription-status %s">%s</span>', $status_class, ucwords( "All {$status}" ) );
										$all_set = true;
										break;
									}
								}

								if ( ! $all_set ) {
									$summary = array();
									foreach ( $known_statuses as $status ) {
										if ( $status_counts[ $status ] > 0 ) {
											$status_class = 'user-subscription-secondary';
											if ( $status == 'active' ) {
												$status_class = 'user-subscription-active';
											} elseif ( $status == 'pending' ) {
												$status_class = 'user-subscription-pending';
											} else {
												$status_class = 'user-subscription-expired';
											}
											$summary[] = sprintf( '<span id="" class="user-subscription-status %s">%s</span>', $status_class, ucwords( "{$status_counts[$status]} {$status}" ) );
										}
									}
									$row .= implode( ', ', $summary );
								}
							} else {
								$user_subs_object = $new_user_object->subscriptions[0] ?? '';

								$status = $user_subs_object['status'] ?? '';

								if ( empty( $status ) ) {
									$row .= '<span>-</span>';
									break;
								}

								$pending_cancel_date = get_user_meta( $new_user_object->ID, 'urm_pending_cancel_' . ( $user_subs_object['subscription_id'] ?? '' ), true );

								if ( $pending_cancel_date ) {
									$clock_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
									$row       .= sprintf(
										'<span id="" class="user-subscription-status user-subscription-canceling">%s%s</span>',
										$clock_icon,
										sprintf(
											/* translators: %s: cancellation date */
											esc_html__( 'Cancels %s', 'user-registration' ),
											esc_html( date_i18n( get_option( 'date_format' ), strtotime( $pending_cancel_date ) ) )
										)
									);
									break;
								}

								if ( ! empty( $user_subs_object['expiry_date'] ) ) {
									$expiry_date = new \DateTime( $user_subs_object['expiry_date'] );

									if ( ! empty( $user_subs_object['payment_method'] ) && ( 'subscription' == $user_subs_object['payment_method'] ) && date( 'Y-m-d' ) > $expiry_date->format( 'Y-m-d' ) ) {
										$status = 'expired';
									}
								}

								$status_class = 'user-subscription-secondary';
								if ( $status == 'active' ) {
									$status_class = 'user-subscription-active';
								} elseif ( $status == 'pending' ) {
									$status_class = 'user-subscription-pending';
								} else {
									$status_class = 'user-subscription-expired';
								}

								$row .= sprintf( '<span id="" class="user-subscription-status %s">%s</span>', $status_class, ucfirst( $status ) );
							}

							break;
						case 'team':
							$teams = is_array( $new_user_object->teams ) ? $new_user_object->teams : array();

							if ( ! empty( $teams ) ) {
								$escaped_teams = array_map( 'esc_html', $teams );
								$row          .= implode( ', ', $escaped_teams );
							}
							break;
						default:
							/**
							 * Filters the display output of custom columns in the Users list table.
							 *
							 * @since 4.1
							 *
							 * @param string $output      Custom column output. Default empty.
							 * @param string $column_name Column name.
							 * @param int    $user_id     ID of the currently-listed user.
							 */
							$row .= apply_filters( 'ur_pro_manage_users_custom_column', '', $column_name, $new_user_object->ID );
					}
					$row .= '</td>';
				}
			}
			$row .= '</tr>';

			return $row;
		}

		/**
		 * Render the filter options for users table.
		 *
		 * @since 4.1
		 *
		 * @return void
		 */
		public function display_filters() {
			?>
				<select name="form_filter" id="user_registration_pro_users_form_filter">
					<?php
					foreach ( $this->get_all_registration_forms() as $id => $form ) {
						$selected = isset( $_REQUEST['form_filter'] ) && $id == $_REQUEST['form_filter'] ? 'selected=selected' : '';
						?>
						<option value='<?php echo esc_attr( $id ); ?>' <?php echo esc_attr( $selected ); ?>><?php echo esc_html( $form ); ?></option>
						<?php
					}
					?>
				</select>

				<?php if ( ur_check_module_activation( 'membership' ) ) : ?>
					<select name="membership_id" id="user_registration_pro_users_membership_filter">
						<option value=''><?php esc_html_e( 'All Memberships', 'user-registration' ); ?></option>
						<?php
						foreach ( $this->get_all_memberships() as $membership_key => $membership_label ) {
							$selected = isset( $_REQUEST['membership_id'] ) && $membership_key == $_REQUEST['membership_id'] ? 'selected=selected' : '';
							?>
							<option value='<?php echo esc_attr( $membership_key ); ?>' <?php echo esc_attr( $selected ); ?>><?php echo esc_html( $membership_label ); ?></option>
							<?php
						}
						?>
					</select>
				<?php else : ?>
					<select name="user_status" id="user_registration_pro_users_user_status_filter">
						<?php
						$user_status = isset( $_GET['user_status'] ) ? sanitize_text_field( $_GET['user_status'] ) : '';
						?>
						<option value="all"><?php echo esc_html__( 'All', 'user-registration' ); ?></option>
						<option value="approved" <?php echo 'approved' === $user_status ? 'selected=selected' : ''; ?>><?php echo esc_html__( 'Approved', 'user-registration' ); ?></option>
						<option value="pending" <?php echo 'pending' === $user_status ? 'selected=selected' : ''; ?>><?php echo esc_html__( 'Pending', 'user-registration' ); ?></option>
						<option value="denied" <?php echo 'denied' === $user_status ? 'selected=selected' : ''; ?>><?php echo esc_html__( 'Denied', 'user-registration' ); ?></option>
						<option value="pending_email" <?php echo 'pending_email' === $user_status ? 'selected=selected' : ''; ?>><?php echo esc_html__( 'Awaiting Email Confirmation', 'user-registration' ); ?></option>
					</select>
					<select name="role" id="user_registration_pro_users_role_filter">
						<?php
						foreach ( $this->get_role_filters() as $role_key => $role_label ) {
							$selected = isset( $_REQUEST['role'] ) && $role_key === $_REQUEST['role'] ? 'selected=selected' : '';
							?>
								<option value='<?php echo esc_attr( $role_key ); ?>' <?php echo esc_attr( $selected ); ?>><?php echo esc_html( $role_label ); ?></option>
							<?php
						}
						?>
					</select>
				<?php endif; ?>

				<select name="date_range" id="user_registration_pro_users_date_range_filter">
					<?php
					$selected_date_range_filter = isset( $_GET['date_range'] ) ? sanitize_text_field( $_GET['date_range'] ) : '';

					$date_range_filters = array(
						'day'    => __( 'Day', 'user-registration' ),
						'week'   => __( 'Week', 'user-registration' ),
						'month'  => __( 'Month', 'user-registration' ),
						'year'   => __( 'Year', 'user-registration' ),
						'custom' => __( 'Custom Range', 'user-registration' ),
					);

					$default_range = 'year';

					foreach ( $date_range_filters as $key => $label ) {

						$selected = '';
						if ( $key === $selected_date_range_filter ) {
							$selected = 'selected=selected';
						} elseif ( '' === $selected_date_range_filter && $key === $default_range ) {
							$selected = 'selected=selected';
						}

						printf(
							'<option value="%s" %s>%s</option>',
							esc_attr( $key ),
							esc_attr( $selected ),
							esc_html( $label )
						);
					}
					?>
				</select>
				<div class="user-registration-users-filter-btns">
					<button type="submit" name="ur_users_filter" id="user-registration-users-filter-btn" class="button ur-button-primary">
						<?php esc_html_e( 'Filter', 'user-registration' ); ?>
					</button>

					<button type="reset"  id="user-registration-users-filter-reset-btn" class="" title="<?php _e( 'Reset', 'user-registration' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
							<path fill="#000" fill-rule="evenodd" d="M12 2h-.004a10.75 10.75 0 0 0-7.431 3.021l-.012.012L4 5.586V3a1 1 0 1 0-2 0v5a.997.997 0 0 0 1 1h5a1 1 0 0 0 0-2H5.414l.547-.547A8.75 8.75 0 0 1 12.001 4 8 8 0 1 1 4 12a1 1 0 1 0-2 0A10 10 0 1 0 12 2Z" clip-rule="evenodd"/>
						</svg>
					</button>
				</div>

				<?php
				$hide_advanced_filters = 'display:none';
				if ( isset( $_REQUEST['date_range'] ) && 'custom' === sanitize_text_field( $_REQUEST['date_range'] ) ) {
					$hide_advanced_filters = '';
				}
				?>
				<div id="user-registration-users-advanced-filters" style="<?php echo esc_attr( $hide_advanced_filters ); ?>">
					<ul>
						<li>
							<div>
								<p>Custom Date Range</p>
								<?php
									$start_date = isset( $_REQUEST['start_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['start_date'] ) ) : '';
									$end_date   = isset( $_REQUEST['end_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['end_date'] ) ) : '';
								?>
								<input type="date" name="start_date" value="<?php echo esc_attr( $start_date ); ?>" id="user_registration_pro_start_date_filter" title="Start date" />
								-
								<input type="date" name="end_date"  value="<?php echo esc_attr( $end_date ); ?>" id="user_registration_pro_end_date_filter" title="End date" />
							</div>
						</li>
					</ul>
				</div>

			<?php
		}

		/**
		 * Displays the search box.
		 *
		 * @since 4.1
		 */
		public function display_search_box() {
			if ( empty( $_REQUEST['s'] ) && ! $this->has_items() ) {
				return;
			}

			$input_id = 'user-registration-users-search-input';

			if ( ! empty( $_REQUEST['orderby'] ) ) {
				echo '<input type="hidden" name="orderby" value="' . esc_attr( wp_unslash( $_REQUEST['orderby'] ) ) . '" />';
			}
			if ( ! empty( $_REQUEST['order'] ) ) {
				echo '<input type="hidden" name="order" value="' . esc_attr( wp_unslash( $_REQUEST['order'] ) ) . '" />';
			}
			if ( ! empty( $_REQUEST['post_mime_type'] ) ) {
				echo '<input type="hidden" name="post_mime_type" value="' . esc_attr( $_REQUEST['post_mime_type'] ) . '" />';
			}
			if ( ! empty( $_REQUEST['detached'] ) ) {
				echo '<input type="hidden" name="detached" value="' . esc_attr( $_REQUEST['detached'] ) . '" />';
			}
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
		 * Returns a list of all registration forms.
		 *
		 * @since 4.1
		 *
		 * @return array
		 */
		protected function get_all_registration_forms() {
			$forms = array(
				'0' => __( 'All Forms', 'user-registration' ),
			);

			$forms = array_replace( $forms, ur_get_all_user_registration_form() );

			return $forms;
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

					return ! empty( $content['status'] );
				}
			);

			return wp_list_pluck( $active_posts, 'post_title', 'ID' );
		}

		/**
		 * Returns a list of all available roles.
		 *
		 * @since 4.1
		 *
		 * @return array
		 */
		protected function get_role_filters() {
			$roles = wp_roles()->role_names;

			$roles = array_merge(
				array(
					'all' => __( 'All Roles', 'user-registration' ),
				),
				$roles
			);

			return $roles;
		}

		/**
		 * Returns an array of translated user role names for a given user object.
		 *
		 * @since 4.1
		 *
		 * @param WP_User $user_object The WP_User object.
		 * @return string[] An array of user role names keyed by role.
		 */
		protected function get_role_list( $user_object ) {
			$wp_roles = wp_roles();

			$role_list = array();

			foreach ( $user_object->roles as $role ) {
				if ( isset( $wp_roles->role_names[ $role ] ) ) {
					$role_list[ $role ] = translate_user_role( $wp_roles->role_names[ $role ] );
				}
			}

			if ( empty( $role_list ) ) {
				$role_list['none'] = _x( 'None', 'no user roles', 'user-registration' );
			}

			/**
			 * Filters the returned array of translated role names for a user.
			 *
			 * @since 4.1
			 *
			 * @param string[] $role_list   An array of translated user role names keyed by role.
			 * @param WP_User  $user_object A WP_User object.
			 */
			return apply_filters( 'ur_pro_get_role_list', $role_list, $user_object );
		}

		/**
		 * Returns a list of sortable columns.
		 *
		 * @since 4.1
		 *
		 * @return array
		 */
		protected function get_sortable_columns() {
			return apply_filters(
				'user_registration_pro_users_table_sortable_columns',
				array(
					'username'            => 'user_login',
					'email'               => 'user_email',
					'user_registered'     => 'user_registered',
					'membership'          => 'membership_title',
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
			$singular = $this->_args['singular'];

			$this->display_tablenav( 'top' );

			$this->screen->render_screen_reader_content( 'heading_list' );
			?>
			<table class="wp-list-table <?php echo implode( ' ', $this->get_table_classes() ); ?>">
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
		 * Prints column headers, accounting for hidden and sortable columns.
		 *
		 * @since 3.1.0
		 *
		 * @param bool $with_id Whether to set the ID attribute or not
		 */
		public function print_column_headers( $with_id = true ) {
			list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();

			$current_url = set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
			$current_url = remove_query_arg(
				array(
					'paged',
					'count_type',
					'reset_count',
					'role_change_count',
					'approval_count',
					'await_count',
					'denial_count',
					'delete_count',
					'enable_disable_count',
				),
				$current_url
			);

			// When users click on a column header to sort by other columns.
			if ( isset( $_GET['orderby'] ) ) {
				$current_orderby = $_GET['orderby'];
				// In the initial view there's no orderby parameter.
			} else {
				$current_orderby = '';
			}

			// Not in the initial view and descending order.
			if ( isset( $_GET['order'] ) && 'desc' === $_GET['order'] ) {
				$current_order = 'desc';
			} else {
				// The initial view is not always 'asc', we'll take care of this below.
				$current_order = 'asc';
			}

			if ( ! empty( $columns['cb'] ) ) {
				static $cb_counter = 1;
				$columns['cb']     = '<input id="cb-select-all-' . $cb_counter . '" type="checkbox" />
			<label for="cb-select-all-' . $cb_counter . '">' .
				'<span class="screen-reader-text">' .
					/* translators: Hidden accessibility text. */
					__( 'Select All', 'user-registration' ) .
				'</span>' .
				'</label>';
				++$cb_counter;
			}

			foreach ( $columns as $column_key => $column_display_name ) {
				$class          = array( 'manage-column', "column-$column_key" );
				$aria_sort_attr = '';
				$abbr_attr      = '';
				$order_text     = '';

				if ( in_array( $column_key, $hidden, true ) ) {
					$class[] = 'hidden';
				}

				if ( 'cb' === $column_key ) {
					$class[] = 'check-column';
				} elseif ( in_array( $column_key, array( 'posts', 'comments', 'links' ), true ) ) {
					$class[] = 'num';
				}

				if ( $column_key === $primary ) {
					$class[] = 'column-primary';
				}

				if ( isset( $sortable[ $column_key ] ) ) {
					$orderby       = isset( $sortable[ $column_key ][0] ) ? $sortable[ $column_key ][0] : '';
					$desc_first    = isset( $sortable[ $column_key ][1] ) ? $sortable[ $column_key ][1] : false;
					$abbr          = isset( $sortable[ $column_key ][2] ) ? $sortable[ $column_key ][2] : '';
					$orderby_text  = isset( $sortable[ $column_key ][3] ) ? $sortable[ $column_key ][3] : '';
					$initial_order = isset( $sortable[ $column_key ][4] ) ? $sortable[ $column_key ][4] : '';

					/*
					 * We're in the initial view and there's no $_GET['orderby'] then check if the
					 * initial sorting information is set in the sortable columns and use that.
					 */
					if ( '' === $current_orderby && $initial_order ) {
						// Use the initially sorted column $orderby as current orderby.
						$current_orderby = $orderby;
						// Use the initially sorted column asc/desc order as initial order.
						$current_order = $initial_order;
					}

					/*
					 * True in the initial view when an initial orderby is set via get_sortable_columns()
					 * and true in the sorted views when the actual $_GET['orderby'] is equal to $orderby.
					 */
					if ( $current_orderby === $orderby ) {
						// The sorted column. The `aria-sort` attribute must be set only on the sorted column.
						if ( 'asc' === $current_order ) {
							$order          = 'desc';
							$aria_sort_attr = ' aria-sort="ascending"';
						} else {
							$order          = 'asc';
							$aria_sort_attr = ' aria-sort="descending"';
						}

						$class[] = 'sorted';
						$class[] = $current_order;
					} else {
						// The other sortable columns.
						$order = strtolower( $desc_first );

						if ( ! in_array( $order, array( 'desc', 'asc' ), true ) ) {
							$order = $desc_first ? 'desc' : 'asc';
						}

						$class[] = 'sortable';
						$class[] = 'desc' === $order ? 'asc' : 'desc';

						/* translators: Hidden accessibility text. */
						$asc_text = __( 'Sort ascending.', 'user-registration' );
						/* translators: Hidden accessibility text. */
						$desc_text  = __( 'Sort descending.', 'user-registration' );
						$order_text = 'asc' === $order ? $asc_text : $desc_text;
					}

					if ( '' !== $order_text ) {
						$order_text = ' <span class="screen-reader-text">' . $order_text . '</span>';
					}

					// Print an 'abbr' attribute if a value is provided via get_sortable_columns().
					$abbr_attr = $abbr ? ' abbr="' . esc_attr( $abbr ) . '"' : '';

					$column_display_name = sprintf(
						'<a href="%1$s">' .
						'<span>%2$s</span>' .
						'<span class="sorting-indicators">' .
							'<span class="sorting-indicator asc" aria-hidden="true"></span>' .
							'<span class="sorting-indicator desc" aria-hidden="true"></span>' .
						'</span>' .
						'%3$s' .
						'</a>',
						esc_url( add_query_arg( compact( 'orderby', 'order' ), $current_url ) ),
						$column_display_name,
						$order_text
					);
				}

				$tag        = ( 'cb' === $column_key ) ? 'td' : 'th';
				$scope      = ( 'th' === $tag ) ? 'scope="col"' : '';
				$id         = $with_id ? "id='$column_key'" : '';
				$class_attr = "class='" . implode( ' ', $class ) . "'";

				echo "<$tag $scope $id $class_attr $aria_sort_attr $abbr_attr>$column_display_name</$tag>";
			}
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
					<div class="user-registration-members-filters">
						<?php $this->display_filters(); ?>
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
		 * Output footer text.
		 *
		 * @return void
		 */
		// protected function footer_text() {
		// $total_items    = $this->_pagination_args['total_items'];
		// $current        = $this->get_pagenum();
		// $users_per_page = $this->_pagination_args['per_page'];

		// echo esc_html(
		// sprintf(
		// 'Showing results %d-%d of %d users',
		// ( ( $current - 1 ) * $users_per_page ) + 1,
		// min( ( $current ) * $users_per_page, $total_items ),
		// $total_items
		// )
		// );
		// }

		public function output_custom_column_data( $output, $column_name, $user_id ) {
			$meta_key = 'user_registration_' . $column_name;

			$meta_value = get_user_meta( $user_id, $meta_key, true );

			if ( empty( $meta_value ) ) {
				$meta_value = get_user_meta( $user_id, $column_name, true );
			}

			$output .= is_array( $meta_value ) ? implode( ', ', $meta_value ) : $meta_value;

			return $output;
		}

		/**
		 * Searches the user on the basis of full name, first name, last name, or phone number.
		 *
		 * @param object $query WP_User_Query instance.
		 */
		public function urm_search_user_on_name( $query ) {
			global $wpdb;

			if ( isset( $_REQUEST['s'], $_REQUEST['page'] ) && ! empty( $_REQUEST['s'] ) && 'user-registration-users' === $_REQUEST['page'] ) {
				$usersearch  = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
				$search_like = '%' . $wpdb->esc_like( $usersearch ) . '%';

				$search_conditions = array();

				$search_conditions[] = $wpdb->prepare( "({$wpdb->users}.user_login LIKE %s)", $search_like );
				$search_conditions[] = $wpdb->prepare( "({$wpdb->users}.user_email LIKE %s)", $search_like );
				$search_conditions[] = $wpdb->prepare( "({$wpdb->users}.display_name LIKE %s)", $search_like );
				$search_conditions[] = $wpdb->prepare( "({$wpdb->users}.user_nicename LIKE %s)", $search_like );

				$search_conditions[] = $wpdb->prepare(
					"EXISTS (
						SELECT 1
						FROM {$wpdb->usermeta} um
						WHERE um.user_id = {$wpdb->users}.ID
						AND um.meta_key IN ('first_name', 'last_name', 'nickname')
						AND um.meta_value LIKE %s
					)",
					$search_like
				);

				// Also match the full name (e.g. "John Smith") when first/last name are stored
				// as separate usermeta rather than combined into a single field like display_name.
				$search_conditions[] = $wpdb->prepare(
					"EXISTS (
						SELECT 1
						FROM {$wpdb->usermeta} umfn
						JOIN {$wpdb->usermeta} umln
							ON umln.user_id = umfn.user_id
							AND umln.meta_key = 'last_name'
						WHERE umfn.user_id = {$wpdb->users}.ID
						AND umfn.meta_key = 'first_name'
						AND (
							CONCAT( umfn.meta_value, ' ', umln.meta_value ) LIKE %s
							OR CONCAT( umln.meta_value, ' ', umfn.meta_value ) LIKE %s
						)
					)",
					$search_like,
					$search_like
				);

				$search_conditions[] = $wpdb->prepare(
					"EXISTS (
						SELECT 1
						FROM {$wpdb->usermeta} um
						WHERE um.user_id = {$wpdb->users}.ID
						AND um.meta_key LIKE %s
						AND um.meta_value LIKE %s
					)",
					'user_registration_%',
					$search_like
				);

				if ( preg_match( '/^[\d\s\-\+\(\)]+$/', $usersearch ) ) {
					$search_conditions[] = $wpdb->prepare(
						"EXISTS (
							SELECT 1
							FROM {$wpdb->usermeta} um
							WHERE um.user_id = {$wpdb->users}.ID
							AND um.meta_key LIKE %s
							AND um.meta_value LIKE %s
						)",
						'phone_%',
						$search_like
					);
				}

				if ( ! empty( $search_conditions ) ) {
					$pattern = "/AND\s*\(\s*(?:\w+\.)?(user_login|user_url|user_email|user_nicename|display_name)\s+LIKE\s+'[^']+'\s*(OR\s+(?:\w+\.)?(user_login|user_url|user_email|user_nicename|display_name)\s+LIKE\s+'[^']+'\s*)+\)/i";

					$query->query_where  = preg_replace( $pattern, '', $query->query_where );
					$query->query_where .= ' AND (' . implode( ' OR ', $search_conditions ) . ')';
				}
			}

			remove_action( 'pre_user_query', array( $this, 'urm_search_user_on_name' ) );
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

}
