<?php
/**
 * AI abilities for content access rules.
 *
 * @package UserRegistrationContentRestriction
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Content rules area to the AI abilities. Every change goes through the Content Access Rules API that the
 * rules screen uses, and every explanation through the same access check the site applies.
 */
class URCR_Abilities {

	/** Condition types the AI may use, matched by urcr_is_allow_access(). */
	const CONDITION_TYPES = array( 'roles', 'capabilities', 'user_state', 'membership', 'email_domain', 'registration_source', 'post_count', 'profile_completeness' );

	/** Target types the AI may use, matched by urcr_is_target_post(). */
	const TARGET_TYPES = array( 'wp_pages', 'wp_posts', 'post_types', 'taxonomy', 'whole_site' );

	/** Actions the AI may set for visitors who are kept out. */
	const ACTION_TYPES = array( 'message', 'redirect', 'local_page' );

	/**
	 * Add the area while the Content Restriction module is on.
	 *
	 * @since xx.xx.xx
	 */
	public static function init() {
		add_filter( 'user_registration_ability_areas', array( __CLASS__, 'add_area' ) );
	}

	/**
	 * Add the Content rules area to the AI ability areas.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $areas Areas keyed by slug.
	 * @return array
	 */
	public static function add_area( $areas ) {
		if ( ! class_exists( 'UR_Abilities' ) || ! ur_check_module_activation( 'content-restriction' ) ) {
			return $areas;
		}

		$id     = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$string = array( 'type' => 'string' );
		$rule   = UR_Abilities::object_schema(
			array(
				'id'              => $id,
				'title'           => $string,
				'enabled'         => array( 'type' => 'boolean' ),
				'access'          => $string,
				'condition_logic' => $string,
				'conditions'      => array( 'type' => 'array' ),
				'targets'         => array( 'type' => 'array' ),
				'action'          => array( 'type' => 'object' ),
				'rule_type'       => $string,
				'whole_site'      => array( 'type' => 'boolean' ),
			)
		);
		$fields = array(
			'title'           => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'access'          => array(
				'type'        => 'string',
				'enum'        => array( 'access', 'restrict' ),
				'description' => 'access: users who meet the conditions may see the targets. restrict: users who meet the conditions are kept out.',
			),
			'condition_logic' => array(
				'type' => 'string',
				'enum' => array( 'AND', 'OR', 'NOT' ),
			),
			'conditions'      => array(
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => 20,
				'items'    => UR_Abilities::object_schema(
					array(
						'type'  => array(
							'type' => 'string',
							'enum' => self::CONDITION_TYPES,
						),
						'value' => array(
							'type'        => array( 'string', 'integer', 'array' ),
							'maxLength'   => 500,
							'maxItems'    => 100,
							'items'       => array( 'type' => array( 'string', 'integer' ) ),
							'description' => 'roles, capabilities, membership (plan IDs) and registration_source take a list; user_state takes logged-in or logged-out; email_domain a comma separated list; post_count and profile_completeness a number.',
						),
					)
				),
			),
			'targets'         => array(
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => 20,
				'items'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'type'     => array(
							'type' => 'string',
							'enum' => self::TARGET_TYPES,
						),
						'values'   => array(
							'type'        => 'array',
							'maxItems'    => 500,
							'items'       => array( 'type' => array( 'string', 'integer' ) ),
							'description' => 'Page or post IDs, post type slugs, or term IDs. Leave out for whole_site.',
						),
						'taxonomy' => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
					),
					'required'             => array( 'type' ),
					'additionalProperties' => false,
				),
			),
			'action'          => array(
				'type'                 => 'object',
				'properties'           => array(
					'type'         => array(
						'type' => 'string',
						'enum' => self::ACTION_TYPES,
					),
					'message'      => array(
						'type'      => 'string',
						'maxLength' => 5000,
					),
					'redirect_url' => array(
						'type'      => 'string',
						'maxLength' => 2000,
					),
					'local_page'   => $id,
				),
				'required'             => array( 'type' ),
				'additionalProperties' => false,
			),
		);

		$areas['content-rules'] = array(
			'label'       => __( 'Content rules', 'user-registration' ),
			'description' => __( 'Lets AI assistants see your content access rules and explain why someone can or cannot see a page. With change access they can also create, change, switch on or off and copy rules, and with delete access move a rule to the trash.', 'user-registration' ),
			'abilities'   => array(
				'list-content-rules'      => array(
					'label'       => __( 'List content rules', 'user-registration' ),
					'description' => __( 'List the content access rules, newest first. The newest matching rule that keeps a visitor out decides what they see, and any matching rule that lets them in wins.', 'user-registration' ),
					'output'      => UR_Abilities::object_schema(
						array(
							'items' => array(
								'type'  => 'array',
								'items' => $rule,
							),
						)
					),
					'permission'  => array( __CLASS__, 'can_manage' ),
					'execute'     => array( __CLASS__, 'list_rules' ),
				),
				'get-content-rule'        => array(
					'label'       => __( 'Get content rule', 'user-registration' ),
					'description' => __( 'Read one content access rule: its conditions, targets and action.', 'user-registration' ),
					'input'       => array( 'id' => $id ),
					'required'    => array( 'id' ),
					'output'      => $rule,
					'permission'  => array( __CLASS__, 'can_manage' ),
					'execute'     => array( __CLASS__, 'get_rule' ),
				),
				'explain-content-access'  => array(
					'label'       => __( 'Explain content access', 'user-registration' ),
					'description' => __( 'Say whether a user, or a logged-out visitor when user_id is left out, can see a page or post, and which rule or setting decides it, using the same check the site applies.', 'user-registration' ),
					'input'       => array(
						'post_id' => $id,
						'user_id' => $id,
					),
					'required'    => array( 'post_id' ),
					'output'      => UR_Abilities::object_schema(
						array(
							'can_view'           => array( 'type' => 'boolean' ),
							'reason'             => $string,
							'deciding_rule_id'   => array( 'type' => 'integer' ),
							'deciding_rule'      => $string,
							'letting_in_rule_id' => array( 'type' => 'integer' ),
						)
					),
					'permission'  => array( __CLASS__, 'can_manage' ),
					'execute'     => array( __CLASS__, 'explain_access' ),
				),
				'create-content-rule'     => array(
					'label'       => __( 'Create content rule', 'user-registration' ),
					'description' => __( 'Create a content access rule, like the Content Access Rules screen. A rule that covers the whole site shows a preview first.', 'user-registration' ),
					'level'       => 'write',
					'confirm'     => array( __CLASS__, 'needs_confirmation' ),
					'input'       => $fields,
					'required'    => array( 'title', 'access', 'conditions', 'targets', 'action' ),
					'output'      => $rule,
					'permission'  => array( __CLASS__, 'can_manage' ),
					'preview'     => array( __CLASS__, 'preview_save' ),
					'execute'     => array( __CLASS__, 'create_rule' ),
				),
				'update-content-rule'     => array(
					'label'       => __( 'Update content rule', 'user-registration' ),
					'description' => __( 'Change a content access rule, like the Content Access Rules screen. Only the given parts change. A change that leaves an active rule covering the whole site shows a preview first.', 'user-registration' ),
					'level'       => 'write',
					'confirm'     => array( __CLASS__, 'needs_confirmation' ),
					'input'       => array( 'id' => $id ) + $fields,
					'required'    => array( 'id' ),
					'output'      => $rule,
					'permission'  => array( __CLASS__, 'can_manage' ),
					'preview'     => array( __CLASS__, 'preview_save' ),
					'execute'     => array( __CLASS__, 'update_rule' ),
				),
				'set-content-rule-status' => array(
					'label'       => __( 'Switch content rule on or off', 'user-registration' ),
					'description' => __( 'Switch a content access rule on or off. Switching a rule off is the safe way to remove its effect. Switching on a rule that covers the whole site shows a preview first.', 'user-registration' ),
					'level'       => 'write',
					'confirm'     => array( __CLASS__, 'needs_confirmation' ),
					'input'       => array(
						'id'      => $id,
						'enabled' => array( 'type' => 'boolean' ),
					),
					'required'    => array( 'id', 'enabled' ),
					'output'      => $rule,
					'permission'  => array( __CLASS__, 'can_manage' ),
					'preview'     => array( __CLASS__, 'preview_status' ),
					'execute'     => array( __CLASS__, 'set_status' ),
				),
				'duplicate-content-rule'  => array(
					'label'       => __( 'Copy content rule', 'user-registration' ),
					'description' => __( 'Make a copy of a content access rule, like Duplicate on the Content Access Rules screen. Membership rules cannot be copied.', 'user-registration' ),
					'level'       => 'write',
					'input'       => array( 'id' => $id ),
					'required'    => array( 'id' ),
					'output'      => $rule,
					'permission'  => array( __CLASS__, 'can_manage' ),
					'execute'     => array( __CLASS__, 'duplicate_rule' ),
				),
				'trash-content-rule'      => array(
					'label'       => __( 'Trash content rule', 'user-registration' ),
					'description' => __( 'Move a content access rule to the trash. It is never deleted for good. Membership rules cannot be trashed. Shows a preview first.', 'user-registration' ),
					'level'       => 'delete',
					'confirm'     => true,
					'input'       => array( 'id' => $id ),
					'required'    => array( 'id' ),
					'output'      => UR_Abilities::object_schema(
						array(
							'id'      => $id,
							'trashed' => array( 'type' => 'boolean' ),
						)
					),
					'permission'  => array( __CLASS__, 'can_manage' ),
					'preview'     => array( __CLASS__, 'preview_trash' ),
					'execute'     => array( __CLASS__, 'trash_rule' ),
				),
			),
		);

		return $areas;
	}

	/**
	 * Match the capability the Content Access Rules API asks for.
	 *
	 * @since xx.xx.xx
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * List the rules through the rules API.
	 *
	 * @since xx.xx.xx
	 *
	 * @return array|WP_Error
	 */
	public static function list_rules() {
		$data = self::api( 'get_rules' );

		return is_wp_error( $data ) ? $data : array( 'items' => array_map( array( __CLASS__, 'rule' ), $data['rules'] ) );
	}

	/**
	 * Read one rule through the rules API.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_rule( $input ) {
		$data = self::api( 'get_rule', array( 'id' => $input['id'] ) );

		return is_wp_error( $data ) ? $data : self::rule( $data['rule'] );
	}

	/**
	 * Say whether a user can see a post, through the same check the site applies.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function explain_access( $input ) {
		$post = get_post( $input['post_id'] );

		if ( ! $post || 'trash' === $post->post_status ) {
			return new WP_Error( 'ur_abilities_not_found', __( 'Page or post not found.', 'user-registration' ), array( 'status' => 404 ) );
		}

		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;

		if ( $user_id && ! get_userdata( $user_id ) ) {
			return new WP_Error( 'ur_abilities_not_found', __( 'User not found.', 'user-registration' ), array( 'status' => 404 ) );
		}

		$asker = get_current_user_id();

		// Check as that user, then switch straight back.
		wp_set_current_user( $user_id );

		try {
			$can_view = urcr_is_content_access_granted( $post );
			$resolved = urcr_resolve_access_rules( $post );
			$override = ur_string_to_bool( get_post_meta( $post->ID, 'urcr_meta_override_global_settings', true ) );
			$editor   = is_super_admin() || current_user_can( 'edit_post', $post->ID );
		} finally {
			wp_set_current_user( $asker );
		}

		$deciding = 0;
		$letting  = $resolved['post']['granting_rule_id'] ? $resolved['post']['granting_rule_id'] : $resolved['whole_site']['granting_rule_id'];

		if ( $editor ) {
			$reason = __( 'This user can edit the content or manage the site, and such users always see it.', 'user-registration' );
		} elseif ( urcr_is_page_excluded( $post->ID ) ) {
			$reason = __( 'This page is never restricted, for example the login or registration page.', 'user-registration' );
		} elseif ( $override ) {
			$reason = $can_view ? __( 'The page uses its own restriction settings instead of the rules, and the user meets them.', 'user-registration' ) : __( 'The page uses its own restriction settings instead of the rules, and the user does not meet them.', 'user-registration' );
		} elseif ( ! $resolved['post']['granted'] && $resolved['post']['rule_id'] ) {
			$deciding = $resolved['post']['rule_id'];
			$reason   = __( 'A rule for this content keeps the user out, and no rule for it lets them in. The newest such rule decides what they see.', 'user-registration' );
		} elseif ( ! $resolved['whole_site']['granted'] && ! $resolved['post']['granted'] && $resolved['whole_site']['rule_id'] ) {
			$deciding = $resolved['whole_site']['rule_id'];
			$reason   = __( 'A whole-site rule keeps the user out, and no rule lets them in.', 'user-registration' );
		} elseif ( $can_view ) {
			$reason = $letting ? __( 'The rules for this content let the user in.', 'user-registration' ) : __( 'No rule restricts this content for the user.', 'user-registration' );
		} else {
			$reason = __( 'No rule keeps the user out, but the site-wide members-only setting or a content drip schedule does.', 'user-registration' );
		}

		return array(
			'can_view'           => (bool) $can_view,
			'reason'             => $reason,
			'deciding_rule_id'   => (int) $deciding,
			'deciding_rule'      => $deciding ? wp_strip_all_tags( get_the_title( $deciding ) ) : '',
			'letting_in_rule_id' => (int) $letting,
		);
	}

	/**
	 * Whether a create, update or switch on leaves an active rule covering the whole site.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public static function needs_confirmation( $input ) {
		$current = ! empty( $input['id'] ) ? self::rule_content( $input['id'] ) : array();
		$enabled = array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : ( $current ? urcr_is_access_rule_enabled( $current ) : true );

		if ( isset( $input['targets'] ) ) {
			$whole_site = in_array( 'whole_site', wp_list_pluck( $input['targets'], 'type' ), true );
		} else {
			$whole_site = in_array( 'whole_site', wp_list_pluck( $current['target_contents'] ?? array(), 'type' ), true );
		}

		return $enabled && $whole_site;
	}

	/**
	 * Describe a whole-site rule that is about to be saved.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Ability input.
	 * @return string|WP_Error
	 */
	public static function preview_save( $input ) {
		$content = self::content_for( $input );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return sprintf(
			/* translators: %s: rule title. */
			__( 'Save the rule "%s", which covers the whole site. Every page is checked against it, so visitors who do not meet it can be kept out of the entire site.', 'user-registration' ),
			$input['title'] ?? ( ! empty( $input['id'] ) ? get_the_title( $input['id'] ) : '' )
		);
	}

	/**
	 * Describe switching on a whole-site rule.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Ability input.
	 * @return string|WP_Error
	 */
	public static function preview_status( $input ) {
		$found = self::rule_content( $input['id'] );

		if ( ! $found ) {
			return self::not_found();
		}

		return sprintf(
			/* translators: %s: rule title. */
			__( 'Switch on the rule "%s", which covers the whole site. Every page is checked against it, so visitors who do not meet it can be kept out of the entire site.', 'user-registration' ),
			get_the_title( $input['id'] )
		);
	}

	/**
	 * Describe trashing a rule.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Ability input.
	 * @return string|WP_Error
	 */
	public static function preview_trash( $input ) {
		if ( ! self::rule_content( $input['id'] ) ) {
			return self::not_found();
		}

		return sprintf(
			/* translators: %s: rule title. */
			__( 'Move the rule "%s" to the trash, like Delete on the Content Access Rules screen. Its pages are no longer checked against it. It is not deleted for good; switching it off instead keeps it on the list.', 'user-registration' ),
			get_the_title( $input['id'] )
		);
	}

	/**
	 * Create a rule through the rules API.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function create_rule( $input ) {
		$content = self::content_for( $input );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$data = self::api(
			'create_rule',
			array(
				'title'            => $input['title'],
				'access_rule_data' => $content,
			)
		);

		return is_wp_error( $data ) ? $data : self::get_rule( array( 'id' => $data['rule']['id'] ) );
	}

	/**
	 * Update a rule through the rules API, keeping the parts that were not given.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function update_rule( $input ) {
		$content = self::content_for( $input );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$params = array(
			'id'               => $input['id'],
			'access_rule_data' => $content,
		);

		if ( isset( $input['title'] ) ) {
			$params['title'] = $input['title'];
		}

		$data = self::api( 'update_rule', $params );

		return is_wp_error( $data ) ? $data : self::get_rule( array( 'id' => $input['id'] ) );
	}

	/**
	 * Switch a rule on or off through the rules API.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function set_status( $input ) {
		$data = self::api(
			'toggle_rule_status',
			array(
				'id'      => $input['id'],
				'enabled' => $input['enabled'] ? 'true' : 'false',
			)
		);

		return is_wp_error( $data ) ? $data : self::get_rule( array( 'id' => $input['id'] ) );
	}

	/**
	 * Copy a rule through the rules API.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function duplicate_rule( $input ) {
		$data = self::api( 'duplicate_rule', array( 'id' => $input['id'] ) );

		return is_wp_error( $data ) ? $data : self::get_rule( array( 'id' => $data['rule_id'] ) );
	}

	/**
	 * Trash a rule through the rules API, never deleting it for good.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function trash_rule( $input ) {
		$data = self::api(
			'delete_rule',
			array(
				'id'    => $input['id'],
				'force' => 'false',
			)
		);

		return is_wp_error( $data ) ? $data : array(
			'id'      => (int) $input['id'],
			'trashed' => true,
		);
	}

	/**
	 * Call a Content Access Rules API method the way the rules screen's requests reach it.
	 *
	 * @param string $method Method of URCR_Content_Access_Rules.
	 * @param array  $params Request parameters.
	 * @return array|WP_Error Response data, or an error when the API refused.
	 */
	private static function api( $method, $params = array() ) {
		if ( ! class_exists( 'URCR_Content_Access_Rules' ) ) {
			include_once __DIR__ . '/includes/RestApi/controllers/version1/class-urcr-content-access-rules.php';
		}

		$request = new WP_REST_Request();

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = call_user_func( array( 'URCR_Content_Access_Rules', $method ), $request );
		$data     = $response instanceof WP_REST_Response ? $response->get_data() : array();

		if ( empty( $data['success'] ) ) {
			$status = $response instanceof WP_REST_Response ? $response->get_status() : 500;

			return new WP_Error( 404 === $status ? 'ur_abilities_not_found' : 'ur_abilities_rule_failed', wp_strip_all_tags( (string) ( $data['message'] ?? __( 'The rule could not be saved.', 'user-registration' ) ) ), array( 'status' => $status ) );
		}

		return $data;
	}

	/**
	 * Read a rule's stored content, or an empty array when it is not a live rule.
	 *
	 * @param int $id Rule ID.
	 * @return array
	 */
	private static function rule_content( $id ) {
		$post = get_post( $id );

		if ( ! $post || 'urcr_access_rule' !== $post->post_type || 'trash' === $post->post_status ) {
			return array();
		}

		$content = json_decode( $post->post_content, true );

		return is_array( $content ) ? $content : array( 'enabled' => false );
	}

	/**
	 * Build the rule content the rules screen saves, from ability input over the current rule.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	private static function content_for( $input ) {
		$content = ! empty( $input['id'] ) ? self::rule_content( $input['id'] ) : array(
			'enabled'                   => true,
			'is_advanced_logic_enabled' => false,
		);

		if ( ! empty( $input['id'] ) && ! $content ) {
			return self::not_found();
		}

		$next = self::id_maker();

		if ( isset( $input['access'] ) ) {
			$content['access_control'] = $input['access'];
		}

		if ( isset( $input['conditions'] ) ) {
			$conditions = array();

			foreach ( $input['conditions'] as $condition ) {
				$value = self::condition_value( $condition );

				if ( is_wp_error( $value ) ) {
					return $value;
				}

				$conditions[] = array(
					'type'  => $condition['type'],
					'id'    => $next(),
					'value' => $value,
				);
			}

			$content['logic_map'] = array(
				'type'       => 'group',
				'id'         => $content['logic_map']['id'] ?? $next(),
				'logic_gate' => $input['condition_logic'] ?? ( $content['logic_map']['logic_gate'] ?? 'OR' ),
				'conditions' => $conditions,
			);
		} elseif ( isset( $input['condition_logic'] ) && ! empty( $content['logic_map'] ) ) {
			$content['logic_map']['logic_gate'] = $input['condition_logic'];
		}

		if ( isset( $input['targets'] ) ) {
			$targets = array();

			foreach ( $input['targets'] as $target ) {
				$built = self::target( $target, $next() );

				if ( is_wp_error( $built ) ) {
					return $built;
				}

				$targets[] = $built;
			}

			$content['target_contents'] = $targets;
		}

		$access = $content['access_control'] ?? ( $content['actions'][0]['access_control'] ?? 'access' );

		if ( isset( $input['action'] ) ) {
			$action = self::action( $input['action'], $next() );

			if ( is_wp_error( $action ) ) {
				return $action;
			}

			$content['actions'] = array( $action );
		}

		if ( ! empty( $content['actions'][0] ) ) {
			$content['actions'][0]['access_control'] = $access;
		}

		return $content;
	}

	/**
	 * Check and clean a condition's value for its type.
	 *
	 * @param array $condition Condition with `type` and `value`.
	 * @return mixed|WP_Error
	 */
	private static function condition_value( $condition ) {
		$value = $condition['value'] ?? '';
		$list  = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $value ) ), 'strlen' ) );

		switch ( $condition['type'] ) {
			case 'roles':
				$unknown = array_diff( $list, array_keys( wp_roles()->roles ) );

				return $list && ! $unknown ? $list : self::invalid( __( 'Use existing role names for a roles condition.', 'user-registration' ) );
			case 'capabilities':
			case 'registration_source':
				return $list ? $list : self::invalid( __( 'Give at least one value for this condition.', 'user-registration' ) );
			case 'membership':
				$ids = array_values( array_filter( array_map( 'absint', $list ) ) );

				return $ids ? array_map( 'strval', $ids ) : self::invalid( __( 'Give membership plan IDs for a membership condition.', 'user-registration' ) );
			case 'user_state':
				return in_array( $value, array( 'logged-in', 'logged-out' ), true ) ? $value : self::invalid( __( 'user_state takes logged-in or logged-out.', 'user-registration' ) );
			case 'email_domain':
				return is_string( $value ) && '' !== trim( $value ) ? sanitize_text_field( $value ) : self::invalid( __( 'Give the email domains as a comma separated list.', 'user-registration' ) );
			default:
				return is_numeric( $value ) && $value >= 0 ? (int) $value : self::invalid( __( 'Give a number that is not negative.', 'user-registration' ) );
		}
	}

	/**
	 * Build a rule target the way the rules screen stores it.
	 *
	 * @param array  $target Target with `type`, `values` and, for a taxonomy, `taxonomy`.
	 * @param string $id     Generated ID.
	 * @return array|WP_Error
	 */
	private static function target( $target, $id ) {
		$built = array(
			'id'   => $id,
			'type' => $target['type'],
			'drip' => array(
				'activeType' => 'fixed_date',
				'value'      => array(
					'fixed_date' => array(
						'date' => '',
						'time' => '',
					),
					'days_after' => array( 'days' => 0 ),
				),
			),
		);

		if ( 'whole_site' === $target['type'] ) {
			return $built;
		}

		$values = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $target['values'] ?? array() ) ) ), 'strlen' ) );

		if ( ! $values ) {
			return self::invalid( __( 'Give the pages, posts, post types or terms this target covers.', 'user-registration' ) );
		}

		if ( 'taxonomy' === $target['type'] ) {
			if ( empty( $target['taxonomy'] ) || ! taxonomy_exists( $target['taxonomy'] ) ) {
				return self::invalid( __( 'A taxonomy target needs an existing taxonomy name.', 'user-registration' ) );
			}

			$built['taxonomy'] = sanitize_key( $target['taxonomy'] );
		}

		// Page and post IDs are matched as text, so they are stored as text like the rules screen does.
		$built['value'] = in_array( $target['type'], array( 'wp_pages', 'wp_posts' ), true ) ? array_map( 'strval', array_map( 'absint', $values ) ) : $values;

		return $built;
	}

	/**
	 * Build a rule action the way the rules screen stores it.
	 *
	 * @param array  $action Action with `type` and its detail.
	 * @param string $id     Generated ID.
	 * @return array|WP_Error
	 */
	private static function action( $action, $id ) {
		$labels = array(
			'message'    => __( 'Show Message', 'user-registration' ),
			'redirect'   => __( 'Redirect', 'user-registration' ),
			'local_page' => __( 'Redirect to a Local Page', 'user-registration' ),
		);

		if ( 'redirect' === $action['type'] && ! wp_http_validate_url( $action['redirect_url'] ?? '' ) ) {
			return self::invalid( __( 'A redirect needs a full web address.', 'user-registration' ) );
		}

		if ( 'local_page' === $action['type'] && 'publish' !== get_post_status( $action['local_page'] ?? 0 ) ) {
			return self::invalid( __( 'A local page redirect needs a published page.', 'user-registration' ) );
		}

		return array(
			'id'           => $id,
			'type'         => $action['type'],
			'label'        => $labels[ $action['type'] ],
			'message'      => 'message' === $action['type'] ? wp_kses_post( $action['message'] ?? '' ) : '',
			'redirect_url' => 'redirect' === $action['type'] ? esc_url_raw( $action['redirect_url'] ) : '',
			'local_page'   => 'local_page' === $action['type'] ? (string) absint( $action['local_page'] ) : '',
			'ur_form'      => '',
			'shortcode'    => array(
				'tag'  => '',
				'args' => '',
			),
		);
	}

	/**
	 * Return a function that hands out rule part IDs in the `x` + milliseconds style of the rules screen.
	 *
	 * @return callable
	 */
	private static function id_maker() {
		$base  = (int) ( microtime( true ) * 1000 );
		$count = 0;

		return function () use ( $base, &$count ) {
			++$count;

			return 'x' . ( $base + $count );
		};
	}

	/**
	 * Serialize only documented rule fields from a rules API rule.
	 *
	 * @param array $data Rule from the rules API.
	 * @return array
	 */
	private static function rule( $data ) {
		$content    = is_array( $data['content'] ?? null ) ? $data['content'] : array();
		$targets    = is_array( $content['target_contents'] ?? null ) ? $content['target_contents'] : array();
		$action     = $content['actions'][0] ?? array();
		$conditions = array();

		foreach ( (array) ( $content['logic_map']['conditions'] ?? array() ) as $condition ) {
			$conditions[] = array(
				'type'  => (string) ( $condition['type'] ?? '' ),
				'value' => $condition['value'] ?? null,
			);
		}

		return array(
			'id'              => (int) $data['id'],
			'title'           => wp_strip_all_tags( (string) $data['title'] ),
			'enabled'         => (bool) $data['enabled'],
			'access'          => (string) ( $data['access_control'] ?? 'access' ),
			'condition_logic' => (string) ( $content['logic_map']['logic_gate'] ?? 'OR' ),
			'conditions'      => $conditions,
			'targets'         => array_map(
				function ( $target ) {
					return array_filter(
						array(
							'type'     => (string) ( $target['type'] ?? '' ),
							'values'   => array_values( (array) ( $target['value'] ?? array() ) ),
							'taxonomy' => (string) ( $target['taxonomy'] ?? '' ),
						),
						function ( $part ) {
							return array() !== $part && '' !== $part;
						}
					);
				},
				$targets
			),
			'action'          => array_filter(
				array(
					'type'         => (string) ( $action['type'] ?? '' ),
					'message'      => (string) ( $action['message'] ?? '' ),
					'redirect_url' => (string) ( $action['redirect_url'] ?? '' ),
					'local_page'   => (int) ( $action['local_page'] ?? 0 ),
				)
			),
			'rule_type'       => (string) ( get_post_meta( $data['id'], 'urcr_rule_type', true ) ? get_post_meta( $data['id'], 'urcr_rule_type', true ) : 'custom' ),
			'whole_site'      => in_array( 'whole_site', wp_list_pluck( $targets, 'type' ), true ),
		);
	}

	/**
	 * Return a uniform bad-input error.
	 *
	 * @param string $message Problem.
	 * @return WP_Error
	 */
	private static function invalid( $message ) {
		return new WP_Error( 'ur_abilities_invalid_input', $message, array( 'status' => 400 ) );
	}

	/**
	 * Return a uniform missing-rule error.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'ur_abilities_not_found', __( 'Content rule not found.', 'user-registration' ), array( 'status' => 404 ) );
	}
}

URCR_Abilities::init();
