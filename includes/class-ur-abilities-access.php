<?php
/**
 * What AI assistants may do in each ability area.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Reads which of each area's switches the admin turned on, and adds those switches to the settings. */
class UR_Abilities_Access {
	/** Access levels, each one including the ones before it. */
	const LEVELS = array(
		'off'    => 0,
		'read'   => 1,
		'write'  => 2,
		'delete' => 3,
	);

	/** Start of the option names that store whether one of an area's switches is on. */
	const GROUP_PREFIX = 'user_registration_ability_group_';

	/** Add the settings from the ability areas, so a new area gets its setting without extra code. */
	public static function init() {
		add_filter( 'user_registration_settings_advanced_ai_integration', array( __CLASS__, 'add_settings' ) );
	}

	/**
	 * Get the option name that stores the access level chosen for a whole area, before areas had switches.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $slug Area slug.
	 * @return string
	 */
	public static function option_id( $slug ) {
		return 'user_registration_ability_access_' . sanitize_key( $slug );
	}

	/**
	 * Get the access chosen for an area. Read only until the admin changes it. Switches that were never saved follow it.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $slug Area slug.
	 * @return string One of the keys of self::LEVELS.
	 */
	public static function get( $slug ) {
		$access = get_option( self::option_id( $slug ), 'read' );

		return is_string( $access ) && isset( self::LEVELS[ $access ] ) ? $access : 'read';
	}

	/**
	 * Whether the chosen access for an area covers a level.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $slug  Area slug.
	 * @param string $level `read`, `write` or `delete`.
	 * @return bool
	 */
	public static function allows( $slug, $level ) {
		return isset( self::LEVELS[ $level ] ) && self::LEVELS[ $level ] <= self::LEVELS[ self::get( $slug ) ];
	}

	/**
	 * Get an area's switches: one for all its read abilities, one for each group it defines, and one for every other ability.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $area Area definition, with an optional `groups` list of `label` and `abilities` (ability names), keyed by group slug.
	 * @return array Groups keyed by slug, in the order their first ability appears, the read group first. Each has `label`,
	 *               `abilities` (names), `covers` (ability labels), `level` (the highest of its abilities) and `confirm`
	 *               (whether any asks first).
	 */
	public static function get_groups( $area ) {
		$defined   = isset( $area['groups'] ) && is_array( $area['groups'] ) ? $area['groups'] : array();
		$abilities = isset( $area['abilities'] ) && is_array( $area['abilities'] ) ? $area['abilities'] : array();
		$group_of  = array();
		$groups    = array();

		foreach ( $defined as $key => $group ) {
			foreach ( (array) ( $group['abilities'] ?? array() ) as $name ) {
				$group_of[ sanitize_key( $name ) ] = sanitize_key( $key );
			}
		}

		foreach ( $abilities as $name => $ability ) {
			$name = sanitize_key( $name );

			if ( '' === $name || ! is_array( $ability ) || empty( $ability['label'] ) ) {
				continue;
			}

			$level = UR_Abilities::level( $ability );
			$key   = $group_of[ $name ] ?? ( 'read' === $level ? 'view' : $name );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'label'     => $defined[ $key ]['label'] ?? ( 'view' === $key ? __( 'See this area', 'user-registration' ) : $ability['label'] ),
					'abilities' => array(),
					'covers'    => array(),
					'level'     => $level,
					'confirm'   => false,
				);
			}

			$groups[ $key ]['abilities'][] = $name;
			$groups[ $key ]['covers'][]    = $ability['label'];
			$groups[ $key ]['confirm']     = $groups[ $key ]['confirm'] || ! empty( $ability['confirm'] );

