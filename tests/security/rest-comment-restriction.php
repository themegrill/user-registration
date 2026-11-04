<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress test doubles share this fixture.
/**
 * Isolated REST comment restriction regressions; no site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// WordPress test doubles intentionally use minimal declarations.
// phpcs:disable Squiz.Commenting.ClassComment, Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.PHP.YodaConditions.NotYoda, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Formatting.MultipleStatementAlignment, Generic.Classes.DuplicateClassName

require __DIR__ . '/bootstrap.php';

class WP_Error {
	public $code;
	public $data;
	public function __construct( $code, $message, $data ) {
		$this->code = $code;
		$this->data = $data; }
}
class WP_REST_Request implements ArrayAccess {
	private $route;
	private $params;
	public function __construct( $route, $params = array() ) {
		$this->route  = $route;
		$this->params = $params; }
	public function get_route() {
		return $this->route; }
	#[ReturnTypeWillChange]
	public function offsetGet( $key ) {
		return $this->params[ $key ] ?? null; }
	#[ReturnTypeWillChange]
	public function offsetExists( $key ) {
		return isset( $this->params[ $key ] ); }
	#[ReturnTypeWillChange]
	public function offsetSet( $key, $value ) {
		$this->params[ $key ] = $value; }
	#[ReturnTypeWillChange]
	public function offsetUnset( $key ) {
		unset( $this->params[ $key ] ); }
}
class Security_Wpdb {
	public $comments = 'wp_comments';
	public $queries  = array();
	public function get_col( $sql ) {
		$this->queries[] = $sql;
		return $GLOBALS['commented_posts']; }
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error; }
function add_action( $hook, $callback ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function get_current_user_id() {
	return $GLOBALS['current_user']; }
function get_comment( $id ) {
	return $GLOBALS['comments'][ (int) $id ] ?? null; }
function urcr_is_content_access_granted( $post_id ) {
	$GLOBALS['resolver_calls'][] = (int) $post_id;
	return ! in_array( (int) $post_id, $GLOBALS['restricted_posts'], true ); }

require dirname( __DIR__, 2 ) . '/modules/content-restriction/class-urcr-rest-restriction.php';

$wpdb                     = new Security_Wpdb();
$GLOBALS['commented_posts'] = array( '1', '2', '3', '4' );
$GLOBALS['restricted_posts'] = array( 2, 4 );
$GLOBALS['resolver_calls']   = array();

// Collection without a post filter: every restricted commented post is excluded in the query.
$GLOBALS['current_user'] = 10;
$args                    = URCR_REST_Restriction::exclude_restricted_post_comments( array() );
security_assert( array( 2, 4 ) === $args['post__not_in'], 'Restricted posts are excluded from the comment query' );
security_assert( false !== strpos( $wpdb->queries[0], "NOT IN ( 'spam', 'trash' )" ), 'The scan skips spam and trash comments' );

// A second query in the same request reuses the scan and the access answers.
URCR_REST_Restriction::exclude_restricted_post_comments( array() );
security_assert( 1 === count( $wpdb->queries ), 'The commented post list is read once per user and request' );
security_assert( array( 1, 2, 3, 4 ) === $GLOBALS['resolver_calls'], 'Each post is checked once per user and request' );

// A request for specific posts checks only those posts and runs no scan.
$GLOBALS['current_user'] = 11;
$GLOBALS['resolver_calls'] = array();
$args                      = URCR_REST_Restriction::exclude_restricted_post_comments( array( 'post__in' => array( 1, 2 ) ) );
security_assert( 1 === count( $wpdb->queries ), 'A post filter does not scan the comments table' );
security_assert( array( 1, 2 ) === $GLOBALS['resolver_calls'], 'Only the requested posts are checked' );
security_assert( array( 2 ) === $args['post__not_in'], 'A restricted requested post is excluded' );

// Exclusions already on the query are kept.
$GLOBALS['current_user'] = 12;
$args                    = URCR_REST_Restriction::exclude_restricted_post_comments( array( 'post__not_in' => array( 9 ) ) );
security_assert( array( 9, 2, 4 ) === $args['post__not_in'], 'Existing post__not_in values are kept' );

// Nothing restricted: the query is left alone.
$GLOBALS['current_user']     = 13;
$GLOBALS['restricted_posts'] = array();
$args                        = URCR_REST_Restriction::exclude_restricted_post_comments( array( 'post__in' => array( 1 ) ) );
security_assert( ! isset( $args['post__not_in'] ), 'No exclusion is added when every post is accessible' );
$GLOBALS['restricted_posts'] = array( 2, 4 );

// Single comment on a restricted post is a 404; others pass through.
$GLOBALS['current_user'] = 14;
$GLOBALS['comments']     = array(
	7 => (object) array( 'comment_post_ID' => 2 ),
	8 => (object) array( 'comment_post_ID' => 1 ),
);
$ok                      = array( 'id' => 7 );
$response                = URCR_REST_Restriction::restrict_single_comment( $ok, array(), new WP_REST_Request( '/wp/v2/comments/7', array( 'id' => 7 ) ) );
security_assert( $response instanceof WP_Error && 404 === $response->data['status'] && 'rest_comment_invalid_id' === $response->code, 'Comment on a restricted post returns 404' );
$response = URCR_REST_Restriction::restrict_single_comment( array( 'id' => 8 ), array(), new WP_REST_Request( '/wp/v2/comments/8', array( 'id' => 8 ) ) );
security_assert( array( 'id' => 8 ) === $response, 'Comment on an accessible post is returned' );
$response = URCR_REST_Restriction::restrict_single_comment( $ok, array(), new WP_REST_Request( '/wp/v2/posts/7', array( 'id' => 7 ) ) );
security_assert( $ok === $response, 'Other routes are not touched' );
$response = URCR_REST_Restriction::restrict_single_comment( $ok, array(), new WP_REST_Request( '/wp/v2/comments/99', array( 'id' => 99 ) ) );
security_assert( $ok === $response, 'An unknown comment is left to core to report' );
$error    = new WP_Error( 'x', 'x', array( 'status' => 400 ) );
$response = URCR_REST_Restriction::restrict_single_comment( $error, array(), new WP_REST_Request( '/wp/v2/comments/7', array( 'id' => 7 ) ) );
security_assert( $error === $response, 'An existing error is passed through' );

