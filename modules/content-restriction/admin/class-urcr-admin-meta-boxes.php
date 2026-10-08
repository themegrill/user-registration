<?php
/**
 * User Registration Content Restriction Meta Boxes
 *
 * Sets up the write panels used by custom post types.
 *
 * @class    URCR_Admin_Meta_Boxes
 * @version  4.0
 * @package  UserRegistrationContentRestriction/Admin/Meta Boxes
 * @category Admin
 * @author   WPEverest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URCR_Admin_Meta_Boxes Class
 */
if ( ! defined( 'UR_PLUGIN_FILE' ) ) {
	return;
}
if ( ! class_exists( 'UR_Meta_Boxes' ) ) {
	include_once dirname( UR_PLUGIN_FILE ) . '/includes/abstracts/abstract-ur-meta-boxes.php';
}

class URCR_Admin_Meta_Box extends UR_Meta_Boxes {
	/**
	 * ID for module
	 *
	 * @var bool
	 */
	public $id = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id = 'content_restriction';

		if ( get_option( 'user_registration_content_restriction_enable' ) == 'no' ) {
			return;
		}

		if ( is_admin() ) {
			add_action( 'load-post.php', array( $this, 'init_metabox' ) );
			add_action( 'load-post-new.php', array( $this, 'init_metabox' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'register_styles' ) );
		}
	}

	public function register_scripts( $hook ) {
		// enqueue scripts here.
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		if ( in_array( $screen_id, ur_get_screen_ids(), true ) || $hook == 'post-new.php' || $hook == 'post.php' ) {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_register_script( 'custom-js', UR()->plugin_url() . '/assets/js/modules/content-restriction/admin/urcr-custom' . $suffix . '.js', array( 'jquery', 'ur-enhanced-select', 'selectWoo' ), UR_VERSION );
			wp_enqueue_script( 'custom-js' );
			wp_enqueue_style( 'select2', UR()->plugin_url() . '/assets/css/select2/select2.css', array(), UR_VERSION );

			wp_register_style( 'user-registration-metabox', UR()->plugin_url() . '/assets/css/metabox.css', array(), UR_VERSION );
			wp_enqueue_style( 'user-registration-metabox' );
		}
	}

	public function register_styles( $hook ) {
		// enqueue styles here.
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		if ( in_array( $screen_id, ur_get_screen_ids(), true ) || $hook == 'post-new.php' || $hook == 'post.php' ) {
			wp_enqueue_style( 'select2' );
		}
	}

	/**
	 * Meta box initialization.
	 */
	public function init_metabox() {
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'save_post', array( $this, 'save_metabox' ), 10, 2 );
	}

	/**
	 * Adds the meta box.
	 */
	public function add_metabox() {
		global $post;

		// Check if we should show the metabox
		if ( ! $this->should_show_metabox( $post ) ) {
			return;
		}

		add_meta_box(
			'urcr-meta-box',
			__( 'Restrict This Content (Legacy)', 'user-registration' ),
			array( $this, 'render_metabox' ),
			array( 'post', 'page' ),
			'advanced',
			'default'
		);
	}

	/**
	 * Check if metabox should be shown for this post/page.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool True if metabox should be shown, false otherwise.
	 */
	private function should_show_metabox( $post ) {
		if ( ! $post || ! isset( $post->ID ) ) {
			return false;
		}

		// Only show for posts and pages
		if ( ! isset( $post->post_type ) || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return false;
		}

		// Get migrated post/page IDs
		$migrated_ids = get_option( 'urcr_migrated_post_page_ids', array() );
		if ( ! is_array( $migrated_ids ) ) {
			$migrated_ids = array();
		}

		// Check if this post/page has urcr_meta_checkbox enabled and migration not done
		$has_checkbox = get_post_meta( $post->ID, 'urcr_meta_checkbox', true ) === 'on';
		$is_migrated = in_array( $post->ID, $migrated_ids, true );

		if ( $has_checkbox && ! $is_migrated || $is_migrated) {
			return true; // Show metabox for posts with checkbox that haven't been migrated
		}

		// Check if this post/page has override global settings enabled
		$has_override = get_post_meta( $post->ID, 'urcr_meta_override_global_settings', true ) === 'on';
		if ( $has_override ) {
			return true; // Show metabox for posts with override enabled
		}

		// Don't show metabox for migrated posts/pages
		return false;
	}

	/**
	 * Renders the migration notice for migrated posts/pages.
	 *
	 * @param int|string $migrated_rule_id The ID of the migrated content access rule.
	 */
	private function render_migration_notice( $migrated_rule_id = '') {
		$message          = esc_html__( "This page's restriction has been converted to a Content Rule. Manage it with your other rules in the Content Rules screen.", 'user-registration' );
		$link_text        = esc_html__( 'View Content Rule', 'user-registration' );
		$sub_notice = esc_html__( 'This setting will be removed in a future version.', 'user-registration' );

		if ( ! empty( $migrated_rule_id ) ) {
		$content_rules_url = admin_url( 'admin.php?page=user-registration-content-restriction&id=' . absint( $migrated_rule_id ) );
		} else {
		$message          = esc_html__( "This page uses custom restriction settings. We recommend switching to Content Rules for centralized management.", 'user-registration' );
		$content_rules_url = admin_url( 'admin.php?page=user-registration-content-restriction' );
		$link_text        = esc_html__( 'Manage Content Rules', 'user-registration' );
		}

		echo '<div class="user-registration-notice">';
		echo '<div class=" user-registration-notice-text">';
		echo '<p><strong>' . esc_html( $message ) . '</strong></p>';
		echo '<p><a target="_blank" href="' . esc_url( $content_rules_url ) . '">' . esc_html( $link_text ) . '</a></p>';
		echo '</div>';
		if( ! empty( $migrated_rule_id )) {
		echo '<p class="ur-notice-subtitle">' . esc_html( $sub_notice ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Renders the meta box.
	 */
	public function render_metabox( $post ) {
		wp_nonce_field( 'custom_nonce_action', 'custom_nonce', false );
		// Get migrated post/page IDs
		$migrated_ids = get_option( 'urcr_migrated_post_page_ids', array() );
		$is_migrated = in_array( $post->ID, $migrated_ids, true );

		if( $is_migrated ) {
			$migrated_rule_id = get_post_meta( $post->ID, 'urcr_migrated_rule_id', true );
			$this->render_migration_notice( $migrated_rule_id );
		} else{
			$this->render_migration_notice();

			$this->ur_metabox_checkbox(
				array(
					'id'    => 'urcr_meta_override_global_settings',
					'label' => 'Enable Custom Restrictions for This Page:',
					'type'  => 'Checkbox',
				)
			);
			echo '<p  style="margin: -10px 0 0 0;">' . sprintf( __( 'When enabled, the settings below will restrict access to this page. When disabled, only Content Rules will apply.', 'user-registration' ), admin_url( 'admin.php?page=user-registration-settings&tab=content_restriction' ) ) . '</p>';

			$this->ur_metabox_select(
				array(
					'id'      => 'urcr_allow_to',
					'label'   => __( 'Allow Access To: ', 'user-registration' ),
					'options' => array( 'All Logged In Users', 'Choose Specific Roles', 'Guest Users', 'Memberships' ),
					'desc'    => __( 'Only select this if you want to override global setting for allow option', 'user-registration' ),
					'class'   => 'ur-enhanced-select',
				)
			);

			$this->ur_metabox_multiple_select(
				array(
					'id'      => 'urcr_meta_roles[]',
					'label'   => __( 'Allow Access To Roles: ', 'user-registration' ),
					'options' => ur_get_all_roles(),
					'desc'    => __( 'Only select this if you want to override global setting for access roles', 'user-registration' ),
					'class'   => 'ur-enhanced-select',
				)
			);

			$this->ur_metabox_multiple_select(
				array(
					'id'      => 'urcr_meta_memberships[]',
					'label'   => __( 'Allow Access To Memberships: ', 'user-registration' ),
					'options' => get_active_membership_id_name(),
					'desc'    => __( 'Only select this if you want to override global setting for membership', 'user-registration' ),
					'class'   => 'ur-enhanced-select',
				)
			);

			$this->ur_metabox_textarea(
				array(
					'id'      => 'urcr_meta_content',
					'label'   => __( 'Restricted Content Message: ', 'user-registration' ),
					'desc'    => __( 'Enter the message to show to users who do not have access to this content.', 'user-registration' ),
					'type'   => 'tinymce',
					'default' =>  __( 'This content is restricted!', 'user-registration' ),
				)
			);
		}


		do_action( 'render_metabox_complete' );
	}

	/**
	 * Handles saving the meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return null
	 */
	public function save_metabox( $post_id, $post ) {

		if ( empty( $_POST ) ) {
			return;
		}

		// Only save meta for posts and pages
		if ( ! $post || ! isset( $post->post_type ) || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id )
			|| empty( $_POST['custom_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['custom_nonce'] ) ), 'custom_nonce_action' )
			|| wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$whole_site_access_restricted = ur_string_to_bool( get_option( 'user_registration_content_restriction_whole_site_access', false ) );

		$checkbox = isset( $_POST['urcr_meta_checkbox'] ) ? $_POST['urcr_meta_checkbox'] : '';

		$override_global_settings = isset( $_POST['urcr_meta_override_global_settings'] ) ? $_POST['urcr_meta_override_global_settings'] : '';

		$allow_to = isset( $_POST['urcr_allow_to'] ) ? $_POST['urcr_allow_to'] : '';

		$array_of_roles = isset( $_POST['urcr_meta_roles'] ) ? $_POST['urcr_meta_roles'] : '';

		$array_of_memberships = isset( $_POST['urcr_meta_memberships'] ) ? $_POST['urcr_meta_memberships'] : '';

		$restricted_message = isset( $_POST['urcr_meta_content'] ) ? wp_kses_post( wp_unslash( $_POST['urcr_meta_content'] ) ) : '';

		if ( ! $whole_site_access_restricted ) {
			update_post_meta( $post_id, 'urcr_meta_checkbox', $checkbox );
		}

		update_post_meta( $post_id, 'urcr_meta_override_global_settings', $override_global_settings );

		update_post_meta( $post_id, 'urcr_allow_to', $allow_to );

		update_post_meta( $post_id, 'urcr_meta_roles', $array_of_roles );

		update_post_meta( $post_id, 'urcr_meta_memberships', $array_of_memberships );

		update_post_meta( $post_id, 'urcr_meta_content', $restricted_message );


	}
}

new URCR_Admin_Meta_Box();
