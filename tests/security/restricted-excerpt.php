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
class WP_Query {
	private $main;
	public function __construct( $main = false ) {
		$this->main = $main; }
	public function is_feed() {
		return false; }
	public function is_embed() {
		return false; }
	public function is_search() {
		return false; }
	public function get( $key ) {
		return false; }
	public function is_main_query() {
		return $this->main; }
}
function add_action( $hook, $callback ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['filters'][ $hook ][] = array( $callback, $priority, $args ); }
function apply_filters( $hook, $value ) {
	if ( 'user_registration_process_smart_tags' === $hook && ! empty( $GLOBALS['smart_tag_hook'] ) ) {
		( $GLOBALS['smart_tag_hook'] )();
	}
	return $value; }
function get_post( $post = null ) {
	if ( null === $post ) {
		return $GLOBALS['post'] ?? null;
	}
	return $post instanceof WP_Post ? $post : ( $GLOBALS['posts'][ (int) $post ] ?? null ); }
function urcr_is_content_access_granted( $post ) {
	$id = $post instanceof WP_Post ? $post->ID : (int) $post;
	return ! in_array( $id, $GLOBALS['restricted'][ $GLOBALS['blog_id'] ] ?? array(), true ); }
function get_current_blog_id() {
	return $GLOBALS['blog_id']; }
function get_current_user_id() {
	return $GLOBALS['user_id']; }
function wp_get_current_user() {
	return (object) array( 'ID' => $GLOBALS['user_id'] ); }
function get_user_meta( $id, $key, $single ) {
	return $GLOBALS['completeness'][ $id ] ?? ''; }
function esc_html__( $text, $domain = '' ) {
	return $text; }
function wp_kses_post( $text ) {
	return $text; }
function is_singular() {
	return ! empty( $GLOBALS['is_singular'] ); }
function is_main_query() {
	return ! empty( $GLOBALS['is_main_query'] ); }
function get_queried_object_id() {
	return $GLOBALS['queried_object_id'] ?? 0; }

