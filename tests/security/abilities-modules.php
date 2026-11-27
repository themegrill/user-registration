<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test double shares this file.
/**
 * Regression: AI assistants can list the modules and addons, and turn them on or off, but only as far as the site's
 * plan allows. A locked one is refused with the plan it needs, an addon that is not installed is never installed, and
 * payment gateways are left to the admin. Turning something off shows a preview first. The calls go through the same
 * code as the Modules and Addons screen, so nothing is copied.
 *
 * Runs the real `UR_Abilities_Data` module methods and `UR_Abilities` plan check with a stand-in for the Modules
 * screen code; no live site or database is used.
 *
 * @package UserRegistration/Tests
 */

// Test doubles and fixture inputs intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/abilities-activity-stubs.php';

$plugins_dir = sys_get_temp_dir() . '/ur-modules-test-' . getmypid();
mkdir( $plugins_dir . '/user-registration-advanced-fields', 0777, true );
file_put_contents( $plugins_dir . '/user-registration-advanced-fields/user-registration-advanced-fields.php', '<?php' );
define( 'WP_PLUGIN_DIR', $plugins_dir );

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class WP_REST_Response {
	private $data;

	public function __construct( $data = null ) {
		$this->data = $data;
	}

	public function get_data() {
		return $this->data;
	}
}

class UR_Modules {
	public static $calls = array();

	public static function module( $slug, $title, $type, $status, $plan ) {
		return (object) array(
			'slug'   => $slug,
			'title'  => $title,
			'type'   => $type,
			'status' => $status,
			'plan'   => $plan,
		);
	}

	public static function ur_get_modules() {
		return new WP_REST_Response(
			array(
				'success'       => true,
				'modules_lists' => $GLOBALS['mods'],
			)
		);
	}

	private static function flip( $slug, $status ) {
		foreach ( $GLOBALS['mods'] as $mod ) {
			if ( $mod->slug === $slug ) {
				$mod->status = $status;
			}
		}
	}

	public static function ur_enable_feature( $slug ) {
		self::$calls[] = array( 'enable', $slug );
		if ( $GLOBALS['fail'] ) {
			return array( 'success' => false );
		}
		self::flip( $slug, 'active' );
		return array( 'success' => true );
	}

	public static function ur_disable_feature( $slug ) {
		self::$calls[] = array( 'disable', $slug );
		if ( $GLOBALS['fail'] ) {
			return array( 'success' => false );
		}
		self::flip( $slug, 'inactive' );
		return array( 'success' => true );
	}

	public static function ur_install_addons( $slug, $name, $plugin ) {
		self::$calls[] = array( 'install_addons', $slug, $name, $plugin );
		if ( $GLOBALS['fail'] ) {
			return array(
				'success'      => false,
				'errorMessage' => 'The addon has a fatal error.',
			);
		}
		self::flip( $slug, 'active' );
		return array( 'success' => true );
	}

	public static function ur_deactivate_addon( $plugin ) {
		self::$calls[] = array( 'deactivate_addon', $plugin );
		self::flip( dirname( $plugin ), 'inactive' );
		return array( 'success' => true );
	}
}

$mods      = array();
$fail      = false;
$site_plan = '';

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function ur_get_license_plan() {
	return $GLOBALS['site_plan'] ? (object) array( 'item_plan' => $GLOBALS['site_plan'] ) : false;
}
function ur_utm_url( $url, $args ) {
	return $url . '?content=' . $args['content'];
}

require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-data.php';
require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities.php';

function fresh_modules() {
	UR_Modules::$calls = array();
	$GLOBALS['fail']   = false;
	$GLOBALS['mods']   = array(
		UR_Modules::module( 'user-registration-membership', 'Membership', 'feature', 'active', array( 'free' ) ),
		UR_Modules::module( 'user-registration-multiple-registration', 'Multiple Registration', 'feature', 'inactive', array( 'free' ) ),
		UR_Modules::module( 'user-registration-calculations', '<b>Calculations</b>', 'feature', 'inactive', array( 'plus', 'professional', 'themegrill agency' ) ),
		UR_Modules::module( 'user-registration-coupon', 'Coupons', 'feature', 'inactive', array( 'personal', 'plus', 'professional', 'themegrill agency' ) ),
		UR_Modules::module( 'user-registration-stripe', 'Stripe', 'feature', 'inactive', array( 'free' ) ),
		UR_Modules::module( 'user-registration-advanced-fields', 'Advanced Fields', 'addon', 'inactive', array( 'personal', 'plus', 'professional', 'themegrill agency' ) ),
		UR_Modules::module( 'user-registration-file-upload', 'File Upload', 'addon', 'not-installed', array( 'personal', 'plus', 'professional', 'themegrill agency' ) ),
		UR_Modules::module( 'user-registration-paypal-addon', 'PayPal addon', 'addon', 'inactive', array( 'free' ) ),
	);
}

