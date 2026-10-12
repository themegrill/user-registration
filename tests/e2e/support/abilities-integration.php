<?php
/**
 * Real WordPress integration proof; run only in a disposable installation.
 *
 * @package UserRegistration/Tests
 */

if ( '1' !== getenv( 'UR_ABILITIES_DISPOSABLE' ) || ! defined( 'WP_CLI' ) ) {
	throw new RuntimeException( 'Set UR_ABILITIES_DISPOSABLE=1 on a disposable WP-CLI installation.' );
}

$GLOBALS['ur_abilities_checks'] = 0;
/**
 * Assert an integration result.
 *
 * @param bool   $condition Result.
 * @param string $message Failure description.
 * @throws RuntimeException On failed assertion.
 */
function abilities_assert( $condition, $message ) {
	++$GLOBALS['ur_abilities_checks'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
/**
 * Execute a registered ability.
 *
 * @param string $name Ability suffix.
 * @param array  $input Input.
 * @return mixed
 * @throws RuntimeException On execution error.
 */
function abilities_run( $name, $input = array() ) {
	$result = wp_get_ability( 'user-registration/' . $name )->execute( $input );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $name . ': ' . $result->get_error_message() );
	}
	return $result;
}
$admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
)[0];
wp_set_current_user( $admin->ID );
$fixture_tag       = 'ability-' . wp_generate_password( 10, false );
$fixture_posts     = array();
$users             = array();
$original_features = get_option( 'user_registration_enabled_features', array() );
$queries           = array();
$watch             = static function ( $sql ) use ( &$queries ) {
	if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE)\b/i', $sql ) ) {
		$queries[] = $sql;
	}
	return $sql;
};
try {
	$form            = wp_insert_post(
		array(
			'post_type'    => 'user_registration',
			'post_status'  => 'publish',
			'post_title'   => $fixture_tag,
			'post_content' => wp_slash(
				wp_json_encode(
					array(
						array(
							array(
								array(
									'field_key'       => 'user_email',
									'general_setting' => array(
										'field_name'    => 'user_email',
										'label'         => 'Email',
										'required'      => 'yes',
										'default_value' => 'SECRET-DEFAULT',
									),
									'advance_setting' => array( 'api_key' => 'SECRET-API-KEY' ),
								),
							),
						),
					)
				)
			),
		)
	);
	$fixture_posts[] = $form;
	$draft           = wp_insert_post(
		array(
			'post_type'   => 'user_registration',
			'post_status' => 'draft',
			'post_title'  => $fixture_tag . '-draft',
		)
	);
	$fixture_posts[] = $draft;
	$ordinary        = wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => $fixture_tag,
		)
	);
	$fixture_posts[] = $ordinary;
	foreach ( array( 'approved', 'pending', 'denied', 'combined', 'membership', 'outsider' ) as $i => $kind ) {
		$user = wp_insert_user(
			array(
				'user_login'      => $fixture_tag . '-' . $kind,
				'user_email'      => $fixture_tag . '-' . $kind . '@example.test',
				'user_pass'       => 'SECRET-PASSWORD',
				'role'            => 'subscriber',
				'user_registered' => '2026-01-02 12:00:00',
			)
		);
		abilities_assert( ! is_wp_error( $user ), 'fixture user created' );
		$users[ $kind ] = $user;
		if ( $i < 4 ) {
			update_user_meta( $user, 'ur_form_id', $form );
			update_user_meta( $user, 'ur_user_status', array( 1, 0, -1, 0 )[ $i ] );
		}
		update_user_meta( $user, 'secret_api_token', 'SECRET-USER-META' );
	}
	update_user_meta( $users['combined'], 'ur_confirm_email', '1' );
	update_user_meta( $users['combined'], 'ur_admin_approval_after_email_confirmation', 'false' );
	update_user_meta( $users['membership'], 'ur_registration_source', 'membership' );
	update_option( 'user_registration_enabled_features', array() );

	$fixture_names = array( 'list-forms', 'get-form', 'get-registration-stats', 'list-members', 'get-member' );
	$inputs        = array( array(), array( 'id' => $form ), array(), array(), array( 'id' => $users['approved'] ) );
	foreach ( array( 0, $users['outsider'] ) as $caller ) {
		wp_set_current_user( $caller );
		foreach ( $fixture_names as $i => $name ) {
			abilities_assert( is_wp_error( wp_get_ability( 'user-registration/' . $name )->execute( $inputs[ $i ] ) ), $name . ' denies unauthorized caller' );
		}
		$mcp = wp_get_ability( 'mcp-adapter/execute-ability' )->execute(
			array(
				'ability_name' => 'user-registration/get-member',
				'parameters'   => array( 'id' => $users['approved'] ),
			)
		);
		abilities_assert( is_wp_error( $mcp ), 'MCP enforces target permissions' );
	}
	$limited = new WP_User( $users['outsider'] );
	$limited->add_cap( 'manage_user_registration' );
	wp_set_current_user( $limited->ID );
	abilities_assert( is_wp_error( wp_get_ability( 'user-registration/list-members' )->execute( array() ) ), 'registration capability alone cannot read PII' );
	abilities_assert( is_wp_error( wp_get_ability( 'user-registration/get-form' )->execute( array( 'id' => $draft ) ) ), 'form object capability enforced' );
	$limited->remove_cap( 'manage_user_registration' );
	wp_set_current_user( $admin->ID );
	foreach ( array( array( 'per_page' => 101 ), array( 'page' => 0 ), array( 'extra' => 'secret' ) ) as $input ) {
		abilities_assert( is_wp_error( wp_get_ability( 'user-registration/list-members' )->execute( $input ) ), 'reject invalid pagination/unknown inputs' );
	}
	abilities_assert( is_wp_error( wp_get_ability( 'user-registration/get-member' )->execute( array() ) ), 'ID required' );
	abilities_assert( is_wp_error( wp_get_ability( 'user-registration/get-member' )->execute( array( 'id' => -1 ) ) ), 'positive ID required' );
	abilities_assert( is_wp_error( wp_get_ability( 'user-registration/get-member' )->execute( array( 'id' => $users['outsider'] ) ) ), 'exclude nonmembers' );
	abilities_assert( is_wp_error( wp_get_ability( 'user-registration/get-form' )->execute( array( 'id' => $ordinary ) ) ), 'exclude other post types' );
	foreach ( array(
		array(
			'date_from' => '2026-02-30',
			'date_to'   => '2026-03-01',
		),
		array(
			'date_from' => '2026-01-01',
			'date_to'   => '2026-05-01',
		),
		array(
			'date_from' => '2026-02-01',
			'date_to'   => '2026-01-01',
		),
	) as $input ) {
		abilities_assert( is_wp_error( wp_get_ability( 'user-registration/get-registration-stats' )->execute( $input ) ), 'reject invalid date range' );
	}

	// Initialize REST before monitoring SQL so unrelated lazy bootstrap is excluded.
	rest_get_server();
	add_filter( 'query', $watch );
	foreach ( array( 'list-forms', 'list-members', 'get-registration-stats' ) as $name ) {
		$default_result = wp_get_ability( 'user-registration/' . $name )->execute();
		abilities_assert( ! is_wp_error( $default_result ), $name . ' accepts omitted input' );
		abilities_assert( $default_result === abilities_run( $name, array() ), $name . ' omitted input matches explicit empty input' );
		if ( 'get-registration-stats' !== $name ) {
			abilities_assert( 1 === $default_result['page'] && 20 === $default_result['per_page'], $name . ' uses default pagination' );
		} else {
			abilities_assert( gmdate( 'Y-m-d', strtotime( '-29 days' ) ) === $default_result['date_from'] && gmdate( 'Y-m-d' ) === $default_result['date_to'], 'omitted statistics input uses the last 30 days' );
		}
	}
	foreach ( array( 'get-form', 'get-member' ) as $name ) {
		abilities_assert( is_wp_error( wp_get_ability( 'user-registration/' . $name )->execute() ), $name . ' still requires an ID when input is omitted' );
	}
	$default_request  = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/user-registration/get-registration-stats/run' );
	$default_response = rest_do_request( $default_request );
	abilities_assert( 200 === $default_response->get_status(), 'native REST statistics accepts no input parameter' );
	abilities_assert( $default_response->get_data() === abilities_run( 'get-registration-stats', array() ), 'native REST omitted input uses documented defaults' );
	$forms = abilities_run( 'list-forms', array( 'per_page' => 1 ) );
	abilities_assert( count( $forms['items'] ) === 1 && $forms['has_more'], 'form pagination' );
	$form_data = abilities_run( 'get-form', array( 'id' => $form ) );
	abilities_assert( count( $form_data['fields'] ) === 1 && $form_data['fields'][0]['required'], 'field definition and required flag' );
	abilities_assert( false === strpos( wp_json_encode( $form_data ), 'SECRET' ), 'no settings/default values' );
	abilities_assert( 'draft' === abilities_run( 'get-form', array( 'id' => $draft ) )['status'], 'authorized draft access' );
	$page1 = abilities_run(
		'list-members',
		array(
			'form_id'  => $form,
			'per_page' => 2,
		)
	);
	$page2 = abilities_run(
		'list-members',
		array(
			'form_id'  => $form,
			'per_page' => 2,
			'page'     => 2,
		)
	);
	abilities_assert( count( $page1['items'] ) === 2 && $page1['has_more'] && count( $page2['items'] ) === 2 && ! $page2['has_more'], 'bounded pagination' );
	abilities_assert( ! array_intersect( array_column( $page1['items'], 'id' ), array_column( $page2['items'], 'id' ) ), 'pages do not overlap' );
	foreach ( array( 'approved', 'pending', 'denied', 'combined' ) as $kind ) {
		$member = abilities_run( 'get-member', array( 'id' => $users[ $kind ] ) );
		abilities_assert( ( 'combined' === $kind ? 'pending' : $kind ) === $member['registration_status'], 'status matches admin: ' . $kind );
		abilities_assert( false === strpos( wp_json_encode( $member ), 'SECRET' ), 'no credentials or arbitrary metadata' );
		abilities_assert( false === $member['membership_available'] && array() === $member['subscriptions'], 'membership disabled is explicit' );
	}
	abilities_assert( true === abilities_run( 'get-member', array( 'id' => $users['combined'] ) )['email_verified'], 'email verification separate from approval' );
	abilities_assert( 0 === abilities_run( 'get-member', array( 'id' => $users['membership'] ) )['form_id'], 'membership-only account included' );
	$stats = abilities_run(
		'get-registration-stats',
		array(
			'form_id'   => $form,
			'date_from' => '2026-01-02',
			'date_to'   => '2026-01-02',
		)
	);
	abilities_assert( 4 === $stats['total'] && count( $stats['groups'] ) === 3, 'inclusive dates and grouped counts' );
	abilities_assert( 2 === array_column( $stats['groups'], 'count', 'status' )['pending'], 'statistics reuse combined approval status' );
	abilities_assert(
		0 === abilities_run(
			'get-registration-stats',
			array(
				'form_id'   => $form,
				'date_from' => '2026-01-03',
				'date_to'   => '2026-01-03',
			)
		)['total'],
		'empty date range'
	);
	$discovery = wp_get_ability( 'mcp-adapter/discover-abilities' )->execute();
	foreach ( $fixture_names as $name ) {
		abilities_assert( in_array( 'user-registration/' . $name, array_column( $discovery['abilities'], 'name' ), true ), 'MCP discovers ' . $name );
	}
	$mcp = wp_get_ability( 'mcp-adapter/execute-ability' )->execute(
		array(
			'ability_name' => 'user-registration/get-form',
			'parameters'   => array( 'id' => $form ),
		)
	);
	abilities_assert( ! is_wp_error( $mcp ) && $mcp['success'] && $mcp['data']['id'] === $form, 'MCP executes shared ability' );
	$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/user-registration/get-form/run' );
	$request->set_param( 'input', array( 'id' => $form ) );
	$response = rest_do_request( $request );
	abilities_assert( $response->get_status() === 200 && $response->get_data()['id'] === $form, 'native REST execution' );
	wp_set_current_user( 0 );
	abilities_assert( rest_do_request( $request )->get_status() === 401, 'anonymous REST denied' );
	remove_filter( 'query', $watch );
	abilities_assert( array() === $queries, 'ability execution performs no SQL writes' );
	wp_set_current_user( $admin->ID );

	// Exercise real shared membership repository, including multiple plans.
	update_option( 'user_registration_enabled_features', array( 'user-registration-membership' ) );
	\WPEverest\URMembership\Admin\Database\Database::create_tables();
	global $wpdb;
	$table = \WPEverest\URMembership\TableList::subscriptions_table();
	foreach ( array( 'active', 'expired' ) as $fixture_status ) {
		$plan            = wp_insert_post(
			array(
				'post_type'    => 'ur_membership',
				'post_status'  => 'publish',
				'post_title'   => $fixture_tag . '-' . $fixture_status,
				'post_content' => 'SECRET-PLAN',
			)
		);
		$fixture_posts[] = $plan;
		$wpdb->insert(
			$table,
			array(
				'user_id'        => $users['membership'],
				'item_id'        => $plan,
				'start_date'     => '2026-01-01 00:00:00',
				'expiry_date'    => '2026-12-31 00:00:00',
				'billing_cycle'  => 'month',
				'billing_amount' => 10,
				'status'         => $fixture_status,
			)
		);
	}
	add_filter( 'query', $watch );
	$member = abilities_run( 'get-member', array( 'id' => $users['membership'] ) );
	remove_filter( 'query', $watch );
	abilities_assert( $member['membership_available'] && count( $member['subscriptions'] ) === 2, 'multiple subscription plans' );
	abilities_assert( false === strpos( wp_json_encode( $member ), 'SECRET' ), 'no raw membership content or payment details' );
	abilities_assert( array() === $queries, 'membership read performs no SQL writes' );
	// Exercise the scan ceiling without manufacturing ten thousand accounts.
	$large_count = static function () {
		return 'SELECT 10001';
	};
	add_filter( 'found_users_query', $large_count );
	$over_limit = wp_get_ability( 'user-registration/get-registration-stats' )->execute(
		array(
			'date_from' => '2026-01-01',
			'date_to'   => '2026-01-03',
			'form_id'   => $form,
		)
	);
	remove_filter( 'found_users_query', $large_count );
	abilities_assert( is_wp_error( $over_limit ) && 'ur_abilities_stats_limit' === $over_limit->get_error_code(), 'large scans fail explicitly without partial statistics' );
	WP_CLI::success( $GLOBALS['ur_abilities_checks'] . ' abilities integration assertions passed.' );
} finally {
	remove_filter( 'query', $watch );
	wp_set_current_user( $admin->ID );
	update_option( 'user_registration_enabled_features', $original_features );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $user ) {
		wp_delete_user( $user );
	}
	foreach ( $fixture_posts as $fixture_post ) {
		wp_delete_post( $fixture_post, true );
	}
}
