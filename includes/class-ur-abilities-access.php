<?php
/**
 * What AI assistants may do in each ability area.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Reads the access the admin chose for each area and adds the settings for it. */
class UR_Abilities_Access {
	/** Access levels, each one including the ones before it. */
	const LEVELS = array(
		'off'    => 0,
		'read'   => 1,
		'write'  => 2,
		'delete' => 3,
	);

	/** Add the settings from the ability areas, so a new area gets its setting without extra code. */
	public static function init() {
		add_filter( 'user_registration_settings_advanced_others', array( __CLASS__, 'add_settings' ) );
	}

	/**
	 * Get the option name that stores the access for an area.
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
	 * Get the access chosen for an area. Read only until the admin changes it.
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
	 * Add one access setting for every area, in a card shown only while AI abilities are switched on.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $settings Settings of the Others section.
	 * @return array
	 */
	public static function add_settings( $settings ) {
		$fields = array();

		foreach ( UR_Abilities::get_areas() as $slug => $area ) {
			$slug = sanitize_key( $slug );

			if ( '' === $slug || ! is_array( $area ) || empty( $area['label'] ) || ! is_string( $area['label'] ) ) {
				continue;
			}

			$fields[] = array(
				'title'    => $area['label'],
				'desc'     => __( 'What AI assistants can do here. Anything above this level is hidden from them.', 'user-registration' ),
				'id'       => self::option_id( $slug ),
				'type'     => 'select',
				'default'  => 'read',
				'class'    => 'ur-enhanced-select',
				'css'      => '',
				'options'  => self::options( $area ),
				'desc_tip' => true,
			);
		}

		if ( empty( $fields ) || ! is_array( $settings ) ) {
			return $settings;
		}

		$settings['sections']['ai_abilities_access'] = array(
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

		return $settings;
	}

	/**
	 * Get the choices an area offers, up to the highest level of its abilities.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $area Area definition.
	 * @return array Labels keyed by access level.
	 */
	private static function options( $area ) {
		$highest = 'read';

		foreach ( (array) ( $area['abilities'] ?? array() ) as $ability ) {
			if ( is_array( $ability ) && self::LEVELS[ UR_Abilities::level( $ability ) ] > self::LEVELS[ $highest ] ) {
				$highest = UR_Abilities::level( $ability );
			}
		}

		$labels = array(
			'off'    => __( 'Off', 'user-registration' ),
			'read'   => __( 'Read only', 'user-registration' ),
			'write'  => __( 'Read and change', 'user-registration' ),
			'delete' => __( 'Read, change and delete', 'user-registration' ),
		);

		return array_intersect_key( $labels, array_filter( self::LEVELS, fn( $rank ) => $rank <= self::LEVELS[ $highest ] ) );
	}
}
