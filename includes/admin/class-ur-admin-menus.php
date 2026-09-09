<?php
/**
 * Setup menus in WP admin.
 *
 * @class    UR_Admin_Menus
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

use WPEverest\URMembership\Admin\Members\Members;
use WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;
use WPEverest\URMembership\Admin\Subscriptions\Subscriptions;
use WPEverest\URMembership\Payment\Orders;
use WPEverest\URMembership\Coupons\Coupons;
use WPEverest\URPrivateNotes\UserRegistrationPrivateNotes;
use WPEverest\URPrivateNotes\Admin\Admin;
use WPEverest\URTeamMembership\Admin\Admin as URTeamMembershipAdmin;
use WPEverest\URTeamMembership\TeamMembership;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'UR_Admin_Menus', false ) ) :

	/**
	 * UR_Admin_Menus Class.
	 */
	class UR_Admin_Menus {

		/**
		 * UR_Admin_Menus Constructor.
		 */
		public function __construct() {

			// Add menus.
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 1 );
			add_action( 'admin_menu', array( $this, 'settings_menu' ), 20 );
			add_action( 'admin_menu', array( $this, 'add_registration_menu' ), 8 );
			add_action( 'admin_menu', array( $this, 'status_menu' ), 75 );
			add_action( 'admin_menu', array( $this, 'dashboard_menu' ), 3 );
			// add_action('admin_head', array($this, 'remove_duplicate_menu_items'));

			if ( is_plugin_active( 'user-registration-pro/user-registration.php' ) && empty( get_option( 'user-registration_license_key', '' ) ) ) {
				add_action( 'admin_menu', array( $this, 'activate_license_menu' ), 100 );
			}

			/**
			 * Filter to show the Addons Page
			 *
			 * @param boolean
			 */
			if ( apply_filters( 'user_registration_show_addons_page', true ) ) {
				add_action( 'admin_menu', array( $this, 'addons_menu' ), 80 );
			}

			if ( ! is_plugin_active( 'user-registration-pro/user-registration.php' ) && ! ur_get_license_plan() ) {
				add_action( 'admin_menu', array( $this, 'user_registration_upgrade_to_pro_menu' ), 81 );
			}

			// Set screens.
			add_filter( 'set-screen-option', array( $this, 'set_screen_option' ), 10, 3 );

			// Add endpoints custom URLs in Appearance > Menus > Pages.
			add_action( 'admin_head-nav-menus.php', array( $this, 'add_nav_menu_meta_boxes' ) );

			// Add all available upgradable fields.
			add_action(
				'ur_after_other_form_fields_printed',
				array(
					$this,
					'add_upgradable_other_fields',
				)
			); // Adds fields in the `Extra Fields` section.
			add_action( 'user_registration_extra_fields', array( $this, 'add_upgradable_extra_fields' ) );
		}

		/**
		 * Add Upgradable other fields.
		 */
		public function add_upgradable_other_fields() {
			$fields = array(
				array(
					'id'          => 'user_registration_file',
					'label'       => 'File Upload',
					'icon'        => 'ur-icon ur-icon-file-upload',
					'field_class' => 'UR_File',
					'plan'        => 'Personal Plan',
					'slug'        => 'file-upload',
					'video_id'    => 'pzhJ3qjQfC4',
					'name'        => __( 'User Registration - File Upload', 'user-registration' ),
				),
				array(
					'id'          => 'user_registration_invite_code',
					'label'       => 'Invitation Code',
					'icon'        => 'ur-icon ur-icon-invite-codes',
					'field_class' => 'UR_Form_Field_Invite_Code',
					'plan'        => 'ThemeGrill Agency Plan or Professional Plan or Plus Plan',
					'slug'        => 'invite-codes',
					'video_id'    => 'IhqQLAc-yuQ',
					'name'        => __( 'User Registration Invite Codes', 'user-registration' ),
				),
				array(
					'id'          => 'user_registration_learndash',
					'label'       => 'LearnDash Course',
					'icon'        => 'ur-icon ur-icon-course',
					'field_class' => 'UR_Form_Field_Learndash_Course',
					'plan'        => 'ThemeGrill Agency Plan or Professional Plan or Plus Plan',
					'slug'        => 'learndash',
					'name'        => __( 'User Registration LearnDash', 'user-registration' ),
				),
				array(
					'id'          => 'user_registration_stripe_gateway',
					'label'       => 'Stripe Gateway',
					'icon'        => 'ur-icon ur-icon-credit-card',
					'field_class' => 'UR_Form_Field_Stripe_Gateway',
					'plan'        => 'ThemeGrill Agency Plan or Professional Plan or Plus Plan',
					'video_id'    => 'TxmXryAqhXE',
					'slug'        => array( 'stripe' ),
					'name'        => array( __( 'User Registration Stripe', 'user-registration' ) ),
				),
			);

			foreach ( $fields as $field ) {
				if ( 'user_registration_learndash' === $field['id'] ) {
					if ( ! defined( 'LEARNDASH_VERSION' ) ) {
						continue;
					}
				}

				if ( ! class_exists( $field['field_class'] ) ) {
					$this->render_upgradable_field( $field );
				}
			}
		}

		/**
		 * Add Upgradable extra fields.
		 */
		public function add_upgradable_extra_fields() {
			$field_sections = array(
				array(
					'section_title'       => 'Advanced Fields',
					'fields_parent_class' => 'URAF_Admin',
					'plan'                => 'Personal Plan',
					'slug'                => 'advanced-fields',
					'name'                => __( 'User Registration-Advanced Fields', 'user-registration' ),
					'fields'              => array(
						array(
							'id'       => 'user_registration_section_title',
							'label'    => 'Section Title',
							'video_id' => 'fp04hfHF_zU',
							'icon'     => 'ur-icon ur-icon-section-title',
						),
						array(
							'id'       => 'user_registration_html',
							'label'    => 'HTML',
							'video_id' => 'vuQhnp2s24U',
							'icon'     => 'ur-icon ur-icon-code',
						),
						array(
							'id'       => 'user_registration_timepicker',
							'label'    => 'Time Picker',
							'video_id' => 'Z8F7h9_d_rc',
							'icon'     => 'ur-icon ur-icon-time-picker',
						),
						array(
							'id'       => 'user_registration_phone',
							'label'    => 'Phone',
							'video_id' => '3yS0s1LYI-I',
							'icon'     => 'ur-icon ur-icon-phone',
						),
						array(
							'id'       => 'user_registration_wysiwyg',
							'label'    => 'WYSIWYG',
							'video_id' => 'LR_RZ8Xn1RA',
							'icon'     => 'ur-icon ur-icon-text-editor',
						),
						array(
							'id'       => 'user_registration_select2',
							'label'    => 'Select2',
							'video_id' => 'wzcUYN-ToTA',
							'icon'     => 'ur-icon ur-icon-select2',
						),
						array(
							'id'       => 'user_registration_multi_select2',
							'label'    => 'Multi Select2',
							'video_id' => '6o1beUJ26b8',
							'icon'     => 'ur-icon ur-icon-multi-select',
						),
						array(
							'id'       => 'user_registration_profile_picture',
							'label'    => 'Profile Picture',
							'video_id' => 'Ev-_bnuVaGQ',
							'icon'     => 'ur-icon ur-icon-user-display-name',
						),
						array(
							'id'       => 'user_registration_range',
							'label'    => 'Range',
							'video_id' => 'MCfHeWGIOmk',
							'icon'     => 'ur-icon ur-icon-range',
						),
						array(
							'id'       => 'user_registration_custom_url',
							'label'    => 'Custom URL',
							'video_id' => 'yei0lH9esaQ',
							'icon'     => 'ur-icon ur-icon-website',
						),
						array(
							'id'       => 'user_registration_hidden',
							'label'    => 'Hidden Field',
							'video_id' => 'WtLSzeL17-s',
							'icon'     => 'ur-icon ur-icon-hidden-field',
						),
					),
				),
				array(
					'section_title'       => 'WooCommerce Billing Address',
					'fields_parent_class' => 'URWC_Admin',
					'plan'                => 'Personal Plan',
					'slug'                => 'woocommerce',
					'name'                => __( 'User Registration - WooCommerce', 'user-registration' ),
					'fields'              => array(
						array(
							'id'       => 'user_registration_billing_address_title',
							'label'    => 'Billing Address',
							'video_id' => 'CujmKsb6zTk',
							'icon'     => 'ur-icon ur-icon-bill',
						),
						array(
							'id'       => 'user_registration_billing_country',
							'label'    => 'Country',
							'video_id' => '6POWImSKKMI',
							'icon'     => 'ur-icon ur-icon-flag',
						),
						array(
							'id'       => 'user_registration_billing_first_name',
							'label'    => 'First Name',
							'video_id' => 'MmBF9WH3bG4',
							'icon'     => 'ur-icon ur-icon-input-first-name',
						),
						array(
							'id'       => 'user_registration_billing_last_name',
							'label'    => 'Last Name',
							'video_id' => 'ZprJivoQmYk',
							'icon'     => 'ur-icon ur-icon-input-last-name',
						),
						array(
							'id'       => 'user_registration_billing_company',
							'label'    => 'Company',
							'video_id' => 'fdK38cCrybI',
							'icon'     => 'ur-icon ur-icon-buildings',
						),
						array(
							'id'       => 'user_registration_billing_address_1',
							'label'    => 'Address 1',
							'video_id' => 'CujmKsb6zTk',
							'icon'     => 'ur-icon ur-icon-map-one',
						),
						array(
							'id'       => 'user_registration_billing_address_2',
							'label'    => 'Address 2',
							'video_id' => 'CujmKsb6zTk',
							'icon'     => 'ur-icon ur-icon-map-two',
						),
						array(
							'id'       => 'user_registration_billing_city',
							'label'    => 'Town / City',
							'video_id' => '224NTyvz5-g',
							'icon'     => 'ur-icon ur-icon-buildings',
						),
						array(
							'id'       => 'user_registration_billing_state',
							'label'    => 'State / County',
							'video_id' => 'YtamDyhfFcc',
							'icon'     => 'ur-icon ur-icon-state',
						),
						array(
							'id'       => 'user_registration_billing_postcode',
							'label'    => 'Postcode / Zip',
							'video_id' => 'sgaJNqkrX4E',
							'icon'     => 'ur-icon ur-icon-zip-code',
						),
						array(
							'id'       => 'user_registration_billing_email',
							'label'    => 'Email',
							'video_id' => '3LV1lYJZ4k0',
							'icon'     => 'ur-icon ur-icon-email',
						),
						array(
							'id'       => 'user_registration_billing_phone',
							'label'    => 'Phone',
							'video_id' => 'mU9k3EufPUQ',
							'icon'     => 'ur-icon ur-icon-phone',
						),
						array(
							'id'       => 'user_registration_separate_shipping',
							'label'    => 'Separate Shipping',
							'video_id' => '4ZA6byVHm30',
							'icon'     => 'ur-icon ur-icon-bill',
						),
					),
				),
				array(
					'section_title'       => 'WooCommerce Shipping Address',
					'fields_parent_class' => 'URWC_Admin',
					'plan'                => 'Personal Plan',
					'slug'                => 'woocommerce',
					'name'                => __( 'User Registration - WooCommerce', 'user-registration' ),
					'fields'              => array(
						array(
							'id'       => 'user_registration_shipping_address_title',
							'label'    => 'Shipping Address',
							'video_id' => 'CujmKsb6zTk',
							'icon'     => 'ur-icon ur-icon-bill',
						),
						array(
							'id'       => 'user_registration_shipping_country',
							'label'    => 'Country',
							'video_id' => '6POWImSKKMI',
							'icon'     => 'ur-icon ur-icon-flag',
						),
						array(
							'id'       => 'user_registration_shipping_first_name',
							'label'    => 'First Name',
							'video_id' => 'MmBF9WH3bG4',
							'icon'     => 'ur-icon ur-icon-input-first-name',
						),
						array(
							'id'       => 'user_registration_shipping_last_name',
							'label'    => 'Last Name',
							'video_id' => 'ZprJivoQmYk',
							'icon'     => 'ur-icon ur-icon-input-last-name',
						),
						array(
							'id'       => 'user_registration_shipping_company',
							'label'    => 'Company',
							'video_id' => 'fdK38cCrybI',
							'icon'     => 'ur-icon ur-icon-buildings',
						),
						array(
							'id'       => 'user_registration_shipping_address_1',
							'label'    => 'Address 1',
							'video_id' => 'CujmKsb6zTk',
							'icon'     => 'ur-icon ur-icon-map-one',
						),
						array(
							'id'       => 'user_registration_shipping_address_2',
							'label'    => 'Address 2',
							'video_id' => 'CujmKsb6zTk',
							'icon'     => 'ur-icon ur-icon-map-two',
						),
						array(
							'id'       => 'user_registration_shipping_city',
							'label'    => 'Town / City',
							'video_id' => '224NTyvz5-g',
							'icon'     => 'ur-icon ur-icon-buildings',
						),
						array(
							'id'       => 'user_registration_shipping_state',
							'label'    => 'State / County',
							'video_id' => 'YtamDyhfFcc',
							'icon'     => 'ur-icon ur-icon-state',
						),
						array(
							'id'       => 'user_registration_shipping_postcode',
							'label'    => 'Postcode / Zip',
							'video_id' => 'sgaJNqkrX4E',
							'icon'     => 'ur-icon ur-icon-zip-code',
						),
					),
				),
				array(
					'section_title'       => 'Payment Fields',
					'fields_parent_class' => 'User_Registration_Payments_Admin',
					'plan'                => 'ThemeGrill Agency Plan or Professional Plan or Plus Plan',
					'slug'                => array( 'payments', 'stripe' ),
					'name'                => array(
						__( 'User Registration Payments', 'user-registration' ),
						__( 'User Registration Stripe', 'user-registration' ),
					),
					'fields'              => array(
						array(
							'id'       => 'user_registration_single_item',
							'label'    => 'Single Item',
							'video_id' => 'xH_lLxg8zsQ',
							'icon'     => 'ur-icon ur-icon-file-dollar',
						),
						array(
							'id'       => 'user_registration_multiple_choice',
							'label'    => 'Multiple Choice',
							'video_id' => '_vu87WQBbx8',
							'icon'     => 'ur-icon ur-icon-multichoice',
						),
						array(
							'id'       => 'user_registration_subscription_plan',
							'label'    => 'Subscription Plan',
							'video_id' => 'h__3fJu5Bvo',
							'icon'     => 'ur-icon ur-icon-subscription-plan',
						),
						array(
							'id'       => 'user_registration_total',
							'label'    => 'Total',
							'video_id' => 'wsaxpXXbE6c',
							'icon'     => 'ur-icon ur-icon-total',
						),
						array(
							'id'       => 'user_registration_quantity',
							'label'    => 'Quantity',
							'video_id' => 'Hjml64__-6c',
							'icon'     => 'ur-icon ur-icon-quantity',
						),
					),
				),
			);

			foreach ( $field_sections as $section ) {
				$class_to_check = $section['fields_parent_class'];

				if ( ! class_exists( $class_to_check ) ) {
					$fields       = $section['fields'];
					$plan         = isset( $section['plan'] ) ? $section['plan'] : '';
					$slug         = isset( $section['slug'] ) ? $section['slug'] : '';
					$name         = isset( $section['name'] ) ? $section['name'] : '';
					$fields_count = count( $fields );

					// Set the same plan for all the section's fields.
					for ( $i = 0; $i < $fields_count; $i++ ) {
						$fields[ $i ]['plan'] = $plan;
						$fields[ $i ]['slug'] = $slug;
						$fields[ $i ]['name'] = $name;
					}

					echo '<h2 class="ur-toggle-heading closed">' . esc_html( $section['section_title'] ) . '</h2><hr/>';
					echo '<ul id = "ur-upgradables" class="ur-registered-list" > ';
					$this->render_upgradable_fields( $fields );
					echo '</ul >';
				}
			}
		}

		/**
		 * Render multiple upgradable fields.
		 *
		 * @param array $fields Field.
		 */
		public function render_upgradable_fields( $fields ) {
			foreach ( $fields as $field ) {
				$this->render_upgradable_field( $field );
			}
		}

		/**
		 * Render an upgradable field.
		 *
		 * @param array $args Args Data.
		 */
		public function render_upgradable_field( $args ) {

			$id       = $args['id'];
			$icon     = $args['icon'];
			$label    = $args['label'];
			$plan     = isset( $args['plan'] ) ? $args['plan'] : '';
			$video_id = isset( $args['video_id'] ) ? $args['video_id'] : '';

			$name = isset( $args['name'] ) ? ( is_array( $args['name'] ) ? implode( ', and ', $args['name'] ) : $args['name'] ) : '';

			if ( isset( $args['slug'] ) ) {
				if ( is_array( $args['slug'] ) ) {
					$new_args_slug = array();

					foreach ( $args['slug'] as $args_slug ) {
						array_push( $new_args_slug, 'user-registration-' . $args_slug );
					}

					$slug = implode( ' ', $new_args_slug );
				} else {
					$slug = 'user-registration-' . $args['slug'];
				}
			}
			echo '<li id="' . esc_attr( $id ) . '_list " class="ur-registered-item ur-upgradable-field ui-draggable-disabled" data-field-id="' . esc_attr( $id ) . '" data-name="' . esc_attr( $name ) . '" data-plan="' . esc_attr( $plan ) . '" data-slug ="' . esc_attr( $slug ) . '" data-video ="' . esc_attr( $video_id ) . '"><span class="' . esc_attr( $icon ) . '"></span>' . esc_html( $label ) . '</li>';
		}

		/**
		 * Returns a base64 URL for the SVG for use in the menu.
		 *
		 * @param bool $base64 Whether or not to return base64-encoded SVG.
		 *
		 * @return string
		 */
		private function get_icon_svg( $base64 = true ) {
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path fill="#82878c" d="M27.58 4a27.9 27.9 0 0 0-5.17 4 27 27 0 0 0-4.09 5.08 33.06 33.06 0 0 1 2 4.65A23.78 23.78 0 0 1 24 12.15V18a8 8 0 0 1-5.89 7.72l-.21.05a27 27 0 0 0-1.9-8.16A27.9 27.9 0 0 0 9.59 8a27.9 27.9 0 0 0-5.17-4L4 3.77V18a12 12 0 0 0 9.93 11.82h.14a11.72 11.72 0 0 0 3.86 0h.14A12 12 0 0 0 28 18V3.77zM8 18v-5.85a23.86 23.86 0 0 1 5.89 13.57A8 8 0 0 1 8 18zm8-16a3 3 0 1 0 3 3 3 3 0 0 0-3-3z"/></svg>';

			if ( $base64 ) {
				return 'data:image/svg+xml;base64,' . base64_encode( $svg );
			}

			return $svg;
		}

		/**
		 * Add menu items.
		 */
		public function admin_menu() {

			$registration_page = add_menu_page(
				'User Registration',
				'User Registration & Membership',
				'manage_user_registration',
				'user-registration',
				array(
					$this,
					'registration_page',
				),
				$this->get_icon_svg(),
				'55.8'
			);

			add_action( 'load-' . $registration_page, array( $this, 'registration_page_init' ) );

			add_action(
				'admin_menu',
				function () {
					global $submenu;

					if ( isset( $submenu['user-registration'] ) ) {
						foreach ( $submenu['user-registration'] as $key => $item ) {
							if ( $item[0] === 'User Registration & Membership' ) {
								unset( $submenu['user-registration'][ $key ] );
								break;
							}
						}
					}
				},
				999
			);

			if ( class_exists( 'WPEverest\URMembership\Admin\Membership\Membership' ) && ur_check_module_activation( 'membership' ) ) {
				$membership_obj = new WPEverest\URMembership\Admin\Membership\Membership();

				$rules_page = add_submenu_page(
					'user-registration',
					__( 'Memberships', 'user-registration' ), // page title
					__( 'Memberships', 'user-registration' ), // menu title
					'edit_posts', // capability
					'user-registration-membership', // slug
					array(
						$membership_obj,
						'render_membership_page',
					),
					2
				);
				add_action( 'load-' . $rules_page, array( $membership_obj, 'membership_initialization' ) );

				$membership_groups_repository = new MembershipGroupRepository();
				$membership_groups            = $membership_groups_repository->get_all_membership_groups();
				$group_installation_flag      = get_option( 'urm_group_module_installation_flag', false );

				if ( ur_check_module_activation( 'membership-groups' ) || ( ! $group_installation_flag && ! empty( $membership_groups ) ) ) {
					add_submenu_page(
						'user-registration',
						__( 'Membership Groups', 'user-registration' ),
						__( 'Groups', 'user-registration' ),
						'manage_user_registration',
						'user-registration-membership&action=list_groups',
						array(
							$membership_obj,
							'render_membership_page',
						),
						3
					);
				}
			}

			$membership_rules_count = 0;
			if ( function_exists( 'ur_get_membership_rules_count' ) ) {
				$membership_rules_count = ur_get_membership_rules_count();
			}

			if ( ur_check_module_activation( 'content-restriction' ) || $membership_rules_count >= 2 ) {
				$content_rules = new \URCR_Admin();
				$content_rules->add_urcr_menus();
			}

			// Instantiate regardless of the gate: the constructor wires the invoice
			// download handler and gateway options, which must keep working even when
			// the Payments submenu itself is hidden.
			$orders_obj = new Orders();

			if ( ur_should_show_payments_menu() ) {
				$orders_obj->add_orders_menu();
			}

			if ( ur_check_module_activation( 'membership' ) ) {
				// Instantiated for its admin_init delete handler, see above.
				$subscription_obj = new Subscriptions();

				if ( ur_should_show_subscriptions_menu() ) {
					$subscription_obj->add_menu();
				}
			}

			if ( UR_PRO_ACTIVE && ur_check_module_activation( 'coupon' ) && class_exists( 'WPEverest\URMembership\Coupons\Coupons' ) ) {
				$coupons_obj = new Coupons();
				$coupons_obj->add_coupons_menu();
			}

			$all_forms = ur_get_all_user_registration_form();
			$postfix   = count( $all_forms ) > 1 ? 'Forms' : 'Form';

			if ( count( $all_forms ) > 1 || ur_check_module_activation( 'multiple-registration' ) ) {
				add_submenu_page(
					'user-registration',
					__( 'All Forms', 'user-registration' ),
					__( 'All Forms', 'user-registration' ),
					'manage_user_registration',
					'user-registration',
					array(
						$this,
						'registration_page',
					),
					10
				);

				if ( isset( $_GET['page'] ) && in_array(
						$_GET['page'],
						array(
							'user-registration',
							'user-registration-login-forms',
						)
					) ) {
					add_submenu_page(
						'user-registration',
						__( 'Registration Forms', 'user-registration' ),
						'↳ ' . sprintf( __( 'Registration %s', 'user-registration' ), $postfix ),
						'manage_user_registration',
						'user-registration',
						array(
							$this,
							'registration_page',
						),
						10
					);
					add_submenu_page(
						'user-registration',
						__( 'Login Form', 'user-registration' ),
						'↳ ' . __( 'Login Form', 'user-registration' ),
						'manage_user_registration',
						'user-registration-login-forms',
						array(
							$this,
							'registration_page',
						),
						11
					);
				}
			} else {
				add_submenu_page(
					'user-registration',
					__( 'Registration Form', 'user-registration' ),
					sprintf( __( 'Registration %s', 'user-registration' ), $postfix ),
					'manage_user_registration',
					'user-registration',
					array(
						$this,
						'registration_page',
					),
				);
				add_submenu_page(
					'user-registration',
					__( 'Login Form', 'user-registration' ),
					__( 'Login Form', 'user-registration' ),
					'manage_user_registration',
					'user-registration-login-forms',
					array(
						$this,
						'registration_page',
					)
				);
			}

			if ( ! class_exists( 'User_Registration_Members_Menu' ) ) {
				require_once UR_ABSPATH . 'includes/admin/settings/class-ur-members-menu.php';
			}

			if ( class_exists( 'User_Registration_Members_Menu' ) ) {
				$members_obj = new \User_Registration_Members_Menu();
				$members_obj->add_members_menu_tab();
			}

			if ( UR_PRO_ACTIVE && class_exists( 'WPEverest\URFrontendListing\Admin\Admin' ) && ur_check_module_activation( 'frontend-listing' ) ) {
				$members_dir_obj = new \WPEverest\URFrontendListing\Admin\Admin();
				$members_dir_obj->user_list_menu();
			}

			if ( UR_PRO_ACTIVE && class_exists( 'WPEverest\URMembership\Admin\Membership\Membership' ) && ur_check_module_activation( 'membership' ) && ur_check_module_activation( 'team' ) && class_exists( 'WPEverest\URTeamMembership\Admin\Admin' ) ) {
				$team_obj = new URTeamMembershipAdmin();
				$team_obj->add_teams_menu();
			}

			if ( UR_PRO_ACTIVE && class_exists( 'WPEverest\URPrivateNotes\UserRegistrationPrivateNotes' ) && version_compare( UR_PRIVATE_NOTES_VERSION, '1.0.12', '>' ) ) {
				$private_notes_obj = new Admin();
				$private_notes_obj->private_notes_menu();
			}
		}

		/**
		 * Loads screen options into memory.
		 */
		public function registration_page_init() {
			global $registration_table_list;

			if ( ! isset( $_GET['add-new-registration'] ) ) {  //phpcs:ignore WordPress.Security.NonceVerification
				$registration_table_list = new UR_Admin_Registrations_Table_List();
				$registration_table_list->process_actions();

				// Add screen option.
				add_screen_option(
					'per_page',
					array(
						'default' => 20,
						'option'  => 'user_registration_per_page',
					)
				);
			}

			$all_forms = ur_get_all_user_registration_form();

			if ( ( ! empty( $all_forms ) && count( $all_forms ) <= 1 && ! ur_check_module_activation( 'multiple-registration' ) ) ) {
				$form_id          = key( $all_forms );
				$form_id_from_url = isset( $_GET['edit-registration'] ) ? absint( $_GET['edit-registration'] ) : '';

				if ( ! isset( $_GET['edit-registration'] ) || $form_id_from_url != $form_id ) {
					wp_redirect( admin_url( 'admin.php?page=add-new-registration&edit-registration=' . $form_id ) );
					exit;
				}
			}
		}

		public function settings_menu() {
			add_submenu_page(
				'user-registration',
				__( 'User Registration settings', 'user-registration' ),
				__( 'Settings', 'user-registration' ),
				'manage_user_registration',
				'user-registration-settings',
				array(
					$this,
					'settings_page',
				)
			);
		}

		/**
		 * Add status menu item.
		 */
		public function status_menu() {
			add_submenu_page(
				'user-registration',
				__( 'User Registration Tools', 'user-registration' ),
				__( 'Tools', 'user-registration' ),
				'manage_user_registration',
				'user-registration-status',
				array(
					$this,
					'status_page',
				)
			);

			if ( isset( $_GET['page'] ) && in_array(
					$_GET['page'],
					array(
						'user-registration-status',
						'user-registration-status&tab=logs',
						'user-registration-status&tab=system_info',
					)
				) ) {

				add_submenu_page(
					'user-registration',
					__( 'Logs', 'user-registration' ),
					'↳ ' . __( 'Logs', 'user-registration' ),
					'manage_user_registration',
					'user-registration-status&tab=logs',
					array(
						$this,
						'status_page',
					),
					76
				);

				add_submenu_page(
					'user-registration',
					__( 'System Info', 'user-registration' ),
					'↳ ' . __( 'System Info', 'user-registration' ),
					'manage_user_registration',
					'user-registration-status&tab=system_info',
					array(
						$this,
						'status_page',
					),
					77
				);

				$is_new_installation = ur_string_to_bool( get_option( 'urm_is_new_installation', '' ) );

				if ( $is_new_installation ) {
					add_submenu_page(
						'user-registration',
						__( 'Setup Wizard', 'user-registration' ),
						'↳ ' . __( 'Setup Wizard', 'user-registration' ),
						'manage_user_registration',
						'user-registration-welcome&tab=setup-wizard',
						array(
							$this,
							'status_page',
						),
						78
					);
				}
			}
		}

		/**
		 * Add dashboard sub menu.
		 */
		public function dashboard_menu() {
			$config_remaining_count = ur_site_assistant_config_count();

			add_submenu_page(
				'user-registration',
				__( 'User Registration Dashboard', 'user-registration' ),
				sprintf(
					'%1$s <span class ="ur-site-config-count">%2$s</span>',
					__( 'Site Assistant', 'user-registration' ),
					$config_remaining_count
				),
				'manage_user_registration',
				'user-registration-dashboard',
				array(
					$this,
					'dashboard_page',
				),
				-1
			);

			$should_show = ur_should_show_site_assistant_menu();
			if ( ! $should_show ) {
				add_action(
					'admin_head',
					function () {
						echo '<style>
						#toplevel_page_user-registration .wp-submenu li a[href="admin.php?page=user-registration-dashboard"] {
							display: none !important;
						}
						#toplevel_page_user-registration .wp-submenu li:has(a[href="admin.php?page=user-registration-dashboard"]) {
							display: none !important;
						}
   					</style>';
					}
				);
			} else {
				add_action(
					'admin_body_class',
					function ( $classes ) {
						return $classes . ' dashboard-visible';
					}
				);
			}
		}

		/**
		 * Add new registration menu items.
		 */
		public function add_registration_menu() {
			add_submenu_page(
				'user-registration',
				esc_html__( 'Add New', 'user-registration' ),
				esc_html__( 'Add New', 'user-registration' ),
				'manage_user_registration',
				'add-new-registration',
				array(
					$this,
					'add_registration_page',
				)
			);

			/**
			 * Hides the Add New Button from the submenu
			 *
			 * @since 5.0.0
			 */
			add_action(
				'admin_head',
				function () {
					global $submenu;
					if ( isset( $submenu['user-registration'] ) ) {
						foreach ( $submenu['user-registration'] as $key => $item ) {
							if ( isset( $item[2] ) && $item[2] === 'add-new-registration' ) {
								unset( $submenu['user-registration'][ $key ] );
								break;
							}
						}
					}
				}
			);
		}

		/**
		 * Upgrade to pro menu items.
		 */
		public function user_registration_upgrade_to_pro_menu() {
			add_submenu_page(
				'user-registration',
				esc_html__( 'Upgrade to Pro', 'user-registration' ),
				sprintf(
					'<span style="color:#FF8C39; font-weight: 600; display: flex;"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: bottom;" ><rect x="0.5" y="0.5" width="19" height="19" rx="2.5" fill="#FF8C39" stroke="#FF8C39"/><path d="M10 5L13 13H7L10 5Z" fill="#EFEFEF"/><path fill="white" fill-rule="evenodd" d="M5 7L5.71429 13H14.2857L15 7L10 11.125L5 7ZM14.2857 13.5714H5.71427V15H14.2857V13.5714Z" clip-rule="evenodd"/></svg><span style="margin-left:5px;">%s</span></span>',
					esc_html__( 'Upgrade to Pro', 'user-registration' )
				),
				'manage_options',
				esc_url_raw( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'ur-submenu', 'medium' => 'upgrade-link' ) ) )
			);
		}

		/**
		 * Addons menu item.
		 */
		public function addons_menu() {
			add_submenu_page(
				'user-registration',
				__( 'User Registration extensions', 'user-registration' ),
				esc_html__( 'Addons', 'user-registration' ),
				'manage_options',
				'user-registration-dashboard#features',
				array( $this, 'redirect_to_addons_page' )
			);
		}

		/**
		 * Addons page redirection.
		 */
		public function redirect_to_addons_page() {
			wp_safe_redirect( esc_url_raw( admin_url( 'admin.php?page=user-registration-dashboard#features' ) ) );
			exit;
		}

		/**
		 * Add license menu item.
		 */
		public function activate_license_menu() {
			add_submenu_page(
				'user-registration',
				__( 'Activate License', 'user-registration' ),
				sprintf(
					'<span style="color: #FF8C39; display: flex;">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" style="fill: currentColor; vertical-align: middle;">
							<circle cx="6" cy="12" r="4" stroke="currentColor" stroke-width="2"/>
							<circle cx="6" cy="12" r="1.5" stroke="currentColor" stroke-width="1"/>
							<line x1="10" y1="12" x2="20" y2="12" stroke="currentColor" stroke-width="2"/>
							<line x1="17" y1="12" x2="17" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<line x1="19" y1="12" x2="19" y2="14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
						<span style="margin-left: 5px;">%s</span>
					</span>',
					esc_html__( 'Activate License', 'user-registration' )
				),
				'manage_options',
				'user-registration-activate-license',
				array( $this, 'redirect_to_license_tab' ),
			);
		}

		/**
		 * License page redirection.
		 */
		public function redirect_to_license_tab() {
			wp_safe_redirect( esc_url_raw( admin_url( 'admin.php?page=user-registration-settings&tab=license' ) ) );
			exit;
		}

		/**
		 * Validate screen options on update.
		 *
		 * @param mixed $status Status.
		 * @param mixed $option Option.
		 * @param mixed $value Value.
		 */
		public function set_screen_option( $status, $option, $value ) {
			if ( in_array( $option, array( 'user_registration_per_page' ), true ) ) {
				return $value;
			}

			return $status;
		}

		/**
		 * Init the settings page.
		 */
		public function registration_page() {
			global $registration_table_list;
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			$all_forms = ur_get_all_user_registration_form();

			if ( isset( $_GET['page'] ) && 'user-registration-login-forms' === $_GET['page'] ) { //phpcs:ignore WordPress.Security.NonceVerification
				wp_enqueue_script(
					'user-registration-login-settings',
					UR()->plugin_url() . '/assets/js/admin/login-settings' . $suffix . '.js',
					array(
						'jquery',
						'jquery-ui-datepicker',
						'jquery-ui-sortable',
						'iris',
						'tooltipster',
						'jquery-ui-tabs',
						'sweetalert2',
					),
					UR_VERSION,
					true
				);
				wp_enqueue_style( 'user-registration-css', UR()->plugin_url() . '/assets/css/user-registration.css', array(), UR_VERSION );
				$login_settings      = array_merge( get_login_form_settings()['sections']['login_options_settings']['settings'], get_login_field_settings()['sections']['login_options_settings']['settings'], get_login_form_settings()['sections']['login_options_settings_advanced']['settings'] );
				$ur_enabled_captchas = array();
				$ur_captchas         = ur_get_captcha_integrations();
				foreach ( $ur_captchas as $key => $value ) {
					if ( get_option( 'user_registration_captcha_setting_recaptcha_enable_' . $key, false ) ) {
						$ur_enabled_captchas[ $key ] = $value;
					}
				}
				$no_captcha_set        = ( count( $ur_enabled_captchas ) < 1 );
				$captcha_not_set_error = sprintf(
				/* translators: %s - Integration tab url */
					'%s <a href="%s" class="ur-captcha-error" rel="noreferrer noopener" target="_blank">here</a> to add them and save your form.',
					esc_html__( 'Seems like you are trying to enable the captcha feature, but the captcha keys are empty. Please click', 'user-registration' ),
					esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=captcha' ) )
				);
				$ur_login_form_params = array(
					'ajax_url'                    => admin_url( 'admin-ajax.php' ),
					'ur_login_settings_save'      => wp_create_nonce( 'ur_login_settings_save_nonce' ),
					'login_settings'              => $login_settings,
					'is_login_settings_page'      => isset( $_GET['page'] ) && 'user-registration-login-forms' === $_GET['page'] ? true : false,
					'i18n_admin'                  => self::get_i18n_admin_data(),
					'user_registration_lost_password_selection_validator_nonce' => wp_create_nonce( 'user_registration_lost_password_selection_validator' ),
					'user_registration_my_account_selection_validator_nonce' => wp_create_nonce( 'user_registration_my_account_selection_validator' ),
					'user_registration_membership_redirect_default_page_message' => esc_html__( 'Please select a page for redirection', 'user-registration' ),
					'email_confirmation_disabled' => ur_string_to_bool( get_option( 'user_registration_enable_email_confirmation', true ) ) ? 'no' : 'yes',
					'ur_embed_page_list'          => wp_create_nonce( 'ur_embed_page_list_nonce' ),
					'ur_embed_action'             => wp_create_nonce( 'ur_embed_action_nonce' ),

				);
				wp_localize_script(
					'user-registration-login-settings',
					'ur_login_form_params',
					$ur_login_form_params
				);
				wp_localize_script(
					'user-registration-settings',
					'ur_login_form_params',
					$ur_login_form_params
				);
				$login_option_settings = get_login_field_settings();
				$login_form_settings   = get_login_form_settings();
				$login_page_id         = get_option( 'user_registration_login_page_id', 0 );
				$login_page_url        = $login_page_id ? get_permalink( $login_page_id ) : '';
				$login_page_title      = $login_page_id ? get_the_title( $login_page_id ) : '';
				include_once __DIR__ . '/views/html-login-page-forms.php';
			} else {
				$registration_table_list->display_page();
			}
		}

		/**
		 * Localize admin data.
		 *
		 * @return array
		 */
		public static function get_i18n_admin_data() {
			$max_upload_size_ini = wp_max_upload_size() / 1024;

			$i18n = array(
				'i18n_choice_delete'                   => esc_html__( 'Delete', 'user-registration' ),
				'i18n_choice_cancel'                   => esc_html__( 'Cancel', 'user-registration' ),
				'i18n_success'                         => _x( 'Success', 'user registration admin', 'user-registration' ),
				'i18n_error'                           => _x( 'Error', 'user registration admin', 'user-registration' ),
				'i18n_msg_delete'                      => esc_html__( 'Confirm Deletion', 'user-registration' ),
				'i18n_embed_form_title'                => esc_html__( 'Embed in Page', 'user-registration' ),
				'i18n_embed_description'               => esc_html__( 'We can help embed your form with just a few clicks!', 'user-registration' ),
				'i18n_embed_to_existing_page'          => esc_html__( 'Select Existing Page', 'user-registration' ),
				'i18n_embed_to_new_page'               => esc_html__( 'Create New Page', 'user-registration' ),
				'i18n_embed_existing_page_description' => esc_html__( 'Select the page to embed your form in.', 'user-registration' ),
				'i18n_embed_go_back_btn'               => esc_html__( 'Go Back', 'user-registration' ),
				'i18n_embed_lets_go_btn'               => esc_html__( "Let's Go!", 'user-registration' ),
				'i18n_embed_new_page_description'      => esc_html__( 'What would you like to call the new page?', 'user-registration' ),

				'i18n_settings_successfully_saved'     => _x( 'Settings successfully saved.', 'user registration admin', 'user-registration' ),
				'i18n_success'                         => _x( 'Success', 'user registration admin', 'user-registration' ),
				'i18n_error'                           => _x( 'Error', 'user registration admin', 'user-registration' ),

			);

			return $i18n;
		}


		/**
		 * Init the add registration page.
		 */
		public function add_registration_page() {
			$form_id   = isset( $_GET['edit-registration'] ) ? absint( $_GET['edit-registration'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification
			$form_data = ( $form_id ) ? UR()->form->get_form( $form_id ) : array();

			$save_label = __( 'Create Form', 'user-registration' );
			if ( ! empty( $form_data ) ) {
				$save_label   = __( 'Update form', 'user-registration' );
				$preview_link = add_query_arg(
					array(
						'ur_preview' => 'true',
						'form_id'    => $form_id,
					),
					home_url()
				);
			}

			if ( isset( $_GET['onboarding-skipped'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				update_option( 'user_registration_onboarding_skipped', true );
			}
			wp_enqueue_script( 'ur-setup' );
			wp_enqueue_style( 'ur-toast' );
			wp_enqueue_script( 'ur-enhanced-select-custom' );

			wp_add_inline_script(
				'ur-enhanced-select-custom',
				"
			(function($) {
				'use strict';
				$(document).ready(function() {
					$('.ur-multiselect').select2();
				});
			})(jQuery);
			"
			);

			wp_localize_script(
				'ur-setup',
				'ur_setup_params',
				array(
					'ajax_url'                     => admin_url( 'admin-ajax.php' ),
					'create_form_nonce'            => wp_create_nonce( 'user_registration_create_form' ),
					'template_licence_check_nonce' => wp_create_nonce( 'user_registration_template_licence_check' ),
					'captcha_setup_check_nonce'    => wp_create_nonce( 'user_registration_captcha_setup_check' ),
					'i18n_form_name'               => esc_html__( 'Give it a name.', 'user-registration' ),
					'i18n_form_error_name'         => esc_html__( 'You must provide a Form name', 'user-registration' ),
					'i18n_install_only'            => esc_html__( 'Activate Plugins', 'user-registration' ),
					'i18n_activating'              => esc_html__( 'Activating', 'user-registration' ),
					'i18n_activating_text'         => esc_html__( 'Please wait until the plugin is being activated', 'user-registration' ),
					'i18n_install_activate'        => esc_html__( 'Install & Activate', 'user-registration' ),
					'i18n_installing'              => esc_html__( 'Installing', 'user-registration' ),
					'i18n_ok'                      => esc_html__( 'OK', 'user-registration' ),

					/**
					 * Filter the Upgrade URL
					 *
					 * @param string Upgrade URL
					 */
					'upgrade_url'                  => apply_filters( 'user_registration_upgrade_url', ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'form-template', 'medium' => 'button' ) ) ),
					'upgrade_button'               => esc_html__( 'Upgrade Plan', 'user-registration' ),
					'upgrade_message'              => esc_html__( 'This template requires premium addons. Please upgrade to the Premium plan to unlock all these awesome Templates.', 'user-registration' ),
					'upgrade_title'                => esc_html__( 'is a Premium Template', 'user-registration' ),
					'i18n_form_ok'                 => esc_html__( 'Continue', 'user-registration' ),
					'i18n_form_placeholder'        => esc_html__( 'Untitled Form', 'user-registration' ),
					'i18n_form_title'              => esc_html__( 'Uplift your form experience to the next level.', 'user-registration' ),
					'download_failed'              => esc_html__( 'Download Failed. Please download and activate addon manually.', 'user-registration' ),
					'download_successful_title'    => esc_html__( 'Installation Successful.', 'user-registration' ),
					'download_successful_message'  => esc_html__( 'Addons have been installed and Activated. You have to reload the page.', 'user-registration' ),
					'i18n_activation_success'     => esc_html__( 'Successfully activated. Reloading...', 'user-registration' ),
					'save_changes_text'            => esc_html__( 'Save Changes and Reload', 'user-registration' ),
					'save_changes_warning'         => esc_html__( 'Save changes before activating the plugin', 'user-registration' ),
					'reload_text'                  => esc_html__( 'Just Reload', 'user-registration' ),
				)
			);

			$all_forms = ur_get_all_user_registration_form();

			if ( ( ! empty( $all_forms ) && count( $all_forms ) <= 1 && ! ur_check_module_activation( 'multiple-registration' ) ) ) {
				$form_id          = key( $all_forms );
				$form_id_from_url = isset( $_GET['edit-registration'] ) ? absint( $_GET['edit-registration'] ) : '';

				if ( ! isset( $_GET['edit-registration'] ) || $form_id_from_url != $form_id ) {
					wp_redirect( admin_url( 'admin.php?page=add-new-registration&edit-registration=' . $form_id ) );
					exit;
				}
			}

			if ( isset( $_GET['edit-registration'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// Forms view.
				include_once __DIR__ . '/views/html-admin-page-forms.php';
			} else {
				UR_Admin_Form_Templates::load_template_view();
			}

			// Forms view.
			include_once __DIR__ . '/views/html-admin-page-forms.php';
		}

		/**
		 * Init the settings page.
		 */
		public function settings_page() {
			UR_Admin_Settings::output();
		}

		/**
		 * Init the status page.
		 */
		public function status_page() {
			UR_Admin_Status::output();
		}

		/**
		 * Init the dashboard page.
		 */
		public function dashboard_page() {
			UR_Admin_Dashboard::output();
		}

		/**
		 * Add custom nav meta box.
		 *
		 * Adapted from http://www.johnmorrisonline.com/how-to-add-a-fully-functional-custom-meta-box-to-wordpress-navigation-menus/.
		 */
		public function add_nav_menu_meta_boxes() {
			add_meta_box(
				'user_registration_endpoints_nav_link',
				__( 'URM Menus', 'user-registration' ),
				array(
					$this,
					'nav_menu_links',
				),
				'nav-menus',
				'side',
				'low'
			);
		}

		/**
		 * Output menu links.
		 */
		public function nav_menu_links() {
			// Get items from account menu.
			$endpoints = ur_get_account_menu_items();

			// Remove dashboard item.
			if ( isset( $endpoints['dashboard'] ) ) {
				unset( $endpoints['dashboard'] );
			}

			// Include missing lost password.
			$endpoints['ur-lost-password'] = __( 'Lost password', 'user-registration' );

			/**
			 * Filter to get the custom Navigation Menu Endpoints
			 *
			 * @param array $endpoints Endpoints
			 */
			$endpoints = apply_filters( 'user_registration_custom_nav_menu_items', $endpoints );

			?>
			<div id="posttype-user-registration-endpoints" class="posttypediv">
				<div id="tabs-panel-user-registration-endpoints" class="tabs-panel tabs-panel-active">
					<ul id="user-registration-endpoints-checklist" class="categorychecklist form-no-clear">
						<?php
						$i = - 1;
						foreach ( $endpoints as $key => $value ) :
							?>
							<li>
								<label class="menu-item-title">
									<input type="checkbox" class="menu-item-checkbox"
										   name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-object-id]"
										   value="<?php echo esc_attr( $i ); ?>"/> <?php echo esc_html( $value ); ?>
								</label>
								<input type="hidden" class="menu-item-type"
									   name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-type]" value="custom"/>
								<input type="hidden" class="menu-item-title"
									   name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-title]"
									   value="<?php echo esc_html( $value ); ?>"/>
								<input type="hidden" class="menu-item-url"
									   name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-url]"
									   value="<?php echo esc_url( ur_get_account_endpoint_url( $key ) ); ?>"/>
								<input type="hidden" class="menu-item-classes"
									   name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-classes]"/>
							</li>
							<?php
							--$i;
						endforeach;
						?>
					</ul>
				</div>
				<p class="button-controls">
					<span class="list-controls">
						<input type="checkbox" id="ur-endpoints-tab" class="select-all">
						<label for="ur-endpoints-tab">
							<?php esc_html_e( 'Select All', 'user-registration' ); ?>
						</label>
					</span>
					<span class="add-to-menu">
						<input type="submit" class="button-secondary submit-add-to-menu right"
							   value="<?php esc_attr_e( 'Add to menu', 'user-registration' ); ?>"
							   name="add-post-type-menu-item"
							   id="submit-posttype-user-registration-endpoints">
						<span class="spinner"></span>
					</span>
				</p>
			</div>
			<?php
		}

		/**
		 * Get Edit Form Field.
		 *
		 * @param object $form_data Form Data.
		 *
		 * @throws Exception Throws exception if error in json.
		 */
		private function get_edit_form_field( $form_data ) {
			$form_row_data = array();

			if ( ! empty( $form_data ) ) {
				$form_data_content = $form_data->post_content;
				$form_row_ids      = get_post_meta( $form_data->ID, 'user_registration_form_row_ids', true );
				$form_row_data     = get_post_meta( $form_data->ID, 'user_registration_form_row_data', true );
			} else {
				$form_data_content = '';
				$form_row_ids      = '';
				$form_row_data     = '';
			}

			try {
				$form_data_content = str_replace( '"noopener noreferrer"', "'noopener noreferrer'", $form_data_content );
				$form_data_array   = json_decode( $form_data_content );

				if ( json_last_error() !== JSON_ERROR_NONE ) {
					throw new Exception( '' );
				}
			} catch ( Exception $e ) {
				$form_data_array = array();
			}

			try {
				$form_row_ids_array = json_decode( $form_row_ids );

				if ( json_last_error() !== JSON_ERROR_NONE ) {
					throw new Exception( '' );
				}
			} catch ( Exception $e ) {
				$form_row_ids_array = array();
			}

			try {
				$form_row_data_array = json_decode( $form_row_data, true );

				if ( json_last_error() !== JSON_ERROR_NONE ) {
					throw new Exception( esc_html__( 'Row data not found', 'user-registration' ) );
				}
			} catch ( Exception $e ) {
				$form_row_data_array = array();
			}

			echo '<div class="ur-selected-inputs">';
			echo '<div class="ur-builder-wrapper-content">';
			?>
			<div class="ur-builder-header">
				<div class="user-registration-editable-title ur-form-name-wrapper ur-my-4">
					<?php
					$form_title = isset( $form_data->post_title ) ? trim( $form_data->post_title ) : __( 'Untitled', 'user-registration' );
					?>
					<input name="ur-form-name" id="ur-form-name" type="text"
						   class="user-registration-editable-title__input ur-form-name regular-text menu-item-textbox"
						   value="<?php echo esc_html( $form_title ); ?>" data-editing="false">
					<span id="ur-form-name-edit-button"
						  class="user-registration-editable-title__icon ur-edit-form-name dashicons dashicons-edit"></span>
				</div>
				<div class="ur-builder-header-right">
					<?php

					/**
					 * Action for Extra Information of Builder Header
					 *
					 * @param int $form_data ->ID Form ID
					 * @param mixed $form_data_array Form Data
					 */
					do_action( 'user_registration_builder_header_extra', $form_data->ID, $form_data_array );
					?>
				</div>
			</div>
			<?php
			echo '<div class="ur-input-grids">';

			$row_id  = 0;
			$last_id = 0;

			foreach ( $form_data_array as $index => $rows ) {
				$row_id  = ( ! empty( $form_row_ids ) ) ? $form_row_ids_array[ $index ] : $index;
				$last_id = ( absint( $row_id ) > $last_id ) ? absint( $row_id ) : $last_id;

				$grid_count = count( $rows );

				$grid_one   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M28,6V26H4V6H28m2-2H2V28H30V4Z"/></svg>';
				$grid_two   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M17,4H2V28H30V4ZM4,26V6H15V26Zm24,0H17V6H28Z"/></svg>';
				$grid_three = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M22,4H2V28H30V4ZM4,26V6h6V26Zm8,0V6h8V26Zm16,0H22V6h6Z"/></svg>';

				$svg_args = array(
					'svg'   => array(
						'class'           => true,
						'aria-hidden'     => true,
						'aria-labelledby' => true,
						'role'            => true,
						'xmlns'           => true,
						'width'           => true,
						'height'          => true,
						'viewbox'         => true, // <= Must be lower case!
					),
					'g'     => array( 'fill' => true ),
					'title' => array( 'title' => true ),
					'path'  => array(
						'd'    => true,
						'fill' => true,
					),
				);

				$row_class      = '';
				$attributes     = '';
				$repeater_label = '';
				foreach ( $form_row_data_array as $row_data ) {
					if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'user-registration-repeater-fields/user-registration-repeater-fields.php' ) ) {
						if ( isset( $row_data['row_id'] ) && $row_id === $row_data['row_id'] && isset( $row_data['type'] ) && 'repeater' === $row_data['type'] && isset( $row_data['repeater_id'] ) ) {
							$row_class      = 'ur-repeater-row';
							$attributes     = 'data-repeater-id=' . $row_data['repeater_id'] . '';
							$repeater_label = '<div class="ur-repeater-label" id="user_registration_repeater_row_title_' . esc_attr( $row_data['repeater_id'] ) . '"><label>' . esc_html__( 'Repeater Row', 'user-registration' ) . '</label></div>';
						}
					}
				}

				echo '<div class="ur-single-row ' . esc_attr( $row_class ) . '"  data-row-id="' . esc_attr( absint( $row_id ) ) . '" ' . esc_attr( $attributes ) . '>';
				?>

				<div class="ur-grids">
					<button type="button" class="ur-edit-grid">
						<?php
						if ( 1 === $grid_count ) {
							echo wp_kses( $grid_one, $svg_args );
						} elseif ( 2 === $grid_count ) {
							echo wp_kses( $grid_two, $svg_args );
						} elseif ( 3 === $grid_count ) {
							echo wp_kses( $grid_three, $svg_args );
						}
						?>
					</button>
					<?php
					do_action( 'user_registration_form_grid_options' );
					?>
					<button type="button" class="dashicons dashicons-no-alt ur-remove-row"></button>
					<div class="ur-toggle-grid-content" style="display:none">
						<small>Select the grid column.</small>
						<div class="ur-grid-selector" data-grid="1">
							<?php

							echo wp_kses( $grid_one, $svg_args );
							?>
						</div>
						<div class="ur-grid-selector" data-grid="2">
							<?php echo wp_kses( $grid_two, $svg_args ); ?>
						</div>
						<div class="ur-grid-selector" data-grid="3">
							<?php echo wp_kses( $grid_three, $svg_args ); ?>
						</div>
					</div>
				</div>

				<?php
				echo wp_kses_post( $repeater_label );
				echo '<div class="ur-grid-lists">';

				$grid_id = 0;

				foreach ( $rows as $grid_lists ) {

					++$grid_id;

					echo '<div ur-grid-id="' . esc_attr( $grid_id ) . '" class="ur-grid-list-item ui-sortable" style="width: 48%; min-height: 70px;">';

					foreach ( $grid_lists as $single_field ) {

						if ( isset( $single_field->field_key ) ) {
							/**
							 * Filter to get the Backward Compatibility for Fields
							 *
							 * @param array $single_field Field
							 */
							apply_filters( 'user_registration_form_builder_field_before', $single_field );

							$admin_field = $this->get_admin_field( $single_field );
							echo '<div class="ur-selected-item">';
							echo '<div class="ur-action-buttons"><span title="Clone" class="dashicons dashicons-admin-page ur-clone"></span><span title="Trash" class="dashicons dashicons-trash ur-trash"></span></div>';
							$template = isset( $admin_field['template'] ) ? $admin_field['template'] : ''; // @codingStandardsIgnoreLine
							echo $template; // phpcs:ignore
							echo '</div>';
						}
					}

					if ( count( $grid_lists ) === 0 ) {
						echo '<div class="user-registration-dragged-me">
						<div class="user-registration-dragged-me-text"><p>' . esc_html__( 'Drag your first form item here.', 'user-registration' ) . '</p></div>
						</div>';
					}

					echo '</div>';
				}

				echo '</div>';
				echo '</div>';

			}
			echo '<div class="ur-row-buttons" data-total-rows="' . esc_attr( $last_id ) . '">';
			echo '<button type="button" class="button button-primary dashicons dashicons-plus-alt ur-add-new-row">' . esc_html__( 'Add New', 'user-registration' ) . '</button>';
			do_action( 'user_registration_form_builder_row_buttons' );
			echo '</div>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}

		/**
		 * Get admin field.
		 *
		 * @param object $single_field Single field.
		 *
		 * @throws Exception Throw exception if empty form data.
		 */
		public static function get_admin_field( $single_field ) {

			if ( empty( $single_field->field_key ) ) {
				throw new Exception( esc_html__( 'Empty form data', 'user-registration' ) );
			}

			$class_name = 'UR_Form_Field_' . ucwords( $single_field->field_key );

			if ( class_exists( $class_name ) ) {
				return $class_name::get_instance()->get_admin_template( $single_field ); // @codingStandardsIgnoreLine
			}

			/* Backward Compat since 1.4.0 */
			$class_name_old = 'UR_' . ucwords( $single_field->field_key );
			if ( class_exists( $class_name_old ) ) {
				return $class_name_old::get_instance()->get_admin_template( $single_field );
			}
			/* Backward compat end */
		}

		/**
		 * Get registered user form fields.
		 */
		private function get_registered_user_form_fields() {

			$registered_form_fields = ur_get_user_field_only();

			echo ' <ul id = "ur-draggabled" class="ur-registered-list" > ';

			foreach ( $registered_form_fields as $field ) {

				$this->ur_get_list( $field );
			}
			echo ' </ul > ';
		}

		/**
		 * Get Registered other form field.
		 */
		private function get_registered_other_form_fields() {

			$registered_form_fields = ur_get_other_form_fields();

			echo ' <ul id = "ur-draggabled" class="ur-registered-list" > ';

			foreach ( $registered_form_fields as $field ) {

				$this->ur_get_list( $field );
			}

			/**
			 * Action to perform after printing other form field
			 */
			do_action( 'ur_after_other_form_fields_printed' );
			echo ' </ul > ';
		}

		/**
		 * Get Admin field List.
		 *
		 * @param mixed $field Fields.
		 */
		public function ur_get_list( $field ) {

			$class_name = ur_load_form_field_class( $field );

			if ( null !== $class_name ) {
				echo wp_kses_post( $class_name::get_instance()->get_registered_admin_fields() );
			}
		}
	}

endif;

return new UR_Admin_Menus();
