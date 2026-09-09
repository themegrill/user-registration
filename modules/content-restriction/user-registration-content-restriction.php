<?php
defined( 'ABSPATH' ) || exit;

/**
 * Main User_Registration_Content_Restriction Class.
 *
 * @class   User_Registration_Content_Restriction
 * @version 4.0
 */
class User_Registration_Content_Restriction {
	/**
	 * FlashToolkit Constructor.
	 */
	public function __construct() {

		$this->define( 'URCR_TEMPLATES_DIR', UR()->plugin_path() . '/templates/modules/content-restriction/' );

		$this->includes();
	}

	/**
	 * Define constant if not already set.
	 *
	 * @param string      $name
	 * @param string|bool $value
	 */
	private function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * What type of request is this?
	 *
	 * @param  string $type admin, ajax, cron or frontend.
	 *
	 * @return bool
	 */
	private function is_request( $type ) {
		switch ( $type ) {
			case 'admin':
				return is_admin();
			case 'ajax':
				return defined( 'DOING_AJAX' );
			case 'cron':
				return defined( 'DOING_CRON' );
			case 'frontend':
				return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
		}
	}

	/**
	 * Includes.
	 */
	private function includes() {

		if ( $this->is_request( 'admin' ) ) {
			include_once __DIR__ . '/admin/class-urcr-admin-meta-boxes.php';
		}

		include_once __DIR__ . '/functions-urcr-core.php';

		include_once __DIR__ . '/class-urcr-post-types.php';
		include_once __DIR__ . '/class-urcr-shortcodes.php';

		include_once __DIR__ . '/includes/RestApi/class-urcr-rest-api.php';

		include_once __DIR__ . '/class-urcr-rest-restriction.php';

		if ( $this->is_request( 'admin' ) ) {

			include_once __DIR__ . '/admin/class-urcr-admin-assets.php';

			include_once __DIR__ . '/admin/class-urcr-admin.php';
		}

		if ( $this->is_request( 'frontend' ) ) {

			include_once __DIR__ . '/class-urcr-frontend.php';
		}
	}
}

new User_Registration_Content_Restriction();
