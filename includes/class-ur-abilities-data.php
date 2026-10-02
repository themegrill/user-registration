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
		$page             = self::page( $input );
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
		$result['membership_available'] = ur_check_module_activation( 'membership' ) && class_exists( '\WPEverest\URMembership\Admin\Repositories\MembersRepository' );
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
	 * Return a uniform missing-record error.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'ur_abilities_not_found', __( 'Registration record not found.', 'user-registration' ) );
	}
}
