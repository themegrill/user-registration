<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test doubles share this file.
/**
 * Regression: AI assistants can read a membership plan, create and change plans, and create and change plan groups,
 * through the same save code the screens use. A plan and a group are checked with the screens' own limits: a paid
 * plan needs a price, a subscription needs a billing period and Pro, a trial belongs to a subscription. A plan that
 * members hold keeps its price, type, billing period and trial. Groups need Pro, a Plus plan and the module, hold
 * real plans, and leave upgrade groups to the screen.
 *
 * Runs the real `UR_Abilities_Data` functions with doubles for the membership services and WordPress; no live site or
 * database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Classes.OpeningBraceSameLine.BraceOnNewLine

namespace WPEverest\URMembership\Admin\Services {
	class MembershipService {
		public static function is_plan_type_available( $type ) {
			return 'subscription' !== $type || ! empty( $GLOBALS['pro'] );
		}

		public function count_delete_blocking_members( $id ) {
			return (int) ( $GLOBALS['holders'][ $id ] ?? 0 );
		}

		public function create_membership_plan( $data, $rule = null ) {
			$GLOBALS['saves'][] = array( 'create', $data );

			if ( ! empty( $GLOBALS['service_error'] ) ) {
				return array(
					'status'  => false,
					'message' => $GLOBALS['service_error'],
				);
			}

			$id = 900 + count( $GLOBALS['saves'] );
			self::store( $id, $data );

			return array(
				'status'        => true,
				'membership_id' => $id,
			);
		}

		public function update_membership_plan( $data, $rule = null ) {
			$GLOBALS['saves'][] = array( 'update', $data );
			self::store( $data['post_data']['ID'], $data );

			return array(
				'status'        => true,
				'membership_id' => $data['post_data']['ID'],
			);
		}

		private static function store( $id, $data ) {
			$GLOBALS['posts'][ $id ] = (object) array(
				'ID'           => $id,
				'post_type'    => 'ur_membership',
				'post_status'  => 'publish',
				'post_title'   => $data['post_data']['name'],
				'post_content' => json_encode(
					array(
						'type'   => $data['post_meta_data']['type'],
						'status' => $data['post_data']['status'],
					)
				),
			);
			$GLOBALS['meta'][ $id ]  = array(
				'ur_membership'             => json_encode( $data['post_meta_data'] ),
				'ur_membership_description' => $data['post_data']['description'],
			);
		}
	}

	class MembershipGroupService {
		public static function is_available() {
			return ! empty( $GLOBALS['groups_on'] );
		}

		public function create_membership_groups( $json ) {
			$data                 = json_decode( stripslashes( $json ), true );
			$GLOBALS['group_in'][] = $data;

			if ( in_array( $data['post_data']['name'], $GLOBALS['taken_names'], true ) && empty( $data['post_data']['ID'] ) ) {
				return array(
					'status'  => false,
					'message' => 'Group name must be unique.',
				);
			}

			$id                      = ! empty( $data['post_data']['ID'] ) ? $data['post_data']['ID'] : 700 + count( $GLOBALS['group_in'] );
			$GLOBALS['posts'][ $id ] = (object) array(
				'ID'           => $id,
				'post_type'    => 'ur_membership_groups',
				'post_status'  => 'publish',
				'post_title'   => $data['post_data']['name'],
				'post_content' => json_encode(
					array(
						'description' => $data['post_data']['description'],
						'status'      => $data['post_data']['status'],
					)
				),
			);
			$GLOBALS['meta'][ $id ]  = array(
				'urmg_memberships' => json_encode( $data['post_meta_data']['memberships'] ),
				'urmg_mode'        => $data['post_meta_data']['mode'],
			);

			return array(
				'status'              => true,
				'membership_group_id' => $id,
			);
		}
	}
}

namespace WPEverest\URMembership\Admin\Repositories {
	class MembersRepository {}

	class MembershipRepository {
		public function count_members_by_membership( $status ) {
			return $GLOBALS['holders'];
		}
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function get_post( $id ) {
		return $GLOBALS['posts'][ $id ] ?? null;
	}
	function get_post_meta( $id, $key = '', $single = false ) {
		return $GLOBALS['meta'][ $id ][ $key ] ?? '';
	}
	function get_the_title( $id ) {
		return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]->post_title : '';
	}
	function get_posts( $args ) {
		$all = array_values( array_filter( $GLOBALS['posts'], function ( $p ) use ( $args ) { return $args['post_type'] === $p->post_type; } ) );
		usort( $all, function ( $a, $b ) { return $b->ID - $a->ID; } );

		return array_slice( $all, ( $args['paged'] - 1 ) * ( $args['posts_per_page'] - 1 ), $args['posts_per_page'] );
	}
	function clean_post_cache( $id ) {}
	function wp_strip_all_tags( $s ) {
		return strip_tags( (string) $s );
	}
	function wp_slash( $value ) {
		return addslashes( $value );
	}
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
	function ur_check_module_activation( $module ) {
		return ! empty( $GLOBALS['modules'][ $module ] );
	}
	function ur_utm_url( $url ) {
		return $url;
	}
	function urm_is_payment_gateway_configured( $gateway ) {
		return ! empty( $GLOBALS['stripe_on'] ) && 'stripe' === $gateway;
	}
	function _n( $single, $plural, $number ) {
		return 1 === $number ? $single : $plural;
	}

	require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities.php';
	require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-data.php';

	function plan_post( $id, $title, $type, $meta, $extra = array() ) {
		$GLOBALS['posts'][ $id ] = (object) array(
			'ID'           => $id,
			'post_type'    => 'ur_membership',
			'post_status'  => $extra['status'] ?? 'publish',
			'post_title'   => $title,
			'post_content' => json_encode(
				array(
					'type'   => $type,
					'status' => true,
				)
			),
		);
		$GLOBALS['meta'][ $id ]  = array(
			'ur_membership'             => json_encode( array( 'type' => $type ) + $meta ),
			'ur_membership_description' => $extra['description'] ?? '',
		);
	}
	function code( $error ) {
		return is_wp_error( $error ) ? $error->get_error_code() : 'ok';
	}
	function status( $error ) {
		return is_wp_error( $error ) ? $error->get_error_data()['status'] : 200;
	}
	function fresh() {
		$GLOBALS['saves']      = array();
		$GLOBALS['group_in']   = array();
		$GLOBALS['service_error'] = '';
	}

	$posts        = array();
	$meta         = array();
	$saves        = array();
	$group_in     = array();
	$holders      = array( 12 => 3 );
	$modules      = array( 'membership' => true );
	$pro          = true;
	$groups_on    = true;
	$taken_names  = array( 'Tiers' );
	$service_error = '';
	$options      = array( 'user_registration_payment_currency' => 'USD' );

	plan_post( 10, 'Free', 'free', array( 'role' => 'subscriber', 'amount' => 0 ) );
	plan_post( 11, 'Gold', 'paid', array( 'role' => 'subscriber', 'amount' => '20', 'payment_gateways' => array( 'stripe' => array( 'status' => 'on', 'product_id' => 'prod_SECRET', 'price_id' => 'price_SECRET' ), 'bank' => array( 'status' => 'off' ) ) ), array( 'description' => 'The <b>gold</b> plan' ) );
	plan_post( 12, 'Monthly', 'subscription', array( 'role' => 'editor', 'amount' => '9.5', 'subscription' => array( 'value' => 1, 'duration' => 'month' ), 'trial_status' => 'on', 'trial_data' => array( 'value' => 7, 'duration' => 'day' ), 'payment_gateways' => array( 'paypal' => array( 'status' => 'on', 'email' => 'owner@example.com' ) ) ) );
	plan_post( 13, 'Old', 'free', array(), array( 'status' => 'trash' ) );
	$posts[14] = (object) array( 'ID' => 14, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Not a plan', 'post_content' => '' );

	$posts[50] = (object) array( 'ID' => 50, 'post_type' => 'ur_membership_groups', 'post_status' => 'publish', 'post_title' => 'Tiers', 'post_content' => json_encode( array( 'description' => 'Choose one', 'status' => true ) ) );
	$meta[50]  = array( 'urmg_memberships' => json_encode( array( 10, 11 ) ), 'urmg_mode' => 'single' );
	$posts[51] = (object) array( 'ID' => 51, 'post_type' => 'ur_membership_groups', 'post_status' => 'publish', 'post_title' => 'Upgrades', 'post_content' => json_encode( array( 'description' => '', 'status' => true ) ) );
	$meta[51]  = array( 'urmg_memberships' => json_encode( array( 10, 11 ) ), 'urmg_mode' => 'upgrade' );

	// Reading one plan.
	$plan = UR_Abilities_Data::get_membership( array( 'id' => 12 ) );
	security_assert( 12 === $plan['id'] && 'Monthly' === $plan['name'] && 'subscription' === $plan['type'] && '9.5' === $plan['amount'] && 'USD' === $plan['currency'], 'A plan is read with its name, type, price and currency' );
	security_assert( 1 === $plan['billing_interval'] && 'month' === $plan['billing_period'] && 7 === $plan['trial_interval'] && 'day' === $plan['trial_period'] && 'editor' === $plan['role'] && 3 === $plan['active_members'], 'Its billing period, trial, role and member count are read' );
	security_assert( array( 'paypal' ) === $plan['payment_gateways'], 'Only the names of the gateways that are on are shown' );
	$gold = UR_Abilities_Data::get_membership( array( 'id' => 11 ) );
	security_assert( array( 'stripe' ) === $gold['payment_gateways'] && false === strpos( json_encode( $gold ), 'SECRET' ) && false === strpos( json_encode( $plan ), 'owner@example.com' ) && 'The gold plan' === $gold['description'], 'No gateway keys, product ids or emails leave the site, and the description is plain text' );
	$free = UR_Abilities_Data::get_membership( array( 'id' => 10 ) );
	security_assert( null === $free['billing_interval'] && null === $free['trial_interval'] && '0' === $free['amount'], 'A free plan has no billing period or trial' );
	security_assert( 'ur_abilities_not_found' === code( UR_Abilities_Data::get_membership( array( 'id' => 999 ) ) ) && 'ur_abilities_not_found' === code( UR_Abilities_Data::get_membership( array( 'id' => 13 ) ) ) && 'ur_abilities_not_found' === code( UR_Abilities_Data::get_membership( array( 'id' => 14 ) ) ), 'A missing plan, a trashed plan and a post that is not a plan are not found' );
	$modules['membership'] = false;
	security_assert( 'ur_abilities_membership_disabled' === code( UR_Abilities_Data::get_membership( array( 'id' => 10 ) ) ) && 'ur_abilities_membership_disabled' === code( UR_Abilities_Data::create_membership( array( 'name' => 'X', 'type' => 'free' ) ) ), 'With Membership off, reading and creating plans say so' );
	$modules['membership'] = true;

	// Creating a plan: the limits of the plan editor.
	security_assert( 'ur_abilities_invalid_plan' === code( UR_Abilities_Data::create_membership( array( 'name' => '   ', 'type' => 'free' ) ) ), 'A plan needs a name' );
	security_assert( 'ur_abilities_invalid_plan' === code( UR_Abilities_Data::create_membership( array( 'name' => 'X', 'type' => 'lifetime' ) ) ), 'The type must be free, paid or subscription' );
	security_assert( 'ur_abilities_invalid_plan' === code( UR_Abilities_Data::create_membership( array( 'name' => 'X', 'type' => 'paid' ) ) ) && 'ur_abilities_invalid_plan' === code( UR_Abilities_Data::create_membership( array( 'name' => 'X', 'type' => 'paid', 'amount' => 0 ) ) ), 'A paid plan needs a price above 0' );
	security_assert( 'ur_abilities_invalid_plan' === code( UR_Abilities_Data::create_membership( array( 'name' => 'X', 'type' => 'subscription', 'amount' => 5 ) ) ) && 'ur_abilities_invalid_plan' === code( UR_Abilities_Data::create_membership( array( 'name' => 'X', 'type' => 'subscription', 'amount' => 5, 'billing_interval' => 1, 'billing_period' => 'fortnight' ) ) ), 'A subscription needs a billing interval and a real period' );
	security_assert( 'ur_abilities_invalid_plan' === code( UR_Abilities_Data::create_membership( array( 'name' => 'X', 'type' => 'subscription', 'amount' => 5, 'billing_interval' => 1, 'billing_period' => 'month', 'trial_interval' => 7 ) ) ), 'A trial needs both a length and a period' );
	security_assert( 'ur_abilities_invalid_plan' === code( UR_Abilities_Data::create_membership( array( 'name' => 'X', 'type' => 'paid', 'amount' => 5, 'trial_interval' => 7, 'trial_period' => 'day' ) ) ), 'A free trial belongs to a subscription, not to a one-time plan' );
	$pro = false;
	$locked = UR_Abilities_Data::create_membership( array( 'name' => 'X', 'type' => 'subscription', 'amount' => 5, 'billing_interval' => 1, 'billing_period' => 'month' ) );
	security_assert( 'ur_ability_plan_required' === code( $locked ) && 403 === status( $locked ) && isset( $locked->get_error_data()['upgrade_url'] ), 'A subscription without Pro gets the usual needs-a-plan answer with an upgrade link' );
	security_assert( is_string( UR_Abilities_Data::preview_create_membership( array( 'name' => 'Free forever', 'type' => 'free' ) ) ) && is_string( UR_Abilities_Data::preview_create_membership( array( 'name' => 'One off', 'type' => 'paid', 'amount' => 5 ) ) ), 'A free or one-time plan does not need Pro' );
	$pro = true;
	security_assert( array() === $saves, 'Nothing was saved by any of the refused requests' );

	// What the editor is given for a new plan.
	fresh();
	$made = UR_Abilities_Data::create_membership( array( 'name' => '  Starter  ', 'type' => 'free', 'amount' => 99, 'description' => 'Try it' ) );
	$sent = $saves[0][1];
	security_assert( 'create' === $saves[0][0] && ! isset( $sent['post_data']['ID'] ) && 'Starter' === $sent['post_data']['name'] && true === $sent['post_data']['status'] && 'Try it' === $sent['post_data']['description'], 'A new plan is sent to the shared save code as the editor would send it: trimmed name, on, no ID' );
	security_assert( 'free' === $sent['post_meta_data']['type'] && 0 === $sent['post_meta_data']['amount'] && 'subscriber' === $sent['post_meta_data']['role'] && ! isset( $sent['post_meta_data']['payment_gateways'] ) && ! isset( $sent['post_meta_data']['subscription'] ), 'A free plan ignores the price it was given, gets the default role and has no gateways or billing period' );
	security_assert( 'Starter' === $made['name'] && 'free' === $made['type'] && true === $made['enabled'] && 'Try it' === $made['description'], 'What comes back is the plan as saved' );

	fresh();
	UR_Abilities_Data::create_membership( array( 'name' => 'Gold', 'type' => 'paid', 'amount' => 20.50, 'enabled' => false, 'role' => 'author' ) );
	$sent = $saves[0][1];
	security_assert( '20.5' === $sent['post_meta_data']['amount'] && false === $sent['post_data']['status'] && 'author' === $sent['post_meta_data']['role'], 'A price is kept as a clean number, a plan can start turned off, and a role can be set' );
	security_assert( 'off' === $sent['post_meta_data']['payment_gateways']['stripe']['status'] && 'off' === $sent['post_meta_data']['payment_gateways']['bank']['status'] && 'sandbox' === $sent['post_meta_data']['payment_gateways']['paypal']['mode'], 'A new paid plan starts with every gateway off, as in the editor, so the admin chooses them' );

	fresh();
	UR_Abilities_Data::create_membership( array( 'name' => 'Club', 'type' => 'subscription', 'amount' => 12, 'billing_interval' => 3, 'billing_period' => 'month', 'trial_interval' => 14, 'trial_period' => 'day' ) );
	$sent = $saves[0][1]['post_meta_data'];
	security_assert( array( 'value' => 3, 'duration' => 'month' ) === $sent['subscription'] && 'on' === $sent['trial_status'] && array( 'value' => 14, 'duration' => 'day' ) === $sent['trial_data'], 'A subscription carries its billing period and its trial in the editor shape' );
	fresh();
	UR_Abilities_Data::create_membership( array( 'name' => 'Club 2', 'type' => 'subscription', 'amount' => 12, 'billing_interval' => 1, 'billing_period' => 'year' ) );
	security_assert( 'off' === $saves[0][1]['post_meta_data']['trial_status'] && ! isset( $saves[0][1]['post_meta_data']['trial_data'] ), 'Without a trial the trial is off' );

	// A failure in the shared save code comes back as it is.
	fresh();
	$service_error = 'Stripe does not support yearly subscription periods greater than 3 years.';
	$refused       = UR_Abilities_Data::create_membership( array( 'name' => 'Long', 'type' => 'subscription', 'amount' => 5, 'billing_interval' => 5, 'billing_period' => 'year' ) );
	security_assert( 'ur_abilities_create_failed' === code( $refused ) && 400 === status( $refused ) && false !== strpos( $refused->get_error_message(), 'Stripe does not support' ), 'When the shared code refuses a plan, the reason comes back with a 400' );
	$service_error = '';

	// The privileged-role rule lives in the shared code, so nothing here can bypass it.
	$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-data.php' );
	security_assert( false === strpos( $source, 'wp_insert_post' ) && false === strpos( $source, 'add_post_meta' ) && false === strpos( $source, 'update_post_meta( $plan' ), 'The abilities never write a plan themselves: they hand it to the shared save code' );

	// Previews.
	fresh();
	$stripe_on = true;
	$preview   = UR_Abilities_Data::preview_create_membership( array( 'name' => 'Club', 'type' => 'subscription', 'amount' => 12, 'billing_interval' => 3, 'billing_period' => 'month', 'trial_interval' => 14, 'trial_period' => 'day' ) );
	security_assert( false !== strpos( $preview, '"Club"' ) && false !== strpos( $preview, '12 USD every 3 months' ) && false !== strpos( $preview, 'free trial of 14 days' ) && false !== strpos( $preview, 'Stripe is connected' ) && false !== strpos( $preview, 'turned on' ), 'The preview names the plan, the price, the billing period, the trial, Stripe and whether it is on' );
	security_assert( false !== strpos( UR_Abilities_Data::preview_create_membership( array( 'name' => 'Weekly', 'type' => 'subscription', 'amount' => 3, 'billing_interval' => 1, 'billing_period' => 'week', 'trial_interval' => 1, 'trial_period' => 'day' ) ), '3 USD every 1 week, with a free trial of 1 day' ), 'One of something is written in the singular'.' (every 1 week, a trial of 1 day)' );
	security_assert( false === strpos( UR_Abilities_Data::preview_create_membership( array( 'name' => 'Free', 'type' => 'free' ) ), 'Stripe' ), 'A free plan is never created in Stripe, so its preview does not mention Stripe' );
	$stripe_on = false;
	security_assert( false === strpos( UR_Abilities_Data::preview_create_membership( array( 'name' => 'Gold', 'type' => 'paid', 'amount' => 5 ) ), 'Stripe' ) && false !== strpos( UR_Abilities_Data::preview_create_membership( array( 'name' => 'Gold', 'type' => 'paid', 'amount' => 5 ) ), 'payment gateways for the plan are chosen on the plan screen' ), 'Without Stripe connected, a paid plan preview points to the plan screen for gateways instead' );
	security_assert( false !== strpos( UR_Abilities_Data::preview_create_membership( array( 'name' => 'One off', 'type' => 'paid', 'amount' => 5 ) ), '5 USD, paid once' ) && false !== strpos( UR_Abilities_Data::preview_create_membership( array( 'name' => 'Free', 'type' => 'free' ) ), '(free)' ), 'The preview says paid once, or free' );
	security_assert( array() === $saves && array() === $group_in, 'Previews save nothing' );

	// Changing a plan.
	fresh();
	$none = UR_Abilities_Data::preview_update_membership( array( 'id' => 11, 'name' => 'Gold' ) );
	security_assert( 'ur_abilities_no_change' === code( $none ) && 400 === status( $none ), 'Sending the values a plan already has changes nothing, and says so' );
	$note = UR_Abilities_Data::preview_update_membership( array( 'id' => 11, 'name' => 'Gold Plus', 'amount' => 25, 'enabled' => false ) );
	security_assert( false !== strpos( $note, 'Change the plan "Gold"' ) && false !== strpos( $note, 'name from "Gold" to "Gold Plus"' ) && false !== strpos( $note, 'price from "20" to "25"' ) && false !== strpos( $note, 'turned off' ), 'The preview lists each change as from and to' );
	security_assert( array() === $saves, 'A preview of a change saves nothing' );

	$changed = UR_Abilities_Data::update_membership( array( 'id' => 11, 'amount' => 25, 'description' => 'New text' ) );
	$sent    = $saves[0][1];
	security_assert( 'update' === $saves[0][0] && 11 === $sent['post_data']['ID'] && 'Gold' === $sent['post_data']['name'] && 'New text' === $sent['post_data']['description'] && '25' === $sent['post_meta_data']['amount'], 'A change sends the plan ID and keeps every value that was not sent' );
	security_assert( 'on' === $sent['post_meta_data']['payment_gateways']['stripe']['status'] && 'prod_SECRET' === $sent['post_meta_data']['payment_gateways']['stripe']['product_id'], 'The payment gateways of the plan are kept exactly as they were, including the Stripe ids the save code needs' );
	security_assert( '25' === $changed['amount'] && 'Gold' === $changed['name'], 'What comes back is the changed plan' );

	// A plan that members hold keeps its price, type, billing period and trial.
	fresh();
	$held = UR_Abilities_Data::update_membership( array( 'id' => 12, 'amount' => 15 ) );
	security_assert( 'ur_abilities_in_use' === code( $held ) && 409 === status( $held ) && false !== strpos( $held->get_error_message(), '3 members' ) && array() === $saves, 'A price change on a plan with members is refused with how many hold it, and nothing is saved' );
	foreach ( array( array( 'type' => 'paid' ), array( 'billing_period' => 'year' ), array( 'billing_interval' => 2 ), array( 'trial_interval' => 3, 'trial_period' => 'day' ) ) as $money ) {
		security_assert( 'ur_abilities_in_use' === code( UR_Abilities_Data::preview_update_membership( array( 'id' => 12 ) + $money ) ), 'The type, billing period and trial are held too: ' . key( $money ) );
	}
	$soft = UR_Abilities_Data::update_membership( array( 'id' => 12, 'name' => 'Monthly club', 'description' => 'Renamed', 'role' => 'author', 'enabled' => false ) );
	security_assert( 'Monthly club' === $soft['name'] && false === $soft['enabled'] && 'author' === $soft['role'] && 'update' === $saves[0][0], 'The name, description, role and on or off can still change for a plan with members' );
	security_assert( array( 'value' => 1, 'duration' => 'month' ) === $saves[0][1]['post_meta_data']['subscription'] && '9.5' === $saves[0][1]['post_meta_data']['amount'] && 'on' === $saves[0][1]['post_meta_data']['trial_status'], 'Those changes leave the price, billing period and trial of the plan untouched' );

	// A plan nobody holds can change type, and the old billing period and trial go with the old type.
	fresh();
	$GLOBALS['holders'] = array();
	$retyped            = UR_Abilities_Data::update_membership( array( 'id' => 12, 'type' => 'paid' ) );
	$sent               = $saves[0][1]['post_meta_data'];
	security_assert( 'paid' === $retyped['type'] && ! isset( $sent['subscription'] ) && ! isset( $sent['trial_status'] ) && ! isset( $sent['trial_data'] ) && null === $retyped['billing_period'], 'Changing a subscription to a one-time plan removes its billing period and trial' );
	$GLOBALS['holders'] = array( 12 => 3 );
	$pro                = false;
	security_assert( 'ur_ability_plan_required' === code( UR_Abilities_Data::update_membership( array( 'id' => 11, 'type' => 'subscription', 'billing_interval' => 1, 'billing_period' => 'month' ) ) ), 'Turning a plan into a subscription needs Pro' );
	$pro = true;
	security_assert( 'ur_abilities_not_found' === code( UR_Abilities_Data::update_membership( array( 'id' => 999, 'name' => 'X' ) ) ) && 'ur_abilities_not_found' === code( UR_Abilities_Data::update_membership( array( 'id' => 14, 'name' => 'X' ) ) ), 'A plan that does not exist cannot be changed' );

	// Plan groups.
	$groups_on = false;
	foreach ( array( 'list_membership_groups' => array(), 'create_membership_group' => array( 'name' => 'G', 'plan_ids' => array( 10 ) ), 'update_membership_group' => array( 'id' => 50, 'name' => 'G' ), 'preview_create_membership_group' => array( 'name' => 'G', 'plan_ids' => array( 10 ) ) ) as $method => $args ) {
		$locked = UR_Abilities_Data::$method( $args );
		security_assert( 'ur_ability_plan_required' === code( $locked ) && 403 === status( $locked ), 'Groups need Pro, a Plus plan and the module: ' . $method );
	}
	$groups_on = true;

	$list = UR_Abilities_Data::list_membership_groups( array( 'page' => 1, 'per_page' => 20 ) );
	security_assert( 2 === count( $list['items'] ) && 51 === $list['items'][0]['id'] && 'upgrade' === $list['items'][0]['mode'] && array( 10, 11 ) === $list['items'][1]['plan_ids'] && 'Tiers' === $list['items'][1]['name'] && false === $list['has_more'], 'Plan groups are listed newest first with their mode and plans' );

	fresh();
	foreach ( array(
		array( 'name' => ' ', 'plan_ids' => array( 10 ) ),
		array( 'name' => 'G', 'plan_ids' => array() ),
		array( 'name' => 'G', 'plan_ids' => array( 10, 999 ) ),
		array( 'name' => 'G', 'plan_ids' => array( 10, 13 ) ),
		array( 'name' => 'G', 'plan_ids' => array( 14 ) ),
		array( 'name' => 'G', 'plan_ids' => array( 10 ), 'mode' => 'upgrade' ),
	) as $bad ) {
		security_assert( 'ur_abilities_invalid_plan' === code( UR_Abilities_Data::create_membership_group( $bad ) ), 'A group needs a name, real plans that are not trashed, and a mode of single or multiple: ' . wp_json_encode( $bad ) );
	}
	security_assert( array() === $group_in, 'Nothing was saved by a refused group' );

	$name_error = UR_Abilities_Data::create_membership_group( array( 'name' => 'Tiers', 'plan_ids' => array( 10 ) ) );
	security_assert( 'ur_abilities_group_failed' === code( $name_error ) && 409 === status( $name_error ) && 'Group name must be unique.' === $name_error->get_error_message(), 'A name already taken comes back from the shared code as a 409 with its reason' );

	fresh();
	$group = UR_Abilities_Data::create_membership_group( array( 'name' => 'He said "hi"', 'description' => 'Pick <one>', 'plan_ids' => array( 11, 10, 11 ), 'mode' => 'multiple' ) );
	$sent  = $group_in[0];
	security_assert( 'He said "hi"' === $sent['post_data']['name'] && ! isset( $sent['post_data']['ID'] ) && true === $sent['post_data']['status'] && array( 11, 10 ) === $sent['post_meta_data']['memberships'] && 'multiple' === $sent['post_meta_data']['mode'], 'A group is sent as the screen sends it: quotes survive the slashing the service undoes, repeated plans count once, and it starts on' );
	security_assert( $group['id'] >= 700 && 'multiple' === $group['mode'] && array( 11, 10 ) === $group['plan_ids'] && 'He said "hi"' === $group['name'], 'What comes back is the group as saved' );
	security_assert( false !== strpos( UR_Abilities_Data::preview_create_membership_group( array( 'name' => 'Tiers 2', 'plan_ids' => array( 10, 11 ), 'mode' => 'single' ) ), '"Free", "Gold"' ) && false !== strpos( UR_Abilities_Data::preview_create_membership_group( array( 'name' => 'Tiers 2', 'plan_ids' => array( 10, 11 ), 'mode' => 'multiple' ) ), 'several of its plans at once' ), 'The group preview names the plans and says what the mode means' );

	// Changing a group.
	fresh();
	security_assert( 'ur_abilities_no_change' === code( UR_Abilities_Data::preview_update_membership_group( array( 'id' => 50, 'name' => 'Tiers', 'plan_ids' => array( 10, 11 ) ) ) ), 'A group change that changes nothing says so' );
	$unsupported = UR_Abilities_Data::update_membership_group( array( 'id' => 51, 'name' => 'Renamed' ) );
	security_assert( 'ur_abilities_unsupported' === code( $unsupported ) && 409 === status( $unsupported ) && array() === $group_in, 'A group in upgrade mode is left to the screen, and nothing is saved' );
	security_assert( 'ur_abilities_not_found' === code( UR_Abilities_Data::update_membership_group( array( 'id' => 11, 'name' => 'X' ) ) ) && 'ur_abilities_not_found' === code( UR_Abilities_Data::update_membership_group( array( 'id' => 999, 'name' => 'X' ) ) ), 'Only a plan group can be changed here' );
	$text = UR_Abilities_Data::preview_update_membership_group( array( 'id' => 50, 'plan_ids' => array( 11 ), 'mode' => 'multiple', 'enabled' => false ) );
	security_assert( false !== strpos( $text, 'Change the plan group "Tiers"' ) && false !== strpos( $text, 'mode from single to multiple' ) && false !== strpos( $text, 'plans from "Free", "Gold" to "Gold"' ) && false !== strpos( $text, 'turned off' ), 'The group preview lists each change as from and to' );
	$updated = UR_Abilities_Data::update_membership_group( array( 'id' => 50, 'plan_ids' => array( 11 ), 'enabled' => false ) );
	$sent    = $group_in[0];
	security_assert( 50 === $sent['post_data']['ID'] && 'Tiers' === $sent['post_data']['name'] && 'Choose one' === $sent['post_data']['description'] && false === $sent['post_data']['status'] && array( 11 ) === $sent['post_meta_data']['memberships'] && 'single' === $sent['post_meta_data']['mode'], 'A group change sends the group ID and keeps what was not sent' );
	security_assert( array( 11 ) === $updated['plan_ids'] && false === $updated['enabled'], 'What comes back is the changed group' );
}
