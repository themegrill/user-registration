<?php
/**
 * Allowlisted read models for the native abilities API.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Read-only callbacks. Input validation and authorization belong to WP_Ability. */
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
			$filters['email_verified'] = $input['email_verified'];
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
		return new WP_Error( 'ur_abilities_member_limit', __( 'More than 10000 members to filter. Add a form_id to narrow the list.', 'user-registration' ) );
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
		$plans            = array_slice( array_reverse( $repository->get_all_memberships_without_status_filter() ), ( $page['page'] - 1 ) * $page['per_page'], $page['per_page'] + 1 );
		$page['has_more'] = count( $plans ) > $page['per_page'];
		foreach ( array_slice( $plans, 0, $page['per_page'] ) as $plan ) {
			$meta            = is_array( $plan['meta_value'] ) ? $plan['meta_value'] : array();
			$type            = isset( $meta['type'] ) ? (string) $meta['type'] : '';
			$billing         = 'subscription' === $type && isset( $meta['subscription']['value'], $meta['subscription']['duration'] );
			$members         = $repository->check_deletable_membership( $plan['ID'], array( 'active' ) );
			$page['items'][] = array(
				'id'               => (int) $plan['ID'],
				'name'             => wp_strip_all_tags( $plan['post_title'] ),
				'enabled'          => 'publish' === $plan['post_status'] && ! empty( $plan['post_content']['status'] ),
				'type'             => $type,
				'amount'           => isset( $meta['amount'] ) ? (string) $meta['amount'] : '0',
				'currency'         => $currency,
				'billing_interval' => $billing ? (int) $meta['subscription']['value'] : null,
				'billing_period'   => $billing ? (string) $meta['subscription']['duration'] : null,
				'active_members'   => isset( $members['total'] ) ? (int) $members['total'] : 0,
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
		foreach ( $result['items'] as $subscription ) {
			$user            = get_userdata( $subscription->user_id );
			$page['items'][] = array(
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
		$repository       = new \WPEverest\URMembership\Admin\Repositories\OrdersRepository();
		$orders           = $repository->get_all( $args );
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
			return new WP_Error( 'ur_abilities_date_range', __( 'Use valid dates in ascending order spanning at most 93 days.', 'user-registration' ) );
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
			return new WP_Error( 'ur_abilities_stats_limit', __( 'More than 10000 registrations match. Narrow the dates or specify a form.', 'user-registration' ) );
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
			'page'     => isset( $input['page'] ) ? $input['page'] : 1,
			'per_page' => isset( $input['per_page'] ) ? $input['per_page'] : 20,
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
			'created_at'      => (string) $order['created_at'],
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
		return new WP_Error( 'ur_abilities_membership_disabled', __( 'Membership is not enabled on this site.', 'user-registration' ) );
	}

	/**
	 * Return a uniform missing-record error.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'ur_abilities_not_found', __( 'Registration record not found.', 'user-registration' ) );
	}
}
