<?php
/**
 * User registration blocks.
 *
 * @since 3.1.5
 * @package user-registration
 */

defined( 'ABSPATH' ) || exit;
/**
 * User registration blocks class.
 */
class UR_Blocks {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Init hooks.
	 *
	 * @since 3.1.5
	 */
	private function init_hooks() {
		add_filter( 'block_categories_all', array( $this, 'block_categories' ), PHP_INT_MAX, 2 );
		add_action( 'init', array( $this, 'register_block_types' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}
	/**
	 * Enqueue Block Editor Assets.
	 *
	 * @return void.
	 */
	public function enqueue_block_editor_assets() {
		global $pagenow;
		$enqueue_script = array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-editor', 'wp-components', 'react', 'react-dom', 'tooltipster' );

		if ( 'widgets.php' === $pagenow ) {
			unset( $enqueue_script[ array_search( 'wp-editor', $enqueue_script ) ] );
		}

		wp_register_style(
			'user-registration-blocks-editor',
			UR()->plugin_url() . '/assets/css/user-registration.css',
			array( 'wp-edit-blocks' ),
			UR_VERSION
		);

		wp_register_script(
			'user-registration-blocks-editor',
			UR()->plugin_url() . '/chunks/blocks.js',
			$enqueue_script,
			UR_VERSION
		);

		wp_register_style(
			'user-registration-blocks-editor-style',
			UR()->plugin_url() . '/chunks/blocks.css',
			array(),
			UR_VERSION
		);

		wp_enqueue_style( 'user-registration-blocks-editor-style' );

		if ( ur_check_module_activation( 'membership' ) ) {

			wp_register_style( 'user-registration-membership-frontend-style', UR()->plugin_url(). '/assets/css/modules/membership/user-registration-membership-frontend.css', array(), UR_VERSION );
			wp_enqueue_style( 'user-registration-membership-frontend-style' );
		}

		$smart_tag = array(
			array(
				'text'  => esc_html__( 'Membership Plan Details', 'user-registration' ),
				'value' => '{{membership_plan_details}}',
			),
			array(
				'text'  => esc_html__( 'All Fields', 'user-registration' ),
				'value' => '{{all_fields}}',
			),
			array(
				'text'  => esc_html__( 'User Name', 'user-registration' ),
				'value' => '{{username}}',
			),
			array(
				'text'  => esc_html__( 'Email', 'user-registration' ),
				'value' => '{{email}}',
			),
			array(
				'text'  => esc_html__( 'First Name', 'user-registration' ),
				'value' => '{{first_name}}',
			),
			array(
				'text'  => esc_html__( 'Last Name', 'user-registration' ),
				'value' => '{{last_name}}',
			),
			array(
				'text'  => esc_html__( 'User Display Name', 'user-registration' ),
				'value' => '{{display_name}}',
			),
		);

		$smart_tag = apply_filters( 'user_registration_thank_you_page_smart_tags', $smart_tag );

		$pages = array_map(
			function ( $page ) {
				return array(
					'label' => $page->post_title,
					'value' => $page->ID,
				);
			},
			get_pages()
		);

		$page_options = array_merge(
			array(
				array(
					'label' => __( 'Select a page', 'user-registration' ),
					'value' => 0,
				),
			),
			$pages
		);

		wp_localize_script(
			'user-registration-blocks-editor',
			'_UR_BLOCKS_',
			array(
				'logoUrl'                     => UR()->plugin_url() . '/assets/images/logo.png',
				'urRestApiNonce'              => wp_create_nonce( 'wp_rest' ),
				'isPro'                       => is_plugin_active( 'user-registration-pro/user-registration.php' ),
				'iscRestrictionActive'        => ur_check_module_activation( 'content-restriction' ),
				'pages'                       => $pages,
				'login_page_id'               => get_option( 'user_registration_login_page_id' ),
				'urcrConfigurl'               => ur_check_module_activation( 'content-restriction' ) ? admin_url( 'admin.php?page=user-registration-content-restriction' ) : '',
				'urcrGlobalRestrictionMsgUrl' => ur_check_module_activation( 'content-restriction' ) ? admin_url( 'admin.php?page=user-registration-settings&tab=membership&section=content-rules' ) : '',
				'isProActive'                 => UR_PRO_ACTIVE,
				'smart_tags'                  => $smart_tag,
				'pages_array'                 => $page_options,
				'membership_all_plan_url'     => admin_url( 'admin.php?page=user-registration-membership' ),
				'membership_group_url'        => admin_url( 'admin.php?page=user-registration-membership&action=list_groups' ),
				'bank_details_settings'       => admin_url( 'admin.php?page=user-registration-settings&tab=payment' ),
			)
		);
		wp_register_script(
			'user-registration-shortcode-embed-form',
			UR()->plugin_url() . '/assets/js/admin/shortcode-form-embed.js',
			$enqueue_script,
			UR_VERSION
		);

		wp_enqueue_script( 'user-registration-blocks-editor' );
		if ( 'post.php' === $pagenow && isset( $_GET['action'] ) && 'edit' === $_GET['action'] && isset( $_GET['form'] ) && 'user_registration' === $_GET['form'] ) {
			wp_enqueue_script( 'user-registration-shortcode-embed-form' );
			wp_localize_script(
				'user-registration-shortcode-embed-form',
				'user_registration_blocks_editor_prams',
				array(
					'i18n_add_a_block'     => esc_html__( 'Add a block', 'user-registration' ),
					'i18n_add_a_block_tip' => sprintf( '%s %s', esc_html__( 'Click the plus button, search for User Registration & Membership, click the block to embed it. ', 'user-registration' ), '<a href="#" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn More', 'user-registration' ) . '</a>' ),
					'i18n_done_btn'        => esc_html__( 'Done', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Add "User Registration" category to the blocks listing in post edit screen.
	 *
	 * @param array $block_categories All registered block categories.
	 * @return array
	 * @since 3.1.5
	 */
	public function block_categories( array $block_categories ) {
		return array_merge(
			array(
				array(
					'slug'  => 'user-registration',
					'title' => esc_html__( 'User Registration and Membership', 'user-registration' ),
				),
			),
			$block_categories
		);
	}
	/**
	 * Register block types.
	 *
	 * @return void
	 */
	public function register_block_types() {
		$block_types = $this->get_block_types();
		foreach ( $block_types as $block_type ) {
			new $block_type();
		}
	}

	/**
	 * Get block types.
	 *
	 * @return AbstractBlock[]
	 */
	private function get_block_types() {

		$ur_blocks_classes = array(
			UR_Block_Regstration_Form::class, //phpcs:ignore;
			UR_Block_Login_Form::class, //phpcs:ignore;
			UR_Block_Myaccount::class, //phpcs:ignore;
			UR_Block_Edit_Profile::class, //phpcs:ignore;
			UR_Block_Edit_Password::class, //phpcs:ignore;
			UR_Block_Login_Logout_Menu::class, //phpcs:ignore;
		);

		if ( ur_check_module_activation( 'content-restriction' ) ) {
			$ur_blocks_classes[] = UR_Block_Content_Restriction::class;
		}
		if ( ur_check_module_activation( 'membership' ) ) {
			$ur_blocks_classes[] = UR_Block_Membership_Listing::class;
			$ur_blocks_classes[] = UR_Block_Thank_You::class;
			$ur_blocks_classes[] = UR_Block_Membership_Buy_Now::class;
		}

		return apply_filters(
			'user_registration_block_types',
			$ur_blocks_classes
		);
	}
}
return new UR_Blocks();
