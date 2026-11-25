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

// On by default: the script and styles are added, and the settings are returned untouched.
security_assert( true === UR_Abilities_Tabs::enabled(), 'The tabs are on by default' );
$result = UR_Abilities_Tabs::add_tabs( $settings );
security_assert( $settings === $result, 'The settings are returned exactly as they came, so no field or card changes' );
security_assert( isset( $calls['script']['user-registration-ai-tabs'] ) && false !== strpos( $calls['script']['user-registration-ai-tabs'], '/assets/js/admin/ai-tabs.js' ), 'The tabs script is added' );
security_assert( array( 'AI Integration' ) === $calls['localized']['urAiTabs']['tabs'] && 'user_registration_enable_abilities' === $calls['localized']['urAiTabs']['switchId'], 'The script is given the first tab name and the id of the master switch, and adds the Connect tab itself' );
$css = $calls['inline_style']['user-registration-ai-tabs'] ?? '';
security_assert( false !== strpos( $css, '.ur-ai-pane--off{display:none !important}' ) && false === strpos( $css, '#ur-ability-access{display:none' ), 'The styles hide the cards of the inactive tab, and leave the access card alone, since it shows on the first tab' );

// The tabs look like the Content Rules tabs: same class names, same rules.
security_assert( false !== strpos( $css, '.urcr-tabs{display:flex;gap:8px;' ) && false !== strpos( $css, 'border-bottom:1px solid #e0e0e0' ) && false !== strpos( $css, '.urcr-tab{padding:12px 16px;font-size:14px;font-weight:500;color:#666;' ) && false !== strpos( $css, '.urcr-tab.urcr-tab-active{color:#475bb2;' ), 'The tab styles are the Content Rules ones: spacing, text, colours and the active underline' );
$js_source = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin/ai-tabs.js' );
security_assert( false !== strpos( $js_source, 'class="urcr-tabs"' ) && false !== strpos( $js_source, 'class="urcr-tab"' ) && false !== strpos( $js_source, 'urcr-tab-active' ) && false === strpos( $js_source, 'ur-ai-tabs__tab' ), 'The tabs are drawn with the Content Rules class names and no new tab design' );

// The content of the Connect drawer becomes a tab of its own, and the drawer stays for when the tabs are off.
security_assert( 'Connect an AI Assistant' === $calls['localized']['urAiTabs']['connectLabel'] && true === $calls['localized']['urAiTabs']['inline'], 'The script is told the Connect tab name, and that the Connect content is shown inline' );
security_assert( false !== strpos( $js_source, 'ur-ai-connect-pane' ) && false !== strpos( $js_source, '#ur-ai-drawer .ur-ai-drawer__body' ) && false !== strpos( $js_source, 'ur-ai-show-connect' ), 'The drawer content is moved, not copied, into a tab, and the Connect button switches to it' );
$connect_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin/ai-connect.js' );
security_assert( false !== strpos( $connect_js, 'if (cfg.inline)' ) && false !== strpos( $connect_js, 'ur-ai-show-connect' ), 'With the tabs on the Connect button asks for the tab; with them off it still opens the drawer' );
$connect_php = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-connect.php' );
security_assert( false !== strpos( $connect_php, "'inline'  => class_exists( 'UR_Abilities_Tabs' ) && UR_Abilities_Tabs::enabled()" ), 'The Connect script only goes inline while the tabs are enabled, so turning the tabs off brings the drawer back' );
security_assert( false !== strpos( $connect_php, "\$inline = class_exists( 'UR_Abilities_Tabs' ) && UR_Abilities_Tabs::enabled();" ) && false !== strpos( $connect_php, '<?php if ( ! $inline ) : ?>' ), 'The Connect row and its button are drawn only while the tabs are off' );
security_assert( false !== strpos( $js_source, 'var groups = [cards, pane];' ) && false === strpos( $js_source, 'ur-ability-access' ), 'The first tab holds every card the page already had, the access card included, and the script never singles it out' );

// Off with one filter: nothing is added, so the single long page is back.
$calls                                      = array();
$tab_filters['user_registration_ai_settings_tabs'] = false;
security_assert( false === UR_Abilities_Tabs::enabled(), 'One filter turns the tabs off' );
security_assert( $settings === UR_Abilities_Tabs::add_tabs( $settings ) && array() === $calls, 'With the tabs off nothing is added and the settings are untouched' );
$tab_filters = array();

// One card alone has nothing to split into tabs.
$calls = array();
UR_Abilities_Tabs::add_tabs( array( 'sections' => array( 'ai_integration' => array( 'title' => 'AI Integration', 'type' => 'card' ) ) ) );
security_assert( array() === $calls, 'With a single card there are no tabs and nothing is added' );

// The script only shows and hides cards that the page already draws.
$script = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin/ai-tabs.js' );
security_assert( false !== strpos( $script, 'ur-ai-pane--off' ) && false === strpos( $script, '.remove()' ) && false === strpos( $script, '$.post' ) && false === strpos( $script, 'ajax' ), 'The script only toggles a class on the cards: it removes nothing and sends nothing' );
security_assert( false !== strpos( $script, 'aria-disabled' ) && false !== strpos( $script, 'is-disabled' ), 'The abilities tab waits, and says so, while the master switch is off' );
