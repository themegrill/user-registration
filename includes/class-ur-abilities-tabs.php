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

		// The same tabs as Content Rules (Membership Rules and Custom Rules): their class names and their rules. Those rules
		// are scoped to the Content Rules page, so the same ones are given here, scoped to this settings page.
		$css = '.user-registration-options-container > .urcr-tabs{display:flex;gap:8px;margin:16px 0 8px;border-bottom:1px solid #e0e0e0;padding-bottom:0}'
			. '.user-registration-options-container > .urcr-tabs .urcr-tab{padding:12px 16px;font-size:14px;font-weight:500;color:#666;background:0 0;border:none;cursor:pointer;transition:all .2s ease;border-radius:4px 4px 0 0;margin:0;box-shadow:none;position:relative}'
			. '.user-registration-options-container > .urcr-tabs .urcr-tab::before{content:"";position:absolute;width:100%;height:2px;background:#475bb2;opacity:0;bottom:0;left:0;transition:all .3s ease}'
			. '.user-registration-options-container > .urcr-tabs .urcr-tab:hover{color:#222}.user-registration-options-container > .urcr-tabs .urcr-tab.urcr-tab-active{color:#475bb2;border-bottom-color:#475bb2}'
			. '.user-registration-options-container > .urcr-tabs .urcr-tab.urcr-tab-active::before{opacity:1}.user-registration-options-container > .urcr-tabs .urcr-tab:focus{outline:0;box-shadow:none;border:0}'
			. '.user-registration-options-container > .urcr-tabs .urcr-tab.is-disabled{opacity:.5;cursor:not-allowed}.user-registration-options-container > .urcr-tabs .urcr-tab.is-disabled:hover{color:#666}'
			. '.ur-ai-connect-pane .ur-ai-drawer__body{overflow:visible;padding:0 24px 24px}.ur-ai-connect-pane .ur-ai-drawer__body > h4:first-child{margin-top:8px}'
			. '.ur-ai-pane--off{display:none !important}.user-registration-options-container:not(.ur-ai-tabs-ready) #ur-ability-access{display:none !important}';
		wp_register_style( 'user-registration-ai-tabs', false, array(), UR_VERSION );
		wp_enqueue_style( 'user-registration-ai-tabs' );
		wp_add_inline_style( 'user-registration-ai-tabs', $css );
		wp_enqueue_script( 'user-registration-ai-tabs', UR()->plugin_url() . '/assets/js/admin/ai-tabs.js', array( 'jquery' ), UR_VERSION, true );
		wp_localize_script(
			'user-registration-ai-tabs',
			'urAiTabs',
			array(
				'tabs'         => $labels,
				'switchId'     => 'user_registration_enable_abilities',
				'needsOn'      => __( 'Turn on Enable AI Abilities first.', 'user-registration' ),
				// The Connect content gets a tab of its own, between the two cards, instead of the drawer.
				'connectLabel' => __( 'Connect an AI Assistant', 'user-registration' ),
				'inline'       => true,
			)
		);

		return $settings;
	}
}