			if ( self::LEVELS[ $level ] > self::LEVELS[ $groups[ $key ]['level'] ] ) {
				$groups[ $key ]['level'] = $level;
			}
		}

		return isset( $groups['view'] ) ? array( 'view' => $groups['view'] ) + $groups : $groups;
	}

	/**
	 * Get the option name that stores whether one of an area's switches is on.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $slug  Area slug.
	 * @param string $group Group slug.
	 * @return string
	 */
	public static function group_option_id( $slug, $group ) {
		return self::GROUP_PREFIX . sanitize_key( $slug ) . '_' . sanitize_key( $group );
	}

	/**
	 * Whether the switch that covers an ability is on. Until the admin saves that switch, it follows the access chosen for the area.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string     $slug  Area slug.
	 * @param string     $name  Ability name, without the `user-registration/` prefix.
	 * @param string     $level `read`, `write` or `delete`.
	 * @param array|null $area  Area definition, when the caller already has it.
	 * @return bool
	 */
	public static function is_enabled( $slug, $name, $level, $area = null ) {
		if ( null === $area ) {
			$areas = UR_Abilities::get_areas();
			$area  = $areas[ $slug ] ?? array();
		}

		foreach ( self::get_groups( $area ) as $key => $group ) {
			if ( in_array( $name, $group['abilities'], true ) ) {
				$saved = get_option( self::group_option_id( $slug, $key ), null );

				return null === $saved ? self::allows( $slug, $group['level'] ) : true === ur_string_to_bool( $saved );
			}
		}

		return self::allows( $slug, $level );
	}

	/**
	 * Add one card with each area's few switches under its name, shown only while AI abilities are switched on.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $settings Settings of the AI Integration section.
	 * @return array
	 */
	public static function add_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		$fields = array();
		$areas  = array();

		foreach ( UR_Abilities::get_areas() as $slug => $area ) {
			$slug = sanitize_key( $slug );

			if ( '' === $slug || ! is_array( $area ) || empty( $area['label'] ) || ! is_string( $area['label'] ) ) {
				continue;
			}

			$groups = self::get_groups( $area );

			if ( empty( $groups ) ) {
				continue;
			}

			foreach ( $groups as $key => $group ) {
				$fields[] = array(
					'title'             => $group['label'],
					'desc'              => implode( ', ', $group['covers'] ),
					'id'                => self::group_option_id( $slug, $key ),
					'type'              => 'toggle',
					'default'           => self::allows( $slug, $group['level'] ) ? 'yes' : 'no',
					'css'               => '',
					'custom_attributes' => array(
						'data-ur-ability-area'    => $slug,
						'data-ur-ability-confirm' => $group['confirm'] ? 'yes' : 'no',
					),
				);
			}

			$areas[ $slug ] = array(
				'label' => $area['label'],
				'tip'   => ! empty( $area['description'] ) && is_string( $area['description'] ) ? $area['description'] : '',
				'pro'   => ! empty( $area['plan'] ),
			);
		}

		if ( empty( $fields ) ) {
			return $settings;
		}

		$settings['sections']['ai_abilities_access'] = array(
			'id'                => 'ur-ability-access',
			'title'             => __( 'AI Abilities Access', 'user-registration' ),
			'type'              => 'card',
			'desc'              => '',
			'settings'          => $fields,
			'display_condition' => array(
				'field'    => 'user_registration_enable_abilities',
				'operator' => 'equals',
				'value'    => 'yes',
				'case'     => 'insensitive',
			),
		);
		self::enqueue_script( $areas );

		return $settings;
	}

	/**
	 * Add the script and styles that put each area's switches under its name, with a search in the card header.
	 *
	 * @param array $areas Label, description and Pro flag of each area, keyed by area slug.
	 */
	private static function enqueue_script( $areas ) {
		$css = '#ur-ability-access > .user-registration-card__header{display:flex;align-items:center;gap:16px;flex-wrap:wrap}#ur-ability-access > .user-registration-card__header .user-registration-card__header-wrapper{flex:1}'
			. '.ur-ability-access__search{position:relative;width:260px;max-width:100%}.ur-ability-access__search input{width:100%;font-size:13px;line-height:1.5;border:1px solid #e1e4ed;border-radius:6px;padding:6px 34px 6px 12px;margin:0}'
			. '.ur-ability-access__search svg{position:absolute;right:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:#646a7a;pointer-events:none}'
			. '.ur-ability-access__empty{margin:4px 0 0;color:#646a7a}'
			. '.ur-ability-access__area{display:grid;grid-template-columns:200px minmax(0,1fr);align-items:start;gap:8px 24px;padding:16px 0;border-top:1px solid #e1e4ed}.ur-ability-access__area:first-child,.ur-ability-access__area--first{border-top:0;padding-top:0}'
			. '.ur-ability-access__title{margin:0;padding-top:6px;display:flex;align-items:center;font-size:14px;font-weight:600;color:#1d2327}'
			. '#ur-ability-access .user-registration-help-tip{display:inline-flex;align-items:center;justify-content:center;flex:none;width:16px;height:16px;margin:0 0 0 6px;padding:0;top:0;line-height:1;vertical-align:middle}#ur-ability-access .user-registration-help-tip::after{width:16px;height:16px;padding:0;box-sizing:border-box;background-size:contain}'
			. '.ur-ability-access__pro{width:16px;height:16px;margin-left:6px}'
			. '#ur-ability-access .ur-ability-access__list .user-registration-global-settings{display:flex;align-items:center;gap:16px;margin:0 !important;padding:8px 0}'
			. '#ur-ability-access .ur-ability-access__list .user-registration-global-settings > label{flex:1 1 auto !important;min-width:0;width:auto !important;max-width:none !important;margin:0}'
			. '#ur-ability-access .ur-ability-access__list .user-registration-global-settings--field{flex:0 0 auto !important;width:auto !important}'
			. '.ur-ability-access__name{display:block;font-weight:500;color:#1d2327}'
			. '.ur-ability-access__list .ur-ability-access__covers{display:block;margin:2px 0 0;font-size:12px;font-weight:400;color:#646a7a}'
			. '.ur-ability-access__asks{margin-left:6px;padding:1px 6px;border-radius:4px;background:#eceffa;color:#2d3559;font-size:11px;font-weight:500;white-space:nowrap}'
			. '@media (max-width:782px){.ur-ability-access__area{grid-template-columns:1fr}}';

		wp_register_style( 'user-registration-ability-access', false, array(), UR_VERSION );
		wp_enqueue_style( 'user-registration-ability-access' );
		wp_add_inline_style( 'user-registration-ability-access', $css );
		wp_enqueue_script( 'user-registration-ability-access', UR()->plugin_url() . '/assets/js/admin/ability-access.js', array( 'jquery' ), UR_VERSION, true );
		wp_localize_script(
			'user-registration-ability-access',
			'urAbilityAccess',
			array(
				'asks'    => __( 'Asks first', 'user-registration' ),
				'search'  => __( 'Search abilities', 'user-registration' ),
				/* translators: Example shown in the empty ability search box, the name of an ability. */
				'example' => __( 'e.g. Approve member', 'user-registration' ),
				'noMatch' => __( 'No abilities match your search.', 'user-registration' ),
				'askstip' => __( 'Shows you a preview and waits for your confirmation before it runs.', 'user-registration' ),
				'proIcon' => UR()->plugin_url() . '/assets/images/icons/ur-pro-icon.png',
				'areas'   => $areas,
			)
		);
	}
}
