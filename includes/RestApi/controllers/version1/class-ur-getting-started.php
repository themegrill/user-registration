<?php
/**
 * Getting Started REST API Controller.
 *
 * Handles the setup wizard endpoints for User Registration & Membership plugin.
 *
 * @since x.x.x
 *
 * @package UserRegistration/Classes
 */

use WPEverest\URMembership\Admin\Database\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class UR_Getting_Started
 *
 * @since x.x.x
 */
class UR_Getting_Started {

	/**
	 * Endpoint namespace.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	protected $namespace = 'user-registration/v1';

	/**
	 * Route base.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	protected $rest_base = 'getting-started';

	/**
	 * Wizard steps.
	 *
	 * @since x.x.x
	 *
	 * @var array
	 */
	protected static $steps = array(
		1 => 'welcome',
		2 => 'membership',
		3 => 'payment',
		4 => 'settings',
		5 => 'finish',
	);

	/**
	 * Option key used to store membership form id created in step 2.
	 *
	 * @since x.x.x
	 */
	const OPTION_MEMBERSHIP_FORM_ID = 'urm_membership_default_form_page_id';

	/**
	 * Option key used to store setup wizard data.
	 *
	 * @since x.x.x
	 */
	const OPTION_ONBOARDING_SNAPSHOT = 'urm_onboarding_snapshot';



	/**
	 * Register all REST API routes for the getting started wizard.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_wizard_state' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/welcome',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_welcome_data' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_welcome_data' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
					'args'                => array(
						'membership_type'      => array(
							'type'              => 'string',
							'required'          => true,
							'enum'              => array( 'paid_membership', 'free_membership', 'normal' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'allow_usage_tracking' => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'allow_email_updates'  => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'admin_email'          => array(
							'type'              => 'string',
							'format'            => 'email',
							'required'          => false,
							'sanitize_callback' => 'sanitize_email',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/memberships',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_memberships_data' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_memberships' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/payments',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_payment_settings' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_payment_settings' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_settings_data' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_settings_data' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/finish',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_finish_data' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'complete_wizard' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/skip',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'skip_step' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/navigate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'navigate_to_step' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
			)
		);
	}

	/**
	 * Get the current wizard state.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function get_wizard_state( $request ) {
		delete_option( 'user_registration_onboarding_skipped_step' );
		delete_option( 'urm_onboarding_current_step' );

		$enabled_features  = get_option( 'user_registration_enabled_features', array() );
		$required_features = array(
			'user-registration-membership',
		);

		foreach ( $required_features as $feature ) {
			if ( ! in_array( $feature, $enabled_features, true ) ) {
				$enabled_features[] = $feature;
			}
		}

		update_option( 'user_registration_enabled_features', $enabled_features );

		if ( class_exists( 'WPEverest\URMembership\Admin\Database\Database' ) ) {
			$membership_db = new Database();
			$membership_db::create_tables();
		}

		self::install_initial_pages( 'normal' );
		self::ensure_default_form( 'normal' );

		$current_step    = self::get_current_step();
		$membership_type = get_option( 'urm_onboarding_membership_type', '' );
		$is_completed    = ! get_option( 'user_registration_first_time_activation_flag', true );
		$is_skipped      = get_option( 'user_registration_onboarding_skipped', false );

		$wizard_state = array(
			'is_completed'    => $is_completed,
			'is_skipped'      => $is_skipped,
			'current_step'    => $current_step,
			'membership_type' => $membership_type,
			'steps'           => self::get_steps_with_status( $current_step, $membership_type ),
			'urls'            => array(
				'dashboard'         => admin_url( 'admin.php?page=user-registration' ),
				'registration_page' => self::get_registration_page_url(),
				'settings'          => admin_url( 'admin.php?page=user-registration-settings' ),
				'memberships'       => admin_url( 'admin.php?page=user-registration-membership' ),
			),
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $wizard_state,
			),
			200
		);
	}

	/**
	 * Get wizard steps enriched with status flags.
	 *
	 * @since x.x.x
	 *
	 * @param int    $current_step    Current step number.
	 * @param string $membership_type Selected membership type.
	 * @return array
	 */
	protected static function get_steps_with_status( $current_step, $membership_type ) {
		$steps_config = array(
			1 => array(
				'id'    => 'welcome',
				'label' => __( 'Welcome', 'user-registration' ),
			),
			2 => array(
				'id'    => 'membership',
				'label' => __( 'Membership', 'user-registration' ),
			),
			3 => array(
				'id'    => 'payment',
				'label' => __( 'Payment', 'user-registration' ),
			),
			4 => array(
				'id'    => 'settings',
				'label' => __( 'Settings', 'user-registration' ),
			),
			5 => array(
				'id'    => 'finish',
				'label' => __( 'Finish', 'user-registration' ),
			),
		);

		$steps = array();

		foreach ( $steps_config as $step_number => $step ) {
			$is_accessible = self::is_step_accessible( $step_number, $membership_type );

			$steps[] = array(
				'step'          => $step_number,
				'id'            => $step['id'],
				'label'         => $step['label'],
				'is_complete'   => $step_number < $current_step,
				'is_current'    => $step_number === $current_step,
				'is_accessible' => $is_accessible,
			);
		}

		return $steps;
	}

	/**
	 * Determine whether a given step is accessible with the selected membership type.
	 *
	 * @since x.x.x
	 *
	 * @param int    $step_number     Step number.
	 * @param string $membership_type Membership type.
	 * @return bool
	 */
	protected static function is_step_accessible( $step_number, $membership_type ) {
		if ( 2 === $step_number && 'normal' === $membership_type ) {
			return false;
		}

		if ( 3 === $step_number && 'paid_membership' !== $membership_type ) {
			return false;
		}

		if ( 4 === $step_number && 'normal' !== $membership_type ) {
			return false;
		}

		return true;
	}

