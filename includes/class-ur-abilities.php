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
		 * Each area is an array with a translated `label`, an optional `description` shown as the access setting's tooltip, an optional `plan` (list of plan slugs allowed to use it,
		 * empty for every site) and `abilities`, keyed by ability name. Each ability takes:
		 * `label`, `description`, `level` (`read`, `write` or `delete`, default `read`), `input` (property schemas),
		 * `required` (property names), `output` (a schema, see UR_Abilities::object_schema()), `permission` and
		 * `execute` (callables), and optionally `idempotent`. Set `confirm` to true and give a `preview` callable that
		 * returns a plain sentence, and the ability first shows that preview and only runs when called again with the
		 * `confirm_token` it handed out. `confirm` can also be a callable that gets the input and returns whether this
		 * request needs the preview, so only the risky requests of an ability wait for a confirmation. The ability is
		 * registered as `user-registration/{name}`.
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
		$plans = isset( $area['plan'] ) ? (array) $area['plan'] : array();

		return self::plan_allows( $plans ) ? true : self::plan_required_error( $area['label'] ?? $slug, $plans, $slug );
	}

	/**
	 * Whether the site's plan is one of the listed plans. An empty list or a list with free in it allows every site.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $plans Plan slugs, for example personal, plus, professional.
	 * @return bool
	 */
	public static function plan_allows( $plans ) {
		$plans = array_filter( array_map( 'trim', array_map( 'strtolower', (array) $plans ) ) );

		if ( empty( $plans ) || in_array( 'free', $plans, true ) ) {
			return true;
		}

		// The license lookup leaves a space in front of the plan name, for example " plus".
		$license   = ur_get_license_plan();
		$site_plan = ! empty( $license->item_plan ) ? trim( strtolower( $license->item_plan ) ) : '';

		// The Agency license is named agency in the license and themegrill agency in the feature lists.
		return in_array( $site_plan, $plans, true ) || ( 'agency' === $site_plan && in_array( 'themegrill agency', $plans, true ) );
	}

	/**
	 * Build the answer for something the site's plan does not include, with the plans that do and the upgrade link.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $label   What is locked, for example Coupons.
	 * @param array  $plans   Plan slugs that include it.
	 * @param string $content Marks where the upgrade link was shown from.
	 * @return WP_Error
	 */
	public static function plan_required_error( $label, $plans, $content ) {
		$plans       = array_values( array_filter( array_map( 'trim', array_map( 'strtolower', (array) $plans ) ) ) );
		$recommended = self::lowest_plan( $plans );
		$names       = array(
			'personal'          => __( 'Personal', 'user-registration' ),
			'plus'              => __( 'Plus', 'user-registration' ),
			'professional'      => __( 'Professional', 'user-registration' ),
			'themegrill agency' => __( 'Agency', 'user-registration' ),
		);
		$url         = ur_utm_url(
			'https://wpuserregistration.com/upgrade/',
			array(
				'source'  => 'ai-abilities',
				'medium'  => 'button',
				'content' => $content,
			)
		);

		return new WP_Error(
			'ur_ability_plan_required',
			isset( $names[ $recommended ] )
				/* translators: 1: what is locked, for example Coupons. 2: plan name, for example Plus. 3: upgrade link. */
				? sprintf( __( 'Your plan does not include %1$s. Upgrade to the %2$s plan or a higher one to use it: %3$s', 'user-registration' ), $label, $names[ $recommended ], $url )
				/* translators: 1: what is locked, for example Coupons. 2: upgrade link. */
				: sprintf( __( 'Your plan does not include %1$s. Upgrade to a higher plan to use it: %2$s', 'user-registration' ), $label, $url ),
			array(
				'status'           => 403,
				'required_plans'   => $plans,
				'recommended_plan' => $recommended,
				'upgrade_url'      => $url,
			)
		);
	}

	/**
	 * Name the lowest plan that includes something.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $plans Plan slugs.
	 * @return string One of free, personal, plus, professional or themegrill agency.
	 */
	public static function lowest_plan( $plans ) {
		$plans = array_map( 'strtolower', (array) $plans );

		foreach ( array( 'free', 'personal', 'plus', 'professional', 'themegrill agency' ) as $plan ) {
			if ( in_array( $plan, $plans, true ) ) {
				return $plan;
			}
		}

		return 'free';
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
		$detail['properties']['layout'] = array(
			'type'        => 'array',
			'description' => 'Rows of columns of field names, as the form builder lays them out.',
			'items'       => array(
				'type'  => 'array',
				'items' => array(
					'type'  => 'array',
					'items' => $string,
				),
			),
		);
		$detail['required'][]           = 'layout';
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

		$deleted              = self::object_schema(
			array(
				'id'      => $id,
				'deleted' => array( 'type' => 'boolean' ),
				'message' => $string,
			)
		);
		$module               = self::object_schema(
			array(
				'slug'          => $string,
				'name'          => $string,
				'type'          => array(
					'type' => 'string',
					'enum' => array( 'module', 'addon' ),
				),
				'status'        => array(
					'type' => 'string',
					'enum' => array( 'active', 'inactive', 'not-installed' ),
				),
				'required_plan' => $string,
				'available'     => array( 'type' => 'boolean' ),
			)
		);
		$module_slug          = array(
			'type'      => 'string',
			'pattern'   => '^[a-z0-9_-]+$',
			'maxLength' => 100,
		);
		$label                = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 200,
		);
		$field_name           = array(
			'type'      => 'string',
			'pattern'   => '^[a-zA-Z_][a-zA-Z0-9_]*$',
			'maxLength' => 100,
		);
		$field_changes        = array(
			'label'       => $label,
			'required'    => array( 'type' => 'boolean' ),
			'placeholder' => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'description' => array(
				'type'      => 'string',
				'maxLength' => 500,
			),
			'options'     => array(
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => 50,
				'items'    => $label,
			),
		);
		$form_settings        = self::object_schema(
			array(
				'login_option'                => $key,
				'default_role'                => $key,
				'redirect_after_registration' => array(
					'type' => 'string',
					'enum' => array( 'no-redirection', 'internal-page', 'external-url', 'previous-page' ),
				),
				'redirect_page_id'            => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'redirect_url'                => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'redirect_delay'              => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 300,
				),
				'submit_label'                => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'success_message_position'    => array(
					'type' => 'integer',
					'enum' => array( 0, 1, 2 ),
				),
			)
		);
		$form_settings_result = self::object_schema(
			array(
				'id'        => $id,
				'title'     => $string,
				'status'    => $string,
				'shortcode' => $string,
				// Saved values may be empty or older than the input rules, so the answer only promises their types.
				'settings'  => self::object_schema(
					array_map(
						function ( $schema ) {
							return array( 'type' => $schema['type'] );
						},
						$form_settings['properties']
					)
				),
				'choices'   => self::object_schema(
					array(
						'login_option' => array(
							'type'  => 'array',
							'items' => $string,
						),
						'default_role' => array(
							'type'  => 'array',
							'items' => $string,
						),
					)
				),
			)
		);

		return array(
			'forms'       => array(
				'label'       => __( 'Forms', 'user-registration' ),
				'description' => __( 'Lets AI assistants see your registration forms and their fields. With change access they can also create a draft form from a template, add, change, move and remove fields, and change some form settings.', 'user-registration' ),
				'abilities'   => array(
					'list-forms'           => array(
						'label'       => __( 'List registration forms', 'user-registration' ),
						'description' => __( 'List editable registration forms. Pagination follows scanned form pages; inaccessible forms are omitted.', 'user-registration' ),
						'input'       => $pagination,
						'output'      => self::page_schema( $form ),
						'permission'  => array( __CLASS__, 'forms_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'list_forms' ),
					),
					'get-form'             => array(
						'label'       => __( 'Get registration form', 'user-registration' ),
						'description' => __( 'Read form identity and field definitions without raw settings or default values.', 'user-registration' ),
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $detail,
						'permission'  => array( __CLASS__, 'forms_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'get_form' ),
					),
					'list-form-templates'  => array(
						'label'       => __( 'List form templates', 'user-registration' ),
						'description' => __( 'List the templates a new registration form can start from, as on the Add New form screen. Templates that need a higher plan are marked as not available. Also says whether another form may be created.', 'user-registration' ),
						'output'      => self::object_schema(
							array(
								'items'           => array(
									'type'  => 'array',
									'items' => self::object_schema(
										array(
											'slug'      => $string,
											'title'     => $string,
											'pro'       => array( 'type' => 'boolean' ),
											'plans'     => array(
												'type'  => 'array',
												'items' => $string,
											),
											'addons'    => array(
												'type'  => 'array',
												'items' => $string,
											),
											'available' => array( 'type' => 'boolean' ),
										)
									),
								),
								'can_create_form' => array( 'type' => 'boolean' ),
							)
						),
						'permission'  => array( __CLASS__, 'forms_write_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'list_form_templates' ),
					),
					'create-form'          => array(
						'label'       => __( 'Create registration form', 'user-registration' ),
						'description' => __( 'Create a registration form from a template with a title, like Add New on the forms screen. The form starts as a draft for the admin to check and publish. A second form needs the Multiple Registration module.', 'user-registration' ),
						'level'       => 'write',
						'input'       => array(
							'title'    => $label,
							'template' => $key + array( 'default' => 'blank' ),
						),
						'required'    => array( 'title' ),
						'output'      => $detail,
						'permission'  => array( __CLASS__, 'forms_write_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'create_form' ),
					),
					'add-form-field'       => array(
						'label'       => __( 'Add form field', 'user-registration' ),
						'description' => __( 'Add a field to a form and save it like the form builder. It goes after after_field, or at the end of the form. type is one of this site\'s field types, for example text, email, select or checkbox; Pro fields need Pro and password fields cannot be added.', 'user-registration' ),
						'level'       => 'write',
						'input'       => array(
							'form_id'     => $id,
							'type'        => $key,
							'after_field' => $field_name,
						) + $field_changes,
						'required'    => array( 'form_id', 'type' ),
						'output'      => $detail,
						'permission'  => array( __CLASS__, 'forms_write_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'add_form_field' ),
					),
					'update-form-field'    => array(
						'label'       => __( 'Update form field', 'user-registration' ),
						'description' => __( 'Change a field\'s label, required setting, placeholder, description or choices, and save the form like the form builder. Nothing else about the field changes.', 'user-registration' ),
						'level'       => 'write',
						'input'       => array(
							'form_id'    => $id,
							'field_name' => $field_name,
						) + $field_changes,
						'required'    => array( 'form_id', 'field_name' ),
						'output'      => $detail,
						'permission'  => array( __CLASS__, 'forms_write_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'update_form_field' ),
					),
					'move-form-field'      => array(
						'label'       => __( 'Move form field', 'user-registration' ),
						'description' => __( 'Move a field to just after after_field, or to the top of the form when after_field is left out, and save the form like the form builder.', 'user-registration' ),
						'level'       => 'write',
						'input'       => array(
							'form_id'     => $id,
							'field_name'  => $field_name,
							'after_field' => $field_name,
						),
						'required'    => array( 'form_id', 'field_name' ),
						'output'      => $detail,
						'permission'  => array( __CLASS__, 'forms_write_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'move_form_field' ),
					),
					'set-form-layout'      => array(
						'label'       => __( 'Set form layout', 'user-registration' ),
						'description' => __( 'Lay out a form\'s fields in rows of 1 to 3 columns, like the column picker in the form builder, and save it. rows lists each row; each row lists its columns; each column lists field names from top to bottom. Every field of the form must be placed once. Read the form first to see its fields and current layout.', 'user-registration' ),
						'level'       => 'write',
						'input'       => array(
							'form_id' => $id,
							'rows'    => array(
								'type'     => 'array',
								'minItems' => 1,
								'maxItems' => 100,
								'items'    => array(
									'type'     => 'array',
									'minItems' => 1,
									'maxItems' => 3,
									'items'    => array(
										'type'     => 'array',
										'minItems' => 1,
										'maxItems' => 100,
										'items'    => $field_name,
									),
								),
							),
						),
						'required'    => array( 'form_id', 'rows' ),
						'output'      => $detail,
						'permission'  => array( __CLASS__, 'forms_write_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'set_form_layout' ),
					),
					'remove-form-field'    => array(
						'label'       => __( 'Remove form field', 'user-registration' ),
						'description' => __( 'Remove a field from a form and save it like the form builder. The email and password fields cannot be removed. Shows a preview first.', 'user-registration' ),
						'level'       => 'write',
						'confirm'     => true,
						'input'       => array(
							'form_id'    => $id,
							'field_name' => $field_name,
						),
						'required'    => array( 'form_id', 'field_name' ),
						'output'      => $detail,
						'permission'  => array( __CLASS__, 'forms_write_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_remove_form_field' ),
						'execute'     => array( 'UR_Abilities_Data', 'remove_form_field' ),
					),
					'get-form-settings'    => array(
						'label'       => __( 'Get form settings', 'user-registration' ),
						'description' => __( 'Read the form settings AI assistants may change, the roles and login options allowed, and the shortcode that places the form on a page.', 'user-registration' ),
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $form_settings_result,
						'permission'  => array( __CLASS__, 'forms_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'get_form_settings' ),
					),
					'update-form-settings' => array(
						'label'       => __( 'Update form settings', 'user-registration' ),
						'description' => __( 'Change some settings of a form: login option, role of new members, redirect after sign-up, submit button label and success message position. Other settings stay as they are. Admin-level roles are never allowed. Email settings are not included. Shows a preview first.', 'user-registration' ),
						'level'       => 'write',
						'confirm'     => true,
						'input'       => array(
							'form_id'  => $id,
							'settings' => array(
								'type'                 => 'object',
								'properties'           => $form_settings['properties'],
								'additionalProperties' => false,
								'minProperties'        => 1,
							),
						),
						'required'    => array( 'form_id', 'settings' ),
						'output'      => $form_settings_result,
						'permission'  => array( __CLASS__, 'forms_write_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_update_form_settings' ),
						'execute'     => array( 'UR_Abilities_Data', 'update_form_settings' ),
					),
				),
			),
			'members'     => array(
				'label'       => __( 'Members', 'user-registration' ),
				'description' => __( 'Lets AI assistants see registered members, their approval status and sign-up counts. With change access they can also approve, deny and resend the verification email, and with delete access delete a member.', 'user-registration' ),
				'abilities'   => array(
					'get-registration-stats'    => array(
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
					'list-members'              => array(
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
					'get-member'                => array(
						'label'       => __( 'Get registered member', 'user-registration' ),
						'description' => __( 'Read a registered member and their subscriptions. Membership availability is reported explicitly.', 'user-registration' ),
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $member_detail,
						'permission'  => array( __CLASS__, 'members_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'get_member' ),
					),
					'approve-member'            => array(
						'label'       => __( 'Approve member', 'user-registration' ),
						'description' => __( 'Approve a member who is waiting for approval or was denied, like the Approve button on the Users screen. Shows a preview first.', 'user-registration' ),
						'level'       => 'write',
						'confirm'     => true,
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $member,
						'permission'  => array( __CLASS__, 'moderate_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_approve_member' ),
						'execute'     => array( 'UR_Abilities_Data', 'approve_member' ),
					),
					'deny-member'               => array(
						'label'       => __( 'Deny member', 'user-registration' ),
						'description' => __( 'Deny a member so they cannot log in, like the Deny button on the Users screen. Shows a preview first.', 'user-registration' ),
						'level'       => 'write',
						'confirm'     => true,
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $member,
						'permission'  => array( __CLASS__, 'moderate_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_deny_member' ),
						'execute'     => array( 'UR_Abilities_Data', 'deny_member' ),
					),
					'resend-verification-email' => array(
						'label'       => __( 'Resend verification email', 'user-registration' ),
						'description' => __( 'Send the email confirmation link again to a member who has not confirmed their email yet.', 'user-registration' ),
						'level'       => 'write',
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $member,
						'permission'  => array( __CLASS__, 'moderate_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'resend_verification_email' ),
					),
					'delete-member'             => array(
						'label'       => __( 'Delete member', 'user-registration' ),
						'description' => __( 'Permanently delete one member, like Delete on the Users screen. If they own posts or links, set content_to to the user who gets them, or delete_content to true. You can never delete yourself. Shows a preview first.', 'user-registration' ),
						'level'       => 'delete',
						'confirm'     => true,
						'input'       => array(
							'id'             => $id,
							'content_to'     => $id,
							'delete_content' => array( 'type' => 'boolean' ),
						),
						'required'    => array( 'id' ),
						'output'      => $deleted,
						'permission'  => array( __CLASS__, 'delete_members_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_delete_member' ),
						'execute'     => array( 'UR_Abilities_Data', 'delete_member' ),
					),
				),
			),
			'memberships' => array(
				'label'       => __( 'Memberships', 'user-registration' ),
				'description' => __( 'Lets AI assistants see your membership plans and who is subscribed to them. With change access they can also assign a plan, extend a subscription or cancel one, and with delete access delete subscriptions, plans and plan groups.', 'user-registration' ),
				'abilities'   => array(
					'list-memberships'        => array(
						'label'       => __( 'List membership plans', 'user-registration' ),
						'description' => __( 'List membership plans with price, billing period and the number of members with an active subscription.', 'user-registration' ),
						'input'       => $pagination,
						'output'      => self::page_schema( $plan ),
						'permission'  => array( __CLASS__, 'members_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'list_memberships' ),
					),
					'list-subscriptions'      => array(
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
					'assign-membership'       => array(
						'label'       => __( 'Assign membership plan', 'user-registration' ),
						'description' => __( 'Give a member a plan for free, active from today, like Add Subscription on the Subscriptions screen with a manual payment. Recurring plans need Pro and end after one billing period unless expiry_date is given. Shows a preview first.', 'user-registration' ),
						'level'       => 'write',
						'confirm'     => true,
						'input'       => array(
							'member_id'     => $id,
							'membership_id' => $id,
							'expiry_date'   => $date,
						),
						'required'    => array( 'member_id', 'membership_id' ),
						'output'      => $subscription,
						'permission'  => array( __CLASS__, 'subscriptions_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_assign_membership' ),
						'execute'     => array( 'UR_Abilities_Data', 'assign_membership' ),
					),
					'extend-subscription'     => array(
						'label'       => __( 'Extend subscription', 'user-registration' ),
						'description' => __( 'Move an active subscription\'s expiry date later, like editing it on the Subscriptions screen. Needs Pro for recurring plans, and not for subscriptions billed by a payment gateway such as Stripe or PayPal. Shows a preview first.', 'user-registration' ),
						'level'       => 'write',
						'confirm'     => true,
						'input'       => array(
							'id'          => $id,
							'expiry_date' => $date,
						),
						'required'    => array( 'id', 'expiry_date' ),
						'output'      => $subscription,
						'permission'  => array( __CLASS__, 'subscriptions_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_extend_subscription' ),
						'execute'     => array( 'UR_Abilities_Data', 'extend_subscription' ),
					),
					'cancel-subscription'     => array(
						'label'       => __( 'Cancel subscription', 'user-registration' ),
						'description' => __( 'Cancel an active subscription the same way members cancel their own: billing stops at the payment gateway and paid access lasts until the expiry date. Recurring plans need Pro. Shows a preview first.', 'user-registration' ),
						'level'       => 'write',
						'confirm'     => true,
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $subscription,
						'permission'  => array( __CLASS__, 'subscriptions_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_cancel_subscription' ),
						'execute'     => array( 'UR_Abilities_Data', 'cancel_subscription' ),
					),
					'delete-subscription'     => array(
						'label'       => __( 'Delete subscription', 'user-registration' ),
						'description' => __( 'Permanently delete one subscription, like Delete on the Subscriptions screen: it is cancelled at the payment gateway right away first, without emailing the member. Recurring plans need Pro. Shows a preview first.', 'user-registration' ),
						'level'       => 'delete',
						'confirm'     => true,
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $deleted,
						'permission'  => array( __CLASS__, 'subscriptions_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_delete_subscription' ),
						'execute'     => array( 'UR_Abilities_Data', 'delete_subscription' ),
					),
					'delete-membership'       => array(
						'label'       => __( 'Delete membership plan', 'user-registration' ),
						'description' => __( 'Permanently delete one plan, like Delete on the Memberships screen. Refused while any member has an active, pending or trial subscription to it. Recurring plans need Pro. Shows a preview first.', 'user-registration' ),
						'level'       => 'delete',
						'confirm'     => true,
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $deleted,
						'permission'  => array( __CLASS__, 'subscriptions_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_delete_membership' ),
						'execute'     => array( 'UR_Abilities_Data', 'delete_membership' ),
					),
					'delete-membership-group' => array(
						'label'       => __( 'Delete plan group', 'user-registration' ),
						'description' => __( 'Permanently delete one plan group, like Delete on the Membership Groups screen. Refused while a registration form uses it. Needs Pro with a Plus or higher plan. Shows a preview first.', 'user-registration' ),
						'level'       => 'delete',
						'confirm'     => true,
						'input'       => array( 'id' => $id ),
						'required'    => array( 'id' ),
						'output'      => $deleted,
						'permission'  => array( __CLASS__, 'subscriptions_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_delete_membership_group' ),
						'execute'     => array( 'UR_Abilities_Data', 'delete_membership_group' ),
					),
				),
			),
			'payments'    => array(
				'label'       => __( 'Payments', 'user-registration' ),
				'description' => __( 'Lets AI assistants see membership payments and revenue totals.', 'user-registration' ),
				'abilities'   => array(
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
						'description' => __( 'Add up membership payments for a date range, 30 days by default and at most 366 days. Revenue counts completed payments only, while order counts include every status. Orders are also counted and added up by status, for example failed or refunded, and by plan.', 'user-registration' ),
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
			'modules'     => array(
				'label'       => __( 'Modules and addons', 'user-registration' ),
				'description' => __( 'Lets AI assistants see which modules and addons the site can use and turn them on or off.', 'user-registration' ),
				'abilities'   => array(
					'list-modules'   => array(
						'label'       => __( 'List modules and addons', 'user-registration' ),
						'description' => __( 'List the modules and addons, as on the Modules and Addons screen: whether each is active, inactive or not installed, the lowest plan that includes it, and whether this site\'s plan allows it. Filter by type or status.', 'user-registration' ),
						'input'       => $pagination + array(
							'type'   => array(
								'type' => 'string',
								'enum' => array( 'module', 'addon' ),
							),
							'status' => array(
								'type' => 'string',
								'enum' => array( 'active', 'inactive', 'not-installed' ),
							),
						),
						'output'      => self::page_schema( $module ),
						'permission'  => array( __CLASS__, 'modules_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'list_modules' ),
					),
					'enable-module'  => array(
						'label'       => __( 'Turn on a module or addon', 'user-registration' ),
						'description' => __( 'Turn on a module, or activate an addon that is already installed, like the Activate button on the Modules and Addons screen. A module or addon the site\'s plan does not include is refused with the plan it needs. Installing a new addon is not possible.', 'user-registration' ),
						'level'       => 'write',
						'idempotent'  => true,
						'input'       => array( 'slug' => $module_slug ),
						'required'    => array( 'slug' ),
						'output'      => $module,
						'permission'  => array( __CLASS__, 'modules_permission' ),
						'execute'     => array( 'UR_Abilities_Data', 'enable_module' ),
					),
					'disable-module' => array(
						'label'       => __( 'Turn off a module or addon', 'user-registration' ),
						'description' => __( 'Turn off a module, or deactivate an installed addon, like the Deactivate button on the Modules and Addons screen. Data stays saved, but the feature stops working until it is turned on again. Shows a preview first.', 'user-registration' ),
						'level'       => 'write',
						'idempotent'  => true,
						'confirm'     => true,
						'input'       => array( 'slug' => $module_slug ),
						'required'    => array( 'slug' ),
						'output'      => $module,
						'permission'  => array( __CLASS__, 'modules_permission' ),
						'preview'     => array( 'UR_Abilities_Data', 'preview_disable_module' ),
						'execute'     => array( 'UR_Abilities_Data', 'disable_module' ),
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
	 * Match the capability the form builder asks for to create or save a form.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Input.
	 * @return bool
	 */
	public static function forms_write_permission( $input ) {
		$input = is_array( $input ) ? $input : array();

		return current_user_can( 'manage_options' ) && self::forms_permission( array( 'id' => $input['form_id'] ?? ( $input['id'] ?? 0 ) ) );
	}

	/**
	 * Require both registration administration and user-list access.
	 *
	 * @return bool
	 */
	public static function members_permission() {
		return current_user_can( 'manage_user_registration' ) && current_user_can( 'list_users' );
	}

	/**
	 * Match the capability the Users screen asks for to approve, deny or resend.
	 *
	 * @since xx.xx.xx
	 *
	 * @return bool
	 */
	public static function moderate_permission() {
		return self::members_permission() && current_user_can( 'promote_users' );
	}

	/**
	 * Match the capability the Subscriptions screen asks for to add or edit a subscription.
	 *
	 * @since xx.xx.xx
	 *
	 * @return bool
	 */
	public static function subscriptions_permission() {
		return self::members_permission() && current_user_can( 'manage_options' );
	}

	/**
	 * Match the capability the Users screen asks for to delete members.
	 *
	 * @since xx.xx.xx
	 *
	 * @return bool
	 */
	public static function delete_members_permission() {
		return self::members_permission() && current_user_can( 'delete_users' );
	}

	/**
	 * Match the capability the Modules and Addons screen asks for to turn a module or addon on or off.
	 *
	 * @since xx.xx.xx
	 *
	 * @return bool
	 */
	public static function modules_permission() {
		return current_user_can( 'manage_user_registration' ) && current_user_can( 'activate_plugins' );
	}
}
