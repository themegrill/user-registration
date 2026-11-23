<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: an AI ability that fails for a reason the caller can fix (unknown record, Membership turned off,
 * a result too large, a bad date range) must answer with a client-error status. WordPress turns an error with
 * no status into HTTP 500, which reads as a server fault.
 *
 * Runs the real error helpers of `UR_Abilities_Data` and checks every `WP_Error` in the file; no live site
 * or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.PHP.Eval.Discouraged

require __DIR__ . '/bootstrap.php';

class WP_Error {
	public $code;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function status() {
		return is_array( $this->data ) && isset( $this->data['status'] ) ? $this->data['status'] : 500;
	}
}

$functions = '';
foreach ( array( 'membership_unavailable', 'not_found', 'date_range_error' ) as $name ) {
	$functions .= 'public static ' . security_function( 'includes/class-ur-abilities-data.php', $name ) . "\n";
}
eval( 'class AbilitiesErrorRunner { ' . $functions . ' }' );

security_assert( 404 === AbilitiesErrorRunner::not_found()->status(), 'An unknown record answers 404' );
security_assert( 403 === AbilitiesErrorRunner::membership_unavailable()->status(), 'Membership turned off answers 403' );
security_assert( 400 === AbilitiesErrorRunner::date_range_error( array( 'from' => '2026-11-05', 'to' => '2026-10-01' ), 'from', 'to' )->status(), 'A start date after the end date answers 400' );
security_assert( 400 === AbilitiesErrorRunner::date_range_error( array( 'from' => 'not-a-date' ), 'from', 'to' )->status(), 'An invalid date answers 400' );
security_assert( null === AbilitiesErrorRunner::date_range_error( array( 'from' => '2026-10-01', 'to' => '2026-11-05' ), 'from', 'to' ), 'A valid date range is accepted' );

// Errors raised inside larger functions (result limits, date window): every WP_Error in the file names a status.
$tokens = token_get_all( file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-data.php' ) );
$total  = count( $tokens );
$errors = 0;
for ( $i = 0; $i < $total; ++$i ) {
	if ( ! is_array( $tokens[ $i ] ) || T_NEW !== $tokens[ $i ][0] ) {
		continue;
	}
	$j = $i + 1;
	while ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
		++$j;
	}
	if ( ! is_array( $tokens[ $j ] ) || 'WP_Error' !== $tokens[ $j ][1] ) {
		continue;
	}
	$call  = '';
	$depth = 0;
	for ( $k = $j; $k < $total; ++$k ) {
		$call .= is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
		if ( '(' === $tokens[ $k ] ) {
			++$depth;
		}
		if ( ')' === $tokens[ $k ] && 0 === --$depth ) {
			break;
		}
	}
	++$errors;
	security_assert( false !== strpos( $call, "'status'" ), 'Every ability error names an HTTP status: ' . substr( $call, 0, 60 ) );
}
security_assert( $errors >= 7, 'The status check saw every ability error' );
