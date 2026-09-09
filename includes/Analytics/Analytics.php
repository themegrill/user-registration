<?php

namespace WPEverest\URM\Analytics;

use WPEverest\URM\Analytics\Controllers\V1\AnalyticsController;
use WPEverest\URM\Analytics\Services\MembershipService;

class Analytics {

	/**
	 * @var Analytics $instance
	 */
	private static $instance;

	/**
	 * @return Analytics
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		$this->init_hooks();
	}

	protected function __clone() {}

	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize a singleton.' );
	}

	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 40 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'user_registration_notice_excluded_pages', array( $this, 'add_excluded_page' ) );
	}

	public function add_excluded_page( $excluded_pages ) {
		$excluded_pages[] = 'user-registration-analytics';
		return $excluded_pages;
	}


	public function add_admin_menu() {
		$hook = add_submenu_page(
			'user-registration',
			__( 'User Registration Analytics', 'user-registration' ),
			__( 'Analytics', 'user-registration' ),
			'manage_user_registration',
			'user-registration-analytics',
			array( $this, 'render_analytics_root' ),
			1
		);

		add_action( 'load-' . $hook, array( $this, 'enqueue_scripts_styles' ) );
	}

	public function register_rest_routes() {
		/**
		 * @var array<int, \WP_REST_Controller> $controllers
		 */
		$controllers = apply_filters(
			'user_registration_analytics_controllers',
			array(
				AnalyticsController::class,
			)
		);
		foreach ( $controllers as $controller ) {
			$instance = new $controller();
			$instance->register_routes();
		}
	}

	public function enqueue_scripts_styles() {
		$asset_file = UR()->plugin_path() . '/chunks/analytics.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		if ( ! wp_style_is( 'ur-snackbar', 'registered' ) ) {
			wp_register_style(
				'ur-snackbar',
				UR()->plugin_url() . '/assets/css/ur-snackbar/ur-snackbar.css',
				array(),
				constant( 'UR_VERSION' )
			);
		}
		wp_enqueue_style( 'ur-snackbar' );

		wp_enqueue_style( 'sweetalert2' );

		if ( ! wp_style_is( 'ur-core-builder-style', 'registered' ) ) {
			wp_register_style(
				'ur-core-builder-style',
				UR()->plugin_url() . '/assets/css/admin.css',
				array(),
				constant( 'UR_VERSION' )
			);
		}
		wp_enqueue_style( 'ur-core-builder-style' );

		$asset = require $asset_file;

		wp_register_script( 'ur-snackbar', UR()->plugin_url() . '/assets/js/ur-snackbar/ur-snackbar' . $suffix . '.js', array(), constant( 'UR_VERSION' ), true );
		wp_enqueue_script( 'ur-snackbar' );

		wp_enqueue_script( 'ur-feature-gate' );
		wp_enqueue_style( 'ur-feature-gate' );

		wp_enqueue_script(
			'ur-analytics',
			UR()->plugin_url() . '/chunks/analytics.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'ur-analytics',
			'__UR_ANALYTICS__',
			apply_filters(
				'user_registration_analytics_localized_data',
				array(
					'install_date'  => get_option( 'user_registration_installation_date' ),
					'memberships'   => ( new MembershipService() )->get_memberships_list(),
					'currency'      => strtoupper( get_option( 'user_registration_payment_currency', 'USD' ) ),
					'utm_campaign'  => UR()->utm_campaign,
				)
			)
		);

		wp_enqueue_style(
			'ur-analytics',
			UR()->plugin_url() . '/chunks/analytics.css',
			array(),
			$asset['version']
		);
	}

	public function render_analytics_root() {
		echo user_registration_plugin_main_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div id="UR-Pro-Analytics-Root"></div>';
	}
}