function items_by_slug( $page ) {
	return array_column( $page['items'], null, 'slug' );
}

// The list: modules and addons with status, the lowest plan and whether this site's plan allows them.
fresh_modules();
$page  = UR_Abilities_Data::list_modules( array() );
$items = items_by_slug( $page );
security_assert( 8 === count( $page['items'] ) && false === $page['has_more'], 'Every module and addon is listed' );
security_assert( 'module' === $items['user-registration-calculations']['type'] && 'addon' === $items['user-registration-file-upload']['type'], 'A feature is a module, and addons stay addons' );
security_assert( 'Calculations' === $items['user-registration-calculations']['name'], 'A name has its markup removed' );
security_assert( 'plus' === $items['user-registration-calculations']['required_plan'] && 'personal' === $items['user-registration-coupon']['required_plan'] && 'free' === $items['user-registration-membership']['required_plan'], 'The lowest plan that includes it is named' );
security_assert( 'not-installed' === $items['user-registration-file-upload']['status'] && 'active' === $items['user-registration-membership']['status'], 'The status is active, inactive or not installed' );
security_assert( ! isset( $items['user-registration-membership']['plans'] ), 'The list of plans is not part of the answer' );
security_assert( true === $items['user-registration-membership']['available'] && false === $items['user-registration-calculations']['available'], 'A site with no license can use only the free ones' );

$GLOBALS['site_plan'] = ' Plus';
$items                = items_by_slug( UR_Abilities_Data::list_modules( array() ) );
security_assert( true === $items['user-registration-calculations']['available'] && true === $items['user-registration-coupon']['available'], 'A plan with a stray space and capitals still matches' );
$GLOBALS['site_plan'] = 'agency';
$items                = items_by_slug( UR_Abilities_Data::list_modules( array() ) );
security_assert( true === $items['user-registration-calculations']['available'], 'The Agency license matches the themegrill agency plan' );
$GLOBALS['site_plan'] = 'personal';
$items                = items_by_slug( UR_Abilities_Data::list_modules( array() ) );
security_assert( true === $items['user-registration-coupon']['available'] && false === $items['user-registration-calculations']['available'], 'A lower plan does not unlock a higher one' );
$GLOBALS['site_plan'] = '';

// Filters and paging.
$addons = UR_Abilities_Data::list_modules( array( 'type' => 'addon' ) );
security_assert( 3 === count( $addons['items'] ) && array( 'addon' ) === array_unique( array_column( $addons['items'], 'type' ) ), 'The list can be limited to addons' );
$active = UR_Abilities_Data::list_modules( array( 'status' => 'active' ) );
security_assert( array( 'user-registration-membership' ) === array_column( $active['items'], 'slug' ), 'The list can be limited to a status' );
$first = UR_Abilities_Data::list_modules(
	array(
		'page'     => 1,
		'per_page' => 3,
	)
);
$last  = UR_Abilities_Data::list_modules(
	array(
		'page'     => 3,
		'per_page' => 3,
	)
);
security_assert( 3 === count( $first['items'] ) && true === $first['has_more'] && 2 === count( $last['items'] ) && false === $last['has_more'], 'The list is paged and says when there is more' );

// Turning one on.
fresh_modules();
$result = UR_Abilities_Data::enable_module( array( 'slug' => 'user-registration-multiple-registration' ) );
security_assert( 'active' === $result['status'] && array( array( 'enable', 'user-registration-multiple-registration' ) ) === UR_Modules::$calls, 'A free module is turned on through the Modules screen code, once' );
UR_Modules::$calls = array();
$again             = UR_Abilities_Data::enable_module( array( 'slug' => 'user-registration-multiple-registration' ) );
security_assert( 'active' === $again['status'] && array() === UR_Modules::$calls, 'Turning on something already on changes nothing' );