	/**
	 * Get settings screen data for Advanced Registration flow.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function get_settings_data( $request ) {

		$login_options_raw = ur_login_option();
		$login_options     = array();
		foreach ( $login_options_raw as $value => $label ) {
			$login_options[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		$roles           = array();
		$available_roles = array();

		if ( function_exists( 'ur_get_default_admin_roles' ) ) {
			$available_roles = ur_get_default_admin_roles();
		} else {
			$wp_roles        = wp_roles();
			$available_roles = $wp_roles->get_names();
		}

		if ( is_array( $available_roles ) ) {
			foreach ( $available_roles as $role_key => $role_name ) {
				$roles[] = array(
					'value' => $role_key,
					'label' => $role_name,
				);
			}
		}

		$selected_login_option = get_option( 'user_registration_general_setting_login_options', 'default' );
		$selected_role         = get_option( 'user_registration_form_setting_default_user_role', 'subscriber' );

		$data = array(
			'login_options'         => $login_options,
			'roles'                 => $roles,
			'selected_login_option' => $selected_login_option,
			'selected_role'         => $selected_role,
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * Save settings data and move to next step.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function save_settings_data( $request ) {
		$login_option = isset( $request['login_option'] ) ? sanitize_text_field( $request['login_option'] ) : 'default';
		$default_role = isset( $request['default_role'] ) ? sanitize_text_field( $request['default_role'] ) : 'subscriber';

		$valid_login_options = array( 'default', 'auto_login', 'admin_approval', 'email_confirmation' );
		if ( function_exists( 'ur_login_option' ) ) {
			$valid_login_options = array_keys( ur_login_option() );
		}

		if ( in_array( $login_option, $valid_login_options, true ) ) {
			update_option( 'user_registration_general_setting_login_options', $login_option );
		}

		$available_roles = array();
		if ( function_exists( 'ur_get_default_admin_roles' ) ) {
			$available_roles = ur_get_default_admin_roles();
		} else {
			$wp_roles        = wp_roles();
			$available_roles = $wp_roles->get_names();
		}

		if ( is_array( $available_roles ) && array_key_exists( $default_role, $available_roles ) ) {
			update_option( 'user_registration_form_setting_default_user_role', $default_role );

			$default_form_id = get_option( 'user_registration_default_form_page_id', 0 );
			if ( $default_form_id ) {
				$form_settings = get_post_meta( $default_form_id, 'user_registration_form_setting', true );
				if ( ! is_array( $form_settings ) ) {
					$form_settings = array();
				}
				$form_settings['user_registration_form_setting_default_user_role'] = $default_role;
				$form_settings['user_registration_form_setting_login_options']     = $login_option;
				update_post_meta( $default_form_id, 'user_registration_form_setting', $form_settings );
			}
		}

		$membership_type = get_option( 'urm_onboarding_membership_type', 'normal' );

		$next_step = self::calculate_next_step( 4, $membership_type );
		self::update_current_step( $next_step );

		return new \WP_REST_Response(
			array(
				'success'   => true,
				'message'   => __( 'Settings saved successfully.', 'user-registration' ),
				'next_step' => $next_step,
			),
			200
		);
	}

	/**
	 * Get welcome screen data.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function get_welcome_data( $request ) {

		$data = array(
			'membership_type'      => get_option( 'urm_onboarding_membership_type', '' ),
			'allow_usage_tracking' => get_option( 'user_registration_allow_usage_tracking', true ),
			'admin_email'          => get_option( 'user_registration_updates_admin_email', get_option( 'admin_email' ) ),
			'membership_options'   => array(
				array(
					'value'       => 'paid_membership',
					'label'       => __( 'Paid Membership', 'user-registration' ),
					'description' => __( 'Charge users to access premium content (you can offer free plans too).', 'user-registration' ),
				),
				array(
					'value'       => 'free_membership',
					'label'       => __( 'Free Membership', 'user-registration' ),
					'description' => __( 'Let users register for free and access members-only content.', 'user-registration' ),
				),
				array(
					'value'       => 'normal',
					'label'       => __( 'Advanced Registration', 'user-registration' ),
					'description' => __( "Complete registration system to replace WordPress's basic signup. Custom signup fields, login & account pages, and user approval.", 'user-registration' ),
				),
			),
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * Save welcome step settings and create initial pages.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function save_welcome_data( $request ) {
		$membership_type      = isset( $request['membership_type'] ) ? sanitize_text_field( $request['membership_type'] ) : '';
		$allow_usage_tracking = isset( $request['allow_usage_tracking'] ) ? $request['allow_usage_tracking'] : true;
		$admin_email          = isset( $request['admin_email'] ) ? sanitize_email( $request['admin_email'] ) : get_option( 'admin_email' );

		if ( empty( $membership_type ) || ! in_array( $membership_type, array( 'paid_membership', 'free_membership', 'normal' ), true ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Please select a valid membership type.', 'user-registration' ),
				),
				400
			);
		}

		update_option( 'urm_onboarding_membership_type', $membership_type );

		$tracking_value = ur_string_to_bool( $allow_usage_tracking ) ? true : false;
		update_option( 'user_registration_allow_usage_tracking', $tracking_value );

		if ( ! empty( $admin_email ) ) {
			update_option( 'user_registration_updates_admin_email', $admin_email );
		}

		$page_details = array();

		if ( in_array( $membership_type, array( 'paid_membership', 'free_membership' ), true ) ) {
			self::ensure_membership_field_in_default_form();
			$page_details = self::create_membership_specific_pages();
		}

		$next_step = self::calculate_next_step( 1, $membership_type );
		self::update_current_step( $next_step );

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'message'      => __( 'Welcome settings saved successfully.', 'user-registration' ),
				'page_details' => $page_details,
				'next_step'    => $next_step,
			),
			200
		);
	}

	/**
	 * Create only membership-specific pages (pricing and thankyou).
	 * Does NOT create registration form or common pages as they already exist.
	 *
	 * @since x.x.x
	 *
	 * @return array
	 */
	protected static function create_membership_specific_pages() {
		include_once untrailingslashit( plugin_dir_path( UR_PLUGIN_FILE ) ) . '/includes/admin/functions-ur-admin.php';

		$enabled_features    = get_option( 'user_registration_enabled_features', array() );
		$membership_features = array(
			'user-registration-membership',
			'user-registration-payment-history',
		);

		foreach ( $membership_features as $feature ) {
			if ( ! in_array( $feature, $enabled_features, true ) ) {
				$enabled_features[] = $feature;
			}
		}

		update_option( 'user_registration_enabled_features', $enabled_features );
		update_option( 'user_registration_membership_installed_flag', true );
		update_option( 'urm_initial_registration_type', 'membership' );

		if ( class_exists( 'WPEverest\URMembership\Admin\Database\Database' ) ) {
			Database::create_tables();
		}

		$page_details = array();

		$existing_form_id = (int) get_option( 'user_registration_default_form_page_id', 0 );
		if ( ! $existing_form_id ) {
			$existing_form_id = (int) get_option( 'user_registration_registration_form', 0 );
		}

		if ( $existing_form_id ) {
			update_option( self::OPTION_MEMBERSHIP_FORM_ID, $existing_form_id );
			update_option( 'user_registration_member_registration_page_id', get_option( 'user_registration_registration_page_id', 0 ) );
		}

		$membership_pages = array(
			'membership_pricing'  => array(
				'name'    => _x( 'membership-pricing', 'Page slug', 'user-registration' ),
				'title'   => _x( 'Membership Pricing', 'Page title', 'user-registration' ),
				'content' => '<!-- wp:user-registration/membership-listing -->
<div>Membership Listing</div>
<!-- /wp:user-registration/membership-listing -->
',
			),
			'membership_thankyou' => array(
				'name'    => _x( 'thankyou', 'Page slug', 'user-registration' ),
				'title'   => _x( 'ThankYou', 'Page title', 'user-registration' ),
				'option'  => 'user_registration_thank_you_page_id',
				'content' => '[user_registration_membership_thank_you]',
			),
		);

		foreach ( $membership_pages as $key => $page ) {
			$post_id = ur_create_page(
				esc_sql( $page['name'] ),
				'user_registration_' . $key . '_page_id',
				wp_kses_post( $page['title'] ),
				wp_kses_post( $page['content'] )
			);

			if ( ! empty( $page['option'] ) ) {
				update_option( $page['option'], $post_id );
				if ( 'user_registration_thank_you_page_id' === $page['option'] && $existing_form_id ) {
					update_post_meta( $existing_form_id, 'user_registration_form_setting_redirect_after_registration', 'internal-page' );
					update_post_meta( $existing_form_id, 'user_registration_form_setting_redirect_page', $post_id );
				}
			}

			$page_details[ get_post_field( 'post_name', $post_id ) ] = array(
				'page_url'      => get_permalink( $post_id ),
				'page_url_text' => esc_html__( 'View Page', 'user-registration' ),
				'title'         => get_the_title( $post_id ) . esc_html__( ' Page', 'user-registration' ),
				'page_slug'     => '/' . get_post_field( 'post_name', $post_id ),
			);
		}

		return $page_details;
	}

	/**
	 * Create initial/common pages and default registration form.
	 *
	 * @since x.x.x
	 *
	 * @param string $membership_type The membership type selected.
	 * @return array
	 */
	protected static function install_initial_pages( $membership_type = 'normal' ) {
		update_option( 'users_can_register', true );
		update_option( 'user_registration_login_options_prevent_core_login', false );

		include_once untrailingslashit( plugin_dir_path( UR_PLUGIN_FILE ) ) . '/includes/admin/functions-ur-admin.php';

		$page_details = array();

		$default_message = '<h3>' . __( 'Membership Required', 'user-registration' ) . '</h3>
			<p>' . __( 'This content is available to members only.', 'user-registration' ) . '</p>
			<p>' . __( 'Sign up to unlock access or log in if you already have an account.', 'user-registration' ) . '</p>
			<p>{{sign_up}} {{log_in}}</p>';
		if ( class_exists( 'URCR_Admin_Assets' ) ) {
			$default_message = URCR_Admin_Assets::get_default_message();
		}
		update_option( 'user_registration_content_restriction_message', $default_message );

		$normal_form_id = self::ensure_default_form( 'normal' );

		$page_details['default_form_id'] = array(
			'title'         => esc_html__( 'Registration Form', 'user-registration' ),
			'page_url'      => admin_url( 'admin.php?page=add-new-registration&edit-registration=' . $normal_form_id ),
			'page_url_text' => esc_html__( 'View Form', 'user-registration' ),
			'page_slug'     => sprintf(
				/* translators: %s: Form ID. */
				esc_html__( 'Form Id: %s', 'user-registration' ),
				$normal_form_id
			),
			'status'        => 'enabled',
			'status_label'  => esc_html__( 'Ready to use', 'user-registration' ),
		);

		$pages = array(
			'registration'  => array(
				'name'    => _x( 'registration', 'Page slug', 'user-registration' ),
				'title'   => _x( 'Registration', 'Page title', 'user-registration' ),
				'content' => '[' . apply_filters( 'user_registration_form_shortcode_tag', 'user_registration_form' ) . ' id="' . esc_attr( $normal_form_id ) . '"]',
			),
			'login'         => array(
				'name'    => _x( 'login', 'Page slug', 'user-registration' ),
				'title'   => _x( 'Login', 'Page title', 'user-registration' ),
				'content' => '[' . apply_filters( 'user_registration_login_shortcode_tag', 'user_registration_login' ) . ']',
			),
			'myaccount'     => array(
				'name'    => _x( 'my-account', 'Page slug', 'user-registration' ),
				'title'   => _x( 'My Account', 'Page title', 'user-registration' ),
				'content' => '[' . apply_filters( 'user_registration_my_account_shortcode_tag', 'user_registration_my_account' ) . ']',
			),
			'lost_password' => array(
				'name'    => _x( 'lost-password', 'Page slug', 'user-registration' ),
				'title'   => _x( 'Lost Password', 'Page title', 'user-registration' ),
				'content' => '[user_registration_lost_password]',
			),
		);

		foreach ( $pages as $key => $page ) {
			$post_id = ur_create_page(
				esc_sql( $page['name'] ),
				'user_registration_' . $key . '_page_id',
				wp_kses_post( $page['title'] ),
				wp_kses_post( $page['content'] )
			);

			if ( 'registration' === $key ) {
				update_option( 'user_registration_member_registration_page_id', $post_id );
			}

			if ( 'login' === $key ) {
				update_option( 'user_registration_login_options_login_redirect_url', $post_id );
			}

			$page_details[ get_post_field( 'post_name', $post_id ) ] = array(
				'page_url'      => get_permalink( $post_id ),
				'page_url_text' => esc_html__( 'View Page', 'user-registration' ),
				'title'         => get_the_title( $post_id ) . esc_html__( ' Page', 'user-registration' ),
				'page_slug'     => '/' . get_post_field( 'post_name', $post_id ),
			);
		}

		update_option( 'user_registration_membership_installed_flag', false );

		return $page_details;
	}

