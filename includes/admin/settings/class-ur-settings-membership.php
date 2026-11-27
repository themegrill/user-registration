<?php
/**
 * Class UR_Settings_Membership
 *
 * Handles the membership related settings for the User Registration & Membership plugin.
 *
 * This class is responsible for:
 * - Membership Settings.
 * - Content Restriction Settings.
 *
 * @package   UserRegistration\Admin
 * @version   5.0.0
 * @since     5.0.0
 */

if ( ! class_exists( 'UR_Settings_Membership' ) ) {
	/**
	 * UR_Settings_Membership Class
	 */
	class UR_Settings_Membership extends UR_Settings_Page {
		private static $_instance = null; // phpcs:ignore
		/**
		 * Constructor.
		 */
		private function __construct() {
			$this->id    = 'membership';
			$this->label = __( 'Membership', 'user-registration' );
			parent::__construct();
			$this->handle_hooks();
		}

		/**
		 * Singleton class instance.
		 *
		 * @return UR_Settings_Membership
		 */
		public static function get_instance() {
			if ( null === self::$_instance ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}
		/**
		 * Register hooks for submenus and section UI.
		 *
		 * @return void
		 */
		public function handle_hooks() {
			add_filter( "user_registration_get_sections_{$this->id}", array( $this, 'get_sections_callback' ), 1, 1 );
			add_filter( "user_registration_get_settings_{$this->id}", array( $this, 'get_settings_callback' ), 1, 1 );
		}

		/**
		 * Filter to provide sections submenu for membership settings.
		 *
		 * @param array $sections Settings section.
		 */
		public function get_sections_callback( $sections ) {
			$sections['general']       = __( 'General', 'user-registration' );
			$sections['content-rules'] = __( 'Content Restriction', 'user-registration' );

			return $sections;
		}

		/**
		 * Filter to provide sections UI for membership settings.
		 *
		 * @param array $settings Settings array.
		 * @return array
		 */
		public function get_settings_callback( $settings ) {
			global $current_section;
			if ( 'general' === $current_section ) {
				/**
				 * Filter to add the options on settings.
				 *
				 * @param array Options to be enlisted.
				 */
				$settings = apply_filters( 'user_registration_membership_settings', $this->get_general_membership_settings() );
			} elseif ( 'content-rules' === $current_section ) {
				$settings = $this->urcr_settings();
			}
			return $settings;
		}

		/**
		 * General membership settings (Renewal Behaviour), or an empty-state
		 * notice in place of it when no active membership plan exists yet.
		 *
		 * @return array
		 */
		private function get_general_membership_settings() {
			$card = array(
				'title' => __( 'General', 'user-registration' ),
				'type'  => 'card',
			);

			$plan_state = $this->get_membership_plan_state();

			if ( 'active' !== $plan_state ) {
				if ( 'inactive' === $plan_state ) {
					$card['desc'] = sprintf(
						/* translators: %s - Admin URL to the membership plan list */
						__( '<strong>No active membership plan.</strong> Activate a plan to configure the Renewal Behaviour. <a href="%s">Manage memberships &rarr;</a>', 'user-registration' ),
						admin_url( 'admin.php?page=user-registration-membership' )
					);
				} else {
					$card['desc'] = sprintf(
						/* translators: %s - Admin URL to create a new membership plan */
						__( '<strong>No membership plans yet.</strong> Set up a plan to configure the Renewal Behaviour. <a href="%s">Create a membership &rarr;</a>', 'user-registration' ),
						admin_url( 'admin.php?page=user-registration-membership&action=add_new_membership' )
					);
				}

				$card['settings'] = array();
			} else {
				$is_new_installation = ur_string_to_bool( get_option( 'urm_is_new_installation', '' ) );
				if ( ! $is_new_installation ) {
					$card['desc'] = sprintf(
						/* translators: %s - Admin URL for membership page settings */
						__( '<strong>Membership page setting has moved.</strong> Configure your membership page <a href="%s">here</a>.', 'user-registration' ),
						admin_url( 'admin.php?page=user-registration-settings&tab=general&section=pages' )
					);
				}
				$card['settings'] = array(
					array(
						'title'    => __( 'Renewal Behaviour', 'user-registration' ),
						'desc'     => __( 'Choose how membership subscriptions are renewed. Renew Automatically bills each cycle through the payment provider. Renew Manually charges for one period only (Stripe, PayPal, Authorize.Net, Mollie); members renew from My Account.', 'user-registration' ),
						'id'       => 'user_registration_renewal_behaviour',
						'type'     => 'select',
						'default'  => 'automatic',
						'class'    => 'ur-enhanced-select',
						'css'      => '',
						'options'  => array(
							'automatic' => __( 'Renew Automatically', 'user-registration' ),
							'manual'    => __( 'Renew Manually', 'user-registration' ),
						),
						'desc_tip' => true,
					),
				);
			}

			return array(
				'title'    => '',
				'sections' => array(
					'membership_settings' => $card,
				),
			);
		}

		/**
		 * State of the published membership plans.
		 *
		 * Deactivated plans do not count as usable, since there is nothing to
		 * renew while every plan is switched off.
		 *
		 * @return string One of 'active' (at least one active plan exists),
		 *                'inactive' (plans exist but all are deactivated) or
		 *                'none' (no plan has been created yet).
		 */
		private function get_membership_plan_state() {
			if ( ! class_exists( 'WPEverest\URMembership\Admin\Repositories\MembershipRepository' ) ) {
				return 'active';
			}

			$membership_repository = new WPEverest\URMembership\Admin\Repositories\MembershipRepository();
			$memberships           = $membership_repository->get_all_memberships_without_status_filter();
			$has_plan              = false;

			foreach ( $memberships as $membership ) {
				if ( ! isset( $membership['post_status'] ) || 'publish' !== $membership['post_status'] ) {
					continue;
				}

				$has_plan = true;
				$status   = isset( $membership['post_content']['status'] ) ? $membership['post_content']['status'] : false;

				if ( ur_string_to_bool( $status ) ) {
					return 'active';
				}
			}

			return $has_plan ? 'inactive' : 'none';
		}

		/**
		 * Content restriction settings.
		 *
		 * @return array
		 */
		public function urcr_settings() {
			// Build sections array.
			$sections = array();

			$default_message = '<h3>' . __( 'Membership Required', 'user-registration' ) . '</h3>
<p>' . __( 'This content is available to members only.', 'user-registration' ) . '</p>
<p>' . __( 'Sign up to unlock access or log in if you already have an account.', 'user-registration' ) . '</p>
<p>{{sign_up}} {{log_in}}</p>';
			if ( class_exists( 'URCR_Admin_Assets' ) ) {
				$default_message = URCR_Admin_Assets::get_default_message();
			}

			$global_rule_id   = get_option( 'urcr_global_rule_id', '' );
			$content_rule_url = admin_url( 'admin.php' ) . '?page=user-registration-content-restriction';
			if ( ! empty( $global_rule_id ) ) {
				$content_rule_url .= '&id=' . $global_rule_id;
			}

			$has_membership_plans = false;
			if ( class_exists( 'WPEverest\URMembership\Admin\Repositories\MembershipRepository' ) ) {
				$membership_repository = new \WPEverest\URMembership\Admin\Repositories\MembershipRepository();
				$has_membership_plans  = ! empty( $membership_repository->get_all_memberships_without_status_filter() );
			}

			/*
			 * Rules that prove content restriction is actually in use:
			 * - 'custom' rules, which can only be created in Pro.
			 * - the auto-migrated "Legacy: Global Site Rule" (flagged with urcr_is_global), present on
			 *   older installs that used the old global restriction setting, in both free and Pro.
			 * Membership-generated rules are deliberately excluded, they are already covered by
			 * $has_membership_plans.
			 */
			$has_content_rules = false;
			if ( post_type_exists( 'urcr_access_rule' ) ) {
				$has_content_rules = (bool) get_posts(
					array(
						'post_type'      => 'urcr_access_rule',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array(
							'relation' => 'OR',
							array(
								'key'   => 'urcr_rule_type',
								'value' => 'custom',
							),
							array(
								'key'     => 'urcr_is_global',
								'compare' => 'EXISTS',
							),
						),
					)
				);
			}

			$has_restriction_in_use = $has_membership_plans || $has_content_rules;

			$sections['user_registration_content_restriction_settings'] = array(
				'title'    => __( 'Content Restriction', 'user-registration' ),
				'type'     => 'card',
				'settings' => $has_restriction_in_use ? array(
					array(
						'title'                            => __( 'Global Restriction Message', 'user-registration' ),
						'desc'                             => __( ' Default message for all restricted content.', 'user-registration' ),
						'id'                               => 'user_registration_content_restriction_message',
						'type'                             => 'tinymce',
						'default'                          => $default_message,
						'css'                              => '',
						'show-smart-tags-button'           => true,
						'show-ur-registration-form-button' => false,
						'show-reset-content-button'        => false,
						'desc_tip'                         => true,
					),
				) : array(),
			);

			if ( ! $has_restriction_in_use ) {
				$create_membership_url = admin_url( 'admin.php?page=user-registration-membership&action=add_new_membership' );

				if ( defined( 'UR_PRO_ACTIVE' ) && UR_PRO_ACTIVE && ur_check_module_activation( 'content-restriction' ) ) {
					$sections['user_registration_content_restriction_settings']['desc'] = sprintf(
						/* translators: 1: Add new membership URL, 2: Content rule URL */
						__( '<strong>No membership plans yet.</strong> Restrict content by creating a membership plan or setting up Content Rules. <a href="%1$s">Create a membership &rarr;</a> <a href="%2$s">Set up Content Rules &rarr;</a>', 'user-registration' ),
						esc_url( $create_membership_url ),
						esc_url( $content_rule_url )
					);
				} else {
					$sections['user_registration_content_restriction_settings']['desc'] = sprintf(
						/* translators: %s - Add new membership URL */
						__( '<strong>No membership plans yet.</strong> Create a membership plan to start restricting content and customize this message. <a href="%s">Create a membership &rarr;</a>', 'user-registration' ),
						esc_url( $create_membership_url )
					);
				}
			} else {
				$is_new_installation = ur_string_to_bool( get_option( 'urm_is_new_installation', '' ) );
				if ( ! $is_new_installation ) {
					$sections['user_registration_content_restriction_settings']['desc'] = sprintf(
						/* translators: %s - Content rule URL */
						__( '<strong>The Global Restriction setting has moved.</strong> You can now manage it <a href="%1$s" target="_blank" style="text-decoration: underline;" >here.</a>', 'user-registration' ),
						esc_url_raw( $content_rule_url )
					);
				}
			}

			return apply_filters(
				'user_registration_content_restriction_settings',
				array(
					'title'    => __( 'Content Restriction Settings', 'user-registration' ),
					'desc'     => '',
					'sections' => $sections,
				)
			);
		}
	}
}

// Backward Compatibility.
return method_exists( 'UR_Settings_Membership', 'get_instance' ) ? UR_Settings_Membership::get_instance() : new UR_Settings_Membership();
