<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Multiple WordPress test doubles share this fixture.
/**
 * Regression: ur_get_ip_address() must not trust client-supplied forwarding headers.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

require __DIR__ . '/bootstrap.php';
function rest_is_ip_address( $ip ) {
	return filter_var( $ip, FILTER_VALIDATE_IP ); }
function apply_filters( $hook, $value ) {
	return 'user_registration_trusted_proxies' === $hook ? $GLOBALS['trusted'] : $value; }
eval( security_function( 'includes/functions-ur-core.php', 'ur_get_ip_address' ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

function ip_case( $name, $server, $trusted, $expected ) {
	$_SERVER            = $server;
	$GLOBALS['trusted'] = $trusted;
	security_assert( ur_get_ip_address() === $expected, $name . ' (got "' . ur_get_ip_address() . '")' );
}

$peer = '203.0.113.9';
$lb   = '10.0.0.1';

// Untrusted peer: every forged header is ignored.
$forged = array(
	'REMOTE_ADDR'          => $peer,
	'HTTP_X_REAL_IP'       => '1.2.3.4',
	'HTTP_X_FORWARDED_FOR' => '5.6.7.8, 10.0.0.1',
);
ip_case( 'no trusted proxy configured', $forged, array(), $peer );
ip_case( 'peer not on the allow-list', $forged, array( $lb ), $peer );
ip_case( 'non-string filter entries are ignored', $forged, array( null, 42, array( $peer ) ), $peer );

// Trusted peer: rightmost untrusted hop wins, a forged leftmost entry never does.
$via_lb = array(
	'REMOTE_ADDR'          => $lb,
	'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
);
ip_case( 'client-forged leftmost XFF entry is ignored', $via_lb, array( $lb ), '198.51.100.7' );
$via_lb['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 198.51.100.7, 10.0.0.2';
ip_case( 'skips every trusted hop from the right', $via_lb, array( $lb, '10.0.0.2' ), '198.51.100.7' );
ip_case( 'port is stripped from an IPv4 hop', array_merge( $via_lb, array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.7:5050' ) ), array( $lb ), '198.51.100.7' );
ip_case( 'bracketed IPv6 with port', array_merge( $via_lb, array( 'HTTP_X_FORWARDED_FOR' => '[2001:db8::1]:443' ) ), array( $lb ), '2001:db8::1' );
ip_case( 'bare IPv6 hop', array_merge( $via_lb, array( 'HTTP_X_FORWARDED_FOR' => '2001:db8::1' ) ), array( $lb ), '2001:db8::1' );
ip_case(
	'IPv6 peer matches an equivalent spelling',
	array_merge(
		$via_lb,
		array(
			'REMOTE_ADDR'          => '::1',
			'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
		)
	),
	array( '0:0:0:0:0:0:0:1' ),
	'198.51.100.7'
);
ip_case(
	'IPv4-mapped IPv6 peer matches a plain IPv4 entry',
	array(
		'REMOTE_ADDR'          => '::ffff:10.0.0.1',
		'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
	),
	array( $lb ),
	'198.51.100.7'
);
ip_case(
	'IPv4-mapped IPv6 hop is skipped as a trusted proxy',
	array(
		'REMOTE_ADDR'          => $lb,
		'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7, ::ffff:10.0.0.2',
	),
	array( $lb, '10.0.0.2' ),
	'198.51.100.7'
);
ip_case( 'a mapped address does not make an untrusted peer trusted', array_merge( $via_lb, array( 'REMOTE_ADDR' => '::ffff:10.0.0.9' ) ), array( $lb ), '::ffff:10.0.0.9' );

// Trusted peer, bad or missing data: fall back safely.
ip_case( 'garbage XFF falls back to REMOTE_ADDR', array_merge( $via_lb, array( 'HTTP_X_FORWARDED_FOR' => 'unknown' ) ), array( $lb ), $lb );
ip_case( 'empty XFF falls back to REMOTE_ADDR', array_merge( $via_lb, array( 'HTTP_X_FORWARDED_FOR' => '' ) ), array( $lb ), $lb );
ip_case( 'invalid hop stops the walk', array_merge( $via_lb, array( 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, bogus' ) ), array( $lb ), $lb );
ip_case( 'no forwarding headers', array( 'REMOTE_ADDR' => $lb ), array( $lb ), $lb );
ip_case(
	'X-Real-IP used when XFF is absent',
	array(
		'REMOTE_ADDR'    => $lb,
		'HTTP_X_REAL_IP' => '198.51.100.7',
	),
	array( $lb ),
	'198.51.100.7'
);
ip_case(
	'invalid X-Real-IP is rejected',
	array(
		'REMOTE_ADDR'    => $lb,
		'HTTP_X_REAL_IP' => '<script>x</script>',
	),
	array( $lb ),
	$lb
);
ip_case(
	'X-Forwarded-For wins over a client-supplied X-Real-IP',
	array_merge(
		$via_lb,
		array(
			'HTTP_X_REAL_IP'       => '1.2.3.4',
			'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
		)
	),
	array( $lb ),
	'198.51.100.7'
);

// REMOTE_ADDR itself.
ip_case( 'missing REMOTE_ADDR', array(), array(), '' );
ip_case( 'invalid REMOTE_ADDR', array( 'REMOTE_ADDR' => 'not-an-ip' ), array(), '' );

// CIDR ranges (IPv4, IPv6, IPv4-mapped IPv6) and malformed CIDRs.
ip_case(
	'IPv4 CIDR matches proxy peer',
	array(
		'REMOTE_ADDR'          => '173.245.55.10',
		'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
	),
	array( '173.245.48.0/20' ),
	'198.51.100.7'
);
ip_case(
	'IPv4 CIDR does not match peer outside subnet',
	array(
		'REMOTE_ADDR'          => '173.245.64.1',
		'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
	),
	array( '173.245.48.0/20' ),
	'173.245.64.1'
);
ip_case(
	'IPv6 CIDR matches proxy peer',
	array(
		'REMOTE_ADDR'          => '2400:cb00:2048:1::c629:d7a2',
		'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
	),
	array( '2400:cb00::/32' ),
	'198.51.100.7'
);
ip_case(
	'IPv6 CIDR does not match peer outside subnet',
	array(
		'REMOTE_ADDR'          => '2400:cb01::1',
		'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
	),
	array( '2400:cb00::/32' ),
	'2400:cb01::1'
);
ip_case(
	'IPv4-mapped IPv6 peer matches IPv4 CIDR',
	array(
		'REMOTE_ADDR'          => '::ffff:10.0.0.5',
		'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
	),
	array( '10.0.0.0/24' ),
	'198.51.100.7'
);
ip_case(
	'trusted CIDR hop in XFF is skipped',
	array(
		'REMOTE_ADDR'          => '10.0.0.1',
		'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7, 10.25.3.4',
	),
	array( '10.0.0.0/8' ),
	'198.51.100.7'
);
ip_case(
	'IPv4 CIDR does not match IPv6 peer',
	array(
		'REMOTE_ADDR'          => '2001:db8::1',
		'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
	),
	array( '0.0.0.0/0' ),
	'2001:db8::1'
);
ip_case(
	'malformed CIDR prefix > 32 is ignored',
	$forged,
	array( '203.0.113.0/33' ),
	$peer
);
ip_case(
	'malformed CIDR negative prefix is ignored',
	$forged,
	array( '203.0.113.0/-1' ),
	$peer
);
ip_case(
	'malformed CIDR non-numeric prefix is ignored',
	$forged,
	array( '203.0.113.0/abc' ),
	$peer
);
ip_case(
	'malformed CIDR extra slash is ignored',
	$forged,
	array( '203.0.113.0/24/16' ),
	$peer
);
ip_case(
	'malformed IPv6 CIDR prefix > 128 is ignored',
	array(
		'REMOTE_ADDR'          => '2400:cb00::1',
		'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
	),
	array( '2400:cb00::/129' ),
	'2400:cb00::1'
);
ip_case(
	'all-trusted XFF hops fall back to REMOTE_ADDR instead of X-Real-IP',
	array(
		'REMOTE_ADDR'          => $lb,
		'HTTP_X_FORWARDED_FOR' => '10.0.0.2, 10.0.0.3',
		'HTTP_X_REAL_IP'       => '1.2.3.4',
	),
	array( '10.0.0.0/24' ),
	$lb
);
