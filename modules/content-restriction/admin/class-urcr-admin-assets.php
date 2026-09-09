<?php
/**
 * UserRegistrationContentRestriction Admin Assets
 *
 * Load Admin Assets.
 *
 * @class    URCR_Admin_Assets
 * @version  1.0.0
 * @package  UserRegistrationContentRestriction/Admin
 * @category Admin
 * @author   WPEverest
 */

use WPEverest\URMembership\Admin\Repositories\MembershipRepository;

defined( 'ABSPATH' ) || exit;

/**
 * URCR_Admin_Assets Class
 */
class URCR_Admin_Assets {
	public $current_page = '';
	public $action       = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		$this->current_page = isset( $_GET['page'] ) ? $_GET['page'] : '';
		$this->action       = isset( $_GET['action'] ) ? $_GET['action'] : '';
	}

	public function enqueue_admin_scripts() {
		$suffix = defined( 'SCRIPT_DEBUG' ) ? '' : '.min';
		/**
		 * Local JS scripts.
		 */
		wp_register_script(
			'urcr-content-access-rule-creator',
			UR()->plugin_url() . '/assets/js/modules/content-restriction/admin/urcr-content-access-rule-creator' . $suffix . '.js',
			array(
				'jquery',
			),
			UR_VERSION,
			true
		);

		if ( 'user-registration-content-restriction' === $this->current_page ) {
			// Enqueue media scripts for media button functionality
			wp_enqueue_media();

			wp_enqueue_script( 'flatpickr' );

			wp_enqueue_script( 'urcr-content-access-rule-creator' );
		}
	}

	/**
	 * Enqueue styles.
	 */
	public function enqueue_admin_styles() {
		/**
		 * Third party style scripts.
		 */
		if ( function_exists( 'UR' ) ) {
			wp_register_style( 'flatpickr', UR()->plugin_url() . '/assets/css/flatpickr/flatpickr.min.css', array(), UR_VERSION );
		}

		/**
		 * Local style scripts.
		 */

		if ( function_exists( 'UR' ) ) {
			wp_register_style( 'ur-core-builder-style', UR()->plugin_url() . '/assets/css/admin.css', array(), UR()->version );
		}

		if ( 'user-registration-content-restriction' === $this->current_page ) {
			wp_enqueue_style( 'select2' );
			wp_enqueue_style( 'sweetalert2' );
			wp_enqueue_style( 'flatpickr' );
			wp_enqueue_style( 'urcr-content-access-rule-creator' );
			wp_enqueue_style( 'ur-core-builder-style' );

			// Enqueue shared content restriction styles
			wp_register_style(
				'urcr-shared',
				UR()->plugin_url() . '/assets/css/urcr-shared.css',
				array(),
				UR()->version
			);
			wp_enqueue_style( 'urcr-shared' );

			// React viewer mode - only load viewer styles
			wp_register_style(
				'urcr-content-access-restriction',
				UR()->plugin_url() . '/assets/css/urcr-content-access-restriction.css',
				array( 'urcr-shared' ),
				UR_VERSION
			);
			wp_enqueue_style( 'urcr-content-access-restriction' );

		}
	}


	/**
	 * Get localized data array for scripts.
	 * This method prepares all the localized data that can be used for script localization.
	 *
	 * @return array Localized data array.
	 */
	public static function get_localized_data() {

		// Prepare user registration sources.

		$ur_forms = ur_get_all_user_registration_form();
		$networks = array();
		if ( ur_check_module_activation( 'social-connect' ) ) {
			$networks = array(
				'facebook' => esc_html__( 'Facebook', 'user-registration' ),
				'linkedin' => esc_html__( 'LinkedIn', 'user-registration' ),
				'google'   => esc_html__( 'Google', 'user-registration' ),
				'twitter'  => esc_html__( 'Twitter', 'user-registration' ),
			);
		}
		$registration_source_ids = array_merge( array_keys( $ur_forms ), array_keys( $networks ) );
		$registration_sources    = array_combine( $registration_source_ids, array_merge( $ur_forms, $networks ) );

		foreach ( $ur_forms as $form_id => $label ) {
			$form_data   = ur_pro_get_form_fields( $form_id );

			$form_fields = array();
			foreach ( $form_data as $field_name => $data ) {
				if( !empty($data['field_key']) && "membership" === $data['field_key'] ) {
					continue;
				}
				$form_fields[ $field_name ] = array(
					'label'   => isset( $data['label'] ) ? $data['label'] : $field_name,
					'type'    => isset( $data['type'] ) ? $data['type'] : '',
					'options' => isset( $data['options'] ) ? $data['options'] : array(),
				);
			}
			$ur_forms[ $form_id ] = $form_fields;
		}

		// Prepare list of posttypes.

		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);
		$post_types = wp_list_pluck( $post_types, 'label', 'name' );

		// Prepare list of taxonomy.

		$taxonomies = get_taxonomies(
			array(
				'public' => true,
			),
			'objects'
		);

		$taxonomies = wp_list_pluck( $taxonomies, 'label', 'name' );

		// Prepare terms of taxonomy.

		$terms_list = array();

		foreach ( $taxonomies as $tax_name => $tax_label ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax_name,
					'hide_empty' => false,
				)
			);

			// Handle WP_Error or empty results
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				$terms_list[ $tax_name ] = array();
			} else {
				$terms_list[ $tax_name ] = wp_list_pluck( $terms, 'name', 'term_id' );
			}
		}

		// Prepare list of posts.

		$posts = get_posts(
			array(
				'post_status' => 'publish',
				'numberposts' => 100,
			)
		);
		$posts = wp_list_pluck( $posts, 'post_title', 'ID' );

		// Prepare list of pages.

		$pages              = get_pages(
			array(
				'post_status' => 'publish',
				'numberposts' => 100,
			)
		);
		$pages              = wp_list_pluck( $pages, 'post_title', 'ID' );
		$pages_for_redirect = $pages;
		// Filter out excluded pages
		if ( function_exists( 'urcr_get_excluded_page_ids' ) ) {
			$excluded_page_ids = urcr_get_excluded_page_ids();
			foreach ( $excluded_page_ids as $excluded_page_id ) {
				if ( isset( $pages[ $excluded_page_id ] ) ) {
					unset( $pages[ $excluded_page_id ] );
				}
			}
		}

		// Prepare list of shortcodes.

		global $shortcode_tags;
		$shortcode_names       = array_keys( $shortcode_tags );
		$shortcodes_list       = array_combine( $shortcode_names, $shortcode_names );
		$formatted_memberships = array();

		if ( ( ( function_exists( 'ur_check_module_activation' ) ) && ur_check_module_activation( 'membership' ) ) ) {
			$membership_repository = new MembershipRepository();
			$memberships           = $membership_repository->get_all_memberships_without_status_filter();

			array_map(
				function ( $membership ) use ( &$formatted_memberships ) {
					$formatted_memberships[ $membership['ID'] ] = $membership['post_title'];
				},
				$memberships
			);
		}

		// Prepare content type options.
		$content_type_options = array(
			array(
				'value' => 'whole_site',
				'label' => esc_html__( 'Whole Site', 'user-registration' ),
			),
			array(
				'value' => 'pages',
				'label' => esc_html__( 'Pages', 'user-registration' ),
			),
			array(
				'value' => 'posts',
				'label' => esc_html__( 'Posts', 'user-registration' ),
			),
			array(
				'value' => 'post_types',
				'label' => esc_html__( 'Post Type', 'user-registration' ),
			),
			array(
				'value' => 'taxonomy',
				'label' => esc_html__( 'Taxonomy', 'user-registration' ),
			),
		);

		/**
		 * Filter content type options for the content restriction dropdown.
		 *
		 * @since 1.0.0
		 *
		 * @param array $content_type_options Array of content type options with 'value' and 'label' keys.
		 */
		$content_type_options = apply_filters( 'urcr_content_type_options', $content_type_options );

		// Prepare condition options.
		$condition_options = array(
			array(
				'value'          => 'membership',
				'label'          => esc_html__( 'Membership', 'user-registration' ),
				'type'           => 'multiselect',
				'operator_label' => esc_html__( 'is', 'user-registration' ),
				'placeholder'    => '',
			),
			array(
				'value'          => 'user_state',
				'label'          => esc_html__( 'Login Status', 'user-registration' ),
				'type'           => 'checkbox',
				'operator_label' => esc_html__( 'is', 'user-registration' ),
				'placeholder'    => '',
			),
			array(
				'value'          => 'roles',
				'label'          => esc_html__( 'Roles', 'user-registration' ),
				'type'           => 'multiselect',
				'operator_label' => esc_html__( 'is', 'user-registration' ),
				'placeholder'    => '',
			),
			array(
				'value'             => 'user_registered_date',
				'label'             => esc_html__( 'User Registered Date', 'user-registration' ),
				'type'              => 'date',
				'operator_label'    => esc_html__( 'is', 'user-registration' ),
				'placeholder'       => '',
				'date_type_options' => array(
					array(
						'value' => 'before',
						'label' => esc_html__( 'Before', 'user-registration' ),
					),
					array(
						'value' => 'after',
						'label' => esc_html__( 'After', 'user-registration' ),
					),
					array(
						'value' => 'range',
						'label' => esc_html__( 'Range', 'user-registration' ),
					),
				),
			),
			array(
				'value'          => 'access_period',
				'label'          => esc_html__( 'Registration Period', 'user-registration' ),
				'type'           => 'period',
				'operator_label' => esc_html__( 'is', 'user-registration' ),
				'placeholder'    => '',
			),
			array(
				'value'          => 'email_domain',
				'label'          => esc_html__( 'Email Domain', 'user-registration' ),
				'type'           => 'text',
				'operator_label' => esc_html__( 'is', 'user-registration' ),
				'placeholder'    => esc_html__( 'gmail.com, outlook.com', 'user-registration' ),
			),
			array(
				'value'          => 'post_count',
				'label'          => esc_html__( 'Min. Public Posts', 'user-registration' ),
				'type'           => 'number',
				'operator_label' => esc_html__( 'is', 'user-registration' ),
				'placeholder'    => esc_html__( '10', 'user-registration' ),
			),
			array(
				'value'          => 'capabilities',
				'label'          => esc_html__( 'Capabilities', 'user-registration' ),
				'type'           => 'multiselect',
				'operator_label' => esc_html__( 'is', 'user-registration' ),
				'placeholder'    => '',
			),
			array(
				'value'          => 'registration_source',
				'label'          => esc_html__( 'Registration Source', 'user-registration' ),
				'type'           => 'multiselect',
				'operator_label' => esc_html__( 'via', 'user-registration' ),
				'placeholder'    => '',
			),
			array(
				'value'          => 'ur_form_field',
				'label'          => esc_html__( 'URM Form Field', 'user-registration' ),
				'type'           => 'multiselect',
				'operator_label' => esc_html__( 'is', 'user-registration' ),
				'placeholder'    => '',
			),
		);

		/**
		 * Filter condition options for the condition row dropdown.
		 *
		 * @since 1.0.0
		 *
		 * @param array $condition_options Array of condition options with 'value', 'label', and 'type' keys.
		 */
		$condition_options = apply_filters( 'urcr_condition_options', $condition_options );

		// Prepare action type options.
		$action_type_options = array(
			array(
				'value' => 'message',
				'label' => esc_html__( 'Show Message', 'user-registration' ),
			),
			array(
				'value' => 'redirect',
				'label' => esc_html__( 'Redirect', 'user-registration' ),
			),
			array(
				'value' => 'local_page',
				'label' => esc_html__( 'Redirect to a Local Page', 'user-registration' ),
			),
		);

		$urm_is_new_installation = get_option( 'urm_is_new_installation', false );
		$is_old_installation     = ( false === $urm_is_new_installation || ! $urm_is_new_installation );

		if ( $is_old_installation ) {
			$action_type_options[] = array(
				'value' => 'ur-form',
				'label' => esc_html__( 'Show UR Form', 'user-registration' ),
			);
			$action_type_options[] = array(
				'value' => 'shortcode',
				'label' => esc_html__( 'Render Shortcode', 'user-registration' ),
			);
		}

		/**
		 * Filter action type options for the action dropdown.
		 *
		 * @since 1.0.0
		 *
		 * @param array $action_type_options Array of action type options with 'value' and 'label' keys.
		 */
		$action_type_options = apply_filters( 'urcr_action_type_options', $action_type_options );

		// Check membership module status and count
		$membership_count             = 0;
		$is_membership_module_enabled = false;
		$has_multiple_memberships     = false;

		if ( function_exists( 'ur_check_module_activation' ) && ur_check_module_activation( 'membership' ) ) {
			$is_membership_module_enabled = true;
			if ( class_exists( '\WPEverest\URMembership\Admin\Services\MembershipService' ) ) {
				$membership_repository    = new MembershipRepository();
				$memberships              = $membership_repository->get_all_memberships_without_status_filter();
				$membership_count         = is_array( $memberships ) ? count( $memberships ) : 0;
				$has_multiple_memberships = $membership_count > 1;
			}
		}

		// Get smart tags list
		$smart_tags_list = array();
		if ( class_exists( 'UR_Smart_Tags' ) ) {
			$smart_tags_list = UR_Smart_Tags::smart_tags_list();
		}

		// Filter to only include sign_up and log_in tags for content restriction editor
		// The smart tags list uses keys with curly braces like {{sign_up}} and {{log_in}}
		$allowed_tags    = array( '{{sign_up}}', '{{log_in}}' );
		$smart_tags_list = array_intersect_key( $smart_tags_list, array_flip( $allowed_tags ) );

		/**
		 * Filter smart tags list for content restriction editor.
		 *
		 * @param array $smart_tags_list List of smart tags.
		 * @param string $editor_id Editor ID (optional, for context-specific filtering).
		 */
		$smart_tags_list = apply_filters( 'urcr_smart_tags_list', $smart_tags_list );

		// Check if smart tags button should be shown (configurable via filter)
		$show_smart_tags_button = apply_filters( 'urcr_show_smart_tags_button', true, 'urcr-action-message-editor' );

		$localized_data = array(
			'URCR_DEBUG'                             => apply_filters( 'urcr_debug_mode', true ),
			'UR_DEV'                                 => defined( 'UR_DEV' ) && UR_DEV,
			'_nonce'                                 => wp_create_nonce( 'urcr_manage_content_access_rule' ),
			'ajax_url'                               => admin_url( 'admin-ajax.php' ),
			'wp_roles'                               => ur_get_all_roles(),
			'wp_capabilities'                        => urcr_get_all_capabilities(),
			'ur_forms'                               => ur_get_all_user_registration_form(),
			'registration_sources'                   => $registration_sources,
			'post_types'                             => $post_types,
			'taxonomies'                             => $taxonomies,
			'terms_list'                             => $terms_list,
			'posts'                                  => $posts,
			'pages'                                  => $pages,
			'pages_for_redirect'                     => $pages_for_redirect,
			'ur_form_data'                           => $ur_forms,
			'shortcodes'                             => $shortcodes_list,
			'content_rule_url'                       => admin_url( 'admin.php?page=user-registration-content-restriction&action=add_new_urcr_content_access_rule' ),
			'content_restriction_settings_url'           => admin_url( 'admin.php?page=user-registration-settings&tab=membership&section=content-rules' ),
			'memberships'                            => $formatted_memberships,
			'is_pro'                                 => defined( 'UR_PRO_ACTIVE' ) && UR_PRO_ACTIVE,
			'content_type_options'                   => $content_type_options,
			'condition_options'                      => $condition_options,
			'masteriyo_courses'                      => class_exists( 'WPEverest\URM\Masteriyo\Helper' ) ? WPEverest\URM\Masteriyo\Helper::get_courses( array(), '', 'free' ) : array(),
			'is_membership_module_enabled'           => $is_membership_module_enabled,
			'membership_count'                       => $membership_count,
			'has_multiple_memberships'               => $has_multiple_memberships,
			'is_content_restriction_enabled'         => ur_check_module_activation( 'content-restriction' ),
			'action_type_options'                    => $action_type_options,
			'urm_is_new_installation'                => $urm_is_new_installation,
			'smart_tags_list'                        => $smart_tags_list,
			'show_smart_tags_button'                 => $show_smart_tags_button,
			'smart_tags_dropdown_title'              => __( 'Smart Tags', 'user-registration' ),
			'smart_tags_dropdown_search_placeholder' => __( 'Search Tags...', 'user-registration' ),
			'membership_default_message'             => '<h3>' . __( 'Membership Required', 'user-registration' ) . '</h3>
<p>' . __( 'This content is available to members only.', 'user-registration' ) . '</p>
<p>' . __( 'Sign up to unlock access or log in if you already have an account.', 'user-registration' ) . '</p>
<p>{{sign_up}} {{log_in}}</p>',
			'labels'                                 => array(
				'pages'                   => __( 'Pages', 'user-registration' ),
				'posts'                   => __( 'Posts', 'user-registration' ),
				'post_types'              => __( 'Post Types', 'user-registration' ),
				'taxonomy'                => __( 'Taxonomy', 'user-registration' ),
				'whole_site'              => __( 'Whole Site', 'user-registration' ),
				'logged_in'               => __( 'Logged In', 'user-registration' ),
				'logged_out'              => __( 'Logged Out', 'user-registration' ),
				'membership'              => __( 'Membership', 'user-registration' ),
				'membership_rule_title'   => __( 'Membership Access Rule', 'user-registration' ),
				'all_content_types_added' => __( 'All content types have been added', 'user-registration' ),
				'ajax_all_forms_nonce'    => wp_create_nonce( 'all-forms-ajax-nonce' ),
			),
			'is_drip_content'                        => ur_check_module_activation( 'content-drip' ),
			'is_masteriyo'                           => ur_check_module_activation( 'masteriyo-course-integration' ),
			'utm_campaign'                           => UR()->utm_campaign,

		);

		/**
		 * Filter the entire localized data array for content restriction.
		 *
		 * @param array $localized_data The complete localized data array.
		 */
		return apply_filters( 'urcr_localized_data', $localized_data );
	}

	/**
	 * Localize React scripts with urcr_localized_data.
	 *
	 * @param string $script_handle The script handle to localize.
	 */
	public static function localize_react_scripts( $script_handle ) {
		$localized_data = self::get_localized_data();
		wp_localize_script(
			$script_handle,
			'urcr_localized_data',
			$localized_data
		);
	}

	/**
	 * Get the default message for content restriction (membership_default_message).
	 *
	 * @return string Default message.
	 */
	public static function get_default_message() {
		$localized_data = self::get_localized_data();
		return isset( $localized_data['membership_default_message'] ) ? $localized_data['membership_default_message'] : '<h3>' . __( 'Membership Required', 'user-registration' ) . '</h3>
<p>' . __( 'This content is available to members only.', 'user-registration' ) . '</p>
<p>' . __( 'Sign up to unlock access or log in if you already have an account.', 'user-registration' ) . '</p>
<p>{{sign_up}} {{log_in}}</p>';
	}
}

return new URCR_Admin_Assets();
