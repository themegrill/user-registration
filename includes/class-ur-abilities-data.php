<?php
/**
 * Allowlisted read models for the native abilities API.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Ability callbacks. Input validation and authorization belong to WP_Ability. */
class UR_Abilities_Data {
	/**
	 * List a bounded page of forms.
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public static function list_forms( $input ) {
		$page             = self::page( $input );
		$query            = new WP_Query(
			array(
				'post_type'      => 'user_registration',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => $page['per_page'] + 1,
				'offset'         => ( $page['page'] - 1 ) * $page['per_page'],
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$page['has_more'] = count( $query->posts ) > $page['per_page'];
		foreach ( array_slice( $query->posts, 0, $page['per_page'] ) as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$page['items'][] = self::form( $post );
			}
		}
		return $page;
	}

	/**
	 * Read field definitions, never arbitrary field settings or defaults.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_form( $input ) {
		$post = get_post( $input['id'] );
		if ( ! $post || 'user_registration' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return self::not_found();
		}
		$result           = self::form( $post );
		$result['fields'] = array();
		foreach ( ur_get_form_fields( $post->ID ) as $name => $field ) {
			$settings           = isset( $field->general_setting ) ? $field->general_setting : new stdClass();
			$result['fields'][] = array(
				'name'     => (string) $name,
				'type'     => isset( $field->field_key ) ? (string) $field->field_key : '',
				'label'    => isset( $settings->label ) ? wp_strip_all_tags( (string) $settings->label ) : '',
				'required' => isset( $settings->required ) && ur_string_to_bool( $settings->required ),
			);
		}
		return $result;
	}

	/**
	 * List members on this site only, including registration-only members.
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public static function list_members( $input ) {
		$page = self::page( $input );
		if ( isset( $input['status'] ) || isset( $input['email_verified'] ) ) {
			return self::filter_members( $input, $page );
		}
		$query            = new WP_User_Query(
			self::member_query( $input ) + array(
				'number' => $page['per_page'] + 1,
				'offset' => ( $page['page'] - 1 ) * $page['per_page'],
			)
		);
		$users            = $query->get_results();
		$page['has_more'] = count( $users ) > $page['per_page'];
		foreach ( array_slice( $users, 0, $page['per_page'] ) as $user ) {
			$page['items'][] = self::member( $user );
		}
		return $page;
	}

	/**
	 * Page through members matching a status filter.
	 *
	 * Status spans several user meta keys, so members are filtered with the same
	 * resolver the output uses instead of a meta query that could disagree with it.
	 *
	 * @param array $input Validated input.
	 * @param array $page  Page response.
	 * @return array|WP_Error
	 */
	private static function filter_members( $input, $page ) {
		$filters = array();
		if ( isset( $input['status'] ) ) {
			$filters['registration_status'] = $input['status'];
		}
		if ( isset( $input['email_verified'] ) ) {
			// GET requests on WordPress 6.9 pass booleans as strings.
			$filters['email_verified'] = rest_sanitize_boolean( $input['email_verified'] );
		}
		$skip = ( $page['page'] - 1 ) * $page['per_page'];
		$args = self::member_query( $input ) + array(
			'number' => 200,
			'fields' => 'all_with_meta',
		);
		for ( $offset = 0; $offset < 10000; $offset += 200 ) {
			$args['offset'] = $offset;
			$query          = new WP_User_Query( $args );
			$users          = $query->get_results();
			foreach ( $users as $user ) {
				$member = self::member( $user );
				foreach ( $filters as $key => $value ) {
					if ( $value !== $member[ $key ] ) {
						continue 2;
					}
				}
				if ( $skip > 0 ) {
					--$skip;
					continue;
				}
				if ( count( $page['items'] ) === $page['per_page'] ) {
					$page['has_more'] = true;
					return $page;
				}
				$page['items'][] = $member;
			}
			if ( count( $users ) < 200 ) {
				return $page;
			}
		}
		$args['offset'] = 10000;
		$args['number'] = 1;
		$args['fields'] = 'ID';
		$query          = new WP_User_Query( $args );
		if ( ! $query->get_results() ) {
			return $page;
		}
		return new WP_Error( 'ur_abilities_member_limit', __( 'More than 10000 members to filter. Add a form_id to narrow the list.', 'user-registration' ), array( 'status' => 400 ) );
	}

