<?php
/**
 * Isolated security regression fixtures; no live site or database is loaded.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and hostile fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.PHP.Eval.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, WordPress.WP.GlobalVariablesOverride.Prohibited

require __DIR__ . '/bootstrap.php';

function wp_generate_password( $length = 32, $special_chars = true ) {
	return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
}
function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0700, true );
}
function wp_is_writable( $path ) {
	return is_writable( $path );
}
function trailingslashit( $path ) {
	return rtrim( $path, '/\\' ) . '/';
}
function apply_filters( $hook, $value ) {
	return $value;
}
function ur_get_logger() {
	return new class() {
		public function warning( $message, $context = array() ) {
			$GLOBALS['ur_warnings'][] = $message;
		}
	};
}
function ur_maybe_unserialize( $data ) {
	if ( ! is_string( $data ) ) {
		return $data;
	}
	$unserialized = @unserialize( $data, array( 'allowed_classes' => false ) );
	return false === $unserialized ? $data : $unserialized;
}
function wp_unique_filename( $dir, $filename ) {
	return $filename;
}
function sanitize_file_name( $filename ) {
	return basename( $filename );
}
function wp_check_filetype( $filename, $mimes = null ) {
	return array(
		'ext'  => 'png',
		'type' => 'image/png',
	);
}
function wp_insert_attachment( $args, $file ) {
	$GLOBALS['attachments'][] = $file;
	return 101;
}
function is_wp_error( $thing ) {
	return false;
}
function wp_generate_attachment_metadata( $attachment_id, $file ) {
	return array( 'file' => basename( $file ) );
}
function wp_update_attachment_metadata( $attachment_id, $data ) {
	return true;
}
function update_user_meta( $user_id, $meta_key, $meta_value ) {
	$GLOBALS['user_meta'][ $user_id ][ $meta_key ] = $meta_value;
	return true;
}

$root = sys_get_temp_dir() . '/ur-profile-pic-token-test-' . bin2hex( random_bytes( 8 ) );
define( 'UR_UPLOAD_PATH', $root . '/' );
$GLOBALS['options']['ur_secret_key'] = 'regression-test-secret-key-32bytes!!';
$GLOBALS['options']['ur_secret_iv']  = 'legacy-static-iv-should-not-be-used';

$source = 'includes/functions-ur-core.php';
eval( security_function( $source, 'ur_generate_random_key' ) );
eval( security_function( $source, 'crypt_the_string' ) );
eval( security_function( $source, 'ur_get_public_upload_directory_rules' ) );
eval( security_function( $source, 'ur_protect_public_upload_directory' ) );
eval( security_function( $source, 'ur_get_tmp_dir' ) );
eval( security_function( $source, 'ur_upload_profile_pic' ) );

try {
	// 1. Random IV per encryption and round-trip integrity.
	$plaintext = 'user-42-multi-block-payload-for-cbc-malleability-check';
	$token_a   = crypt_the_string( $plaintext, 'e' );
	$token_b   = crypt_the_string( $plaintext, 'e' );
	security_assert( is_string( $token_a ) && 0 === strpos( $token_a, 'v2.' ), 'Encrypt emits v2 authenticated token' );
	security_assert( $token_a !== $token_b, 'Identical plaintexts produce distinct ciphertexts (random IV)' );
	security_assert( crypt_the_string( $token_a, 'd' ) === $plaintext, 'Decrypts first token back to plaintext' );
	security_assert( crypt_the_string( $token_b, 'd' ) === $plaintext, 'Decrypts second token back to plaintext' );

	// 2. Tamper detection across IV, MAC, ciphertext, truncation, downgrade, and legacy format.
	$encoded = strtr( substr( $token_a, 3 ), '-_', '+/' );
	$pad     = strlen( $encoded ) % 4;
	if ( $pad > 0 ) {
		$encoded .= str_repeat( '=', 4 - $pad );
	}
	$raw = base64_decode( $encoded, true );

	$flip_byte = function ( $bytes, $index ) {
		$bytes[ $index ] = chr( ord( $bytes[ $index ] ) ^ 0x01 );
		return 'v2.' . rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	};

	security_assert( false === crypt_the_string( $flip_byte( $raw, 0 ), 'd' ), 'Rejects tampered IV' );
	security_assert( false === crypt_the_string( $flip_byte( $raw, 20 ), 'd' ), 'Rejects tampered MAC' );
	security_assert( false === crypt_the_string( $flip_byte( $raw, 48 ), 'd' ), 'Rejects tampered ciphertext' );
	security_assert( false === crypt_the_string( substr( $token_a, 0, -4 ), 'd' ), 'Rejects truncated token' );

	$legacy_key   = hash( 'sha256', $GLOBALS['options']['ur_secret_key'] );
	$legacy_iv    = substr( hash( 'sha256', $GLOBALS['options']['ur_secret_iv'] ), 0, 16 );
	$legacy_token = base64_encode( openssl_encrypt( $plaintext, 'AES-256-CBC', $legacy_key, 0, $legacy_iv ) );
	security_assert( false === crypt_the_string( $legacy_token, 'd' ), 'Rejects unauthenticated legacy fixed-IV ciphertext by default' );
	security_assert( crypt_the_string( $legacy_token, 'd', true ) === $plaintext, 'Allows legacy decryption only when caller explicitly opts in' );
	security_assert( false === crypt_the_string( $flip_byte( $raw, 48 ), 'd', true ), 'Never falls back to legacy for tampered v2 tokens' );

	$downgrade_iv  = random_bytes( 16 );
	$mac_key       = hash_hmac( 'sha256', 'ur-mac-v2', $GLOBALS['options']['ur_secret_key'], true );
	$downgrade_mac = hash_hmac( 'sha256', 'v2m' . $downgrade_iv . $plaintext, $mac_key, true );
	$downgrade_tok = 'v2m.' . rtrim( strtr( base64_encode( $downgrade_iv . $downgrade_mac . $plaintext ), '+/', '-_' ), '=' );
	security_assert( false === crypt_the_string( $downgrade_tok, 'd' ), 'Rejects unencrypted v2m tokens' );

	$saved_key                           = $GLOBALS['options']['ur_secret_key'];
	$GLOBALS['options']['ur_secret_key'] = 'completely-different-secret-key-32!!';
	security_assert( false === crypt_the_string( $token_a, 'd' ), 'Rejects token under wrong secret key' );
	$GLOBALS['options']['ur_secret_key'] = $saved_key;

	// 3. ur_upload_profile_pic() rejects tampered tokens and out-of-directory paths even with a valid key.
	$png     = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
	$tmp_dir = ur_get_tmp_dir();

	wp_mkdir_p( $root . '/outside' );
	wp_mkdir_p( $root . '/temp-uploads-evil' );
	wp_mkdir_p( $tmp_dir . '/nested' );
	file_put_contents( $root . '/outside/secret.png', $png );
	file_put_contents( $root . '/temp-uploads-evil/sibling.png', $png );
	file_put_contents( $tmp_dir . '/nested/deep.png', $png );
	file_put_contents( $tmp_dir . '/valid-avatar.png', $png );

	$make_form = function ( $token ) {
		return array(
			'profile_pic_url' => (object) array( 'value' => $token ),
		);
	};

	// Tampered upload token.
	security_assert( false === ur_upload_profile_pic( $make_form( $flip_byte( $raw, 48 ) ), 7 ), 'Rejects tampered profile pic token' );

	// Forged valid-key token pointing outside temp-uploads.
	$outside_token = crypt_the_string(
		serialize(
			array(
				'file_name'      => 'secret.png',
				'file_path'      => $root . '/outside/secret.png',
				'file_extension' => 'png',
			)
		),
		'e'
	);
	security_assert( false === ur_upload_profile_pic( $make_form( $outside_token ), 7 ), 'Rejects out-of-directory absolute path' );
	security_assert( is_file( $root . '/outside/secret.png' ), 'Preserves outside file after rejected move' );
	security_assert( ! file_exists( $root . '/profile-pictures/secret.png' ), 'Does not relocate outside file to profile-pictures' );

	// Forged valid-key token using .. traversal from temp-uploads.
	$traversal_token = crypt_the_string(
		serialize(
			array(
				'file_name'      => 'secret.png',
				'file_path'      => $tmp_dir . '/../outside/secret.png',
				'file_extension' => 'png',
			)
		),
		'e'
	);
	security_assert( false === ur_upload_profile_pic( $make_form( $traversal_token ), 7 ), 'Rejects dot-dot path traversal' );
	security_assert( is_file( $root . '/outside/secret.png' ), 'Preserves outside file after traversal rejection' );

	// Forged valid-key token targeting sibling directory with matching prefix.
	$sibling_token = crypt_the_string(
		serialize(
			array(
				'file_name'      => 'sibling.png',
				'file_path'      => $root . '/temp-uploads-evil/sibling.png',
				'file_extension' => 'png',
			)
		),
		'e'
	);
	security_assert( false === ur_upload_profile_pic( $make_form( $sibling_token ), 7 ), 'Rejects sibling directory with matching prefix' );
	security_assert( is_file( $root . '/temp-uploads-evil/sibling.png' ), 'Preserves sibling directory file' );

	// Forged valid-key token targeting nested subdirectory inside temp-uploads.
	$nested_token = crypt_the_string(
		serialize(
			array(
				'file_name'      => 'deep.png',
				'file_path'      => $tmp_dir . '/nested/deep.png',
				'file_extension' => 'png',
			)
		),
		'e'
	);
	security_assert( false === ur_upload_profile_pic( $make_form( $nested_token ), 7 ), 'Rejects nested subdirectory inside temp-uploads' );
	security_assert( is_file( $tmp_dir . '/nested/deep.png' ), 'Preserves nested file' );

	// 4. Legitimate upload inside temp-uploads succeeds.
	$valid_token = crypt_the_string(
		serialize(
			array(
				'file_name'      => 'valid-avatar.png',
				'file_path'      => $tmp_dir . '/valid-avatar.png',
				'file_extension' => 'png',
			)
		),
		'e'
	);
	security_assert( true === ur_upload_profile_pic( $make_form( $valid_token ), 7 ), 'Accepts legitimate profile pic in temp-uploads' );
	security_assert( is_file( $root . '/profile-pictures/valid-avatar.png' ), 'Moves legitimate profile pic into profile-pictures' );
	security_assert( ! file_exists( $tmp_dir . '/valid-avatar.png' ), 'Removes legitimate profile pic from temp-uploads' );
	security_assert( 101 === ( $GLOBALS['user_meta'][7]['user_registration_profile_pic_url'] ?? null ), 'Saves attachment ID to user meta' );
} finally {
	if ( is_dir( $root ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
		rmdir( $root );
	}
}
