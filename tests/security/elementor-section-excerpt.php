<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress test doubles share this fixture.
/**
 * Isolated regression for restricted Elementor section text leaking through auto-generated excerpts.
 *
 * @package UserRegistration/Tests
 */

// WordPress test doubles intentionally use minimal declarations.
// phpcs:disable Squiz.Commenting.ClassComment, Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, WordPress.PHP.YodaConditions.NotYoda, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Formatting.MultipleStatementAlignment, Generic.Classes.DuplicateClassName, WordPress.PHP.DevelopmentFunctions.error_log_print_r, Generic.CodeAnalysis.UnusedFunctionParameter

require __DIR__ . '/bootstrap.php';

class WP_Post {
	public $ID;
	public $post_excerpt;
	public function __construct( $id, $excerpt = '' ) {
		$this->ID           = $id;
		$this->post_excerpt = $excerpt; }
}
function add_action( $hook, $callback ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['filters'][ $hook ][] = array( $callback, $priority, $args ); }
function urcr_is_content_access_granted( $post ) {
	return true; }
function ur_string_to_bool( $value ) {
	return in_array( $value, array( 'yes', 'on', true, 1, '1', 'true' ), true ); }
function get_current_user_id() {
	return $GLOBALS['current_user']; }
function get_post( $post = null ) {
	return $post instanceof WP_Post ? $post : ( $GLOBALS['posts'][ (int) $post ] ?? null ); }
function get_post_meta( $id, $key, $single ) {
	return '_elementor_data' === $key ? ( $GLOBALS['elementor_data'][ $id ] ?? '' ) : ( $GLOBALS['override'][ $id ] ?? '' ); }
/** The shortcode hides its content from this visitor unless they are in the allowed list. */
function do_shortcode( $content ) {
	$GLOBALS['shortcode_calls'][] = $GLOBALS['post']->ID;
	security_assert( $GLOBALS['post'] instanceof WP_Post, 'The shortcode is evaluated against the post' );
	return $GLOBALS['shortcode_allows'] ? 'granted' : '<div class="urcr-access-card">Membership Required</div>'; }

foreach ( array( 'urcr_elementor_has_restricted_section', 'urcr_is_elementor_section_denied', 'urcr_is_restrict_shortcode_denied' ) as $name ) {
	eval( security_function( 'modules/content-restriction/functions-urcr-core.php', $name ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Loads the exact production function.
}
require dirname( __DIR__, 2 ) . '/modules/content-restriction/class-urcr-syndication-restriction.php';

// The filter must run after wp_trim_excerpt and receive the post, or REST would stop blanking.
security_assert(
	array( array( 'URCR_Syndication_Restriction', 'filter_elementor_excerpt' ), PHP_INT_MAX, 2 ) === ( $GLOBALS['filters']['get_the_excerpt'][0] ?? null ),
	'The excerpt filter is registered late and with the post argument'
);

$section = static function ( $restricted, $children = array() ) {
	return array(
		'elType'   => 'section',
		'settings' => $restricted ? array( 'urcr_restrict_section' => 'yes' ) : array(),
		'elements' => $children,
	);
};

$GLOBALS['posts']          = array(
	1 => new WP_Post( 1 ),
	2 => new WP_Post( 2 ),
	3 => new WP_Post( 3, 'Hand written teaser' ),
	4 => new WP_Post( 4 ),
	5 => new WP_Post( 5 ),
	6 => new WP_Post( 6 ),
);
$GLOBALS['elementor_data'] = array(
	1 => wp_json_encode_stub( array( $section( false ), $section( true ) ) ),
	2 => wp_json_encode_stub( array( $section( false ) ) ),
	3 => wp_json_encode_stub( array( $section( true ) ) ),
	4 => wp_json_encode_stub( array( $section( false, array( $section( false, array( $section( true ) ) ) ) ) ) ),
	5 => wp_json_encode_stub( array( $section( true ) ) ),
	6 => '{not json',
);
function wp_json_encode_stub( $data ) {
	return json_encode( $data ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test double.

$GLOBALS['current_user']     = 0;
$GLOBALS['shortcode_allows'] = false;
$GLOBALS['caps']             = array();

// A denied visitor loses the auto generated excerpt of a post with a restricted section.
security_assert( '' === URCR_Syndication_Restriction::filter_elementor_excerpt( 'SECRET', 1 ), 'Restricted section text is blanked from the excerpt' );
security_assert( '' === URCR_Syndication_Restriction::filter_elementor_excerpt( 'SECRET', $GLOBALS['posts'][4] ), 'A restricted section nested in other elements is found' );

// A post with no restricted section, or unreadable builder data, keeps its excerpt.
security_assert( 'public' === URCR_Syndication_Restriction::filter_elementor_excerpt( 'public', 2 ), 'A post without a restricted section keeps its excerpt' );
security_assert( 'public' === URCR_Syndication_Restriction::filter_elementor_excerpt( 'public', 6 ), 'Invalid builder data leaves the excerpt alone' );

// A hand written excerpt is the author's choice and survives.
security_assert( 'Hand written teaser' === URCR_Syndication_Restriction::filter_elementor_excerpt( 'Hand written teaser', 3 ), 'A manual excerpt is kept' );

// A visitor the shortcode lets through keeps the excerpt.
$GLOBALS['current_user']     = 7;
$GLOBALS['shortcode_allows'] = true;
security_assert( 'SECRET' === URCR_Syndication_Restriction::filter_elementor_excerpt( 'SECRET', 1 ), 'An allowed visitor keeps the excerpt' );

// Editing rights do not bypass the section; the shortcode decides, exactly as it does when rendering.
$GLOBALS['current_user']     = 8;
$GLOBALS['shortcode_allows'] = false;
$GLOBALS['caps']             = array( 'edit_post' );
security_assert( '' === URCR_Syndication_Restriction::filter_elementor_excerpt( 'SECRET', 5 ), 'An editor the shortcode denies loses the excerpt, as in the render path' );
$GLOBALS['caps'] = array();

// Posts without an override share one shortcode answer per user; an override post is asked on its own.
$GLOBALS['current_user']     = 10;
$GLOBALS['shortcode_calls']  = array();
$GLOBALS['override']         = array( 4 => 'on' );
foreach ( array( 1, 3, 5, 4 ) as $id ) {
	URCR_Syndication_Restriction::filter_elementor_excerpt( 'SECRET', $id );
}
security_assert( array( 1, 4 ) === $GLOBALS['shortcode_calls'], 'The shortcode runs once for the global posts and once for the override post' );
security_assert( '' === URCR_Syndication_Restriction::filter_elementor_excerpt( 'SECRET', 5 ), 'The shared answer still blanks every global post' );

// The caller's global post is restored after the shortcode check.
$GLOBALS['current_user'] = 9;
$GLOBALS['post']    = 'caller-post';
URCR_Syndication_Restriction::filter_elementor_excerpt( 'SECRET', 5 );
security_assert( 'caller-post' === $GLOBALS['post'], 'The global post is restored' );
