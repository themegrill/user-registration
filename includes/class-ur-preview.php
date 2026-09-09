<?php
/**
 * Form Preview.
 *
 * @package UserRegistration\Classes
 * @version 1.6.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle frontend forms.
 *
 * @class       UR_Preview
 * @version     1.0.0
 * @package     UserRegistration/Classes/
 */
class UR_Preview {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Init hook function.
	 */
	public function init() {
		if ( is_user_logged_in() && ! is_admin() ) {
			if ( isset( $_GET['ur_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				add_filter( 'edit_post_link', array( $this, 'edit_form_link' ) );
				add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
				add_filter( 'home_template_hierarchy', array( $this, 'template_include' ) );
				add_filter( 'frontpage_template_hierarchy', array( $this, 'template_include' ) );
				add_action( 'template_redirect', array( $this, 'handle_preview' ) );
				add_filter( 'astra_remove_entry_header_content', '__return_true' ); // Need to remove in next version, If astra release the patches.

			} elseif ( isset( $_GET['ur_login_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
				add_action( 'template_redirect', array( $this, 'handle_login_preview' ) );
				add_filter( 'home_template_hierarchy', array( $this, 'template_include' ) );
				add_filter( 'frontpage_template_hierarchy', array( $this, 'template_include' ) );
				add_filter( 'astra_remove_entry_header_content', '__return_true' ); // Need to remove in next version, If astra release the patches.

			} elseif ( isset( $_GET['ur_email_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				add_filter( 'template_include', array( $this, 'handle_email_preview' ), PHP_INT_MAX );
				add_filter( 'astra_remove_entry_header_content', '__return_true' ); // Need to remove in next version, If astra release the patches.
			}
		}
	}

	/**
	 * Change edit link of preview page.
	 *
	 * @param string $link Link.
	 */
	public function edit_form_link( $link ) {
		$form_id       = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_form_url = add_query_arg(
			array(
				'page'              => 'add-new-registration',
				'edit-registration' => $form_id,
			),
			admin_url( 'admin.php' )
		);

		$link = '<a class="post-edit-link" href="' . esc_url( $edit_form_url ) . '">' . __( 'Edit Form', 'user-registration' ) . '</a>';
		return $link;
	}

	/**
	 * Hook into pre_get_posts to limit posts.
	 *
	 * @param WP_Query $q Query instance.
	 */
	public function pre_get_posts( $q ) {
		// Limit one post to query.
		if ( $q->is_main_query() ) {
			$q->set( 'posts_per_page', 1 );
		}
	}

	/**
	 * A list of template candidates.
	 *
	 * @param array $templates A list of template candidates, in descending order of priority.
	 */
	public function template_include( $templates ) {
		return array( 'page.php', 'single.php', 'index.php' );
	}

	/**
	 * Handles the preview of form.
	 */
	public function handle_preview() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( isset( $_GET['form_id'] ) && isset( $_GET['ur-style-customizer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'the_title', array( $this, 'form_preview_title' ) );
			add_filter( 'the_content', array( $this, 'form_preview_content' ) );
			add_filter( 'get_the_excerpt', array( $this, 'form_preview_content' ) );
			add_filter( 'post_thumbnail_html', '__return_empty_string' );
		} else {
			add_filter( 'template_include', array( $this, 'ur_form_preview_template' ), PHP_INT_MAX );
		}
	}

	/**
	 * Filter the title and insert form preview title.
	 *
	 * @param  string $title Existing title.
	 * @return string
	 */
	public static function form_preview_title( $title ) {
		$form_id   = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0 ; // @codingStandardsIgnoreLine

		if ( $form_id && in_the_loop() ) {
			$form_data = UR()->form->get_form( $form_id );

			if ( ! empty( $form_data ) ) {
				/* translators: %s - Form name. */
				return sprintf( esc_html__( '%s &ndash; Preview', 'user-registration' ), sanitize_text_field( $form_data->post_title ) );
			}
		}

		return $title;
	}

	/**
	 * Displays content of form preview.
	 *
	 * @param string $content Page/Post content.
	 * @return string
	 */
	public function form_preview_content( $content ) {
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		remove_filter( 'the_content', array( $this, 'form_preview_content' ) );
		if ( function_exists( 'apply_shortcodes' ) ) {
			$content = apply_shortcodes( '[user_registration_form id="' . $form_id . '"]' );
		} else {
			$content = do_shortcode( '[user_registration_form id="' . $form_id . '"]' );
		}

		return $content;
	}

	/**
	 * Include Form Preview Template.
	 *
	 * @param string $template Template.
	 *
	 * @since 4.0
	 */
	public function ur_form_preview_template( $template ) {

		if ( is_embed() ) {
			return $template;
		}

		wp_register_style( 'user-registration-form-preview-style', UR()->plugin_url() . '/assets/css/user-registration-form-preview.css', array(), UR()->version );
		wp_register_style( 'ur-form-preview-tooltip', UR()->plugin_url() . '/assets/css/tooltipster/tooltipster-sideTip-borderless.min.css', array(), UR()->version );
		wp_register_style( 'ur-form-preview-bundle-css', UR()->plugin_url() . '/assets/css/tooltipster/tooltipster.bundle.css', array(), UR()->version );
		wp_register_style( 'ur-form-preview-min-css', UR()->plugin_url() . '/assets/css/tooltipster/tooltipster.bundle.min.css', array(), UR()->version );
		wp_enqueue_style( 'user-registration-form-preview-style' );
		wp_enqueue_style( 'ur-form-preview-tooltip' );
		wp_enqueue_style( 'ur-form-preview-bundle-css' );
		wp_enqueue_style( 'ur-form-preview-min-css' );

		wp_register_script( 'user-registration-form-preview-script', UR()->plugin_url() . '/assets/js/frontend/ur-form-preview.js', array( 'jquery', 'wp-element', 'wp-blocks', 'wp-editor', 'tooltipster' ), UR()->version );
		wp_register_script( 'ur-form-preview-copy', UR()->plugin_url() . '/assets/js/admin/ur-copy.js', array( 'jquery' ), UR()->version, true );
		wp_enqueue_script( 'user-registration-form-preview-script' );
		wp_enqueue_script( 'ur-form-preview-tooltipster' );
		wp_enqueue_script( 'ur-form-preview-copy' );

		wp_localize_script(
			'user-registration-form-preview-script',
			'user_registration_form_preview',
			array(
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'form_preview_nonce' => wp_create_nonce( 'ur_form_preview_nonce' ),
				'pro_upgrade_link'   => esc_url( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'form-preview', 'medium' => 'button' ) ) ),
			)
		);

		ob_start();
		if ( is_user_logged_in() && isset( $_GET['form_id'] ) ) {
			self::generate_form_preview();
		}

		$form_content = ob_get_clean();
		ob_start();
		self::side_panel_content();

		$side_panel_content = ob_get_clean();
		$template           = ur_get_template(
			'ur-form-preview-template.php',
			array(
				'form_content'       => $form_content,
				'side_panel_content' => $side_panel_content,
			)
		);
		return $template;
	}

	/**
	 * Handles the preview of form.
	 *
	 * @since 4.0
	 */
	public static function generate_form_preview() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( isset( $_GET['form_id'] ) ) {
			$form_id = absint( $_GET['form_id'] );// phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$html  = '';
			$html .= '<div class="ur-preview-content">';
			$html .= '<span class="ur-form-preview-title">';
			$html .= esc_html( get_the_title( $form_id ) );
			$html .= '</span>';

			if ( function_exists( 'apply_shortcodes' ) ) {
				$content = apply_shortcodes( '[user_registration_form id="' . $form_id . '"]' );
			} else {
				$content = do_shortcode( '[user_registration_form id="' . $form_id . '"]' );
			}
			$html .= $content;
			$html .= '</div>';

			echo $html;

		}
	}

	/**
	 * Handles the preview of login form.
	 */
	public function handle_login_preview() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		add_filter( 'the_title', array( $this, 'login_form_preview_title' ) );
		add_filter( 'the_content', array( $this, 'login_form_preview_content' ) );
	}

	/**
	 * Filter the title and insert form preview title.
	 *
	 * @param  string $title Existing title.
	 * @return string
	 */
	public static function login_form_preview_title( $title ) {
		if ( in_the_loop() ) {
			/*
			translators: %s - Form name. */
			// return sprintf( esc_html__( '%s &ndash; Preview', 'user-registration' ), sanitize_text_field( 'Login Form' ) );
			return '';
		}

		return $title;
	}

	/**
	 * Displays content of login form preview.
	 *
	 * @param string $content Page/Post content.
	 * @return string
	 */
	public function login_form_preview_content( $content ) {

		/**
		 * Enqueues scripts and applies filters for User Registration 'login' shortcode.
		 *
		 * The 'user_registration_my_account_enqueue_scripts' action allows developers to enqueue scripts
		 * before rendering the 'login' shortcode. The 'user_registration_login_shortcode' filter
		 * lets developers customize shortcode attributes like class, before, and after.
		 */
		do_action( 'user_registration_my_account_enqueue_scripts', array(), 0 );

		remove_filter( 'the_content', array( $this, 'form_preview_content' ) );

		wp_enqueue_script( 'ur-my-account' );
		$recaptcha_enabled = ur_option_checked( 'user_registration_login_options_enable_recaptcha', false );
		$recaptcha_node    = ur_get_recaptcha_node( 'login', $recaptcha_enabled );

		ob_start();
		echo '<div id="user-registration">';
		ur_get_template(
			'myaccount/form-login.php',
			array(
				'recaptcha_node' => $recaptcha_node,
				'redirect'       => '',
			)
		);
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Displays content of email preview.
	 *
	 * @return string
	 */
	public function handle_email_preview() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Use strict character allowlist to break taint chain (only a-z0-9 and underscore allowed).
		$option_name    = isset( $_GET['ur_email_preview'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( wp_unslash( $_GET['ur_email_preview'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$email_template = isset( $_GET['ur_email_template'] ) ? sanitize_text_field( wp_unslash( $_GET['ur_email_template'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		/**
		 * Applies a filter to modify the email classes.
		 */
		$emails = apply_filters( 'user_registration_email_classes', array() );

		$class_name = 'UR_Settings_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $option_name ) ) );

		// Validate class_name is in the allowed emails list before any class instantiation.
		$is_allowed_class = isset( $emails[ $class_name ] );

		if ( $is_allowed_class && ! class_exists( $class_name ) ) {
			$class_name = get_class( $emails[ $class_name ] );
		}
		if ( ! $is_allowed_class && ! class_exists( $class_name ) ) {
			echo '<h3>' . esc_html__( 'Something went wrong. Please verify if the email you want to preview exists or addon it is associated with is activated.', 'user-registration' ) . '</h3>';
		} else {
			$class_instance = new $class_name();

			$default_content = 'ur_get_' . $option_name;

			if ( ! method_exists( $class_instance, $default_content ) ) {
				$default_content = 'user_registration_get_' . $option_name;
			}

			// Ensure the resolved method actually exists before calling it dynamically.
			if ( ! method_exists( $class_instance, $default_content ) ) {
				echo '<h3>' . esc_html__( 'Something went wrong. Please verify if the email you want to preview exists or addon it is associated with is activated.', 'user-registration' ) . '</h3>';
				return;
			}

			$pro_content_suffix_emails = array( 'delete_account_email', 'delete_account_admin_email', 'auto_generated_password_email', 'email_verified_admin_email' );

			// Membership emails whose saved content option uses a `_message` suffix (the content field id differs from $this->id).
			$message_suffix_emails = array( 'membership_ended_user_email', 'membership_expiring_soon_user_email', 'membership_renewal_reminder_user_email' );

			if ( 'passwordless_login_email' === $option_name ) {
				$email_content = get_option( 'user_registration_' . $option_name . '_content', $class_instance->$default_content() );
			} elseif ( 'prevent_concurrent_login_email' === $option_name ) {
				$email_content = get_option( 'user_registration_prevent_concurrent_login_email_content', $class_instance->$default_content() );
			} elseif ( in_array( $option_name, $pro_content_suffix_emails, true ) ) {
				$email_content = get_option( 'user_registration_pro_' . $option_name . '_content', $class_instance->$default_content() );
			} elseif ( in_array( $option_name, $message_suffix_emails, true ) ) {
				$email_content = get_option( 'user_registration_' . $option_name . '_message', $class_instance->$default_content() );
			} else {
				$email_content = get_option( 'user_registration_' . $option_name, $class_instance->$default_content() );
			}
			/**
			 * Filter to process the smart tags.
			 *
			 * @param string $email_content The email message content.
			 */
			$email_content = apply_filters( 'user_registration_process_smart_tags', $email_content );
			$allowed       = array_merge(
				wp_kses_allowed_html( 'post' ),
				array(
					'style' => array(),
					'head'  => array(),
					'html'  => array(),
					'body'  => array(),
				)
			);
			ur_get_template(
				'email-preview.php',
				array(
					'email_content'  => wp_kses( $email_content, $allowed ),
					'email_template' => sanitize_text_field( $email_template ),
				)
			);
		}
	}

	/**
	 * Side panel content.
	 *
	 * @since 4.0
	 */
	public static function side_panel_content() {

		$is_pro_active = is_plugin_active( 'user-registration-pro/user-registration.php' );
		if ( ! $is_pro_active ) {
			$heading      = esc_html__( 'Upgrade to our Pro version for everything you need for advanced registration form building.', 'user-registration' );
			$pro_features = array(
				esc_html__( '40+ unique addons', 'user-registration' ),
				esc_html__( 'Advanced fields for registration forms', 'user-registration' ),
				esc_html__( 'WooCommerce with billing and shipping fields', 'user-registration' ),
				esc_html__( 'Supports 12 file types for uploads', 'user-registration' ),
				esc_html__( 'Stylish forms with customizer', 'user-registration' ),
				esc_html__( 'Conditional Logic for dynamic forms', 'user-registration' ),
				esc_html__( 'Control content with restrictions', 'user-registration' ),
				esc_html__( 'All form templates included', 'user-registration' ),

			);
		} else {
			$heading      = esc_html__( 'Unlock more functionality with these popular add-ons, loved by users like you.', 'user-registration' );
			$pro_features = array(
				esc_html__( 'Advanced Fields', 'user-registration' ),
				esc_html__( 'WooCommerce', 'user-registration' ),
				esc_html__( 'Customize My Account', 'user-registration' ),
				esc_html__( 'File Upload', 'user-registration' ),
				esc_html__( 'Style Customizer', 'user-registration' ),
				esc_html__( 'Multi-Part', 'user-registration' ),
				esc_html__( 'Email Templates', 'user-registration' ),
				esc_html__( 'Field Visibility', 'user-registration' ),
			);
		}
		$is_theme_style = get_post_meta( absint( $_GET['form_id'] ), 'user_registration_enable_theme_style', true );
		if ( 'default' === $is_theme_style ) {
			$checked    = '';
			$data_theme = 'default';
		} else {
			$checked    = 'checked';
			$data_theme = 'theme';
		}
		$html  = '';
		$html .= '<div class="ur-from-preview-theme-toggle">';
		$html .= '<label class="ur-form-preview-toggle-title">' . esc_html__( 'Apply Theme Style', 'user-registration' ) . '</label>';
		$html .= '<span class="ur-form-preview-toggle-theme-preview">';
		$html .= '<input type="checkbox" class="ur-form-preview-theme-toggle-checkbox input-checkbox " id="ur_toggle_form_preview_theme" ' . $checked . '>';
		$html .= '<span class="slider round"></span>';
		$html .= '</span>';
		$html .= '</div>';
		$html .= '<div class="ur-form-preview-save hidden" id="ur-form-save" data-theme="' . $data_theme . '" data-id="' . absint( $_GET['form_id'] ) . '">';
		$html .= '<img src="' . esc_url( UR()->plugin_url() . '/assets/images/save-frame.svg' ) . '" alt="Save">';
		$html .= '<div class="ur-form-preview-save-title">' . esc_html__( 'Save', 'user-registration' ) . '</div>';
		$html .= '</div>';
		$html .= '<div class="ur-form-preview-pro-features">';
		$html .= '<p class="ur-form-preview-pro-features-title">' . esc_html__( $heading, 'user-registration' ) . '</p>';
		foreach ( $pro_features as $list ) {
			$html .= '<div class="ur-form-preview-sidebar__body--list-item">';

			$html .= '<svg xmlns="http://www.w3.org/2000/svg"  viewBox="0 0 18 18" fill="none">
						<path d="M15 5.25L6.75 13.5L3 9.75" stroke="#4CC741" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>';

			$html .= '<span>';
			$html .= wp_kses_post( $list );
			$html .= '</span>';
			$html .= '</div>';

		}
		if ( ! $is_pro_active ) {
			$html .= '<div class="ur-form-preview-upgrade  id="ur-form-save" data-theme="default" ">';
			$html .= '<img src="' . esc_url( UR()->plugin_url() . '/assets/images/upgrade-icon.svg' ) . '" alt="Save">';
			$html .= '<div class="ur-form-preview-upgrade-title"><a target="_blank" href="' . esc_url( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'ur-membership-create', 'medium' => 'upgrade-link' ) ) ) . '">' . esc_html__( 'Upgrade to Pro', 'user-registration' ) . '</a></div>';
			$html .= '</div>';
		}

		echo $html; // phpcs:ignore

		?>
		<?php
	}
}

new UR_Preview();
