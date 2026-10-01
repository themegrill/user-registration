<?php
/**
 * User Registration - ThemeGrill SDK deactivation feedback
 *
 * @package UserRegistration\Admin
 * @since   2.3.2
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'UR_SDK_Deactivation_Feedback', false ) ) {

	/**
	 * UR_SDK_Deactivation_Feedback Class
	 */
	class UR_SDK_Deactivation_Feedback {

		/**
		 * ThemeGrill SDK product key (folder slug with underscores).
		 *
		 * Free folder `user-registration` → `user_registration`.
		 * Pro folder `user-registration-pro` → `user_registration_pro`.
		 *
		 * @var string
		 */
		private $product_key;

		/**
		 * Plugin directory slug (folder name under wp-content/plugins).
		 *
		 * @var string
		 */
		private $plugin_slug;

		/**
		 * Registers filters to customize the ThemeGrill SDK deactivation popup for User Registration.
		 */
		public function __construct() {
			$this->plugin_slug = defined( 'UR_PLUGIN_BASENAME' ) ? dirname( UR_PLUGIN_BASENAME ) : 'user-registration';
			$this->product_key = str_replace( '-', '_', strtolower( $this->plugin_slug ) );

			add_filter( $this->product_key . '_feedback_deactivate_button_submit', array( $this, 'button_submit_label' ) );
			add_filter( $this->product_key . '_feedback_deactivate_button_cancel', array( $this, 'button_cancel_label' ) );
			add_filter( $this->product_key . '_feedback_deactivate_options', array( $this, 'deactivate_options' ) );
			add_filter( $this->product_key . '_feedback_deactivate_options_skip_randomize', '__return_true' );
			add_action( 'init', array( $this, 'patch_sdk_labels_after_init' ), 15 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_popup_assets' ), 20 );
		}

		/**
		 * Submit button label.
		 *
		 * @param string $label Default label.
		 * @return string
		 */
		public function button_submit_label( $label ) {
			return __( 'Submit & Deactivate', 'user-registration' );
		}

		/**
		 * Cancel / Skip button label.
		 *
		 * @param string $label Default label.
		 * @return string
		 */
		public function button_cancel_label( $label ) {
			return __( 'Skip & Deactivate', 'user-registration' );
		}

		/**
		 * Deactivation feedback options. Labels are set via themegrill_sdk_labels.
		 *
		 * @param array $options Default plugin options (id3–id6).
		 * @return array
		 */
		public function deactivate_options( $options ) {
			return array(
				'id3'   => array(
					'id'   => 'Found_bugs_or_errors',
					'type' => 'textarea',
				),
				'id4'   => array(
					'id'   => 'Too_complex_or_confusing_to_use',
					'type' => 'textarea',
				),
				'id5'   => array(
					'id'   => 'no_longer_needed',
					'type' => 'textarea',
				),
				'id999' => array(
					'id'   => 'Other',
					'type' => 'textarea',
				),
			);
		}

		/**
		 * Labels for deactivation options.
		 *
		 * @param array $labels ThemeGrill SDK labels.
		 * @return array
		 */
		public function deactivate_options_labels( $labels ) {
			if ( ! isset( $labels['uninstall']['options'] ) || ! is_array( $labels['uninstall']['options'] ) ) {
				return $labels;
			}

			$labels['uninstall']['heading_plugin']   = __( 'Why are you parting ways with the URM plugin?', 'user-registration' );
			$labels['uninstall']['options']['id3']   = array(
				'title'       => __( 'Found bugs or errors', 'user-registration' ),
				'placeholder' => __( 'What bugs did you run into?', 'user-registration' ),
			);
			$labels['uninstall']['options']['id4']   = array(
				'title'       => __( 'Too complex or confusing to use', 'user-registration' ),
				'placeholder' => __( 'What feature felt confusing?', 'user-registration' ),
			);
			$labels['uninstall']['options']['id5']   = array(
				'title'       => __( 'I no longer need the plugin', 'user-registration' ),
				'placeholder' => 'Anything we can improve on our end?',
			);
			$labels['uninstall']['options']['id999'] = array(
				'title'       => __( 'Other', 'user-registration' ),
				'placeholder' => __( 'Tell us your reason', 'user-registration' ),
			);

			return $labels;
		}

		/**
		 * Enqueue CSS and JS for URM deactivation popup design (plugins screen only).
		 */
		public function enqueue_popup_assets() {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
				return;
			}
			$base = ur()->plugin_url() . '/assets/';
			wp_enqueue_style(
				'urm-deactivation-popup',
				$base . 'css/urm-deactivation-popup.css',
				array(),
				UR_VERSION
			);
			wp_enqueue_script(
				'urm-deactivation-popup',
				$base . 'js/admin/urm-deactivation-popup.js',
				array( 'jquery' ),
				UR_VERSION,
				true
			);
			wp_localize_script(
				'urm-deactivation-popup',
				'urmDeactivationPopup',
				array(
					'pluginUrl'         => ur()->plugin_url() . '/',
					'logoUrl'           => ur()->plugin_url() . '/assets/images/logo.png',
					'quickFeedback'     => __( 'Quick Feedback', 'user-registration' ),
					'disclaimer'        => __( '* By submitting this form, you will send us non-sensitive diagnostic data, site URL and email.', 'user-registration' ),
					'popupId'          => $this->plugin_slug . '_uninstall_feedback_popup',
				)
			);
		}

		/**
		 * Patch ThemeGrill SDK labels after init so custom option labels are applied.
		 * The SDK applies themegrill_sdk_labels in Loader::init() on init priority 10.
		 * This runs at priority 15 and directly sets Loader::$labels so our options show correctly.
		 */
		public function patch_sdk_labels_after_init() {
			if ( ! class_exists( 'ThemeGrillSDK\Loader' ) ) {
				return;
			}
			\ThemeGrillSDK\Loader::$labels['uninstall']['heading_plugin'] = __( 'Why are you parting ways with the URM plugin?', 'user-registration' );
			$our_options = array(
				'id3'   => array(
					'title'       => __( 'Found bugs or errors', 'user-registration' ),
					'placeholder' => __( 'What bugs did you run into?', 'user-registration' ),
				),
				'id4'   => array(
					'title'       => __( 'Too complex or confusing to use', 'user-registration' ),
					'placeholder' => __( 'What feature felt confusing?', 'user-registration' ),
				),
				'id5'   => array(
					'title'       => __( 'I no longer need the plugin', 'user-registration' ),
					'placeholder' => 'Anything we can improve on our end?',
				),
				'id999' => array(
					'title'       => __( 'Other', 'user-registration' ),
					'placeholder' => __( 'Tell us your reason', 'user-registration' ),
				),
			);
			if ( isset( \ThemeGrillSDK\Loader::$labels['uninstall']['options'] ) && is_array( \ThemeGrillSDK\Loader::$labels['uninstall']['options'] ) ) {
				// Keep only our option keys so Pro does not keep default SDK reasons (e.g. temporary deactivation).
				\ThemeGrillSDK\Loader::$labels['uninstall']['options'] = $our_options;
			}
		}
	}

	new UR_SDK_Deactivation_Feedback();
}
