<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress test doubles share this fixture.
/**
 * Isolated regression for restricted post excerpts leaking on archives and the profile completeness gate.
 *
 * @package UserRegistration/Tests
 */

// WordPress test doubles intentionally use minimal declarations.
// phpcs:disable Squiz.Commenting.ClassComment, Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, WordPress.PHP.YodaConditions.NotYoda, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Formatting.MultipleStatementAlignment, Generic.CodeAnalysis.UnusedFunctionParameter

require __DIR__ . '/bootstrap.php';

class WP_Post {
	public $ID;
	public function __construct( $id ) {
		$this->ID = $id; }
}
function add_action( $hook, $callback ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['filters'][ $hook ][] = array( $callback, $priority, $args ); }
function get_post( $post = null ) {
	return $post instanceof WP_Post ? $post : ( $GLOBALS['posts'][ (int) $post ] ?? null ); }
function urcr_is_content_access_granted( $post ) {
	return ! in_array( $post->ID, $GLOBALS['restricted'], true ); }
function get_current_user_id() {
	return $GLOBALS['user_id']; }
function wp_get_current_user() {
	return (object) array( 'ID' => $GLOBALS['user_id'] ); }
function get_user_meta( $id, $key, $single ) {
	return $GLOBALS['completeness'][ $id ] ?? ''; }

eval( security_function( 'modules/content-restriction/functions-urcr-core.php', 'urcr_is_allow_access' ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Loads the exact production function.
require dirname( __DIR__, 2 ) . '/modules/content-restriction/class-urcr-syndication-restriction.php';

// The filter must run late and receive the post so every theme block and REST read is covered.
security_assert(
	in_array( array( array( 'URCR_Syndication_Restriction', 'filter_excerpt' ), PHP_INT_MAX, 2 ), $GLOBALS['filters']['get_the_excerpt'] ?? array(), true ),
	'The excerpt filter is registered late and with the post argument'
);

$GLOBALS['posts']      = array(
	1 => new WP_Post( 1 ),
	2 => new WP_Post( 2 ),
);
$GLOBALS['restricted'] = array( 1 );
$GLOBALS['user_id']    = 0;

security_assert( '' === URCR_Syndication_Restriction::filter_excerpt( 'Secret teaser', 1 ), 'A restricted post excerpt is blanked by ID' );
security_assert( '' === URCR_Syndication_Restriction::filter_excerpt( 'Secret teaser', $GLOBALS['posts'][1] ), 'A restricted post excerpt is blanked by object' );
security_assert( 'Public teaser' === URCR_Syndication_Restriction::filter_excerpt( 'Public teaser', 2 ), 'An accessible post keeps its excerpt' );
security_assert( '' === URCR_Syndication_Restriction::filter_excerpt( '', 2 ), 'An empty excerpt stays empty' );
security_assert( 'Orphan' === URCR_Syndication_Restriction::filter_excerpt( 'Orphan', 99 ), 'An excerpt with no post behind it is left alone' );

$gate = array(
	'type'  => 'profile_completeness',
	'value' => 50,
);

$GLOBALS['user_id'] = 0;
security_assert( false === urcr_is_allow_access( $gate, new WP_Post( 1 ) ), 'An anonymous visitor never passes the completeness gate' );

$GLOBALS['user_id']      = 7;
$GLOBALS['completeness'] = array( 7 => '' );
security_assert( false === urcr_is_allow_access( $gate, new WP_Post( 1 ) ), 'An empty stored percentage counts as 0, not 100' );

$GLOBALS['completeness'] = array( 7 => '0' );
security_assert( false === urcr_is_allow_access( $gate, new WP_Post( 1 ) ), 'A stored 0 percent stays 0' );

$GLOBALS['completeness'] = array( 7 => '80' );
security_assert( true === urcr_is_allow_access( $gate, new WP_Post( 1 ) ), 'A completed profile above the threshold passes' );
