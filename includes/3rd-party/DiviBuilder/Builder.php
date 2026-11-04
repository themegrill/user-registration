<?php
namespace WPEverest\URM\DiviBuilder;

use WPEverest\URM\DiviBuilder\Modules\ContentRestriction;
use WPEverest\URM\DiviBuilder\Modules\RegistrationForm;
use WPEverest\URM\DiviBuilder\Modules\LoginForm;
use WPEverest\URM\DiviBuilder\Modules\MyAccount;
use WPEverest\URM\DiviBuilder\Modules\EditPassword;
use WPEverest\URM\DiviBuilder\Modules\EditProfile;
use WPEverest\URM\DiviBuilder\Modules\MembershipGroups;
use WPEverest\URM\DiviBuilder\Modules\MembershipThankYou;

if ( file_exists( UR()->plugin_path() . '/vendor/autoload.php' ) ) {
	require_once UR()->plugin_path() . '/vendor/autoload.php';
}

defined( 'ABSPATH' ) || exit;

/**
 * Builder.
 *
 * @since xx.xx.xx
 */
class Builder {

	/**
	 * Holds single instance of the class.
	 *
	 * @var null|static
	 */
	private static $instance = null;

	/**
	 * Get instance of the class.
	 *
	 * @return static
	 */
	final public static function init() {
		if ( is_null( static::$instance ) ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since xx.xx.xx
	 */
	public function __construct() {
		$this->setup();
	}
	/**
	 * Init.
	 *
	 * @since xx.xx.xx
	 */
	public function setup() {

		if ( ! function_exists( 'urm_is_divi_active' ) || ! urm_is_divi_active() ) {
			return;
		}

		/*
		 * ET_BUILDER_5_DIR is defined by the Divi theme's functions.php.
		 * WordPress loads plugins before themes, so the constant is not
		 * available yet when this method runs. Defer the D4/D5 decision
		 * to after_setup_theme (priority 20), which fires after the theme
		 * has fully loaded and defined its constants.
		 */
		add_action( 'after_setup_theme', array( $this, 'init_divi_integration' ), 20 );
	}

	/**
	 * Route to D4 or D5 registration once the theme constants are available.
	 *
	 * In Divi 5, both registrations are needed:
	 * - D5 registration: native blocks (no "Legacy" badge in VB)
	 * - D4 registration: required so the VB Insert dialog includes our modules
	 *   (Divi 5 populates its module list from ET_Builder_Module subclasses)
	 *
	 * In Divi 4, only D4 registration is used.
	 *
	 * @since xx.xx.xx
	 */
	public function init_divi_integration() {
		if ( urm_is_divi5_active() ) {
			D5\Builder::init();
		}
		// Register URM Divi shortcodes on init so they are available on the
		// frontend. In Divi 5 et_builder_ready does not fire on non-VB page
		// loads, so shortcodes registered only inside register_divi_builder()
		// would never be processed and would appear as raw text.
		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'et_builder_ready', array( $this, 'register_divi_builder' ) );
	}

	/**
	 * Register URM Divi module shortcodes with WordPress.
	 *
	 * Called on the `init` hook so shortcodes are available on every page
	 * load — including Divi 5 frontend requests where et_builder_ready never
	 * fires and ET_Builder_Module does not register them automatically.
	 *
	 * @since xx.xx.xx
	 */
	public function register_shortcodes(): void {
		add_shortcode( 'urm-content-restriction', array( ContentRestriction::class, 'render_module' ) );
	}

	/**
	 * Function to check whether the divi module is loaded or not.
	 *
	 * @since xx.xx.xx
	 */
	public function register_divi_builder() {
		if ( ! class_exists( 'ET_Builder_Module' ) ) {
			return;
		}

		$modules = apply_filters(
			'urm_divi_modules',
			array(
				'registration-form'    => RegistrationForm::class,
				'login-form'           => LoginForm::class,
				'myaccount'            => MyAccount::class,
				'edit-profile'         => EditProfile::class,
				'edit-password'        => EditPassword::class,
				'membership-groups'    => MembershipGroups::class,
				'membership-thank-you' => MembershipThankYou::class,
				'content-restriction'  => ContentRestriction::class,
			)
		);

		foreach ( $modules as $module ) {
			new $module();
		}
	}
}

// Lint pilot test.
