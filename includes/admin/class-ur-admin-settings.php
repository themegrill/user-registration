<?php
/**
 * UserRegistration Admin Settings Class
 *
 * @class    UR_Admin_Settings
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Admin_Settings Class.
 */
class UR_Admin_Settings {

	/**
	 * Setting pages.
	 *
	 * @var array
	 */
	private static $settings = array();

	/**
	 * Error messages.
	 *
	 * @var array
	 */
	private static $errors = array();

	/**
	 * Update messages.
	 *
	 * @var array
	 */
	private static $messages = array();

	/**
	 * Output messages + errors.
	 *
	 * @echo string
	 */
	public static function show_messages() {
		if ( count( self::$errors ) > 0 ) {
			foreach ( self::$errors as $key => $error ) {
				echo '<div id="' . esc_attr( 'message-' . $key ) . '" class="inline error"><p><strong>'
				. wp_kses_post( $error )  // second layer — defence in depth
				. '</strong></p></div>';
			}
		} elseif ( count( self::$messages ) > 0 ) {
			foreach ( self::$messages as $key => $message ) {
				echo '<div id="' . esc_attr( 'message-' . $key ) . '" class="inline updated"><p><strong>'
				. wp_kses_post( $message ) // second layer — defence in depth
				. '</strong></p></div>';
			}
		}
	}

	/**
	 * Settings page.
	 *
	 * Handles the display of the main Social Sharing settings page in admin.
	 */
	public static function output() {
		global $current_section, $current_tab;
		global $current_section_part;

		$suffix                 = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$current_tab_for_assets = empty( $_GET['tab'] ) ? 'general' : sanitize_title( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		/**
		 * Action to output start settings
		 */
		do_action( 'user_registration_settings_start' );
		wp_register_script( 'ur-snackbar', UR()->plugin_url() . '/assets/js/ur-snackbar/ur-snackbar' . $suffix . '.js', array(), UR_VERSION, true );

		wp_enqueue_script(
			'user-registration-settings',
			UR()->plugin_url() . '/assets/js/admin/settings' . $suffix . '.js',
			array(
				'jquery',
				'jquery-ui-datepicker',
				'jquery-ui-sortable',
				'iris',
				'tooltipster',
				'ur-snackbar',
			),
			UR_VERSION,
			true
		);
		wp_enqueue_script( 'ur-setup' );
		wp_enqueue_style( 'ur-toast' );
		if ( ! wp_style_is( 'ur-snackbar', 'registered' ) ) {
			wp_register_style( 'ur-snackbar', UR()->plugin_url() . '/assets/css/ur-snackbar/ur-snackbar.css', array(), UR_VERSION );
		}

		wp_add_inline_script(
			'user-registration-settings',
			"
			(function($) {
				'use strict';
				// Initialize multiselect-v2 with Select2
				function initMultiselectV2() {
					$('.ur-multiselect-v2').each(function() {
						var \$select = $(this);
						// Destroy existing Select2 instance if any
						if (\$select.hasClass('select2-hidden-accessible')) {
							\$select.select2('destroy');
						}
						// Initialize Select2
						\$select.select2({
							dropdownAutoWidth: true,
							containerCss: { display: 'block' },
							width: '100%',
							placeholder: \$select.data('placeholder') || 'Select an option...',
							allowClear: false
						});
					});
				}
				$(document).ready(function() {
					initMultiselectV2();
				});
			})(jQuery);
			"
		);

		if ( 'payment' === $current_tab_for_assets ) {
			wp_enqueue_script( 'tippy' );
			if ( ! wp_style_is( 'ur-membership-admin-style', 'registered' ) ) {
				wp_register_style( 'ur-membership-admin-style', UR()->plugin_url() . '/assets/css/modules/membership/user-registration-membership-admin.css', array(), UR_VERSION );
			}
			wp_enqueue_style( 'ur-membership-admin-style' );
			wp_add_inline_script(
				'tippy',
				'jQuery(document).ready(function() {
					var el = document.querySelectorAll("[data-gate-content]");
					if (el.length && typeof window.tippy !== "undefined") {
						tippy.setDefaultProps({ maxWidth: "280px" });
						el.forEach(function(ref) {
							var placement = ref.getAttribute("data-gate-placement") || "right";
							tippy(ref, {
								content: function() {
									var id = ref.getAttribute("data-gate-content");
									var template = document.getElementById(id);
									if (!template || !template.content) return "";
									var div = document.createElement("div");
									div.appendChild(template.content.cloneNode(true));
									return div.innerHTML;
								},
								allowHTML: true,
								interactive: true,
								placement: placement,
								inertia: true,
								theme: "ur-gate"
							});
						});
					}
				});'
			);
		}

		wp_add_inline_style(
			'ur-snackbar',
			'
			.ur-multiselect-v2.select2-container {
				width: 100% !important;
			}
			.ur-multiselect-v2.select2-container .select2-selection {
				border: 1px solid #d0d0d0;
				border-radius: 4px;
				background: #f5f5f5;
				min-height: 40px;
				padding: 4px 8px;
			}
			.ur-multiselect-v2.select2-container.select2-container--focus .select2-selection {
				border-color: #2271b1;
				background: #fff;
			}
			.ur-multiselect-v2.select2-container .select2-selection__choice {
				background: #e8e8e8;
				border: 1px solid #c0c0c0;
				border-radius: 3px;
				padding: 4px 8px;
				margin: 2px 4px 2px 0;
				font-size: 13px;
				color: #333;
			}
			.ur-multiselect-v2.select2-container .select2-selection__choice__remove {
				color: #666;
				margin-right: 4px;
				font-size: 14px;
				line-height: 1;
			}
			.ur-multiselect-v2.select2-container .select2-selection__choice__remove:hover {
				color: #333;
			}
			.ur-multiselect-v2.select2-container .select2-selection__rendered {
				padding: 0;
			}
			.ur-multiselect-v2.select2-container .select2-search--inline {
				margin-top: 2px;
			}
			.ur-multiselect-v2.select2-container .select2-search--inline .select2-search__field {
				border: none;
				background: transparent;
				padding: 4px 8px;
				font-size: 14px;
				color: #333;
				outline: none;
				margin: 0;
			}
			'
		);

