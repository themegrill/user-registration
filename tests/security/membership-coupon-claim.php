<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Isolated regression: a coupon's usage limit holds on every membership checkout path.
 *
 * Runs the real `CouponService::claim_for_checkout()`, `OrdersRepository::keep_coupon_claim()`,
 * `settle_coupon_claim()` and `delete()`, plus the real claim/release counters; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.BraceOnNewLine, PEAR.NamingConventions.ValidClassName.Invalid

namespace {
	require __DIR__ . '/bootstrap.php';

	const COUPON_CODE = 'SAVE10';

	$GLOBALS['coupon']        = array(
		'count' => 0,
		'limit' => 1,
	);
	$GLOBALS['module_active'] = true;
	$GLOBALS['coupon_valid']  = true;
	$GLOBALS['order_meta']    = array();
	$GLOBALS['meta_insert_ok'] = true;
	$GLOBALS['deleted_orders'] = array();

	function ur_check_module_activation( $module ) {
		return 'coupon' === $module ? $GLOBALS['module_active'] : true; }
	function ur_get_coupon_details( $code ) {
		return COUPON_CODE === $code ? array( 'coupon_id' => 1 ) : array(); }
	function ur_coupon_has_remaining_uses( $details ) {
		return $GLOBALS['coupon']['count'] < $GLOBALS['coupon']['limit']; }
	function ur_change_coupon_usage( $code, $delta, $enforce_limit = false ) {
		if ( COUPON_CODE !== $code || ( $enforce_limit && ! ur_coupon_has_remaining_uses( array() ) ) ) {
			return false; }
		$GLOBALS['coupon']['count'] = max( 0, $GLOBALS['coupon']['count'] + $delta );
		return true; }

	// The real claim/release counters, not doubles.
	foreach ( array( 'ur_claim_coupon_usage', 'ur_release_coupon_usage', 'ur_coupon_claimed_this_request' ) as $counter_function ) {
		eval( security_function( 'includes/functions-ur-core.php', $counter_function ) );
	}
}

namespace WPEverest\URMembership\Admin\Repositories {
	class BaseRepository {
		public function delete( $id ) {
			$GLOBALS['deleted_orders'][] = $id;
			return 1; }
	}
}

namespace {
	eval(
		'namespace WPEverest\URMembership\Admin\Services;'
		. 'class CouponRunner { '
		. 'public function validate( $data ) { return array( "status" => $GLOBALS["coupon_valid"] && ur_coupon_has_remaining_uses( array() ) ); } public '
		. security_function( 'modules/membership/includes/Admin/Services/CouponService.php', 'claim_for_checkout' )
		. ' }'
	);

	eval(
		'namespace WPEverest\URMembership\Admin\Repositories;'
		. 'class OrdersRunner extends BaseRepository { '
		. 'public function get_order_meta_by_order_id_and_meta_key( $order_id, $key ) { return isset( $GLOBALS["order_meta"][ $order_id ] ) ? array( "meta_value" => $GLOBALS["order_meta"][ $order_id ] ) : array(); } '
		. 'public function delete_order_meta( $conditions ) { unset( $GLOBALS["order_meta"][ $conditions["order_id"] ] ); return true; } '
		. 'public function update_order_meta( $meta ) { if ( ! $GLOBALS["meta_insert_ok"] ) { return false; } $GLOBALS["order_meta"][ $meta["order_id"] ] = $meta["meta_value"]; return 1; } public '
		. security_function( 'modules/membership/includes/Admin/Repositories/OrdersRepository.php', 'settle_coupon_claim' ) . ' public '
		. security_function( 'modules/membership/includes/Admin/Repositories/OrdersRepository.php', 'keep_coupon_claim' ) . ' public '
		. security_function( 'modules/membership/includes/Admin/Repositories/OrdersRepository.php', 'delete' )
		. ' }'
	);

	function coupon_state( $count, $limit = 1 ) {
		$GLOBALS['coupon']        = array(
			'count' => $count,
			'limit' => $limit,
		);
		$GLOBALS['module_active'] = true;
		$GLOBALS['coupon_valid']  = true;
		$GLOBALS['order_meta']    = array();
		$GLOBALS['meta_insert_ok'] = true;
		$GLOBALS['deleted_orders'] = array();
	}
	function claim( $coupon = COUPON_CODE ) {
		return ( new \WPEverest\URMembership\Admin\Services\CouponRunner() )->claim_for_checkout(
			array(
				'coupon'        => $coupon,
				'membership_id' => 5,
			)
		); }
	function orders() {
		return new \WPEverest\URMembership\Admin\Repositories\OrdersRunner(); }
	function order_row( $id, $status ) {
		return array(
			'ID'     => $id,
			'status' => $status,
		); }
	function uses() {
		return $GLOBALS['coupon']['count']; }