fresh_modules();
$locked = UR_Abilities_Data::enable_module( array( 'slug' => 'user-registration-calculations' ) );
security_assert( is_wp_error( $locked ) && 'ur_ability_plan_required' === $locked->code && 403 === $locked->data['status'], 'A module the plan does not include is refused with the plan error' );
security_assert( array( 'plus', 'professional', 'themegrill agency' ) === $locked->data['required_plans'] && false !== strpos( $locked->data['upgrade_url'], 'content=user-registration-calculations' ), 'The error names the plans and carries the upgrade link' );
security_assert( array() === UR_Modules::$calls, 'A locked module is never turned on' );
security_assert( 'plus' === $locked->data['recommended_plan'] && false !== strpos( $locked->message, 'Your plan does not include Calculations. Upgrade to the Plus plan or a higher one' ) && false !== strpos( $locked->message, $locked->data['upgrade_url'] ), 'The message says the plan does not allow it, recommends the plan to buy and carries the link' );

// A paid addon that is not installed is refused for the plan first, and nothing is installed.
$paid = UR_Abilities_Data::enable_module( array( 'slug' => 'user-registration-file-upload' ) );
security_assert( is_wp_error( $paid ) && 'ur_ability_plan_required' === $paid->code && 'personal' === $paid->data['recommended_plan'] && false !== strpos( $paid->message, 'Upgrade to the Personal plan' ) && array() === UR_Modules::$calls, 'A paid addon on a site without the plan is refused with the plan to buy, and nothing is installed' );

$unknown = UR_Abilities_Data::enable_module( array( 'slug' => 'nope' ) );
security_assert( is_wp_error( $unknown ) && 'ur_abilities_not_found' === $unknown->code && 404 === $unknown->data['status'], 'An unknown slug is a 404' );

foreach ( array( 'user-registration-stripe', 'user-registration-paypal-addon' ) as $payment ) {
	$protected = UR_Abilities_Data::enable_module( array( 'slug' => $payment ) );
	$off       = UR_Abilities_Data::disable_module( array( 'slug' => $payment ) );
	$preview   = UR_Abilities_Data::preview_disable_module( array( 'slug' => $payment ) );
	security_assert( is_wp_error( $protected ) && 'ur_abilities_module_protected' === $protected->code && 403 === $protected->data['status'] && is_wp_error( $off ) && is_wp_error( $preview ), 'The payment gateway ' . $payment . ' is left to the admin, for on, off and the preview' );
}
security_assert( array() === UR_Modules::$calls, 'A payment gateway is never touched' );

$GLOBALS['fail'] = true;
$failed           = UR_Abilities_Data::enable_module( array( 'slug' => 'user-registration-multiple-registration' ) );
security_assert( is_wp_error( $failed ) && 'ur_abilities_module_failed' === $failed->code && 500 === $failed->data['status'], 'A module that cannot be turned on answers with a fault' );

// Addons: only installed ones are switched on, and nothing is ever installed.
fresh_modules();
$GLOBALS['site_plan'] = 'plus';
$result               = UR_Abilities_Data::enable_module( array( 'slug' => 'user-registration-advanced-fields' ) );
security_assert( 'active' === $result['status'] && array( array( 'install_addons', 'user-registration-advanced-fields', 'Advanced Fields', 'user-registration-advanced-fields/user-registration-advanced-fields.php' ) ) === UR_Modules::$calls, 'An installed addon is activated through the same code as the Activate button' );

UR_Modules::$calls = array();
$missing           = UR_Abilities_Data::enable_module( array( 'slug' => 'user-registration-file-upload' ) );
security_assert( is_wp_error( $missing ) && 'ur_abilities_addon_not_installed' === $missing->code && 400 === $missing->data['status'] && array() === UR_Modules::$calls, 'An addon that is not installed is never installed' );

fresh_modules();
$GLOBALS['mods'][5]->status = 'inactive';
$GLOBALS['mods'][6]->status = 'inactive';
$ghost                      = UR_Abilities_Data::enable_module( array( 'slug' => 'user-registration-file-upload' ) );
security_assert( is_wp_error( $ghost ) && 'ur_abilities_addon_not_installed' === $ghost->code && array() === UR_Modules::$calls, 'An addon listed as inactive with no plugin file is still not installed' );

fresh_modules();
$GLOBALS['fail'] = true;
$broken          = UR_Abilities_Data::enable_module( array( 'slug' => 'user-registration-advanced-fields' ) );
security_assert( is_wp_error( $broken ) && 'The addon has a fatal error.' === $broken->message, 'The reason an addon could not be activated is passed on' );
$GLOBALS['site_plan'] = '';

