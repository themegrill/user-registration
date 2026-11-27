<?php
/**
 * The AI Integration section of the Advanced settings.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Adds the AI Integration section and its master switch. Other classes add their own cards through a filter. */
class UR_Abilities_Settings {
	/** Slug of the section under Advanced. */
	const SECTION = 'ai-integration';

	/** Hook the section into the Advanced settings. */
	public static function init() {
		add_filter( 'user_registration_get_sections_advanced', array( __CLASS__, 'add_section' ), 20 );
		add_filter( 'user_registration_get_settings_advanced', array( __CLASS__, 'get_settings' ), 20 );
	}

	/**
	 * Add AI Integration at the top of the Advanced sections, which also makes it the one Advanced opens on.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $sections Sections of the Advanced settings.
	 * @return array
	 */
	public static function add_section( $sections ) {
		return is_array( $sections ) ? array( self::SECTION => __( 'AI Integration', 'user-registration' ) ) + $sections : $sections;
	}

	/**
	 * Give the AI Integration section its settings, starting with the master switch.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $settings Settings of the open Advanced section.
	 * @return array
	 */
	public static function get_settings( $settings ) {
		global $current_section;

		if ( self::SECTION !== $current_section ) {
			return $settings;
		}

		/**
		 * Filters the settings of the AI Integration section, so each part adds its own card from its own code.
		 *
		 * @since xx.xx.xx
		 *
		 * @param array $settings Title and cards of the section.
		 */
		return apply_filters(
			'user_registration_settings_advanced_ai_integration',
			array(
				'title'    => '',
				'sections' => array(
					'ai_integration' => array(
						'title'    => __( 'AI Integration', 'user-registration' ),
						'type'     => 'card',
						'desc'     => '',
						'settings' => array(
							array(
								'title'   => __( 'Enable AI Abilities', 'user-registration' ),
								'desc'    => __( 'Let AI apps connect to this site through MCP. Once on, you choose what they can do in each area. <a href="https://docs.wpuserregistration.com/global-settings/ai-abilities/" rel="noreferrer noopener" target="_blank">Learn more</a>', 'user-registration' ),
								'id'      => 'user_registration_enable_abilities',
								'type'    => 'toggle',
								'css'     => '',
								'default' => 'no',
							),
						),
					),
				),
			)
		);
	}
}