	/**
	 * List membership plans with their active member counts.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function list_memberships( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}
		$page             = self::page( $input );
		$repository       = new \WPEverest\URMembership\Admin\Repositories\MembershipRepository();
		$currency         = get_option( 'user_registration_payment_currency', 'USD' );
		$plans            = array_filter(
			array_reverse( $repository->get_all_memberships_without_status_filter() ),
			function ( $plan ) {
				return 'trash' !== $plan['post_status'];
			}
		);
		$plans            = array_slice( $plans, ( $page['page'] - 1 ) * $page['per_page'], $page['per_page'] + 1 );
		$members          = $repository->count_members_by_membership( 'active' );
		$page['has_more'] = count( $plans ) > $page['per_page'];
		foreach ( array_slice( $plans, 0, $page['per_page'] ) as $plan ) {
			$meta            = is_array( $plan['meta_value'] ) ? $plan['meta_value'] : array();
			$type            = isset( $meta['type'] ) ? (string) $meta['type'] : '';
			$billing         = 'subscription' === $type && isset( $meta['subscription']['value'], $meta['subscription']['duration'] );
			$page['items'][] = array(
				'id'               => (int) $plan['ID'],
				'name'             => wp_strip_all_tags( $plan['post_title'] ),
				'enabled'          => 'publish' === $plan['post_status'] && ! empty( $plan['post_content']['status'] ),
				'type'             => $type,
				'amount'           => isset( $meta['amount'] ) ? (string) $meta['amount'] : '0',
				'currency'         => $currency,
				'billing_interval' => $billing ? (int) $meta['subscription']['value'] : null,
				'billing_period'   => $billing ? (string) $meta['subscription']['duration'] : null,
				'active_members'   => isset( $members[ $plan['ID'] ] ) ? $members[ $plan['ID'] ] : 0,
			);
		}
		return $page;
	}

	/**
	 * List a bounded page of membership subscriptions.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function list_subscriptions( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}
		$error = self::date_range_error( $input, 'expiry_from', 'expiry_to' );
		if ( $error ) {
			return $error;
		}
		$page             = self::page( $input );
		$repository       = new \WPEverest\URMembership\Admin\Repositories\SubscriptionRepository();
		$result           = $repository->query(
			array(
				'page'        => $page['page'],
				'per_page'    => $page['per_page'],
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'status'      => isset( $input['status'] ) ? $input['status'] : '',
				'user_id'     => isset( $input['member_id'] ) ? $input['member_id'] : 0,
				'item_id'     => isset( $input['membership_id'] ) ? $input['membership_id'] : 0,
				'expiry_from' => isset( $input['expiry_from'] ) ? $input['expiry_from'] : '',
				'expiry_to'   => isset( $input['expiry_to'] ) ? $input['expiry_to'] : '',
			)
		);
		$page['has_more'] = $page['page'] * $page['per_page'] < $result['total'];
		cache_users( wp_list_pluck( $result['items'], 'user_id' ) );
		foreach ( $result['items'] as $subscription ) {
			$page['items'][] = self::subscription( $subscription );
		}
		return $page;
	}

	/**
	 * List a bounded page of membership payments, newest first.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function list_orders( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}
		$error = self::date_range_error( $input, 'date_from', 'date_to' );
		if ( $error ) {
			return $error;
		}
		$page = self::page( $input );
		$args = array(
			'orderby' => 'ID',
			'order'   => 'DESC',
			'limit'   => $page['per_page'] + 1,
			'offset'  => ( $page['page'] - 1 ) * $page['per_page'],
		);
		foreach ( array( 'membership_id', 'status', 'date_from', 'date_to' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$args[ $key ] = $input[ $key ];
			}
		}
		if ( isset( $input['gateway'] ) ) {
			$args['payment_method'] = $input['gateway'];
		}
		$repository = new \WPEverest\URMembership\Admin\Repositories\OrdersRepository();
		$orders     = $repository->get_all( $args );
		// The repository returns an empty page shape, not rows, when the table is missing.
		if ( isset( $orders['items'] ) ) {
			return $page;
		}
		$page['has_more'] = count( $orders ) > $page['per_page'];
		foreach ( array_slice( $orders, 0, $page['per_page'] ) as $order ) {
			$page['items'][] = self::order( $order );
		}
		return $page;
	}

	/**
	 * Read one membership payment.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_order( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}
		$repository = new \WPEverest\URMembership\Admin\Repositories\OrdersRepository();
		$order      = $repository->get_order_detail( $input['id'] );
		if ( empty( $order['order_id'] ) ) {
			return self::not_found();
		}
		return self::order( $order ) + array(
			'member_id'           => (int) $order['user_id'],
			'subscription_status' => (string) $order['subscription_status'],
			'expiry_date'         => (string) $order['expiry_date'],
			'next_billing_date'   => (string) $order['next_billing_date'],
			'coupon'              => (string) $order['coupon'],
		);
	}

	/**
	 * Add up membership payments for a date range. Revenue is the completed payments only.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_revenue_summary( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}
		$from  = isset( $input['date_from'] ) ? $input['date_from'] : gmdate( 'Y-m-d', strtotime( '-29 days' ) );
		$to    = isset( $input['date_to'] ) ? $input['date_to'] : gmdate( 'Y-m-d' );
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, new DateTimeZone( 'UTC' ) );
		$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, new DateTimeZone( 'UTC' ) );
		if ( ! $start || ! $end || $start->format( 'Y-m-d' ) !== $from || $end->format( 'Y-m-d' ) !== $to || $end < $start || $start->diff( $end )->days > 365 ) {
			return new WP_Error( 'ur_abilities_date_range', __( 'Use valid dates in ascending order spanning at most 366 days.', 'user-registration' ), array( 'status' => 400 ) );
		}
		$args = array(
			'date_from' => $from,
			'date_to'   => $to,
		);
		if ( isset( $input['membership_id'] ) ) {
			$args['membership_id'] = $input['membership_id'];
		}
		$statuses = array();
		$plans    = array();
		$orders   = 0;
		$revenue  = 0.0;
		foreach ( ( new \WPEverest\URMembership\Admin\Repositories\OrdersRepository() )->get_summary( $args ) as $row ) {
			$status = (string) $row['status'];
			if ( ! isset( $statuses[ $status ] ) ) {
				$statuses[ $status ] = array(
					'status' => $status,
					'orders' => 0,
					'amount' => 0.0,
				);
			}
			$statuses[ $status ]['orders'] += (int) $row['orders'];
			$statuses[ $status ]['amount'] += (float) $row['amount'];
			$plan_id                        = (int) $row['item_id'];
			if ( ! isset( $plans[ $plan_id ] ) ) {
				$plans[ $plan_id ] = array(
					'membership_id'   => $plan_id,
					'membership_name' => wp_strip_all_tags( get_the_title( $plan_id ) ),
					'orders'          => 0,
					'revenue'         => 0.0,
				);
			}
			// Orders count every status, like by_status; revenue counts completed payments only.
			$orders                      += (int) $row['orders'];
			$plans[ $plan_id ]['orders'] += (int) $row['orders'];
			if ( 'completed' === $status ) {
				$revenue                      += (float) $row['amount'];
				$plans[ $plan_id ]['revenue'] += (float) $row['amount'];
			}
		}
		$plans = array_values( $plans );
		usort(
			$plans,
			function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);
		ksort( $statuses );
		return array(
			'date_from' => $from,
			'date_to'   => $to,
			'currency'  => get_option( 'user_registration_payment_currency', 'USD' ),
			'orders'    => $orders,
			'revenue'   => number_format( $revenue, 2, '.', '' ),
			'by_status' => array_map(
				function ( $item ) {
					return array( 'amount' => number_format( $item['amount'], 2, '.', '' ) ) + $item;
				},
				array_values( $statuses )
			),
			'by_plan'   => array_map(
				function ( $item ) {
					return array( 'revenue' => number_format( $item['revenue'], 2, '.', '' ) ) + $item;
				},
				array_slice( $plans, 0, 100 )
			),
		);
	}

	/**
	 * Read a member's identity and membership subscriptions.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_member( $input ) {
		$query = new WP_User_Query(
			self::member_query( array() ) + array(
				'include' => array( $input['id'] ),
				'number'  => 1,
			)
		);
		$users = $query->get_results();
		if ( ! $users ) {
			return self::not_found();
		}
		$result                         = self::member( $users[0] );
		$result['membership_available'] = self::membership_ready();
		$result['subscriptions']        = array();
		if ( $result['membership_available'] ) {
			$repository    = new \WPEverest\URMembership\Admin\Repositories\MembersRepository();
			$subscriptions = $repository->get_member_membership_by_id( $users[0]->ID );
			foreach ( (array) $subscriptions as $subscription ) {
				$result['subscriptions'][] = array(
					'id'            => (int) $subscription['subscription_id'],
					'membership_id' => (int) $subscription['post_id'],
					'name'          => wp_strip_all_tags( $subscription['post_title'] ),
					'status'        => (string) $subscription['status'],
					'start_date'    => (string) $subscription['start_date'],
					'expiry_date'   => (string) $subscription['expiry_date'],
				);
			}
		}
		return $result;
	}

	/**
	 * Describe what approving a member would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_approve_member( $input ) {
		$user = self::moderated_member( $input['id'], 'approved' );

		return is_wp_error( $user ) ? $user : sprintf(
			/* translators: 1: username, 2: email address. */
			__( 'Approve %1$s (%2$s). They will be able to log in, and the approval email is sent if it is turned on.', 'user-registration' ),
			$user->user_login,
			$user->user_email
		);
	}

	/**
	 * Approve a member the same way the Users screen does.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function approve_member( $input ) {
		return self::moderate( $input['id'], true );
	}

	/**
	 * Describe what denying a member would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_deny_member( $input ) {
		$user = self::moderated_member( $input['id'], 'denied' );

		return is_wp_error( $user ) ? $user : sprintf(
			/* translators: 1: username, 2: email address. */
			__( 'Deny %1$s (%2$s). They will not be able to log in, and the denial email is sent if it is turned on.', 'user-registration' ),
			$user->user_login,
			$user->user_email
		);
	}

	/**
	 * Deny a member the same way the Users screen does.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function deny_member( $input ) {
		return self::moderate( $input['id'], false );
	}

	/**
	 * Send the email confirmation link again to a member who has not confirmed yet.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function resend_verification_email( $input ) {
		$user = self::moderated_member( $input['id'] );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$manager = new UR_Admin_User_Manager( $user );

		if ( ! $manager->is_email_pending() ) {
			return new WP_Error( 'ur_abilities_not_email_pending', __( 'This member is not waiting for email confirmation.', 'user-registration' ), array( 'status' => 409 ) );
		}

		ur_resend_verification_email( $user->ID );

		return self::member( $user );
	}

	/**
	 * Find a registered member whose status the current user may change, like the Users screen allows.
	 *
	 * @param int    $id     Member ID.
	 * @param string $target Status the change would set, to stop a change that does nothing.
	 * @return WP_User|WP_Error
	 */
	private static function moderated_member( $id, $target = '' ) {
		$query = new WP_User_Query(
			self::member_query( array() ) + array(
				'include' => array( $id ),
				'number'  => 1,
			)
		);
		$users = $query->get_results();

		if ( ! $users ) {
			return self::not_found();
		}

		$manager = new UR_Admin_User_Manager( $users[0] );

		if ( ! $manager->can_status_be_changed_by( get_current_user_id() ) ) {
			return new WP_Error( 'ur_abilities_forbidden', __( 'You cannot change the status of this member, for example your own account or another admin.', 'user-registration' ), array( 'status' => 403 ) );
		}

		if ( '' !== $target && self::status( $users[0] ) === $target ) {
			/* translators: %s: approved or denied. */
			return new WP_Error( 'ur_abilities_no_change', sprintf( __( 'This member is already %s.', 'user-registration' ), $target ), array( 'status' => 409 ) );
		}

		return $users[0];
	}

	/**
	 * Approve or deny a member and update the email confirmation the same way the Users screen does.
	 *
	 * @param int  $id      Member ID.
	 * @param bool $approve True to approve, false to deny.
	 * @return array|WP_Error
	 */
	private static function moderate( $id, $approve ) {
		$user = self::moderated_member( $id, $approve ? 'approved' : 'denied' );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		( new UR_Admin_User_Manager( $user ) )->set_approval( $approve );

		return self::member( $user );
	}

	/**
	 * Count registrations using the same status resolver as member administration.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_registration_stats( $input ) {
		$from  = isset( $input['date_from'] ) ? $input['date_from'] : gmdate( 'Y-m-d', strtotime( '-29 days' ) );
		$to    = isset( $input['date_to'] ) ? $input['date_to'] : gmdate( 'Y-m-d' );
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, new DateTimeZone( 'UTC' ) );
		$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, new DateTimeZone( 'UTC' ) );
		if ( ! $start || ! $end || $start->format( 'Y-m-d' ) !== $from || $end->format( 'Y-m-d' ) !== $to || $end < $start || $start->diff( $end )->days > 92 ) {
			return new WP_Error( 'ur_abilities_date_range', __( 'Use valid dates in ascending order spanning at most 93 days.', 'user-registration' ), array( 'status' => 400 ) );
		}
		$args = self::member_query( $input );
		// Statistics count form registrations, excluding membership-only accounts.
		$args['meta_query']  = array(
			array(
				'key'     => 'ur_form_id',
				'value'   => isset( $input['form_id'] ) ? $input['form_id'] : 0,
				'compare' => isset( $input['form_id'] ) ? '=' : '>',
				'type'    => 'NUMERIC',
			),
		);
		$args['date_query']  = array(
			array(
				'after'     => $from . ' 00:00:00',
				'before'    => $to . ' 23:59:59',
				'inclusive' => true,
			),
		);
		$args['number']      = 1;
		$args['count_total'] = true;
		$query               = new WP_User_Query( $args );
		$total               = (int) $query->get_total();
		if ( $total > 10000 ) {
			return new WP_Error( 'ur_abilities_stats_limit', __( 'More than 10000 registrations match. Narrow the dates or specify a form.', 'user-registration' ), array( 'status' => 400 ) );
		}
		$groups              = array();
		$args['number']      = 500;
		$args['count_total'] = false;
		for ( $offset = 0; $offset < $total; $offset += 500 ) {
			$args['offset'] = $offset;
			$query          = new WP_User_Query( $args );
			foreach ( $query->get_results() as $user ) {
				$form_id = (int) get_user_meta( $user->ID, 'ur_form_id', true );
				$status  = self::status( $user );
				$date    = substr( $user->user_registered, 0, 10 );
				$key     = $date . ':' . $form_id . ':' . $status;
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'date'    => $date,
						'form_id' => $form_id,
						'status'  => $status,
						'count'   => 0,
					);
				}
				++$groups[ $key ]['count'];
			}
		}
		ksort( $groups );
		return array(
			'date_from' => $from,
			'date_to'   => $to,
			'timezone'  => 'UTC',
			'total'     => array_sum( array_column( $groups, 'count' ) ),
			'groups'    => array_values( $groups ),
		);
	}

	/**
	 * Restrict user reads to registration members on the current site.
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	private static function member_query( $input ) {
		$forms = array(
			'key'     => 'ur_form_id',
			'value'   => isset( $input['form_id'] ) ? $input['form_id'] : 0,
			'compare' => isset( $input['form_id'] ) ? '=' : '>',
			'type'    => 'NUMERIC',
		);
		return array(
			'blog_id'     => get_current_blog_id(),
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'count_total' => false,
			'meta_query'  => isset( $input['form_id'] ) ? array( $forms ) : array(
				'relation' => 'OR',
				$forms,
				array(
					'key'   => 'ur_registration_source',
					'value' => 'membership',
				),
			),
		);
	}

	/**
	 * Serialize non-sensitive form identity.
	 *
	 * @param WP_Post $post Form.
	 * @return array
	 */
	private static function form( $post ) {
		return array(
			'id'     => (int) $post->ID,
			'title'  => wp_strip_all_tags( $post->post_title ),
			'status' => $post->post_status,
		);
	}

	/**
	 * Serialize only documented member fields.
	 *
	 * @param WP_User $user Member.
	 * @return array
	 */
	private static function member( $user ) {
		$email_status = get_user_meta( $user->ID, 'ur_confirm_email', true );
		return array(
			'id'                  => (int) $user->ID,
			'username'            => $user->user_login,
			'email'               => $user->user_email,
			'display_name'        => $user->display_name,
			'registered_at'       => str_replace( ' ', 'T', $user->user_registered ) . 'Z',
			'form_id'             => (int) get_user_meta( $user->ID, 'ur_form_id', true ),
			'registration_status' => self::status( $user ),
			'email_verified'      => '' === $email_status ? null : '1' === (string) $email_status,
		);
	}

	/**
	 * Resolve current status without changing approval or triggering emails.
	 *
	 * @param WP_User $user Member.
	 * @return string
	 */
	private static function status( $user ) {
		$manager = new UR_Admin_User_Manager( $user );
		$result  = $manager->get_user_status();
		$value   = is_array( $result ) ? $result['user_status'] : $result;
		$labels  = array(
			'1'  => 'approved',
			'0'  => 'pending',
			'-1' => 'denied',
		);
		return isset( $labels[ (string) $value ] ) ? $labels[ (string) $value ] : 'unknown';
	}

	/**
	 * Initialize a page response.
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	private static function page( $input ) {
		return array(
			'items'    => array(),
			'page'     => isset( $input['page'] ) ? absint( $input['page'] ) : 1,
			'per_page' => isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20,
			'has_more' => false,
		);
	}

	/**
	 * Serialize the payment fields shown in the admin payment list.
	 *
	 * @param array $order Order row.
	 * @return array
	 */
	private static function order( $order ) {
		return array(
			'id'              => (int) $order['order_id'],
			'member_email'    => (string) $order['user_email'],
			'member_name'     => (string) $order['display_name'],
			'membership_id'   => (int) $order['post_id'],
			'membership_name' => wp_strip_all_tags( $order['post_title'] ),
			'subscription_id' => (int) $order['subscription_id'],
			'status'          => (string) $order['status'],
			'type'            => isset( $order['order_type'] ) ? (string) $order['order_type'] : '',
			'gateway'         => (string) $order['payment_method'],
			'transaction_id'  => (string) $order['transaction_id'],
			'amount'          => (string) $order['total_amount'],
			'currency'        => get_option( 'user_registration_payment_currency', 'USD' ),
			'created_at'      => (string) ( isset( $order['order_created_at'] ) ? $order['order_created_at'] : $order['created_at'] ),
		);
	}

	/**
	 * Check optional Y-m-d dates are real and in ascending order.
	 *
	 * @param array  $input    Validated input.
	 * @param string $from_key Start date key.
	 * @param string $to_key   End date key.
	 * @return WP_Error|null
	 */
	private static function date_range_error( $input, $from_key, $to_key ) {
		$dates = array();
		foreach ( array( $from_key, $to_key ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $input[ $key ] );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $input[ $key ] ) {
				return new WP_Error( 'ur_abilities_date_range', __( 'Use valid dates with the start date on or before the end date.', 'user-registration' ), array( 'status' => 400 ) );
			}
			$dates[] = $input[ $key ];
		}
		if ( 2 === count( $dates ) && $dates[0] > $dates[1] ) {
			return new WP_Error( 'ur_abilities_date_range', __( 'Use valid dates with the start date on or before the end date.', 'user-registration' ), array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * Describe what assigning a plan would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_assign_membership( $input ) {
		$request = self::assignment( $input );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		return sprintf(
			/* translators: 1: plan name, 2: username, 3: email address, 4: end of access. */
			__( 'Give the %1$s plan to %2$s (%3$s), active from today %4$s. No payment is taken and no payment gateway is contacted. It is added like the Add Subscription screen does, with a manual order.', 'user-registration' ),
			$request['plan'],
			$request['user']->user_login,
			$request['user']->user_email,
			$request['form']['expiry_date']
				/* translators: %s: date. */
				? sprintf( __( 'until %s', 'user-registration' ), $request['form']['expiry_date'] )
				: __( 'with no end date', 'user-registration' )
		);
	}

	/**
	 * Assign a plan through the same code as the Add Subscription screen.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function assign_membership( $input ) {
		$request = self::assignment( $input );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$result = ( new \WPEverest\URMembership\Admin\Services\SubscriptionService() )->create_admin_subscription( $request['form'] );

		return is_wp_error( $result ) ? $result : self::subscription_by_id( $result['subscription']['ID'] );
	}

	/**
	 * Describe what extending a subscription would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_extend_subscription( $input ) {
		$subscription = self::extendable( $input );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		return sprintf(
			/* translators: 1: subscription ID, 2: plan name, 3: email address, 4: current expiry date, 5: new expiry date. */
			__( 'Move the end of subscription #%1$d (%2$s, %3$s) from %4$s to %5$s, like editing the expiry date on the Subscriptions screen. No payment gateway is contacted.', 'user-registration' ),
			$subscription['id'],
			$subscription['membership_name'],
			$subscription['member_email'],
			gmdate( 'Y-m-d', strtotime( $subscription['expiry_date'] ) ),
			$input['expiry_date']
		);
	}

	/**
	 * Extend a subscription through the same code as the Edit Subscription screen.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function extend_subscription( $input ) {
		$subscription = self::extendable( $input );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$result = ( new \WPEverest\URMembership\Admin\Services\SubscriptionService() )->update_admin_subscription( $input['id'], array( 'expiry_date' => $input['expiry_date'] ) );

		return is_wp_error( $result ) ? $result : self::subscription_by_id( $input['id'] );
	}

	/**
	 * Describe what cancelling a subscription would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_cancel_subscription( $input ) {
		$subscription = self::cancellable( $input );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$order   = ( new \WPEverest\URMembership\Admin\Repositories\OrdersRepository() )->get_order_by_subscription( $input['id'] );
		$gateway = (string) ( $order['payment_method'] ?? '' );
		$expiry  = $subscription['expiry_date'] ? strtotime( $subscription['expiry_date'] ) : 0;

		// Matches SubscriptionRepository::cancel_subscription_by_id(): one-time, free and gateway-less orders end at once.
		if ( '' === $gateway || in_array( $order['order_type'] ?? '', array( 'free', 'paid' ), true ) ) {
			$when = __( 'Access ends now.', 'user-registration' );
		} elseif ( $expiry > time() ) {
			/* translators: %s: expiry date. */
			$when = sprintf( __( 'Billing stops and access lasts until %s.', 'user-registration' ), gmdate( 'Y-m-d', $expiry ) );
		} else {
			$when = __( 'Billing stops and access ends now.', 'user-registration' );
		}

		$contact = in_array( $gateway, array( '', 'bank', 'manual', 'free' ), true )
			? __( 'No payment gateway is contacted.', 'user-registration' )
			/* translators: %s: payment gateway, for example stripe. */
			: sprintf( __( 'The subscription is also cancelled at %s.', 'user-registration' ), $gateway );

		return sprintf(
			/* translators: 1: subscription ID, 2: plan name, 3: email address, 4: when access ends, 5: whether a gateway is contacted. */
			__( 'Cancel subscription #%1$d (%2$s, %3$s), the same way members cancel their own. %4$s %5$s The cancellation email is sent if it is turned on.', 'user-registration' ),
			$subscription['id'],
			$subscription['membership_name'],
			$subscription['member_email'],
			$when,
			$contact
		);
	}

	/**
	 * Cancel a subscription through the same code members use to cancel their own.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function cancel_subscription( $input ) {
		$subscription = self::cancellable( $input );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		try {
			$result = ( new \WPEverest\URMembership\Admin\Repositories\SubscriptionRepository() )->cancel_subscription_by_id( $input['id'] );
		} catch ( Exception $e ) {
			$result = array(
				'status'  => false,
				'message' => $e->getMessage(),
			);
		}

		if ( empty( $result['status'] ) ) {
			return new WP_Error( 'ur_abilities_cancel_failed', wp_strip_all_tags( $result['message'] ?? __( 'The subscription could not be cancelled.', 'user-registration' ) ), array( 'status' => 502 ) );
		}

		return self::subscription_by_id( $input['id'] );
	}

	/**
	 * Describe what deleting a member would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_delete_member( $input ) {
		$request = self::member_deletion( $input );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		if ( ! $request['has_content'] ) {
			$content = __( 'They own no posts or links.', 'user-registration' );
		} elseif ( $request['reassign'] ) {
			/* translators: %s: username. */
			$content = sprintf( __( 'Their posts and links go to %s.', 'user-registration' ), get_userdata( $request['reassign'] )->user_login );
		} else {
			$content = __( 'Their posts and links are deleted too.', 'user-registration' );
		}

		return sprintf(
			/* translators: 1: username, 2: email address, 3: what happens to their content. */
			__( 'Permanently delete %1$s (%2$s), like Delete on the Users screen. %3$s On a multisite network they are only removed from this site. This cannot be undone.', 'user-registration' ),
			$request['user']->user_login,
			$request['user']->user_email,
			$content
		);
	}

	/**
	 * Delete a member through the same code as the Users screen.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function delete_member( $input ) {
		$request = self::member_deletion( $input );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$deleted = ( new UR_Admin_User_Manager( $request['user'] ) )->delete( $request['reassign'] );

		return is_wp_error( $deleted ) ? $deleted : self::deleted( $input['id'], __( 'Member deleted.', 'user-registration' ) );
	}

	/**
	 * Describe what deleting a plan would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_delete_membership( $input ) {
		$plan = self::deletable_plan( $input );

		return is_wp_error( $plan ) ? $plan : sprintf(
			/* translators: %s: plan name. */
			__( 'Permanently delete the %s plan, like Delete on the Memberships screen. Past subscriptions and payments stay. This cannot be undone.', 'user-registration' ),
			wp_strip_all_tags( $plan->post_title )
		);
	}

	/**
	 * Delete a plan through the same code as the Memberships screen.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function delete_membership( $input ) {
		$plan = self::deletable_plan( $input );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$result = ( new \WPEverest\URMembership\Admin\Services\MembershipService() )->delete_membership( $plan->ID );

		return empty( $result['status'] )
			? new WP_Error( 'ur_abilities_delete_failed', wp_strip_all_tags( $result['message'] ?? '' ), array( 'status' => 409 ) )
			: self::deleted( $plan->ID, __( 'Plan deleted.', 'user-registration' ) );
	}

	/**
	 * Describe what deleting a plan group would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_delete_membership_group( $input ) {
		$group = self::deletable_group( $input );

		return is_wp_error( $group ) ? $group : sprintf(
			/* translators: %s: group name. */
			__( 'Permanently delete the %s plan group, like Delete on the Membership Groups screen. Its plans are kept. This cannot be undone.', 'user-registration' ),
			wp_strip_all_tags( $group->post_title )
		);
	}

	/**
	 * Delete a plan group through the same code as the Membership Groups screen.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function delete_membership_group( $input ) {
		$group = self::deletable_group( $input );

		if ( is_wp_error( $group ) ) {
			return $group;
		}

		return ( new \WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository() )->delete( $group->ID )
			? self::deleted( $group->ID, __( 'Plan group deleted.', 'user-registration' ) )
			: new WP_Error( 'ur_abilities_delete_failed', __( 'Sorry! There was an unexpected error while deleting the membership group.', 'user-registration' ), array( 'status' => 500 ) );
	}

	/**
	 * Describe what deleting a subscription would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_delete_subscription( $input ) {
		$subscription = self::deletable_subscription( $input );

		return is_wp_error( $subscription ) ? $subscription : sprintf(
			/* translators: 1: subscription ID, 2: plan name, 3: email address. */
			__( 'Permanently delete subscription #%1$d (%2$s, %3$s), like Delete on the Subscriptions screen. If it is still billing, it is cancelled at the payment gateway right away and access ends now. The member is not emailed. This cannot be undone.', 'user-registration' ),
			$subscription['id'],
			$subscription['membership_name'],
			$subscription['member_email']
		);
	}

	/**
	 * Delete a subscription through the same code as the Subscriptions screen.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function delete_subscription( $input ) {
		$subscription = self::deletable_subscription( $input );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$still_billing = ( new \WPEverest\URMembership\Admin\Repositories\SubscriptionRepository() )->delete_and_cancel_at_gateway( $input['id'] );

		return self::deleted(
			$input['id'],
			$still_billing
				? __( 'Subscription deleted, but it could not be cancelled at its payment gateway. It may still be billing, so cancel it in the gateway dashboard.', 'user-registration' )
				: __( 'Subscription deleted.', 'user-registration' )
		);
	}

	/**
	 * Check a member delete request: who, whether the current user may, and what happens to their content.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error User, whether they own content, and who gets it.
	 */
	private static function member_deletion( $input ) {
		$query = new WP_User_Query(
			self::member_query( array() ) + array(
				'include' => array( $input['id'] ),
				'number'  => 1,
			)
		);
		$users = $query->get_results();

		if ( ! $users ) {
			return self::not_found();
		}

		if ( ! ( new UR_Admin_User_Manager( $users[0] ) )->can_be_deleted() ) {
			return new WP_Error( 'ur_abilities_forbidden', __( 'You cannot delete this member, for example your own account.', 'user-registration' ), array( 'status' => 403 ) );
		}

		$has_content = UR_Admin_User_Manager::users_have_content( array( $users[0]->ID ) );
		$reassign    = $has_content && ! empty( $input['content_to'] ) ? (int) $input['content_to'] : null;

		if ( $has_content && ! $reassign && empty( $input['delete_content'] ) ) {
			return new WP_Error( 'ur_abilities_content_choice', __( 'This member owns posts or links. Give them to another user with content_to, or set delete_content to true to delete them.', 'user-registration' ), array( 'status' => 400 ) );
		}

		if ( $reassign && ( $reassign === $users[0]->ID || ! get_userdata( $reassign ) ) ) {
			return new WP_Error( 'ur_abilities_invalid_user', __( 'content_to must be another existing user.', 'user-registration' ), array( 'status' => 400 ) );
		}

		return array(
			'user'        => $users[0],
			'has_content' => $has_content,
			'reassign'    => $reassign,
		);
	}

	/**
	 * Find a plan that can be deleted now.
	 *
	 * @param array $input Validated input.
	 * @return WP_Post|WP_Error
	 */
	private static function deletable_plan( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}

		$plan = get_post( $input['id'] );

		if ( ! $plan || 'ur_membership' !== $plan->post_type ) {
			return self::not_found();
		}

		$meta    = json_decode( wp_unslash( get_post_meta( $plan->ID, 'ur_membership', true ) ), true );
		$service = new \WPEverest\URMembership\Admin\Services\MembershipService();

		if ( ! $service::is_plan_type_available( $meta['type'] ?? '' ) ) {
			return self::pro_only();
		}

		$blocking = $service->count_delete_blocking_members( $plan->ID );

		if ( $blocking ) {
			/* translators: %d: number of members. */
			return new WP_Error( 'ur_abilities_in_use', sprintf( _n( '%d member has an active, pending or trial subscription to this plan. Expire, cancel or move them first.', '%d members have an active, pending or trial subscription to this plan. Expire, cancel or move them first.', $blocking, 'user-registration' ), $blocking ), array( 'status' => 409 ) );
		}

		return $plan;
	}

	/**
	 * Find a plan group that can be deleted now.
	 *
	 * @param array $input Validated input.
	 * @return WP_Post|WP_Error
	 */
	private static function deletable_group( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}

		if ( ! \WPEverest\URMembership\Admin\Services\MembershipGroupService::is_available() ) {
			return new WP_Error( 'ur_ability_plan_required', __( 'Membership groups need Pro with a Plus or higher plan and the Membership Groups module turned on.', 'user-registration' ), array( 'status' => 403 ) );
		}

		$group = get_post( $input['id'] );

		if ( ! $group || 'ur_membership_groups' !== $group->post_type ) {
			return self::not_found();
		}

		$form_id = ( new \WPEverest\URMembership\Admin\Services\MembershipGroupService() )->get_group_form_id( $group->ID );

		if ( '' != $form_id ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Same check as the Membership Groups screen.
			/* translators: %s: form ID. */
			return new WP_Error( 'ur_abilities_in_use', sprintf( __( 'This group is used in registration form #%s. Remove it from the form first.', 'user-registration' ), $form_id ), array( 'status' => 409 ) );
		}

		return $group;
	}

	/**
	 * Find a subscription that can be deleted.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	private static function deletable_subscription( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}

		$subscription = self::subscription_by_id( $input['id'] );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$meta = json_decode( wp_unslash( get_post_meta( $subscription['membership_id'], 'ur_membership', true ) ), true );

		return \WPEverest\URMembership\Admin\Services\MembershipService::is_plan_type_available( $meta['type'] ?? '' ) ? $subscription : self::pro_only();
	}

	/**
	 * Answer for a finished delete.
	 *
	 * @param int    $id      ID of what was deleted.
	 * @param string $message Plain sentence about the result.
	 * @return array
	 */
	private static function deleted( $id, $message ) {
		return array(
			'id'      => (int) $id,
			'deleted' => true,
			'message' => $message,
		);
	}

	/**
	 * Check an assignment request and build the Add Subscription form fields for it.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error User, plan name and form fields.
	 */
	private static function assignment( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}

		$user = get_userdata( $input['member_id'] );
		$plan = get_post( $input['membership_id'] );
		$meta = $plan ? json_decode( wp_unslash( get_post_meta( $plan->ID, 'ur_membership', true ) ), true ) : array();

		if ( ! $user || ! $plan || 'ur_membership' !== $plan->post_type || 'publish' !== $plan->post_status || empty( $meta ) ) {
			return self::not_found();
		}

		if ( ! \WPEverest\URMembership\Admin\Services\MembershipService::is_plan_type_available( $meta['type'] ?? '' ) ) {
			return self::pro_only();
		}

		$existing = ( new \WPEverest\URMembership\Admin\Repositories\SubscriptionRepository() )->query(
			array(
				'user_id'  => $user->ID,
				'item_id'  => $plan->ID,
				'per_page' => 100,
			)
		);

		foreach ( $existing['items'] as $subscription ) {
			if ( in_array( $subscription->status, array( 'active', 'trial', 'pending' ), true ) ) {
				return new WP_Error( 'ur_abilities_no_change', __( 'This member already has this plan. Extend the subscription instead.', 'user-registration' ), array( 'status' => 409 ) );
			}
		}

		$expiry = '';

		if ( 'subscription' === ( $meta['type'] ?? '' ) ) {
			$expiry = ! empty( $input['expiry_date'] ) ? $input['expiry_date'] : \WPEverest\URMembership\Admin\Services\SubscriptionService::get_expiry_date( current_time( 'Y-m-d' ), $meta['subscription']['duration'] ?? '', $meta['subscription']['value'] ?? 1 );

			if ( ! $expiry || $expiry <= current_time( 'Y-m-d' ) ) {
				return new WP_Error( 'ur_abilities_invalid_date', __( 'Give an expiry date after today for this recurring plan.', 'user-registration' ), array( 'status' => 400 ) );
			}
		}

		return array(
			'user' => $user,
			'plan' => wp_strip_all_tags( $plan->post_title ),
			'form' => array(
				'member'          => $user->ID,
				'plan'            => $plan->ID,
				'status'          => 'active',
				'payment_gateway' => 'manual',
				'expiry_date'     => $expiry,
			),
		);
	}

	/**
	 * Find a subscription whose end date may be moved without a payment gateway billing on the old date.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	private static function extendable( $input ) {
		$subscription = self::changeable_subscription( $input['id'] );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		// Lifetime plans store an empty or all-zero expiry date.
		if ( strtotime( $subscription['expiry_date'] ) <= 0 ) {
			return new WP_Error( 'ur_abilities_no_change', __( 'This subscription has no end date, so it cannot be extended.', 'user-registration' ), array( 'status' => 409 ) );
		}

		$order   = ( new \WPEverest\URMembership\Admin\Repositories\OrdersRepository() )->get_order_by_subscription( $input['id'] );
		$gateway = (string) ( $order['payment_method'] ?? '' );

		if ( ! in_array( $gateway, array( '', 'bank', 'manual', 'free' ), true ) ) {
			/* translators: %s: payment gateway, for example stripe. */
			return new WP_Error( 'ur_abilities_gateway_billed', sprintf( __( 'This subscription is billed by %s, which would still charge on the old date. Change it in the payment gateway instead.', 'user-registration' ), $gateway ), array( 'status' => 409 ) );
		}

		if ( $input['expiry_date'] <= gmdate( 'Y-m-d', strtotime( $subscription['expiry_date'] ) ) ) {
			return new WP_Error( 'ur_abilities_invalid_date', __( 'The new expiry date must be later than the current one.', 'user-registration' ), array( 'status' => 400 ) );
		}

		return $subscription;
	}

	/**
	 * Find a subscription that may be cancelled.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	private static function cancellable( $input ) {
		return self::changeable_subscription( $input['id'] );
	}

	/**
	 * Find a live subscription that is not already set to cancel.
	 *
	 * @param int $id Subscription ID.
	 * @return array|WP_Error
	 */
	private static function changeable_subscription( $id ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}

		$subscription = self::subscription_by_id( $id );

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$meta = json_decode( wp_unslash( get_post_meta( $subscription['membership_id'], 'ur_membership', true ) ), true );

		if ( ! \WPEverest\URMembership\Admin\Services\MembershipService::is_plan_type_available( $meta['type'] ?? '' ) ) {
			return self::pro_only();
		}

		if ( ! in_array( $subscription['status'], array( 'active', 'trial' ), true ) ) {
			/* translators: %s: subscription status. */
			return new WP_Error( 'ur_abilities_no_change', sprintf( __( 'This subscription is %s, so it cannot be changed.', 'user-registration' ), $subscription['status'] ), array( 'status' => 409 ) );
		}

		if ( get_user_meta( $subscription['member_id'], 'urm_pending_cancel_' . $id, true ) ) {
			return new WP_Error( 'ur_abilities_no_change', __( 'This subscription is already set to cancel.', 'user-registration' ), array( 'status' => 409 ) );
		}

		return $subscription;
	}

	/**
	 * Read one subscription in the shape the subscription abilities return.
	 *
	 * @param int $id Subscription ID.
	 * @return array|WP_Error
	 */
	private static function subscription_by_id( $id ) {
		$row = ( new \WPEverest\URMembership\Admin\Repositories\SubscriptionRepository() )->retrieve( $id );

		return $row ? self::subscription( (object) $row ) : self::not_found();
	}

	/**
	 * Serialize only documented subscription fields.
	 *
	 * @param object $subscription Subscription row.
	 * @return array
	 */
	private static function subscription( $subscription ) {
		$user = get_userdata( $subscription->user_id );

		return array(
			'id'                => (int) $subscription->ID,
			'member_id'         => (int) $subscription->user_id,
			'member_email'      => $user ? $user->user_email : '',
			'membership_id'     => (int) $subscription->item_id,
			'membership_name'   => wp_strip_all_tags( get_the_title( $subscription->item_id ) ),
			'status'            => (string) $subscription->status,
			'start_date'        => (string) $subscription->start_date,
			'expiry_date'       => (string) $subscription->expiry_date,
			'next_billing_date' => (string) $subscription->next_billing_date,
			'billing_amount'    => (string) $subscription->billing_amount,
		);
	}

	/**
	 * Whether the membership module and its repositories are loaded.
	 *
	 * @return bool
	 */
	private static function membership_ready() {
		return ur_check_module_activation( 'membership' ) && class_exists( '\WPEverest\URMembership\Admin\Repositories\MembersRepository' );
	}

	/**
	 * Return a uniform error when membership is turned off.
	 *
	 * @return WP_Error
	 */
	private static function membership_unavailable() {
		return new WP_Error( 'ur_abilities_membership_disabled', __( 'Membership is not enabled on this site.', 'user-registration' ), array( 'status' => 403 ) );
	}

	/**
	 * Return the same lock the plan editor shows for a Pro-only plan type.
	 *
	 * @return WP_Error
	 */
	private static function pro_only() {
		return new WP_Error(
			'ur_ability_plan_required',
			__( 'Subscription type is a paid feature.', 'user-registration' ),
			array(
				'status'      => 403,
				'upgrade_url' => ur_utm_url(
					'https://wpuserregistration.com/upgrade/',
					array(
						'source'  => 'ai-abilities',
						'medium'  => 'button',
						'content' => 'subscription-type',
					)
				),
			)
		);
	}

	/**
	 * Return a uniform missing-record error.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'ur_abilities_not_found', __( 'Registration record not found.', 'user-registration' ), array( 'status' => 404 ) );
	}
}
