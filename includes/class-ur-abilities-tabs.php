<?php
/**
 * Tabs on the AI Integration settings: the switch and the connection on the first, the access switches on the second.
 *
 * This is a layout only. Every field stays in the same form, so Save Changes saves both tabs. To go back to the single
 * long page, turn it off with one line:
 *
 *     add_filter( 'user_registration_ai_settings_tabs', '__return_false' );
 *
 * or delete the `UR_Abilities_Tabs::init();` line in `UR_Abilities::init()`. Nothing else depends on it.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Splits the AI Integration cards into tabs, drawn in the browser, so the cards themselves are untouched. */
class UR_Abilities_Tabs {
	/** Hook the tabs in. */
	public static function init() {
		add_filter( 'user_registration_settings_advanced_ai_integration', array( __CLASS__, 'add_tabs' ), 99 );
	}

	/**
	 * Whether the tabbed layout is on.
	 *
	 * @since xx.xx.xx
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filters whether the AI Integration settings use tabs. Return false for the single long page.
		 *
		 * @since xx.xx.xx
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'user_registration_ai_settings_tabs', true );
	}

	/**
	 * The tab names, one for each card, in the order the cards are drawn.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $settings Settings of the AI Integration section.
	 * @return array
	 */
	public static function labels( $settings ) {
		$labels = array();

		foreach ( is_array( $settings ) && isset( $settings['sections'] ) && is_array( $settings['sections'] ) ? $settings['sections'] : array() as $section ) {
			if ( isset( $section['type'], $section['title'] ) && 'card' === $section['type'] ) {
				$labels[] = (string) $section['title'];
			}
		}

		return $labels;
	}

	/**
	 * Add the script and styles of the tabs. Runs only while the AI Integration section is drawn.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $settings Settings of the AI Integration section.
	 * @return array Unchanged.
	 */
	public static function add_tabs( $settings ) {
		$labels = self::labels( $settings );

		if ( ! self::enabled() || count( $labels ) < 2 ) {
			return $settings;
		}

		$css = '.ur-ai-tabs{display:flex;gap:4px;margin:16px 0 0;border-bottom:1px solid #e1e4ed}'
			. '.ur-ai-tabs__tab{margin:0 0 -1px;padding:10px 18px;border:0;border-bottom:2px solid transparent;background:none;font-size:14px;color:#646a7a;cursor:pointer}'
			. '.ur-ai-tabs__tab:hover{color:#2d3559}.ur-ai-tabs__tab.is-active{border-bottom-color:#475bb2;color:#475bb2;font-weight:600}'
			. '.ur-ai-tabs__tab.is-disabled{opacity:.5;cursor:not-allowed}.ur-ai-tabs__tab.is-disabled:hover{color:#646a7a}'
			. '.ur-ai-pane--off{display:none !important}.user-registration-options-container:not(.ur-ai-tabs-ready) #ur-ability-access{display:none !important}';

		wp_register_style( 'user-registration-ai-tabs', false, array(), UR_VERSION );
		wp_enqueue_style( 'user-registration-ai-tabs' );
		wp_add_inline_style( 'user-registration-ai-tabs', $css );
		wp_enqueue_script( 'user-registration-ai-tabs', UR()->plugin_url() . '/assets/js/admin/ai-tabs.js', array( 'jquery' ), UR_VERSION, true );
		wp_localize_script(
			'user-registration-ai-tabs',
			'urAiTabs',
			array(
				'tabs'     => $labels,
				'switchId' => 'user_registration_enable_abilities',
				'needsOn'  => __( 'Turn on Enable AI Abilities first.', 'user-registration' ),
			)
		);

		return $settings;
	}
}
