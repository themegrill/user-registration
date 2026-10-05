<?php
/**
 * UR_FORMBRICKS Class to collect formbricks data.
 *
 * Explore more what information is shared https://docs.wpuserregistration.com/docs/miscellaneous-settings/#1-toc-title
 *
 * @package User_Registration
 * @since  xx.xx.xx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UR_FORMBRICKS' ) ) {

	/**
	 * UR_FORMBRICKS class.
	 */
	class UR_FORMBRICKS {

		/**
		 * Environment ID for Formbricks.
		 */
		const ENVIRONMENT_ID = 'cmi4emqjs0j7mad01823vxb2e';

		/**
		 * Boot the formbricks service provider.
		 * Registers block types, categories, and editor assets.
		 *
		 * @since xx.xx.xx
		 * @return void
		 */
		public function __construct() {
			add_filter( 'themegrill_sdk_products', array( $this, 'add_product' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'declare_internal_pages' ) );
			add_filter( 'themegrill-sdk/survey/user-registration', array( $this, 'configure_formbricks' ), 10, 2 );
		}

		/**
		 * Add the product.
		 *
		 * @param mixed $product The product.
		 * @return array
		 */
		public function add_product( $product ) {
			$product[] = UR_PLUGIN_FILE;

			return $product;
		}

		/**
		 * Declares internal pages for the plugin by triggering the 'themeisle_internal_page' action.
		 *
		 * This method fires the 'themeisle_internal_page' action hook with the plugin's slug and
		 * the top-level page identifier. It is used to register or declare internal admin pages
		 * for the plugin within the WordPress admin dashboard.
		 *@since xx.xx.xx
		* @return void
		*/
		public function declare_internal_pages() {
			if ( ! is_admin() ) {
				return;
			}

			$page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $this->is_page_valid() ) {
				do_action( 'themegrill_internal_page', 'user-registration', $page );
			}
		}

		/**
		 * Check page validity.
		 *
		 * @return boolean
		 */
		public function is_page_valid() {
			if ( ! is_admin() ) {
				return false;
			}

			$screen = get_current_screen();

			if ( ! $screen ) {
				return false;
			}

			$current_screen_id = $screen->id;

			$urm_screen_list = $this->supported_screen_ids();

			return in_array( $current_screen_id, $urm_screen_list, true );
		}

		/**
		 * Supported page list.
		 *
		 * @return void
		 */
		public function supported_screen_ids() {
			if ( ! function_exists( 'ur_get_screen_ids' ) ) {
				include_once untrailingslashit( plugin_dir_path( UR_PLUGIN_FILE ) ) . '/includes/admin/functions-ur-admin.php';
			}

			$all_screen_id = ur_get_screen_ids();

			return array_diff( $all_screen_id, array( 'profile', 'user-edit' ) );
		}

		/**
		 * Configures Formbricks survey data based on the provided page slug.
		 *
		 * @param array  $data      Existing data to be configured.
		 * @param string $page_slug The slug of the current page.
		 *@since xx.xx.xx
			* @return array Modified data with Formbricks survey information if applicable.
			*/
		public function configure_formbricks( $data, $page_slug ) {

			if ( empty( $page_slug ) ) {
				return $data;
			}
			$survey_data = array(
				'environmentId' => self::ENVIRONMENT_ID,
				'attributes'    => array(
					'free_version'        => UR()->version,
					'install_days_number' => (int) $this->get_install_days(),
					'is_premium'          => $this->is_premium(),
				),
			);

			return $survey_data;
		}

		/**
		 * Calculates the number of days since the plugin was installed.
		 *
		 * Retrieves the installation date from the 'user_registration_installation_date' option.
		 * If the value is not numeric, it attempts to convert it to a timestamp.
		 * Returns the number of full days elapsed since installation.
		 *
		 *  @since xx.xx.xx
		 * @return int Number of days since the plugin was installed.
		 */
		private function get_install_days() {
			$install_time = get_option( 'user_registration_installation_date', time() );
			if ( ! is_numeric( $install_time ) ) {
				$install_time = strtotime( $install_time );
			}
			$current_time       = time();
			$days_since_install = floor( ( $current_time - $install_time ) / DAY_IN_SECONDS );

			return $days_since_install;
		}

		/**
		 * Checks if the premium version of the User Registration plugin is active.
		 *
		 * This method determines whether the 'user-registration-pro/user-registration.php' plugin
		 * is currently active. Returns true if the premium plugin is active, otherwise false.
		 *
		 * @since xx.xx.xx
		 * @return bool True if the premium plugin is active, false otherwise.
		 */
		private function is_premium() {
			return is_plugin_active( 'user-registration-pro/user-registration.php' );
		}
	}
}
new UR_FORMBRICKS();