// Turning one off.
fresh_modules();
$GLOBALS['mods'][1]->status = 'active';
$text                       = UR_Abilities_Data::preview_disable_module( array( 'slug' => 'user-registration-multiple-registration' ) );
security_assert( 0 === strpos( $text, 'Turn off the Multiple Registration module.' ) && array() === UR_Modules::$calls, 'The preview says what would stop and changes nothing' );
$GLOBALS['mods'][5]->status = 'active';
security_assert( 0 === strpos( UR_Abilities_Data::preview_disable_module( array( 'slug' => 'user-registration-advanced-fields' ) ), 'Deactivate the Advanced Fields addon.' ), 'An addon preview says deactivate' );
security_assert( 'Coupons is already off. Nothing would change.' === UR_Abilities_Data::preview_disable_module( array( 'slug' => 'user-registration-coupon' ) ), 'The preview says so when it is already off' );

$off = UR_Abilities_Data::disable_module( array( 'slug' => 'user-registration-multiple-registration' ) );
security_assert( 'inactive' === $off['status'] && array( array( 'disable', 'user-registration-multiple-registration' ) ) === UR_Modules::$calls, 'A module is turned off through the Modules screen code' );

UR_Modules::$calls = array();
UR_Abilities_Data::disable_module( array( 'slug' => 'user-registration-advanced-fields' ) );
security_assert( array( array( 'deactivate_addon', 'user-registration-advanced-fields/user-registration-advanced-fields.php' ) ) === UR_Modules::$calls, 'An addon is deactivated through the same code as the Deactivate button' );

UR_Modules::$calls = array();
$idle              = UR_Abilities_Data::disable_module( array( 'slug' => 'user-registration-coupon' ) );
security_assert( 'inactive' === $idle['status'] && array() === UR_Modules::$calls, 'Turning off something already off changes nothing' );

fresh_modules();
$GLOBALS['mods'][1]->status = 'active';
$GLOBALS['fail']            = true;
$stuck                      = UR_Abilities_Data::disable_module( array( 'slug' => 'user-registration-multiple-registration' ) );
security_assert( is_wp_error( $stuck ) && 400 === $stuck->data['status'] && false !== strpos( $stuck->message, 'multiple registration forms' ), 'Multiple Registration cannot be turned off while forms depend on it, with the screen\'s reason' );

// The area as the registration sees it.
$area = UR_Abilities::get_areas()['modules'];
$defs = $area['abilities'];
security_assert( array( 'list-modules', 'enable-module', 'disable-module' ) === array_keys( $defs ), 'The Modules and addons area has the three abilities' );
security_assert( 'read' === UR_Abilities::level( $defs['list-modules'] ) && 'write' === UR_Abilities::level( $defs['enable-module'] ) && 'write' === UR_Abilities::level( $defs['disable-module'] ), 'Listing is a read, turning on and off are changes' );
security_assert( ! empty( $defs['disable-module']['confirm'] ) && is_callable( $defs['disable-module']['preview'] ) && empty( $defs['enable-module']['confirm'] ), 'Turning off asks for a preview, turning on does not' );
security_assert( array( 'UR_Abilities', 'modules_permission' ) === $defs['enable-module']['permission'] && is_callable( $defs['list-modules']['permission'] ), 'The abilities use the capability of the Modules screen' );

fresh_modules();
$one = UR_Abilities_Data::enable_module( array( 'slug' => 'user-registration-multiple-registration' ) );
security_assert( array_keys( $one ) === $defs['enable-module']['output']['required'], 'An answer has exactly the fields its schema promises' );
security_assert( array_keys( UR_Abilities_Data::list_modules( array() )['items'][0] ) === array_keys( $defs['list-modules']['output']['properties']['items']['items']['properties'] ), 'A list entry has exactly the fields its schema promises' );

// The plan check helpers.
security_assert( UR_Abilities::plan_allows( array() ) && UR_Abilities::plan_allows( array( 'Free' ) ) && ! UR_Abilities::plan_allows( array( 'plus' ) ), 'An empty or free list allows every site, a paid list does not' );

foreach ( glob( $plugins_dir . '/*/*' ) as $file ) {
	unlink( $file );
}
foreach ( glob( $plugins_dir . '/*' ) as $dir ) {
	rmdir( $dir );
}
rmdir( $plugins_dir );
