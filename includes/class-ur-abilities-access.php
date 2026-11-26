<?php
/**
 * What AI assistants may do in each ability area.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Reads which modules the admin gave to AI assistants, and whether they may change things in them. */
class UR_Abilities_Access {
	/** Option that stores the modules (ability area slugs) AI assistants can use. Not saved yet means every module. */
	const MODULES_OPTION = 'user_registration_ability_modules';

	/** Option that stores whether AI assistants can also make changes in those modules. Off means they only read. */
	const WRITE_OPTION = 'user_registration_ability_allow_changes';

	/** Add the settings from the ability areas, so a new area shows up in the list without extra code. */
	public static function init() {
		add_filter( 'user_registration_settings_advanced_ai_integration', array( __CLASS__, 'add_settings' ) );
	}

	/**
	 * Get the modules AI assistants can use. Every module until the admin saves the list.
	 *
	 * @since xx.xx.xx
	 *
	 * @return string[] Area slugs.
	 */
	public static function modules() {
		$saved = get_option( self::MODULES_OPTION, null );

		return null === $saved ? array_map( 'sanitize_key', array_keys( UR_Abilities::get_areas() ) ) : array_map( 'sanitize_key', array_filter( (array) $saved, 'is_string' ) );
	}

	/**
	 * Whether AI assistants may make changes, and not only read.
	 *
	 * @since xx.xx.xx
	 *
	 * @return bool
	 */
	public static function can_change() {
		return true === ur_string_to_bool( get_option( self::WRITE_OPTION, 'no' ) );
	}

	/**
	 * Whether an ability is available to AI assistants: its module is chosen, and it only reads or changes are allowed.
	 * Making changes and deleting both sit under the one changes switch, and the ones that ask first still do.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string     $slug  Area slug.
	 * @param string     $name  Ability name, without the `user-registration/` prefix.
	 * @param string     $level `read`, `write` or `delete`.
	 * @param array|null $area  Unused, kept so callers need no change.
	 * @return bool
	 */
	public static function is_enabled( $slug, $name, $level, $area = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return in_array( sanitize_key( $slug ), self::modules(), true ) && ( 'read' === $level || self::can_change() );
	}

	/**
	 * Add one card with the module list and the switch for changes, shown only while AI abilities are switched on.
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

		$options = array();

		foreach ( UR_Abilities::get_areas() as $slug => $area ) {
			if ( is_array( $area ) && ! empty( $area['label'] ) && is_string( $area['label'] ) && '' !== sanitize_key( $slug ) ) {
				$options[ sanitize_key( $slug ) ] = $area['label'];
			}
		}

		if ( empty( $options ) ) {
			return $settings;
		}

		$settings['sections']['ai_abilities_access'] = array(
			'id'                => 'ur-ability-access',
			'title'             => __( 'AI Abilities Access', 'user-registration' ),
			'type'              => 'card',
			'desc'              => '',
			'settings'          => array(
				array(
					'title'    => __( 'Enabled Abilities', 'user-registration' ),
					'desc'     => __( 'The parts of the site AI assistants can work with. They can only read them, unless you allow changes below.', 'user-registration' ),
					'id'       => self::MODULES_OPTION,
					'type'     => 'multiselect',
					'class'    => 'ur-enhanced-select',
					'css'      => 'min-width: 350px;',
					'desc_tip' => true,
					'default'  => array_keys( $options ),
					'options'  => $options,
				),
				array(
					'title'    => __( 'Allow changes', 'user-registration' ),
					'desc'     => __( 'Let AI assistants also create, change and delete things in the chosen modules. Risky changes still show a preview and wait for your confirmation.', 'user-registration' ),
					'id'       => self::WRITE_OPTION,
					'type'     => 'toggle',
					'desc_tip' => true,
					'css'      => '',
					'default'  => 'no',
				),
			),
			'display_condition' => array(
				'field'    => 'user_registration_enable_abilities',
				'operator' => 'equals',
				'value'    => 'yes',
				'case'     => 'insensitive',
			),
		);

		return $settings;
	}
}
