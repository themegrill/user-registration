<?php
/**
 * What AI assistants may do in each ability area.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Reads which abilities the admin switched on and adds a switch for each one. */
class UR_Abilities_Access {
	/** Access levels, each one including the ones before it. */
	const LEVELS = array(
		'off'    => 0,
		'read'   => 1,
		'write'  => 2,
		'delete' => 3,
	);

	/** Start of the option names that store whether one ability is switched on. */
	const ABILITY_PREFIX = 'user_registration_ability_enabled_';

	/** Add the settings from the ability areas, so a new area gets its setting without extra code. */
	public static function init() {
		add_filter( 'user_registration_settings_advanced_ai_integration', array( __CLASS__, 'add_settings' ) );
	}

	/**
	 * Get the option name that stores the access level chosen for a whole area, before each ability had its own switch.
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
	 * Get the access chosen for an area. Read only until the admin changes it. Abilities whose switch was never saved follow it.
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
	 * Get the option name that stores whether one ability is switched on.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $name Ability name, without the `user-registration/` prefix.
	 * @return string
	 */
	public static function ability_option_id( $name ) {
		return self::ABILITY_PREFIX . sanitize_key( $name );
	}

	/**
	 * Whether one ability is switched on. Until the admin saves its switch, it follows the access chosen for the area.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $slug  Area slug.
	 * @param string $name  Ability name, without the `user-registration/` prefix.
	 * @param string $level `read`, `write` or `delete`.
	 * @return bool
	 */
	public static function is_enabled( $slug, $name, $level ) {
		$saved = get_option( self::ability_option_id( $name ), null );

		return null === $saved ? self::allows( $slug, $level ) : true === ur_string_to_bool( $saved );
	}

	/**
	 * Add one card for every area, with a switch for each of its abilities, shown only while AI abilities are switched on.
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

		$cards = array();

		foreach ( UR_Abilities::get_areas() as $slug => $area ) {
			$slug = sanitize_key( $slug );

			if ( '' === $slug || ! is_array( $area ) || empty( $area['label'] ) || ! is_string( $area['label'] ) || empty( $area['abilities'] ) || ! is_array( $area['abilities'] ) ) {
				continue;
			}

			$fields = array();

			foreach ( $area['abilities'] as $name => $ability ) {
				if ( ! is_array( $ability ) || empty( $ability['label'] ) ) {
					continue;
				}

				$level    = UR_Abilities::level( $ability );
				$fields[] = array(
					'title'             => $ability['label'],
					'desc'              => isset( $ability['description'] ) && is_string( $ability['description'] ) ? $ability['description'] : '',
					'id'                => self::ability_option_id( $name ),
					'type'              => 'toggle',
					'default'           => self::allows( $slug, $level ) ? 'yes' : 'no',
					'css'               => '',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'data-ur-ability-level'   => $level,
						'data-ur-ability-confirm' => empty( $ability['confirm'] ) ? 'no' : 'yes',
					),
				);
			}

			if ( empty( $fields ) ) {
				continue;
			}

			// Reads first, then changes, then deletes, keeping the area's own order inside each.
			$order = array_keys( $fields );
			array_multisort( array_map( fn( $field ) => self::LEVELS[ $field['custom_attributes']['data-ur-ability-level'] ], $fields ), $order, $fields );

			$cards[ 'ai_abilities_access_' . $slug ] = array(
				'id'                => 'ur-ability-access-' . $slug,
				'title'             => $area['label'],
				'type'              => 'card',
				'desc'              => ! empty( $area['description'] ) && is_string( $area['description'] ) ? $area['description'] : '',
				'is_premium'        => ! empty( $area['plan'] ),
				'settings'          => $fields,
				'display_condition' => array(
					'field'    => 'user_registration_enable_abilities',
					'operator' => 'equals',
					'value'    => 'yes',
					'case'     => 'insensitive',
				),
			);
		}

		if ( empty( $cards ) ) {
			return $settings;
		}

		$settings['sections'] = array_merge( isset( $settings['sections'] ) && is_array( $settings['sections'] ) ? $settings['sections'] : array(), $cards );
		self::enqueue_script();

		return $settings;
	}

	/** Add the script and styles that give each card its quick choices, its count and its open and close header. */
	private static function enqueue_script() {
		$css = '.ur-ability-access__header{cursor:pointer;display:flex;align-items:center;gap:12px;flex-wrap:wrap}'
			. '.ur-ability-access__header .user-registration-card__header-wrapper{flex:1}'
			. '.ur-ability-access__header .user-registration-card__title::before{content:"";display:inline-block;width:7px;height:7px;margin:0 10px 2px 0;border:solid currentColor;border-width:0 2px 2px 0;transform:rotate(45deg);transition:transform .15s}'
			. '.ur-ability-access--closed .user-registration-card__title::before{transform:rotate(-45deg)}'
			. '.ur-ability-access--closed > .ur-ability-access__body{display:none}'
			. '[id^="ur-ability-access-"] > .ur-p-tag{display:none}.ur-ability-access__tip{margin-left:6px}'
			. '.ur-ability-access__count{margin-left:8px;font-size:12px;font-weight:400;color:#646a7a}'
			. '.ur-ability-access__presets{display:flex;gap:4px}'
			. '.ur-ability-access__presets button{font-size:12px;line-height:1.6;color:#000;background:#fff;border:1px solid #e1e4ed;border-radius:999px;padding:1px 10px;cursor:pointer}'
			. '.ur-ability-access__presets button:hover{border-color:#475bb2}'
			. '.ur-ability-access__presets button[aria-pressed="true"]{background:#475bb2;border-color:#475bb2;color:#fff}'
			. '.ur-ability-access__group{margin:16px 0 4px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase}'
			. '.ur-ability-access__group--read{color:#2f7d52}.ur-ability-access__group--write{color:#9a6a12}.ur-ability-access__group--delete{color:#b3261e}'
			. '.ur-ability-access__asks{margin-left:6px;padding:1px 6px;border-radius:4px;background:#eceffa;color:#2d3559;font-size:11px;font-weight:500;white-space:nowrap}';

		wp_register_style( 'user-registration-ability-access', false, array(), UR_VERSION );
		wp_enqueue_style( 'user-registration-ability-access' );
		wp_add_inline_style( 'user-registration-ability-access', $css );
		wp_enqueue_script( 'user-registration-ability-access', UR()->plugin_url() . '/assets/js/admin/ability-access.js', array( 'jquery' ), UR_VERSION, true );
		wp_localize_script(
			'user-registration-ability-access',
			'urAbilityAccess',
			array(
				'none'    => __( 'None', 'user-registration' ),
				'read'    => __( 'Read only', 'user-registration' ),
				'all'     => __( 'All', 'user-registration' ),
				/* translators: 1: abilities switched on, 2: abilities in the area. */
				'count'   => __( '%1$s of %2$s on', 'user-registration' ),
				'asks'    => __( 'Asks first', 'user-registration' ),
				'askstip' => __( 'Shows you a preview and waits for your confirmation before it runs.', 'user-registration' ),
				'groups'  => array(
					'read'   => __( 'Reads', 'user-registration' ),
					'write'  => __( 'Changes', 'user-registration' ),
					'delete' => __( 'Deletes', 'user-registration' ),
				),
			)
		);
	}
}
