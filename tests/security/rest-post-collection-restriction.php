<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress test doubles share this fixture.
/**
 * Isolated REST post collection regressions; no site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// WordPress test doubles intentionally use minimal declarations.
// phpcs:disable Squiz.PHP.Eval.Discouraged, Squiz.Commenting.ClassComment, Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.PHP.YodaConditions.NotYoda, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Formatting.MultipleStatementAlignment, Generic.Classes.DuplicateClassName

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
	$GLOBALS['primed'][] = $ids;
	$GLOBALS['primed_terms'] = $terms; }
function wp_using_ext_object_cache() {
	return ! empty( $GLOBALS['ext_cache'] ); }
function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['cache_deleted'][ $group ][] = $key; }
function ur_check_module_activation( $slug ) {
	return ! empty( $GLOBALS['drip_active'] ); }
function urcr_get_published_access_rules() {
	return $GLOBALS['rules']; }
function urcr_is_access_rule_evaluable( $rule ) {
	return is_array( $rule ) && ! empty( $rule['enabled'] ); }
class SecurityWpdb {
	public $postmeta = 'wp_postmeta';
	public function prepare( $sql, $args ) {
		$GLOBALS['override_query'] = array( $sql, $args );
		return $sql; }
	public function get_var( $sql ) {
		return $GLOBALS['override_row']; }
}
$GLOBALS['wpdb'] = new SecurityWpdb();
define( 'UR_PRO_ACTIVE', true );
function urcr_is_content_access_granted( $post_id ) {
	return ! in_array( (int) $post_id, $GLOBALS['restricted_posts'], true ); }

foreach ( array( 'urcr_is_content_drip_enabled', 'urcr_has_evaluable_access_rule', 'urcr_has_post_override', 'urcr_has_restriction_source' ) as $core_function ) {
	eval( security_function( 'modules/content-restriction/functions-urcr-core.php', $core_function ) );
}

require dirname( __DIR__, 2 ) . '/modules/content-restriction/class-urcr-rest-restriction.php';

$GLOBALS['options']['user_registration_content_restriction_enable'] = true;
$GLOBALS['options']['user_registration_content_restriction_whole_site_access'] = true;
$GLOBALS['rules']        = array();
$GLOBALS['override_row'] = null;
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
security_assert( array( 1, 2, 3, 4 ) === $GLOBALS['primed'][0], 'Candidate posts are primed in bulk before the access checks' );

// A large collection is checked in chunks, every post is still checked, and each chunk is released after its checks.
$GLOBALS['current_user']     = 26;
$GLOBALS['matching_posts']   = range( 1, 1201 );
$GLOBALS['restricted_posts'] = array( 2, 1200 );
$GLOBALS['primed']           = array();
$GLOBALS['cache_deleted']    = array();
$args                        = URCR_REST_Restriction::exclude_restricted_posts( array( 's' => 'big' ), null );
security_assert( array( 500, 500, 201 ) === array_map( 'count', $GLOBALS['primed'] ), 'The scan is split into chunks of 500 posts' );
security_assert( array( 2, 1200 ) === $args['post__not_in'], 'Restricted posts in any chunk are excluded' );
security_assert( 1201 === count( $GLOBALS['cache_deleted']['posts'] ) && 1201 === count( $GLOBALS['cache_deleted']['post_meta'] ), 'Every chunk is released after it is checked' );

// An external object cache keeps posts for other requests, so nothing is dropped from it.
$GLOBALS['current_user']  = 27;
$GLOBALS['ext_cache']     = true;
$GLOBALS['cache_deleted'] = array();
URCR_REST_Restriction::exclude_restricted_posts( array( 's' => 'big' ), null );
security_assert( array() === $GLOBALS['cache_deleted'], 'An external object cache is left alone' );
$GLOBALS['ext_cache']      = false;
$GLOBALS['matching_posts'] = array( 1, 2, 3, 4 );

// Nothing on the site can restrict a post: no scan runs and the query is left alone.
$GLOBALS['current_user']     = 28;
$GLOBALS['restricted_posts'] = array( 2, 4 );
$GLOBALS['options']['user_registration_content_restriction_whole_site_access'] = false;
$GLOBALS['candidate_args']   = null;
$args                        = URCR_REST_Restriction::exclude_restricted_posts( array( 's' => 'secret' ), null );
security_assert( null === $GLOBALS['candidate_args'] && ! isset( $args['post__not_in'] ), 'No scan runs when no rule, override or setting can restrict a post' );

// Each restriction source on its own switches the scan back on.
security_assert( false === urcr_has_restriction_source(), 'A site with no restriction source reports none' );
$GLOBALS['options']['user_registration_content_restriction_whole_site_access'] = true;
security_assert( true === urcr_has_restriction_source(), 'Whole site members only counts as a restriction source' );
$GLOBALS['options']['user_registration_content_restriction_whole_site_access'] = false;
$GLOBALS['drip_active'] = true;
security_assert( true === urcr_has_restriction_source(), 'Content drip counts as a restriction source' );
$GLOBALS['drip_active'] = false;
$GLOBALS['rules']       = array( (object) array( 'post_content' => '{"enabled":false}' ), (object) array( 'post_content' => 'not json' ) );
security_assert( false === urcr_has_restriction_source(), 'Disabled and unreadable rules are not a restriction source' );
$GLOBALS['rules'][]     = (object) array( 'post_content' => '{"enabled":true}' );
security_assert( true === urcr_has_restriction_source(), 'An enabled rule counts as a restriction source' );
$GLOBALS['rules']        = array();
$GLOBALS['override_row'] = '7';
security_assert( true === urcr_has_restriction_source(), 'A post with its own restriction settings counts as a restriction source' );
list( $override_sql, $override_args ) = $GLOBALS['override_query'];
security_assert( 'urcr_meta_override_global_settings' === $override_args[0] && false !== strpos( $override_sql, 'LIMIT 1' ), 'The override check is one limited lookup on the override meta key' );
security_assert( false !== strpos( $override_sql, 'NOT IN' ) && array( '', '0', 'no', 'off', 'false' ) === array_slice( $override_args, 1 ), 'Only clearly disabled values are ignored, so an unfamiliar override value still triggers the scan' );
$GLOBALS['override_row'] = null;

// Terms are primed with the posts so taxonomy targeted rules do not query each post's terms one by one.
security_assert( true === $GLOBALS['primed_terms'], 'The bulk prime includes the term cache' );

// Tripwire: whatever can restrict a post in urcr_is_content_access_granted() must be reported by urcr_has_restriction_source().
$access_source = security_function( 'modules/content-restriction/functions-urcr-core.php', 'urcr_is_content_access_granted' );
preg_match_all( "/\b(get_option|get_post_meta)\(\s*(?:\\\$[a-z_]+,\s*)?'([a-z_]+)'/", $access_source, $reads );
preg_match_all( '/\b(urcr_[a-z_]+)\(/', $access_source, $calls );
$known_reads = array( 'user_registration_content_restriction_enable', 'urcr_meta_override_global_settings', 'urcr_allow_to', 'urcr_meta_roles', 'urcr_meta_memberships', 'user_registration_content_restriction_whole_site_access', 'user_registration_content_restriction_allow_access_to', 'user_registration_content_restriction_allow_to_roles', 'user_registration_content_restriction_allow_to_memberships' );
$known_calls = array( 'urcr_is_content_access_granted', 'urcr_is_page_excluded', 'urcr_is_basic_access_granted', 'urcr_resolve_access_rules', 'urcr_has_whole_site_access_rule', 'urcr_is_content_drip_pending' );
security_assert( array() === array_diff( array_unique( $reads[2] ), $known_reads ) && array() === array_diff( array_unique( $calls[1] ), $known_calls ), 'urcr_is_content_access_granted() reads a new setting or calls a new helper: add it to urcr_has_restriction_source() and to this list' );
security_assert( false === strpos( $access_source, 'apply_filters' ), 'A filter in urcr_is_content_access_granted() can restrict posts that urcr_has_restriction_source() cannot see' );
$GLOBALS['options']['user_registration_content_restriction_whole_site_access'] = true;

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
