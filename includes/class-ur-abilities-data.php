<?php
/**
 * Allowlisted read models for the native abilities API.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Ability callbacks. Input validation and authorization belong to WP_Ability. */
class UR_Abilities_Data {
	/** Form meta that marks a form an AI assistant created, holding the UTC time it was made. */
	const AI_CREATED_META = '_ur_created_by_ai';

	/** Setting field types AI assistants may change. */
	const SETTING_TYPES = array( 'text', 'textarea', 'email', 'number', 'select', 'radio-group', 'multiselect', 'toggle', 'checkbox', 'single_select_page', 'color', 'tinymce' );

	/** Settings tabs that are never changed by AI: the license, payments (where money goes) and tools that act on the whole site at once. */
	const LOCKED_SETTING_TABS = array( 'license', 'payment', 'import_export', 'tools' );

	/** Settings that may change without a preview. Empty on purpose: every other setting shows a preview first. */
	const SAFE_SETTINGS = array();

	/** Settings that can lock people out or open the site up, so a change always shows a preview first. */
	const RISKY_SETTINGS = array(
		'user_registration_login_options_prevent_core_login',
		'user_registration_login_options_login_redirect_url',
		'user_registration_general_setting_disabled_user_roles',
		'user_registration_general_setting_login_options_with',
		'user_registration_content_restriction_whole_site_access',
		'user_registration_content_restriction_allow_access_to',
		'user_registration_content_restriction_allow_to_roles',
	);

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
		// Rows of columns of field names, as the form builder lays them out.
		$result['layout'] = array();
		$rows             = json_decode( $post->post_content );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$columns = array();
			foreach ( (array) $row as $column ) {
				$names = array();
				foreach ( (array) $column as $field ) {
					if ( isset( $field->general_setting->field_name ) ) {
						$names[] = (string) $field->general_setting->field_name;
					}
				}
				$columns[] = $names;
			}
			$result['layout'][] = $columns;
		}
		return $result;
	}

	/**
	 * Lay a form's fields out in rows and columns and save it through the same code as the form builder.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function set_form_layout( $input ) {
		$form = self::form_layout( $input['form_id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		// Row settings such as repeater rows or row conditions are tied to row numbers, which a new layout changes.
		$row_data = json_decode( $form['row_data'], true );

		if ( ! empty( $row_data ) ) {
			return new WP_Error( 'ur_abilities_invalid_form', __( 'This form has rows with their own settings, such as repeater rows or row conditions. Change its layout in the form builder.', 'user-registration' ), array( 'status' => 409 ) );
		}

		$fields = array();

		foreach ( $form['rows'] as $row ) {
			foreach ( (array) $row as $column ) {
				foreach ( (array) $column as $field ) {
					if ( isset( $field->general_setting->field_name ) ) {
						$fields[ $field->general_setting->field_name ] = $field;
					}
				}
			}
		}

		$rows   = array();
		$placed = array();

		foreach ( $input['rows'] as $row ) {
			$columns = array();

			foreach ( $row as $column ) {
				$list = array();

				foreach ( $column as $name ) {
					if ( ! isset( $fields[ $name ] ) ) {
						return self::field_not_found();
					}

					if ( isset( $placed[ $name ] ) ) {
						/* translators: %s: field name. */
						return new WP_Error( 'ur_abilities_invalid_input', sprintf( __( 'The %s field is placed more than once. Each field goes in one place.', 'user-registration' ), $name ), array( 'status' => 400 ) );
					}

					$placed[ $name ] = true;
					$list[]          = $fields[ $name ];
				}

				$columns[] = $list;
			}

			$rows[] = $columns;
		}

		$missing = array_diff( array_keys( $fields ), array_keys( $placed ) );

		if ( $missing ) {
			/* translators: %s: list of field names. */
			return new WP_Error( 'ur_abilities_invalid_input', sprintf( __( 'Place every field of the form. Missing: %s. To drop a field, remove it first.', 'user-registration' ), implode( ', ', $missing ) ), array( 'status' => 400 ) );
		}

		$form['rows']    = $rows;
		$form['row_ids'] = wp_json_encode( array_map( 'strval', array_keys( $rows ) ) );

		return self::save_form_layout( $form );
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

		// Like approve and deny, never remove an administrator: that could lock everyone out of the site.
		if ( user_can( $users[0], 'manage_options' ) ) {
			return new WP_Error( 'ur_abilities_forbidden', __( 'You cannot delete an administrator.', 'user-registration' ), array( 'status' => 403 ) );
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
	 * Read one membership plan in full.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_membership( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}

		$plan = self::plan_post( $input['id'] );

		return is_wp_error( $plan ) ? $plan : self::plan_detail( $plan );
	}

	/**
	 * Describe the plan a create request would make.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_create_membership( $input ) {
		$values = self::plan_values( $input, null );

		if ( is_wp_error( $values ) ) {
			return $values;
		}

		$text = sprintf(
			/* translators: 1: plan name, 2: what it costs, 3: on or off. */
			__( 'Create a new membership plan called "%1$s" (%2$s), %3$s, like Add New on the Memberships screen.', 'user-registration' ),
			$values['name'],
			self::plan_price_text( $values ),
			$values['enabled'] ? __( 'turned on', 'user-registration' ) : __( 'turned off', 'user-registration' )
		);

		// Only a plan that costs something is created in Stripe, and only when Stripe is connected.
		if ( 'free' !== $values['type'] ) {
			$text .= ' ' . ( function_exists( 'urm_is_payment_gateway_configured' ) && urm_is_payment_gateway_configured( 'stripe' )
				? __( 'Stripe is connected, so the plan is created there too.', 'user-registration' )
				: __( 'The payment gateways for the plan are chosen on the plan screen.', 'user-registration' ) );
		}

		return $text;
	}

	/**
	 * Create a membership plan through the same code as the plan editor.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function create_membership( $input ) {
		$values = self::plan_values( $input, null );

		if ( is_wp_error( $values ) ) {
			return $values;
		}

		$result = ( new \WPEverest\URMembership\Admin\Services\MembershipService() )->create_membership_plan( self::plan_editor_data( $values, null ) );

		if ( empty( $result['status'] ) ) {
			return new WP_Error( 'ur_abilities_create_failed', wp_strip_all_tags( $result['message'] ?? '' ), array( 'status' => 400 ) );
		}

		return self::plan_detail( get_post( $result['membership_id'] ) );
	}

	/**
	 * Describe what a change to a plan would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_update_membership( $input ) {
		$change = self::plan_change( $input );

		if ( is_wp_error( $change ) ) {
			return $change;
		}

		return sprintf(
			/* translators: 1: plan name, 2: the list of changes. */
			__( 'Change the plan "%1$s", like Save on the plan screen: %2$s.', 'user-registration' ),
			$change['plan']->post_title,
			implode( '; ', $change['changes'] )
		);
	}

	/**
	 * Change a membership plan through the same code as the plan editor.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function update_membership( $input ) {
		$change = self::plan_change( $input );

		if ( is_wp_error( $change ) ) {
			return $change;
		}

		$result = ( new \WPEverest\URMembership\Admin\Services\MembershipService() )->update_membership_plan( self::plan_editor_data( $change['values'], $change['plan'] ) );

		if ( empty( $result['status'] ) ) {
			return new WP_Error( 'ur_abilities_update_failed', wp_strip_all_tags( $result['message'] ?? '' ), array( 'status' => 400 ) );
		}

		clean_post_cache( $change['plan']->ID );

		return self::plan_detail( get_post( $change['plan']->ID ) );
	}

	/**
	 * List plan groups, newest first.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function list_membership_groups( $input ) {
		$ready = self::groups_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$page             = self::page( $input );
		$groups           = get_posts(
			array(
				'post_type'      => 'ur_membership_groups',
				'post_status'    => array( 'publish', 'draft' ),
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'posts_per_page' => $page['per_page'] + 1,
				'paged'          => $page['page'],
				'no_found_rows'  => true,
			)
		);
		$page['has_more'] = count( $groups ) > $page['per_page'];

		foreach ( array_slice( $groups, 0, $page['per_page'] ) as $group ) {
			$page['items'][] = self::group_detail( $group );
		}

		return $page;
	}

	/**
	 * Describe the plan group a create request would make.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_create_membership_group( $input ) {
		$values = self::group_values( $input, null );

		if ( is_wp_error( $values ) ) {
			return $values;
		}

		return sprintf(
			/* translators: 1: group name, 2: the plan names, 3: the mode. */
			__( 'Create a new plan group called "%1$s" holding %2$s, where members may hold %3$s, like Add New on the Membership Groups screen.', 'user-registration' ),
			$values['name'],
			self::plan_names( $values['plan_ids'] ),
			'multiple' === $values['mode'] ? __( 'several of its plans at once', 'user-registration' ) : __( 'one of its plans', 'user-registration' )
		);
	}

	/**
	 * Create a plan group through the same code as the Membership Groups screen.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function create_membership_group( $input ) {
		$values = self::group_values( $input, null );

		return is_wp_error( $values ) ? $values : self::save_group( $values, null );
	}

	/**
	 * Describe what a change to a plan group would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_update_membership_group( $input ) {
		$change = self::group_change( $input );

		if ( is_wp_error( $change ) ) {
			return $change;
		}

		return sprintf(
			/* translators: 1: group name, 2: the list of changes. */
			__( 'Change the plan group "%1$s", like Save on the Membership Groups screen: %2$s.', 'user-registration' ),
			$change['group']->post_title,
			implode( '; ', $change['changes'] )
		);
	}

	/**
	 * Change a plan group through the same code as the Membership Groups screen.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function update_membership_group( $input ) {
		$change = self::group_change( $input );

		return is_wp_error( $change ) ? $change : self::save_group( $change['values'], $change['group'] );
	}

	/**
	 * Find a plan that is not in the trash.
	 *
	 * @param int $id Plan ID.
	 * @return WP_Post|WP_Error
	 */
	private static function plan_post( $id ) {
		$plan = get_post( $id );

		return ( ! $plan || 'ur_membership' !== $plan->post_type || 'trash' === $plan->post_status ) ? self::not_found() : $plan;
	}

	/**
	 * Decode a plan's settings.
	 *
	 * @param WP_Post $plan Plan.
	 * @return array
	 */
	private static function plan_meta( $plan ) {
		$meta = json_decode( wp_unslash( get_post_meta( $plan->ID, 'ur_membership', true ) ), true );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Describe a plan with the same fields the plan screen has, minus the payment keys.
	 *
	 * @param WP_Post $plan Plan.
	 * @return array
	 */
	private static function plan_detail( $plan ) {
		$meta       = self::plan_meta( $plan );
		$content    = json_decode( wp_unslash( $plan->post_content ), true );
		$type       = isset( $meta['type'] ) ? (string) $meta['type'] : '';
		$billing    = 'subscription' === $type && isset( $meta['subscription']['value'], $meta['subscription']['duration'] );
		$trial      = $billing && 'on' === ( $meta['trial_status'] ?? '' ) && isset( $meta['trial_data']['value'], $meta['trial_data']['duration'] );
		$members    = ( new \WPEverest\URMembership\Admin\Repositories\MembershipRepository() )->count_members_by_membership( 'active' );
		$gateways   = array();
		$gateway_on = isset( $meta['payment_gateways'] ) && is_array( $meta['payment_gateways'] ) ? $meta['payment_gateways'] : array();

		foreach ( $gateway_on as $key => $gateway ) {
			if ( is_array( $gateway ) && 'on' === ( $gateway['status'] ?? '' ) ) {
				$gateways[] = (string) $key;
			}
		}

		return array(
			'id'               => (int) $plan->ID,
			'name'             => wp_strip_all_tags( $plan->post_title ),
			'description'      => wp_strip_all_tags( (string) get_post_meta( $plan->ID, 'ur_membership_description', true ) ),
			'enabled'          => 'publish' === $plan->post_status && ! empty( $content['status'] ),
			'type'             => $type,
			'amount'           => isset( $meta['amount'] ) ? (string) $meta['amount'] : '0',
			'currency'         => get_option( 'user_registration_payment_currency', 'USD' ),
			'billing_interval' => $billing ? (int) $meta['subscription']['value'] : null,
			'billing_period'   => $billing ? (string) $meta['subscription']['duration'] : null,
			'trial_interval'   => $trial ? (int) $meta['trial_data']['value'] : null,
			'trial_period'     => $trial ? (string) $meta['trial_data']['duration'] : null,
			'role'             => isset( $meta['role'] ) ? (string) $meta['role'] : '',
			'payment_gateways' => $gateways,
			'active_members'   => isset( $members[ $plan->ID ] ) ? (int) $members[ $plan->ID ] : 0,
		);
	}

	/**
	 * Check the plan fields a request gives, on top of the plan it changes, and settle them into one set of values.
	 *
	 * The limits are the plan editor's own: a paid plan needs a price, a subscription needs a billing period, a trial
	 * belongs to a subscription, and a recurring plan needs Pro.
	 *
	 * @param array        $input   Validated input.
	 * @param WP_Post|null $current The plan being changed, or null when making a new one.
	 * @return array|WP_Error
	 */
	private static function plan_values( $input, $current ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}

		$values = $current ? self::plan_detail( $current ) : array(
			'name'             => '',
			'description'      => '',
			'enabled'          => true,
			'type'             => '',
			'amount'           => '0',
			'billing_interval' => null,
			'billing_period'   => null,
			'trial_interval'   => null,
			'trial_period'     => null,
			'role'             => 'subscriber',
		);

		foreach ( array( 'name', 'description', 'enabled', 'type', 'amount', 'billing_interval', 'billing_period', 'trial_interval', 'trial_period', 'role' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$values[ $field ] = $input[ $field ];
			}
		}

		$values['name']        = trim( sanitize_text_field( (string) $values['name'] ) );
		$values['description'] = (string) $values['description'];
		$values['enabled']     = (bool) $values['enabled'];
		$values['role']        = '' === (string) $values['role'] ? 'subscriber' : sanitize_key( (string) $values['role'] );

		if ( '' === $values['name'] ) {
			return self::invalid_plan( __( 'A plan needs a name.', 'user-registration' ) );
		}

		if ( ! in_array( $values['type'], array( 'free', 'paid', 'subscription' ), true ) ) {
			return self::invalid_plan( __( 'The type must be free, paid or subscription.', 'user-registration' ) );
		}

		if ( ! \WPEverest\URMembership\Admin\Services\MembershipService::is_plan_type_available( $values['type'] ) ) {
			return self::pro_only();
		}

		if ( 'free' === $values['type'] ) {
			$values['amount'] = '0';
		} else {
			$amount = (float) $values['amount'];

			if ( $amount <= 0 ) {
				return self::invalid_plan( __( 'A paid plan needs a price above 0.', 'user-registration' ) );
			}

			$values['amount'] = rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' );
		}

		if ( 'subscription' === $values['type'] ) {
			if ( (int) $values['billing_interval'] < 1 || ! in_array( $values['billing_period'], UR_Abilities::PLAN_PERIODS, true ) ) {
				return self::invalid_plan( __( 'A subscription needs a billing interval of 1 or more and a billing period: day, week, month or year.', 'user-registration' ) );
			}

			$values['billing_interval'] = (int) $values['billing_interval'];

			$has_trial = null !== $values['trial_interval'] || null !== $values['trial_period'];

			if ( $has_trial && ( (int) $values['trial_interval'] < 1 || ! in_array( $values['trial_period'], UR_Abilities::PLAN_PERIODS, true ) ) ) {
				return self::invalid_plan( __( 'A free trial needs a length of 1 or more and a period: day, week, month or year.', 'user-registration' ) );
			}

			$values['trial_interval'] = $has_trial ? (int) $values['trial_interval'] : null;
			$values['trial_period']   = $has_trial ? $values['trial_period'] : null;
		} else {
			if ( null !== ( $input['trial_interval'] ?? null ) || null !== ( $input['trial_period'] ?? null ) ) {
				return self::invalid_plan( __( 'A free trial belongs to a subscription plan.', 'user-registration' ) );
			}

			$values['billing_interval'] = null;
			$values['billing_period']   = null;
			$values['trial_interval']   = null;
			$values['trial_period']     = null;
		}

		return $values;
	}

	/**
	 * Work out what changing a plan would do, and refuse a change that would surprise the members who hold it.
	 *
	 * The price, type, billing period and trial are only changed on a plan nobody holds. A plan with members keeps them,
	 * so a new plan is the way to change what new members pay.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error The plan, the settled values and a list of the changes.
	 */
	private static function plan_change( $input ) {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}

		$plan = self::plan_post( $input['id'] );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$before = self::plan_detail( $plan );
		$values = self::plan_values( $input, $plan );

		if ( is_wp_error( $values ) ) {
			return $values;
		}

		$labels  = array(
			'name'             => __( 'name', 'user-registration' ),
			'description'      => __( 'description', 'user-registration' ),
			'enabled'          => __( 'turned on', 'user-registration' ),
			'type'             => __( 'type', 'user-registration' ),
			'amount'           => __( 'price', 'user-registration' ),
			'billing_interval' => __( 'billing interval', 'user-registration' ),
			'billing_period'   => __( 'billing period', 'user-registration' ),
			'trial_interval'   => __( 'trial length', 'user-registration' ),
			'trial_period'     => __( 'trial period', 'user-registration' ),
			'role'             => __( 'role', 'user-registration' ),
		);
		$changes = array();
		$money   = false;

		foreach ( $labels as $field => $label ) {
			if ( (string) $before[ $field ] === (string) $values[ $field ] || ( is_bool( $before[ $field ] ) && $before[ $field ] === $values[ $field ] ) ) {
				continue;
			}

			$money = $money || in_array( $field, array( 'type', 'amount', 'billing_interval', 'billing_period', 'trial_interval', 'trial_period' ), true );

			if ( 'description' === $field ) {
				$changes[] = __( 'the description is replaced', 'user-registration' );
			} elseif ( 'enabled' === $field ) {
				$changes[] = $values['enabled'] ? __( 'turned on', 'user-registration' ) : __( 'turned off', 'user-registration' );
			} else {
				/* translators: 1: the setting, 2: the old value, 3: the new value. */
				$changes[] = sprintf( __( '%1$s from "%2$s" to "%3$s"', 'user-registration' ), $label, (string) $before[ $field ], (string) $values[ $field ] );
			}
		}

		if ( ! $changes ) {
			return new WP_Error( 'ur_abilities_no_change', __( 'Nothing would change. The plan already has these values.', 'user-registration' ), array( 'status' => 400 ) );
		}

		if ( $money ) {
			$holding = ( new \WPEverest\URMembership\Admin\Services\MembershipService() )->count_delete_blocking_members( $plan->ID );

			if ( $holding ) {
				/* translators: %d: number of members. */
				return new WP_Error( 'ur_abilities_in_use', sprintf( _n( '%d member has an active, pending or trial subscription to this plan, so its price, type, billing period and trial cannot change. Make a new plan for new members instead. The name, description, role and on or off can still change.', '%d members have an active, pending or trial subscription to this plan, so its price, type, billing period and trial cannot change. Make a new plan for new members instead. The name, description, role and on or off can still change.', $holding, 'user-registration' ), $holding ), array( 'status' => 409 ) );
			}
		}

		return array(
			'plan'    => $plan,
			'values'  => $values,
			'changes' => $changes,
		);
	}

	/**
	 * Build what the plan editor posts for a plan, so the shared save code takes it as it would from the screen.
	 *
	 * A plan that already exists keeps every setting this does not set, such as its payment gateways. A new paid plan
	 * starts with every gateway off, as in the editor, and the admin picks the gateways on the plan screen.
	 *
	 * @param array        $values  Settled values from plan_values().
	 * @param WP_Post|null $current The plan being changed, or null.
	 * @return array
	 */
	private static function plan_editor_data( $values, $current ) {
		$meta = $current ? self::plan_meta( $current ) : array(
			'email_marketing_sync' => array( 'addons_sync_details' => array() ),
			'local_currency'       => array(),
			'upgrade_settings'     => array(
				'upgrade_action' => false,
				'upgrade_path'   => array(),
				'upgrade_type'   => 'full',
			),
			'cancel_subscription'  => '',
		);

		$meta['type']   = $values['type'];
		$meta['role']   = $values['role'];
		$meta['amount'] = 'free' === $values['type'] ? 0 : $values['amount'];

		unset( $meta['subscription'], $meta['trial_status'], $meta['trial_data'] );

		if ( 'subscription' === $values['type'] ) {
			$meta['subscription'] = array(
				'value'    => $values['billing_interval'],
				'duration' => $values['billing_period'],
			);
			$meta['trial_status'] = null === $values['trial_interval'] ? 'off' : 'on';

			if ( null !== $values['trial_interval'] ) {
				$meta['trial_data'] = array(
					'value'    => $values['trial_interval'],
					'duration' => $values['trial_period'],
				);
			}
		}

		if ( 'free' === $values['type'] ) {
			unset( $meta['payment_gateways'] );
		} elseif ( empty( $meta['payment_gateways'] ) ) {
			$meta['payment_gateways'] = array(
				'paypal'    => array(
					'status'     => 'off',
					'email'      => '',
					'mode'       => 'sandbox',
					'cancel_url' => '',
					'return_url' => '',
				),
				'stripe'    => array( 'status' => 'off' ),
				'bank'      => array( 'status' => 'off' ),
				'authorize' => array( 'status' => 'off' ),
				'mollie'    => array( 'status' => 'off' ),
			);
		}

		$post_data = array(
			'name'        => $values['name'],
			'status'      => $values['enabled'],
			'description' => $values['description'],
		);

		if ( $current ) {
			$post_data['ID'] = $current->ID;
		}

		return array(
			'post_data'      => $post_data,
			'post_meta_data' => $meta,
		);
	}

	/**
	 * Say in a few words what a plan costs.
	 *
	 * @param array $values Settled values.
	 * @return string
	 */
	private static function plan_price_text( $values ) {
		$currency = get_option( 'user_registration_payment_currency', 'USD' );

		if ( 'free' === $values['type'] ) {
			return __( 'free', 'user-registration' );
		}

		if ( 'subscription' === $values['type'] ) {
			/* translators: 1: price, 2: currency, 3: how long, for example 3 months. */
			$text = sprintf( __( '%1$s %2$s every %3$s', 'user-registration' ), $values['amount'], $currency, self::period_text( $values['billing_interval'], $values['billing_period'] ) );

			if ( null !== $values['trial_interval'] ) {
				/* translators: %s: how long, for example 14 days. */
				$text .= sprintf( __( ', with a free trial of %s', 'user-registration' ), self::period_text( $values['trial_interval'], $values['trial_period'] ) );
			}

			return $text;
		}

		/* translators: 1: price, 2: currency. */
		return sprintf( __( '%1$s %2$s, paid once', 'user-registration' ), $values['amount'], $currency );
	}

	/**
	 * Say a length of time in words, for example "3 months".
	 *
	 * @param int    $count  How many.
	 * @param string $period day, week, month or year.
	 * @return string
	 */
	private static function period_text( $count, $period ) {
		$names = array(
			/* translators: %d: number of days. */
			'day'   => _n( '%d day', '%d days', $count, 'user-registration' ),
			/* translators: %d: number of weeks. */
			'week'  => _n( '%d week', '%d weeks', $count, 'user-registration' ),
			/* translators: %d: number of months. */
			'month' => _n( '%d month', '%d months', $count, 'user-registration' ),
			/* translators: %d: number of years. */
			'year'  => _n( '%d year', '%d years', $count, 'user-registration' ),
		);

		return sprintf( $names[ $period ] ?? '%d', $count ) . ( isset( $names[ $period ] ) ? '' : ' ' . $period );
	}

	/**
	 * Return the error for a plan or group field that is not allowed.
	 *
	 * @param string $message What is wrong.
	 * @return WP_Error
	 */
	private static function invalid_plan( $message ) {
		return new WP_Error( 'ur_abilities_invalid_plan', $message, array( 'status' => 400 ) );
	}

	/**
	 * Check plan groups can be used here: Pro, a Plus or higher plan, and the module on.
	 *
	 * @return true|WP_Error
	 */
	private static function groups_ready() {
		if ( ! self::membership_ready() ) {
			return self::membership_unavailable();
		}

		if ( ! \WPEverest\URMembership\Admin\Services\MembershipGroupService::is_available() ) {
			return new WP_Error( 'ur_ability_plan_required', __( 'Membership groups need Pro with a Plus or higher plan and the Membership Groups module turned on.', 'user-registration' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Describe a plan group.
	 *
	 * @param WP_Post $group Group.
	 * @return array
	 */
	private static function group_detail( $group ) {
		$content = json_decode( wp_unslash( $group->post_content ), true );
		$plans   = json_decode( wp_unslash( (string) get_post_meta( $group->ID, 'urmg_memberships', true ) ), true );

		return array(
			'id'          => (int) $group->ID,
			'name'        => wp_strip_all_tags( $group->post_title ),
			'description' => wp_strip_all_tags( (string) ( $content['description'] ?? '' ) ),
			'enabled'     => ! empty( $content['status'] ),
			'mode'        => (string) get_post_meta( $group->ID, 'urmg_mode', true ),
			'plan_ids'    => is_array( $plans ) ? array_values( array_map( 'intval', $plans ) ) : array(),
		);
	}

	/**
	 * Check the group fields a request gives, on top of the group it changes, and settle them.
	 *
	 * @param array        $input   Validated input.
	 * @param WP_Post|null $current The group being changed, or null.
	 * @return array|WP_Error
	 */
	private static function group_values( $input, $current ) {
		$ready = self::groups_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$values = $current ? self::group_detail( $current ) : array(
			'name'        => '',
			'description' => '',
			'enabled'     => true,
			'mode'        => 'single',
			'plan_ids'    => array(),
		);

		foreach ( array( 'name', 'description', 'enabled', 'mode', 'plan_ids' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$values[ $field ] = $input[ $field ];
			}
		}

		$values['name']        = trim( sanitize_text_field( (string) $values['name'] ) );
		$values['description'] = sanitize_text_field( (string) $values['description'] );
		$values['enabled']     = (bool) $values['enabled'];
		$values['plan_ids']    = array_values( array_unique( array_map( 'absint', (array) $values['plan_ids'] ) ) );

		if ( '' === $values['name'] ) {
			return self::invalid_plan( __( 'A plan group needs a name.', 'user-registration' ) );
		}

		if ( ! in_array( $values['mode'], array( 'single', 'multiple' ), true ) ) {
			return self::invalid_plan( __( 'The mode must be single (one plan at a time) or multiple (several plans at once). Upgrade groups are set up on the Membership Groups screen.', 'user-registration' ) );
		}

		if ( ! $values['plan_ids'] ) {
			return self::invalid_plan( __( 'A plan group needs at least one plan. List the plans first to find their IDs.', 'user-registration' ) );
		}

		$missing = array();

		foreach ( $values['plan_ids'] as $plan_id ) {
			if ( is_wp_error( self::plan_post( $plan_id ) ) ) {
				$missing[] = $plan_id;
			}
		}

		if ( $missing ) {
			/* translators: %s: plan IDs. */
			return self::invalid_plan( sprintf( __( 'No plan with the ID %s. List the plans first to find their IDs.', 'user-registration' ), implode( ', ', $missing ) ) );
		}

		return $values;
	}

	/**
	 * Work out what changing a plan group would do.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error The group, the settled values and a list of the changes.
	 */
	private static function group_change( $input ) {
		$ready = self::groups_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$group = get_post( $input['id'] );

		if ( ! $group || 'ur_membership_groups' !== $group->post_type || 'trash' === $group->post_status ) {
			return self::not_found();
		}

		$before = self::group_detail( $group );

		if ( 'upgrade' === $before['mode'] ) {
			return new WP_Error( 'ur_abilities_unsupported', __( 'This group uses upgrade mode, with its own upgrade paths. Change it on the Membership Groups screen.', 'user-registration' ), array( 'status' => 409 ) );
		}

		$values = self::group_values( $input, $group );

		if ( is_wp_error( $values ) ) {
			return $values;
		}

		$changes = array();

		if ( $before['name'] !== $values['name'] ) {
			/* translators: 1: the old name, 2: the new name. */
			$changes[] = sprintf( __( 'name from "%1$s" to "%2$s"', 'user-registration' ), $before['name'], $values['name'] );
		}

		if ( $before['description'] !== $values['description'] ) {
			$changes[] = __( 'the description is replaced', 'user-registration' );
		}

		if ( $before['enabled'] !== $values['enabled'] ) {
			$changes[] = $values['enabled'] ? __( 'turned on', 'user-registration' ) : __( 'turned off', 'user-registration' );
		}

		if ( $before['mode'] !== $values['mode'] ) {
			/* translators: 1: the old mode, 2: the new mode. */
			$changes[] = sprintf( __( 'mode from %1$s to %2$s', 'user-registration' ), $before['mode'], $values['mode'] );
		}

		if ( $before['plan_ids'] !== $values['plan_ids'] ) {
			/* translators: 1: the old plans, 2: the new plans. */
			$changes[] = sprintf( __( 'plans from %1$s to %2$s', 'user-registration' ), self::plan_names( $before['plan_ids'] ), self::plan_names( $values['plan_ids'] ) );
		}

		if ( ! $changes ) {
			return new WP_Error( 'ur_abilities_no_change', __( 'Nothing would change. The group already has these values.', 'user-registration' ), array( 'status' => 400 ) );
		}

		return array(
			'group'   => $group,
			'values'  => $values,
			'changes' => $changes,
		);
	}

	/**
	 * Save a plan group through the service the Membership Groups screen uses.
	 *
	 * @param array        $values  Settled values from group_values().
	 * @param WP_Post|null $current The group being changed, or null.
	 * @return array|WP_Error
	 */
	private static function save_group( $values, $current ) {
		$post_data = array(
			'name'        => $values['name'],
			'description' => $values['description'],
			'status'      => $values['enabled'],
		);

		if ( $current ) {
			$post_data['ID'] = $current->ID;
		}

		// The service reads what the screen posts, which WordPress has slashed, so give it the same.
		$result = ( new \WPEverest\URMembership\Admin\Services\MembershipGroupService() )->create_membership_groups(
			wp_slash(
				wp_json_encode(
					array(
						'post_data'      => $post_data,
						'post_meta_data' => array(
							'memberships' => $values['plan_ids'],
							'mode'        => $values['mode'],
						),
					)
				)
			)
		);

		if ( empty( $result['status'] ) ) {
			return new WP_Error( 'ur_abilities_group_failed', wp_strip_all_tags( $result['message'] ?? __( 'The group could not be saved.', 'user-registration' ) ), array( 'status' => 409 ) );
		}

		clean_post_cache( $result['membership_group_id'] );

		return self::group_detail( get_post( $result['membership_group_id'] ) );
	}

	/**
	 * Name plans for a sentence, for example "Free and Gold".
	 *
	 * @param int[] $ids Plan IDs.
	 * @return string
	 */
	private static function plan_names( $ids ) {
		$names = array();

		foreach ( $ids as $id ) {
			$names[] = '"' . wp_strip_all_tags( get_the_title( $id ) ) . '"';
		}

		return $names ? implode( ', ', $names ) : __( 'no plans', 'user-registration' );
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
	 * List the form templates a new form can start from, as on the Add New form screen.
	 *
	 * @since xx.xx.xx
	 *
	 * @return array
	 */
	public static function list_form_templates() {
		$items = array();

		foreach ( self::form_templates() as $template ) {
			$items[] = array(
				'slug'      => (string) $template->slug,
				'title'     => wp_strip_all_tags( (string) ( $template->title ?? $template->slug ) ),
				'pro'       => ! empty( $template->isPro ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Remote template data.
				'plans'     => array_values( array_map( 'strval', (array) ( $template->plan ?? array() ) ) ),
				'addons'    => array_values( array_map( 'wp_strip_all_tags', (array) ( $template->addons ?? array() ) ) ),
				'available' => self::template_available( $template ),
			);
		}

		return array(
			'items'           => $items,
			'can_create_form' => ur_can_create_registration_form(),
		);
	}

	/**
	 * Create a draft form from a template through the same code as the Add New form screen.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function create_form( $input ) {
		if ( ! ur_can_create_registration_form() ) {
			return new WP_Error( 'ur_abilities_forbidden', __( 'Multiple registration forms cannot be created. Turn on the Multiple Registration module first.', 'user-registration' ), array( 'status' => 403 ) );
		}

		$slug     = isset( $input['template'] ) ? $input['template'] : 'blank';
		$template = null;

		foreach ( self::form_templates() as $item ) {
			if ( $slug === $item->slug ) {
				$template = $item;
			}
		}

		if ( ! $template ) {
			return new WP_Error( 'ur_abilities_not_found', __( 'Form template not found. List the templates to see the slugs.', 'user-registration' ), array( 'status' => 404 ) );
		}

		if ( ! self::template_available( $template ) ) {
			return new WP_Error( 'ur_ability_plan_required', __( 'This template needs a higher plan. Pick a template marked as available.', 'user-registration' ), array( 'status' => 403 ) );
		}

		$form_id = UR()->form->create( sanitize_text_field( $input['title'] ), $slug );

		if ( ! $form_id || is_wp_error( $form_id ) ) {
			return new WP_Error( 'ur_abilities_create_failed', __( 'Something went wrong, please try again later', 'user-registration' ), array( 'status' => 500 ) );
		}

		// The admin opens a new form in the builder; an AI-made form waits as a draft, with the asked title, for the admin to publish.
		wp_update_post(
			array(
				'ID'          => $form_id,
				'post_title'  => sanitize_text_field( $input['title'] ),
				'post_status' => 'draft',
			)
		);

		// Mark the form as made by AI, so usage statistics can count AI-made forms even after the admin edits them.
		update_post_meta( $form_id, self::AI_CREATED_META, gmdate( 'c' ) );
		update_post_meta( $form_id, self::AI_CREATED_META . '_app', UR_Abilities_Activity::app_name() );
		update_post_meta( $form_id, self::AI_CREATED_META . '_user', get_current_user_id() );

		return self::get_form( array( 'id' => $form_id ) );
	}

	/**
	 * Add a field to a form and save it through the same code as the form builder.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function add_form_field( $input ) {
		$form = self::form_layout( $input['form_id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		if ( ! in_array( $input['type'], self::field_types(), true ) ) {
			return new WP_Error( 'ur_abilities_invalid_type', __( 'This field type is not available here. Pro fields need Pro, and password fields cannot be added.', 'user-registration' ), array( 'status' => 400 ) );
		}

		$class = ur_load_form_field_class( $input['type'] );

		if ( ! $class || ! class_exists( $class ) ) {
			return new WP_Error( 'ur_abilities_invalid_type', __( 'This field type is not available here.', 'user-registration' ), array( 'status' => 400 ) );
		}

		$field_class = $class::get_instance();

		// Same naming as the form builder: WordPress user fields are named after the field, the rest get the class default.
		$field_name = in_array( $input['type'], ur_get_fields_without_prefix(), true )
			? trim( str_replace( 'user_registration_', '', $field_class->id ) )
			: (string) $field_class->get_general_setting_data( 'field_name' );
		$taken      = self::field_names( $form['rows'] );

		if ( in_array( $field_name, $taken, true ) ) {
			if ( ! preg_match( '/_\d+$/', $field_name ) ) {
				/* translators: %s: field type. */
				return new WP_Error( 'ur_abilities_no_change', sprintf( __( 'This form already has a %s field.', 'user-registration' ), $input['type'] ), array( 'status' => 409 ) );
			}

			$base = preg_replace( '/_\d+$/', '', $field_name );
			$n    = time();

			while ( in_array( $base . '_' . $n, $taken, true ) ) {
				++$n;
			}

			$field_name = $base . '_' . $n;
		}

		$field = (object) array(
			'field_key'       => $input['type'],
			'general_setting' => (object) array(
				'label'       => (string) $field_class->get_general_setting_data( 'label' ),
				'description' => '',
				'placeholder' => '',
				'required'    => 'false',
				'hide_label'  => 'false',
				'field_name'  => $field_name,
			),
			'advance_setting' => (object) array( 'custom_class' => '' ),
			'icon'            => (string) ( $field_class->registered_fields_config['icon'] ?? '' ),
		);

		if ( in_array( $input['type'], array( 'select', 'radio', 'checkbox' ), true ) ) {
			$field->general_setting->options = array( __( 'First Choice', 'user-registration' ), __( 'Second Choice', 'user-registration' ), __( 'Third Choice', 'user-registration' ) );
		}

		$error = self::apply_field_changes( $field, $input );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$after = isset( $input['after_field'] ) ? self::find_field( $form['rows'], $input['after_field'] ) : null;

		if ( isset( $input['after_field'] ) && ! $after ) {
			return self::field_not_found();
		}

		if ( $after ) {
			array_splice( $form['rows'][ $after[0] ][ $after[1] ], $after[2] + 1, 0, array( $field ) );
		} else {
			// Like a field dropped at the end of the form: the last column of the last row.
			$row  = count( $form['rows'] ) - 1;
			$grid = count( $form['rows'][ $row ] ) - 1;

			$form['rows'][ $row ][ $grid ][] = $field;
		}

		return self::save_form_layout( $form );
	}

	/**
	 * Change a field's label, required setting, placeholder, description or choices.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function update_form_field( $input ) {
		$form = self::form_layout( $input['form_id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$at = self::find_field( $form['rows'], $input['field_name'] );

		if ( ! $at ) {
			return self::field_not_found();
		}

		$error = self::apply_field_changes( $form['rows'][ $at[0] ][ $at[1] ][ $at[2] ], $input );

		return is_wp_error( $error ) ? $error : self::save_form_layout( $form );
	}

	/**
	 * Describe what removing a field would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_remove_form_field( $input ) {
		$form = self::form_layout( $input['form_id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$at = self::find_field( $form['rows'], $input['field_name'] );

		if ( ! $at ) {
			return self::field_not_found();
		}

		return sprintf(
			/* translators: 1: field label, 2: field name, 3: form title. */
			__( 'Remove the "%1$s" field (%2$s) from the %3$s form, like deleting it in the form builder. New sign-ups will not be asked for it. Answers members already gave stay in their profiles.', 'user-registration' ),
			$form['rows'][ $at[0] ][ $at[1] ][ $at[2] ]->general_setting->label ?? '',
			$input['field_name'],
			$form['post']->post_title
		);
	}

	/**
	 * Remove a field and save the form through the same code as the form builder.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function remove_form_field( $input ) {
		$form = self::form_layout( $input['form_id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$at = self::find_field( $form['rows'], $input['field_name'] );

		if ( ! $at ) {
			return self::field_not_found();
		}

		array_splice( $form['rows'][ $at[0] ][ $at[1] ], $at[2], 1 );

		return self::save_form_layout( $form );
	}

	/**
	 * Move a field after another field, or to the top of the form.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function move_form_field( $input ) {
		$form = self::form_layout( $input['form_id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$at = self::find_field( $form['rows'], $input['field_name'] );

		if ( ! $at || ( isset( $input['after_field'] ) && ( $input['after_field'] === $input['field_name'] || ! self::find_field( $form['rows'], $input['after_field'] ) ) ) ) {
			return self::field_not_found();
		}

		$field = array_splice( $form['rows'][ $at[0] ][ $at[1] ], $at[2], 1 );

		if ( isset( $input['after_field'] ) ) {
			$after = self::find_field( $form['rows'], $input['after_field'] );
			array_splice( $form['rows'][ $after[0] ][ $after[1] ], $after[2] + 1, 0, $field );
		} else {
			array_unshift( $form['rows'][0][0], $field[0] );
		}

		return self::save_form_layout( $form );
	}

	/**
	 * Read the form settings AI assistants may change, with the shortcode that places the form.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_form_settings( $input ) {
		$post = self::form_post( $input['id'] );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$current  = array();
		$settings = array();

		foreach ( ur_admin_form_settings_fields( $post->ID ) as $field ) {
			if ( ! empty( $field['id'] ) ) {
				$current[ $field['id'] ] = $field['default'] ?? '';
			}
		}

		foreach ( self::form_setting_ids() as $key => $setting_id ) {
			$value            = $current[ $setting_id ] ?? '';
			$settings[ $key ] = in_array( $key, array( 'redirect_page_id', 'redirect_delay', 'success_message_position' ), true ) ? (int) $value : (string) $value;
		}

		return array(
			'id'        => (int) $post->ID,
			'title'     => wp_strip_all_tags( $post->post_title ),
			'status'    => $post->post_status,
			'shortcode' => '[user_registration_form id="' . (int) $post->ID . '"]',
			'settings'  => $settings,
			'choices'   => array(
				'login_option' => array_keys( ur_login_option() ),
				'default_role' => self::allowed_roles(),
			),
		);
	}

	/**
	 * Describe what changing form settings would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_update_form_settings( $input ) {
		$values = self::form_setting_values( $input );

		if ( is_wp_error( $values ) ) {
			return $values;
		}

		$labels = array();

		foreach ( $input['settings'] as $key => $value ) {
			$labels[] = $key . ' = ' . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value );
		}

		return sprintf(
			/* translators: 1: form title, 2: list of changes. */
			__( 'Change these settings of the %1$s form, like saving them in the form builder: %2$s. Other settings stay as they are.', 'user-registration' ),
			$values['post']->post_title,
			implode( '; ', $labels )
		);
	}

	/**
	 * Change form settings through the same code as the form builder.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function update_form_settings( $input ) {
		$values = self::form_setting_values( $input );

		if ( is_wp_error( $values ) ) {
			return $values;
		}

		include_once UR_ABSPATH . 'includes/admin/functions-ur-admin.php';
		ur_update_form_setting_values( $values['post']->ID, $values['values'] );

		return self::get_form_settings( array( 'id' => $values['post']->ID ) );
	}

	/**
	 * Templates the form create code can use: the first section of the Add New form screen's list.
	 *
	 * @return array
	 */
	private static function form_templates() {
		$sections = UR_Admin_Form_Templates::get_template_data();

		return is_array( $sections ) && isset( $sections[0]->templates ) && is_array( $sections[0]->templates ) ? array_filter(
			$sections[0]->templates,
			function ( $template ) {
				return isset( $template->slug );
			}
		) : array();
	}

	/**
	 * Whether this site's edition and plan include a template, the way the template screen locks them.
	 *
	 * @param object $template Template.
	 * @return bool
	 */
	private static function template_available( $template ) {
		if ( empty( $template->isPro ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Remote template data.
			return true;
		}

		if ( ! UR_PRO_ACTIVE ) {
			return false;
		}

		$license = ur_get_license_plan();
		$site    = ! empty( $license->item_plan ) ? trim( strtolower( $license->item_plan ) ) : '';

		// Same ladder as the template screen: a Personal template works on every paid plan, a Plus one from Plus up.
		$ladder = array(
			'personal' => array( 'personal', 'plus', 'professional', 'agency' ),
			'plus'     => array( 'plus', 'professional', 'agency' ),
		);

		foreach ( (array) ( $template->plan ?? array() ) as $plan ) {
			if ( in_array( $site, $ladder[ $plan ] ?? array( $plan ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Field types AI assistants may add: the types this site registers, without password fields.
	 *
	 * @return array
	 */
	private static function field_types() {
		return array_values( array_diff( ur_get_registered_form_fields(), array( 'user_pass', 'user_confirm_password', 'password' ) ) );
	}

	/**
	 * Find a form and read its fields and layout the way the form builder stores them.
	 *
	 * @param int $id Form ID.
	 * @return array|WP_Error Post, rows, row IDs and row details.
	 */
	private static function form_layout( $id ) {
		$post = self::form_post( $id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$rows = json_decode( $post->post_content );

		if ( ! is_array( $rows ) || empty( $rows ) || ! is_array( $rows[0] ) || empty( $rows[0] ) ) {
			return new WP_Error( 'ur_abilities_invalid_form', __( 'This form has no fields layout to change. Open it in the form builder first.', 'user-registration' ), array( 'status' => 409 ) );
		}

		return array(
			'post'     => $post,
			'rows'     => $rows,
			'row_ids'  => (string) get_post_meta( $post->ID, 'user_registration_form_row_ids', true ),
			'row_data' => (string) get_post_meta( $post->ID, 'user_registration_form_row_data', true ),
		);
	}

	/**
	 * Find a registration form the current user may edit.
	 *
	 * @param int $id Form ID.
	 * @return WP_Post|WP_Error
	 */
	private static function form_post( $id ) {
		$post = get_post( $id );

		return $post && 'user_registration' === $post->post_type && 'trash' !== $post->post_status ? $post : self::not_found();
	}

	/**
	 * Save changed fields through the form builder's save, keeping the form's status and settings as they are.
	 *
	 * @param array $form Form from form_layout().
	 * @return array|WP_Error
	 */
	private static function save_form_layout( $form ) {
		try {
			UR_AJAX::save_form_data(
				array(
					'form_id'      => $form['post']->ID,
					'form_name'    => $form['post']->post_title,
					'form_data'    => $form['rows'],
					'row_ids'      => $form['row_ids'],
					'row_data'     => $form['row_data'],
					'setting_data' => null,
					'post_status'  => $form['post']->post_status,
				)
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'ur_abilities_invalid_form', wp_strip_all_tags( $e->getMessage() ), array( 'status' => 400 ) );
		}

		return self::get_form( array( 'id' => $form['post']->ID ) );
	}

	/**
	 * Apply the asked changes to a field's general settings.
	 *
	 * @param object $field Field from the form layout.
	 * @param array  $input Validated input.
	 * @return true|WP_Error
	 */
	private static function apply_field_changes( $field, $input ) {
		$settings = $field->general_setting;

		if ( isset( $input['required'] ) && in_array( $settings->field_name ?? '', ur_get_required_fields(), true ) ) {
			return new WP_Error( 'ur_abilities_no_change', __( 'This field is always required.', 'user-registration' ), array( 'status' => 409 ) );
		}

		if ( isset( $input['options'] ) && ! isset( $settings->options ) ) {
			return new WP_Error( 'ur_abilities_invalid_input', __( 'Only dropdown, radio and checkbox fields have choices.', 'user-registration' ), array( 'status' => 400 ) );
		}

		foreach ( array( 'label', 'placeholder', 'description' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$settings->$key = sanitize_text_field( $input[ $key ] );
			}
		}

		if ( isset( $input['required'] ) ) {
			$settings->required = $input['required'] ? '1' : 'false';
		}

		if ( isset( $input['options'] ) ) {
			$settings->options = array_values( array_filter( array_map( 'sanitize_text_field', $input['options'] ), 'strlen' ) );
		}

		return true;
	}

	/**
	 * Find a field by its field name.
	 *
	 * @param array  $rows       Rows of grids of fields.
	 * @param string $field_name Field name.
	 * @return array|null Row, grid and position, or null.
	 */
	private static function find_field( $rows, $field_name ) {
		foreach ( $rows as $r => $grids ) {
			foreach ( (array) $grids as $g => $fields ) {
				foreach ( (array) $fields as $i => $field ) {
					if ( isset( $field->general_setting->field_name ) && $field_name === $field->general_setting->field_name ) {
						return array( $r, $g, $i );
					}
				}
			}
		}

		return null;
	}

	/**
	 * List every field name used in a form.
	 *
	 * @param array $rows Rows of grids of fields.
	 * @return array
	 */
	private static function field_names( $rows ) {
		$names = array();

		foreach ( $rows as $grids ) {
			foreach ( (array) $grids as $fields ) {
				foreach ( (array) $fields as $field ) {
					if ( isset( $field->general_setting->field_name ) ) {
						$names[] = $field->general_setting->field_name;
					}
				}
			}
		}

		return $names;
	}

	/**
	 * Return a uniform missing-field error.
	 *
	 * @return WP_Error
	 */
	private static function field_not_found() {
		return new WP_Error( 'ur_abilities_not_found', __( 'Field not found in this form. Read the form to see its field names.', 'user-registration' ), array( 'status' => 404 ) );
	}

	/**
	 * Form settings AI assistants may change, keyed by the name the ability uses.
	 *
	 * @return array
	 */
	private static function form_setting_ids() {
		return array(
			'login_option'                => 'user_registration_form_setting_login_options',
			'default_role'                => 'user_registration_form_setting_default_user_role',
			'redirect_after_registration' => 'user_registration_form_setting_redirect_after_registration',
			'redirect_page_id'            => 'user_registration_form_setting_redirect_page',
			'redirect_url'                => 'user_registration_form_setting_redirect_options',
			'redirect_delay'              => 'user_registration_form_setting_redirect_after',
			'submit_label'                => 'user_registration_form_setting_form_submit_label',
			'success_message_position'    => 'user_registration_form_setting_success_message_position',
		);
	}

	/**
	 * Roles new members may get from a form: every known role that cannot manage the site.
	 *
	 * @return array
	 */
	private static function allowed_roles() {
		return array_values(
			array_filter(
				array_keys( ur_get_default_admin_roles() ),
				function ( $role ) {
					return ! ur_registration_role_is_privileged( $role );
				}
			)
		);
	}

	/**
	 * Check asked setting changes and turn them into setting IDs and values.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error Form post and values keyed by setting ID.
	 */
	private static function form_setting_values( $input ) {
		$post = self::form_post( $input['form_id'] );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$settings = (array) $input['settings'];

		if ( empty( $settings ) ) {
			return new WP_Error( 'ur_abilities_no_change', __( 'Give at least one setting to change.', 'user-registration' ), array( 'status' => 400 ) );
		}

		$invalid = function ( $message ) {
			return new WP_Error( 'ur_abilities_invalid_input', $message, array( 'status' => 400 ) );
		};

		if ( isset( $settings['login_option'] ) && ! array_key_exists( $settings['login_option'], ur_login_option() ) ) {
			return $invalid( __( 'Unknown login option.', 'user-registration' ) );
		}

		if ( isset( $settings['default_role'] ) && ! in_array( $settings['default_role'], self::allowed_roles(), true ) ) {
			return $invalid( __( 'New members cannot get this role. Admin-level roles are never allowed.', 'user-registration' ) );
		}

		if ( ! empty( $settings['redirect_page_id'] ) && 'publish' !== get_post_status( $settings['redirect_page_id'] ) ) {
			return $invalid( __( 'The redirect page must be a published page.', 'user-registration' ) );
		}

		if ( isset( $settings['redirect_url'] ) && ! wp_http_validate_url( $settings['redirect_url'] ) ) {
			return $invalid( __( 'The redirect URL must be a full web address.', 'user-registration' ) );
		}

		$values = array();

		foreach ( self::form_setting_ids() as $key => $setting_id ) {
			if ( isset( $settings[ $key ] ) ) {
				$values[ $setting_id ] = 'redirect_url' === $key ? esc_url_raw( $settings[ $key ] ) : sanitize_text_field( (string) $settings[ $key ] );
			}
		}

		return array(
			'post'   => $post,
			'values' => $values,
		);
	}

	/**
	 * List the modules and addons, using the list the Modules and Addons screen shows.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public static function list_modules( $input ) {
		$page  = self::page( $input );
		$items = array_values(
			array_filter(
				self::modules(),
				function ( $entry ) use ( $input ) {
					return ( ! isset( $input['type'] ) || $input['type'] === $entry['type'] ) && ( ! isset( $input['status'] ) || $input['status'] === $entry['status'] );
				}
			)
		);

		$page['has_more'] = count( $items ) > $page['page'] * $page['per_page'];
		$page['items']    = array_map( array( __CLASS__, 'module_output' ), array_slice( $items, ( $page['page'] - 1 ) * $page['per_page'], $page['per_page'] ) );

		return $page;
	}

	/**
	 * Turn on a module, or activate an installed addon, the way the Modules and Addons screen does.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function enable_module( $input ) {
		$entry = self::module_target( $input['slug'] );

		if ( is_wp_error( $entry ) || 'active' === $entry['status'] ) {
			return is_wp_error( $entry ) ? $entry : self::module_output( $entry );
		}

		if ( ! $entry['available'] ) {
			return UR_Abilities::plan_required_error( $entry['name'], $entry['plans'], $entry['slug'] );
		}

		$plugin = $entry['slug'] . '/' . $entry['slug'] . '.php';

		if ( 'addon' === $entry['type'] ) {
			// Installing downloads and runs code, so only an addon that is already on the site is switched on.
			if ( 'not-installed' === $entry['status'] || ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				return new WP_Error( 'ur_abilities_addon_not_installed', __( 'This addon is not installed on the site. Install it from the Addons screen first, because AI assistants cannot install plugins.', 'user-registration' ), array( 'status' => 400 ) );
			}

			$status = UR_Modules::ur_install_addons( $entry['slug'], $entry['name'], $plugin );
		} else {
			$status = UR_Modules::ur_enable_feature( $entry['slug'] );
		}

		if ( empty( $status['success'] ) ) {
			return new WP_Error( 'ur_abilities_module_failed', ! empty( $status['errorMessage'] ) && is_string( $status['errorMessage'] ) ? $status['errorMessage'] : __( 'It could not be turned on. Try again later.', 'user-registration' ), array( 'status' => 500 ) );
		}

		$fresh = self::module_find( $entry['slug'] );

		return self::module_output( $fresh ? $fresh : $entry );
	}

	/**
	 * Say what turning a module or addon off would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_disable_module( $input ) {
		$entry = self::module_target( $input['slug'] );

		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		if ( 'active' !== $entry['status'] ) {
			/* translators: %s: module or addon name. */
			return sprintf( __( '%s is already off. Nothing would change.', 'user-registration' ), $entry['name'] );
		}

		return 'addon' === $entry['type']
			/* translators: %s: addon name. */
			? sprintf( __( 'Deactivate the %s addon. Its data stays saved, but its features stop working until it is activated again.', 'user-registration' ), $entry['name'] )
			/* translators: %s: module name. */
			: sprintf( __( 'Turn off the %s module. Its data stays saved, but its screens and features stop working until it is turned on again.', 'user-registration' ), $entry['name'] );
	}

	/**
	 * Turn off a module, or deactivate an installed addon, the way the Modules and Addons screen does.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function disable_module( $input ) {
		$entry = self::module_target( $input['slug'] );

		if ( is_wp_error( $entry ) || 'active' !== $entry['status'] ) {
			return is_wp_error( $entry ) ? $entry : self::module_output( $entry );
		}

		$status = 'addon' === $entry['type']
			? UR_Modules::ur_deactivate_addon( $entry['slug'] . '/' . $entry['slug'] . '.php' )
			: UR_Modules::ur_disable_feature( $entry['slug'] );

		if ( empty( $status['success'] ) ) {
			// The same message the Modules screen gives when forms still depend on the module.
			$message = 'user-registration-multiple-registration' === $entry['slug']
				? __( 'You have multiple registration forms, so you can\'t deactivate the module.', 'user-registration' )
				: __( 'It could not be turned off. Try again later.', 'user-registration' );

			return new WP_Error( 'ur_abilities_module_failed', $message, array( 'status' => 'user-registration-multiple-registration' === $entry['slug'] ? 400 : 500 ) );
		}

		$fresh = self::module_find( $entry['slug'] );

		return self::module_output( $fresh ? $fresh : $entry );
	}

	/**
	 * Get the modules and addons from the Modules and Addons screen, with the plan check for this site.
	 *
	 * @return array Entries with slug, name, type, status, required_plan, available and plans.
	 */
	private static function modules() {
		if ( ! class_exists( 'UR_Modules' ) ) {
			return array();
		}

		$response = UR_Modules::ur_get_modules();
		$data     = $response instanceof WP_REST_Response ? $response->get_data() : array();
		$entries  = array();

		foreach ( (array) ( $data['modules_lists'] ?? array() ) as $item ) {
			$plans     = array_values( (array) ( $item->plan ?? array() ) );
			$entries[] = array(
				'slug'          => (string) $item->slug,
				'name'          => wp_strip_all_tags( (string) ( $item->title ?? $item->name ?? $item->slug ) ),
				'type'          => 'addon' === ( $item->type ?? '' ) ? 'addon' : 'module',
				'status'        => in_array( $item->status ?? '', array( 'active', 'not-installed' ), true ) ? $item->status : 'inactive',
				'required_plan' => self::lowest_plan( $plans ),
				'available'     => UR_Abilities::plan_allows( $plans ),
				'plans'         => $plans,
			);
		}

		return $entries;
	}

	/**
	 * Find one module or addon by its slug.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	private static function module_find( $slug ) {
		foreach ( self::modules() as $entry ) {
			if ( $slug === $entry['slug'] ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Find the module or addon an AI assistant may change, or say why it may not.
	 *
	 * @param string $slug Slug.
	 * @return array|WP_Error
	 */
	private static function module_target( $slug ) {
		$entry = self::module_find( $slug );

		if ( ! $entry ) {
			return new WP_Error( 'ur_abilities_not_found', __( 'Module or addon not found. List them to see the slugs.', 'user-registration' ), array( 'status' => 404 ) );
		}

		// Payment gateways carry keys and money settings, so they stay with the admin.
		if ( preg_match( '/stripe|paypal|authorize|payments/', $entry['slug'] ) ) {
			return new WP_Error( 'ur_abilities_module_protected', __( 'Payment gateways are turned on and off on the Modules and Addons screen, not by AI assistants.', 'user-registration' ), array( 'status' => 403 ) );
		}

		return $entry;
	}

	/**
	 * Keep only the fields an answer promises.
	 *
	 * @param array $entry Entry from self::modules().
	 * @return array
	 */
	private static function module_output( $entry ) {
		return array_diff_key( $entry, array( 'plans' => true ) );
	}

	/**
	 * Name the lowest plan that includes something.
	 *
	 * @param array $plans Plan slugs.
	 * @return string
	 */
	private static function lowest_plan( $plans ) {
		foreach ( array( 'free', 'personal', 'plus', 'professional', 'themegrill agency' ) as $plan ) {
			if ( in_array( $plan, $plans, true ) ) {
				return $plan;
			}
		}

		return 'free';
	}

	/**
	 * List the plugin's global settings, as shown on its settings pages. Secrets only say whether they are set.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public static function list_settings( $input ) {
		$search = isset( $input['search'] ) ? strtolower( $input['search'] ) : '';
		$items  = array();

		foreach ( self::setting_fields() as $id => $entry ) {
			if ( isset( $input['tab'] ) && $input['tab'] !== $entry['tab'] ) {
				continue;
			}

			$item = self::setting( $id, $entry );

			if ( null === $item || ( '' !== $search && false === strpos( strtolower( $id . ' ' . $item['title'] . ' ' . $item['location'] ), $search ) ) ) {
				continue;
			}

			$items[] = $item;
		}

		return array( 'items' => $items );
	}

	/**
	 * Read one global setting.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_setting( $input ) {
		$fields = self::setting_fields();
		$item   = isset( $fields[ $input['id'] ] ) ? self::setting( $input['id'], $fields[ $input['id'] ] ) : null;

		return null === $item ? self::setting_not_found() : $item;
	}

	/**
	 * Whether changing a setting needs a preview first.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public static function setting_needs_confirmation( $input ) {
		$id = (string) ( $input['id'] ?? '' );

		/**
		 * Filters the settings that may change without a preview first.
		 *
		 * @since xx.xx.xx
		 *
		 * @param array $ids Setting IDs.
		 */
		$safe = (array) apply_filters( 'user_registration_ability_safe_settings', self::SAFE_SETTINGS );

		/**
		 * Filters the settings whose change always shows a preview first.
		 *
		 * @since xx.xx.xx
		 *
		 * @param array $ids Setting IDs.
		 */
		$risky = (array) apply_filters( 'user_registration_ability_risky_settings', self::RISKY_SETTINGS );

		// Safe by default: a setting shows a preview unless it is on the harmless list.
		return ! in_array( $id, $safe, true ) || in_array( $id, $risky, true );
	}

	/**
	 * Describe what changing a setting would do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return string|WP_Error
	 */
	public static function preview_update_setting( $input ) {
		$change = self::setting_change( $input );

		if ( is_wp_error( $change ) ) {
			return $change;
		}

		return sprintf(
			/* translators: 1: setting title, 2: where it is shown, 3: current value, 4: new value. */
			__( 'Change "%1$s" (%2$s) from %3$s to %4$s, like saving it on the settings screen.', 'user-registration' ),
			$change['item']['title'],
			$change['item']['location'],
			wp_json_encode( $change['item']['value'] ),
			wp_json_encode( $input['value'] )
		);
	}

	/**
	 * Change one global setting through the settings screen's own checks, cleaning and save.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function update_setting( $input ) {
		$change = self::setting_change( $input );

		if ( is_wp_error( $change ) ) {
			return $change;
		}

		$value = UR_Admin_Settings::sanitize_setting_value( $change['field'], $input['value'], $input['id'] );

		if ( null === $value ) {
			return new WP_Error( 'ur_abilities_invalid_input', __( 'This value cannot be saved for this setting.', 'user-registration' ), array( 'status' => 400 ) );
		}

		UR_Admin_Settings::update_setting_options( array( $input['id'] => $value ) );

		return self::get_setting( array( 'id' => $input['id'] ) );
	}

	/**
	 * Every settings field, loaded the way the settings screen loads them.
	 *
	 * @return array
	 */
	private static function setting_fields() {
		if ( ! class_exists( 'UR_Admin_Settings' ) ) {
			include_once UR_ABSPATH . 'includes/admin/functions-ur-admin.php';
			include_once UR_ABSPATH . 'includes/admin/class-ur-admin-settings.php';
		}

		// Some settings pages print notices while they build their fields.
		ob_start();

		try {
			$fields = UR_Admin_Settings::get_setting_fields();
		} finally {
			ob_end_clean();
		}

		return $fields;
	}

	/**
	 * Serialize a setting, or null when it is not a real setting field.
	 *
	 * @param string $id    Setting ID.
	 * @param array  $entry Entry from UR_Admin_Settings::get_setting_fields().
	 * @return array|null
	 */
	private static function setting( $id, $entry ) {
		$field = $entry['field'];
		$type  = (string) ( $field['type'] ?? '' );

		if ( in_array( $type, array( 'title', 'sectionend', 'button', 'link', 'nonce', 'html' ), true ) ) {
			return null;
		}

		$secret  = self::is_secret_setting( $id, $type );
		$current = get_option( $id, $field['default'] ?? '' );
		$choices = array();

		foreach ( (array) ( $field['options'] ?? array() ) as $key => $label ) {
			$choices[] = array(
				'value' => (string) $key,
				'label' => wp_strip_all_tags( is_string( $label ) ? $label : (string) $key ),
			);
		}

		return array(
			'id'       => $id,
			'title'    => wp_strip_all_tags( (string) ( $field['title'] ?? '' ) ),
			'location' => wp_strip_all_tags( (string) $entry['location'] ),
			'tab'      => (string) $entry['tab'],
			'type'     => $type,
			'value'    => $secret ? null : ( is_scalar( $current ) || is_array( $current ) ? $current : null ),
			'is_set'   => '' !== $current && null !== $current && array() !== $current && false !== $current,
			'secret'   => $secret,
			'editable' => ! $secret && ! self::is_ai_control_setting( $id ) && ! in_array( $entry['tab'], self::LOCKED_SETTING_TABS, true ) && in_array( $type, self::SETTING_TYPES, true ) && false === strpos( $id, '[' ),
			'choices'  => $choices,
		);
	}

	/**
	 * Whether only a person may change a setting: the AI switches themselves, and choices about data and consent.
	 *
	 * @param string $id Setting ID.
	 * @return bool
	 */
	private static function is_ai_control_setting( $id ) {
		// Also a person's choice: deleting all data on uninstall, and consent to share usage data.
		$people_only = array( 'user_registration_enable_abilities', 'user_registration_general_setting_uninstall_option', 'user_registration_allow_usage_tracking' );

		return in_array( $id, $people_only, true ) || in_array( $id, array( UR_Abilities_Access::MODULES_OPTION, UR_Abilities_Access::WRITE_OPTION ), true );
	}

	/**
	 * Whether a setting holds a secret, such as a payment key, captcha secret, password or license key.
	 *
	 * @param string $id   Setting ID.
	 * @param string $type Field type.
	 * @return bool
	 */
	private static function is_secret_setting( $id, $type ) {
		return 'password' === $type || 1 === preg_match( '/secret|license|token|_key(_|$)/', $id );
	}

	/**
	 * Check a setting change the way the settings screen does, and return what is needed to save it.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error The setting, its definition and its current details.
	 */
	private static function setting_change( $input ) {
		$fields = self::setting_fields();

		if ( ! isset( $fields[ $input['id'] ] ) ) {
			return self::setting_not_found();
		}

		$entry = $fields[ $input['id'] ];
		$field = $entry['field'];
		$item  = self::setting( $input['id'], $entry );

		if ( null === $item || ! $item['editable'] ) {
			return new WP_Error( 'ur_abilities_forbidden', __( 'AI assistants cannot change this setting. Secrets, the license, tools and some field types are left to the settings screen.', 'user-registration' ), array( 'status' => 403 ) );
		}

		$error = self::setting_value_error( $field, $input['value'] );

		if ( '' !== $error ) {
			return new WP_Error( 'ur_abilities_invalid_input', $error, array( 'status' => 400 ) );
		}

		// The settings screen checks a whole section at once, so check the new value alongside the section's current values.
		$section = array();

		foreach ( $fields as $id => $other ) {
			if ( $other['tab'] === $entry['tab'] && $other['section'] === $entry['section'] ) {
				$section[ $id ] = get_option( $id, $other['field']['default'] ?? '' );
			}
		}

		$section[ $input['id'] ] = $input['value'];
		$page_error              = UR_Admin_Settings::get_save_error( apply_filters( 'user_registration_settings_prevent_default_login', $section ) );

		if ( '' !== $page_error ) {
			return new WP_Error( 'ur_abilities_invalid_input', $page_error, array( 'status' => 400 ) );
		}

		return array(
			'field' => $field,
			'item'  => $item,
		);
	}

	/**
	 * Check a value against what its setting allows.
	 *
	 * @param array $field Setting definition.
	 * @param mixed $value New value.
	 * @return string The problem, or an empty string when the value fits.
	 */
	private static function setting_value_error( $field, $value ) {
		$type    = $field['type'];
		$options = array_map( 'strval', array_keys( (array) ( $field['options'] ?? array() ) ) );
		$values  = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );

		switch ( $type ) {
			case 'toggle':
			case 'checkbox':
				return is_bool( $value ) ? '' : __( 'Use true or false for this setting.', 'user-registration' );
			case 'select':
			case 'radio-group':
				if ( is_array( $value ) || ! in_array( (string) $value, $options, true ) ) {
					return __( 'Use one of the choices this setting offers.', 'user-registration' );
				}
				break;
			case 'multiselect':
				if ( ! is_array( $value ) || array_diff( $values, $options ) ) {
					return __( 'Use a list of the choices this setting offers.', 'user-registration' );
				}
				break;
			case 'number':
				$attributes = (array) ( $field['custom_attributes'] ?? array() );

				if ( ! is_numeric( $value ) || ( isset( $attributes['min'] ) && $value < $attributes['min'] ) || ( isset( $attributes['max'] ) && $value > $attributes['max'] ) ) {
					return __( 'Use a number within the range this setting allows.', 'user-registration' );
				}
				break;
			case 'single_select_page':
				if ( ! is_numeric( $value ) || ( 0 !== (int) $value && 'publish' !== get_post_status( (int) $value ) ) ) {
					return __( 'Use the ID of a published page, or 0 for none.', 'user-registration' );
				}
				break;
			case 'email':
				if ( ! is_string( $value ) || ( '' !== $value && ! is_email( $value ) ) ) {
					return __( 'Use a valid email address.', 'user-registration' );
				}
				break;
			case 'color':
				if ( ! is_string( $value ) || ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ) {
					return __( 'Use a hex color such as #475BB2.', 'user-registration' );
				}
				break;
			default:
				if ( ! is_string( $value ) || strlen( $value ) > 10000 ) {
					return __( 'Use text of at most 10000 characters.', 'user-registration' );
				}
		}

		// A role setting never takes an admin-level role, the same rule new members get at sign-up.
		if ( $options && ! array_diff( $options, array_keys( wp_roles()->roles ) ) ) {
			foreach ( $values as $role ) {
				// Blocking the dashboard only locks people out when the role runs the site, so only that is refused there.
				$locked_out = 'user_registration_general_setting_disabled_user_roles' === $field['id'] ? ( get_role( $role ) && get_role( $role )->has_cap( 'manage_options' ) ) : ur_registration_role_is_privileged( $role );

				if ( $locked_out ) {
					return __( 'Admin-level roles are never allowed here.', 'user-registration' );
				}
			}
		}

		return '';
	}

	/**
	 * Return a uniform missing-setting error.
	 *
	 * @return WP_Error
	 */
	private static function setting_not_found() {
		return new WP_Error( 'ur_abilities_not_found', __( 'Setting not found. List the settings to see their IDs.', 'user-registration' ), array( 'status' => 404 ) );
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
