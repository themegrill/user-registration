<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: the AI Integration tabs are a layout only. They draw one tab for each card, change no setting, and turn
 * off with one filter, which puts the single long page back.
 *
 * Runs the real `UR_Abilities_Tabs` class with WordPress test doubles; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';

define( 'UR_VERSION', '1.0.0' );

$tab_filters = array();
$calls       = array();

function apply_filters( $tag, $value ) {
	return array_key_exists( $tag, $GLOBALS['tab_filters'] ) ? $GLOBALS['tab_filters'][ $tag ] : $value;
}
function add_filter() {}
function wp_register_style( $handle ) {
	$GLOBALS['calls']['register_style'][] = $handle;
}
function wp_enqueue_style( $handle ) {
	$GLOBALS['calls']['enqueue_style'][] = $handle;
}
function wp_add_inline_style( $handle, $css ) {
	$GLOBALS['calls']['inline_style'][ $handle ] = $css;
}
function wp_enqueue_script( $handle, $src ) {
	$GLOBALS['calls']['script'][ $handle ] = $src;
}
function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['calls']['localized'][ $name ] = $data;
}
class Plugin_Double {
	public function plugin_url() {
		return 'https://shop.example.com/wp-content/plugins/user-registration';
	}
}
function UR() {
	return new Plugin_Double();
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-settings.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-tabs.php';

$settings = array(
	'title'    => '',
	'sections' => array(
		'ai_integration'      => array(
			'title' => 'AI Integration',
			'type'  => 'card',
		),
		'ai_abilities_access' => array(
			'title' => 'AI Abilities Access',
			'type'  => 'card',
		),
	),
);

// The tabs follow the cards, one tab for each, in order.
security_assert( array( 'AI Integration', 'AI Abilities Access' ) === UR_Abilities_Tabs::labels( $settings ), 'There is one tab for each card, named after it, in the order the cards are drawn' );
security_assert( array( 'AI Integration' ) === UR_Abilities_Tabs::labels( array( 'sections' => array( 'a' => array( 'title' => 'AI Integration', 'type' => 'card' ), 'b' => array( 'title' => 'Not a card', 'type' => 'notice' ), 'c' => array( 'type' => 'card' ) ) ) ), 'Only cards with a title get a tab' );
security_assert( array() === UR_Abilities_Tabs::labels( 'odd' ) && array() === UR_Abilities_Tabs::labels( array() ), 'Settings of an unexpected shape give no tabs and no error' );

// The script and styles are added, and the settings are returned untouched.
$result = UR_Abilities_Tabs::add_tabs( $settings );
security_assert( $settings === $result, 'The settings are returned exactly as they came, so no field or card changes' );
security_assert( isset( $calls['script']['user-registration-ai-tabs'] ) && false !== strpos( $calls['script']['user-registration-ai-tabs'], '/assets/js/admin/ai-tabs.js' ), 'The tabs script is added' );
security_assert( array( 'AI Integration' ) === $calls['localized']['urAiTabs']['tabs'] && 'user_registration_enable_abilities' === $calls['localized']['urAiTabs']['switchId'], 'The script is given the first tab name and the id of the master switch, and adds the Connect tab itself' );
$css = $calls['inline_style']['user-registration-ai-tabs'] ?? '';
security_assert( false !== strpos( $css, '.ur-ai-pane--off{display:none !important}' ) && false === strpos( $css, '#ur-ability-access{display:none' ), 'The styles hide the cards of the inactive tab, and leave the access card alone, since it shows on the first tab' );

security_assert( false !== strpos( file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin/ai-tabs.js' ), 'nav.append($("#ur-ai-ready"))' ) && false !== strpos( $css, '.urcr-tabs .ur-ai-ready{position:relative;' ), 'The site status badge moves to the end of the tab row, so it shows on both tabs' );

// The tabs look like the Content Rules tabs: same class names, same rules.
security_assert( false !== strpos( $css, '.urcr-tabs{display:flex;gap:8px;' ) && false !== strpos( $css, 'border-bottom:1px solid #e0e0e0' ) && false !== strpos( $css, '.urcr-tab{padding:12px 16px;font-size:14px;font-weight:500;color:#666;' ) && false !== strpos( $css, '.urcr-tab.urcr-tab-active{color:#475bb2;' ), 'The tab styles are the Content Rules ones: spacing, text, colours and the active underline' );
$js_source = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin/ai-tabs.js' );
security_assert( false !== strpos( $js_source, 'class="urcr-tabs"' ) && false !== strpos( $js_source, 'class="urcr-tab"' ) && false !== strpos( $js_source, 'urcr-tab-active' ) && false === strpos( $js_source, 'ur-ai-tabs__tab' ), 'The tabs are drawn with the Content Rules class names and no new tab design' );

// The Connect content is a tab of its own. There is no drawer and no way back to one.
security_assert( 'Connect MCP' === $calls['localized']['urAiTabs']['connectLabel'] && ! isset( $calls['localized']['urAiTabs']['inline'] ), 'The script is told the Connect tab name' );
security_assert( 'Generated passwords' === $calls['localized']['urAiTabs']['activeLabel'], 'The script is given the name of the card for active connections' );
security_assert( false !== strpos( $js_source, 'ur-ai-active-pane' ) && false !== strpos( $js_source, 'body.find(".ur-ai-active")' ) && false !== strpos( $js_source, 'ur-ai-connections-changed' ) && false !== strpos( $js_source, 'pane.add(activePane)' ), 'The active connections are moved into a card of their own under the Connect card, on the same tab, and it follows the list as apps come and go' );
security_assert( false !== strpos( $css, '.ur-ai-active-pane--empty{display:none !important}' ), 'That card stays out of sight while there are no connections' );
security_assert( false !== strpos( $js_source, 'ur-ai-connect-pane' ) && false !== strpos( $js_source, '.ur-ai-connect-content' ), 'The Connect content is moved, not copied, into a tab' );
$connect_js  = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin/ai-connect.js' );
$connect_php = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-connect.php' );
security_assert( false === strpos( $connect_js, 'openDrawer' ) && false === strpos( $connect_js, 'cfg.inline' ) && false === strpos( $connect_php, 'ur-ai-drawer' ) && false === strpos( $connect_php, 'ur-ai-open' ) && false === strpos( $connect_php, 'UR_Abilities_Tabs::enabled' ), 'The drawer, its button and the switch back to it are gone' );
security_assert( false === strpos( file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-tabs.php' ), 'user_registration_ai_settings_tabs' ), 'There is no filter that turns the tabs off' );
security_assert( false !== strpos( $js_source, 'var groups = [cards, pane.add(activePane)];' ) && false === strpos( $js_source, 'ur-ability-access' ), 'The first tab holds every card the page already had, the access card included, and the script never singles it out' );

// One card alone has nothing to split into tabs.
$calls = array();
UR_Abilities_Tabs::add_tabs( array( 'sections' => array( 'ai_integration' => array( 'title' => 'AI Integration', 'type' => 'card' ) ) ) );
security_assert( array() === $calls, 'With a single card there are no tabs and nothing is added' );

// The script only shows and hides cards that the page already draws.
$script = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin/ai-tabs.js' );
security_assert( false !== strpos( $script, 'ur-ai-pane--off' ) && false === strpos( $script, '.remove()' ) && false === strpos( $script, '$.post' ) && false === strpos( $script, 'ajax' ), 'The script only toggles a class on the cards: it removes nothing and sends nothing' );
security_assert( false !== strpos( $script, 'aria-disabled' ) && false !== strpos( $script, 'is-disabled' ), 'The abilities tab waits, and says so, while the master switch is off' );