	/**
	 * Ensure a default registration form exists.
	 *
	 * @since x.x.x
	 *
	 * @param string $mode Mode: 'normal' or 'membership'.
	 * @return int Form post ID.
	 */
	protected static function ensure_default_form( $mode = 'normal' ) {
		$is_membership = ( 'membership' === $mode );

		$existing = 0;

		if ( $is_membership ) {
			$existing = (int) get_option( self::OPTION_MEMBERSHIP_FORM_ID, 0 );
		} else {
			$existing = (int) get_option( 'user_registration_default_form_page_id', 0 );
			if ( ! $existing ) {
				$existing = (int) get_option( 'user_registration_registration_form', 0 );
			}
		}

		if ( $existing > 0 ) {
			return $existing;
		}

		$membership_field_name = 'membership_field_' . ( function_exists( 'ur_get_random_number' ) ? ur_get_random_number() : wp_rand( 1000, 999999 ) );

		$post_content = $is_membership
		? '[[[' .
		'{"field_key":"user_login","general_setting":{"label":"Username","description":"","field_name":"user_login","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":"","username_length":"","username_character":"1"},"icon":"ur-icon ur-icon-user"},' .
		'{"field_key":"user_email","general_setting":{"label":"User Email","description":"","field_name":"user_email","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-email"},' .
		'{"field_key":"user_pass","general_setting":{"label":"User Password","description":"","field_name":"user_pass","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password"},' .
		'{"field_key":"user_confirm_password","general_setting":{"label":"Confirm Password","description":"","field_name":"user_confirm_password","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password-confirm"}' .
		'],[' .
		'{"field_key":"membership","general_setting":{"label":"Membership Field","description":"","field_name":"' . $membership_field_name . '","required":"false","hide_label":"false","membership_listing_option":"all","membership_group":"0"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-membership-field"}' .
		']]]'
		: '[[[' .
		'{"field_key":"user_login","general_setting":{"label":"Username","description":"","field_name":"user_login","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":"","username_length":"","username_character":"1"},"icon":"ur-icon ur-icon-user"},' .
		'{"field_key":"user_email","general_setting":{"label":"User Email","description":"","field_name":"user_email","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-email"},' .
		'{"field_key":"user_pass","general_setting":{"label":"User Password","description":"","field_name":"user_pass","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password"},' .
		'{"field_key":"user_confirm_password","general_setting":{"label":"Confirm Password","description":"","field_name":"user_confirm_password","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password-confirm"}' .
		']]]';

		$title = esc_html__( 'Registration Form', 'user-registration' );

		$new_id = wp_insert_post(
			array(
				'post_type'      => 'user_registration',
				'post_title'     => $title,
				'post_content'   => $post_content,
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		if ( $is_membership ) {
			update_option( self::OPTION_MEMBERSHIP_FORM_ID, (int) $new_id );
			update_option( 'ur_membership_default_membership_field_name', $membership_field_name );
		} else {
			update_option( 'user_registration_default_form_page_id', (int) $new_id );
			update_option( 'user_registration_registration_form', (int) $new_id );
		}

		return (int) $new_id;
	}



	/**
	 * Get memberships data for step 2 including saved memberships and available content.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function get_memberships_data( $request ) {
		$membership_type      = get_option( 'urm_onboarding_membership_type', 'free_membership' );
		$saved_membership_ids = get_option( 'urm_onboarding_membership_ids', array() );
		$memberships          = self::fetch_memberships_for_wizard( $saved_membership_ids );
		$content              = array(
			'posts' => self::get_available_posts(),
			'pages' => self::get_available_pages(),
		);

		$default_type = 'paid_membership' === $membership_type ? 'one-time' : 'free';

		$currencies_raw = array();
		if ( function_exists( 'ur_payment_integration_get_currencies' ) ) {
			$currencies_raw = ur_payment_integration_get_currencies();
		}

		$currencies        = array();
		$currency_symbol   = '$';
		$selected_currency = get_option( 'user_registration_payment_currency', 'USD' );

		foreach ( $currencies_raw as $code => $currency_data ) {
			$symbol       = html_entity_decode( $currency_data['symbol'], ENT_QUOTES, 'UTF-8' );
			$currencies[] = array(
				'code'   => $code,
				'name'   => $currency_data['name'],
				'symbol' => $symbol,
			);

			if ( $code === $selected_currency ) {
				$currency_symbol = $symbol;
			}
		}

		return new \WP_REST_Response(
			array(
				'success'           => true,
				'memberships'       => $memberships,
				'content'           => $content,
				'membership_type'   => $membership_type,
				'default_plan_type' => $default_type,
				'can_create_paid'   => 'paid_membership' === $membership_type,
				'currency'          => $selected_currency,
				'currency_symbol'   => $currency_symbol,
				'currencies'        => $currencies,
			),
			200
		);
	}

	/**
	 * Save memberships from step 2.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function save_memberships( $request ) {
		$memberships = $request->get_param( 'memberships' );

		if ( ! is_array( $memberships ) ) {
			$memberships = array();
		}

		$membership_type = get_option( 'urm_onboarding_membership_type', 'free_membership' );

		if ( in_array( $membership_type, array( 'paid_membership', 'free_membership' ), true ) ) {
			self::ensure_membership_field_in_default_form();
			$pricing_page_id = get_option( 'user_registration_membership_pricing_page_id', 0 );
			if ( ! $pricing_page_id ) {
				self::create_membership_specific_pages();
			}
		}

		if ( empty( $memberships ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Please provide at least one membership.', 'user-registration' ),
				),
				400
			);
		}

		$allowed_types = array( 'free', 'one-time', 'subscription' );

		$results = array(
			'created' => array(),
			'updated' => array(),
			'errors'  => array(),
		);

		foreach ( $memberships as $index => $membership ) {
			$plan_type = isset( $membership['type'] ) ? sanitize_text_field( $membership['type'] ) : 'free';

			if ( ! in_array( $plan_type, $allowed_types, true ) ) {
				$results['errors'][] = array(
					'index'   => $index,
					'name'    => isset( $membership['name'] ) ? $membership['name'] : '',
					'message' => sprintf(
						/* translators: 1: Membership plan type 2: Allowed membership plan types. */
						__( 'Invalid membership type: %1$s. Only %2$s memberships are allowed based on your selection.', 'user-registration' ),
						$plan_type,
						implode( ' or ', $allowed_types )
					),
				);
				continue;
			}

			$is_update = ( ! empty( $membership['id'] ) && is_numeric( $membership['id'] ) );

			$result = self::save_single_membership( $membership );

			if ( is_wp_error( $result ) ) {
				$results['errors'][] = array(
					'index'   => $index,
					'name'    => isset( $membership['name'] ) ? $membership['name'] : '',
					'message' => $result->get_error_message(),
				);
				continue;
			}

			if ( $is_update ) {
				$results['updated'][] = $result;
			} else {
				$results['created'][] = $result;
			}
		}

		$all_ids = array_merge( $results['created'], $results['updated'] );
		update_option( 'urm_onboarding_membership_ids', $all_ids );

		$next_step = self::calculate_next_step( 2, $membership_type );
		self::update_current_step( $next_step );

		return new \WP_REST_Response(
			array(
				'success'        => true,
				'message'        => __( 'Memberships saved successfully.', 'user-registration' ),
				'created_count'  => count( $results['created'] ),
				'updated_count'  => count( $results['updated'] ),
				'membership_ids' => $all_ids,
				'errors'         => $results['errors'],
				'next_step'      => $next_step,
			),
			200
		);
	}


	/**
	 * Ensure the already-created default registration form contains Membership field.
	 *
	 * @since x.x.x
	 *
	 * @return int|false Updated form ID on success, false on failure.
	 */
	protected static function ensure_membership_field_in_default_form() {
		$form_id = (int) get_option( 'user_registration_default_form_page_id', 0 );

		if ( ! $form_id ) {
			$form_id = (int) get_option( 'user_registration_registration_form', 0 );
		}

		if ( ! $form_id ) {
			$form_id = (int) self::ensure_default_form( 'normal' );
		}

		if ( ! $form_id ) {
			return false;
		}

		$post = get_post( $form_id );
		if ( ! $post || 'user_registration' !== $post->post_type ) {
			return false;
		}

		$content = (string) $post->post_content;

		if ( false !== strpos( $content, '"field_key":"membership"' ) ) {
			update_option( self::OPTION_MEMBERSHIP_FORM_ID, (int) $form_id );
			return (int) $form_id;
		}

		$membership_field_name = get_option( 'ur_membership_default_membership_field_name', '' );
		if ( empty( $membership_field_name ) ) {
			$membership_field_name = 'membership_field_' . ( function_exists( 'ur_get_random_number' ) ? ur_get_random_number() : wp_rand( 1000, 999999 ) );
			update_option( 'ur_membership_default_membership_field_name', $membership_field_name );
		}

		$membership_field_json =
		'{"field_key":"membership","general_setting":{"label":"Membership Field","description":"","field_name":"' .
		$membership_field_name .
		'","required":"false","hide_label":"false","membership_listing_option":"all","membership_group":"0"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-membership-field"}';

		if ( substr( $content, -3 ) === ']]]' ) {
			$content = substr( $content, 0, -3 ) . '],[' . $membership_field_json . ']]]';
		} else {
			$content .= "\n" . $membership_field_json;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $form_id,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $updated ) || ! $updated ) {
			return false;
		}

		update_option( self::OPTION_MEMBERSHIP_FORM_ID, (int) $form_id );

		if ( ! get_option( 'user_registration_registration_form', 0 ) ) {
			update_option( 'user_registration_registration_form', (int) $form_id );
		}

		return (int) $form_id;
	}



	/**
	 * Save a single membership (insert/update) and sync access rules.
	 *
	 * @since x.x.x
	 *
	 * @param array $membership Membership data.
	 * @return int|\WP_Error
	 */
	protected static function save_single_membership( $membership ) {
		$membership_id = ( ! empty( $membership['id'] ) && is_numeric( $membership['id'] ) ) ? absint( $membership['id'] ) : 0;

		if ( empty( $membership['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'Membership name is required.', 'user-registration' )
			);
		}

		$type_input    = ! empty( $membership['type'] ) ? sanitize_text_field( $membership['type'] ) : 'free';
		$billing_cycle = ! empty( $membership['billing_cycle'] ) ? sanitize_text_field( $membership['billing_cycle'] ) : 'month';
		$billing_count = ! empty( $membership['billing_cycle_count'] ) ? absint( $membership['billing_cycle_count'] ) : 1;
		$amount        = isset( $membership['price'] ) ? floatval( $membership['price'] ) : 0;

		$meta = array(
			'payment_gateways' => array(),
			'amount'           => $amount,
			'role'             => 'subscriber',
		);

		if ( 'free' === $type_input ) {
			$meta['type'] = 'free';
		} elseif ( 'one-time' === $type_input ) {
			$meta['type']             = 'paid';
			$meta['payment_gateways'] = self::get_wizard_payment_gateways();
		} elseif ( 'subscription' === $type_input ) {
			$meta['type']             = 'subscription';
			$meta['payment_gateways'] = self::get_wizard_payment_gateways();
			$meta['subscription']     = array(
				'value'    => $billing_count,
				'duration' => $billing_cycle,
			);
		} else {
			$meta['type'] = 'free';
		}

		$data = array(
			'post_data'      => array(
				'ID'          => $membership_id,
				'name'        => $membership['name'],
				'status'      => true,
				'description' => ! empty( $membership['description'] ) ? wp_kses_post( $membership['description'] ) : '',
			),
			'post_meta_data' => $meta,
		);

		$service  = new \WPEverest\URMembership\Admin\Services\MembershipService();
		$prepared = $service->prepare_membership_post_data( $data );

		if ( isset( $prepared['status'] ) && ! $prepared['status'] ) {
			$message = ! empty( $prepared['message'] ) ? $prepared['message'] : __( 'Invalid membership data.', 'user-registration' );
			return new \WP_Error( 'invalid_membership', $message );
		}

		$prepared = apply_filters( 'ur_membership_after_create_membership_data_prepare', $prepared );

		if ( $membership_id ) {
			$result = wp_update_post( $prepared['post_data'], true );
		} else {
			$result        = wp_insert_post( $prepared['post_data'], true );
			$membership_id = $result;
		}

		if ( is_wp_error( $result ) || ! $result ) {
			return $result;
		}

		if ( ! empty( $prepared['post_meta_data'] ) ) {
			foreach ( $prepared['post_meta_data'] as $meta_data ) {
				if ( isset( $meta_data['meta_key'], $meta_data['meta_value'] ) ) {
					update_post_meta( $membership_id, $meta_data['meta_key'], $meta_data['meta_value'] );
				}
			}
		}

		$access_rules = array();

		if ( isset( $membership['access'] ) && is_array( $membership['access'] ) ) {
			$access_rules = $membership['access'];
		}

		self::save_membership_access_rules( $membership_id, $access_rules );
		self::sync_membership_access_rule( $membership_id, $access_rules );

		return $membership_id;
	}

	/**
	 * Build the payment_gateways meta for wizard-created plans.
	 *
	 * Returns every available gateway (via the runtime filter) with status 'on',
	 * matching the structure used by manually-created plans so both new and
	 * legacy installations display all configured gateways correctly.
	 *
	 * @return array
	 */
	protected static function get_wizard_payment_gateways() {
		$available = apply_filters(
			'user_registration_membership_payment_gateways',
			array(
				'paypal' => __( 'PayPal', 'user-registration' ),
				'stripe' => __( 'Stripe', 'user-registration' ),
				'bank'   => __( 'Bank', 'user-registration' ),
			)
		);

		$gateways = array();
		foreach ( $available as $key => $label ) {
			$gateways[ $key ] = array( 'status' => 'on' );
		}
		return $gateways;
	}

	/**
	 * Save membership access rules to post meta.
	 *
	 * @since x.x.x
	 *
	 * @param int   $membership_id Membership ID.
	 * @param array $access_rules  Access rules.
	 * @return bool
	 */
	protected static function save_membership_access_rules( $membership_id, $access_rules ) {
		if ( empty( $access_rules ) || ! is_array( $access_rules ) ) {
			delete_post_meta( $membership_id, '_ur_membership_access_rules' );
			return true;
		}

		$formatted_rules = array();

		foreach ( $access_rules as $rule ) {
			if ( empty( $rule['type'] ) ) {
				continue;
			}

			if ( 'wholesite' === $rule['type'] ) {
				$formatted_rules[] = array(
					'type'  => 'wholesite',
					'value' => array(),
				);
				continue;
			}

			if ( empty( $rule['value'] ) ) {
				continue;
			}

			$values = array_values(
				array_filter(
					array_map( 'absint', (array) $rule['value'] )
				)
			);

			if ( empty( $values ) ) {
				continue;
			}

			$formatted_rules[] = array(
				'type'  => sanitize_text_field( $rule['type'] ),
				'value' => $values,
			);
		}

		update_post_meta( $membership_id, '_ur_membership_access_rules', $formatted_rules );

		return true;
	}

	/**
	 * Sync membership access rules with Content Restriction engine.
	 *
	 * @since x.x.x
	 *
	 * @param int   $membership_id Membership ID.
	 * @param array $access_rules  Access rules from wizard.
	 * @return void
	 */
	protected static function sync_membership_access_rule( $membership_id, $access_rules ) {
		if ( empty( $membership_id ) ) {
			return;
		}

		if ( ! function_exists( 'urcr_create_or_update_membership_rule' ) ) {
			return;
		}

		if ( ! is_array( $access_rules ) ) {
			$access_rules = array();
		}

		$enabled_features  = get_option( 'user_registration_enabled_features', array() );
		$required_features = array(
			'user-registration-membership',
		);

		foreach ( $required_features as $feature ) {
			if ( ! in_array( $feature, $enabled_features, true ) ) {
				$enabled_features[] = $feature;
			}
		}

		update_option( 'user_registration_enabled_features', $enabled_features );

		$normalized = array(
			'pages'     => array(),
			'posts'     => array(),
			'wholesite' => false,
		);

		foreach ( $access_rules as $rule ) {
			if ( empty( $rule['type'] ) ) {
				continue;
			}

			$type = sanitize_text_field( $rule['type'] );

			if ( 'wholesite' === $type ) {
				$normalized['wholesite'] = true;
				continue;
			}

			if ( ! isset( $normalized[ $type ] ) ) {
				continue;
			}

			if ( ! isset( $rule['value'] ) ) {
				continue;
			}

			$normalized[ $type ] = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', (array) $rule['value'] )
					)
				)
			);
		}

		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );

		$mkid = static function ( $suffix ) use ( $uuid ) {
			return 'x' . str_replace( '-', '', $uuid ) . '_' . $suffix;
		};

		$access_rule_data = array(
			'enabled'         => true,
			'access_control'  => 'access',
			'logic_map'       => array(
				'type'       => 'group',
				'id'         => $mkid( 'logic' ),
				'conditions' => array(),
				'logic_gate' => 'AND',
			),
			'target_contents' => array(),
			'actions'         => array(
				array(
					'id'             => $mkid( 'action' ),
					'type'           => 'message',
					'access_control' => 'access',
					'label'          => __( 'Show Message', 'user-registration' ),
					'message'        => '',
					'redirect_url'   => '',
					'local_page'     => '',
					'ur_form'        => '',
					'shortcode'      => array(
						'tag'  => '',
						'args' => '',
					),
				),
			),
		);

		if ( $normalized['wholesite'] ) {
			$access_rule_data['target_contents'][] = array(
				'id'    => $mkid( 'target_wholesite' ),
				'type'  => 'whole_site',
				'value' => array(),
			);
		}

		foreach ( array( 'pages', 'posts' ) as $type ) {
			$values = $normalized[ $type ];

			if ( empty( $values ) ) {
				continue;
			}

			$cr_type = '';

			if ( 'pages' === $type ) {
				$cr_type = 'wp_pages';
			} elseif ( 'posts' === $type ) {
				$cr_type = 'wp_posts';
			}

			if ( ! $cr_type ) {
				continue;
			}

			$access_rule_data['target_contents'][] = array(
				'id'    => $mkid( 'target_' . $type ),
				'type'  => $cr_type,
				'value' => array_map( 'strval', $values ),
			);
		}

		$rule_data = array(
			'title'            => get_the_title( $membership_id ) . ' Rule',
			'access_rule_data' => $access_rule_data,
			'rule_type'        => 'membership',
			'membership_id'    => absint( $membership_id ),
		);

		urcr_create_or_update_membership_rule( $membership_id, $rule_data );
	}

	/**
	 * Fetch memberships created during wizard for display.
	 *
	 * @since x.x.x
	 *
	 * @param array $membership_ids Array of membership IDs to fetch.
	 * @return array
	 */
	protected static function fetch_memberships_for_wizard( $membership_ids = array() ) {
		if ( empty( $membership_ids ) ) {
			return array();
		}

		$memberships = array();

		foreach ( $membership_ids as $membership_id ) {
			$post = get_post( $membership_id );

			if ( ! $post || 'ur_membership' !== $post->post_type ) {
				continue;
			}

			$meta_type         = get_post_meta( $membership_id, 'urm_type', true );
			$meta_amount       = get_post_meta( $membership_id, 'urm_amount', true );
			$meta_subscription = get_post_meta( $membership_id, 'urm_subscription', true );
			$access_rules      = get_post_meta( $membership_id, '_ur_membership_access_rules', true );

			$plan_type = 'free';
			if ( 'paid' === $meta_type ) {
				$plan_type = 'one-time';
			} elseif ( 'subscription' === $meta_type ) {
				$plan_type = 'subscription';
			}

			$billing_cycle       = 'month';
			$billing_cycle_count = '';
			if ( 'subscription' === $meta_type && ! empty( $meta_subscription ) ) {
				$billing_cycle       = isset( $meta_subscription['duration'] ) ? $meta_subscription['duration'] : 'month';
				$billing_cycle_count = isset( $meta_subscription['value'] ) ? strval( $meta_subscription['value'] ) : '1';
			}

			$content_access = array();
			if ( ! empty( $access_rules ) && is_array( $access_rules ) ) {
				foreach ( $access_rules as $rule ) {
					if ( empty( $rule['type'] ) ) {
						continue;
					}

					if ( 'wholesite' === $rule['type'] ) {
						$content_access[] = array(
							'id'    => wp_generate_uuid4(),
							'type'  => 'wholesite',
							'value' => array(),
						);
						continue;
					}

					if ( ! empty( $rule['value'] ) ) {
						$content_access[] = array(
							'id'    => wp_generate_uuid4(),
							'type'  => $rule['type'],
							'value' => array_map( 'intval', (array) $rule['value'] ),
						);
					}
				}
			}

			$memberships[] = array(
				'id'                => $membership_id,
				'name'              => $post->post_title,
				'type'              => $plan_type,
				'price'             => ! empty( $meta_amount ) ? strval( $meta_amount ) : '',
				'billingCycle'      => $billing_cycle,
				'billingCycleCount' => $billing_cycle_count,
				'contentAccess'     => $content_access,
				'isNew'             => false,
			);
		}

		return $memberships;
	}

	/**
	 * Get available posts and pages for content restriction setup.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function get_available_content( $request ) {
		$content = array(
			'posts' => self::get_available_posts(),
			'pages' => self::get_available_pages(),
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'content' => $content,
			),
			200
		);
	}

	/**
	 * Get available posts for content restriction.
	 *
	 * @since x.x.x
	 *
	 * @return array
	 */
	protected static function get_available_posts() {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return array_map(
			function ( $post ) {
				return array(
					'value' => $post->ID,
					'label' => $post->post_title,
				);
			},
			$posts
		);
	}

	/**
	 * Get available pages for content restriction.
	 *
	 * @since x.x.x
	 *
	 * @return array
	 */
	protected static function get_available_pages() {
		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'post_title',
				'sort_order'  => 'ASC',
			)
		);

		if ( function_exists( 'urcr_get_excluded_page_ids' ) ) {
			$excluded_page_ids = urcr_get_excluded_page_ids();

			$pages = array_filter(
				$pages,
				function ( $page ) use ( $excluded_page_ids ) {
					return ! in_array( $page->ID, $excluded_page_ids, true );
				}
			);

			$pages = array_values( $pages );
		}

		return array_map(
			function ( $page ) {
				return array(
					'value' => $page->ID,
					'label' => $page->post_title,
				);
			},
			$pages
		);
	}

	/**
	 * Get payment settings for step 3.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function get_payment_settings( $request ) {
		$gateways = array(
			array(
				'id'           => 'offline_payment',
				'label'        => __( 'Offline Payment', 'user-registration' ),
				'description'  => __( 'Accept payments manually via bank transfer, check, or cash.', 'user-registration' ),
				'enabled'      => self::get_bool_option( 'urm_bank_connection_status' ),
				'configured'   => true,
				'settings_url' => '',
				'bank_details' => get_option( 'user_registration_global_bank_details', '' ),
			),
			array(
				'id'                              => 'paypal',
				'label'                           => __( 'PayPal', 'user-registration' ),
				'description'                     => __( 'Accept payments via PayPal.', 'user-registration' ),
				'enabled'                         => self::get_bool_option( 'urm_paypal_connection_status' ),
				'configured'                      => self::is_gateway_configured( 'paypal' ),
				'settings_url'                    => admin_url( 'admin.php?page=user-registration-settings&tab=ur_membership&section=payment_settings' ),
				'paypal_mode'                     => get_option( 'user_registration_global_paypal_mode', 'test' ),
				'paypal_test_email'               => get_option( 'user_registration_global_paypal_test_email_address', get_option( 'user_registration_global_paypal_email_address', '' ) ),
				'paypal_test_client_id'           => get_option( 'user_registration_global_paypal_test_client_id', get_option( 'user_registration_global_paypal_client_id', '' ) ),
				'paypal_test_client_secret'       => get_option( 'user_registration_global_paypal_test_client_secret', get_option( 'user_registration_global_paypal_client_secret', '' ) ),
				'paypal_production_email'         => get_option( 'user_registration_global_paypal_live_email_address', get_option( 'user_registration_global_paypal_live_admin_email', get_option( 'user_registration_global_paypal_email_address', '' ) ) ),
				'paypal_production_client_id'     => get_option( 'user_registration_global_paypal_live_client_id', get_option( 'user_registration_global_paypal_client_id', '' ) ),
				'paypal_production_client_secret' => get_option( 'user_registration_global_paypal_live_client_secret', get_option( 'user_registration_global_paypal_client_secret', '' ) ),
				'is_new_installation'             => ! ur_is_paypal_old_installation(),
			),
			array(
				'id'                          => 'stripe',
				'label'                       => __( 'Stripe', 'user-registration' ),
				'description'                 => __( 'Accept credit card payments via Stripe.', 'user-registration' ),
				'enabled'                     => self::get_bool_option( 'urm_stripe_connection_status' ),
				'configured'                  => self::is_gateway_configured( 'stripe' ),
				'settings_url'                => admin_url( 'admin.php?page=user-registration-settings&tab=ur_membership&section=payment_settings' ),
				'stripe_test_mode'            => self::get_bool_option( 'user_registration_stripe_test_mode' ),
				'stripe_test_publishable_key' => get_option( 'user_registration_stripe_test_publishable_key', '' ),
				'stripe_test_secret_key'      => get_option( 'user_registration_stripe_test_secret_key', '' ),
				'stripe_live_publishable_key' => get_option( 'user_registration_stripe_live_publishable_key', '' ),
				'stripe_live_secret_key'      => get_option( 'user_registration_stripe_live_secret_key', '' ),
			),
		);

		$currencies_raw = array();
		if ( function_exists( 'ur_payment_integration_get_currencies' ) ) {
			$currencies_raw = ur_payment_integration_get_currencies();
		}

		$currencies = array();
		foreach ( $currencies_raw as $code => $currency_data ) {
			$currencies[] = array(
				'code'   => $code,
				'name'   => $currency_data['name'],
				'symbol' => html_entity_decode( $currency_data['symbol'], ENT_QUOTES, 'UTF-8' ),
			);
		}

		$selected_currency = get_option( 'user_registration_payment_currency', 'USD' );

		return new \WP_REST_Response(
			array(
				'success'          => true,
				'payment_gateways' => $gateways,
				'currencies'       => $currencies,
				'currency'         => $selected_currency,
			),
			200
		);
	}

	/**
	 * Save payment settings and enabled gateways.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 * @throws \Exception When Stripe credential validation fails.
	 */
	public static function save_payment_settings( $request ) {
		$offline_payment = isset( $request['offline_payment'] ) ? (bool) $request['offline_payment'] : false;
		$paypal          = isset( $request['paypal'] ) ? (bool) $request['paypal'] : false;
		$stripe          = isset( $request['stripe'] ) ? (bool) $request['stripe'] : false;

		$currency                        = isset( $request['currency'] ) ? sanitize_text_field( $request['currency'] ) : 'USD';
		$bank_details                    = isset( $request['bank_details'] ) ? sanitize_textarea_field( $request['bank_details'] ) : '';
		$paypal_mode                     = isset( $request['paypal_mode'] ) ? sanitize_text_field( $request['paypal_mode'] ) : 'test';
		$paypal_test_email               = isset( $request['paypal_test_email'] ) ? sanitize_email( $request['paypal_test_email'] ) : '';
		$paypal_test_client_id           = isset( $request['paypal_test_client_id'] ) ? sanitize_text_field( $request['paypal_test_client_id'] ) : '';
		$paypal_test_client_secret       = isset( $request['paypal_test_client_secret'] ) ? sanitize_text_field( $request['paypal_test_client_secret'] ) : '';
		$paypal_production_email         = isset( $request['paypal_production_email'] ) ? sanitize_email( $request['paypal_production_email'] ) : '';
		$paypal_production_client_id     = isset( $request['paypal_production_client_id'] ) ? sanitize_text_field( $request['paypal_production_client_id'] ) : '';
		$paypal_production_client_secret = isset( $request['paypal_production_client_secret'] ) ? sanitize_text_field( $request['paypal_production_client_secret'] ) : '';
		$stripe_test_mode                = isset( $request['stripe_test_mode'] ) ? (bool) $request['stripe_test_mode'] : false;
		$stripe_test_publishable_key     = isset( $request['stripe_test_publishable_key'] ) ? sanitize_text_field( $request['stripe_test_publishable_key'] ) : '';
		$stripe_test_secret_key          = isset( $request['stripe_test_secret_key'] ) ? sanitize_text_field( $request['stripe_test_secret_key'] ) : '';
		$stripe_live_publishable_key     = isset( $request['stripe_live_publishable_key'] ) ? sanitize_text_field( $request['stripe_live_publishable_key'] ) : '';
		$stripe_live_secret_key          = isset( $request['stripe_live_secret_key'] ) ? sanitize_text_field( $request['stripe_live_secret_key'] ) : '';

		update_option( 'user_registration_payment_currency', $currency );

		$configuration_needed = array();

		$offline_configured = true;
		if ( $offline_payment ) {
			$sanitized_bank_details = trim( wp_strip_all_tags( $bank_details ) );
			$offline_configured     = ! empty( $sanitized_bank_details );

			if ( ! $offline_configured ) {
				$configuration_needed[] = array(
					'gateway'      => 'offline',
					'message'      => __( 'Offline payment requires bank details.', 'user-registration' ),
					'settings_url' => admin_url( 'admin.php?page=user-registration-settings&tab=ur_membership&section=payment_settings' ),
				);
			}
		}

		$paypal_configured = true;
		if ( $paypal ) {
			if ( 'test' === $paypal_mode ) {
				$has_rest_creds    = ! empty( $paypal_test_client_id ) && ! empty( $paypal_test_client_secret );
				$paypal_configured = $has_rest_creds || ! empty( $paypal_test_email );
			} else {
				$has_rest_creds    = ! empty( $paypal_production_client_id ) && ! empty( $paypal_production_client_secret );
				$paypal_configured = $has_rest_creds || ! empty( $paypal_production_email );
			}

			if ( ! $paypal_configured ) {
				$message = ur_is_paypal_old_installation()
					? __( 'PayPal requires either an email address or Client ID and Secret.', 'user-registration' )
					: __( 'PayPal requires Client ID and Secret.', 'user-registration' );

				$configuration_needed[] = array(
					'gateway'      => 'paypal',
					'message'      => $message,
					'settings_url' => admin_url( 'admin.php?page=user-registration-settings&tab=ur_membership&section=payment_settings' ),
				);
			}
		}

		$stripe_configured = true;
		if ( $stripe ) {
			if ( $stripe_test_mode ) {
				$stripe_configured = ! empty( $stripe_test_publishable_key ) && ! empty( $stripe_test_secret_key );
			} else {
				$stripe_configured = ! empty( $stripe_live_publishable_key ) && ! empty( $stripe_live_secret_key );
			}

			if ( ! $stripe_configured ) {
				$configuration_needed[] = array(
					'gateway'      => 'stripe',
					'message'      => $stripe_test_mode
						? __( 'Stripe requires test API keys configuration.', 'user-registration' )
						: __( 'Stripe requires live API keys configuration.', 'user-registration' ),
					'settings_url' => admin_url( 'admin.php?page=user-registration-settings&tab=ur_membership&section=payment_settings' ),
				);
			}
		}

		$offline_enabled = $offline_payment && $offline_configured;
		$paypal_enabled  = $paypal && $paypal_configured;
		$stripe_enabled  = $stripe && $stripe_configured;

		update_option( 'urm_bank_connection_status', $offline_enabled );
		update_option( 'urm_paypal_connection_status', $paypal_enabled );
		update_option( 'urm_stripe_connection_status', $stripe_enabled );
		update_option( 'user_registration_bank_enabled', $offline_enabled );
		update_option( 'user_registration_paypal_enabled', $paypal_enabled );
		update_option( 'user_registration_stripe_enabled', $stripe_enabled );

		// Stripe is a built-in module now, not a separate add-on to activate - validating its keys here
		// is the only activation step a user sees, so it must also flip the module flag that gates
		// Field Sync and the rest of Stripe's admin UI (Addons > E-Commerce controls the same flag).
		if ( $stripe_enabled ) {
			$enabled_features = get_option( 'user_registration_enabled_features', array() );
			$enabled_features = is_array( $enabled_features ) ? $enabled_features : array();

			if ( ! in_array( 'user-registration-stripe', $enabled_features, true ) ) {
				$enabled_features[] = 'user-registration-stripe';
				update_option( 'user_registration_enabled_features', $enabled_features );
			}
		}

		if ( $offline_enabled ) {
			update_option( 'user_registration_global_bank_details', $bank_details );
		}

		update_option( 'user_registration_global_paypal_mode', $paypal_mode );
		update_option( 'user_registration_global_paypal_test_email_address', $paypal_test_email );
		update_option( 'user_registration_global_paypal_test_client_id', $paypal_test_client_id );
		update_option( 'user_registration_global_paypal_test_client_secret', $paypal_test_client_secret );
		update_option( 'user_registration_global_paypal_live_email_address', $paypal_production_email );
		update_option( 'user_registration_global_paypal_live_client_id', $paypal_production_client_id );
		update_option( 'user_registration_global_paypal_live_client_secret', $paypal_production_client_secret );

		if ( $stripe_enabled ) {
			update_option( 'user_registration_stripe_test_mode', $stripe_test_mode );
			update_option( 'user_registration_stripe_test_publishable_key', $stripe_test_publishable_key );
			update_option( 'user_registration_stripe_test_secret_key', $stripe_test_secret_key );
			update_option( 'user_registration_stripe_live_publishable_key', $stripe_live_publishable_key );
			update_option( 'user_registration_stripe_live_secret_key', $stripe_live_secret_key );

			try {

				$stripe_service = new \WPEverest\URMembership\Admin\Services\Stripe\StripeService();

				$is_valid = $stripe_service->validate_credentials();

				if ( ! $is_valid ) {
					throw new \Exception( __( 'Invalid Stripe API credentials. Please verify your keys.', 'user-registration' ) );
				}

				$membership_ids = (array) get_option( 'urm_onboarding_membership_ids', array() );

				foreach ( $membership_ids as $membership_id ) {
					$membership_id = absint( $membership_id );
					if ( ! $membership_id ) {
						continue;
					}

					$meta_raw = get_post_meta( $membership_id, 'ur_membership', true );
					if ( empty( $meta_raw ) ) {
						continue;
					}

					$meta = json_decode( wp_unslash( $meta_raw ), true );
					if ( empty( $meta ) || empty( $meta['type'] ) || 'free' === $meta['type'] ) {
						continue;
					}

					$product_id = $meta['payment_gateways']['stripe']['product_id'] ?? '';
					$price_id   = $meta['payment_gateways']['stripe']['price_id'] ?? '';

					if ( ! empty( $product_id ) && ! empty( $price_id ) ) {
						continue;
					}

					$post = get_post( $membership_id );
					if ( ! $post ) {
						continue;
					}

					$post_data = array(
						'ID'           => $membership_id,
						'post_title'   => $post->post_title,
						'post_content' => $post->post_content,
					);

					user_registration_create_product_and_price_for_stripe( $post_data );
				}
			} catch ( \Exception $e ) {

				$configuration_needed[] = array(
					'gateway'      => 'stripe',
					'message'      => $e->getMessage(),
					'settings_url' => admin_url( 'admin.php?page=user-registration-settings&tab=ur_membership&section=payment_settings' ),
				);

				$stripe_enabled = false;
				update_option( 'urm_stripe_connection_status', false );
				update_option( 'user_registration_stripe_enabled', false );
				update_option( 'user_registration_stripe_test_publishable_key', '' );
				update_option( 'user_registration_stripe_test_secret_key', '' );
				update_option( 'user_registration_stripe_live_publishable_key', '' );
				update_option( 'user_registration_stripe_live_secret_key', '' );
			}
		}

		$enabled_gateways = array();

		if ( $offline_enabled ) {
			$enabled_gateways[] = 'offline';
		}

		if ( $paypal_enabled ) {
			$enabled_gateways[] = 'paypal';
		}

		if ( $stripe_enabled ) {
			$enabled_gateways[] = 'stripe';
		}

		update_option( 'urm_enabled_payment_gateways', $enabled_gateways );

		$next_step = 5;
		self::update_current_step( $next_step );

		return new \WP_REST_Response(
			array(
				'success'              => true,
				'message'              => __( 'Payment settings saved successfully.', 'user-registration' ),
				'enabled_gateways'     => $enabled_gateways,
				'configuration_needed' => $configuration_needed,
				'next_step'            => $next_step,
			),
			200
		);
	}

	/**
	 * Check if a payment gateway is configured.
	 *
	 * @since x.x.x
	 *
	 * @param string $gateway Gateway ID.
	 * @return bool
	 */
	protected static function is_gateway_configured( $gateway ) {
		switch ( $gateway ) {
			case 'paypal':
				$paypal_email = get_option( 'user_registration_global_paypal_live_admin_email', '' );
				return ! empty( $paypal_email );

			case 'stripe':
				$test_mode = self::get_bool_option( 'user_registration_stripe_test_mode' );

				if ( $test_mode ) {
					$publishable_key = get_option( 'user_registration_stripe_test_publishable_key', '' );
					$secret_key      = get_option( 'user_registration_stripe_test_secret_key', '' );
				} else {
					$publishable_key = get_option( 'user_registration_stripe_live_publishable_key', '' );
					$secret_key      = get_option( 'user_registration_stripe_live_secret_key', '' );
				}

				return ! empty( $publishable_key ) && ! empty( $secret_key );

			default:
				return true;
		}
	}

	/**
	 * Get summary data for finish step.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function get_finish_data( $request ) {
		$membership_type  = get_option( 'urm_onboarding_membership_type', '' );
		$membership_ids   = get_option( 'urm_onboarding_membership_ids', array() );
		$enabled_gateways = get_option( 'urm_enabled_payment_gateways', array() );

		$is_membership = in_array( $membership_type, array( 'paid_membership', 'free_membership' ), true );

		$pages = array();

		if ( $is_membership ) {
			$pages['membership_registration'] = array(
				'id'    => get_option( 'user_registration_member_registration_page_id', 0 ),
				'label' => __( 'Registration', 'user-registration' ),
				'url'   => self::get_page_url( 'user_registration_member_registration_page_id' ),
			);
			$pages['membership_pricing']      = array(
				'id'    => get_option( 'user_registration_membership_pricing_page_id', 0 ),
				'label' => __( 'Membership Pricing', 'user-registration' ),
				'url'   => self::get_page_url( 'user_registration_membership_pricing_page_id' ),
			);
		} else {
			$pages['registration'] = array(
				'id'    => get_option( 'user_registration_registration_page_id', 0 ),
				'label' => __( 'Registration', 'user-registration' ),
				'url'   => self::get_page_url( 'user_registration_registration_page_id' ),
			);
		}

		$pages['login'] = array(
			'id'    => get_option( 'user_registration_login_page_id', 0 ),
			'label' => __( 'Login', 'user-registration' ),
			'url'   => self::get_page_url( 'user_registration_login_page_id' ),
		);

		$pages['my_account'] = array(
			'id'    => get_option( 'user_registration_myaccount_page_id', 0 ),
			'label' => __( 'My Account', 'user-registration' ),
			'url'   => self::get_page_url( 'user_registration_myaccount_page_id' ),
		);

		$roles        = array();
		$default_role = get_option( 'user_registration_form_setting_default_user_role', 'subscriber' );

		if ( 'normal' === $membership_type ) {
			$available_roles = ur_get_default_admin_roles();

			if ( is_array( $available_roles ) ) {
				foreach ( $available_roles as $role_key => $role_name ) {
					$roles[] = array(
						'value' => $role_key,
						'label' => $role_name,
					);
				}
			}
		}

		$action_urls = array(
			'dashboard' => admin_url( 'admin.php?page=user-registration-dashboard' ),
			'settings'  => admin_url( 'admin.php?page=user-registration-settings' ),
			'forms'     => admin_url( 'admin.php?page=user-registration' ),
		);

		if ( $is_membership ) {
			$action_urls['primary_action']       = admin_url( 'admin.php?page=user-registration-membership' );
			$action_urls['primary_action_label'] = __( 'Create Membership', 'user-registration' );
			$action_urls['registration_page']    = self::get_page_url( 'user_registration_member_registration_page_id' );
			$action_urls['memberships']          = admin_url( 'admin.php?page=user-registration-membership' );
		} else {
			$action_urls['primary_action']       = self::get_page_url( 'user_registration_registration_page_id' );
			$action_urls['primary_action_label'] = __( 'Visit Registration', 'user-registration' );
			$action_urls['registration_page']    = self::get_page_url( 'user_registration_registration_page_id' );
		}

		$summary = array(
			'membership_type'     => $membership_type,
			'memberships_created' => count( $membership_ids ),
			'enabled_gateways'    => $enabled_gateways,
			'pages'               => $pages,
			'roles'               => $roles,
			'default_role'        => $default_role,
			'links'               => $action_urls,
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $summary,
			),
			200
		);
	}

	/**
	 * Complete the wizard and mark it as done.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function complete_wizard( $request ) {

		$default_user_role = isset( $request['default_user_role'] ) ? sanitize_text_field( $request['default_user_role'] ) : '';
		if ( ! empty( $default_user_role ) ) {
			$available_roles = ur_get_default_admin_roles();

			if ( is_array( $available_roles ) && array_key_exists( $default_user_role, $available_roles ) ) {
				update_option( 'user_registration_form_setting_default_user_role', $default_user_role );
				$default_form_id = get_option( 'user_registration_default_form_page_id', 0 );

				if ( $default_form_id ) {
					$form_settings = get_post_meta( $default_form_id, 'user_registration_form_setting', true );

					if ( ! is_array( $form_settings ) ) {
						$form_settings = array();
					}

					$form_settings['user_registration_form_setting_default_user_role'] = $default_user_role;
					update_post_meta( $default_form_id, 'user_registration_form_setting', $form_settings );
				}
			}
		}

		update_option( 'user_registration_first_time_activation_flag', false );
		update_option( 'user_registration_onboarding_skipped', false );
		delete_option( 'user_registration_onboarding_skipped_step' );
		update_option( 'urm_onboarding_completed_at', current_time( 'mysql' ) );
		self::update_current_step( 5 );

		do_action( 'user_registration_getting_started_completed' );

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'message'      => __( "Success! You're all set!", 'user-registration' ),
				'redirect_url' => admin_url( 'admin.php?page=user-registration' ),
				'links'        => array(
					'registration_page' => self::get_registration_page_url(),
					'dashboard'         => admin_url( 'admin.php?page=user-registration' ),
				),
			),
			200
		);
	}

	/**
	 * Skip current step and move to the next one.
	 * Resets step data to defaults when skipping.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function skip_step( $request ) {
		$current_step    = isset( $request['step'] ) ? absint( $request['step'] ) : self::get_current_step();
		$membership_type = get_option( 'urm_onboarding_membership_type', '' );

		$next_step = self::calculate_next_step( $current_step, $membership_type );
		self::update_current_step( $next_step );

		$skipped_steps   = get_option( 'user_registration_onboarding_skipped_steps', array() );
		$skipped_steps[] = $current_step;

		update_option( 'user_registration_onboarding_skipped_steps', array_unique( $skipped_steps ) );

		if ( 5 === $next_step ) {
			update_option( 'user_registration_onboarding_skipped', true );
			update_option( 'user_registration_onboarding_skipped_step', $current_step );
		}

		return new \WP_REST_Response(
			array(
				'success'   => true,
				'message'   => __( 'Step skipped.', 'user-registration' ),
				'next_step' => $next_step,
				'skipped'   => true,
			),
			200
		);
	}

	/**
	 * Navigate to a specific step (back navigation).
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public static function navigate_to_step( $request ) {
		$target_step     = isset( $request['step'] ) ? absint( $request['step'] ) : 1;
		$current_step    = self::get_current_step();
		$membership_type = get_option( 'urm_onboarding_membership_type', '' );

		if ( ! self::is_step_accessible( $target_step, $membership_type ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'This step is not accessible with current settings.', 'user-registration' ),
				),
				400
			);
		}

		if ( $target_step > $current_step ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Cannot skip ahead to incomplete steps.', 'user-registration' ),
				),
				400
			);
		}

		self::update_current_step( $target_step );

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'message'      => __( 'Navigation successful.', 'user-registration' ),
				'current_step' => $target_step,
			),
			200
		);
	}

	/**
	 * Get current onboarding step.
	 *
	 * @since x.x.x
	 *
	 * @return int
	 */
	protected static function get_current_step() {
		return (int) get_option( 'urm_onboarding_current_step', 1 );
	}

	/**
	 * Update current onboarding step.
	 *
	 * @since x.x.x
	 *
	 * @param int $step Step number.
	 * @return bool
	 */
	protected static function update_current_step( $step ) {
		return update_option( 'urm_onboarding_current_step', (int) $step );
	}

	/**
	 * Calculate the next step based on current step and membership type.
	 *
	 * @since x.x.x
	 *
	 * @param int    $current_step    Current step.
	 * @param string $membership_type Membership type.
	 * @return int
	 */
	protected static function calculate_next_step( $current_step, $membership_type ) {
		$next_step = $current_step + 1;

		if ( 'normal' === $membership_type ) {
			if ( 1 === $current_step ) {
				return 4;
			}
			if ( 4 === $current_step ) {
				return 5;
			}
		}

		if ( 2 === $next_step && 'normal' === $membership_type ) {
			$next_step = 4;
		}

		if ( 3 === $next_step && 'paid_membership' !== $membership_type ) {
			$next_step = 5;
		}

		if ( 4 === $next_step && 'normal' !== $membership_type ) {
			$next_step = 5;
		}

		return min( $next_step, 5 );
	}

	/**
	 * Get boolean option with normalization.
	 *
	 * @since x.x.x
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	protected static function get_bool_option( $option_name ) {
		$value = get_option( $option_name, false );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( $value, array( 'yes', '1', 1, true ), true );
	}

	/**
	 * Get page URL from an option key.
	 *
	 * @since x.x.x
	 *
	 * @param string $option_key Option key storing page ID.
	 * @return string|null
	 */
	protected static function get_page_url( $option_key ) {
		$page_id = (int) get_option( $option_key, 0 );

		if ( ! $page_id ) {
			return null;
		}

		return get_permalink( $page_id );
	}

	/**
	 * Get preferred registration page URL.
	 *
	 * Prefers membership registration page if exists, otherwise normal registration page.
	 *
	 * @since x.x.x
	 *
	 * @return string|null
	 */
	protected static function get_registration_page_url() {
		$member_page_id = (int) get_option( 'user_registration_member_registration_page_id', 0 );

		if ( $member_page_id > 0 ) {
			return get_permalink( $member_page_id );
		}

		$reg_page_id = (int) get_option( 'user_registration_registration_page_id', 0 );

		return $reg_page_id > 0 ? get_permalink( $reg_page_id ) : null;
	}

	/**
	 * Build onboarding snapshot data.
	 *
	 * @since x.x.x
	 *
	 * @return array
	 */
	protected static function build_onboarding_snapshot() {
		return array(
			'onboarding' => array(
				'current_step' => (int) get_option( 'urm_onboarding_current_step', 1 ),
				'completed_at' => get_option( 'urm_onboarding_completed_at', '' ),
				'is_completed' => ! get_option( 'user_registration_first_time_activation_flag', true ),
				'is_skipped'   => (bool) get_option( 'user_registration_onboarding_skipped', false ),

				'welcome'      => array(
					'membership_type'      => get_option( 'urm_onboarding_membership_type', '' ),
					'allow_usage_tracking' => (bool) get_option( 'user_registration_allow_usage_tracking', true ),
					'admin_email'          => get_option(
						'user_registration_updates_admin_email',
						get_option( 'admin_email' )
					),
				),

				'memberships'  => array(
					'ids' => (array) get_option( 'urm_onboarding_membership_ids', array() ),
				),

				'payments'     => array(
					'currency'         => get_option( 'user_registration_payment_currency', 'USD' ),
					'enabled_gateways' => (array) get_option( 'urm_enabled_payment_gateways', array() ),
				),

				'settings'     => array(
					'login_option' => get_option(
						'user_registration_general_setting_login_options',
						'default'
					),
					'default_role' => get_option(
						'user_registration_form_setting_default_user_role',
						'subscriber'
					),
				),
			),

			'meta'       => array(
				'site_url' => get_bloginfo( 'url' ),
				'saved_at' => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Save onboarding snapshot to options table.
	 *
	 * @since x.x.x
	 *
	 * @return bool
	 */
	protected static function save_onboarding_snapshot() {
		return update_option(
			self::OPTION_ONBOARDING_SNAPSHOT,
			self::build_onboarding_snapshot(),
			false
		);
	}

	/**
	 * Permission callback for all getting started endpoints.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return bool
	 */
	public static function check_admin_permissions( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', 'Admins only.', array( 'status' => 403 ) );
		}

		return true;
	}
}