eval( security_function( 'modules/content-restriction/functions-urcr-core.php', 'urcr_is_allow_access' ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Loads the exact production function.
require dirname( __DIR__, 2 ) . '/modules/content-restriction/class-urcr-syndication-restriction.php';

// The filters must run late so every theme block and REST read is covered.
security_assert(
	in_array( array( array( 'URCR_Syndication_Restriction', 'filter_excerpt' ), PHP_INT_MAX, 2 ), $GLOBALS['filters']['get_the_excerpt'] ?? array(), true ),
	'The excerpt filter is registered late and with the post argument'
);
security_assert(
	in_array( array( array( 'URCR_Syndication_Restriction', 'filter_content' ), PHP_INT_MAX, 1 ), $GLOBALS['filters']['the_content'] ?? array(), true ),
	'The content filter is registered late on the_content'
);

$GLOBALS['posts']      = array(
	1 => new WP_Post( 1 ),
	2 => new WP_Post( 2 ),
);
$GLOBALS['blog_id']    = 1;
$GLOBALS['restricted'] = array(
	1 => array( 1 ),
	2 => array(),
);
$GLOBALS['user_id']    = 0;
$GLOBALS['options']    = array( 'user_registration_content_restriction_message' => 'Members only.' );

security_assert( '' === URCR_Syndication_Restriction::filter_excerpt( 'Secret teaser', 1 ), 'A restricted post excerpt is blanked by ID' );
security_assert( '' === URCR_Syndication_Restriction::filter_excerpt( 'Secret teaser', $GLOBALS['posts'][1] ), 'A restricted post excerpt is blanked by object' );
security_assert( 'Public teaser' === URCR_Syndication_Restriction::filter_excerpt( 'Public teaser', 2 ), 'An accessible post keeps its excerpt' );
security_assert( '' === URCR_Syndication_Restriction::filter_excerpt( '', 2 ), 'An empty excerpt stays empty' );
security_assert( 'Orphan' === URCR_Syndication_Restriction::filter_excerpt( 'Orphan', 99 ), 'An excerpt with no post behind it is left alone' );

// Switching blogs must not reuse blog 1's cached denial for a post with the same ID on blog 2.
$GLOBALS['blog_id'] = 2;
security_assert( 'Blog 2 teaser' === URCR_Syndication_Restriction::filter_excerpt( 'Blog 2 teaser', 1 ), 'Multisite cache key isolates decisions per blog ID' );
$GLOBALS['blog_id'] = 1;

// Secondary loop / archive content restriction via the_content.
$GLOBALS['is_singular']       = false;
$GLOBALS['is_main_query']     = false;
$GLOBALS['queried_object_id'] = 0;
$GLOBALS['post']              = $GLOBALS['posts'][1];
security_assert(
	false !== strpos( URCR_Syndication_Restriction::filter_content( 'Secret body' ), 'Members only.' ),
	'Restricted post content is replaced with the restriction message in archive/secondary loops'
);

$GLOBALS['post'] = $GLOBALS['posts'][2];
security_assert( 'Public body' === URCR_Syndication_Restriction::filter_content( 'Public body' ), 'Accessible post content is preserved in loops' );

// Single main post is left alone so urcr_apply_content_restriction() keeps ownership.
$GLOBALS['is_singular']       = true;
$GLOBALS['is_main_query']     = true;
$GLOBALS['queried_object_id'] = 1;
$GLOBALS['post']              = $GLOBALS['posts'][1];
security_assert( 'Rule custom form' === URCR_Syndication_Restriction::filter_content( 'Rule custom form' ), 'Single main post skips filter_content' );

// A secondary query inside a singular public page still restricts restricted posts.
$GLOBALS['is_singular']       = true;
$GLOBALS['is_main_query']     = false;
$GLOBALS['queried_object_id'] = 2;
$GLOBALS['post']              = $GLOBALS['posts'][1];
security_assert(
	false !== strpos( URCR_Syndication_Restriction::filter_content( 'Secret body' ), 'Members only.' ),
	'Secondary query inside a singular page still restricts inaccessible posts'
);

// Re-entry while building the restriction message returns empty without recursing.
$inner_result                = null;
$GLOBALS['smart_tag_hook']   = static function () use ( &$inner_result ) {
	$inner_result = URCR_Syndication_Restriction::filter_content( 'Nested content' );
};
$outer_result                = URCR_Syndication_Restriction::filter_content( 'Secret body' );
$GLOBALS['smart_tag_hook']   = null;
security_assert( '' === $inner_result && false !== strpos( $outer_result, 'Members only.' ), 'Re-entrant the_content call returns empty without breaking outer message' );

// core/latest-posts in full_post mode filters restricted posts out of its secondary query.
$secondary_query = new WP_Query( false );
$latest_full     = array(
	'blockName' => 'core/latest-posts',
	'attrs'     => array(
		'displayPostContent'      => true,
		'displayPostContentRadio' => 'full_post',
	),
);
URCR_Syndication_Restriction::enter_latest_posts_block( null, $latest_full );
$filtered = URCR_Syndication_Restriction::filter_posts( array( $GLOBALS['posts'][1], $GLOBALS['posts'][2] ), $secondary_query );
URCR_Syndication_Restriction::leave_latest_posts_block( '' );
security_assert( 1 === count( $filtered ) && 2 === $filtered[0]->ID, 'Full-post Latest Posts block removes restricted posts from its query' );

// After leaving the block, ordinary secondary queries are untouched by filter_posts.
$unfiltered = URCR_Syndication_Restriction::filter_posts( array( $GLOBALS['posts'][1], $GLOBALS['posts'][2] ), $secondary_query );
security_assert( 2 === count( $unfiltered ), 'Secondary queries outside full-post Latest Posts are not removed by filter_posts' );

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
