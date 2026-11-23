<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: the AI revenue summary counts completed payments as revenue, lists every other status on its own so
 * failed and refunded payments are never mixed in, groups by plan with the biggest first, and rejects bad or
 * very long date ranges. It answers with the exact shape its schema promises.
 *
 * Runs the real `UR_Abilities_Data::get_revenue_summary()` with a stand-in for the orders table; no live site or
 * database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/abilities-activity-stubs.php';

class WP_Error {
	public $code;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}
}

eval(
	'namespace WPEverest\URMembership\Admin\Repositories;
	class MembersRepository {}
	class OrdersRepository {
		public function get_summary( $args ) {
			$GLOBALS["summary_args"] = $args;
			return $GLOBALS["summary_rows"];
		}
	}'
);

$modules      = true;
$summary_rows = array();
$filters      = array();

function ur_check_module_activation() {
	return $GLOBALS['modules'];
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function get_the_title( $id ) {
	return array(
		12 => 'Paid Membership',
		27 => 'Free',
		37 => '<b>3 years</b>',
	)[ $id ] ?? '';
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-data.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities.php';

function revenue_for( $input, $rows = array() ) {
	$GLOBALS['summary_rows'] = $rows;
	return UR_Abilities_Data::get_revenue_summary( $input );
}

function summary_row( $item_id, $status, $orders, $amount ) {
	return array(
		'item_id' => $item_id,
		'status'  => $status,
		'orders'  => $orders,
		'amount'  => $amount,
	);
}

$rows = array(
	summary_row( 12, 'completed', 20, '400.00000000' ),
	summary_row( 37, 'completed', 3, '1191.00000000' ),
	summary_row( 27, 'completed', 5, '0.00000000' ),
	summary_row( 12, 'pending', 10, '200.00000000' ),
	summary_row( 37, 'failed', 2, '794.00000000' ),
	summary_row( 12, 'refunded', 1, '20.00000000' ),
);

$result = revenue_for(
	array(
		'date_from' => '2026-11-01',
		'date_to'   => '2026-11-23',
	),
	$rows
);

security_assert( '1591.00' === $result['revenue'] && 41 === $result['orders'], 'Revenue is the completed payments only, and the order count includes every status' );
security_assert( 'USD' === $result['currency'] && '2026-11-01' === $result['date_from'] && '2026-11-23' === $result['date_to'], 'The answer names the currency and the range it covers' );

$by_status = array_column( $result['by_status'], null, 'status' );
security_assert( array( 'completed', 'failed', 'pending', 'refunded' ) === array_keys( $by_status ), 'Every status is listed on its own, in a steady order' );
security_assert( '200.00' === $by_status['pending']['amount'] && 10 === $by_status['pending']['orders'], 'Pending payments are counted but not added to revenue' );
security_assert( '794.00' === $by_status['failed']['amount'] && '20.00' === $by_status['refunded']['amount'], 'Failed and refunded payments stay out of revenue' );
security_assert( '1591.00' === $by_status['completed']['amount'] && 28 === $by_status['completed']['orders'], 'The completed line matches the revenue' );

security_assert( array( 37, 12, 27 ) === array_column( $result['by_plan'], 'membership_id' ), 'Plans are listed with the biggest revenue first' );
security_assert( '3 years' === $result['by_plan'][0]['membership_name'] && '1191.00' === $result['by_plan'][0]['revenue'], 'A plan name has its markup removed and its revenue is a two-decimal string' );
security_assert( 3 === count( $result['by_plan'] ), 'Each plan is listed once, whatever the statuses of its orders' );
security_assert( 5 === $result['by_plan'][0]['orders'] && 31 === $result['by_plan'][1]['orders'] && '400.00' === $result['by_plan'][1]['revenue'], 'A plan counts its orders of every status, and only its completed payments as revenue' );

// The dates and the plan filter reach the orders table, and an empty range gives zeros.
security_assert(
	array(
		'date_from' => '2026-11-01',
		'date_to'   => '2026-11-23',
	) === $GLOBALS['summary_args'],
	'Without a plan filter none is sent'
);
revenue_for( array( 'membership_id' => 12 ) );
security_assert( 12 === $GLOBALS['summary_args']['membership_id'], 'The plan filter reaches the orders table' );
$empty = revenue_for( array() );
security_assert( '0.00' === $empty['revenue'] && 0 === $empty['orders'] && array() === $empty['by_status'] && array() === $empty['by_plan'], 'A range with no payments answers with zeros and empty lists' );
security_assert( gmdate( 'Y-m-d' ) === $empty['date_to'] && gmdate( 'Y-m-d', strtotime( '-29 days' ) ) === $empty['date_from'], 'Without dates the last 30 days are used' );

// Bad ranges are refused with a client error.
$bad_ranges = array(
	array(
		'date_from' => '2026-11-23',
		'date_to'   => '2026-11-01',
	),
	array(
		'date_from' => '2026-02-30',
		'date_to'   => '2026-03-02',
	),
	array(
		'date_from' => '2025-01-01',
		'date_to'   => '2026-11-23',
	),
);
foreach ( $bad_ranges as $bad ) {
	$error = revenue_for( $bad );
	security_assert( is_wp_error( $error ) && 'ur_abilities_date_range' === $error->code && 400 === $error->data['status'], 'A backwards, impossible or longer than 366 day range is a 400' );
}
$longest = revenue_for(
	array(
		'date_from' => '2025-11-23',
		'date_to'   => '2026-11-23',
	)
);
security_assert( ! is_wp_error( $longest ), 'A range of exactly one year is allowed' );

// The module must be on.
$GLOBALS['modules'] = false;
$off                = revenue_for( array() );
security_assert( is_wp_error( $off ) && 'ur_abilities_membership_disabled' === $off->code && 403 === $off->data['status'], 'Without the membership module the answer is a 403' );
$GLOBALS['modules'] = true;

// The answer has exactly the shape the ability promises.
$ability = null;
foreach ( UR_Abilities::get_areas() as $area ) {
	$ability = $area['abilities']['get-revenue-summary'] ?? $ability;
}
$schema  = $ability['output']['properties'];
security_assert( array_keys( $result ) === $ability['output']['required'], 'The answer has the fields its schema promises, no more and no fewer' );
foreach ( array(
	'by_status' => $result['by_status'][0],
	'by_plan'   => $result['by_plan'][0],
) as $key => $line ) {
	$have = array_keys( $line );
	$want = array_keys( $schema[ $key ]['items']['properties'] );
	sort( $have );
	sort( $want );
	security_assert( $have === $want, 'A ' . $key . ' line has exactly the promised fields' );
}
security_assert( 'read' === UR_Abilities::level( $ability ), 'The summary is a read ability' );
