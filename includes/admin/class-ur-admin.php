<?php
/**
 * UserRegistration Admin.
 *
 * @class    UR_Admin
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

use WPEverest\URMembership\Admin\Services\MembershipService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Admin Class
 */
class UR_Admin {

	/**
	 * UR_Admin Constructor
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'includes' ) );
		add_action( 'init', array( $this, 'translation_migration' ) );
		add_action( 'init', array( $this, 'run_migration_script' ) );
		if ( ur_check_module_activation( 'membership' ) ) {
			add_action( 'init', array( $this, 'run_membership_migration_script' ) );
		}
		add_action( 'current_screen', array( $this, 'conditional_includes' ) );
		add_action( 'admin_init', array( $this, 'prevent_admin_access' ), 10, 2 );
		add_action( 'load-users.php', array( $this, 'live_user_read' ), 10, 2 );
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ), 1 );
		add_action( 'delete_user', 'ur_unlink_user_profile_pictures' );
		add_action( 'admin_footer', 'ur_print_js', 25 );
		add_filter( 'heartbeat_received', array( $this, 'new_user_live_notice' ), 10, 2 );
		add_filter( 'admin_body_class', array( $this, 'user_registration_add_body_classes' ) );
		add_action( 'admin_init', array( $this, 'admin_redirects' ) );
		add_action( 'admin_init', array( $this, 'template_actions' ) );
		add_filter( 'display_post_states', array( $this, 'ur_add_post_state' ), 10, 2 );
		add_action( 'user_registration_after_form_settings', array( $this, 'render_integration_section' ) );
		add_action( 'user_registration_after_form_settings', array( $this, 'render_integration_List_section' ) );
		add_action( 'init', array( $this, 'init_users_menu' ) );
	}

	/**
	 * Initialize Users Menu.
	 *
	 * @return void
	 */
	public function init_users_menu() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_user_registration' ) ) {
			return;
		}

		require_once UR_ABSPATH . 'includes/admin/settings/class-ur-members-menu.php';
	}

	/**
	 * Migration script for membership module.
	 *
	 * This script is responsible for creating a default membership group and a registration form with membership field.
	 * It also replaces the old membership form shortcode with the newly created form.
	 *
	 * @return void
	 */
	public function run_membership_migration_script() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$logger = ur_get_logger();

		update_option( 'user_registration_content_restriction_enable', true );
		if ( ur_check_module_activation( 'payments' ) && ! get_option( 'global_paypal_setting_migration', false ) ) {
			$get_all_forms = ur_get_all_user_registration_form();
			foreach ( $get_all_forms as $key => $form ) {
				$is_paypal_setting_used = get_post_meta( $key, 'user_registration_enable_paypal_standard', false );
				if ( $is_paypal_setting_used ) {
					$logger->notice( 'Updating for form: ' . $form, array( 'source' => 'migration-logger' ) );
					add_post_meta( $key, 'user_registration_override_paypal_global_settings', true );
				}
			}

			add_option( 'global_paypal_setting_migration', true );
		}

		if ( UR_PRO_ACTIVE && UR_VERSION <= '5.0' && is_plugin_active( 'user-registration-membership/user-registration-membership.php' ) && ! get_option( 'membership_migration_finished', false ) ) {

			if ( ! class_exists( MembershipService::class ) ) {
				$logger->error(
					'MembershipService class not found. Skipping membership migration.',
					array(
						'source' => 'migration-logger',
					)
				);
				return;
			}

			$membership_service = new MembershipService();

			deactivate_plugins( 'user-registration-membership/user-registration-membership.php' );

			$logger->notice( '---------- Begin Membership Migration. ----------', array( 'source' => 'migration-logger' ) );
			$memberships = $membership_service->list_active_memberships();
			if ( count( $memberships ) === 0 ) {
				$logger->error(
					'! No memberships available....creating a default membership.',
					array(
						'source' => 'migration-logger',
					)
				);
				$membership_id = UR_Install::create_default_membership();
				// $memberships   = array( array( 'ID' => $membership_id ) );
			}

			// $logger->notice( 'Begin Default Membership Group creation.', array( 'source' => 'migration-logger' ) );

			// first create a default membership group and assign all the memberships to the group.
			// enable the commented codes to create a default group on migration
			// $group_id = UR_Install::create_default_membership_group( $memberships );
			// if ( $group_id ) {
			// $logger->notice( 'Created Default Membership Group.', array( 'source' => 'migration-logger' ) );

			// then use the group id to create a new registration form with membership field and the default group selected.
			$logger->notice( 'Begin Membership form creation.', array( 'source' => 'migration-logger' ) );

			$form_id = UR_Install::create_membership_form( 0 );
			if ( $form_id ) {
				$logger->notice( 'Membership form created successfully.', array( 'source' => 'migration-logger' ) );
				// find and replace old shortcode with newly created form.
				$result = $membership_service->find_and_replace_membership_form_with_registration_form( $form_id );
				// assign old members to new membership form
				$membership_service->assign_users_to_new_form( $form_id );
				if ( ! $result ) {
					$logger->notice(
						'Skipped old shortcode replace process.',
						array(
							'source' => 'migration-logger',
						)
					);
				}
				$logger->notice(
					'---------- Membership Migration Completed ----------',
					array(
						'source' => 'migration-logger',
					)
				);
				add_option( 'membership_migration_finished', true ); // to check if migration runs just once
				update_option( 'user_registration_membership_installed_flag', true ); // to check if membership has been installed
				$enabled_features   = get_option( 'user_registration_enabled_features', array() );
				$enabled_features[] = 'user-registration-membership';
				update_option( 'user_registration_enabled_features', $enabled_features );

			} else {
				// wp_delete_post( $group_id );
				$logger->error(
					'! Membership form creation failed....aborting migration.',
					array(
						'source' => 'migration-logger',
					)
				);
			}
			// } else {
			// $logger->error(
			// '! Group creation failed....aborting migration.',
			// array(
			// 'source' => 'migration-logger',
			// )
			// );
			// }
		}
	}

	/**
	 * Render Integration Section
	 *
	 * @param int $form_id Form Id.
	 *
	 * @return void
	 * @since 3.3.3
	 */
	public function render_integration_section( $form_id = 0 ) {

		echo '<div id="integration-settings"><h3 class="ur-integration">' . esc_html__( 'Integration', 'user-registration' ) . '</h3>';
		echo '</div>';
	}

	/**
	 * Render Integration Lists Section
	 *
	 * @param int $form_id Form Id.
	 *
	 * @return void
	 * @since 3.3.3
	 */
	public function render_integration_List_section( $form_id = 0 ) {

		$integration_addons = ur_integration_addons();

		foreach ( $integration_addons as $key => $integration ) {
			if ( isset( $integration['display'] ) && ! in_array( 'form_settings', $integration['display'] ) ) {
				continue;
			}

			echo '<div id="' . esc_attr( $integration['id'] ) . '-settings" class="integration-lists-settings" data-connected="' . esc_attr( $integration['connected'] ) . '">';
			$is_pro_active = is_plugin_active( 'user-registration-pro/user-registration.php' );
			$css_class     = '';

			if ( ! $is_pro_active ) {
				$css_class = 'ur-nav-premium';
			} else {

				$is_addon_active = is_plugin_active( 'user-registration-' . $integration['id'] . '/user-registration-' . $integration['id'] . '.php' );

				if ( ! $is_addon_active ) {
					$css_class = 'ur-nav-premium';
				}
			}

			$available_in = isset( $integration['available_in'] ) ? sanitize_text_field( wp_unslash( $integration['available_in'] ) ) : '';
			echo '<div class="form-settings-sub-tab ' . esc_attr( $css_class ) . '" id="' . esc_attr( $integration['id'] ) . '-settings" data-title="' . esc_attr( $integration['plugin_name'] ) . '" data-integration-id="user-registration-' . esc_attr( $integration['id'] ) . '" data-video="' . esc_attr( $integration['video_id'] ) . '" data-available-in="' . esc_attr( $available_in ) . '"><h3 class="ur-integration-list">' . esc_html( $integration['title'] ) . '</h3>';
			do_action( 'user_registration_form_settings_integration', $integration['id'], $form_id );
			echo '</div>';
			echo '</div>';
		}
		echo '<div id="integration-selection-settings" style="display:none;">';
		echo '<img src="' . esc_url_raw( UR()->plugin_url() . '/assets/images/no-integration-selected.png' ) . '"/>';
		echo '<div class="integration-selection-settings-contents">';
		echo '<h3>' . esc_attr__( 'No Integration Selected', 'user-registration' ) . '</h3>';
		echo '<p>' . esc_attr__( 'Please select an integration from the list to configure its settings', 'user-registration' ) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Execute migration script if version is not similar.
	 *
	 * @since 4.2.0.1
	 */
	public function run_migration_script() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( UR_VERSION !== get_option( 'user_registration_version' ) ) {
			UR_Install::maybe_run_migrations();
			update_option( 'user_registration_version', UR_VERSION );
		}
	}

	/**
	 * Translation Migration for Payments, Content Restriction and Frontend Listing.
	 */
	public function translation_migration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		global $wpdb;
		$migration_flag = get_option( 'ur_translations_migration_done', false );
		// $migration_flag = false;

		if ( ! $migration_flag ) {

			$merge_addons = array( 'payments', 'content-restriction', 'frontend-listing' );
			foreach ( $merge_addons as $text_domain ) {
				$plugin_source_dir = ABSPATH . 'wp-content/plugins/user-registration-' . $text_domain . '/languages';
				$global_source_dir = ABSPATH . 'wp-content/languages/plugins/';
				$source_paths      = array( $plugin_source_dir, $global_source_dir );

				foreach ( $source_paths as $source_dir ) {
					$destination_dir = ABSPATH . 'wp-content/plugins/user-registration-pro/languages';
					// Merge .po files.
					ur_merge_translations( $source_dir, $destination_dir, 'po', $text_domain );

					// Merge .mo files.
					ur_merge_translations( $source_dir, $destination_dir, 'mo', $text_domain );
				}

				// Check if WPML is active.
				if ( class_exists( 'SitePress', false ) ) {

					$new_domain = 'user-registration';
					// Update text domain in wp_icl_strings table.
					$wpdb->query(
						$wpdb->prepare( "UPDATE {$wpdb->prefix}icl_strings SET context = %s WHERE context = %s", $new_domain, 'user-registration-' . $text_domain )
					);
				}
			}

			update_option( 'ur_translations_migration_done', true );
		}
	}

	/**
	 * Add Tag for My Account to know which page is current my account page.
	 *
	 * @param mixed  $post_states Tags.
	 * @param object $post Post.
	 */
	public function ur_add_post_state( $post_states, $post ) {

		$urm_installable_pages = array(
			'user_registration_login_page_id'              => __( 'Login', 'user-registration' ),
			'user_registration_lost_password_page_id'      => __( 'Lost Password', 'user-registration' ),
			'user_registration_reset_password_page_id'     => __( 'Reset Password', 'user-registration' ),
			'user_registration_registration_page_id'       => __( 'Registration', 'user-registration' ),
			'user_registration_member_registration_page_id' => __( 'Membership Registration', 'user-registration' ),
			'user_registration_thank_you_page_id'          => __( 'Membership Thank You', 'user-registration' ),
			'user_registration_myaccount_page_id'          => __( 'My Account', 'user-registration' ),
			'user_registration_membership_pricing_page_id' => __( 'Membership Pricing', 'user-registration' ),
		);

		foreach ( $urm_installable_pages as $option_name => $title ) {
			$page_id = (int) get_option( $option_name );
			if ( $post->ID === $page_id ) {
				$post_states[] = sprintf(
					/* translators: 1: Page Title */
					__( 'URM %s Page', 'user-registration' ),
					$title
				);
				break;
			}
		}

		return $post_states;
	}

	/**
	 * Includes any classes we need within admin.
	 */
	public function includes() {
		include_once __DIR__ . '/functions-ur-admin.php';

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_user_registration' ) && ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		include_once __DIR__ . '/notifications/class-ur-admin-notices.php';
		include_once __DIR__ . '/class-ur-admin-menus.php';
		include_once __DIR__ . '/class-ur-admin-export-users.php';
		include_once __DIR__ . '/class-ur-admin-import-export-forms.php';
		include_once __DIR__ . '/class-ur-admin-form-modal.php';
		include_once __DIR__ . '/class-ur-admin-user-list-manager.php';
		include_once UR_ABSPATH . 'includes' . UR_DS . 'admin' . UR_DS . 'class-ur-admin-assets.php';
		include_once __DIR__ . '/class-ur-admin-form-templates.php';
		include_once __DIR__ . '/class-ur-sdk-deactivation-feedback.php';

		// Setup/welcome.
		if ( ! empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			switch ( $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
				case 'user-registration-welcome':
					include_once __DIR__ . '/class-ur-admin-welcome.php';
					break;
				case 'user-registration-email-checkup':
				case 'user-registration-settings':
					// Also on the settings page, so the old Emails → Health Checkup
					// section can hand off to the full-screen run.
					include_once __DIR__ . '/class-ur-admin-email-checkup.php';
					break;
				case 'user-registration-dashboard':
					include_once __DIR__ . '/class-ur-admin-dashboard.php';
					break;
				case 'ur_form_preview':
					include_once __DIR__ . '/class-ur-admin-form-preview.php';
					break;
			}
		}
	}

	/**
	 * Include admin files conditionally.
	 */
	public function conditional_includes() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_user_registration' ) && ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		switch ( $screen->id ) {
			case 'users':
			case 'user':
			case 'profile':
			case 'user-edit':
				include 'class-ur-admin-profile.php';
				break;
		}
	}

	/**
	 * Prevent any user who cannot 'edit_posts' from accessing admin.
	 */
	public function prevent_admin_access() {
		if ( defined( 'DOING_AJAX' ) ) {
			return;
		}
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$user_meta    = get_userdata( $user_id );
			$user_roles   = $user_meta->roles;
			$option_roles = get_option( 'user_registration_general_setting_disabled_user_roles', array( 'subscriber' ) );
			if ( ! is_array( $option_roles ) ) {
				$option_roles = array();
			}

			if ( ! in_array( 'administrator', $user_roles, true ) ) {
				$result = array_intersect( $user_roles, $option_roles );

				/**
				 * Filter to Prevent admin access
				 */
				if ( count( $result ) > 0 && apply_filters( 'user_registration_prevent_admin_access', true ) ) {
					wp_safe_redirect( esc_url_raw( ur_get_page_permalink( 'myaccount' ) ) );
					exit;
				}
			}
		}
	}

	/**
	 * Change the admin footer text on User Registration admin pages.
	 *
	 * @param string $footer_text User Registration Plugin footer text.
	 *
	 * @return string
	 * @since  1.1.2
	 */
	public function admin_footer_text( $footer_text ) {
		if ( ! current_user_can( 'manage_user_registration' ) || ! function_exists( 'ur_get_screen_ids' ) ) {
			return $footer_text;
		}
		$current_screen = get_current_screen();
		$ur_pages       = ur_get_screen_ids();

		// Set only UR pages.
		$ur_pages = array_diff( $ur_pages, array( 'profile', 'user-edit' ) );

		/**
		 * Filter to display admin footer text
		 *
		 * @param boolean Whether current screen is a UR page
		 */
		// Check to make sure we're on a User Registration admin page.
		if ( isset( $current_screen->id ) && apply_filters( 'user_registration_display_admin_footer_text', in_array( $current_screen->id, $ur_pages, true ) ) ) {
			// Change the footer text.
			if ( ! get_option( 'user_registration_admin_footer_text_rated' ) ) {
				// $footer_text = wp_kses_post(
				// sprintf(
				// * translators: 1: User Registration 2:: five stars */
				// __( 'If you like %1$s please leave us a %2$s rating. A huge thanks in advance!', 'user-registration' ),
				// sprintf( '<strong>%s</strong>', esc_html( 'User Registration' ) ),
				// '<a href="https://wordpress.org/support/plugin/user-registration/reviews?rate=5#new-post" rel="noreferrer noopener" target="_blank" class="ur-rating-link" data-rated="' . esc_attr__( 'Thank You!', 'user-registration' ) . '">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
				// )
				// );
				// ur_enqueue_js(
				// "
				// jQuery( 'a.ur-rating-link' ).on('click', function() {
				// jQuery.post( '" . UR()->ajax_url() . "', { action: 'user_registration_rated' } );
				// jQuery( this ).parent().text( jQuery( this ).data( 'rated' ) );
				// });
				// "
				// );
				$footer_text = '';
			} else {
				$footer_text = esc_html__( 'Thank you for using User Registration & Membership.', 'user-registration' );
			}
		}

		return $footer_text;
	}

	/**
	 * Mark the read time of the user list table.
	 */
	public function live_user_read() {
		$now = current_time( 'mysql' );
		update_option( 'user_registration_users_listing_viewed', sanitize_text_field( $now ) );
	}

	/**
	 * Check for new user by read time.
	 *
	 * @param array $response Heartbeat response data to pass back to front end.
	 * @param array $data Data received from the front end (unslashed).
	 */
	public function new_user_live_notice( $response, $data ) {

		if ( empty( $data['user_registration_new_user_notice'] ) ) {
			return $response;
		}

		if ( false === get_transient( 'urm_users_not_from_urm_forms' ) ) {
			// Get users not registered via URM forms.
			$urm_users_not_from_urm_forms = count(
				get_users(
					array(
						'fields'     => 'ID',
						'meta_query' => array(
							array(
								'key'     => 'ur_form_id',
								'compare' => 'NOT EXISTS',
							),
						),
					)
				)
			);
			set_transient( 'urm_users_not_from_urm_forms', $urm_users_not_from_urm_forms, apply_filters( 'urm_non_urm_user_transient_expiration', MINUTE_IN_SECONDS * 5 ) );
		}

		$read_time = get_option( 'user_registration_users_listing_viewed' );
		if ( ! $read_time ) {
			$now = current_time( 'mysql' );
			update_option( 'user_registration_users_listing_viewed', sanitize_text_field( $now ) );
			$read_time = $now;
		}

		$user_args  = array(
			'meta_key'    => 'ur_form_id',
			'count_total' => true,
			'date_query'  => array(
				array(
					'after'     => $read_time,
					'inclusive' => false,
				),
			),
		);
		$user_query = new WP_User_Query( $user_args );
		$user_count = $user_query->get_total();

		/* translators: 1: Newly registered user count 2: User */
		$response['user_registration_new_user_message'] = sprintf( esc_html__( '%1$d new %2$s registered.', 'user-registration' ), $user_count, _n( 'User', 'Users', $user_count, 'user-registration' ) );
		$response['user_registration_new_user_count']   = $user_count;

		return $response;
	}

	/**
	 * Add user-registration class to body in admin.
	 *
	 * @param string $classes Class to add to body.
	 */
	public function user_registration_add_body_classes( $classes ) {
		global $current_screen;

		// Check if the screen contains user-registration_page_ as prefix inorder to make sure the page is user registration plugin's page.
		if ( strpos( $current_screen->id, 'user-registration-membership_page_' ) !== false ) {
			$classes = 'user-registration';
		}

		return $classes;
	}

	/**
	 * Handle redirects to setup/welcome page after install and updates.
	 * For setup wizard, transient must be present, the user must have access rights, and we must ignore the network/bulk plugin updaters.
	 *
	 * @since 2.1.4
	 */
	public function admin_redirects() {
		if ( ! get_transient( '_ur_activation_redirect' ) ) {
			return;
		}

		delete_transient( '_ur_activation_redirect' );

		if ( ( ! empty( $_GET['page'] ) && in_array( $_GET['page'], array( 'user-registration-welcome' ) ) ) || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.PHP.StrictInArray.MissingTrueStrict
			return;
		}

		// If plugin is running for first time, redirect to onboard page.
		if ( ur_option_checked( 'user_registration_first_time_activation_flag' ) ) {
			wp_safe_redirect( admin_url( 'index.php?page=user-registration-welcome' ) );
			exit;
		}
	}

	/**
	 * Handle redirects after template refresh.
	 */
	public function template_actions() {
		if ( isset( $_GET['page'], $_REQUEST['action'] ) && 'add-new-registration' === $_GET['page'] ) {
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

			$templates = UR_Admin_Form_Templates::get_template_data();

			$templates = is_array( $templates ) ? $templates : array();

			if ( 'ur-template-refresh' === $action && ! empty( $templates ) ) {
				if ( empty( $_GET['ur-template-nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['ur-template-nonce'] ) ), 'refresh' ) ) {
					wp_die( esc_html__( 'Could not verify nonce', 'user-registration' ) );
				}

				foreach ( array( 'ur_pro_license_plan', 'ur_template_section_list' ) as $transient ) {
					delete_transient( $transient );
				}

				// Redirect to the builder page normally.
				wp_safe_redirect( admin_url( 'admin.php?page=add-new-registration' ) );
				exit;
			}
		}
	}
}

return new UR_Admin();
