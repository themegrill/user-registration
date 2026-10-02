<?php
/**
 * Read-only WordPress abilities shared by Free and Pro.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Registers abilities only when WordPress initializes its native API. */
class UR_Abilities {
	/** Attach lazy hooks; older WordPress versions never invoke them. */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/** Register the shared category. */
	public static function register_category() {
		wp_register_ability_category(
			'user-registration',
			array(
				'label'       => __( 'User Registration', 'user-registration' ),
				'description' => __( 'Read registration forms and member information.', 'user-registration' ),
			)
		);
	}

	/** Register bounded inputs and explicit output contracts. */
	public static function register_abilities() {
		$id                             = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$string                         = array( 'type' => 'string' );
		$pagination                     = array(
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 10000,
				'default' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			),
		);
		$form                           = self::object_schema(
			array(
				'id'     => $id,
				'title'  => $string,
				'status' => $string,
			)
		);
		$member                         = self::object_schema(
			array(
				'id'                  => $id,
				'username'            => $string,
				'email'               => $string,
				'display_name'        => $string,
				'registered_at'       => $string,
				'form_id'             => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'registration_status' => array(
					'type' => 'string',
					'enum' => array( 'approved', 'pending', 'denied', 'unknown' ),
				),
				'email_verified'      => array( 'type' => array( 'boolean', 'null' ) ),
			)
		);
		$detail                         = $form;
		$detail['properties']['fields'] = array(
			'type'  => 'array',
			'items' => self::object_schema(
				array(
					'name'     => $string,
					'type'     => $string,
					'label'    => $string,
					'required' => array( 'type' => 'boolean' ),
				)
			),
		);
		$detail['required'][]           = 'fields';
		$member_detail                  = $member;
		$member_detail['properties']['membership_available'] = array( 'type' => 'boolean' );
		$member_detail['properties']['subscriptions']        = array(
			'type'  => 'array',
			'items' => self::object_schema(
				array(
					'id'            => $id,
					'membership_id' => $id,
					'name'          => $string,
					'status'        => $string,
					'start_date'    => $string,
					'expiry_date'   => $string,
				)
			),
		);
		$member_detail['required'][]                         = 'membership_available';
		$member_detail['required'][]                         = 'subscriptions';
		$date        = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		$stats       = self::object_schema(
			array(
				'date_from' => $date,
				'date_to'   => $date,
				'timezone'  => $string,
				'total'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'groups'    => array(
					'type'  => 'array',
					'items' => self::object_schema(
						array(
							'date'    => $date,
							'form_id' => $id,
							'status'  => $member['properties']['registration_status'],
							'count'   => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						)
					),
				),
			)
		);
		$definitions = array(
			'list-forms'             => array( __( 'List registration forms', 'user-registration' ), __( 'List editable registration forms. Pagination follows scanned form pages; inaccessible forms are omitted.', 'user-registration' ), $pagination, array(), self::page_schema( $form ), 'forms_permission' ),
			'get-form'               => array( __( 'Get registration form', 'user-registration' ), __( 'Read form identity and field definitions without raw settings or default values.', 'user-registration' ), array( 'id' => $id ), array( 'id' ), $detail, 'forms_permission' ),
			'get-registration-stats' => array(
				__( 'Get registration statistics', 'user-registration' ),
				__( 'Count form registrations by UTC day, form and current approval status. Defaults to 30 days; maximum 93 days and 10000 registrations. These are not historical status transitions or conversion analytics.', 'user-registration' ),
				array(
					'date_from' => $date,
					'date_to'   => $date,
					'form_id'   => $id,
				),
				array(),
				$stats,
				'members_permission',
			),
			'list-members'           => array( __( 'List registered members', 'user-registration' ), __( 'List users registered through a form or the membership module on this site.', 'user-registration' ), $pagination + array( 'form_id' => $id ), array(), self::page_schema( $member ), 'members_permission' ),
			'get-member'             => array( __( 'Get registered member', 'user-registration' ), __( 'Read a registered member and their subscriptions. Membership availability is reported explicitly.', 'user-registration' ), array( 'id' => $id ), array( 'id' ), $member_detail, 'members_permission' ),
		);
		foreach ( $definitions as $name => $definition ) {
			$input             = self::object_schema( $definition[2] );
			$input['required'] = $definition[3];
			if ( empty( $definition[3] ) ) {
				$input['default'] = array();
			}
			wp_register_ability(
				'user-registration/' . $name,
				array(
					'label'               => $definition[0],
					'description'         => $definition[1],
					'category'            => 'user-registration',
					'input_schema'        => $input,
					'output_schema'       => $definition[4],
					'permission_callback' => array( __CLASS__, $definition[5] ),
					'execute_callback'    => array( 'UR_Abilities_Data', str_replace( '-', '_', $name ) ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array(
							'public' => true,
							'type'   => 'tool',
						),
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);
		}
	}

	/**
	 * Build a closed object schema.
	 *
	 * @param array $properties Properties.
	 * @return array
	 */
	private static function object_schema( $properties ) {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Build a paginated response schema.
	 *
	 * @param array $item Item schema.
	 * @return array
	 */
	private static function page_schema( $item ) {
		return self::object_schema(
			array(
				'items'    => array(
					'type'  => 'array',
					'items' => $item,
				),
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
				'has_more' => array( 'type' => 'boolean' ),
			)
		);
	}

	/**
	 * Match the form administration capability and object access.
	 *
	 * @param array $input Input.
	 * @return bool
	 */
	public static function forms_permission( $input ) {
		return current_user_can( 'manage_user_registration' ) && ( empty( $input['id'] ) || current_user_can( 'edit_post', $input['id'] ) );
	}

	/**
	 * Require both registration administration and user-list access.
	 *
	 * @return bool
	 */
	public static function members_permission() {
		return current_user_can( 'manage_user_registration' ) && current_user_can( 'list_users' );
	}
}
