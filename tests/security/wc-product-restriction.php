<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WP_Post test double shares this fixture.
/**
 * Isolated regression: content rules must decide WooCommerce product visibility and purchase.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.Files.FileName.InvalidClassFileName, WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_read_fwrite, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

require __DIR__ . '/bootstrap.php';

class WP_Post {
	public $ID;
	public $post_type   = 'product';
	public $post_parent = 0;
	public function __construct( $id, $post_type = 'product', $post_parent = 0 ) {
		$this->ID          = $id;
		$this->post_type   = $post_type;
		$this->post_parent = $post_parent; }
}
function is_super_admin() {
	return false; }
function get_post( $id ) {
	if ( 756 === $id ) {
		return new WP_Post( 756, 'product_variation', 755 ); }
	if ( 757 === $id ) {
		return new WP_Post( 757, 'product_variation', 0 ); }
	if ( 0 === $id ) {
		return new WP_Post( 755 ); }
	return 755 === $id ? new WP_Post( $id ) : null; }
function is_singular( $type ) {
	return false; }
function apply_filters( $hook, $value ) {
	return $value; }
function wp_list_pluck( $list, $field ) {
	return array_column( $list, $field ); }
function urcr_is_allow_access( $logic_map, $target_post ) {
	return $logic_map['conditions'][0]['matches'] ?? $GLOBALS['user_matches_rule']; }

$core = 'modules/content-restriction/functions-urcr-core.php';
eval( security_function( $core, 'urcr_is_access_rule_enabled' ) );
eval( security_function( $core, 'urcr_is_action_specified' ) );
eval( security_function( $core, 'urcr_is_target_post' ) );

function wp_json_encode( $value ) {
	return json_encode( $value ); }
function urcr_get_published_access_rules() {
	return array_map(
		function ( $rule ) {
			return (object) array( 'post_content' => wp_json_encode( $rule ) ); },
		$GLOBALS['access_rules']
	); }
function get_post_type( $id ) {
	$post = get_post( $id );
	return $post ? $post->post_type : false; }
function wp_get_post_parent_id( $id ) {
	$post = get_post( $id );
	return $post ? $post->post_parent : 0; }
function get_post_meta( $id, $key, $single ) {
	return ''; }
function ur_string_to_bool( $value ) {
	return true === $value || 'yes' === $value || 'on' === $value || 1 === $value; }
$frontend = 'modules/content-restriction/class-urcr-frontend.php';
eval(
	'class ProductRestrictionRunner { public '
	. security_function( $frontend, 'wc_advanced_restriction_with_access_rule' )
	. ' private '
	. security_function( $frontend, 'get_rule_product_id' )
	. ' public '
	. security_function( $frontend, 'ur_user_can_purchase_woocommerce_product' )
	. ' }'
);

function product_rule( $target_type, $enabled = true, $control = 'access', $value = array( 'product' ), $matches = null ) {
	return array(
		'enabled'         => $enabled,
		'logic_map'       => array(
			'type'       => 'group',
			'conditions' => array(
				null === $matches ? array( 'type' => 'membership' ) : array(
					'type'    => 'membership',
					'matches' => $matches,
				),
			),
		),
		'target_contents' => array(
			array_filter(
				array(
					'type'  => $target_type,
					'value' => $value,
				)
			),
		),
		'actions'         => array(
			array(
				'type'           => 'message',
				'access_control' => $control,
			),
		),
	);
}
function product_allowed( $rules, $user_matches, $product_id = 755 ) {
	$GLOBALS['user_matches_rule'] = $user_matches;
	$GLOBALS['access_rules']      = $rules;
	$runner                       = new ProductRestrictionRunner();
	return $runner->ur_user_can_purchase_woocommerce_product( $product_id );
}

$post_type_rule = product_rule( 'post_types' );
$whole_site     = product_rule( 'whole_site', true, 'access', array( 'x' ) );

try {
	security_assert( false === product_allowed( array( $post_type_rule ), false ), 'Post Type rule must restrict a non-member on a product (bare ID never matched)' );
	security_assert( true === product_allowed( array( $post_type_rule ), true ), 'Post Type rule must let a member through' );
	security_assert( true === product_allowed( array(), false ), 'No rules leaves the product open' );
	security_assert( true === product_allowed( array( product_rule( 'post_types', false ) ), false ), 'Disabled product rule has no effect' );
	security_assert( true === product_allowed( array( product_rule( 'post_types', true, 'access', array( 'page' ) ) ), false ), 'Rule for another post type does not match a product' );
	security_assert( false === product_allowed( array( $post_type_rule, $whole_site ), false ), 'Enabled Whole Site rule must not switch product rules off' );
	security_assert( false === product_allowed( array( $post_type_rule, array_merge( $whole_site, array( 'enabled' => false ) ) ), false ), 'Disabled Whole Site rule must not switch product rules off' );
	security_assert( true === product_allowed( array( $whole_site ), false ), 'A Whole Site rule alone never restricts product purchase here' );
	security_assert( true === product_allowed( array( product_rule( 'post_types', true, 'restrict' ) ), false ), 'Restrict rule leaves non-matching users alone' );
	security_assert( false === product_allowed( array( product_rule( 'post_types', true, 'restrict' ) ), true ), 'Restrict rule blocks matching users' );
	security_assert( true === product_allowed( array( product_rule( 'post_types', true, 'restrict' ), $post_type_rule ), true ), 'A rule that grants access wins over one that restricts' );
	security_assert( false === product_allowed( array( $post_type_rule ), false, 756 ), 'A variation of a restricted product must be restricted through its parent' );
	security_assert( true === product_allowed( array( $post_type_rule ), true, 756 ), 'A member can still buy a variation of a restricted product' );
	security_assert( true === product_allowed( array( $post_type_rule ), false, 999 ), 'A missing product is left open' );
	$access_rule_editor   = product_rule( 'post_types', true, 'access', array( 'product' ), false );
	$restrict_rule_subscr = product_rule( 'post_types', true, 'restrict', array( 'product' ), false );
	security_assert( false === product_allowed( array( $access_rule_editor, $restrict_rule_subscr ), false ), 'An unmatched Restrict rule must not cancel an Access rule that blocks the user' );
	security_assert( false === product_allowed( array( $restrict_rule_subscr, $access_rule_editor ), false ), 'Rule order must not change the outcome' );
	security_assert( false === product_allowed( array( $restrict_rule_subscr, product_rule( 'post_types', true, 'restrict', array( 'product' ), true ) ), false ), 'An unmatched Restrict rule must not cancel a matching Restrict rule' );
	security_assert( true === product_allowed( array( product_rule( 'post_types', true, 'access', array( 'product' ), true ), product_rule( 'post_types', true, 'restrict', array( 'product' ), true ) ), false ), 'A matching Access rule still wins over a matching Restrict rule' );
	security_assert( true === product_allowed( array( $post_type_rule ), false, 757 ), 'A variation without a parent is left open instead of falling back to the current post' );
} catch ( Throwable $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}
