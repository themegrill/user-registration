<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Regression: price-driving keys in the browser `members_data` never survive into the registration data.
 *
 * `team_id` reaching `PaymentService::get_payment_data()` loads any team post's pricing and charges that
 * instead of the membership price, so the registration handler must strip it (and the upgrade price keys).
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

require __DIR__ . '/bootstrap.php';

$admin_file = 'modules/membership/includes/Admin.php';
eval(
	'class ClientPriceFieldsStripper {'
	. security_function( $admin_file, 'remove_client_price_fields' )
	. 'public function run( $data ) { return $this->remove_client_price_fields( $data ); } }'
);

$stripper = new ClientPriceFieldsStripper();
$tampered = array(
	'membership'                   => '5329',
	'payment_method'               => 'stripe',
	'upgrade'                      => true,
	'chargeable_amount'            => '0.01',
	'remaining_subscription_value' => '99',
	'team_id'                      => 13239,
);
$clean    = $stripper->run( $tampered );

foreach ( array( 'upgrade', 'chargeable_amount', 'remaining_subscription_value', 'team_id' ) as $key ) {
	security_assert( ! array_key_exists( $key, $clean ), "Client-supplied {$key} must not survive" );
}
security_assert( '5329' === $clean['membership'] && 'stripe' === $clean['payment_method'], 'Unrelated keys are kept' );
security_assert( array() === $stripper->run( array() ), 'Empty data stays empty' );

// The registration handler must actually route members_data through the stripper.
$handler = security_function( $admin_file, 'process_membership_after_registration' );
security_assert( false !== strpos( $handler, '$data = $this->remove_client_price_fields( $data );' ), 'The handler must strip client price fields' );
security_assert( strpos( $handler, 'remove_client_price_fields' ) < strpos( $handler, "'country' === \$field_data" ), 'Stripping happens before any later use of $data' );
