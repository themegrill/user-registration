<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress test doubles share this fixture.
/**
 * Isolated REST post collection regressions; no site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// WordPress test doubles intentionally use minimal declarations.
// phpcs:disable Squiz.Commenting.ClassComment, Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.PHP.YodaConditions.NotYoda, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Formatting.MultipleStatementAlignment, Generic.Classes.DuplicateClassName

require __DIR__ . '/bootstrap.php';

class WP_Query {
	public $posts = array();
	public function __construct( $args = array() ) {
		$GLOBALS['candidate_args'] = $args;
		$this->posts               = $GLOBALS['matching_posts']; }
}
function add_action( $hook, $callback ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $hook ][] = $callback; }
function get_current_user_id() {
	return $GLOBALS['current_user']; }
function ur_string_to_bool( $value ) {
	return (bool) $value; }
function is_super_admin() {
	return ! empty( $GLOBALS['super_admin'] ); }
function _prime_post_caches( $ids, $terms = true, $meta = true ) {
	$GLOBALS['primed'] = $ids; }
function urcr_is_content_access_granted( $post_id ) {
	return ! in_array( (int) $post_id, $GLOBALS['restricted_posts'], true ); }

require dirname( __DIR__, 2 ) . '/modules/content-restriction/class-urcr-rest-restriction.php';

$GLOBALS['options']['user_registration_content_restriction_enable'] = true;
$GLOBALS['restricted_posts']    = array( 2, 4 );
$GLOBALS['matching_posts']      = array( 1, 2, 3, 4 );
$GLOBALS['current_user']        = 20;

// A paged, searched request: every matching post is checked, and restricted ones leave the real query.
$request = array(
	's'              => 'secret',
	'posts_per_page' => 10,
	'paged'          => 3,
	'offset'         => 20,
);
$args    = URCR_REST_Restriction::exclude_restricted_posts( $request, null );
security_assert( array( 2, 4 ) === $args['post__not_in'], 'Restricted posts are excluded before the query is counted' );
security_assert( 'secret' === $args['s'] && 10 === $args['posts_per_page'] && 3 === $args['paged'], 'The request arguments are returned untouched' );
$scan = $GLOBALS['candidate_args'];
security_assert( 'secret' === $scan['s'] && 'ids' === $scan['fields'], 'The scan uses the same search and only reads IDs' );
security_assert( -1 === $scan['posts_per_page'] && 1 === $scan['paged'] && 0 === $scan['offset'], 'The scan ignores pagination so no matching post is missed' );

// Exclusions already on the query are kept.
$GLOBALS['current_user'] = 21;
$args                    = URCR_REST_Restriction::exclude_restricted_posts( array( 'post__not_in' => array( 9 ) ), null );
security_assert( array( 9, 2, 4 ) === $args['post__not_in'], 'Existing post__not_in values are kept' );

// Nothing restricted: the query is left alone.
$GLOBALS['current_user']     = 22;
$GLOBALS['restricted_posts'] = array();
$args                        = URCR_REST_Restriction::exclude_restricted_posts( array( 's' => 'open' ), null );
security_assert( ! isset( $args['post__not_in'] ), 'No exclusion is added when every post is accessible' );

// WP_Query ignores post__not_in when post__in is set, so an include list is trimmed instead.
$GLOBALS['current_user']     = 24;
$GLOBALS['restricted_posts'] = array( 2, 4 );
$args                    = URCR_REST_Restriction::exclude_restricted_posts( array( 'post__in' => array( 1, 2, 3 ) ), null );
security_assert( array( 1, 3 ) === $args['post__in'] && ! isset( $args['post__not_in'] ), 'Restricted posts are removed from an include list' );
$args = URCR_REST_Restriction::exclude_restricted_posts( array( 'post__in' => array( '2', 4 ) ), null );
security_assert( array( 0 ) === $args['post__in'], 'An include list of only restricted posts matches nothing instead of everything' );

// The bulk cache prime covers the candidate posts.
security_assert( array( 1, 2, 3, 4 ) === $GLOBALS['primed'], 'Candidate posts are primed in bulk before the access checks' );

// A super admin is granted everything, so no scan runs.
$GLOBALS['current_user']   = 25;
$GLOBALS['super_admin']    = true;
$GLOBALS['candidate_args'] = null;
$args                      = URCR_REST_Restriction::exclude_restricted_posts( array( 's' => 'secret' ), null );
security_assert( null === $GLOBALS['candidate_args'] && ! isset( $args['post__not_in'] ), 'A super admin skips the scan' );
$GLOBALS['super_admin'] = false;

// Restriction switched off: no scan runs at all.
$GLOBALS['current_user']        = 23;
$GLOBALS['restricted_posts']    = array( 2, 4 );
$GLOBALS['options']['user_registration_content_restriction_enable'] = false;
$GLOBALS['candidate_args']      = null;
$args                           = URCR_REST_Restriction::exclude_restricted_posts( array( 's' => 'secret' ), null );
security_assert( null === $GLOBALS['candidate_args'] && ! isset( $args['post__not_in'] ), 'Nothing is scanned or excluded while content restriction is disabled' );

// The core search endpoint builds its own query, so it needs the same exclusion.
$search_hook = array( 'URCR_REST_Restriction', 'exclude_restricted_posts' );
security_assert( in_array( $search_hook, $GLOBALS['hooks']['rest_post_search_query'], true ), 'The REST search endpoint uses the same exclusion' );
