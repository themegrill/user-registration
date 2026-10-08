<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress test doubles share this fixture.
/**
 * Isolated REST product restriction regressions; no site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// WordPress test doubles intentionally use minimal declarations.
// phpcs:disable Squiz.Commenting.ClassComment, Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.PHP.YodaConditions.NotYoda

require __DIR__ . '/bootstrap.php';

class WP_Post {
	public $ID;
	public $post_type;
	public $post_parent;
	public function __construct( $id, $type = 'product', $parent_id = 0 ) {
		$this->ID          = $id;
		$this->post_type   = $type;
		$this->post_parent = $parent_id;
	}
}
class WP_REST_Response {
	private $data;
	private $status;
	public function __construct( $data, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function get_data() {
		return $this->data; }
	public function set_data( $data ) {
		$this->data = $data; }
	public function get_status() {
		return $this->status; }
}
class WP_REST_Request {
	private $route;
	public function __construct( $route ) {
		$this->route = $route; }
	public function get_route() {
		return $this->route; }
}
class WP_Error {
	public $data;
	public function __construct( $code, $message, $data ) {
		$this->data = $data; }
}
class WC_Product {
	private $id;
	public function __construct( $id ) {
		$this->id = $id; }
	public function get_id() {
		return $this->id; }
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function get_post( $id ) {
	return $GLOBALS['security_posts'][ (int) $id ] ?? null; }
function urcr_is_content_access_granted( $post ) {
	return ! empty( $GLOBALS['authorized'] ) || ! in_array( $post->ID, $GLOBALS['restricted'], true );
}
function is_wp_error( $response ) {
	return $response instanceof WP_Error; }
function rest_ensure_response( $data ) {
	return $data instanceof WP_REST_Response ? $data : new WP_REST_Response( $data ); }
function add_action( $hook, $callback ) {
	$GLOBALS['hooks'][ $hook ] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $hook ] = $callback; }
function get_post_types( $args ) {
	return array( 'product', 'post', 'attachment' ); }
function apply_filters( $hook, $value, ...$args ) {
	return $value; }

require $argv[1] ?? dirname( __DIR__, 2 ) . '/modules/content-restriction/class-urcr-rest-restriction.php';
URCR_REST_Restriction::register_response_filters();
$GLOBALS['security_posts'] = array(
	1 => new WP_Post( 1 ),
	2 => new WP_Post( 2 ),
	3 => new WP_Post( 3, 'product_variation', 1 ),
	4 => new WP_Post( 4, 'product_variation', 2 ),
);
$GLOBALS['restricted']     = array( 1, 4 );
$private                   = array(
	'id'                => 1,
	'description'       => 'PRIVATE_LONG',
	'short_description' => 'PRIVATE_SHORT',
	'sku'               => 'PRIVATE_SKU',
	'extensions'        => array( 'private' => 'PRIVATE_EXTENSION' ),
);
$public                    = array(
	'id'                => 2,
	'description'       => 'PUBLIC_LONG',
	'short_description' => 'PUBLIC_SHORT',
	'sku'               => 'PUBLIC_SKU',
);

function product_response( $route, $data ) {
	return call_user_func( $GLOBALS['hooks']['rest_request_after_callbacks'], $data, array(), new WP_REST_Request( $route ) );
}
function assert_private_absent( $response ) {
	$data = $response instanceof WP_REST_Response ? $response->get_data() : $response->data;
	security_assert( false === strpos( json_encode( $data ), 'PRIVATE_' ), 'No private field may survive' );
}

$core = URCR_REST_Restriction::restrict_response( new WP_REST_Response( $private ), get_post( 1 ), new WP_REST_Request( '/wp/v2/product/1' ) );
assert_private_absent( $core );
security_assert( array( 'id' => 1 ) === $core->get_data(), 'Core product responses retain no sensitive fields' );
foreach ( array( 'product', 'product_variation' ) as $security_type ) {
	$response = call_user_func( $GLOBALS['hooks'][ 'woocommerce_rest_prepare_' . $security_type . '_object' ], new WP_REST_Response( array( 'description' => 'PRIVATE_LONG' ) ), new WC_Product( 3 ), new WP_REST_Request( '/wc/v3/products/1/variations/3' ) );
	assert_private_absent( $response );
}

foreach ( array( '/wc/store/v1/products/1', '/wc/store/v1/products/restricted-slug', '/wc/store/products/1', '/wc/v3/products/1' ) as $route ) {
	$response = product_response( $route, $private );
	security_assert( $response instanceof WP_Error && 404 === $response->data['status'], 'Restricted single product returns 404: ' . $route );
	assert_private_absent( $response );
}
// Query execution is a live integration concern; this guard checks its resulting objects.
$response = product_response( '/wc/store/v1/products', array( $private, $public ) );
security_assert( array( $public ) === $response->get_data(), 'Preserve accessible collection products and reindex the list' );
assert_private_absent( $response );
security_assert( array() === product_response( '/wc/store/v1/products', array( $private ) )->get_data(), 'Restricted-only collections stay JSON lists' );
$variation       = $private;
$variation['id'] = 3;
foreach ( array( '/wc/store/v1/products/3', '/wc/v3/products/1/variations/3' ) as $route ) {
	security_assert( product_response( $route, $variation ) instanceof WP_Error, 'Variation inherits parent restriction' );
}
$linked                     = $public;
$linked['variations']       = array(
	array(
		'id'         => 4,
		'attributes' => array( 'PRIVATE_ATTRIBUTE' ),
	),
	array( 'id' => 3 ),
);
$linked['related_ids']      = array( 1, 2 );
$linked['upsell_ids']       = array( 1 );
$linked['grouped_products'] = array( 1, 2 );
$filtered                   = product_response( '/wc/store/v1/products/2', $linked )->get_data();
security_assert( array() === $filtered['variations'], 'Restricted variation attributes and parent-restricted variations disappear' );
security_assert( array( 2 ) === $filtered['related_ids'] && array() === $filtered['upsell_ids'] && array( 2 ) === $filtered['grouped_products'], 'Restricted linked products disappear' );

foreach ( array( '/wc/store/v1/cart', '/wc/store/v1/cart/add-item', '/wc/store/v1/checkout' ) as $route ) {
	$response = product_response(
		$route,
		array(
			'items'  => array( $private, $public ),
			'totals' => array( 'total_price' => '1000' ),
		)
	);
	security_assert( $response instanceof WP_Error && 403 === $response->data['status'], 'Do not expose restricted cart items or return inconsistent totals' );
	assert_private_absent( $response );
}
security_assert( $public === product_response( '/wc/store/v1/products/2', $public )->get_data(), 'Accessible product is unchanged' );
security_assert( $private === product_response( '/custom/v1/products/1', $private ), 'Unrelated namespaces are untouched' );
$existing_error = new WP_Error( 'existing', 'Existing error', array( 'status' => 401 ) );
security_assert( product_response( '/wc/store/v1/products/1', $existing_error ) === $existing_error, 'Existing errors are untouched' );
security_assert( $private === product_response( '/wc/store/v1/products/1', new WP_REST_Response( $private, 500 ) )->get_data(), 'Failed responses are untouched' );
$GLOBALS['security_posts'][5] = new WP_Post( 5, 'post' );
$GLOBALS['restricted'][]      = 5;
$ordinary                     = URCR_REST_Restriction::restrict_response(
	new WP_REST_Response(
		array(
			'id'      => 5,
			'title'   => 'Public title',
			'content' => array( 'rendered' => 'PRIVATE_POST' ),
		)
	),
	get_post( 5 ),
	new WP_REST_Request( '/wp/v2/posts/5' )
)->get_data();
security_assert(
	array(
		'rendered'  => '',
		'protected' => true,
	) === $ordinary['content'] && 'Public title' === $ordinary['title'],
	'Existing non-product redaction is preserved'
);
// WooCommerce uses objects for checkout cart data and variation references.
$object_linked               = $linked;
$object_linked['variations'] = array(
	(object) array(
		'id'         => 4,
		'attributes' => array( 'PRIVATE_ATTRIBUTE' ),
	),
	(object) array( 'id' => 3 ),
	(object) array( 'id' => 2 ),
);
$filtered                    = product_response( '/wc/store/v1/products/2', $object_linked )->get_data();
security_assert( array( array( 'id' => 2 ) ) === $filtered['variations'], 'Object variation entries are checked by their real IDs without mutation' );
assert_private_absent( new WP_REST_Response( $filtered ) );
$checkout = (object) array(
	'__experimentalCart' => (object) array(
		'items'  => array( (object) $private, (object) $public ),
		'totals' => (object) array( 'total_price' => '1000' ),
	),
);
$response = product_response( '/wc/store/v1/checkout', $checkout );
security_assert( $response instanceof WP_Error && 403 === $response->data['status'], 'Nested object checkout refuses restricted cart items' );
assert_private_absent( $response );
foreach ( array( '/wc/store/v1/cart/add-item', '/wc/store/v1/cart', '/wc/store/v1/checkout' ) as $route ) {
	$cart     = array(
		'items'       => array( (object) $public ),
		'cross_sells' => array( (object) $private, (object) $public ),
		'totals'      => array( 'total_price' => '1000' ),
	);
	$body     = false !== strpos( $route, 'checkout' ) ? array( '__experimentalCart' => (object) $cart ) : $cart;
	$response = product_response( $route, $body );
	security_assert( $response instanceof WP_REST_Response, 'Restricted recommendations do not reject a public cart: ' . $route );
	$filtered      = $response->get_data();
	$filtered_cart = $filtered['__experimentalCart'] ?? $filtered;
	security_assert( array( $public ) === $filtered_cart['items'] && $cart['totals'] === $filtered_cart['totals'], 'Public cart items and totals remain intact' );
	security_assert( array( $public ) === $filtered_cart['cross_sells'], 'Restricted cross-sells disappear silently' );
	assert_private_absent( $response );
	$cart['items'][] = (object) $private;
	$body            = false !== strpos( $route, 'checkout' ) ? array( '__experimentalCart' => (object) $cart ) : $cart;
	security_assert( product_response( $route, $body ) instanceof WP_Error, 'Restricted cart items still reject a cart which also contains recommendations' );
}
$GLOBALS['authorized'] = true;
security_assert( $private === product_response( '/wc/store/v1/products/1', $private )->get_data(), 'Authorized readers retain full data' );
security_assert( $variation === product_response( '/wc/v3/products/1/variations/3', $variation )->get_data(), 'Authorized readers retain variations' );

$authorized_checkout = product_response( '/wc/store/v1/checkout', $checkout );
security_assert( $authorized_checkout instanceof WP_REST_Response && json_decode( wp_json_encode( $checkout ), true ) === $authorized_checkout->get_data(), 'Authorized checkout retains every nested product field' );
