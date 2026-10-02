<?php
/** Run with wp eval-file on a disposable WordPress install. @package UserRegistration/Tests */
if ( '1' !== getenv( 'UR_SECURITY_DISPOSABLE' ) ) { throw new RuntimeException( 'Disposable install required.' ); }
$count = 0;
$check = function ( $value, $message ) use ( &$count ) { if ( ! $value ) { throw new RuntimeException( $message ); } ++$count; };
$payload = 'POC" type="image" src="zzz" onerror="alert(document.domain)" a="';
$html = user_registration_form_field( 'check', array( 'type' => 'checkbox', 'label' => 'Check', 'options' => array(), 'return' => true ), $payload );
$dom = new DOMDocument();
@$dom->loadHTML( $html );
$input = $dom->getElementsByTagName( 'input' )->item( 0 );
$check( $input && 'checkbox' === $input->getAttribute( 'type' ), 'Fallback retains checkbox type' );
$check( $payload === $input->getAttribute( 'data-value' ), 'Payload stays inside data-value' );
$check( ! $input->hasAttribute( 'onerror' ) && ! $input->hasAttribute( 'src' ), 'No injected attributes' );
$user_id = wp_insert_user( array( 'user_login' => 'checkbox-' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
try {
	$data = (object) array( 'field_name' => 'check', 'value' => wp_json_encode( array( '<script>alert(1)</script>Choice', 'Second' ) ), 'extra_params' => array( 'field_key' => 'checkbox' ) );
	UR_Frontend_Form_Handler::ur_update_user_meta( $user_id, array( $data ), 0 );
	$stored = get_user_meta( $user_id, 'user_registration_check', true );
	$check( array( 'Choice', 'Second' ) === $stored, 'Decoded checkbox values sanitized before storage' );
	foreach ( array( array( 'Choice' ), array( 'Not configured' ), array( array( 'Choice' ) ) ) as $i => $values ) {
		$field = (object) array( 'general_setting' => (object) array( 'field_name' => 'check', 'options' => array( 'Choice', 'Second' ) ), 'advance_setting' => (object) array() );
		UR_Form_Field_Checkbox::get_instance()->validation( $field, (object) array( 'value' => wp_json_encode( $values ) ), 'checkbox_test_message', 0 );
		$message = apply_filters( 'checkbox_test_message', '' );
		$check( ( 0 === $i ) === ( '' === $message ), 'Only configured scalar options accepted' );
		remove_all_filters( 'checkbox_test_message' );
	}
} finally { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user_id ); }
echo $count . " assertions passed\n";
