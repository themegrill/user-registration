<?php
/**
 * WordPress abilities shared by Free, Pro and addons.
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
		UR_Abilities_Settings::init();
		UR_Abilities_Access::init();
	}

	/** Register the shared category. */
	public static function register_category() {
		if ( ! self::enabled() ) {
			return;
		}
		wp_register_ability_category(
			'user-registration',
			array(
				'label'       => __( 'User Registration', 'user-registration' ),
				'description' => __( 'Read registration forms, members, membership plans, subscriptions and payments.', 'user-registration' ),
			)
		);
	}

	/**
	 * Whether the site admin allows AI abilities.
	 *
	 * Off for new installs; existing sites are switched on once by UR_Install on update.
	 *
	 * @return bool
	 */
	private static function enabled() {
		return ur_option_checked( 'user_registration_enable_abilities', false );
	}

	/**
	 * Get every ability area: the built-in ones first, then the ones added by modules and addons.
	 *
	 * @since xx.xx.xx
	 *
	 * @return array Areas keyed by slug.
	 */
	public static function get_areas() {
		/**
		 * Filters the AI ability areas, so a module or an addon can add its own from its own code.
		 *
		 * Each area is an array with a translated `label`, an optional `plan` (list of plan slugs allowed to use it,
		 * empty for every site) and `abilities`, keyed by ability name. Each ability takes:
		 * `label`, `description`, `level` (`read`, `write` or `delete`, default `read`), `input` (property schemas),
		 * `required` (property names), `output` (a schema, see UR_Abilities::object_schema()), `permission` and
		 * `execute` (callables), and optionally `idempotent`. Set `confirm` to true and give a `preview` callable that
		 * returns a plain sentence, and the ability first shows that preview and only runs when called again with the
		 * `confirm_token` it handed out. The ability is registered as `user-registration/{name}`.
		 *
		 * An addon adds nothing when it is not active, because its code never runs to add the area.
		 *
		 *     add_filter(
		 *         'user_registration_ability_areas',
		 *         function ( $areas ) {
		 *             $areas['gift-cards'] = array(
		 *                 'label'     => __( 'Gift cards', 'my-addon' ),
		 *                 'plan'      => array( 'plus', 'professional' ),
		 *                 'abilities' => array(
		 *                     'list-gift-cards' => array(
		 *                         'label'       => __( 'List gift cards', 'my-addon' ),
		 *                         'description' => __( 'List gift cards.', 'my-addon' ),
		 *                         'output'      => UR_Abilities::object_schema( array( 'total' => array( 'type' => 'integer' ) ) ),
		 *                         'permission'  => array( 'My_Addon_Abilities', 'can_read' ),
		 *                         'execute'     => array( 'My_Addon_Abilities', 'list_gift_cards' ),
		 *                     ),
		 *                 ),
		 *             );
		 *             return $areas;
		 *         }
		 *     );
		 *
		 * @since xx.xx.xx
		 *
		 * @param array $areas Areas keyed by slug.
		 */
		$areas = apply_filters( 'user_registration_ability_areas', self::core_areas() );

		return is_array( $areas ) ? $areas : array();
	}

	/** Register every area through the same path, so each ability is treated alike. */
	public static function register_abilities() {
		if ( ! self::enabled() ) {
			return;
		}

		$registered = array();

		foreach ( self::get_areas() as $slug => $area ) {
			if ( empty( $area['abilities'] ) || ! is_array( $area['abilities'] ) ) {
				continue;
			}

			foreach ( $area['abilities'] as $name => $ability ) {
				$name = sanitize_key( $name );

				if ( '' === $name || isset( $registered[ $name ] ) || ! self::is_complete( $ability ) ) {
					continue;
				}

				$registered[ $name ] = true;

				$level = self::level( $ability );

				/**
				 * Filters whether an ability is available to AI assistants.
				 *
				 * The default follows the access the admin chose for the area. A module can hook in to hide
				 * abilities for its own reasons. A hidden ability is not registered, so the AI cannot see or call it.
				 *
				 * @since xx.xx.xx
				 *
				 * @param bool   $enabled Whether the ability is available.
				 * @param string $slug    Area slug.
				 * @param string $name    Ability name, without the `user-registration/` prefix.
				 * @param string $level   Level of the ability: `read`, `write` or `delete`.
				 */
				if ( ! apply_filters( 'user_registration_ability_enabled', UR_Abilities_Access::allows( $slug, $level ), $slug, $name, $level ) ) {
					continue;
				}

				$confirm    = ! empty( $ability['confirm'] );
				$properties = $ability['input'] ?? array();

				if ( $confirm ) {
					$properties['confirm_token'] = UR_Abilities_Safety::token_schema();
				}

				$input = self::object_schema( $properties );

				$input['required'] = $ability['required'] ?? array();

				if ( empty( $input['required'] ) ) {
					$input['default'] = array();
				}

				wp_register_ability(
					'user-registration/' . $name,
					array(
						'label'               => $ability['label'],
						'description'         => $ability['description'],
						'category'            => 'user-registration',
						'input_schema'        => $input,
						'output_schema'       => $confirm ? UR_Abilities_Safety::output_schema( $ability['output'] ) : $ability['output'],
						'permission_callback' => UR_Abilities_Activity::wrap_permission(
							$name,
							$level,
							function ( $args = null ) use ( $slug, $area, $ability ) {
								$locked = self::plan_error( $slug, $area );

								return is_wp_error( $locked ) ? $locked : call_user_func( $ability['permission'], $args );
							}
						),
						'execute_callback'    => UR_Abilities_Activity::wrap_execute( $name, $level, $confirm ? UR_Abilities_Safety::wrap( $name, $ability ) : $ability['execute'] ),
						'meta'                => array(
							'show_in_rest' => true,
							'mcp'          => array(
								'public' => true,
								'type'   => 'tool',
							),
							'annotations'  => array(
								'readonly'    => 'read' === $level,
								'destructive' => 'delete' === $level,
								'idempotent'  => $ability['idempotent'] ?? ( 'read' === $level ),
							),
						),
					)
				);
			}
		}
	}

	/**
	 * Get the level of an ability.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $ability Ability definition.
	 * @return string `read`, `write` or `delete`.
	 */
	public static function level( $ability ) {
		return isset( $ability['level'] ) && in_array( $ability['level'], array( 'write', 'delete' ), true ) ? $ability['level'] : 'read';
	}

	/**
	 * Whether an ability has what it needs to be registered.
	 *
	 * @since xx.xx.xx
	 *
	 * @param mixed $ability Ability definition.
	 * @return bool
	 */
	private static function is_complete( $ability ) {
		return is_array( $ability )
			&& ! empty( $ability['label'] )
			&& is_string( $ability['label'] )
			&& ! empty( $ability['description'] )
			&& is_string( $ability['description'] )
			&& ! empty( $ability['output'] )
			&& is_array( $ability['output'] )
			&& is_array( $ability['input'] ?? array() )
			&& is_array( $ability['required'] ?? array() )
			&& is_callable( $ability['permission'] ?? null )
			&& is_callable( $ability['execute'] ?? null )
			&& ( empty( $ability['confirm'] ) || is_callable( $ability['preview'] ?? null ) );
	}

	/**
	 * Tell the AI which plan an area needs when the site's plan does not include it.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $slug Area slug.
	 * @param array  $area Area definition.
	 * @return true|WP_Error True when the site may use the area.
	 */
	private static function plan_error( $slug, $area ) {
		$plans = isset( $area['plan'] ) ? array_filter( array_map( 'trim', array_map( 'strtolower', (array) $area['plan'] ) ) ) : array();

		if ( empty( $plans ) ) {
			return true;
		}

		// The license lookup leaves a space in front of the plan name, for example " plus".
		$license   = ur_get_license_plan();
		$site_plan = ! empty( $license->item_plan ) ? trim( strtolower( $license->item_plan ) ) : '';

		if ( in_array( $site_plan, $plans, true ) ) {
			return true;
		}

		return new WP_Error(
			'ur_ability_plan_required',
			/* translators: %s: area name, for example Coupons. */
			sprintf( __( '%s is not part of this site\'s plan. Upgrade to use it.', 'user-registration' ), $area['label'] ?? $slug ),
			array(
				'status'         => 403,
				'required_plans' => array_values( $plans ),
				'upgrade_url'    => ur_utm_url(
					'https://wpuserregistration.com/upgrade/',
					array(
						'source'  => 'ai-abilities',
						'medium'  => 'button',
						'content' => $slug,
					)
				),
			)
		);
	}

	/**
	 * The areas that ship with the plugin.
	 *
	 * @since xx.xx.xx
	 *
	 * @return array
	 */
	private static function core_areas() {
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
		$date            = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		$stats           = self::object_schema(
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
		$nullable_string = array( 'type' => array( 'string', 'null' ) );
		$count           = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$key             = array(
			'type'      => 'string',
			'pattern'   => '^[a-z0-9_-]+$',
			'maxLength' => 32,
		);
		$plan            = self::object_schema(
			array(
				'id'               => $id,
				'name'             => $string,
				'enabled'          => array( 'type' => 'boolean' ),
				'type'             => $string,
				'amount'           => $string,
				'currency'         => $string,
				'billing_interval' => array( 'type' => array( 'integer', 'null' ) ),
				'billing_period'   => $nullable_string,
				'active_members'   => $count,
			)
		);
		$subscription    = self::object_schema(
			array(
				'id'                => $id,
				'member_id'         => $count,
				'member_email'      => $string,
				'membership_id'     => $count,
				'membership_name'   => $string,
				'status'            => $string,
				'start_date'        => $string,
				'expiry_date'       => $string,
				'next_billing_date' => $string,
				'billing_amount'    => $string,
			)
		);
		$order           = self::object_schema(
			array(
				'id'              => $id,
				'member_email'    => $string,
				'member_name'     => $string,
				'membership_id'   => $count,
				'membership_name' => $string,
				'subscription_id' => $count,
				'status'          => $string,
				'type'            => $string,
				'gateway'         => $string,
				'transaction_id'  => $string,
				'amount'          => $string,
				'currency'        => $string,
				'created_at'      => $string,
			)
		);
		$order_detail    = self::object_schema(
			$order['properties'] + array(
				'member_id'           => $count,
				'subscription_status' => $string,
				'expiry_date'         => $string,
				'next_billing_date'   => $string,
				'coupon'              => $string,
			)
		);
		$revenue_summary = self::object_schema(
			array(
				'date_from' => $date,
				'date_to'   => $date,
				'currency'  => $string,
				'orders'    => $count,
				'revenue'   => $string,
				'by_status' => array(
					'type'  => 'array',
					'items' => self::object_schema(
						array(
							'status' => $string,
							'orders' => $count,
							'amount' => $string,
						)
					),
				),
				'by_plan'   => array(
					'type'  => 'array',
					'items' => self::object_schema(
						array(
							'membership_id'   => $count,
							'membership_name' => $string,
							'orders'          => $count,
							'revenue'         => $string,
						)
					),
				),
			)
		);

		return array(
			'forms'       => array(
				'label'     => __( 'Forms', 'user-registration' ),
				'abilities' => array(
					'list-forms' => array(
						'label'       => __( 'List registration forms', 'user-registration' ),
						'description' => __( 'List editable registration forms. Pagination follows scanned form pages; inaccessible forms are omitted.', 'user-registration' ),
						'input'       => $pagination,
						'output'      => self::page_schema( $form ),
						'permission'  => array( __CLASS__, 'forms_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'list_forms' ),
					),
					'get-form'   => array(
						'label'       => __( 'Get registration form', 'user-registration' ),
						'description' => __( 'Read form identity and field definitions without raw settings or default values.', 'user-registration' ),
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $detail,
						'permission'  => array( __CLASS__, 'forms_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'get_form' ),
					),
				),
			),
			'members'     => array(
				'label'     => __( 'Members', 'user-registration' ),
				'abilities' => array(
					'get-registration-stats' => array(
						'label'       => __( 'Get registration statistics', 'user-registration' ),
						'description' => __( 'Count form registrations by UTC day, form and current approval status. Defaults to 30 days; maximum 93 days and 10000 registrations. These are not historical status transitions or conversion analytics.', 'user-registration' ),
						'input'       => array(
							'date_from' => $date,
							'date_to'   => $date,
							'form_id'   => $id,
						),
						'output'      => $stats,
						'permission'  => array( __CLASS__, 'members_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'get_registration_stats' ),
					),
					'list-members'           => array(
						'label'       => __( 'List registered members', 'user-registration' ),
						'description' => __( 'List users registered through a form or the membership module on this site. Filter by approval status or email verification to find members waiting for review; filtering scans at most 10000 members.', 'user-registration' ),
						'input'       => $pagination + array(
							'form_id'        => $id,
							'status'         => array(
								'type' => 'string',
								'enum' => array( 'approved', 'pending', 'denied' ),
							),
							'email_verified' => array( 'type' => 'boolean' ),
						),
						'output'      => self::page_schema( $member ),
						'permission'  => array( __CLASS__, 'members_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'list_members' ),
					),
					'get-member'             => array(
						'label'       => __( 'Get registered member', 'user-registration' ),
						'description' => __( 'Read a registered member and their subscriptions. Membership availability is reported explicitly.', 'user-registration' ),
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $member_detail,
						'permission'  => array( __CLASS__, 'members_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'get_member' ),
					),
				),
			),
			'memberships' => array(
				'label'     => __( 'Memberships and payments', 'user-registration' ),
				'abilities' => array(
					'list-memberships'    => array(
						'label'       => __( 'List membership plans', 'user-registration' ),
						'description' => __( 'List membership plans with price, billing period and the number of members with an active subscription.', 'user-registration' ),
						'input'       => $pagination,
						'output'      => self::page_schema( $plan ),
						'permission'  => array( __CLASS__, 'members_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'list_memberships' ),
					),
					'list-subscriptions'  => array(
						'label'       => __( 'List membership subscriptions', 'user-registration' ),
						'description' => __( 'List subscriptions by status, plan, member or expiry date range, for example to find memberships that expire soon.', 'user-registration' ),
						'input'       => $pagination + array(
							'status'        => $key,
							'membership_id' => $id,
							'member_id'     => $id,
							'expiry_from'   => $date,
							'expiry_to'     => $date,
						),
						'output'      => self::page_schema( $subscription ),
						'permission'  => array( __CLASS__, 'members_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'list_subscriptions' ),
					),
					'list-orders'         => array(
						'label'       => __( 'List membership payments', 'user-registration' ),
						'description' => __( 'List membership payments, newest first, by status, gateway, plan or payment date range.', 'user-registration' ),
						'input'       => $pagination + array(
							'status'        => $key,
							'gateway'       => $key,
							'membership_id' => $id,
							'date_from'     => $date,
							'date_to'       => $date,
						),
						'output'      => self::page_schema( $order ),
						'permission'  => array( __CLASS__, 'members_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'list_orders' ),
					),
					'get-order'           => array(
						'label'       => __( 'Get membership payment', 'user-registration' ),
						'description' => __( 'Read one membership payment with its subscription status and dates.', 'user-registration' ),
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $order_detail,
						'permission'  => array( __CLASS__, 'members_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'get_order' ),
					),
					'get-revenue-summary' => array(
						'label'       => __( 'Get revenue summary', 'user-registration' ),
						'description' => __( 'Add up membership payments for a date range, 30 days by default and at most 366 days. Revenue counts completed payments only. Orders are also counted and added up by status, for example failed or refunded, and by plan.', 'user-registration' ),
						'input'       => array(
							'date_from'     => $date,
							'date_to'       => $date,
							'membership_id' => $id,
						),
						'output'      => $revenue_summary,
						'permission'  => array( __CLASS__, 'members_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'get_revenue_summary' ),
					),
				),
			),
		);
	}

	/**
	 * Build a closed object schema.
	 *
	 * @param array $properties Properties.
	 * @return array
	 */
	public static function object_schema( $properties ) {
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
	public static function page_schema( $item ) {
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