		wp_enqueue_style( 'ur-snackbar' );
		wp_localize_script(
			'user-registration-settings',
			'user_registration_settings_params',
			array(
				'ajax_url'                             => admin_url( 'admin-ajax.php' ),
				'assets_url'                           => UR_ASSETS_URL,
				'ur_license_nonce'                     => wp_create_nonce( '_ur_license_nonce' ),
				'ur_updater_nonce'                     => wp_create_nonce( 'updates' ),
				'user_registration_search_global_settings_nonce' => wp_create_nonce( 'user_registration_search_global_settings' ),
				'user_registration_captcha_test_nonce' => wp_create_nonce( 'user_registration_captcha_test_nonce' ),
				'user_registration_my_account_selection_validator_nonce' => wp_create_nonce( 'user_registration_my_account_selection_validator' ),
				'user_registration_lost_password_selection_validator_nonce' => wp_create_nonce( 'user_registration_lost_password_selection_validator' ),
				'user_registration_membership_pages_selection_validator_nonce' => wp_create_nonce( 'user_registration_validate_page_none' ),
				'user_registration_membership_payment_settings_nonce' => wp_create_nonce( 'user_registration_validate_payment_settings_none' ),
				'user_registration_membership_validate_payment_currency_nonce' => wp_create_nonce( 'user_registration_validate_payment_currency' ),
				'user_registration_membership_captcha_settings_nonce' => wp_create_nonce( 'user_registration_validate_captcha_settings_nonce' ),
				'user_registration_settings_nonce'     => wp_create_nonce( 'user_registration_settings_nonce' ),
				'i18n_nav_warning'                     => esc_html__( 'The changes you made will be lost if you navigate away from this page.', 'user-registration' ),
				'i18n'                                 => array(
					'advanced_logic_rules_exist_error'   => esc_html__( 'Remove all rules with advance logics first before disabling.', 'user-registration' ),
					'advanced_logic_check_error'         => esc_html__( 'An error occurred while checking for advanced logic rules.', 'user-registration' ),
					'captcha_success'                    => esc_html__( 'Captcha Test Successful !', 'user-registration' ),
					'captcha_reset_title'                => esc_html__( 'Reset Keys', 'user-registration' ),
					'payment_reset_title'                => esc_html__( 'Reset Details', 'user-registration' ),
					'i18n_prompt_reset'                  => esc_html__( 'Reset', 'user-registration' ),
					'i18n_prompt_cancel'                 => esc_html__( 'Cancel', 'user-registration' ),
					'captcha_failed'                     => esc_html__( 'Some error occured. Please verify that the keys you entered are valid.', 'user-registration' ),
					'captcha_reset_prompt'               => esc_html__( 'Are you sure you want to reset these keys? This action will clear both the Site Key and Secret Key permanently.', 'user-registration' ),
					'payment_reset_prompt'               => esc_html__( 'Are you sure you want to reset these details? This will permanently remove the stored details from our system. This action cannot be undone.', 'user-registration' ),
					'unsaved_changes'                    => esc_html__( 'You have some unsaved changes. Please save and try again.', 'user-registration' ),
					'pro_feature_title'                  => esc_html__( 'is a Pro Feature', 'user-registration' ),
					'upgrade_message'                    => esc_html__(
						'We apologize, but %title% is not available with the free version. To access this fantastic features, please consider upgrading to the %plan%.',
						'user-registration'
					),

					'license_activated_text'             => esc_html__( 'You\'ve activated your license, great! To get all the Pro Features, we just need to install the URM Pro plugin on your website. Don\'t worry, it\'s quick and safe!', 'user-registration' ),
					'pro_install_popup_button'           => esc_html__( 'Install Pro Now', 'user-registration' ),
					'pro_install_popup_title'            => esc_html__( 'Install User Registration & Membership Pro to Unlock All Features', 'user-registration' ),
					'will_install_and_activate_pro_text' => esc_html__( 'This will automatically install and activate the User Registration & Membership Pro Plugin for you.', 'user-registration' ),
					'installing_plugin_text'             => esc_html__( 'Installing Plugin', 'user-registration' ),
					'invalid_stripe_test_publishable_key' => esc_html__( 'Invalid Stripe test publishable key. It must start with pk_test_.', 'user-registration' ),
					'invalid_stripe_live_publishable_key' => esc_html__( 'Invalid Stripe live publishable key. It must start with pk_live_.', 'user-registration' ),
					'pro_activated_success_title'        => esc_html__( 'Success!', 'user-registration' ),
					'pro_activated_success_text'         => esc_html__( 'URM Pro has been successfully installed and activated. You now have access to all premium features!', 'user-registration' ),
					'continue_to_dashboard_text'         => esc_html__( 'Continue to dashboard', 'user-registration' ),

					'upgrade_plan'                       => esc_html__( 'Upgrade Plan', 'user-registration' ),
					'upgrade_link'                       => esc_url( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'integration-settings', 'medium' => 'popup' ) ) ),
				),
				'is_advanced_field_active'             => is_plugin_active( 'user-registration-advanced-fields/user-registration-advanced-fields.php' ),
				'reset_keys_icon'                      => plugins_url( 'assets/images/users/reset-keys-red.svg', UR_PLUGIN_FILE ),
			)
		);

		// Include settings pages.
		self::get_settings_pages();

		// Get current tab/section.
		$current_tab          = empty( $_GET['tab'] ) ? 'general' : sanitize_title( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$current_section      = empty( $_REQUEST['section'] ) ? apply_filters( 'user_registration_settings_' . $current_tab . '_default_section', '' ) : sanitize_title( wp_unslash( $_REQUEST['section'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$current_section_part = empty( $_GET['part'] ) ? '' : sanitize_title( wp_unslash( $_GET['part'] ) );
		/**
		 * Filter to save settings actions
		 *
		 * @param boolean Save Settings or Not.
		 */
		$flag = apply_filters( 'user_registration_settings_save_action', true );

		if ( $flag ) {
			// Save settings if data has been posted.
			if ( ! empty( $_POST ) && ! empty( $_REQUEST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				self::save();
			}
		}

		// Add any posted messages.
		if ( ! empty( $_GET['ur_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::add_error( wp_unslash( $_GET['ur_error'] ) ); // phpcs:ignore
		}

		if ( ! empty( $_GET['ur_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::add_message( wp_unslash( $_GET['ur_error'] ) ); // phpcs:ignore
		}
		global $tabs;
		/**
		 * Filter to get tabs for settings page
		 *
		 * @param array Array of settings page
		 */
		$tabs = apply_filters( 'user_registration_settings_tabs_array', array() ); // phpcs:ignore

		$GLOBALS['hide_save_button'] = false;
		if ( 'import_export' === $current_tab ) {
			$GLOBALS['hide_save_button'] = true;
		}

		include __DIR__ . '/views/html-admin-settings.php';
	}

	/**
	 * Include the settings page classes.
	 */
	public static function get_settings_pages() {

		if ( empty( self::$settings ) ) {
			$settings = array();

			include_once __DIR__ . '/settings/class-ur-settings-page.php';

			if ( ! empty( $_GET['install_user_registration_pages'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification
				UR_Install::create_pages();
				UR_Admin_Notices::remove_notice( 'install' );
			}

			$settings[] = include 'settings/class-ur-settings-general.php';
			$settings[] = include 'settings/class-ur-settings-membership.php';
			$settings[] = include 'settings/class-ur-settings-payment.php';
			$settings[] = include 'settings/class-ur-settings-email.php';
			$settings[] = include 'settings/class-ur-settings-registration-login.php';
			$settings[] = include 'settings/class-ur-settings-my-account.php';
			$settings[] = include 'settings/class-ur-settings-integration.php';

			$settings[] = include 'settings/class-ur-settings-security.php';
			$settings[] = include 'settings/class-ur-settings-advanced.php';
			$settings[] = include 'settings/class-ur-settings-import-export.php';
			$settings[] = include 'settings/class-ur-settings-license.php';
			/**
			 * Filter to retrieve settings pages
			 *
			 * @param array $settings Settings.
			 */
			self::$settings = apply_filters( 'user_registration_get_settings_pages', $settings );
		}

		return self::$settings;
	}

	/**
	 * Save the settings.
	 */
	public static function save() {
		global $current_tab;

		if ( empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'user-registration-settings' ) ) {
			die( esc_html__( 'Action failed. Please refresh the page and retry.', 'user-registration' ) );
		}

		/**
		 * Filter to modify display of setting message
		 *
		 * @param boolean Show/Hide.
		 */
		$flag = apply_filters( 'show_user_registration_setting_message', true );

		$flag = apply_filters( 'user_registration_settings_prevent_default_login', $_REQUEST );

		if ( $flag && is_bool( $flag ) ) {
			if ( 'license' !== $current_tab ) {
				self::add_message( esc_html__( 'Your settings have been saved.', 'user-registration' ) );
			}

			/**
			 * Action to save current tab settings
			 */
			do_action( 'user_registration_settings_save_' . $current_tab );
			/**
			 * Action to save current tab options
			 */
			do_action( 'user_registration_update_options_' . $current_tab );
			/**
			 * Action to save options
			 */
			do_action( 'user_registration_update_options' );
		} elseif ( $flag && 'redirect_login_error' === $flag ) {

			self::add_error(
				esc_html__(
					'Your settings has not been saved. You enabled "Disable Default WordPress Login Screen" but did not select a login page. Please select a page for "Redirect Default WordPress Login To".',
					'user-registration'
				)
			);

		} elseif ( $flag && 'redirect_login_not_myaccount' === $flag ) {

			self::add_error(
				esc_html__(
					'Your settings has not been saved.The selected page for "Redirect Default WordPress Login To" is not a login page. Please select a valid login page.',
					'user-registration'
				)
			);

		} elseif ( $flag && 'invalid_membership_pages' === $flag ) {
			self::add_error(
				esc_html__(
					'Your settings has not been saved. Please select valid pages for the fields.',
					'user-registration'
				)
			);
		}
		// Flush rules.
		wp_schedule_single_event( time(), 'user_registration_flush_rewrite_rules' );

		/**
		 * Action to save settings
		 */
		do_action( 'user_registration_settings_saved' );
	}

	/**
	 * Add a message.
	 *
	 * @param string $text Text.
	 */
	public static function add_message( $text ) {
		self::$messages[] = $text;
	}

	/**
	 * Add an error.
	 *
	 * @param string $text Text.
	 * @param string $type Error Type.
	 */
	public static function add_error( $text, $type = '' ) {
		// Sanitize at input — safe HTML tags allowed (links, strong etc. in error messages)
		$sanitized = wp_kses_post( $text );

		if ( ! empty( $type ) ) {
			self::$errors[ sanitize_key( $type ) ] = $sanitized;
		} else {
			self::$errors[] = $sanitized;
		}
	}

	/**
	 * Output admin fields.
	 *
	 * Loops though the user registration options array and outputs each field.
	 *
	 * @param array $options Opens array to output.
	 */
	public static function output_fields( $options ) {

		$settings = '';

		if ( is_array( $options ) && ! empty( $options ) ) {
			$back_link       = isset( $options['back_link'] ) ? esc_url( $options['back_link'] ) : '';
			$back_link_text  = isset( $options['back_link_text'] ) ? wp_kses_post( $options['back_link_text'] ) : '';
			$back_link_style = isset( $options['back_link_style'] ) ? wp_kses_post( $options['back_link_style'] ) : '';

			if ( isset( $options['back_link'] ) ) {
				$class     = 'inline' === $back_link_style ? 'navigator-action' : 'page-title-action';
				$settings .= '<a href="' . esc_url( $back_link ) . '" class="' . $class . '">';

				if ( isset( $options['back_link_text'] ) ) {
					$settings .= wp_kses_post( $back_link_text );
				}

				$settings .= '</a>';
			}
			$settings .= '</h3>';
			if ( ! empty( $options['desc'] ) ) {
				$settings .= '<p class="ur-p-tag">' . wptexturize( wp_kses_post( $options['desc'] ) ) . '</p>';
			}

			if ( isset( $options['sections'] ) && is_array( $options['sections'] ) ) {

				foreach ( $options['sections'] as $id => $section ) {

					if ( ! isset( $section['type'] ) ) {
						continue;
					}

					if ( 'card' === $section['type'] ) {
						$section_id      = isset( $section['id'] ) ? 'id=' . $section['id'] : '';
						$card_header_css = '';

						if ( isset( $section['id'] ) && 'payment-settings' === $section['id'] ) {
							$card_header_css .= 'max-width: 100%';
						}

						$settings .= '<div class="user-registration-card ur-mt-4 ur-border-0" ' . esc_attr( $section_id ) . ' style="' . esc_attr( $card_header_css ) . '">';

						$header_css = '';
						if ( isset( $section['preview_link'] ) ) {
							$header_css = 'display:flex; justify-content: space-between;';
						}

						$settings .= '<div class="user-registration-card__header ur-border-0" style="' . esc_attr( $header_css ) . '">';
						if ( ! empty( $section['title'] ) ) {
							$settings .= '<div class="user-registration-card__header-wrapper">';
							if ( isset( $section['back_link'] ) ) {
								$settings .= $section['back_link']; // removed kses since the inputs are sanitized in the function ur_back_link itself.
							}
							$settings .= '<h3 class="user-registration-card__title">';
							$settings .= esc_html( ucwords( $section['title'] ) );

							if ( isset( $section['is_premium'] ) && $section['is_premium'] ) {
								$settings .= '<div style="margin-right: 4px;display: inline-block;width: 16px; height: 16px;" ><img style="width: 100%;height:100%;" src="' . UR()->plugin_url() . '/assets/images/icons/ur-pro-icon.png" /></div>';
							}
							$settings .= '</h3>';

							if ( ! empty( $section['button'] ) ) {
								if ( isset( $section['button']['button_type'] ) && 'upgrade_link' === $section['button']['button_type'] ) {
									$settings .= '<a href="' . ( isset( $section['button']['button_link'] ) ? $section['button']['button_link'] : '#' ) . '" class="ur-upgrade--link" target="_blank"><span>' . ( isset( $section['button']['button_text'] ) ? $section['button']['button_text'] : '' ) . '</span></a>';
								} else {
									$button_class  = isset( $section['button']['button_class'] ) ? esc_attr( $section['button']['button_class'] ) : 'user_registration_smart_tags_used';
									$button_type   = isset( $section['button']['button_type'] ) ? $section['button']['button_type'] : '';
									$button_target = ( 'ur-add-new-custom-email' === $button_type ) ? '' : 'target="_blank"';
									$external_icon = ( 'ur-add-new-custom-email' === $button_type ) ? '' : '<span class="dashicons dashicons-external"></span>';
									$settings     .= '<a href="' . ( isset( $section['button']['button_link'] ) ? $section['button']['button_link'] : '#' ) . '" class="' . $button_class . '" style="min-width:90px;" ' . $button_target . '><span style="text-decoration: underline;">' . ( isset( $section['button']['button_text'] ) ? $section['button']['button_text'] : '' ) . '</span>' . $external_icon . '</a>';
								}
							}
							$settings .= '</div>';
						}

						if ( isset( $section['preview_link'] ) ) {
							$settings .= wp_kses_post( $section['preview_link'] );
						}

						$settings .= '</div>';

						// Show upsell texts.
						if ( ! empty( $section['upsell'] ) ) {
							$upsell_section = $section['upsell'];
							$settings      .= '<div class="user-registration-upsell">';
							// excerpt.
							if ( ! empty( $upsell_section['excerpt'] ) ) {
								$settings .= '<p style="font-size: 14px;">' . wptexturize( wp_kses_post( $upsell_section['excerpt'] ) ) . '</p>';
							}
							// descriptions.
							if ( ! empty( $upsell_section['description'] ) ) {
								if ( is_string( $upsell_section['description'] ) ) {
									$settings .= '<p style="font-size: 14px;">' . wptexturize( wp_kses_post( $upsell_section['excerpt'] ) ) . '</p>';
								} elseif ( is_array( $upsell_section['description'] ) ) {
									$settings .= '<ul class="user-registration-upsell__description-list">';
									foreach ( $upsell_section['description'] as $description_text ) {
										$settings .= '<li class="user-registration-upsell__description-list-item">' . $description_text . '</li>';
									}
									$settings .= '</ul>';
								}
							}

							$license_data = ur_get_license_plan();
							$license_plan = ! empty( $license_data->item_plan ) ? $license_data->item_plan : false;
							if ( empty( $license_plan ) && ! empty( $upsell_section['feature_link'] ) ) {
								$settings .= '<a href="' . esc_url( $upsell_section['feature_link'] ) . '" class="user-registration-upsell__feature-link" target="_blank">' . esc_html__( 'Learn More', 'user-registration' ) . '</a>';
							}
							$settings .= '</div>';
						}

						if ( ! empty( $section['desc'] ) ) {
							$settings .= '<p class="ur-p-tag">' . wptexturize( wp_kses_post( $section['desc'] ) ) . '</p>';
						}

						$settings .= '<div class="pt-0 pb-0 user-registration-card__body">';

						if ( ! empty( $id ) ) {
							/**
							 * Action to output settings
							 */
							do_action( 'user_registration_settings_' . sanitize_title( $id ) );
						}
					}

					if ( 'accordian' === $section['type'] ) {
						$section_id = isset( $section['id'] ) ? 'id=' . $section['id'] : '';

						$available_in      = isset( $section['available_in'] ) ? sanitize_text_field( wp_unslash( $section['available_in'] ) ) : '';
						$is_captcha        = isset( $section['settings_type'] ) ? ' ur-captcha-settings' : '';
						$is_captcha_header = isset( $section['settings_type'] ) ? $is_captcha . '-header' : '';
						$is_captcha_body   = isset( $section['settings_type'] ) ? $is_captcha . '-body' : '';
						$is_connected      = isset( $section['is_connected'] ) ? $section['is_connected'] : false;

						if ( isset( $section['video_id'] ) ) {
							$inactive_class = 'user-registration-inactive-addon';
							$extras         = 'data-title="' . esc_attr( $section['title'] ) . '"';
							$extras        .= 'data-id="' . esc_attr( $section['id'] ) . '"';
							$extras        .= 'data-video="' . esc_attr( $section['video_id'] ) . '"';
							$extras        .= 'data-available-in="' . esc_attr( $available_in ) . '"';
							$settings      .= '<div class="user-registration-card ur-mb-2 ' . esc_attr( $section_id ) . esc_attr( $inactive_class ) . '" ' . $extras . '>';
						} else {
							$settings .= '<div class="user-registration-card ur-mb-2' . $is_captcha . '" ' . esc_attr( $section_id ) . '>';
						}

						$settings .= '<div class="user-registration-card__header ur-d-flex ur-align-items-center ur-p-3 integration-header-info accordion' . $is_captcha_header . '">';
						if ( ! UR_PRO_ACTIVE && ( 'free-mollie' === $section['id'] || 'free-authorize-net' === $section['id'] ) ) {
							$settings .= '<div class="integration-detail upgradable-type">';
						} else {

							$settings .= '<div class="integration-detail ">';
						}
						$settings .= '<figure class="logo">';
						$settings .= '<img src="' . UR()->plugin_url() . '/assets/images/settings-icons/' . $section['id'] . '.png" alt="' . $section['title'] . '">';
						$settings .= '</figure>';
						if ( ! empty( $section['title'] ) ) {
							$settings .= '<h3 class="user-registration-card__title">' . esc_html( $section['title'] );
							$settings .= '</h3>';
						}
						if ( ! UR_PRO_ACTIVE && ( 'free-mollie' === $section['id'] || 'free-authorize-net' === $section['id'] ) ) {
							$result    = ' data-gate-content="ur-pro-' . $section['id'] . '"';
							$settings .= '<img class="ur-pro-premium" data-feature-gate="tooltip" data-gate-placement="right" data-gate-interactive="true"' . $result . ' src="' . UR()->plugin_url() . '/assets/images/icons/ur-pro-icon.png" alt="' . $section['title'] . '">';
							$settings .= '<template id="ur-pro-' . $section['id'] . '">';
							$settings .= '<div class="ur-feature">';
							$settings .= '<div class="ur-feature__title">';
							$settings .= esc_html__( $section['title'] . ' payment feature only available in Pro.', 'user-registration' );
							$settings .= '</div>';
							$settings .= '<a target="_blank" class="ur-feature__btn" href="' . esc_url( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'ur-membership-create', 'medium' => 'upgrade-link' ) ) ) . '">';
							$settings .= '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"></path><path d="M5 21h14"></path></svg>';
							$settings .= esc_html__( 'Upgrade to Pro', 'user-registration' );
							$settings .= '</a>';
							$settings .= '</div>';
							$settings .= '</template>';
						}
						$settings .= '<span class="ur-connection-status ' . ( $is_connected ? 'ur-connection-status--active' : '' ) . '">';
						$settings .= '</span>';

						$settings .= '</div>';
						$settings .= '<div class="integration-action">';
						$settings .= '<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><polyline points="6 9 12 15 18 9"></polyline></svg>';
						$settings .= '</div>';
						$settings .= '</div>';
						if ( isset( $section['video_id'] ) ) {
							$settings .= '<div>';
						} else {
							$settings .= '<div class="user-registration-card__body ur-p-3 integration-body-info' . $is_captcha_body . '">';
						}

						if ( ! empty( $id ) ) {
							/**
							 * Action to output settings
							 */
							do_action( 'user_registration_settings_' . sanitize_title( $id ) );
						}
					}

					if ( isset( $section['settings'] ) && ( is_array( $section['settings'] ) || is_object( $section['settings'] ) ) ) {
						foreach ( $section['settings'] as $key => $value ) {

							if ( ! isset( $value['type'] ) ) {
								continue;
							}

							if ( ! isset( $value['id'] ) ) {
								$value['id'] = '';
							}
							if ( ! isset( $value['row_class'] ) ) {
								$value['row_class'] = '';
							}
							if ( ! isset( $value['rows'] ) ) {
								$value['rows'] = '';
							}
							if ( ! isset( $value['cols'] ) ) {
								$value['cols'] = '';
							}
							if ( ! isset( $value['title'] ) ) {
								$value['title'] = isset( $value['name'] ) ? $value['name'] : '';
							}
							if ( ! isset( $value['class'] ) ) {
								$value['class'] = '';
							}
							if ( ! isset( $value['css'] ) ) {
								$value['css'] = '';
							}
							if ( ! isset( $value['default'] ) ) {
								$value['default'] = '';
							}
							if ( ! isset( $value['desc'] ) ) {
								$value['desc'] = '';
							}
							if ( ! isset( $value['desc_tip'] ) ) {
								$value['desc_tip'] = false;
							}
							if ( ! isset( $value['desc_field'] ) ) {
								$value['desc_field'] = false;
							}
							if ( ! isset( $value['placeholder'] ) ) {
								$value['placeholder'] = '';
							}

							// Capitalize Setting Label.
							$value['title'] = self::capitalize_title( $value['title'] );

							// Custom attribute handling.
							$custom_attributes = array();

							if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
								foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
									$custom_attributes[] = esc_attr( $attribute ) . '=' . esc_attr( $attribute_value ) . '';
								}
							}

							// Description handling.
							$field_description = self::get_field_description( $value );
							extract( $field_description ); // phpcs:ignore

							$notice_field = '';
							if ( isset( $value['notice'] ) && ! empty( $value['notice'] ) ) {
								$notice_type    = isset( $value['notice']['type'] ) ? $value['notice']['type'] : 'info';
								$notice_message = isset( $value['notice']['message'] ) ? $value['notice']['message'] : '';
								$notice_field   = '<span class="ur-settings-notice ur-settings-notice--' . $notice_type . '">' . $notice_message . '</span>';
							}

							// Display condition/dependency handling.
							$display_condition_data  = self::get_display_condition_attributes( $value );
							$display_condition_attrs = $display_condition_data['attrs'];
							$display_condition_style = $display_condition_data['initial_style'];

							// Switch based on type.
							switch ( $value['type'] ) {

								// Standard text inputs and subtypes like 'number'.
								case 'text':
								case 'email':
								case 'number':
								case 'password':
								case 'date':
									$option_value = self::get_option( $value['id'], $value['default'] );

									$settings .= '<div class="user-registration-global-settings"' . $display_condition_attrs . $display_condition_style . '>';
									$settings .= '<label class="ur-label" for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field">';
									$settings .= '<input
											name="' . esc_attr( $value['id'] ) . '"
											id="' . esc_attr( $value['id'] ) . '"
											type="' . esc_attr( $value['type'] ) . '"
											style="' . esc_attr( $value['css'] ) . '"
											value="' . esc_attr( $option_value ) . '"
											class="' . esc_attr( $value['class'] ) . '"
											min="' . esc_attr( ! empty( $value['min'] ) ? $value['min'] : '' ) . '"
											max="' . esc_attr( ! empty( $value['max'] ) ? $value['max'] : '' ) . '"
											placeholder="' . esc_attr( $value['placeholder'] ) . '"
											' . esc_attr( implode( ' ', $custom_attributes ) ) . '/>';
									$settings .= wp_kses_post( $description );
									$settings .= '</div>';
									$settings .= '</div>';
									break;
								case 'nonce':
									$settings .= '<div class="user-registration-global-settings"' . $display_condition_attrs . $display_condition_style . '>';
									$settings .= '<div class="user-registration-global-settings--field">';
									$settings .= '<input
											name="' . esc_attr( $value['id'] ) . '"
											id="' . esc_attr( $value['id'] ) . '"
											type="hidden"
											value="' . esc_attr( wp_create_nonce( $value['action'] ) ) . '"
											/>';
									$settings .= '</div>';
									$settings .= '</div>';
									break;

								// Color picker.
								case 'color':
									$option_value  = self::get_option( $value['id'], $value['default'] );
									$default_value = isset( $value['default'] ) ? $value['default'] : '';
									$settings     .= '<div class="user-registration-global-settings user-registration-color-picker">';
									$settings     .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings     .= '<div class="user-registration-global-settings--field">';
									$settings     .= '<input
											name="' . esc_attr( $value['id'] ) . '"
											id="' . esc_attr( $value['id'] ) . '"
											type="text"
											dir="ltr"
											style="' . esc_attr( $value['css'] ) . '"
											value="' . esc_attr( $option_value ) . '"
											class="' . esc_attr( $value['class'] ) . 'colorpick"
											data-alpha="true"
											data-default-value="' . esc_attr( $default_value ) . '"
											placeholder="' . esc_attr( $value['placeholder'] ) . '"
											' . esc_attr( implode( ' ', $custom_attributes ) ) . '/>&lrm;' . wp_kses_post( $description );
									$settings     .= '<div id="colorPickerDiv_' . esc_attr( $value['id'] ) . '" class="colorpickdiv" style="z-index: 100;background:#eee;border:1px solid #ccc;position:absolute;display:none;"></div></div>';
									$settings     .= '</div>';
									break;

								case 'color-group':
									$base_id = $value['id'];

									$states_config = isset( $value['states'] ) && is_array( $value['states'] ) ? $value['states'] : array( 'normal', 'hover' );

									$default_labels = array(
										'normal' => __( 'Normal', 'user-registration' ),
										'active' => __( 'Active', 'user-registration' ),
										'hover'  => __( 'Hover', 'user-registration' ),
										'focus'  => __( 'Focus', 'user-registration' ),
									);

									$color_states = array();
									foreach ( $states_config as $state_key => $state ) {
										if ( is_numeric( $state_key ) && is_string( $state ) ) {
											$state_key = $state;
											$state     = array();
										} elseif ( is_array( $state ) && isset( $state['key'] ) ) {
											$state_key = $state['key'];
										} elseif ( is_string( $state ) ) {
											$state = array( 'label' => $state );
										}

										$state_label   = '';
										$state_default = '';

										if ( is_array( $state ) && isset( $state['label'] ) ) {
											$state_label = $state['label'];
										} elseif ( isset( $value['labels'][ $state_key ] ) ) {
											$state_label = $value['labels'][ $state_key ];
										} else {
											$state_label = isset( $default_labels[ $state_key ] ) ? $default_labels[ $state_key ] : ucfirst( $state_key );
										}

										if ( is_array( $state ) && isset( $state['default'] ) ) {
											$state_default = $state['default'];
										} elseif ( isset( $value['default'][ $state_key ] ) ) {
											$state_default = $value['default'][ $state_key ];
										} else {
											$state_default = '';
										}

										$color_states[ $state_key ] = array(
											'label'   => $state_label,
											'default' => $state_default,
										);
									}

									$settings .= '<div class="user-registration-global-settings user-registration-color-group">';
									$settings .= '<label>' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field user-registration-color-group-field">';

									$saved_colors = self::get_option( $base_id, array() );
									if ( ! is_array( $saved_colors ) ) {
										$saved_colors = array();
									}

									foreach ( $color_states as $state => $state_data ) {
										$state_id      = $base_id . '_' . $state;
										$option_value  = isset( $saved_colors[ $state ] ) ? $saved_colors[ $state ] : ( isset( $state_data['default'] ) ? $state_data['default'] : '' );
										$default_value = isset( $state_data['default'] ) ? $state_data['default'] : '';

										$settings .= '<div class="user-registration-color-group-item">';
										$settings .= '<span class="ur-color-state-label">' . esc_html( ucfirst( $state ) ) . '</span>';
										$settings .= '<input
												name="' . esc_attr( $state_id ) . '"
												id="' . esc_attr( $state_id ) . '"
												type="text"
												dir="ltr"
												style="' . esc_attr( $value['css'] ) . '"
												value="' . esc_attr( $option_value ) . '"
												class="' . esc_attr( $value['class'] ) . ' colorpick"
												data-alpha="true"
												data-state="' . esc_attr( $state ) . '"
												data-default-value="' . esc_attr( $default_value ) . '"
												data-current-value="' . esc_attr( $option_value ) . '"
												placeholder="' . esc_attr( $value['placeholder'] ) . '"
												' . esc_attr( implode( ' ', $custom_attributes ) ) . '/>&lrm;';
										$settings .= '<div id="colorPickerDiv_' . esc_attr( $state_id ) . '" class="colorpickdiv" style="z-index: 100;background:#eee;border:1px solid #ccc;position:absolute;display:none;"></div>';
										$settings .= '</div>';
									}

									$settings .= wp_kses_post( $description );
									$settings .= '</div>';
									$settings .= '</div>';
									break;

								// Textarea.
								case 'textarea':
									$option_value = self::get_option( $value['id'], $value['default'] );

									$settings .= '<div class="user-registration-global-settings"' . $display_condition_attrs . $display_condition_style . '>';
									$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field">';
									$settings .= wp_kses_post( $description );
									$settings .= '<textarea
											name="' . esc_attr( $value['id'] ) . '"
											id="' . esc_attr( $value['id'] ) . '"
											style="' . esc_attr( $value['css'] ) . '"
											class="' . esc_attr( $value['class'] ) . '"
											rows="' . esc_attr( $value['rows'] ) . '"
											cols="' . esc_attr( $value['cols'] ) . '"
											placeholder="' . esc_attr( $value['placeholder'] ) . '"
											' . esc_html( implode( ' ', $custom_attributes ) ) . '>'
												. esc_textarea( $option_value ) . '</textarea>';
									$settings .= '</div>';
									$settings .= '</div>';
									break;

								// Select boxes.
								case 'select':
								case 'multiselect':
									$option_value = self::get_option( $value['id'], $value['default'] );

									$settings .= '<div class="user-registration-global-settings"' . $display_condition_attrs . $display_condition_style . '>';
									$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field">';
									$multiple  = '';
									$type      = '';
									if ( 'multiselect' == $value['type'] ) {
										$type     = '[]';
										$multiple = 'multiple="multiple"';
									}

									$settings .= '<select
											name="' . esc_attr( $value['id'] ) . '' . $type . '"
											id="' . esc_attr( $value['id'] ) . '"
											style="' . esc_attr( $value['css'] ) . '"
											class="' . esc_attr( $value['class'] ) . '"
											' . esc_attr( implode( ' ', $custom_attributes ) ) . '
											' . esc_attr( $multiple ) . '>';

									foreach ( $value['options'] as $key => $val ) {
										$selected = '';

										if ( is_array( $option_value ) ) {
											$selected = selected( in_array( $key, $option_value ), true, false );
										} else {
											$selected = selected( $option_value, $key, false );
										}

										$settings .= '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $selected ) . '>';
										$settings .= esc_html( $val );
										$settings .= '</option>';
									}

									$settings .= '</select>' . wp_kses_post( $description );
									$settings .= '</div>';
									$settings .= '</div>';
									break;

								case 'multiselect-v2':
									$option_value = self::get_option( $value['id'], $value['default'] );
									if ( ! is_array( $option_value ) ) {
										$option_value = array();
									}

									$settings .= '<div class="user-registration-global-settings"' . $display_condition_attrs . $display_condition_style . '>';
									$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field">';

									$settings .= '<select
											name="' . esc_attr( $value['id'] ) . '[]"
											id="' . esc_attr( $value['id'] ) . '"
											multiple="multiple"
											class="multiple-select ur-multiselect-v2"
											style="' . esc_attr( $value['css'] ) . '">';

									foreach ( $value['options'] as $key => $val ) {
										$selected  = selected( in_array( $key, $option_value ), true, false );
										$settings .= '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $selected ) . '>';
										$settings .= esc_html( $val );
										$settings .= '</option>';
									}

									$settings .= '</select>';

									$settings .= wp_kses_post( $description );
									$settings .= '</div>';
									$settings .= '</div>';
									break;

								// Radio inputs.
								case 'radio':
									$option_value = self::get_option( $value['id'], $value['default'] );
									$settings    .= '<div class="user-registration-global-settings"' . $display_condition_attrs . $display_condition_style . '>';
									$settings    .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings    .= '<div class="user-registration-global-settings--field">';
									$settings    .= '<fieldset>';
									$settings    .= wp_kses_post( $description );
									$settings    .= '<ul>';

									$option_descriptions = isset( $value['option_descriptions'] ) ? $value['option_descriptions'] : array();

									foreach ( $value['options'] as $key => $val ) {
										$settings .= '<li>';
										$settings .= '<label>';
										$settings .= '<span class="ur-radio-option-wrapper">';
										$settings .= '<input
													name="' . esc_attr( $value['id'] ) . '"
													value="' . esc_attr( $key ) . '"
													type="radio"
													style="' . esc_attr( $value['css'] ) . '"
													class="' . esc_attr( $value['class'] ) . '"
													' . esc_attr( implode( ' ', $custom_attributes ) ) . '
													' . esc_attr( checked( $key, $option_value, false ) ) . '
													/>' . wp_kses_post( $val );
										$settings .= '</span>';

										if ( ! empty( $option_descriptions[ $key ] ) ) {
											$settings .= '<span class="ur-radio-option-description">' . esc_html( $option_descriptions[ $key ] ) . '</span>';
										}

										$settings .= '</label>';
										$settings .= '</li>';
									}

									$settings .= '</ul>';
									$settings .= '</fieldset>';
									$settings .= '</div>';
									$settings .= '</div>';
									break;

								// Checkbox input.
								case 'checkbox':
									$option_value = self::get_option( $value['id'], $value['default'] );

									$visbility_class = array();

									if ( ! isset( $value['hide_if_checked'] ) ) {
										$value['hide_if_checked'] = false;
									}
									if ( ! isset( $value['show_if_checked'] ) ) {
										$value['show_if_checked'] = false;
									}
									if ( 'yes' === $value['hide_if_checked'] || 'yes' === $value['show_if_checked'] ) {
										$visbility_class[] = 'hidden_option';
									}
									if ( 'option' === $value['hide_if_checked'] ) {
										$visbility_class[] = 'hide_options_if_checked';
									}
									if ( 'option' === $value['show_if_checked'] ) {
										$visbility_class[] = 'show_options_if_checked';
									}
									$settings .= '<div class="user-registration-global-settings ' . esc_attr( implode( ' ', $visbility_class ) ) . ' ' . esc_attr( $value['row_class'] ) . '"' . $display_condition_attrs . $display_condition_style . '>';

									if ( ! isset( $value['checkboxgroup'] ) || 'start' === $value['checkboxgroup'] ) {
										$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
										$settings .= '<div class="user-registration-global-settings--field">';
										$settings .= '<fieldset>';
									} else {
										$settings .= '<div class="user-registration-global-settings--field">';
										$settings .= '<fieldset class="' . esc_attr( implode( ' ', $visbility_class ) ) . '">';
									}

									$settings .= '<input
											name="' . esc_attr( $value['id'] ) . '"
											id="' . esc_attr( $value['id'] ) . '"
											type="checkbox"
											class="' . esc_attr( isset( $value['class'] ) ? $value['class'] : '' ) . '"
											value="1"
											' . esc_attr( checked( $option_value, 'yes', false ) ) . '
											' . esc_attr( implode( ' ', $custom_attributes ) ) . '/>';

									$settings .= '</fieldset>';
									$settings .= wp_kses_post( $description );
									$settings .= wp_kses_post( $desc_field );
									$settings .= '</div>';
									$settings .= '</div>';
									break;

								case 'multicheckbox':
									$option_value = self::get_option( $value['id'], $value['default'] );
									if ( ! is_array( $option_value ) ) {
										$option_value = array();
									}

									$settings .= '<div class="user-registration-global-settings">';
									$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field">';
									$settings .= wp_kses_post( $description );
									$settings .= '<fieldset>';
									$settings .= '<ul>';

									if ( ! empty( $value['options'] ) && is_array( $value['options'] ) ) {
										foreach ( $value['options'] as $key => $val ) {
											$checked   = in_array( $key, $option_value, true ) ? 'checked="checked"' : '';
											$settings .= '<li>';
											$settings .= '<label>';
											$settings .= '<input
														name="' . esc_attr( $value['id'] ) . '[]"
														id="' . esc_attr( $value['id'] ) . '_' . esc_attr( $key ) . '"
														type="checkbox"
														value="' . esc_attr( $key ) . '"
														class="' . esc_attr( isset( $value['class'] ) ? $value['class'] : '' ) . '"
														' . esc_attr( $checked ) . '
														' . esc_attr( implode( ' ', $custom_attributes ) ) . '/>';
											$settings .= esc_html( $val );
											$settings .= '</label>';
											$settings .= '</li>';
										}
									}

									$settings .= '</ul>';
									$settings .= '</fieldset>';
									$settings .= '</div>';
									$settings .= '</div>';
									break;

								// Single page selects.
								case 'single_select_page':
									$args = array(
										'name'             => $value['id'],
										'id'               => $value['id'],
										'sort_column'      => 'menu_order',
										'sort_order'       => 'ASC',
										'show_option_none' => ' ',
										'class'            => $value['class'],
										'echo'             => false,
										'selected'         => absint( self::get_option( $value['id'], $value['default'] ) ),
									);

									if ( isset( $value['args'] ) ) {
										$args = wp_parse_args( $value['args'], $args );
									}

									// Combine display condition style with existing display style if needed.
									$existing_display = ( isset( $value['display'] ) && 'none' === $value['display'] ) ? 'display:none' : '';
									if ( ! empty( $display_condition_style ) ) {
										// Display condition style takes precedence (it already has style="...")
										$combined_style = $display_condition_style;
									} elseif ( ! empty( $existing_display ) ) {
										// Only existing style exists
										$combined_style = ' style="' . esc_attr( $existing_display ) . '"';
									} else {
										$combined_style = '';
									}
									$settings .= '<div class="user-registration-global-settings single_select_page"' . $display_condition_attrs . $combined_style . '>';
									$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field">';
									$settings .= str_replace( ' id=', " data-placeholder='" . esc_attr__( 'Select a page&hellip;', 'user-registration' ) . "' style='" . esc_attr( $value['css'] ) . "' class='" . esc_attr( $value['class'] ) . "' id=", wp_dropdown_pages( $args ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									$settings .= wp_kses_post( $description );
									$settings .= '</div>';
									$settings .= '</div>';
									break;

								case 'tinymce':
									$editor_settings = array(
										'name'       => esc_attr( $value['id'] ),
										'id'         => esc_attr( $value['id'] ),
										'style'      => esc_attr( $value['css'] ),
										'default'    => esc_attr( $value['default'] ),
										'class'      => esc_attr( $value['class'] ),
										'quicktags'  => true,
										'teeny'      => true,
										'show-ur-registration-form-button' => isset( $value['show-ur-registration-form-button'] ) ? $value['show-ur-registration-form-button'] : true,
										'show-smart-tags-button' => isset( $value['show-smart-tags-button'] ) ? $value['show-smart-tags-button'] : true,
										'show-reset-content-button' => isset( $value['show-reset-content-button'] ) ? $value['show-reset-content-button'] : true,
										'tinymce'    => array(
											'skin'      => 'lightgray',
											'toolbar1'  => 'undo,redo,formatselect,fontselect,fontsizeselect,bold,italic,forecolor,alignleft,aligncenter,alignright,alignjustify,bullist,numlist,outdent,indent,removeformat',
											'statusbar' => false,
											'toolbar2'  => '',
											'toolbar3'  => '',
											'toolbar4'  => '',
											'plugins'   => 'wordpress,wpautoresize,wplink,wpdialogs,wptextpattern,wpview,colorpicker,textcolor,hr,charmap,link,fullscreen,lists',
										),
										'editor_css' => '<style>#wp-excerpt-editor-container .wp-editor-area{height:175px; width:100%;}</style>',
									);

									$option_value = self::get_option( $value['id'], $value['default'] );

									// Unwrap email content if it contains wrapper HTML (for editor display).
									if ( function_exists( 'ur_unwrap_email_body_content' ) ) {
										$option_value = ur_unwrap_email_body_content( $option_value );
									}

									$settings .= '<div class="user-registration-global-settings"' . $display_condition_attrs . $display_condition_style . '>';
									$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field">';
									$settings .= wp_kses_post( $description );

									// Output buffer for tinymce editor.
									ob_start();
									wp_editor( $option_value, $value['id'], $editor_settings );
									$settings .= ob_get_clean();

									$settings .= '</div>';
									$settings .= '</div>';

									break;

								case 'link':
									$align_items = isset( $value['align'] ) ? $value['align'] : '';

									$settings .= '<div class="user-registration-global-settings"' . $display_condition_attrs . $display_condition_style . '>';
									$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_attr( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field"' . ( ! empty( $align_items ) ? 'style="align-items: end;"' : '' ) . '>';

									if ( isset( $value['buttons'] ) && is_array( $value['buttons'] ) ) {
										foreach ( $value['buttons'] as $button ) {
											$settings .= '<a
														href="' . esc_url( $button['href'] ) . '"
														class="button ' . esc_attr( $button['class'] ) . '" style="' . esc_attr( $value['css'] ) . '">' . esc_html( $button['title'] ) . '</a>';
										}
									}

									$settings .= ( ! empty( $value['desc'] ) && isset( $value['desc_tip'] ) && true !== $value['desc_tip'] ) ? '<p class="description" >' . wp_kses_post( $value['desc'] ) . '</p>' : '';
									$settings .= '</div>';
									$settings .= '</div>';
									break;
								// Image upload.
								case 'image':
									$option_value = self::get_option( $value['id'], $value['default'] );

									$settings .= '<div class="user-registration-global-settings image-upload"' . $display_condition_attrs . $display_condition_style . '>';

									$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_attr( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field">';
									$settings .= '<img src="' . esc_attr( $option_value ) . '" alt="' . esc_attr__( 'Header Logo', 'user-registration' ) . '" class="ur-image-uploader" height="auto" width="20%" ' . ( empty( $option_value ) ? 'style="display:none"' : '' ) . '">';
									$settings .= '<button type="button" class="ur-image-uploader ur-button button-secondary" ' . ( empty( $option_value ) ? '' : 'style = "display:none"' ) . '>' . esc_html__( 'Upload Image', 'user-registration' ) . '</button>';
									$settings .= '<button type="button" class="ur-image-remover ur-button button-secondary" ' . ( ! empty( $option_value ) ? '' : 'style = "display:none"' ) . '>' . esc_html__( 'Remove Image', 'user-registration' ) . '</button>';

									$settings .= '	<input
											name="' . esc_attr( $value['id'] ) . '"
											id="' . esc_attr( $value['id'] ) . '"
											value="' . esc_attr( $option_value ) . '"
											type="hidden"
										>';
									$settings .= '</div>';
									$settings .= '</div>';
									wp_enqueue_media();

									break;

								// Radio image inputs.
								case 'radio-image':
									$option_value = self::get_option( $value['id'], $value['default'] );

									$settings .= '<div class="user-registration-global-settings radio-image"' . $display_condition_attrs . $display_condition_style . '>';
									$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_attr( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field">';
									$settings .= '<ul>';

									foreach ( $value['options'] as $key => $val ) {
										$settings .= '<li>';
										$settings .= '<label class="' . ( esc_attr( checked( $key, $option_value, false ) ) ? 'selected' : '' ) . '">';
										$settings .= '<img src="' . esc_html( $val['image'] ) . '">';
										$settings .= '<input
													name="' . esc_attr( $value['id'] ) . '"
													value="' . esc_attr( $key ) . '"
													type="radio"
													style="' . esc_attr( $value['css'] ) . '"
													class="' . esc_attr( $value['class'] ) . '"
													' . esc_attr( implode( ' ', $custom_attributes ) ) . '
													' . esc_attr( checked( $key, $option_value, false ) ) . '>';

										$settings .= esc_html( $val['name'] );
										$settings .= '</label>';
										$settings .= '</li>';
									}

									$settings .= '</ul>';
									$settings .= '</div>';
									$settings .= '</div>';
									break;
								// Toggle input.
								case 'toggle':
									$option_value = self::get_option( $value['id'], $value['default'] );

									$settings .= '<div class="user-registration-global-settings"' . $display_condition_attrs . $display_condition_style . '>';
									$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field">';
									$settings .= '<div class="ur-toggle-section">';
									$settings .= '<span class="user-registration-toggle-form">';
									$settings .= '<input
												type="checkbox"
												name="' . esc_attr( $value['id'] ) . '"
												id="' . esc_attr( $value['id'] ) . '"
												style="' . esc_attr( $value['css'] ) . '"
												class="' . esc_attr( $value['class'] ) . '"
												value="1"
												' . esc_attr( implode( ' ', $custom_attributes ) ) . '
												' . esc_attr( checked( true, ur_string_to_bool( $option_value ), false ) ) . '>';
									$settings .= '<span class="slider round"></span>';
									$settings .= '</span>';
									$settings .= '</div>';
									$settings .= wp_kses_post( $description );
									$settings .= wp_kses_post( $desc_field );
									$settings .= wp_kses_post( $notice_field );
									$settings .= '</div>';
									$settings .= '</div>';
									break;
								case 'radio-group':
									$option_value = self::get_option( $value['id'], $value['default'] );
									$options      = isset( $value['options'] ) ? $value['options'] : array(); // $args['choices'] for backward compatibility. Modified since 1.5.7.

									if ( ! empty( $options ) ) {
										$settings .= '<div class="user-registration-global-settings"' . $display_condition_attrs . $display_condition_style . '>';
										$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
										$settings .= '<div class="user-registration-global-settings--field">';

										$settings .= '<ul class="ur-radio-group-list">';
										foreach ( $options as $option_index => $option_text ) {
											$class     = str_replace( ' ', '-', strtolower( $option_text ) );
											$settings .= '<li class="ur-radio-group-list--item ' . $class . ( trim( $option_index ) === $option_value ? ' active' : '' ) . '">';

											$checked = '';

											if ( '' !== $option_value ) {
												$checked = checked( $option_value, trim( $option_index ), false );
											}

											$settings .= '<label for="' . esc_attr( isset( $args['id'] ) ? $args['id'] : '' ) . '_' . esc_attr( $option_text ) . '" class="radio">';

											if ( isset( $value['radio-group-images'] ) ) {
												$settings .= '<img src="' . $value['radio-group-images'][ $option_index ] . '" />';
											}

											$settings .= wp_kses(
												trim( $option_text ),
												array(
													'a'    => array(
														'href'  => array(),
														'title' => array(),
													),
													'span' => array(),
												)
											);

											$settings .= '<input type="radio" name="' . esc_attr( $value['id'] ?? '' ) . '" id="' . esc_attr( $value['id'] ?? '' ) . '"	style="' . esc_attr( $value['css'] ) . '" class="' . esc_attr( $value['class'] ) . '" value="' . esc_attr( trim( $option_index ) ) . '" ' . implode( ' ', $custom_attributes ) . ' / ' . $checked . ' /> ';
											$settings .= '</label>';

											$settings .= '</li>';
										}
										$settings .= '</ul>';
										$settings .= '</div>';
										$settings .= '</div>';

									}
									break;
								case 'button':
									$css                   = '';
									$field_css             = '';
									$btn_css               = ! empty( $value['class'] ) ? $value['class'] : '';
									$btn_slug              = ! empty( $value['attrs']['data-slug'] ) ? $value['attrs']['data-slug'] : '';
									$btn_name              = ! empty( $value['attrs']['data-name'] ) ? $value['attrs']['data-name'] : '';
									$is_connected          = isset( $section['is_connected'] ) ? $section['is_connected'] : false;
									$section_id            = isset( $section['id'] ) ? $section['id'] : '';
									$is_captcha            = in_array(
										$section_id,
										array(
											'v2',
											'v3',
											'hCaptcha',
											'cloudflare',
										)
									);
									$show_reset_key_button = ( $is_connected && in_array(
										$section_id,
										array(
											'v2',
											'v3',
											'hCaptcha',
											'cloudflare',
										)
									) );
									if ( in_array(
										$section_id,
										array(
											'stripe',
											'paypal',
											'bank',
											'payment-settings',
											'mollie',
											'free-mollie',
											'free-authorize-net',
											'authorize-net',
											'v2',
											'v3',
											'hCaptcha',
											'cloudflare',
											'captcha-settings',
											'payment-retry',
											'invoice-business-info',
											'invoice-settings',
										)
									) ) {
										$css       = 'ur-flex-row-reverse';
										$field_css = 'ur-align-items-end';
									}

									$settings .= '<div class="user-registration-global-settings ' . $css . '"' . $display_condition_attrs . $display_condition_style . '>';
									$settings .= '<div class="user-registration-global-settings--field ' . $field_css . '">';
									$settings .= '<button
											id="' . esc_attr( $value['id'] ) . '"
											type="button"
											class="button button-primary ' . esc_attr( $btn_css ) . '"
											type="button"
											data-id="' . esc_attr( $section_id ) . '"';
									if ( ! empty( $btn_slug ) ) {
										$settings .= ' data-slug="' . esc_attr( $btn_slug ) . '"';
									}
									if ( ! empty( $btn_name ) ) {
										$settings .= ' data-name="' . esc_attr( $btn_name ) . '"';
									}
									$settings .= '>' . $value['title'] . '</button>';
									$settings .= '</div>';
									if ( $is_captcha ) {
										$settings .= '<a
										href="#"
										class="reset-captcha-keys ' . ( $show_reset_key_button ? '' : 'ur-d-none' ) . '"
										data-id="' . esc_attr( $section_id ) . '"
										/>
										<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
			                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
			                            </svg>
										' . __( 'Reset Keys', 'user-registration' ) . '</a>';
									}
									$settings .= '</div>';
									break;

								case 'local_currency':
									ob_start();
									CoreFunctions::render_local_currencies_table();
									$settings .= ob_get_clean();
									break;

								case 'tax_table':
									$settings .= '<div class="user-registration-list-table-container user-registration-list-tax-region-table-container">';
									$settings .= ur_render_tax_table();
									$settings .= '</div>';
									break;

								case 'duration_input':
									$unit_id              = isset( $value['unit_id'] ) ? $value['unit_id'] : '';
									$value_id             = isset( $value['value_id'] ) ? $value['value_id'] : '';
									$unit_options         = isset( $value['unit_options'] ) ? $value['unit_options'] : array();
									$default_unit         = isset( $value['default_unit'] ) ? $value['default_unit'] : 'days';
									$default_value        = isset( $value['default_value'] ) ? $value['default_value'] : 1;
									$before_after_id      = isset( $value['before_after_id'] ) ? $value['before_after_id'] : '';
									$before_after_options = isset( $value['before_after_options'] ) ? $value['before_after_options'] : array();
									$default_before_after = isset( $value['default_before_after'] ) ? $value['default_before_after'] : 'after';

									$saved_unit         = self::get_option( $unit_id, $default_unit );
									$saved_value        = self::get_option( $value_id, $default_value );
									$saved_before_after = ! empty( $before_after_id ) ? self::get_option( $before_after_id, $default_before_after ) : $default_before_after;

									$settings .= '<div class="user-registration-global-settings ur-duration-input-wrapper">';
									$settings .= '<label class="ur-label" for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
									$settings .= '<div class="user-registration-global-settings--field ur-duration-input-field">';
									$settings .= '<div class="ur-duration-rows-wrapper">';

									$settings .= '<div class="ur-duration-row ur-duration-row-1">';
									$settings .= '<input
											name="' . esc_attr( $value_id ) . '"
											id="' . esc_attr( $value_id ) . '"
											type="number"
											class="ur-duration-value-input"
											style="display: inline-block; width: auto; min-width: 100px;"
											value="' . esc_attr( $saved_value ) . '"
											min="1"
											step="1"/>';
									$settings .= '<select
											name="' . esc_attr( $unit_id ) . '"
											id="' . esc_attr( $unit_id ) . '"
											class="ur-duration-unit-select"
											style="display: inline-block; width: auto; min-width: 120px;">';

									foreach ( $unit_options as $option_key => $option_label ) {
										$selected  = selected( $saved_unit, $option_key, false );
										$settings .= '<option value="' . esc_attr( $option_key ) . '" ' . $selected . '>' . esc_html( $option_label ) . '</option>';
									}

									$settings .= '</select>';
									$settings .= '</div>';

									if ( ! empty( $before_after_id ) && ! empty( $before_after_options ) ) {
										$settings .= '<div class="ur-duration-row ur-duration-row-2">';
										$settings .= '<select
												name="' . esc_attr( $before_after_id ) . '"
												id="' . esc_attr( $before_after_id ) . '"
												class="ur-duration-before-after-select"
												style="display: inline-block; width: auto; min-width: 150px;">';

										foreach ( $before_after_options as $option_key => $option_label ) {
											$selected  = selected( $saved_before_after, $option_key, false );
											$settings .= '<option value="' . esc_attr( $option_key ) . '" ' . $selected . '>' . esc_html( $option_label ) . '</option>';
										}

										$settings .= '</select>';
										$settings .= '</div>';
									}

									$settings .= '<div class="ur-duration-row ur-duration-row-3 ur-trigger-event-display" style="display: none;">';
									$settings .= '<div class="ur-trigger-event-badge">';
									$settings .= '<span class="ur-trigger-event-icon dashicons dashicons-clock"></span>';
									$settings .= '<span class="ur-trigger-event-text"></span>';
									$settings .= '</div>';
									$settings .= '</div>';
									$settings .= '</div>';

									$settings .= wp_kses_post( $description );
									$settings .= '</div>';
									$settings .= '</div>';
									break;
								// Default: run an action.
								default:
									/**
									 * Filter to retrieve default admin field for output
									 *
									 * @param string $settings Settings.
									 * @param mixed $settings Field value.
									 */
									$settings = apply_filters( 'user_registration_admin_field_' . $value['type'], $settings, $value );
									break;
							}// End switch case.
						}
					} elseif ( isset( $section['settings'] ) && is_string( $section['settings'] ) ) {
						$settings .= $section['settings'];
					}

					/**
					 * Filter to retrieve extra settings for this section.
					 *
					 * @param string $settings Settings.
					 * @param mixed $options Section options.
					 */
					$settings = apply_filters( 'user_registration_admin_after_global_settings', $settings, $options, $section );

					$settings .= ' </div> ';
					$settings .= ' </div> ';

					if ( ! empty( $section['id'] ) ) {
						/**
						 * Action after output settings
						 */
						do_action( 'user_registration_settings_' . sanitize_title( $section['id'] ) . '_after' );
					}
				}// End foreach.
			}
		}
		echo $settings; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Capitalize Settings Title Phrase.
	 *
	 * @param string $text Setting Label.
	 */
	public static function capitalize_title( $text = null ) {
		$prepositions = array( 'at', 'by', 'for', 'in', 'on', 'to', 'or' );

		$words = explode( ' ', $text );

		$capitalized_words = array();

		foreach ( $words as $word ) {
			$word = trim( $word );
			if ( ! in_array( $word, $prepositions ) ) {

				// Check if the word is a shash separated terms. Eg: "Hide/Show".
				if ( strpos( $word, '/' ) ) {
					$separate_terms    = explode( '/', $word );
					$capitalized_terms = array();

					foreach ( $separate_terms as $term ) {
						$capitalized_terms[] = ucfirst( $term );
					}

					$word = implode( '/', $capitalized_terms );
				} elseif ( strpos( $word, 'CAPTCHA' ) ) {
					$word = $word;
				} else {
					$word = ucfirst( $word );
				}
			}
			$capitalized_words[] = $word;
		}

		return implode( ' ', $capitalized_words );
	}

	/**
	 * Helper function to get the formated description and tip HTML for a
	 * given form field. Plugins can call this when implementing their own custom
	 * settings types.
	 *
	 * @param array $value The form field value array.
	 *
	 * @return array The description and tip as a 2 element array
	 */
	public static function get_field_description( $value ) {
		$description  = '';
		$tooltip_html = '';
		$desc_field   = '';

		if ( true === $value['desc_tip'] ) {
			$tooltip_html = $value['desc'];
		} elseif ( ! empty( $value['desc_tip'] ) ) {
			$description  = $value['desc'];
			$tooltip_html = $value['desc_tip'];
		} elseif ( ! empty( $value['desc'] ) ) {
			$description = $value['desc'];
		}

		if ( ! empty( $value['desc_field'] ) ) {
			$desc_field = $value['desc_field'];
		}

		if ( $description && in_array( $value['type'], array( 'textarea', 'radio' ) ) ) {
			$description = ' <p style ="margin-top:0" > ' . wp_kses_post( $description ) . ' </p > ';
		} elseif ( $description && in_array( $value['type'], array( 'checkbox' ) ) ) {
			$description = wp_kses_post( $description );
		} elseif ( $description ) {
			$description = ' <span class = "description" > ' . wp_kses_post( $description ) . ' </span > ';
		}

		if ( $desc_field && in_array( $value['type'], array( 'textarea', 'radio', 'checkbox' ) ) ) {
			$desc_field = ' <p class = "description" > ' . wp_kses_post( $desc_field ) . ' </p > ';
		} elseif ( $desc_field ) {
			$desc_field = ' <span class = "description" > ' . wp_kses_post( $desc_field ) . ' </span > ';
		}

		if ( $tooltip_html ) {
			$tooltip_html = ur_help_tip( $tooltip_html );
		}

		return array(
			'description'  => $description,
			'desc_field'   => $desc_field,
			'tooltip_html' => $tooltip_html,
		);
	}

	/**
	 * Helper function to get display condition attributes for a field.
	 *
	 * @param array $value The form field value array.
	 *
	 * @return array Array with 'attrs' (HTML attributes) and 'initial_style' (inline style for initial visibility).
	 */
	public static function get_display_condition_attributes( $value ) {
		$attrs         = '';
		$initial_style = '';

		if ( ! empty( $value['display_condition'] ) && is_array( $value['display_condition'] ) ) {
			$condition = $value['display_condition'];

			// Field ID to depend on.
			if ( ! empty( $condition['field'] ) ) {
				$attrs .= ' data-display-condition-field="' . esc_attr( $condition['field'] ) . '"';
			}

			// Operator (equals, not_equals, contains, not_contains, empty, not_empty, greater_than, less_than, in, not_in).
			if ( ! empty( $condition['operator'] ) ) {
				$attrs .= ' data-display-condition-operator="' . esc_attr( $condition['operator'] ) . '"';
			} else {
				$attrs .= ' data-display-condition-operator="equals"'; // Default operator.
			}

			// Value to compare against.
			if ( isset( $condition['value'] ) ) {
				$value_json = is_array( $condition['value'] ) ? wp_json_encode( $condition['value'] ) : esc_attr( $condition['value'] );
				$attrs     .= ' data-display-condition-value="' . $value_json . '"';
			}

			// Case sensitivity (for string comparisons).
			if ( isset( $condition['case'] ) ) {
				$attrs .= ' data-display-condition-case="' . esc_attr( $condition['case'] ) . '"';
			}

			// Add class to identify fields with display conditions.
			$attrs .= ' data-has-display-condition="1"';

			// Check initial visibility state.
			$should_show = self::check_display_condition_initial( $condition );
			if ( ! $should_show ) {
				$initial_style = ' style="display:none;"';
			}
		}

		return array(
			'attrs'         => $attrs,
			'initial_style' => $initial_style,
		);
	}

	/**
	 * Check if a field should be visible initially based on its display condition.
	 *
	 * @param array $condition Display condition array.
	 *
	 * @return bool True if field should be visible, false otherwise.
	 */
	public static function check_display_condition_initial( $condition ) {
		if ( empty( $condition['field'] ) ) {
			return true;
		}

		$field_id        = $condition['field'];
		$operator        = ! empty( $condition['operator'] ) ? $condition['operator'] : 'equals';
		$condition_value = isset( $condition['value'] ) ? $condition['value'] : '';
		$case_sensitive  = isset( $condition['case'] ) ? $condition['case'] : 'insensitive';

		// Get current field value.
		$field_value = self::get_option( $field_id, '' );

		// Handle checkbox fields.
		if ( 'yes' === $field_value || '1' === $field_value || true === $field_value ) {
			$field_value = 'yes';
		} elseif ( empty( $field_value ) || 'no' === $field_value || '0' === $field_value || false === $field_value ) {
			$field_value = 'no';
		}

		// Convert to strings for comparison.
		$field_value_str     = is_array( $field_value ) ? implode( ',', $field_value ) : (string) $field_value;
		$condition_value_str = is_array( $condition_value ) ? implode( ',', $condition_value ) : (string) $condition_value;

		// Case sensitivity handling.
		if ( 'insensitive' === $case_sensitive || 'false' === $case_sensitive ) {
			$field_value_str     = strtolower( $field_value_str );
			$condition_value_str = strtolower( $condition_value_str );
		}

		// Evaluate condition.
		switch ( $operator ) {
			case 'equals':
			case '==':
				return $field_value_str === $condition_value_str;
			case 'not_equals':
			case '!=':
				return $field_value_str !== $condition_value_str;
			case 'contains':
				return false !== strpos( $field_value_str, $condition_value_str );
			case 'not_contains':
				return false === strpos( $field_value_str, $condition_value_str );
			case 'empty':
				return empty( $field_value ) || '' === $field_value_str || ( is_array( $field_value ) && empty( $field_value ) );
			case 'not_empty':
				return ! empty( $field_value ) && '' !== $field_value_str && ! ( is_array( $field_value ) && empty( $field_value ) );
			case 'greater_than':
			case '>':
				return is_numeric( $field_value ) && is_numeric( $condition_value ) && floatval( $field_value ) > floatval( $condition_value );
			case 'less_than':
			case '<':
				return is_numeric( $field_value ) && is_numeric( $condition_value ) && floatval( $field_value ) < floatval( $condition_value );
			case 'greater_than_or_equal':
			case '>=':
				return is_numeric( $field_value ) && is_numeric( $condition_value ) && floatval( $field_value ) >= floatval( $condition_value );
			case 'less_than_or_equal':
			case '<=':
				return is_numeric( $field_value ) && is_numeric( $condition_value ) && floatval( $field_value ) <= floatval( $condition_value );
			case 'in':
				if ( is_array( $condition_value ) ) {
					return in_array( $field_value, $condition_value, true ) || in_array( $field_value_str, $condition_value, true );
				}
				$values = explode( ',', $condition_value_str );
				return in_array( $field_value_str, $values, true );
			case 'not_in':
				if ( is_array( $condition_value ) ) {
					return ! in_array( $field_value, $condition_value, true ) && ! in_array( $field_value_str, $condition_value, true );
				}
				$values = explode( ',', $condition_value_str );
				return ! in_array( $field_value_str, $values, true );
			default:
				return $field_value_str === $condition_value_str;
		}
	}

	/**
	 * Get a setting from the settings API.
	 *
	 * @param mixed $option_name Option Name.
	 * @param mixed $default Default.
	 *
	 * @return string
	 */
	public static function get_option( $option_name, $default = '' ) {

		global $current_section;

		if ( 'popup' === $current_section ) {
			return $default;
		} else {
			// Array value.
			if ( null !== $option_name ) {
				if ( strstr( $option_name, '[' ) ) {
					parse_str( $option_name, $option_array );

					// Option name is first key.
					$option_name = current( array_keys( $option_array ) );

					// Get value.
					$option_values = get_option( $option_name, '' );

					$key = key( $option_array[ $option_name ] );

					if ( isset( $option_values[ $key ] ) ) {
						$option_value = $option_values[ $key ];
					} else {
						$option_value = null;
					}
				} else {
					$option_value = get_option( $option_name, $default );
				}
			}

			if ( is_array( $option_value ) ) {
				$option_value = array_map( 'stripslashes', $option_value );
			} elseif ( ! is_null( $option_value ) ) {
				$option_value = stripslashes( $option_value );
			}

			return null === $option_value ? $default : $option_value;
		}
	}

	/**
	 * Save admin fields.
	 *
	 * Loops though the user registration options array and outputs each field.
	 *
	 * @param array $options Options array to output.
	 *
	 * @return bool
	 */
	public static function save_fields( $options ) {
		if ( empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		// Options to update will be stored here and saved later.
		$update_options = array();

		if ( empty( $options ) ) {
			return false;
		}
		// Return if default wp_login is disabled and no redirect url is set.
		$is_wp_login_disabled_error = apply_filters( 'user_registration_settings_prevent_default_login', $_POST );
		if ( $is_wp_login_disabled_error && 'redirect_login_error' === $is_wp_login_disabled_error ) {
			return;
		} elseif ( $is_wp_login_disabled_error && 'invalid_renewal_period' === $is_wp_login_disabled_error ) {
			self::add_error(
				esc_html__(
					'Your settings has not been saved. Send Before days cannot be less than or equal to 0.',
					'user-registration'
				)
			);
		}

		// Loop options and get values to save.
		foreach ( $options['sections'] as $id => $section ) {
			if ( ! isset( $id ) || ! isset( $section['type'] ) || ! isset( $section['settings'] ) ) {
				continue;
			}

			foreach ( $section['settings'] as $option ) {

				// Skip color-group type - handled separately below
				if ( isset( $option['type'] ) && 'color-group' === $option['type'] ) {
					// Handle color-group fields - save as array structure: id => array('normal' => value, 'hover' => value)
					if ( ! empty( $option['id'] ) ) {
						$base_id = $option['id'];

						// Get states from settings, or use default states (normal and hover)
						$states_config = isset( $option['states'] ) && is_array( $option['states'] ) ? $option['states'] : array( 'normal', 'hover' );

						// Extract state keys
						$state_keys = array();
						foreach ( $states_config as $state_key => $state ) {
							if ( is_numeric( $state_key ) && is_string( $state ) ) {
								$state_keys[] = $state;
							} elseif ( is_array( $state ) && isset( $state['key'] ) ) {
								$state_keys[] = $state['key'];
							} else {
								$state_keys[] = $state_key;
							}
						}

						// Initialize the color array
						$color_array = array();

						// Collect all state values into array structure
						foreach ( $state_keys as $state ) {
							$state_id = $base_id . '_' . $state;

							// Check if the field exists in POST (even if empty)
							if ( array_key_exists( $state_id, $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification
								$state_raw_value = isset( $_POST[ $state_id ] ) ? wp_unslash( $_POST[ $state_id ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

								// Create a temporary option array for sanitization (same as regular color field)
								$state_option = array_merge(
									$option,
									array(
										'id'   => $state_id,
										'type' => 'color',
									)
								);
								$state_value  = ur_sanitize_value_by_type( $state_option, $state_raw_value );

								// Apply filters (same as regular color field)
								$state_value = apply_filters( 'user_registration_admin_settings_sanitize_option', $state_value, $state_option, $state_raw_value );
								$state_value = apply_filters( "user_registration_admin_settings_sanitize_option_$state_id", $state_value, $state_option, $state_raw_value );

								// Add to color array
								if ( ! is_null( $state_value ) ) {
									$color_array[ $state ] = $state_value;
								}
							}
						}

						// Save as array structure: id => array('normal' => value, 'hover' => value)
						// Same pattern as regular color field but as array
						if ( ! empty( $color_array ) ) {
							$update_options[ $base_id ] = $color_array;
						}
					}
					continue; // Skip the regular processing for color-group
				}

				// Get posted value.
				if ( isset( $option['id'] ) && null !== $option['id'] ) {
					if ( strstr( $option['id'], '[' ) ) {
						parse_str( $option['id'], $option_name_array );
						$option_name = sanitize_text_field( current( array_keys( $option_name_array ) ) );

						$setting_name = key( $option_name_array[ $option_name ] );
						$raw_value    = isset( $_POST[ $option_name ][ $setting_name ] ) ? wp_unslash( $_POST[ $option_name ][ $setting_name ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					} else {
						$option_name  = sanitize_text_field( $option['id'] );
						$setting_name = '';
						$raw_value    = isset( $_POST[ $option['id'] ] ) ? wp_unslash( $_POST[ $option['id'] ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					}
				}
				// Get posted value.
				$value = ur_sanitize_value_by_type( $option, $raw_value );

				/**
				 * Filter to Sanitize the value of an option.
				 *
				 * @param boolean $value String converted to Boolean
				 * @param mixed $option Option to save
				 * @param string $raw_value Option value to save
				 */
				$value = apply_filters( 'user_registration_admin_settings_sanitize_option', $value, $option, $raw_value );

				/**
				 * Filter to Sanitize the value of an option by option name.
				 *
				 * @param boolean $value String converted to Boolean
				 * @param mixed $option Option value to save
				 * @param string $raw_value Option value to save
				 */
				$value = apply_filters( "user_registration_admin_settings_sanitize_option_$option_name", $value, $option, $raw_value );

				if ( is_null( $value ) || count( self::$errors ) > 0 ) {
					continue;
				}

				// Check if option is an array and handle that differently to single values.
				if ( $option_name && $setting_name ) {
					if ( ! isset( $update_options[ $option_name ] ) ) {
						$update_options[ $option_name ] = get_option( $option_name, array() );
					}
					if ( ! is_array( $update_options[ $option_name ] ) ) {
						$update_options[ $option_name ] = array();
					}
					$update_options[ $option_name ][ $setting_name ] = $value;
				} else {
					$update_options[ $option_name ] = $value;
				}
			}
		}// End foreach().

		// Save all options in our array.
		foreach ( $update_options as $name => $value ) {
			// sync membership 'user_registration_member_registration_page_id' with 'user_registration_registration_page_id'.
			if ( 'user_registration_member_registration_page_id' === $name ) {
				update_option( 'user_registration_registration_page_id', $value );
			}
			if ( 'user_registration_login_page_id' === $name ) {
				update_option( 'user_registration_login_options_login_redirect_url', $value );
			}
			update_option( $name, $value );
		}

		return true;
	}

	/**
	 * Search GLobal Settings.
	 */
	public static function search_settings() {
		$search_string = isset( $_POST['search_string'] ) ? sanitize_text_field( wp_unslash( $_POST['search_string'] ) ) : ''; //phpcs:ignore;
		$search_url    = '';
		$found         = false;

		// Create an array of results to return as JSON.
		$autocomplete_results = array();
		$index                = 0;

		$settings = self::get_settings_pages();

		if ( ! empty( $settings ) ) {

			foreach ( $settings as $key => $section ) {
				if ( is_bool( $section ) || ( is_object( $section ) && ! method_exists( $section, 'get_settings' ) ) ) {
					unset( $settings[ $key ] );
					continue;
				}
				$reflection = new ReflectionProperty( get_class( $section ), 'id' );

				if ( ! $reflection->isPublic() ) {
					unset( $settings[ $key ] );
					continue;
				}
			}

			foreach ( $settings as $section ) {

				$subsections = array_values( array_unique( array_merge( array( '' ), array_keys( $section->get_sections() ) ) ) );

				if ( ! empty( $subsections ) ) {

					foreach ( $subsections as $subsection ) {

						if ( 'user-registration-invite-codes' === $section->id ) {
							if ( '' !== $subsection ) {
								$subsection_array = $section->get_settings( $subsection );
							}
						} else {
							switch ( $subsection ) {
								case 'login-options':
									$subsection_array = get_login_options_settings();
									break;
								case 'frontend-messages':
									$subsection_array = $section->get_frontend_messages_settings();
									break;
								default:
									$subsection_array = $section->get_settings( $subsection );
									break;
							}
						}

						if ( is_array( $subsection_array ) && ! empty( $subsection_array ) ) {
							$flattened_array = self::flatten_array( $subsection_array );
							$result          = self::search_string_in_array( $search_string, $flattened_array );
							if ( ! empty( $result ) ) {
								foreach ( $result as $key => $value ) {
									$match = array_search( $value['title'], array_column( $autocomplete_results, 'label' ), true ); //phpcs:ignore;
									if ( false === $match ) {
										$autocomplete_results[ $index ]['label'] = $value['title'];
										$autocomplete_results[ $index ]['desc']  = $value['desc'];
										if ( ! empty( $subsection ) ) {
											$autocomplete_results[ $index ]['value'] = admin_url( 'admin.php?page=user-registration-settings&tab=' . $section->id . '&section=' . $subsection . '&searched_option=' . $value['id'] );
										} else {
											$autocomplete_results[ $index ]['value'] = admin_url( 'admin.php?page=user-registration-settings&tab=' . $section->id . '&searched_option=' . $value['id'] );
										}
										++$index;
									}
								}
								continue;
							}
						}
					}
				}
			}
		}

		if ( ! empty( $autocomplete_results ) ) {
			wp_send_json_success(
				array(
					'results' => $autocomplete_results,
				)
			);
		} else {
			$autocomplete_results[ $index ]['label'] = __( 'No Search result found !', 'user-registration' );
			$autocomplete_results[ $index ]['desc']  = '';
			$autocomplete_results[ $index ]['value'] = 'no_result_found';

			wp_send_json_success(
				array(
					'results' => $autocomplete_results,
				)
			);
		}
	}

	/**
	 * Return Non Nested Array from Nested Settings Array
	 *
	 * @param array $nested_array Nested Settings Array.
	 *
	 * @return array
	 */
	public static function flatten_array( $nested_array ) {

		$settings_array = array();  // create an empty array to store the list of settings.
		if ( isset( $nested_array['sections'] ) ) {
			// loop through each section in the array.
			foreach ( $nested_array['sections'] as $section ) {

				if ( isset( $section['settings'] ) ) {

					if ( is_string( $section['settings'] ) ) {
						continue;
					}

					// loop through each setting in the section and add it to the $settings_array.
					foreach ( $section['settings'] as $setting ) {
						$settings_array[] = $setting;
					}
				} else {
					$inner_settings = self::flatten_array( $section );
					if ( ! empty( $inner_settings ) ) {
						$settings_array[] = $inner_settings;
					}
				}
			}
		}

		return $settings_array;
	}

	/**
	 * Search String in Array.
	 *
	 * @param string $string_to_search String to Search.
	 * @param array  $array Search Array.
	 */
	public static function search_string_in_array( $string_to_search, $array ) {
		$result = array();
		if ( is_object( $array ) ) {
			$array = (array) $array;
		}
		$index = 0;

		foreach ( $array as $key => $value ) {

			if ( is_array( $value ) ) {

				foreach ( $value as $text ) {
					if ( ! is_array( $text ) ) {
						if ( stripos( $text, $string_to_search ) !== false ) {

							$result[ $index ]['id']    = isset( $value['id'] ) ? $value['id'] : 'true';
							$result[ $index ]['title'] = isset( $value['title'] ) ? $value['title'] : 'true';
							$desc_tip                  = isset( $value['desc_tip'] ) && true !== $value['desc_tip'] ? $value['desc_tip'] : '';
							$desc                      = isset( $value['desc'] ) && true !== $value['desc'] ? $value['desc'] : '';
							$result[ $index ]['desc']  = ! empty( $desc_tip ) ? $desc_tip : $desc;
							++$index;
							break;
						}
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Load payment modules for AJAX and other requests.
	 */
	public static function load_payment_modules() {
		$modules = array();
		include_once __DIR__ . '/settings/class-ur-settings-page.php';
		// Always available.
		include_once __DIR__ . '/settings/class-ur-settings-payment.php';
		include_once __DIR__ . '/settings/class-ur-settings-membership.php';

		if ( UR_PRO_ACTIVE ) {
			if ( ur_check_module_activation( 'membership' ) ) {
				$modules = array(
					'class-ur-payment-settings.php',
					'stripe/class-ur-stripe-module.php',
					'paypal/class-ur-paypal-module.php',
					'membership/includes/Admin/Settings/class-ur-settings-membership.php',
				);
			} else {
				if ( ur_check_module_activation( 'payments' ) ) {
					$modules[] = 'class-ur-payment-settings.php';
					$modules[] = 'paypal/class-ur-paypal-module.php';
				}
				if ( is_plugin_active( 'user-registration-stripe/user-registration-stripe.php' ) ) {
					$modules[] = 'class-ur-payment-settings.php';
					$modules[] = 'stripe/class-ur-stripe-module.php';
				}
				if ( is_plugin_active( 'user-registration-authorize-net/user-registration-authorize-net.php' ) ) {
					$modules[] = 'class-ur-payment-settings.php';
				}
				if ( is_plugin_active( 'user-registration-mollie/user-registration-mollie.php' ) ) {
					$modules[] = 'class-ur-payment-settings.php';
				}
			}
		} elseif ( ur_check_module_activation( 'membership' ) ) {
			$modules = array(
				'class-ur-payment-settings.php',
				'stripe/class-ur-stripe-module.php',
				'paypal/class-ur-paypal-module.php',
				'membership/includes/Admin/Settings/class-ur-settings-membership.php',
			);
		}

		foreach ( $modules as $module ) {
			$module_path = UR_ABSPATH . 'modules/' . $module;
			if ( file_exists( $module_path ) ) {
				include_once $module_path;
			}
		}
	}
}