	// claim_for_checkout(): shared by registration, extra-plan purchase and (via the same helpers) upgrade.
	coupon_state( 0 );
	security_assert( '' === claim( '' ) && 0 === uses(), 'No coupon means no claim' );

	coupon_state( 0 );
	$GLOBALS['module_active'] = false;
	security_assert( '' === claim() && 0 === uses(), 'A coupon is ignored while the coupon module is off' );

	coupon_state( 0 );
	security_assert( COUPON_CODE === claim() && 1 === uses(), 'A valid coupon with uses left is claimed and counted' );

	coupon_state( 1 );
	security_assert( false === claim() && 1 === uses(), 'A used-up coupon is refused, not silently charged at full price' );

	coupon_state( 0 );
	$GLOBALS['coupon_valid'] = false;
	security_assert( '' === claim() && 0 === uses(), 'A coupon that does not apply for another reason is dropped without a claim' );

	coupon_state( 0 );
	$GLOBALS['coupon']['limit'] = 1;
	$first                      = claim();
	$second                     = claim();
	security_assert( COUPON_CODE === $first && false === $second && 1 === uses(), 'A second purchase cannot claim past a limit of one' );

	// keep_coupon_claim(): ties the use to the order or gives it back.
	coupon_state( 1 );
	security_assert( false === orders()->keep_coupon_claim( COUPON_CODE, false ) && 0 === uses(), 'A purchase whose order was never created gives the use back' );

	coupon_state( 1 );
	security_assert( true === orders()->keep_coupon_claim( COUPON_CODE, order_row( 7, 'pending' ) ) && COUPON_CODE === $GLOBALS['order_meta'][7] && 1 === uses(), 'A pending order stores the claim marker and keeps the use' );

	coupon_state( 1 );
	security_assert( true === orders()->keep_coupon_claim( COUPON_CODE, order_row( 8, 'completed' ) ) && empty( $GLOBALS['order_meta'] ) && 1 === uses(), 'A completed order keeps the use without a marker' );

	coupon_state( 1 );
	$GLOBALS['meta_insert_ok'] = false;
	security_assert( false === orders()->keep_coupon_claim( COUPON_CODE, order_row( 9, 'pending' ) ) && 0 === uses(), 'A marker that cannot be saved gives the use back' );

	// delete(): cancelling a scheduled upgrade deletes its unfinished order.
	coupon_state( 1 );
	$GLOBALS['order_meta'][12] = COUPON_CODE;
	orders()->delete( 12 );
	security_assert( 0 === uses() && array( 12 ) === $GLOBALS['deleted_orders'] && empty( $GLOBALS['order_meta'] ), 'Deleting an unfinished order returns its coupon use' );

	coupon_state( 1 );
	orders()->delete( 13 );
	security_assert( 1 === uses() && array( 13 ) === $GLOBALS['deleted_orders'], 'Deleting an order that holds no claim leaves the count alone' );

	coupon_state( 2, 5 );
	$GLOBALS['order_meta'][14] = COUPON_CODE;
	orders()->delete( 14 );
	orders()->delete( 14 );
	security_assert( 1 === uses(), 'Deleting the same order twice returns the use once' );

	// Wiring: the handlers must keep going through the shared claim helpers.
	$handler = security_function( 'modules/membership/includes/AJAX.php', 'add_multiple_membership' );
	security_assert( false !== strpos( $handler, 'claim_for_checkout' ) && false !== strpos( $handler, 'keep_coupon_claim' ), 'Buying an extra plan claims the coupon use and ties it to the order' );
	$handler = security_function( 'modules/membership/includes/AJAX.php', 'cancel_upcoming_subscription' );
	security_assert( false !== strpos( $handler, '$order_repository->delete(' ), 'Cancelling a scheduled upgrade deletes through the repository that settles the claim' );
	$handler = security_function( 'modules/membership/includes/Admin/Services/SubscriptionService.php', 'upgrade_membership_under_lock' );
	security_assert( false !== strpos( $handler, 'keep_coupon_claim' ), 'The upgrade checkout ties its coupon claim to the order' );
}
