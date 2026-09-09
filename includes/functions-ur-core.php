<?php
/**
 * UserRegistration Functions.
 *
 * General core functions available on both the front-end and admin.
 *
 * @package UserRegistration/Functions
 * @version 1.0.0
 */

use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\MembersOrderRepository;
use WPEverest\URMembership\Admin\Services\MembershipService;
use WPEverest\URMembership\Admin\Services\Stripe\StripeService;

defined( 'ABSPATH' ) || exit;

// Include core functions (available in both admin and frontend).
require UR_ABSPATH . 'includes/functions-ur-page.php';
require UR_ABSPATH . 'includes/functions-ur-account.php';
require UR_ABSPATH . 'includes/functions-ur-deprecated.php';

/**
 * Define a constant if it is not already defined.
 *
 * @param string $name Constant name.
 * @param string $value Value.
 */
function ur_maybe_define_constant( $name, $value ) {
	if ( ! defined( $name ) ) {
		define( $name, $value );
	}
}

if ( ! function_exists( 'ur_utm_allowed_mediums' ) ) {
	/**
	 * Allowed utm_medium values for outbound marketing links.
	 *
	 * @since 5.2.8
	 * @return string[]
	 */
	function ur_utm_allowed_mediums() {
		return array( 'upgrade-link', 'button', 'popup', 'menu-link', 'notice' );
	}
}

if ( ! function_exists( 'ur_utm_url' ) ) {
	/**
	 * Build a marketing URL with standardized UTM parameters.
	 *
	 * Order is always source → medium → campaign → content.
	 * Campaign defaults to UR()->utm_campaign (lite-version / pro-version).
	 *
	 * @since 5.2.8
	 *
	 * @param string $base_url Base URL without UTM params (fragments preserved).
	 * @param array  $args {
	 *     @type string $source   Required. Granular UI location (lowercase-hyphenated).
	 *     @type string $medium   Required. One of ur_utm_allowed_mediums(); falls back to button.
	 *     @type string $campaign Optional. Defaults to UR()->utm_campaign.
	 *     @type string $content  Optional. What was clicked (addon/feature slug, etc.).
	 * }
	 * @return string
	 */
	function ur_utm_url( $base_url, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'source'   => '',
				'medium'   => 'button',
				'campaign' => '',
				'content'  => '',
			)
		);

		$source = sanitize_title( (string) $args['source'] );
		if ( '' === $source ) {
			$source = 'wp-admin';
		}

		$medium = sanitize_title( (string) $args['medium'] );
		if ( ! in_array( $medium, ur_utm_allowed_mediums(), true ) ) {
			$medium = 'button';
		}

		$campaign = (string) $args['campaign'];
		if ( '' === $campaign ) {
			$campaign = ( function_exists( 'UR' ) && UR() ) ? (string) UR()->utm_campaign : 'lite-version';
		}
		$campaign = sanitize_title( $campaign );

		$query = array(
			'utm_source'   => $source,
			'utm_medium'   => $medium,
			'utm_campaign' => $campaign,
		);

		$content = sanitize_title( (string) $args['content'] );
		if ( '' !== $content ) {
			$query['utm_content'] = $content;
		}

		return add_query_arg( $query, esc_url_raw( trim( $base_url ) ) );
	}
}

if ( ! function_exists( 'is_ur_endpoint_url' ) ) {

	/**
	 * Check if an endpoint is showing.
	 *
	 * @param string $endpoint User registration myaccount endpoints.
	 *
	 * @return bool
	 */
	function is_ur_endpoint_url( $endpoint = false ) {
		global $wp;

		$ur_endpoints = UR()->query->get_query_vars();

		if ( false !== $endpoint ) {
			if ( ! isset( $ur_endpoints[ $endpoint ] ) ) {
				return false;
			} else {
				$endpoint_var = $ur_endpoints[ $endpoint ];
			}

			return isset( $wp->query_vars[ $endpoint_var ] );
		} else {
			foreach ( $ur_endpoints as $key => $value ) {
				if ( isset( $wp->query_vars[ $key ] ) ) {
					return true;
				}
			}

			return false;
		}
	}
}

if ( ! function_exists( 'is_ur_account_page' ) ) {

	/**
	 * Returns true when viewing an account page.
	 *
	 * @return bool
	 */
	function is_ur_account_page() {
		/**
		 * Filter hook to modify the result of determining if the current page is an
		 * account page in user registration.
		 *
		 * @param bool $is_account_page The result of determining if the current page is
		 * a user registration account page. Default is false.
		 */
		return is_page( ur_get_page_id( 'myaccount' ) ) || ur_post_content_has_shortcode( 'user_registration_my_account' ) || apply_filters( 'user_registration_is_account_page', false );
	}
}

if ( ! function_exists( 'is_ur_login_page' ) ) {

	/**
	 * Returns true when viewing an login page.
	 *
	 * @return bool
	 */
	function is_ur_login_page() {
		/**
		 * Filter hook to modify the result of determining if the current page is an
		 * login page in user registration.
		 *
		 * @param bool $is_login_page The result of determining if the current page is
		 * a user registration login page. Default is false.
		 */
		return is_page( ur_get_page_id( 'login' ) ) || ur_post_content_has_shortcode( 'user_registration_login' ) || apply_filters( 'user_registration_is_login_page', false );
	}
}

if ( ! function_exists( 'is_ur_edit_account_page' ) ) {

	/**
	 * Check for edit account page.
	 * Returns true when viewing the edit account page.
	 *
	 * @return bool
	 */
	function is_ur_edit_account_page() {
		global $wp;

		return ( is_ur_account_page() && isset( $wp->query_vars['edit-password'] ) );
	}
}

if ( ! function_exists( 'is_ur_lost_password_page' ) ) {

	/**
	 * Returns true when viewing the lost password page.
	 *
	 * @return bool
	 */
	function is_ur_lost_password_page() {
		global $wp;

		$lost_password_page_id = get_option( 'user_registration_lost_password_page_id', false );

		return ( is_ur_account_page() && isset( $wp->query_vars['ur-lost-password'] ) ) || is_page( $lost_password_page_id );
	}
}

/**
 * Clean variables using sanitize_text_field. Arrays are cleaned recursively.
 * Non-scalar values are ignored.
 *
 * @param string|array $var Variable.
 *
 * @return string|array
 */
function ur_clean( $var ) {
	if ( is_array( $var ) ) {
		return array_map( 'ur_clean', $var );
	} else {
		return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
	}
}

/**
 * Sanitize a string destined to be a tooltip.
 *
 * @param string $var Value to sanitize.
 *
 * @return string
 * @since  1.0.0  Tooltips are encoded with htmlspecialchars to prevent XSS. Should not be used in conjunction with esc_attr()
 */
function ur_sanitize_tooltip( $var ) {
	return htmlspecialchars(
		wp_kses(
			html_entity_decode( $var ),
			array(
				'br'     => array(),
				'em'     => array(),
				'strong' => array(),
				'small'  => array(),
				'span'   => array(),
				'ul'     => array(),
				'li'     => array(),
				'ol'     => array(),
				'p'      => array(),
			)
		)
	);
}

/**
 * Format dimensions for display.
 *
 * @param array $dimensions Array of dimensions.
 * @param array $unit Unit, defaults to 'px'.
 *
 * @return string
 * @since  1.7.0
 */
function ur_sanitize_dimension_unit( $dimensions = array(), $unit = 'px' ) {
	return ur_array_to_string( ur_suffix_array( $dimensions, $unit ) );
}

/**
 * Add a suffix into an array.
 *
 * @param array  $array Raw array data.
 * @param string $suffix Suffix to be added.
 *
 * @return array Modified array with suffix added.
 * @since  1.7.0
 */
function ur_suffix_array( $array = array(), $suffix = '' ) {
	return preg_filter( '/$/', $suffix, $array );
}

/**
 * Implode an array into a string by $glue and remove empty values.
 *
 * @param array  $array Array to convert.
 * @param string $glue Glue, defaults to ' '.
 *
 * @return string
 * @since  1.7.0
 */
function ur_array_to_string( $array = array(), $glue = ' ' ) {
	return is_string( $array ) ? $array : implode( $glue, array_filter( $array ) );
}

/**
 * Explode a string into an array by $delimiter and remove empty values.
 *
 * @param string $string String to convert.
 * @param string $delimiter Delimiter, defaults to ','.
 *
 * @return array
 * @since  1.7.0
 */
function ur_string_to_array( $string, $delimiter = ',' ) {
	return is_array( $string ) ? $string : array_filter( explode( $delimiter, $string ) );
}

/**
 * Converts a string (e.g. 'yes' or 'no') to a bool.
 *
 * @param string $string String to convert.
 *
 * @return bool
 */
function ur_string_to_bool( $string ) {
	return is_bool( $string ) ? $string : ( ( 'yes' === $string || 'on' === $string || 1 === $string || 'true' === $string || '1' === $string || 'today' === $string || 'range' === $string ) ? true : ( null === $string ? '0' : false ) );
}

/**
 * Converts a bool to a 'yes' or 'no'.
 *
 * @param bool $bool String to convert.
 *
 * @return string
 */
function ur_bool_to_string( $bool ) {
	if ( ! is_bool( $bool ) ) {
		$bool = ur_string_to_bool( $bool );
	}

	return true === $bool ? 'yes' : 'no';
}

/**
 * Get other templates (e.g. my account) passing attributes and including the file.
 *
 * @param string $template_name Template Name.
 * @param array  $args Extra arguments(default: array()).
 * @param string $template_path Path of template provided (default: '').
 * @param string $default_path Default path of template provided(default: '').
 */
function ur_get_template( $template_name, $args = array(), $template_path = '', $default_path = '' ) {
	// NOTE: extract() is intentionally deferred to the isolated closure below to prevent
	// $args from overriding $template_name or other path-related variables.

	$located = ur_locate_template( $template_name, $template_path, $default_path );

	// Snapshot the trusted resolved path BEFORE the filter can modify it.
	$trusted_located = realpath( $located );

	/** Allow 3rd party plugin filter template file from their plugin.
	 *
	 * @param string $located Template locate.
	 * @param string $template_name Template Name.
	 * @param array $args Extra arguments(default: array()).
	 * @param string $template_path Path of template provided (default: '').
	 * @param string $default_path Default path of template provided(default: '').
	 */
	$located = apply_filters( 'ur_get_template', $located, $template_name, $args, $template_path, $default_path );

	// Use realpath() to resolve symlinks and '..' components before validation.
	$real_located = realpath( $located );

	// Derive the plugin's templates/ root from the pre-filter trusted path.
	// This works for every plugin/addon without needing any *_ABSPATH constant.
	$templates_marker = DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR;
	$templates_pos    = ( false !== $trusted_located ) ? strpos( $trusted_located, $templates_marker ) : false;
	$real_plugin_path = ( false !== $templates_pos )
		? realpath( substr( $trusted_located, 0, $templates_pos + strlen( $templates_marker ) - 1 ) )
		: false;

	// Theme override directories (child + parent) — only if /user-registration/ subfolder exists.
	$real_child_path  = realpath( get_stylesheet_directory() . '/user-registration' );
	$real_parent_path = realpath( get_template_directory() . '/user-registration' );

	$path_allowed = false;

	if ( false !== $real_located && false !== $real_plugin_path && 0 === strpos( $real_located, $real_plugin_path . DIRECTORY_SEPARATOR ) ) {
		$path_allowed = true;
	}
	if ( ! $path_allowed && false !== $real_located && false !== $real_child_path && 0 === strpos( $real_located, $real_child_path . DIRECTORY_SEPARATOR ) ) {
		$path_allowed = true;
	}
	if ( ! $path_allowed && false !== $real_located && false !== $real_parent_path && 0 === strpos( $real_located, $real_parent_path . DIRECTORY_SEPARATOR ) ) {
		$path_allowed = true;
	}

	if ( ! $path_allowed ) {
		_doing_it_wrong(
			__FUNCTION__,
			esc_html__( 'Template path is not allowed.', 'user-registration' ),
			'1.0'
		);
		return;
	}

	if ( ! file_exists( $located ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf( '<code>%s</code> does not exist.', esc_html( $located ) ),
			'1.0'
		);
		return;
	}

	ob_start();

	/**
	 * Executes an action before including a template part.
	 *
	 * @param string $template_name Name of the template part.
	 * @param string $template_path Path to the template part.
	 * @param string $located       Path to the located template file.
	 * @param array  $args          Additional arguments passed to the template part.
	 */
	do_action( 'user_registration_before_template_part', $template_name, $template_path, $located, $args );

	// Isolated closure: extract() runs inside a separate scope so it cannot override
	// $located or any other path-related variable in the outer function.
	// EXTR_SKIP prevents the closure's own parameters from being overridden.
	( function ( $ur_template_file, $ur_template_args ) {
		if ( ! empty( $ur_template_args ) && is_array( $ur_template_args ) ) {
			extract( $ur_template_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		}
		include $ur_template_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
	} )( $located, $args );

	/**
	 * Executes an action after including a template part.
	 *
	 * @param string $template_name Name of the template part.
	 * @param string $template_path Path to the template part.
	 * @param string $located       Path to the located template file.
	 * @param array  $args          Additional arguments passed to the template part.
	 */
	do_action( 'user_registration_after_template_part', $template_name, $template_path, $located, $args );

	$template_content = ob_get_clean();

	/**
	 * Filter hook to process the smart tags in the template content.
	 *
	 * @param string $template_content The template content.
	 */
	$template_content = apply_filters( 'user_registration_process_smart_tags', $template_content, array(), array() );

	echo $template_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Locate a template and return the path for inclusion.
 *
 * This is the load order:
 *
 *        yourtheme        /    $template_path    /    $template_name
 *        yourtheme        /    $template_name
 *        $default_path    /    $template_name
 *
 * @param string $template_name Template Name.
 * @param string $template_path Path of template provided (default: '').
 * @param string $default_path Default path of template provided(default: '').
 *
 * @return string
 */
function ur_locate_template( $template_name, $template_path = '', $default_path = '' ) {
	if ( ! $template_path ) {
		$template_path = UR()->template_path();
	}

	if ( ! $default_path ) {
		$default_path = UR()->plugin_path() . '/templates/';
	}

	// Look within passed path within the theme - this is priority.
	$template = locate_template(
		array(
			trailingslashit( $template_path ) . $template_name,
			$template_name,
		)
	);

	// Get default template.
	if ( ! $template || UR_TEMPLATE_DEBUG_MODE ) {
		$template = $default_path . $template_name;
	}

	/**
	 * Filters the located template file path before including it.
	 *
	 * @param string $template The located template file path.
	 * @param string $template_name The name of the template file.
	 * @param string $template_path The path to the template file.
	 */
	return apply_filters( 'user_registration_locate_template', $template, $template_name, $template_path );
}

/**
 * Display a UserRegistration help tip.
 *
 * @param string $tip Help tip text.
 * @param bool   $allow_html Allow sanitized HTML if true or escape.
 * @param string $classname Classname.
 *
 * @return string
 */
function ur_help_tip( $tip, $allow_html = false, $classname = 'user-registration-help-tip' ) {
	if ( $allow_html ) {
		$tip = ur_sanitize_tooltip( $tip );
	} else {
		$tip = esc_attr( $tip );
	}

	return sprintf( '<span class="%s" data-tip="%s"></span>', $classname, $tip );
}

function ur_render_premium_feature_gate_template( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'template_id' => 'ur-pro-feature',
			'utm_source'  => 'ur-membership-create',
			'plan_gated'  => false,
			'title'       => esc_html__( 'You have run into a premium feature, please upgrade to URM Pro', 'user-registration' ),
			'button_text' => esc_html__( 'Upgrade to Pro', 'user-registration' ),
		)
	);

	// Render for free installs, or when a higher plan is required even though Pro is active.
	if ( UR_PRO_ACTIVE && empty( $args['plan_gated'] ) ) {
		return;
	}

	if ( empty( $args['upgrade_url'] ) ) {
		$args['upgrade_url'] = ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => $args['utm_source'], 'medium' => 'upgrade-link' ) );
	}

	static $rendered_templates = array();
	if ( in_array( $args['template_id'], $rendered_templates, true ) ) {
		return;
	}
	$rendered_templates[] = $args['template_id'];
	?>
	<template id="<?php echo esc_attr( $args['template_id'] ); ?>">
		<div class="ur-feature">
			<div class="ur-feature__title">
				<?php echo esc_html( $args['title'] ); ?>
			</div>
			<a class="ur-feature__btn" target="_blank" href="<?php echo esc_url( $args['upgrade_url'] ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"></path><path d="M5 21h14"></path></svg>
				<?php echo esc_html( $args['button_text'] ); ?>
			</a>
		</div>
	</template>
	<?php
}

function ur_render_premium_feature_gate( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'template_id'     => 'ur-pro-feature',
			'utm_source'      => 'ur-membership-create',
			'render_template' => true,
			'plan_gated'      => false,
			'title'           => esc_html__( 'You have run into a premium feature, please upgrade to URM Pro', 'user-registration' ),
			'button_text'     => esc_html__( 'Upgrade to Pro', 'user-registration' ),
		)
	);

	// Render for free installs, or when a higher plan is required even though Pro is active.
	if ( UR_PRO_ACTIVE && empty( $args['plan_gated'] ) ) {
		return;
	}

	$args['upgrade_url'] = ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => $args['utm_source'], 'medium' => 'upgrade-link' ) );

	if ( ! empty( $args['render_template'] ) ) {
		ur_render_premium_feature_gate_template( $args );
	}

	$gate_attrs = 'data-feature-gate="tooltip" data-gate-placement="right" data-gate-interactive="true" data-gate-content="' . esc_attr( $args['template_id'] ) . '"';
	?>
	<span class="ur-premium-pro" <?php echo $gate_attrs; ?>><img src="<?php echo esc_url( UR()->plugin_url() . '/assets/images/icons/ur-pro-icon.png' ); ?>" alt="" /></span>
	<?php
}

/**
 * Checks whether the content passed contains a specific short code.
 *
 * @param string $tag Shortcode tag to check.
 *
 * @return bool
 */
function ur_post_content_has_shortcode( $tag = '' ) {
	global $post;
	$new_shortcode = '';
	$wp_version    = '5.0';
	if ( version_compare( $GLOBALS['wp_version'], $wp_version, '>=' ) ) {
		if ( is_object( $post ) && ! empty( $post->post_content ) ) {
			$blocks = parse_blocks( $post->post_content );
			foreach ( $blocks as $block ) {

				if ( ( 'core/shortcode' === $block['blockName'] || 'core/paragraph' === $block['blockName'] ) && isset( $block['innerHTML'] ) ) {
					$new_shortcode = ( 'core/shortcode' === $block['blockName'] ) ? $block['innerHTML'] : wp_strip_all_tags( $block['innerHTML'] );
				} elseif ( 'user-registration/form-selector' === $block['blockName'] && isset( $block['attrs']['shortcode'] ) ) {
					$new_shortcode = '[' . $block['attrs']['shortcode'] . ']';
				}
			}
		}

		return ( is_singular() || is_front_page() ) && is_a( $post, 'WP_Post' ) && has_shortcode( $new_shortcode, $tag );
	} else {
		return ( is_singular() || is_front_page() ) && is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, $tag );
	}
}

if ( ! function_exists( 'ur_is_login_form_markup_rendered' ) ) {

	/**
	 *
	 * Detects the actual rendered HTML (id="ur-frontend-form" and form class user-registration-form-login).
	 *
	 * @param string $content Optional. HTML to check. If empty, uses current post content.
	 * @return bool True if the login form markup is present, false otherwise.
	 */
	function ur_is_login_form_markup_rendered( $content = '' ) {
		if ( '' !== $content ) {
			$html = $content;
		} else {
			if ( ! is_singular() && ! is_front_page() ) {
				return false;
			}
			$post = get_post();
			if ( ! $post || ! $post->post_content ) {
				return false;
			}
			$html = apply_filters( 'the_content', $post->post_content );
		}

		$has_container  = ( false !== strpos( $html, 'id="ur-frontend-form"' ) );
		$has_login_form = ( false !== strpos( $html, 'user-registration-form-login' ) );

		return $has_container && $has_login_form;
	}
}

/**
 * Wrapper for ur_doing_it_wrong.
 *
 * @param string $function Callback function name.
 * @param string $message Message to display.
 * @param string $version Version of the plugin.
 *
 * @since  1.0.0
 */
function ur_doing_it_wrong( $function, $message, $version ) {
	$message .= ' Backtrace: ' . wp_debug_backtrace_summary(); // PHPCS:Ignore WordPress.PHP.DevelopmentFunctions.error_log_backtrace_summary -- Backtrace is added to the message for better debugging and error tracking.

	if ( defined( 'DOING_AJAX' ) ) {
		/**
		 * The 'doing_it_wrong_run' action is triggered when the function is called incorrectly.
		 *
		 * @param string $function The function that was called incorrectly.
		 * @param string $message Error message providing details about the incorrect usage.
		 * @param string $version The version when the incorrect usage was introduced.
		 */
		do_action( 'doing_it_wrong_run', $function, $message, $version );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( "{$function} was called incorrectly. {$message}. This message was added in version {$version}." );
	} else {
		_doing_it_wrong( esc_html( $function ), esc_html( $message ), esc_html( $version ) );
	}
}

/**
 * Set a cookie - wrapper for setcookie using WP constants.
 *
 * @param string  $name Name of the cookie being set.
 * @param string  $value Value of the cookie.
 * @param integer $expire Expiry of the cookie.
 * @param string  $secure Whether the cookie should be served only over https.
 */
function ur_setcookie( $name, $value, $expire = 0, $secure = null, $httponly = true ) {
	if ( null === $secure ) {
		$secure = is_ssl();
	}
	if ( ! headers_sent() ) {
		setcookie( $name, $value, $expire, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, $httponly );
	} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		headers_sent( $file, $line );
		trigger_error( "{$name} cookie cannot be set - headers already sent by {$file} on line {$line}", E_USER_NOTICE ); //phpcs:ignore.
	}
}

/**
 * Read in UserRegistration headers when reading plugin headers.
 *
 * @param array $headers header.
 *
 * @return array $headers
 * @since  1.1.0
 */
function ur_enable_ur_plugin_headers( $headers ) {
	if ( ! class_exists( 'UR_Plugin_Updates', false ) ) {
		include_once __DIR__ . '/admin/updater/class-ur-plugin-updates.php';
	}

	$headers['URRequires'] = UR_Plugin_Updates::VERSION_REQUIRED_HEADER;
	$headers['URTested']   = UR_Plugin_Updates::VERSION_TESTED_HEADER;

	return $headers;
}

add_filter( 'extra_plugin_headers', 'ur_enable_ur_plugin_headers' );

/**
 * Set field type for all registrered field keys
 *
 * @param string $field_key field's field key.
 *
 * @return string $field_type
 */
function ur_get_field_type( $field_key ) {
	$fields = ur_get_registered_form_fields();
	if ( ur_check_module_activation( 'coupon' ) ) {
		$fields[] = 'coupon';
	}

	$field_type = 'text';

	if ( in_array( $field_key, $fields ) ) {

		switch ( $field_key ) {

			case 'user_email':
			case 'user_confirm_email':
			case 'email':
				$field_type = 'email';
				break;
			case 'user_confirm_password':
			case 'password':
			case 'user_pass':
				$field_type = 'password';
				break;
			case 'user_login':
			case 'nickname':
			case 'first_name':
			case 'last_name':
			case 'display_name':
			case 'text':
				$field_type = 'text';
				break;
			case 'user_url':
				$field_type = 'url';
				break;
			case 'description':
			case 'textarea':
				$field_type = 'textarea';
				break;
			case 'select':
			case 'country':
				$field_type = 'select';
				break;
			case 'file':
				$field_type = 'file';
				break;
			case 'privacy_policy':
			case 'mailchimp':
			case 'mailerlite':
			case 'checkbox':
				$field_type = 'checkbox';
				break;
			case 'number':
				$field_type = 'number';
				break;
			case 'date':
				$field_type = 'date';
				break;
			case 'radio':
				$field_type = 'radio';
				break;
			case 'coupon':
				$field_type = 'coupon';
				break;
		}
	}

	/**
	 * Filters the field keys before rendering or processing.
	 *
	 * @param string $field_type The type of the user registration field.
	 * @param string $field_key The key identifying the specific field.
	 */
	return apply_filters( 'user_registration_field_keys', $field_type, $field_key );
}

/**
 * Get user table fields.
 *
 * @return array
 */
function ur_get_user_table_fields() {
	/**
	 * Filters the user table fields before rendering or processing.
	 *
	 * @param array $user_table_fields An array of user table fields to be displayed
	 * or processed during user registration.
	 */
	return apply_filters(
		'user_registration_user_table_fields',
		array(
			'user_email',
			'user_pass',
			'user_login',
			'user_url',
			'display_name',
		)
	);
}

/**
 * Get required fields.
 *
 * @return array
 */
function ur_get_required_fields() {
	/**
	 * Filters the list of required form fields during user registration.
	 *
	 * @param array $required_form_fields An array of user fields that are required.
	 */
	return apply_filters(
		'user_registration_required_form_fields',
		array(
			'user_email',
			'user_pass',
		)
	);
}

/**
 * Get one time draggable fields fields.
 *
 * @return array
 */
function ur_get_one_time_draggable_fields() {
	$form_fields = ur_get_user_field_only();

	/**
	 * Filters the list of one-time draggable form fields during user registration.
	 *
	 * @param array $form_fields An array of user fields to be used as one-time draggable form fields.
	 */
	return apply_filters( 'user_registration_one_time_draggable_form_fields', $form_fields );
}

/**
 * Check if membership is available for the form builder (at least one group or one active membership).
 *
 * @return bool
 */
function ur_has_membership_available() {
	if ( ! ur_check_module_activation( 'membership' ) ) {
		return false;
	}
	$has_groups = false;
	if ( class_exists( 'WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository' ) ) {
		$repository = new \WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository();
		$groups     = $repository->get_all_membership_groups();
		$has_groups = ! empty( $groups );
	}
	$has_memberships = false;
	if ( class_exists( 'WPEverest\URMembership\Admin\Services\MembershipService' ) ) {
		$service         = new \WPEverest\URMembership\Admin\Services\MembershipService();
		$memberships     = $service->list_active_memberships();
		$has_memberships = ! empty( $memberships );
	}
	return $has_groups || $has_memberships;
}

/**
 * Get fields excluding in profile tab
 *
 * @return array
 */
function ur_exclude_profile_details_fields() {

	$fields_to_exclude = array(
		'user_pass',
		'user_confirm_password',
		'user_confirm_email',
		'invite_code',
		'learndash_course',
	);

	// Check if the my account page contains [user_registration_my_account] shortcode.
	if ( ur_post_content_has_shortcode( 'user_registration_my_account' ) || ur_post_content_has_shortcode( 'user_registration_edit_profile' ) ) {
		// Push profile_picture field to fields_to_exclude array.
		array_push( $fields_to_exclude, 'profile_picture' );
	}

	/**
	 * Filters the list of profile fields to be excluded during user registration.
	 *
	 * @param array $fields_to_exclude An array of profile fields to be excluded.
	 */
	return apply_filters(
		'user_registration_exclude_profile_fields',
		$fields_to_exclude
	);
}

/**
 * Get readonly fields in profile tab
 *
 * @return array
 */
function ur_readonly_profile_details_fields() {
	/**
	 * Filters the list of readonly profile fields during user registration.
	 *
	 * @param array $readonly_profile_fields An associative array where keys are the
	 *                                       profile fields to be marked as readonly,
	 *                                       and values are arrays containing optional
	 *                                       messages or values associated with each field.
	 */
	return apply_filters(
		'user_registration_readonly_profile_fields',
		array(
			'user_login'            => array(
				'message' => __( 'Username can not be changed.', 'user-registration' ),
			),
			'user_pass'             => array(
				'value'   => 'password',
				'message' => __( 'Passowrd can not be changed.', 'user-registration' ),
			),
			'user_confirm_password' => array(
				'value'   => 'password',
				'message' => __( 'Confirm password can not be changed.', 'user-registration' ),
			),
			'user_confirm_email'    => array(
				'message' => __( 'Confirm email can not be changed.', 'user-registration' ),
			),
		)
	);
}

/**
 * Get profile detail fields.
 *
 * @return void
 * @deprecated 1.4.1
 */
function ur_get_account_details_fields() {
	ur_deprecated_function( 'ur_get_account_details_fields', '1.4.1', 'ur_exclude_profile_details_fields' );
}

/**
 * Get all fields appearing in profile tab.
 *
 * @return array
 */
function ur_get_user_profile_field_only() {
	$user_fields = array_diff( ur_get_registered_form_fields(), ur_exclude_profile_details_fields() );

	/**
	 * Filters the list of user profile fields during user registration.
	 *
	 * @param array $user_fields An array of user profile fields to be used during user registration.
	 */
	return apply_filters( 'user_registration_user_profile_field_only', $user_fields );
}

/**
 * All fields to update without adding prefix.
 *
 * @return array
 */
function ur_get_fields_without_prefix() {
	$fields = ur_get_user_field_only();

	/**
	 * Filters the list of user registration fields without the field prefix.
	 *
	 * @param array $fields An array of user registration fields without the field prefix.
	 */
	return apply_filters( 'user_registration_fields_without_prefix', $fields );
}

/**
 * Get all default fields by WordPress.
 *
 * @return array
 */
function ur_get_user_field_only() {
	/**
	 * Filters the list of user form fields during user registration.
	 *
	 * @param array $user_form_fields An array of user form fields to be used during user registration.
	 */
	return apply_filters(
		'user_registration_user_form_fields',
		array(
			'user_email',
			'user_confirm_email',
			'user_pass',
			'user_confirm_password',
			'user_login',
			'nickname',
			'first_name',
			'last_name',
			'user_url',
			'display_name',
			'description',
		)
	);
}

/**
 * Get all extra form fields
 *
 * @return array
 */
function ur_get_other_form_fields() {
	$registered  = ur_get_registered_form_fields();
	$user_fields = ur_get_user_field_only();
	$result      = array_diff( $registered, $user_fields );

	/**
	 * Filters the list of other form fields during user registration.
	 *
	 * @param mixed $result The result of processing other form fields during user registration.
	 */
	return apply_filters( 'user_registration_other_form_fields', $result );
}

/**
 * All default fields storing in usermeta table
 *
 * @return mixed|array
 */
function ur_get_registered_user_meta_fields() {
	/**
	 * Filters the list of user meta fields for a registered user during user registration.
	 *
	 * @param array $registered_user_meta_fields An array of user meta fields associated with a registered user.
	 */
	return apply_filters(
		'user_registration_registered_user_meta_fields',
		array(
			'nickname',
			'first_name',
			'last_name',
			'description',
		)
	);
}

if ( ! function_exists( 'ur_get_field_name_with_prefix_usermeta' ) ) {
	/**
	 * Returns user registration meta fields with prefix before registration.
	 *
	 * @param string $field_name Field name.
	 *
	 * @return string
	 */
	function ur_get_field_name_with_prefix_usermeta( $field_name ) {
		$default_fields = array_merge_recursive( ur_get_user_table_fields(), ur_get_registered_user_meta_fields() );
		if ( ! in_array( $field_name, $default_fields ) ) {
			$field_name = 'user_registration_' . $field_name;
		}

		return $field_name;
	}
}

/**
 * All registered form fields
 *
 * @return mixed|array
 */
function ur_get_registered_form_fields() {
	/**
	 * Filters the list of form fields for a registered user during user registration.
	 *
	 * @param array $registered_form_fields An array of form fields associated with a registered user during registration.
	 */
	return apply_filters(
		'user_registration_registered_form_fields',
		array(
			'user_email',
			'user_confirm_email',
			'user_pass',
			'user_confirm_password',
			'user_login',
			'nickname',
			'first_name',
			'last_name',
			'user_url',
			'display_name',
			'description',
			'text',
			'password',
			'email',
			'select',
			'country',
			'textarea',
			'number',
			'date',
			'checkbox',
			'privacy_policy',
			'radio',
		)
	);
}

/**
 * All registered form fields with default labels
 *
 * @return mixed|array
 */
function ur_get_registered_form_fields_with_default_labels() {
	/**
	 * Filters the list of form fields for a registered user with default labels during user registration.
	 *
	 * @param array $registered_form_fields_with_labels An associative array where keys
	 *                                                  are form field keys, and values are
	 *                                                  the corresponding default labels.
	 */
	return apply_filters(
		'user_registration_registered_form_fields_with_default_labels',
		array(
			'user_email'            => __( 'User Email', 'user-registration' ),
			'user_confirm_email'    => __( 'User Confirm Email', 'user-registration' ),
			'user_pass'             => __( 'User Pass', 'user-registration' ),
			'user_confirm_password' => __( 'User Confirm Password', 'user-registration' ),
			'user_login'            => __( 'User Name', 'user-registration' ),
			'nickname'              => __( 'Nickname', 'user-registration' ),
			'first_name'            => __( 'First Name', 'user-registration' ),
			'last_name'             => __( 'Last Name', 'user-registration' ),
			'user_url'              => __( 'User URL', 'user-registration' ),
			'display_name'          => __( 'Display Name', 'user-registration' ),
			'description'           => __( 'Description', 'user-registration' ),
			'text'                  => __( 'Text', 'user-registration' ),
			'password'              => __( 'Password', 'user-registration' ),
			'email'                 => __( 'Secondary Email', 'user-registration' ),
			'select'                => __( 'Select', 'user-registration' ),
			'country'               => __( 'Country', 'user-registration' ),
			'textarea'              => __( 'Textarea', 'user-registration' ),
			'number'                => __( 'Number', 'user-registration' ),
			'date'                  => __( 'Date', 'user-registration' ),
			'checkbox'              => __( 'Checkbox', 'user-registration' ),
			'privacy_policy'        => __( 'Privacy Policy', 'user-registration' ),
			'radio'                 => __( 'Radio', 'user-registration' ),
			'hidden'                => __( 'Hidden', 'user-registration' ),
		)
	);
}

/**
 * General settings for each fields
 *
 * @param string $id id for each field.
 *
 * @return mixed|array
 */
function ur_get_general_settings( $id ) {

	$general_settings = array(
		'label'       => array(
			'setting_id'  => 'label',
			'type'        => 'text',
			'label'       => __( 'Label', 'user-registration' ),
			'name'        => 'ur_general_setting[label]',
			'placeholder' => __( 'Label', 'user-registration' ),
			'required'    => true,
			'tip'         => __( 'Text shown as the field\’s title. You can hide it using the hide label settings below if you prefer a clean layout.', 'user-registration' ),
		),
		'description' => array(
			'setting_id'  => 'description',
			'type'        => 'textarea',
			'label'       => __( 'Description', 'user-registration' ),
			'name'        => 'ur_general_setting[description]',
			'placeholder' => __( 'Description', 'user-registration' ),
			'required'    => true,
			'tip'         => __( 'Optional helper text that appears under the label to guide users.', 'user-registration' ),
		),

		'placeholder' => array(
			'setting_id'  => 'placeholder',
			'type'        => 'text',
			'label'       => __( 'Placeholder', 'user-registration' ),
			'name'        => 'ur_general_setting[placeholder]',
			'placeholder' => __( 'Placeholder', 'user-registration' ),
			'required'    => true,
			'tip'         => __( 'Text shown inside the field until the user enters a value.', 'user-registration' ),
		),
		'required'    => array(
			'setting_id'  => 'required',
			'type'        => 'toggle',
			'label'       => __( 'Required', 'user-registration' ),
			'name'        => 'ur_general_setting[required]',
			'placeholder' => '',
			'required'    => true,
			'default'     => 'false',
			'tip'         => __( 'Make this field required, so the form can’t be submitted without it.', 'user-registration' ),
		),
		'hide_label'  => array(
			'setting_id'  => 'hide-label',
			'type'        => 'toggle',
			'label'       => __( 'Hide Label', 'user-registration' ),
			'name'        => 'ur_general_setting[hide_label]',
			'placeholder' => '',
			'required'    => true,
			'default'     => 'false',
			'tip'         => __( 'Hide the title of the field, keeping your form cleaner and simpler.', 'user-registration' ),
		),
		'field_name'  => array(
			'setting_id'  => 'field-name',
			'type'        => 'text',
			'label'       => __( 'Field Name', 'user-registration' ),
			'name'        => 'ur_general_setting[field_name]',
			'placeholder' => __( 'Field Name', 'user-registration' ),
			'required'    => true,
			'tip'         => __( 'Unique key for the field.', 'user-registration' ),
		),
	);
	/**
	 * Filters the list of form field types to exclude placeholders.
	 *
	 * @param array $exclude_placeholder_fields An array of form field types to exclude placeholders.
	 */
	$exclude_placeholder = apply_filters(
		'user_registration_exclude_placeholder',
		array(
			'checkbox',
			'privacy_policy',
			'radio',
			'file',
			'mailchimp',
			'hidden',
			'signature',
		)
	);
	$strip_id            = str_replace( 'user_registration_', '', $id );

	if ( in_array( $strip_id, $exclude_placeholder, true ) ) {
		unset( $general_settings['placeholder'] );
	}

	$choices_fields = array( 'radio', 'select', 'checkbox' );

	if ( in_array( $strip_id, $choices_fields, true ) ) {

		$settings['options'] = array(
			'setting_id'  => 'options',
			'type'        => 'checkbox' === $strip_id ? 'checkbox' : 'radio',
			'label'       => __( 'Options', 'user-registration' ),
			'name'        => 'ur_general_setting[options]',
			'placeholder' => '',
			'required'    => true,
			'options'     => array(
				__( 'First Choice', 'user-registration' ),
				__( 'Second Choice', 'user-registration' ),
				__( 'Third Choice', 'user-registration' ),
			),
		);

		$general_settings = ur_insert_after_helper( $general_settings, $settings, 'field_name' );
	}
	if ( 'privacy_policy' === $strip_id || 'user_confirm_email' === $strip_id || 'user_confirm_password' === $strip_id || in_array( $strip_id, ur_get_required_fields() ) ) {
		$general_settings['required'] = array(
			'setting_id'  => '',
			'type'        => 'hidden',
			'label'       => '',
			'name'        => 'ur_general_setting[required]',
			'placeholder' => '',
			'default'     => true,
			'required'    => true,
		);
	}

	/**
	 * Filters the general settings for a specific field type during user registration.
	 *
	 * @param array $general_settings An array of general settings/options for a specific
	 *                                field type during user registration.
	 * @param string $id The identifier for the specific field type.
	 */
	return apply_filters( 'user_registration_field_options_general_settings', $general_settings, $id );
}

/**
 * Insert in between the indexes in multidimensional array.
 *
 * @param array  $items An array of items.
 * @param array  $new_items New items to insert inbetween.
 * @param string $after Index to insert after.
 *
 * @return array              Ordered array of items.
 * @since  1.5.7
 */
function ur_insert_after_helper( $items, $new_items, $after ) {

	// Search for the item position and +1 since is after the selected item key.
	$position = array_search( $after, array_keys( $items ), true ) + 1;

	// Insert the new item.
	$return_items  = array_slice( $items, 0, $position, true );
	$return_items += $new_items;
	$return_items += array_slice( $items, $position, count( $items ) - $position, true );

	return $return_items;
}

/**
 * Load form field class.
 *
 * @param string $class_key Class Key.
 */
function ur_load_form_field_class( $class_key ) {
	$exploded_class = explode( '_', $class_key );
	$class_path     = UR_FORM_PATH . 'class-ur-' . join( '-', array_map( 'strtolower', $exploded_class ) ) . '.php';
	$class_name     = 'UR_Form_Field_' . join( '_', array_map( 'ucwords', $exploded_class ) );
	/**
	 * Filter the path of the form field class file and class name before loading.
	 *
	 * Dynamic portion of hook name, $class_key.
	 *
	 * @param string $class_path The path to the form field class file.
	 * @param string $class_key The key identifying the form field class.
	 */
	$class_path = apply_filters( 'user_registration_form_field_' . $class_key . '_path', $class_path );
	/* Backward Compat since 1.4.0 */
	if ( null != $class_path && file_exists( $class_path ) ) {
		$class_name = 'UR_' . join( '_', array_map( 'ucwords', $exploded_class ) );
		if ( ! class_exists( $class_name ) ) {
			include_once $class_path;
		}
	}

	/* Backward compat end*/

	return $class_name;
}

/**
 * List of all roles
 *
 * @return array $all_roles
 */
function ur_get_default_admin_roles() {
	global $wp_roles;

	if ( ! class_exists( 'WP_Roles' ) ) {
		return;
	}

	if ( ! isset( $wp_roles ) ) {
		$wp_roles = new WP_Roles(); // @codingStandardsIgnoreLine
	}

	$roles     = isset( $wp_roles->roles ) ? $wp_roles->roles : array();
	$all_roles = array();

	foreach ( $roles as $role_key => $role ) {
		$all_roles[ $role_key ] = $role['name'];
	}

	/**
	 * Filters the default user roles available.
	 *
	 * @param array $all_roles An array of all available user roles.
	 */
	return apply_filters( 'user_registration_user_default_roles', $all_roles );
}


/**
 * Random number generated by time()
 *
 * @return int
 */
function ur_get_random_number() {
	return time();
}

/**
 * General Form settings
 *
 * @param int $form_id Form ID.
 *
 * @return array Form settings.
 * @since 1.0.1
 */
function ur_admin_form_settings_fields( $form_id ) {

	$all_roles = ur_get_default_admin_roles();

	$ur_captchas         = ur_get_captcha_integrations();
	$ur_enabled_captchas = array(
		'' => __( 'Select Enabled Captcha', 'user-registration' ),
	);
	foreach ( $ur_captchas as $key => $value ) {
		if ( get_option( 'user_registration_captcha_setting_recaptcha_enable_' . $key, false ) ) {
			$ur_enabled_captchas[ $key ] = $value;
		}
	}
	$redirect_after_registration_default = ur_get_default_redirect_after_registration( $form_id );
	$arguments                           = array(
		'form_id'      => $form_id,
		'setting_data' => array(
			array(
				'type'              => 'select',
				'label'             => __( 'Form Style', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_template',
				'class'             => array( 'ur-enhanced-select' ),
				'input_class'       => array(),
				'options'           => array(
					'Default'      => __( 'Default', 'user-registration' ),
					'Bordered'     => __( 'Bordered', 'user-registration' ),
					'Flat'         => __( 'Flat', 'user-registration' ),
					'Rounded'      => __( 'Rounded', 'user-registration' ),
					'Rounded Edge' => __( 'Rounded Edge', 'user-registration' ),
				),
				'custom_attributes' => array(),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_template', ucwords( str_replace( '_', ' ', get_option( 'user_registration_form_template', 'default' ) ) ) ),
				'tip'               => __( 'Choose how your registration form looks.', 'user-registration' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'toggle',
				'label'             => __( 'Show Form Title & Description', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_enable_form_title_description',
				'class'             => array(),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'default'           => ur_string_to_bool( ur_get_single_post_meta( $form_id, 'user_registration_enable_form_title_description', false ) ),
				'tip'               => __( 'Turn this on to display the form’s title and description above the form.', 'user-registration' ),
				'default_value'     => false,
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'text',
				'label'             => __( 'Form Title', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_title',
				'class'             => array( 'ur-input-field' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'tip'               => __( 'The text shown at the top of your form (e.g., "Register Now").', 'user-registration' ),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_title', __( 'Register', 'user-registration' ) ),
				'default_value'     => __( 'Register', 'user-registration' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'textarea',
				'label'             => __( 'Form Description', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_description',
				'class'             => array( 'ur-input-field' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'tip'               => __( 'A short message to guide people filling in the form.', 'user-registration' ),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_description', __( 'Fill the form below to create an account.', 'user-registration' ) ),
				'default_value'     => __( 'Fill the form below to create an account.', 'user-registration' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'label'             => __( 'User Approval And Login Option', 'user-registration' ),
				'description'       => '',
				'id'                => 'user_registration_form_setting_login_options',
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_login_options', get_option( 'user_registration_general_setting_login_options' ) ),
				'default_value'     => 'default',
				'type'              => 'select',
				'class'             => array( 'ur-enhanced-select' ),
				'custom_attributes' => array(),
				'input_class'       => array(),
				'required'          => false,
				'options'           => ur_login_option(),
				'tip'               => __( 'Choose how new users can log in after signing up.', 'user-registration' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'label'             => __( 'Select Phone Fields for SMS Verification', 'user-registration' ),
				'description'       => '',
				'id'                => 'user_registration_form_setting_default_phone_field',
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_default_phone_field', '' ),
				'default_value'     => '',
				'type'              => 'select',
				'class'             => array( 'ur-enhanced-select' ),
				'custom_attributes' => array(),
				'input_class'       => array(),
				'required'          => false,
				'options'           => user_registration_get_form_fields_for_dropdown( $form_id ),
				'tip'               => __( 'This option is to map phone field for sms verification.', 'user-registration' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'label'             => __( 'SMS Verification message', 'user-registration' ),
				'description'       => '',
				'id'                => 'user_registration_form_setting_sms_verification_msg',
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_sms_verification_msg', ur_get_sms_verification_default_message_content() ),
				'default_value'     => ur_get_sms_verification_default_message_content(),
				'type'              => 'textarea',
				'class'             => array( 'ur-input-field' ),
				'custom_attributes' => array(),
				'input_class'       => array(),
				'required'          => false,
				'tip'               => __( 'This is sms verification message content.', 'user-registration' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'select',
				'label'             => __( 'User Role After Registration', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_default_user_role',
				'class'             => array( 'ur-enhanced-select' ),
				'input_class'       => array(),
				'options'           => $all_roles,
				'custom_attributes' => array(),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_default_user_role', get_option( 'user_registration_form_setting_default_user_role', 'subscriber' ) ),
				'tip'               => __( 'Pick what role new users will have after they sign up.', 'user-registration' ),
				'default_value'     => get_option( 'user_registration_form_setting_default_user_role', 'subscriber' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'select',
				'label'             => __( 'Redirect After Registration', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_redirect_after_registration',
				'class'             => array( 'ur-enhanced-select' ),
				'input_class'       => array(),
				/**
				 * Filters the redirection options after user registration.
				 *
				 * @param array $redirection_options An associative array where keys represent
				 *                                   the option values, and values represent the labels
				 *                                   for the redirection options.
				 */
				'options'           => apply_filters(
					'user_registration_redirect_after_registration_options',
					array(
						'no-redirection' => __( 'No Redirection', 'user-registration' ),
						'internal-page'  => __( 'Internal Page', 'user-registration' ),
						'external-url'   => __( 'External URL', 'user-registration' ),
						'previous-page'  => __( 'Previous Page', 'user-registration' ),
					)
				),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_redirect_after_registration', $redirect_after_registration_default ),
				'tip'               => __( 'Decide where users go after completing registration.', 'user-registration' ),
				'default_value'     => $redirect_after_registration_default,
				'custom_attributes' => array(),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'number',
				'label'             => __( 'Delay Before Redirect ( Seconds )', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_redirect_after',
				'class'             => array( 'ur-input-field' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'min'               => '0',
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_redirect_after', '2' ),
				'tip'               => __( 'How many seconds to wait before sending the user to another page.', 'user-registration' ),
				'default_value'     => '2',
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'select',
				'label'             => __( 'Redirect to Page', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_redirect_page',
				'class'             => array( 'ur-enhanced-select' ),
				'input_class'       => array(),
				'options'           => ur_get_all_pages(),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_redirect_page', get_option( 'user_registration_thank_you_page_id', '' ) ),
				'tip'               => __( 'Pick the page users will see after signing up.', 'user-registration' ),
				'default_value'     => get_option( 'user_registration_thank_you_page_id', '' ),
				'custom_attributes' => array(),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'text',
				'label'             => __( 'Redirect URL', 'user-registration' ),
				'id'                => 'user_registration_form_setting_redirect_options',
				'class'             => array( 'ur-input-field' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'tip'               => __( 'Set the URL of the page users should be sent to after signing up.', 'user-registration' ),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_redirect_options', get_option( 'user_registration_general_setting_redirect_options', '' ) ),
				// Getting redirect options from global settings for backward compatibility.
				'default_value'     => get_option( 'user_registration_general_setting_redirect_options', '' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'    => 'section',
				'title'   => __( 'Advanced', 'user-registration' ),
				'id'      => 'user_registration_form_setting_general_advanced',
				'class'   => array( 'ur-form-settings-section' ),
				'product' => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'toggle',
				'label'             => __( 'Require Strong Password', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_enable_strong_password',
				'class'             => array(),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_enable_strong_password', ur_string_to_bool( get_option( 'user_registration_form_setting_enable_strong_password', 0 ) ) ),
				'tip'               => __( 'Make sure users create a password that’s harder to guess.', 'user-registration' ),
				'default_value'     => ur_string_to_bool( get_option( 'user_registration_form_setting_enable_strong_password', 0 ) ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'radio-group',
				'label'             => __( 'Minimum Password Strength', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_minimum_password_strength',
				'class'             => array( 'ur-enhanced-select' ),
				'input_class'       => array(),
				'options'           => array(
					'0' => __( 'Very Weak', 'user-registration' ),
					'1' => __( 'Weak', 'user-registration' ),
					'2' => __( 'Medium', 'user-registration' ),
					'3' => __( 'Strong', 'user-registration' ),
					'4' => __( 'Custom', 'user-registration' ),
				),
				'custom_attributes' => array(),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_minimum_password_strength', get_option( 'user_registration_form_setting_minimum_password_strength', '3' ) ),
				'tip'               => __( 'Choose how secure a password must be.', 'user-registration' ),
				'default_value'     => get_option( 'user_registration_form_setting_minimum_password_strength', '3' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'number',
				'label'             => __( 'Minimum Uppercase Letters', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_form_minimum_uppercase',
				'class'             => array( 'ur-input-field custom-password-params' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'min'               => '0',
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_minimum_uppercase', '0' ),
				'tip'               => __( 'How many capital letters the password must have.', 'user-registration' ),
				'default_value'     => '0',
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'number',
				'label'             => __( 'Minimum Numbers', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_form_minimum_digits',
				'class'             => array( 'ur-input-field custom-password-params' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'min'               => '0',
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_minimum_digits', '0' ),
				'tip'               => __( 'How many numbers the password must have.', 'user-registration' ),
				'default_value'     => '0',
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'number',
				'label'             => __( 'Minimum Special Symbols', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_form_minimum_special_chars',
				'class'             => array( 'ur-input-field custom-password-params' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'min'               => '0',
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_minimum_special_chars', '0' ),
				'tip'               => __( 'How many symbols (like @, #, $) the password must have.', 'user-registration' ),
				'default_value'     => '0',
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'number',
				'label'             => __( 'Minimum Password Length', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_form_minimum_pass_length',
				'class'             => array( 'ur-input-field custom-password-params' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'min'               => '6',
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_minimum_pass_length', '6' ),
				'tip'               => __( 'The fewest characters allowed for the password.', 'user-registration' ),
				'default_value'     => '6',
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'toggle',
				'label'             => __( 'Block Repetitive Characters', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_no_repeat_chars',
				'class'             => array( 'custom-password-params' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_no_repeat_chars', ur_string_to_bool( get_option( 'user_registration_form_setting_no_repeat_chars', 0 ) ) ),
				'tip'               => __( 'Stop users from using the same character too many times in a row.', 'user-registration' ),
				'default_value'     => ur_string_to_bool( get_option( 'user_registration_form_setting_no_repeat_chars', 0 ) ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'number',
				'label'             => __( 'Maximum Allowed Repeats', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_form_max_char_repeat_length',
				'class'             => array( 'ur-input-field custom-password-params' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'min'               => '1',
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_max_char_repeat_length', '' ),
				'tip'               => __( 'How many times a character can be repeated in a row.', 'user-registration' ),
				'default_value'     => '',
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'text',
				'label'             => __( 'Submit Button Text', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_form_submit_label',
				'class'             => array( 'ur-input-field' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_submit_label', 'Submit' ),
				'tip'               => __( 'The label shown on the form’s submit button.', 'user-registration' ),
				'product'           => 'user-registration/user-registration.php',

			),
			array(
				'type'              => 'text',
				'label'             => __( 'Submit Button CSS Classes', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_form_submit_class',
				'class'             => array( 'ur-input-field' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'tip'               => __( 'Add custom CSS classes for styling the submit button. Separate multiple classes with spaces.', 'user-registration' ),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_form_submit_class', __( 'submit', 'user-registration' ) ),
				'default_value'     => __( 'Submit', 'user-registration' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'select',
				'label'             => __( 'Success Message Position', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_success_message_position',
				'class'             => array( 'ur-enhanced-select' ),
				'input_class'       => array(),
				'options'           => array(
					'0' => esc_html__( 'Top', 'user-registration' ),
					'1' => esc_html__( 'Bottom', 'user-registration' ),
					'2' => esc_html__( 'Hide Form After Successful Submission', 'user-registration' ),
				),
				'custom_attributes' => array(),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_success_message_position', '1' ),
				'tip'               => __( 'Choose where the “Registration Successful” message appears after sign-up.', 'user-registration' ),
				'default_value'     => '1',
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'toggle',
				/* translators: 1: Link tag open 2:: Link content 3:: Link tag close */
				'label'             => sprintf( __( 'Enable %1$s %2$s Captcha %3$s Support', 'user-registration' ), '<a title="', 'Please make sure the site key and secret are not empty in setting page." href="' . admin_url() . 'admin.php?page=user-registration-settings&tab=registration_login&section=captcha" rel="noreferrer noopener" target="_blank">', '</a>' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_enable_recaptcha_support',
				'class'             => array(),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'default'           => ur_string_to_bool( ur_get_single_post_meta( $form_id, 'user_registration_form_setting_enable_recaptcha_support', false ) ),
				'tip'               => __( 'Turn on Captcha to prevent spam registration attempts.', 'user-registration' ),
				'default_value'     => false,
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'select',
				'label'             => __( 'Select Captcha Type', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_setting_configured_captcha_type',
				'class'             => array( 'ur-enhanced-select' ),
				'input_class'       => array(),
				'options'           => $ur_enabled_captchas,
				'custom_attributes' => array(),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_setting_configured_captcha_type', '1' ),
				'default_value'     => '1',
				'tip'               => __( 'Select the type of Captcha you want in this form.', 'user-registration' ),
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'text',
				'label'             => __( 'Form CSS Classes', 'user-registration' ),
				'description'       => '',
				'required'          => false,
				'id'                => 'user_registration_form_custom_class',
				'class'             => array( 'ur-input-field' ),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'tip'               => __( 'Add custom CSS classes for styling the entire form. Separate multiple classes with spaces.', 'user-registration' ),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_form_custom_class', '' ),
				'default_value'     => '',
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'              => 'toggle',
				'label'             => __( 'Enable Spam Protection (Akismet)', 'user-registration' ),
				'required'          => false,
				'id'                => 'user_registration_enable_akismet',
				'class'             => array( '' ),
				'custom_attributes' => array(),
				'default'           => ur_get_single_post_meta( $form_id, 'user_registration_enable_akismet', false ),
				'tip'               => __( 'Stop spam sign-ups using the Akismet plugin.', 'user-registration' ),
				'default_value'     => false,
				'product'           => 'user-registration/user-registration.php',
			),
			array(
				'type'        => 'label',
				'id'          => 'user_registration_akismet_warning',
				'description' => ur_check_akismet_installation(),
				'product'     => 'user-registration/user-registration.php',
			),
		),
	);

	/**
	 * Filters the form settings before processing or rendering.
	 *
	 * @param array $arguments An array of form settings.
	 */
	$arguments                 = apply_filters( 'user_registration_get_form_settings', $arguments );
	$arguments['setting_data'] = apply_filters( 'user_registration_settings_text_format', $arguments['setting_data'] );

	return $arguments['setting_data'];
}

/**
 * User Login Option
 *
 * @return array
 */
function ur_login_option() {
	/**
	 * Filters the login options available during user registration.
	 *
	 * @param array $login_options An associative array where keys represent the
	 *                             option values, and values represent the labels
	 *                             for the login options.
	 */
	return apply_filters(
		'user_registration_login_options',
		array(
			'default'            => __( 'Auto approval and manual login', 'user-registration' ),
			'auto_login'         => __( 'Auto approval and auto login ', 'user-registration' ),
			'admin_approval'     => __( 'Admin approval', 'user-registration' ),
			'email_confirmation' => __( 'Auto approval after email confirmation', 'user-registration' ),
		)
	);
}

/**
 * User Login Option
 *
 * @return array
 */
function ur_login_option_with() {
	/**
	 * Filters the login options with specific identification types during login.
	 *
	 * @param array $login_options_with An associative array where keys represent the
	 *                                  identification types, and values represent the labels
	 *                                  for the corresponding login options.
	 */
	return apply_filters(
		'user_registration_login_options_with',
		array(
			'default'  => __( 'Username or Email', 'user-registration' ),
			'username' => __( 'Username', 'user-registration' ),
			'email'    => __( 'Email', 'user-registration' ),
		)
	);
}

/**
 * Get Post meta value by meta key.
 *
 * @param int    $post_id Post ID.
 * @param string $meta_key Meta Key.
 * @param mixed  $default Default Value.
 *
 * @return mixed
 * @since 1.0.1
 */
function ur_get_single_post_meta( $post_id, $meta_key, $default = null ) {

	$post_meta = get_post_meta( $post_id, $meta_key );

	if ( isset( $post_meta[0] ) ) {
		if (
			'user_registration_form_setting_enable_recaptcha_support' === $meta_key || 'user_registration_form_setting_enable_strong_password' === $meta_key
			|| 'user_registration_pdf_submission_to_admin' === $meta_key || 'user_registration_pdf_submission_to_user' === $meta_key || 'user_registration_form_setting_enable_assign_user_role_conditionally' === $meta_key
		) {
			$post_meta[0] = ur_string_to_bool( $post_meta[0] );
		}

		return $post_meta[0];
	}

	return $default;
}

if ( ! function_exists( 'ur_get_default_redirect_after_registration' ) ) {
	/**
	 * Get default redirect-after-registration value for a form.
	 * Returns 'internal-page' if the form has a membership field, otherwise 'no-redirection'.
	 *
	 * @param int $form_id Form ID.
	 * @return string 'internal-page' or 'no-redirection'
	 * @since 1.0.1
	 */
	function ur_get_default_redirect_after_registration( $form_id ) {
		if ( ! $form_id ) {
			return 'no-redirection';
		}
		$form_post = get_post( $form_id );
		if ( $form_post && isset( $form_post->post_content ) && false !== strpos( $form_post->post_content, '"field_key":"membership"' ) ) {
			return 'internal-page';
		}
		return 'no-redirection';
	}
}

/**
 * Get general form settings by meta key (settings id).
 *
 * @param int    $form_id Form ID.
 * @param string $meta_key Meta Key.
 * @param mixed  $default Default Value.
 *
 * @return mixed
 * @since 1.0.1
 */
function ur_get_form_setting_by_key( $form_id, $meta_key, $default = '' ) {

	$fields = ur_admin_form_settings_fields( $form_id );
	$value  = '';

	foreach ( $fields as $field ) {

		if ( isset( $field['id'] ) && $meta_key == $field['id'] ) {
			$value = isset( $field['default'] ) ? sanitize_text_field( $field['default'] ) : $default;
			break;
		}
	}

	return $value;
}

/**
 * Get user status in case of admin approval login option
 *
 * @param int $user_id User ID.
 *
 * @return int
 */
function ur_get_user_approval_status( $user_id ) {

	$user_status = 1;

	$login_option = ur_get_user_login_option( $user_id );

	if ( 'admin_approval' === $login_option ) {

		$user_status = get_user_meta( $user_id, 'ur_user_status', true );
	}

	return $user_status;
}

/**
 * Get form data by field key.
 *
 * @param array  $form_data Form Data.
 * @param string $key Field Key.
 *
 * @return array
 */
function ur_get_form_data_by_key( $form_data, $key = null ) {

	$form_data_array = array();

	foreach ( $form_data as $data ) {
		foreach ( $data as $single_data ) {
			foreach ( $single_data as $field_data ) {

				$field_key = isset( $field_data->field_key ) && null !== $field_data->field_key ? $field_data->field_key : '';

				if ( ! empty( $field_key ) ) {
					$field_name = isset( $field_data->general_setting->field_name ) && null !== $field_data->general_setting->field_name ? $field_data->general_setting->field_name : '';

					if ( null === $key ) {

						if ( ! empty( $field_name ) ) {
							$form_data_array[ $field_name ] = $field_data;
						} else {
							$form_data_array[] = $field_data;
						}
					} elseif ( $field_key === $key ) {

						if ( ! empty( $field_name ) ) {
							$form_data_array[ $field_name ] = $field_data;
						} else {
							$form_data_array[] = $field_data;
						}
					}
				}
			}
		}
	}

	return $form_data_array;
}

/**
 * Get a log file path.
 *
 * @param string $handle name.
 *
 * @return string the log file path.
 * @since 1.0.5
 */
function ur_get_log_file_path( $handle ) {
	return UR_Log_Handler_File::get_log_file_path( $handle );
}

/**
 * Registers the default log handler.
 *
 * @param array $handlers Log handlers.
 *
 * @return array
 * @since 1.0.5
 */
function ur_register_default_log_handler( $handlers ) {

	if ( defined( 'UR_LOG_HANDLER' ) && class_exists( UR_LOG_HANDLER ) ) {
		$handler_class   = UR_LOG_HANDLER;
		$default_handler = new $handler_class();
	} else {
		$default_handler = new UR_Log_Handler_File();
	}

	array_push( $handlers, $default_handler );

	return $handlers;
}

add_filter( 'user_registration_register_log_handlers', 'ur_register_default_log_handler' );


/**
 * Get a shared logger instance.
 *
 * Use the user_registration_logging_class filter to change the logging class. You may provide one of the following:
 *     - a class name which will be instantiated as `new $class` with no arguments
 *     - an instance which will be used directly as the logger
 * In either case, the class or instance *must* implement UR_Logger_Interface.
 *
 * @return UR_Logger
 * @since 1.1.0
 * @see UR_Logger_Interface
 */
function ur_get_logger() {
	static $logger = null;
	if ( null === $logger ) {
		/**
		 * Applies the 'user_registration_logging_class' filter to customize the logger class.
		 *
		 * @param string|object $class The class name or an instance of the logger.
		 *
		 * @since 1.1.0
		 */
		$class      = apply_filters( 'user_registration_logging_class', 'UR_Logger' );
		$implements = class_implements( $class );
		if ( is_array( $implements ) && in_array( 'UR_Logger_Interface', $implements ) ) {
			if ( is_object( $class ) ) {
				$logger = $class;
			} else {
				$logger = new $class();
			}
		} else {
			ur_doing_it_wrong(
				__FUNCTION__,
				sprintf(
				/* translators: %s: Class */
					__( 'The class <code>%s</code> provided by user_registration_logging_class filter must implement <code>UR_Logger_Interface</code>.', 'user-registration' ),
					esc_html( is_object( $class ) ? get_class( $class ) : $class )
				),
				'1.0.5'
			);
			$logger = new UR_Logger();
		}
	}

	return $logger;
}

/**
 * Handles addon plugin updater.
 *
 * @param string $file Plugin File.
 * @param int    $item_id Item ID.
 * @param string $addon_version Addon Version.
 * @param bool   $beta Is beta version.
 *
 * @since 1.1.0
 */
function ur_addon_updater( $file, $item_id, $addon_version, $beta = false ) {
	$api_endpoint = 'https://wpeverest.com/edd-sl-api/';
	$license_key  = trim( get_option( 'user-registration_license_key' ) );
	if ( class_exists( 'UR_AddOn_Updater' ) ) {
		new UR_AddOn_Updater(
			esc_url_raw( $api_endpoint ),
			$file,
			array(
				'version' => $addon_version,
				'license' => $license_key,
				'item_id' => $item_id,
				'author'  => 'WPEverest',
				'url'     => home_url(),
				'beta'    => $beta,
			)
		);
	}
}

/**
 * Check if username already exists in case of optional username
 * And while stripping through email address and incremet last number by 1.
 *
 * @param string $username Username.
 *
 * @return string
 */
function check_username( $username ) {

	if ( username_exists( $username ) ) {
		preg_match_all( '/\d+$/m', $username, $matches );

		if ( isset( $matches[0][0] ) ) {
			$last_char       = $matches[0][0];
			$strip_last_char = substr( $username, 0, - ( strlen( (string) $last_char ) ) );
			++$last_char;
			$username = $strip_last_char . $last_char;
			$username = check_username( $username );

			return $username;
		} else {
			$username = $username . '_1';
			$username = check_username( $username );

			return $username;
		}
	}

	return $username;
}

/**
 * Get all user registration forms title with respective id.
 *
 * @param int $post_count Post Count.
 *
 * @return array
 */
function ur_get_all_user_registration_form( $post_count = -1 ) {
	$all_forms = array();

	$args = array(
		'status'      => 'publish',
		'numberposts' => $post_count,
		'order'       => 'ASC',
	);

	if ( isset( UR()->form ) && method_exists( UR()->form, 'get_form' ) ) {
		$posts_array = UR()->form->get_form( '', $args );

		if ( ! empty( $posts_array ) && is_array( $posts_array ) ) {
			foreach ( $posts_array as $post ) {
				if ( isset( $post->ID, $post->post_title ) ) {
					$all_forms[ $post->ID ] = esc_html( $post->post_title );
				}
			}
		}
	}

	if ( empty( $all_forms ) ) {
		$fallback_args = array(
			'post_type'      => 'user_registration',
			'post_status'    => 'publish',
			'posts_per_page' => $post_count,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$posts = get_posts( $fallback_args );

		if ( ! empty( $posts ) ) {
			foreach ( $posts as $post ) {
				$all_forms[ $post->ID ] = esc_html( $post->post_title );
			}
		}
	}

	return $all_forms;
}

/**
 * Get the node to display google reCaptcha
 *
 * @param string $context Recaptcha context.
 * @param string $recaptcha_enabled Is Recaptcha enabled.
 * @param int    $form_id Form ID.
 *
 * @return string
 */
function ur_get_recaptcha_node( $context, $recaptcha_enabled = false, $form_id = 0 ) {
	$recaptcha_type         = get_option( 'user_registration_captcha_setting_recaptcha_version', 'v2' );
	$invisible_recaptcha    = ur_option_checked( 'user_registration_captcha_setting_invisible_recaptcha_v2', false );
	$theme_mod              = '';
	$enqueue_script         = '';
	$recaptcha_site_key     = '';
	$recaptcha_site_secret  = '';
	$empty_credentials      = false;
	$global_captcha_enabled = false;

	if ( 'login' === $context ) {
		$recaptcha_type = get_option( 'user_registration_login_options_configured_captcha_type', $recaptcha_type );
	} elseif ( 'register' === $context && $form_id ) {
		$recaptcha_type = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_configured_captcha_type', $recaptcha_type );
	} elseif ( 'test_captcha' === $context && false !== $recaptcha_enabled ) {
		$recaptcha_type = $recaptcha_enabled;
	} elseif ( 'lost_password' === $context ) {
		// Same recaptcha type as login.
		$recaptcha_type = get_option( 'user_registration_login_options_configured_captcha_type', $recaptcha_type );
		$recaptcha_type = apply_filters( 'user_registration_lost_password_captcha_type', $recaptcha_type );

	}

	if ( 'v2' === $recaptcha_type && ! $invisible_recaptcha ) {
		$recaptcha_site_key    = get_option( 'user_registration_captcha_setting_recaptcha_site_key' );
		$recaptcha_site_secret = get_option( 'user_registration_captcha_setting_recaptcha_site_secret' );
		$enqueue_script        = 'ur-google-recaptcha';
	} elseif ( 'v2' === $recaptcha_type && $invisible_recaptcha ) {
		$recaptcha_site_key    = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_key' );
		$recaptcha_site_secret = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_secret' );
		$enqueue_script        = 'ur-google-recaptcha';
	} elseif ( 'v3' === $recaptcha_type ) {
		$recaptcha_site_key    = get_option( 'user_registration_captcha_setting_recaptcha_site_key_v3' );
		$recaptcha_site_secret = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_v3' );
		$enqueue_script        = 'ur-google-recaptcha-v3';
	} elseif ( 'hCaptcha' === $recaptcha_type ) {
		$recaptcha_site_key    = get_option( 'user_registration_captcha_setting_recaptcha_site_key_hcaptcha' );
		$recaptcha_site_secret = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha' );
		$enqueue_script        = 'ur-recaptcha-hcaptcha';
	} elseif ( 'cloudflare' === $recaptcha_type ) {
		$recaptcha_site_key    = get_option( 'user_registration_captcha_setting_recaptcha_site_key_cloudflare' );
		$recaptcha_site_secret = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_cloudflare' );
		$theme_mod             = get_option( 'user_registration_captcha_setting_recaptcha_cloudflare_theme' );
		$enqueue_script        = 'ur-recaptcha-cloudflare';
	}
	static $rc_counter = 0;

	if ( empty( $recaptcha_site_key ) && empty( $recaptcha_site_secret ) ) {
		$empty_credentials = true;
	}

	// Exit early if recaptcha is not enabled in global settings or has messing credentials.
	if ( $empty_credentials ) {
		return '';
	}

	if ( $recaptcha_enabled ) {
		// Don't enqueue scripts for test_captcha context (admin settings).
		// Admin settings will handle script loading via JavaScript.
		if ( 'test_captcha' !== $context ) {
			wp_enqueue_script( 'ur-recaptcha' );
			wp_enqueue_script( $enqueue_script );
		}

		$ur_recaptcha_code = array(
			'site_key'          => $recaptcha_site_key,
			'is_captcha_enable' => true,
			'version'           => $recaptcha_type,
			'is_invisible'      => $invisible_recaptcha,
			'theme_mode'        => $theme_mod,
		);
		$ur_recaptcha_slug = 'ur_' . strtolower( $recaptcha_type ) . '_recaptcha_code';
		if ( 'v2' === $recaptcha_type ) {
			$ur_recaptcha_slug = 'ur_recaptcha_code';
		}

		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			?>
			<script id="<?php echo esc_attr( $enqueue_script ); ?>_<?php echo esc_attr( $rc_counter ); ?>">
				const <?php echo $ur_recaptcha_slug; // PHPCS:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> = <?php echo wp_json_encode( $ur_recaptcha_code ); ?>
			</script>
			<?php
		} else {
			wp_localize_script( $enqueue_script, $ur_recaptcha_slug, $ur_recaptcha_code );
		}
		++$rc_counter;

		if ( 'v3' === $recaptcha_type ) {
			if ( 'login' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_login" class="g-recaptcha-v3" style="display:none"><textarea id="g-recaptcha-response" name="g-recaptcha-response" ></textarea></div>';
			} elseif ( 'test_captcha' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_login-v3" class="g-recaptcha-v3" style="display:none"><textarea id="g-recaptcha-response-v3" name="g-recaptcha-response" ></textarea></div>';
			} elseif ( 'register' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_register" class="g-recaptcha-v3" style="display:none"><textarea id="g-recaptcha-response" name="g-recaptcha-response" ></textarea></div>';
			} elseif ( 'lost_password' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_lost_password" class="g-recaptcha-v3" style="display:none"><textarea id="g-recaptcha-response" name="g-recaptcha-response" ></textarea></div>';
			} else {
				$recaptcha_node = '';
			}
		} elseif ( 'hCaptcha' === $recaptcha_type ) {
			if ( 'login' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_login" class="g-recaptcha-hcaptcha"></div>';
			} elseif ( 'test_captcha' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_login-hcaptcha" class="g-recaptcha-hcaptcha"></div>';
			} elseif ( 'register' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_register" class="g-recaptcha-hcaptcha"></div>';
			} elseif ( 'lost_password' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_lost_password" class="g-recaptcha-hcaptcha"></div>';
			} else {
				$recaptcha_node = '';
			}
		} elseif ( 'cloudflare' === $recaptcha_type ) {

			if ( 'login' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_login" class="cf-turnstile"></div>';
			} elseif ( 'test_captcha' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_login-cf-turnstile" class="cf-turnstile"></div>';
			} elseif ( 'register' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_register" class="cf-turnstile"></div>';
			} elseif ( 'lost_password' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_lost_password" class="cf-turnstile"></div>';
			} else {
				$recaptcha_node = '';
			}
		} elseif ( 'v2' === $recaptcha_type && $invisible_recaptcha ) {
			if ( 'login' === $context || 'test_captcha' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_login" class="g-recaptcha" data-size="invisible"></div>';
			} elseif ( 'register' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_register" class="g-recaptcha" data-size="invisible"></div>';
			} elseif ( 'lost_password' === $context ) {
				$recaptcha_node = '<div id="node_recaptcha_lost_password" class="g-recaptcha" data-size="invisible"></div>';
			} else {
				$recaptcha_node = '';
			}
		} elseif ( 'login' === $context || 'test_captcha' === $context ) {
			$recaptcha_node = '<div id="node_recaptcha_login" class="g-recaptcha"></div>';
		} elseif ( 'register' === $context ) {
			$recaptcha_node = '<div id="node_recaptcha_register" class="g-recaptcha"></div>';
		} elseif ( 'lost_password' === $context ) {
			$recaptcha_node = '<div id="node_recaptcha_lost_password" class="g-recaptcha"></div>';
		} else {
			$recaptcha_node = '';
		}
	} else {
		$recaptcha_node = '';
	}

	return $recaptcha_node;
}


/**
 * Get meta key label pair by form id
 *
 * @param int $form_id Form ID.
 *
 * @return array
 * @since  1.5.0
 */
function ur_get_meta_key_label( $form_id ) {

	$key_label = array();

	$post_content_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();

	foreach ( $post_content_array as $post_content_row ) {
		foreach ( $post_content_row as $post_content_grid ) {
			foreach ( $post_content_grid as $field ) {
				if ( isset( $field->field_key ) && isset( $field->general_setting->field_name ) ) {
					$key_label[ $field->general_setting->field_name ] = $field->general_setting->label;
				}
			}
		}
	}

	/**
	 * Filters the label for a meta key.
	 *
	 * @param string $key_label The label for the meta key.
	 * @param int $form_id The ID of the user registration form.
	 * @param array $post_content_array An array containing the post content for the form.
	 */
	return apply_filters( 'user_registration_meta_key_label', $key_label, $form_id, $post_content_array );
}

/**
 * Get all user registration fields of the user by querying to database.
 *
 * @param int    $user_id User ID.
 * @param string $action The action being performed (e.g., 'export_users').
 *
 * @return array
 * @since  1.5.0
 */
function ur_get_user_extra_fields( $user_id, $action = '' ) {
	$name_value = array();

	$admin_profile = new UR_Admin_Profile();
	$extra_data    = $admin_profile->get_user_meta_by_form_fields( $user_id );
	$form_id       = ur_get_form_id_by_userid( $user_id );

	if ( 'export_users' === $action ) {

		$all_meta_for_user = $admin_profile->get_user_meta_by_prefix( $user_id, 'user_registration_' );
		$form_row_data     = get_post_meta( $form_id, 'user_registration_form_row_data', true );
		$row_datas         = ! empty( $form_row_data ) ? json_decode( $form_row_data ) : array();

		$repeaters = array();
		if ( ! empty( $row_datas ) ) {
			foreach ( $row_datas as $row_data ) {
				if ( isset( $row_data->type ) && 'repeater' === $row_data->type ) {

					if ( array_key_exists( 'user_registration_' . $row_data->field_name, $all_meta_for_user ) ) {
						$repeater_rows = maybe_unserialize(
							$all_meta_for_user[ 'user_registration_' .
												$row_data->field_name ]
						);

						if ( ! empty( $repeater_rows ) ) {
							foreach ( $repeater_rows as $fields ) {
								foreach ( $fields as $field ) {
									$field_value = $field->value;
									$field_type  = isset( $field->field_type ) ? $field->field_type : '';

									if ( in_array( $field_type, array( 'checkbox', 'multi-select' ), true ) ) {
										$field_value = json_decode( $field_value, true );
									}

									$field_name  = $field->field_name;
									$field_value = is_array( $field_value ) ? array( implode( ', ', $field_value ) ) : $field_value;

									$field_key = 'user_registration_' . $field_name;

									if ( array_key_exists( $field_key, $all_meta_for_user ) ) {
										$all_meta_for_user[ $field_key ] = is_array( $all_meta_for_user[ $field_key ] ) ? array_merge( $all_meta_for_user[ $field_key ], $field_value ) : ( $all_meta_for_user[ $field_key ] . ' - ' . $field_value );
									} else {
										$all_meta_for_user[ $field_key ] = $field_value;
									}
								}
							}
						}
					}
				}
			}
		}
	}

	$form_fields = isset( array_column( $extra_data, 'fields' )[0] ) ? array_column( $extra_data, 'fields' )[0] : array(); //phpcs:ignore.
	if ( ! empty( $form_fields ) ) {
		foreach ( $form_fields as $field_key => $field_data ) {
			// For repeator export.
			if ( 'export_users' === $action ) {
				$value = isset( $all_meta_for_user[ $field_key ] ) ? $all_meta_for_user[ $field_key ] : get_user_meta( $user_id, $field_key, true );
			} else {
				$value = get_user_meta( $user_id, $field_key, true );
			}

			$field_key = str_replace( 'user_registration_', '', $field_key );

			if ( is_serialized( $value ) ) {
				$value = unserialize( $value, array( 'allowed_classes' => false ) ); //phpcs:ignore.

				if ( is_array( $value ) ) {
					$value = implode( ',', $value );
				} else {
					$value = (string) $value;
				}
			}

			$name_value[ $field_key ] = $value;
		}
	}

	/**
	 * Filters extra fields associated with a user.
	 *
	 * @param array $name_value An array of name-value pairs representing extra fields.
	 * @param int $user_id The user ID associated with the registration process.
	 */
	return apply_filters( 'user_registration_user_extra_fields', $name_value, $user_id );
}

/**
 * Get User status like approved, pending.
 *
 * @param string $user_status Admin approval status of user.
 * @param string $user_email_status Email confirmation status of user.
 */
function ur_get_user_status( $user_status, $user_email_status ) {
	$status = array();
	if ( '0' === $user_status || '0' === $user_email_status ) {
		array_push( $status, 'Pending' );
	} elseif ( '-1' === $user_status || '-1' === $user_email_status ) {
		array_push( $status, 'Denied' );
	} elseif ( $user_email_status ) {
		array_push( $status, 'Verified' );
	} else {
		array_push( $status, 'Approved' );
	}

	return $status;
}

/**
 * Get link for back button used on email settings.
 *
 * @param string $label Label.
 * @param string $url URL.
 */
function ur_back_link( $label, $url ) {
	return '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $label ) . '">
                <svg xmlns="http://www.w3.org/2000/svg" fill="#000" viewBox="0 0 24 24">
                  <path d="M15.653 2.418a1.339 1.339 0 0 1 1.944 0 1.468 1.468 0 0 1 0 2.02L10.32 12l7.278 7.562.094.108a1.47 1.47 0 0 1-.094 1.912c-.503.523-1.3.555-1.84.098l-.104-.098-8.25-8.572a1.468 1.468 0 0 1 0-2.02l8.25-8.572Z"/>
                </svg>
            </a>';
}

/**
 * The function wp_doing ajax() is introduced in core @since 4.7,
 */
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/**
	 * Filters whether the current request is a WordPress Ajax request.
	 */
	function wp_doing_ajax() {
		return apply_filters( 'wp_doing_ajax', defined( 'DOING_AJAX' ) && DOING_AJAX );
	}
}

/**
 * Checks if the string is json or not
 *
 * @param string $str String to check.
 *
 * @return mixed
 * @since  1.4.2
 */
function ur_is_json( $str ) {
	if ( ! is_string( $str ) ) {
		return false;
	}

	$json = json_decode( $str );

	return $json && $str != $json && json_last_error() == JSON_ERROR_NONE;
}

/**
 * Checks if the form contains a date field or not.
 *
 * @param int $form_id Form ID.
 *
 * @return void
 * @since  1.5.3
 * @deprecated 3.1.3
 */
function ur_has_date_field( $form_id ) {
	ur_deprecated_function( 'ur_has_date_field', '3.1.3', 'ur_has_flatpickr_field' );
}

/**
 * Checks if the form contains a date and time field or not.
 *
 * @param int $form_id Form ID.
 *
 * @return boolean
 * @since  1.5.3
 */
function ur_has_flatpickr_field( $form_id ) {

	$post_content_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();

	if ( ! empty( $post_content_array ) ) {
		foreach ( $post_content_array as $post_content_row ) {
			foreach ( $post_content_row as $post_content_grid ) {
				foreach ( $post_content_grid as $field ) {
					if ( isset( $field->field_key ) && ( 'date' === $field->field_key || 'timepicker' === $field->field_key ) ) {
						return true;
					}
				}
			}
		}
	}

	return false;
}

/**
 * Get attributes from the shortcode content.
 *
 * @param string $content Shortcode content.
 *
 * @return array        Array of attributes within the shortcode.
 *
 * @since  1.6.0
 */
function ur_get_shortcode_attr( $content ) {
	$pattern = get_shortcode_regex();

	$keys   = array();
	$result = array();

	if ( preg_match_all( '/' . $pattern . '/s', $content, $matches ) ) {

		foreach ( $matches[0] as $key => $value ) {

			// $matches[ 3 ] return the shortcode attribute as string.
			// replace space with '&' for parse_str() function.
			$get = str_replace( ' ', '&', $matches[3][ $key ] );
			parse_str( $get, $output );

			// Get all shortcode attribute keys.
			$keys     = array_unique( array_merge( $keys, array_keys( $output ) ) );
			$result[] = $output;
		}

		if ( $keys && $result ) {

			// Loop the result array and add the missing shortcode attribute key.
			foreach ( $result as $key => $value ) {

				// Loop the shortcode attribute key.
				foreach ( $keys as $attr_key ) {
					$result[ $key ][ $attr_key ] = isset( $result[ $key ][ $attr_key ] ) ? $result[ $key ][ $attr_key ] : null;
				}

				// Sort the array key.
				ksort( $result[ $key ] );
			}
		}
	}

	return $result;
}

/**
 * Print js script by properly sanitizing and escaping.
 *
 * @since 1.1.2
 * Output any queued javascript code in the footer.
 */
function ur_print_js() {
	global $ur_queued_js;

	if ( ! empty( $ur_queued_js ) ) {
		// Sanitize.
		$ur_queued_js = wp_check_invalid_utf8( $ur_queued_js );
		$ur_queued_js = preg_replace( '/&#(x)?0*(?(1)27|39);?/i', "'", $ur_queued_js );
		$ur_queued_js = str_replace( "\r", '', $ur_queued_js );

		$js = "<!-- User Registration JavaScript -->\n<script type=\"text/javascript\">\njQuery(function($) { $ur_queued_js });\n</script>\n";

		/**
		 * User Registration js filter.
		 *
		 * @param string $js JavaScript code.
		 */
		echo wp_kses( apply_filters( 'user_registration_queued_js', $js ), array( 'script' => array( 'type' => true ) ) );

		unset( $ur_queued_js );
	}
}

/**
 * Enqueue UR js.
 *
 * @param string $code Code to enqueue.
 *
 * @since 1.1.2
 * Queue some JavaScript code to be output in the footer.
 */
function ur_enqueue_js( $code ) {
	global $ur_queued_js;

	if ( empty( $ur_queued_js ) ) {
		$ur_queued_js = '';
	}

	$ur_queued_js .= "\n" . $code . "\n";
}

/**
 * Delete expired transients.
 *
 * Deletes all expired transients. The multi-table delete syntax is used.
 * to delete the transient record from table a, and the corresponding.
 * transient_timeout record from table b.
 *
 * Based on code inside core's upgrade_network() function.
 *
 * @return int Number of transients that were cleared.
 * @since  1.2.0
 */
function ur_delete_expired_transients() {
	global $wpdb;

	$rows = $wpdb->query(
		$wpdb->prepare(
			"DELETE a, b FROM $wpdb->options a, $wpdb->options b
			WHERE a.option_name LIKE %s
			AND a.option_name NOT LIKE %s
			AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
			AND b.option_value < %d",
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			time()
		)
	);

	$rows2 = $wpdb->query(
		$wpdb->prepare(
			"DELETE a, b FROM $wpdb->options a, $wpdb->options b
			WHERE a.option_name LIKE %s
			AND a.option_name NOT LIKE %s
			AND b.option_name = CONCAT( '_site_transient_timeout_', SUBSTRING( a.option_name, 17 ) )
			AND b.option_value < %d",
			$wpdb->esc_like( '_site_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
			time()
		)
	);

	return absint( $rows + $rows2 );
}

add_action( 'user_registration_installed', 'ur_delete_expired_transients' );

/**
 * String translation function.
 *
 * @param int    $form_id Form ID.
 * @param string $field_id Field ID.
 * @param mixed  $variable To be translated for WPML compatibility.
 *
 * @since 1.7.3
 */
function ur_string_translation( $form_id, $field_id, $variable ) {
	$context = ( isset( $form_id ) && 0 !== $form_id )
		? 'user_registration_' . absint( $form_id )
		: 'user-registration';
	$name    = isset( $field_id ) ? $field_id : '';

	// For handling translation in WPML.
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		if ( function_exists( 'icl_register_string' ) ) {
			icl_register_string( $context, $name, $variable );
			if ( function_exists( 'icl_t' ) ) {
				$variable = icl_t( $context, $name, $variable );
			}
		}
	} elseif ( defined( 'POLYLANG_VERSION' ) ) {
		// For handling translation in Polylang.
		if ( function_exists( 'pll_register_string' ) ) {
			pll_register_string( $name, $variable, $context );
			if ( function_exists( 'pll__' ) ) {
				$variable = pll__( $variable );
			}
		}
	}

	return $variable;
}


/**
 * Get Form ID from User ID.
 *
 * @param int $user_id User ID.
 *
 * @return int $form_id Form ID.
 */
function ur_get_form_id_by_userid( $user_id ) {
	$form_id_array = get_user_meta( $user_id, 'ur_form_id' );
	$form_id       = 0;

	if ( isset( $form_id_array[0] ) ) {
		$form_id = $form_id_array[0];
	}

	return $form_id;
}

/**
 * Get source ID through which the given user was supposedly registered.
 *
 * @param int $user_id User ID.
 *
 * @return mixed
 * @since 1.9.0
 */
function ur_get_registration_source_id( $user_id ) {
	$user_metas = get_user_meta( $user_id );

	if ( isset( $user_metas['user_registration_social_connect_bypass_current_password'] ) ) {
		$networks = array( 'facebook', 'linkedin', 'google', 'twitter' );

		foreach ( $networks as $network ) {

			if ( isset( $user_metas[ 'user_registration_social_connect_' . $network . '_username' ] ) ) {
				return $network;
			}
		}
	} elseif ( isset( $user_metas['ur_form_id'] ) ) {
		return $user_metas['ur_form_id'][0];
	} else {
		return null;
	}
}

/**
 * Check if a datetime falls in a range of time.
 *
 * @param string      $target_date Target date.
 * @param string|null $start_date Start date.
 * @param string|null $end_date End date.
 *
 * @return bool
 * @since 1.9.0
 */
function ur_falls_in_date_range( $target_date, $start_date = null, $end_date = null ) {
	$start_ts       = strtotime( $start_date );
	$end_ts         = strtotime( $end_date . ' +1 Day' );
	$target_date_ts = strtotime( $target_date );

	// If the starting and the ending date are set as same.
	if ( $start_ts === $end_ts ) {
		$datetime = new DateTime();
		$datetime->setTimestamp( $end_ts );

		date_add( $datetime, date_interval_create_from_date_string( '23 hours 59 mins 59 secs' ) );
		$end_ts = $datetime->getTimestamp();
	}

	if ( $start_date && $end_date ) {
		return ( $start_ts <= $target_date_ts ) && ( $target_date_ts <= $end_ts );
	} elseif ( $start_date ) {
		return ( $start_ts <= $target_date_ts );
	} elseif ( $end_date ) {
		return ( $target_date_ts <= $end_ts );
	} else {
		return false;
	}
}

/**
 * Get Post Content By Form ID.
 *
 * @param int    $form_id Form Id.
 * @param string $form_status The form status.
 *
 * @return array|mixed|null|object
 */
function ur_get_post_content( $form_id, $form_status = 'publish' ) {
	$args      = array(
		'post_type'   => 'user_registration',
		'post_status' => $form_status,
		'post__in'    => array( $form_id ),
	);
	$post_data = get_posts( $args );

	if ( isset( $post_data[0]->post_content ) ) {

		return json_decode( $post_data[0]->post_content );
	} else {

		return array();
	}
}

/**
 * A wp_parse_args() for multi-dimensional array.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_parse_args/
 *
 * @since 1.9.0
 *
 * @param array $args Value to merge with $defaults.
 * @param array $defaults Array that serves as the defaults.
 *
 * @return array    Merged user defined values with defaults.
 */
function ur_parse_args( &$args, $defaults ) {
	$args     = (array) $args;
	$defaults = (array) $defaults;
	$result   = $defaults;
	foreach ( $args as $k => &$v ) {
		if ( is_array( $v ) && isset( $result[ $k ] ) ) {
			$result[ $k ] = ur_parse_args( $v, $result[ $k ] );
		} else {
			$result[ $k ] = $v;
		}
	}

	return $result;
}

/**
 * Override email content for specific form.
 *
 * @param int    $form_id Form Id.
 * @param object $settings Settings for specific email.
 * @param string $message Message to be sent in email body.
 * @param string $subject Subject of the email.
 *
 * @return array
 */
function user_registration_email_content_overrider( $form_id, $settings, $message, $subject ) {
	// Check if email templates addon is active.
	if ( class_exists( 'User_Registration_Email_Templates' ) ) {
		$email_content_override = ur_get_single_post_meta( $form_id, 'user_registration_email_content_override', '' );

		// Check if the post meta exists and have contents.
		if ( $email_content_override ) {

			$auto_password_template_overrider = isset( $email_content_override[ $settings->id ] ) ? $email_content_override[ $settings->id ] : '';

			// Check if the email override is enabled.
			if ( '' !== $auto_password_template_overrider && ur_string_to_bool( $auto_password_template_overrider['override'] ) ) {
				$message = $auto_password_template_overrider['content'];
				$subject = $auto_password_template_overrider['subject'];
			}
		}
	}

	return array( $message, $subject );
}

/** Get User Data in particular array format.
 *
 * @param string $new_string Field Key.
 * @param string $post_key Post Key.
 * @param array  $profile Form Data.
 * @param mixed  $value Value.
 */
function ur_get_valid_form_data_format( $new_string, $post_key, $profile, $value ) {
	$valid_form_data = array();
	if ( isset( $profile[ $post_key ] ) ) {
		$field_type = $profile[ $post_key ]['type'];

		if ( 'repeater' === $field_type ) {
			return $valid_form_data;
		}
		switch ( $field_type ) {
			case 'checkbox':
			case 'multi_select2':
				if ( ! is_array( $value ) && ! empty( $value ) ) {
					$value = ur_maybe_unserialize( $value );
				}
				break;
			case 'file':
				$files = is_array( $value ) ? $value : explode( ',', $value );

				if ( is_array( $files ) && isset( $files[0] ) ) {
					$attachment_ids = '';

					foreach ( $files as $key => $file ) {
						$seperator = 0 < $key ? ',' : '';

						if ( wp_http_validate_url( $file ) ) {
							$attachment_ids = $attachment_ids . '' . $seperator . '' . attachment_url_to_postid( $file );
						}
					}
					$value = ! empty( $attachment_ids ) ? $attachment_ids : $value;
				} elseif ( wp_http_validate_url( $value ) ) {

					$value = attachment_url_to_postid( $value );
				}
				break;
		}

		$valid_form_data[ $new_string ]               = new stdClass();
		$valid_form_data[ $new_string ]->field_name   = $new_string;
		$valid_form_data[ $new_string ]->value        = $value;
		$valid_form_data[ $new_string ]->field_type   = $profile[ $post_key ]['type'];
		$valid_form_data[ $new_string ]->label        = $profile[ $post_key ]['label'];
		$valid_form_data[ $new_string ]->extra_params = array(
			'field_key' => $profile[ $post_key ]['field_key'],
			'label'     => $profile[ $post_key ]['label'],
		);
	} else {
		$valid_form_data[ $new_string ]               = new stdClass();
		$valid_form_data[ $new_string ]->field_name   = $new_string;
		$valid_form_data[ $new_string ]->value        = $value;
		$valid_form_data[ $new_string ]->extra_params = array(
			'field_key' => $new_string,
		);
	}

	return $valid_form_data;
}

/**
 * Add our login and my account shortcodes to conflicting shortcodes filter of All In One Seo plugin to resolve the conflict
 *
 * @param array $conflict_shortcodes Array of shortcodes that All in one Seo is conflicting with.
 *
 * @since 1.9.4
 */
function ur_resolve_conflicting_shortcodes_with_aioseo( $conflict_shortcodes ) {
	$ur_shortcodes = array(
		'User Registration My Account' => '[user_registration_my_account]',
		'User Registration Login'      => '[user_registration_login]',
	);

	$conflict_shortcodes = array_merge( $conflict_shortcodes, $ur_shortcodes );

	return $conflict_shortcodes;
}

add_filter( 'aioseo_conflicting_shortcodes', 'ur_resolve_conflicting_shortcodes_with_aioseo' );

/**
 * Parse name values and smart tags
 *
 * @param int   $user_id User ID.
 * @param int   $form_id Form ID.
 * @param array $valid_form_data Form filled data.
 *
 * @return array
 * @since 1.9.6
 */
function ur_parse_name_values_for_smart_tags( $user_id, $form_id, $valid_form_data ) {

	$name_value = array();
	$data_html  = '<table class="user-registration-email__entries" cellpadding="0" cellspacing="0"><tbody>';

	// Generate $data_html string to replace for {{all_fields}} smart tag.
	foreach ( $valid_form_data as $field_meta => $form_data ) {

		if ( 'user_confirm_password' === $field_meta || 'user_pass' === $field_meta || preg_match( '/password_/', $field_meta ) ) {
			continue;
		}

		// Donot include privacy policy value.
		if ( isset( $form_data->extra_params['field_key'] ) && 'privacy_policy' === $form_data->extra_params['field_key'] ) {
			continue;
		}

		if ( isset( $form_data->extra_params['field_key'] ) && 'country' === $form_data->extra_params['field_key'] && '' !== $form_data->value ) {
			$is_json = preg_match( '/^\{.*\}$/s', $form_data->value ) ? true : false;
			if ( $is_json ) {
				$country_data = json_decode( $form_data->value, true );
				$country_code = isset( $country_data['country'] ) ? $country_data['country'] : '';
				$state_code   = isset( $country_data['state'] ) ? $country_data['state'] : '';

				$form_data->value = ur_format_country_field_data( $country_code, $state_code );
			} else {
				$country_class    = ur_load_form_field_class( $form_data->extra_params['field_key'] );
				$countries        = $country_class::get_instance()->get_country();
				$form_data->value = isset( $countries[ $form_data->value ] ) ? $countries[ $form_data->value ] : $form_data->value;
			}
		}
		/**
		 * Filter hook allows developers to modify the parsed values for smart tags
		 * during the user registration process. It provides an opportunity to customize
		 * the values based on the form data.
		 *
		 * @param array $form_data An array of form data used for parsing smart tags.
		 */
		$form_data = apply_filters( 'user_registration_parse_values_for_smart_tags', $form_data );

		if ( is_array( $form_data ) ) {
			$label      = isset( $form_data['label'] ) ? $form_data['label'] : '';
			$field_name = isset( $form_data['field_key'] ) ? $form_data['field_key'] : '';
			$value      = isset( $form_data['default'] ) ? $form_data['default'] : '';
			if ( 'checkbox' === $field_name && ! empty( $value ) ) {
				$unserialized_value = unserialize( $form_data['default'] );

				if ( is_array( $unserialized_value ) ) {
					$value = implode( ', ', $unserialized_value );
				} else {
					$value = (string) $unserialized_value;
				}
			}
		} else {
			$label      = isset( $form_data->extra_params['label'] ) ? $form_data->extra_params['label'] : '';
			$field_name = isset( $form_data->field_name ) ? $form_data->field_name : '';
			$value      = isset( $form_data->value ) ? $form_data->value : '';
		}

		if ( 'user_pass' === $field_meta ) {
			$value = __( 'Chosen Password', 'user-registration' );
		}

		// Check if value contains array.
		if ( is_array( $value ) ) {
			$value = ! isset( $value['row_1'] ) ? implode( ',', $value ) : '';
		}

		$data_html .= '<tr class="user-registration-email__entries-tr">';

		if ( isset( $form_data->field_type ) && 'repeater' === $form_data->field_type ) {
			$data_html .= '<td class="user-registration-email__entries-data">' . $value . '</td></tr>';
		} elseif ( isset( $form_data->extra_params['field_key'] ) && 'signature' === $form_data->extra_params['field_key'] ) {
			$data_html .= '<td class="user-registration-email__entries-label">' . $label . ' : </td><td class="user-registration-email__entries-data"><img class="profile-preview" alt="Signature" width="50px" height="50px" src="' . ( is_numeric( $value ) ? esc_url( wp_get_attachment_url( $value ) ) : esc_url( $value ) ) . '" /></td></tr>';
		} else {
			$data_html .= '<td class="user-registration-email__entries-label">' . $label . ' : </td><td class="user-registration-email__entries-data">' . $value . '</td></tr>';
		}

		$name_value[ $field_name ] = $value;
	}

	$data_html .= '</tbody></table>';
	/**
	 * Filters the processed values for a smart tag.
	 *
	 * @param array $name_value An array of name-value pairs representing the smart tag.
	 * @param array $valid_form_data An array of valid form data used in the registration process.
	 * @param int $form_id The ID of the user registration form.
	 * @param int $user_id The user ID associated with the registration process.
	 */
	$name_value = apply_filters( 'user_registration_process_smart_tag', $name_value, $valid_form_data, $form_id, $user_id );

	return array( $name_value, $data_html );
}

/**
 * Get field data by field_name.
 *
 * @param int    $form_id Form Id.
 * @param string $field_name Field Name.
 *
 * @return array
 */
function ur_get_field_data_by_field_name( $form_id, $field_name ) {
	$field_data = array();

	$post_content_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();

	foreach ( $post_content_array as $post_content_row ) {
		foreach ( $post_content_row as $post_content_grid ) {
			if ( is_array( $post_content_grid ) || is_object( $post_content_grid ) ) {
				foreach ( $post_content_grid as $field ) {
					if ( isset( $field->field_key ) && isset( $field->general_setting->field_name ) && $field->general_setting->field_name === $field_name ) {
						$field_data = array(
							'field_key'       => $field->field_key,
							'general_setting' => $field->general_setting,
							'advance_setting' => $field->advance_setting,
						);
					}
				}
			}
		}
	}

	return $field_data;
}

if ( ! function_exists( 'user_registration_get_form_fields_for_dropdown' ) ) {
	/**
	 * Get form fields array for dropdown
	 *
	 * @param int $form_id Form ID.
	 */
	function user_registration_get_form_fields_for_dropdown( $form_id ) {
		$get_all_fields = user_registration_pro_get_conditional_fields_by_form_id( $form_id, '' );
		$field_array    = array();
		if ( isset( $get_all_fields ) ) {
			foreach ( $get_all_fields as $key => $field ) {
				if ( 'phone' === $field['field_key'] ) {
					$field_array[ $key ] = $field['label'];
				}
			}
		}

		return $field_array;
	}
}

if ( ! function_exists( 'user_registration_pro_get_conditional_fields_by_form_id' ) ) {
	/**
	 * Get form fields by form id
	 *
	 * @param int    $form_id Form ID.
	 * @param string $selected_field_key Field Key.
	 */
	function user_registration_pro_get_conditional_fields_by_form_id( $form_id, $selected_field_key ) {
		$args      = array(
			'post_type'   => 'user_registration',
			'post_status' => array( 'publish', 'draft' ),
			'post__in'    => array( $form_id ),
		);
		$post_data = get_posts( $args );
		// wrap all fields in array.
		$fields = array();
		if ( isset( $post_data[0]->post_content ) ) {
			$post_content_array = json_decode( $post_data[0]->post_content );

			if ( ! is_null( $post_content_array ) ) {
				foreach ( $post_content_array as $data ) {
					foreach ( $data as $single_data ) {
						foreach ( $single_data as $field_data ) {
							if (
								isset( $field_data->general_setting->field_name )
								&& isset( $field_data->general_setting->label )
							) {

								$strip_fields = array(
									'section_title',
									'html',
									'wysiwyg',
									'billing_address_title',
									'shipping_address_title',
									'stripe_gateway',
									'authorize_net_gateway',
									'profile_picture',
									'file',
								);

								if ( isset( $field_data->field_key ) && in_array( $field_data->field_key, $strip_fields, true ) ) {
									continue;
								}

								$fields[ $field_data->general_setting->field_name ] = array(
									'label'     => $field_data->general_setting->label,
									'field_key' => isset( $field_data->field_key ) ? $field_data->field_key : '',
								);
							}
						}
					}
				}
			}
		}
		// Unset selected meta key.
		unset( $fields[ $selected_field_key ] );

		return $fields;
	}
}

if ( ! function_exists( 'user_registration_pro_render_conditional_logic' ) ) {
	/**
	 * Render Conditional Logic in form settings of form builder.
	 *
	 * @param array  $connection Connection Data.
	 * @param string $integration Integration.
	 * @param int    $form_id Form ID.
	 *
	 * @return string
	 */
	function user_registration_pro_render_conditional_logic( $connection, $integration, $form_id ) {
		$output  = '<div class="ur_conditional_logic_container">';
		$output .= '<div class="form-row ur-form-settings-section ur-form-settings-' . $integration . '-section">';
		$output .= '<div class="ur-form-settings-section--field">';
		$output .= '<h4>' . esc_html__( 'Conditional Logic', 'user-registration' ) . '</h4>';
		$output .= '</div>';
		$output .= '</div>';
		$checked = '';

		if ( isset( $connection['enable_conditional_logic'] ) && ur_string_to_bool( $connection['enable_conditional_logic'] ) ) {
			$checked = 'checked=checked';
		}
		$output .= '<div class="form-row ur-toggle-section ur-form-builder-toggle ur-form-builder-' . $integration . '-toggle">';
		$output .= '<label class="ur-label checkbox">' . esc_html__( 'Use Conditional Logics', 'user-registration' ) . '</label>';
		$output .= '<div class="ur-settings-field">';
		$output .= '<span class="user-registration-toggle-form">';
		$output .= '<input class="ur-use-conditional-logic" type="checkbox" name="ur_use_conditional_logic" id="ur_use_conditional_logic" ' . $checked . '>';
		$output .= '<span class="slider round">';
		$output .= '</span>';
		$output .= '</span>';
		$output .= '</div>';
		$output .= '</div>';

		// Keep the rules hidden initially when conditional logic is disabled,
		// so the "Conditional Rules" section only shows once the toggle is on.
		$wrapper_style = $checked ? '' : ' style="display:none;"';
		$output       .= '<div class="form-row ur_conditional_logic_wrapper" data-source="' . esc_attr( $integration ) . '"' . $wrapper_style . '>';

		$output                .= '<label class="ur-label checkbox">' . esc_html__( 'Conditional Rules', 'user-registration' ) . '</label>';
		$output                .= '<div class="ur-logic"><p>' . esc_html__( 'Send data only if the following matches.', 'user-registration' ) . '</p></div>';
		$output                .= '<div class="ur-conditional-wrapper">';
		$output                .= '<select class="ur_conditional_field" name="ur_conditional_field">';
		$get_all_fields         = user_registration_pro_get_conditional_fields_by_form_id( $form_id, '' );
		$selected_ur_field_type = '';

		if ( isset( $get_all_fields ) ) {

			foreach ( $get_all_fields as $key => $field ) {
				$selected_attr = '';

				if ( isset( $connection['conditional_logic_data']['conditional_field'] ) && $connection['conditional_logic_data']['conditional_field'] === $key ) {
					$selected_attr          = 'selected=selected';
					$selected_ur_field_type = $field['field_key'];
				}
				$output .= '<option data-type="' . esc_attr( $field['field_key'] ) . '" data-label="' . esc_attr( $field['label'] ) . '" value="' . esc_attr( $key ) . '" ' . $selected_attr . '>' . esc_html( $field['label'] ) . '</option>';
			}
		}
		$output .= '</select>';
		$output .= '<select class="ur-conditional-condition" name="ur-conditional-condition">';
		$output .= '<option value="is" ' . ( isset( $connection['conditional_logic_data']['conditional_operator'] ) && 'is' === $connection['conditional_logic_data']['conditional_operator'] ? 'selected' : '' ) . '> is </option>';
		$output .= '<option value="is_not" ' . ( isset( $connection['conditional_logic_data']['conditional_operator'] ) && 'is_not' === $connection['conditional_logic_data']['conditional_operator'] ? 'selected' : '' ) . '> is not </option>';
		$output .= '</select>';

		if ( 'checkbox' == $selected_ur_field_type || 'radio' == $selected_ur_field_type || 'select' == $selected_ur_field_type || 'country' == $selected_ur_field_type || 'billing_country' == $selected_ur_field_type || 'shipping_country' == $selected_ur_field_type || 'select2' == $selected_ur_field_type || 'multi_select2' == $selected_ur_field_type ) {
			$choices = user_registration_pro_get_checkbox_choices( $form_id, $connection['conditional_logic_data']['conditional_field'] );
			$output .= '<select name="ur-conditional-input" class="ur-conditional-input">';

			if ( is_array( $choices ) && array_filter( $choices ) ) {
				$output .= '<option>--select--</option>';

				foreach ( $choices as $key => $choice ) {
					$key           = 'country' == $selected_ur_field_type ? $key : $choice;
					$selectedvalue = isset( $connection['conditional_logic_data']['conditional_value'] ) && $connection['conditional_logic_data']['conditional_value'] == $key ? 'selected="selected"' : '';
					$output       .= '<option ' . $selectedvalue . ' value="' . esc_attr( $key ) . '">' . esc_html( $choice ) . '</option>';
				}
			} else {
				$selected = isset( $connection['conditional_logic_data']['conditional_value'] ) ? $connection['conditional_logic_data']['conditional_value'] : 0;
				$output  .= '<option value="1" ' . ( ur_string_to_bool( $selected ) ? 'selected="selected"' : '' ) . ' >' . esc_html__( 'Checked', 'user-registration' ) . '</option>';
			}
			$output .= '</select>';
		} else {
			$value   = isset( $connection['conditional_logic_data']['conditional_value'] ) ? $connection['conditional_logic_data']['conditional_value'] : '';
			$output .= '<input class="ur-conditional-input" type="text" name="ur-conditional-input" value="' . esc_attr( $value ) . '">';
		}
		$output .= '</div>';
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}
}


if ( ! function_exists( 'user_registration_pro_get_checkbox_choices' ) ) {
	/**
	 * Get Select and Checkbox Fields Choices
	 *
	 * @param int    $form_id Form ID.
	 * @param string $field_name Field Name.
	 *
	 * @return array $choices
	 */
	function user_registration_pro_get_checkbox_choices( $form_id, $field_name ) {

		$form_data = (object) user_registration_pro_get_field_data( $form_id, $field_name );
		/* Backward Compatibility. Modified since 1.5.7. To be removed later. */
		$advance_setting_choices = isset( $form_data->advance_setting->choices ) ? $form_data->advance_setting->choices : '';
		$advance_setting_options = isset( $form_data->advance_setting->options ) ? $form_data->advance_setting->options : '';
		/* Bacward Compatibility end.*/

		$choices = isset( $form_data->general_setting->options ) ? $form_data->general_setting->options : '';

		/* Backward Compatibility. Modified since 1.5.7. To be removed later. */
		if ( ! empty( $advance_setting_choices ) ) {
			$choices = explode( ',', $advance_setting_choices );
		} elseif ( ! empty( $advance_setting_options ) ) {
			$choices = explode( ',', $advance_setting_options );
			/* Backward Compatibility end. */
		} elseif ( 'country' === $form_data->field_key ) {
			$country = new UR_Form_Field_Country();
			$country->get_country();
			$choices = $country->get_country();
		}

		return $choices;
	}
}

if ( ! function_exists( 'user_registration_pro_get_field_data' ) ) {
	/**
	 * Get all fields data
	 *
	 * @param int    $form_id Form ID.
	 * @param string $field_name Field Name.
	 *
	 * @return array    $field_data.
	 */
	function user_registration_pro_get_field_data( $form_id, $field_name ) {
		$args      = array(
			'post_type'   => 'user_registration',
			'post_status' => 'publish',
			'post__in'    => array( $form_id ),
		);
		$post_data = get_posts( $args );

		if ( isset( $post_data[0]->post_content ) ) {
			$post_content_array = json_decode( $post_data[0]->post_content );

			foreach ( $post_content_array as $data ) {
				foreach ( $data as $single_data ) {
					foreach ( $single_data as $field_data ) {
						isset( $field_data->general_setting->field_name ) ? $field_data->general_setting->field_name : '';
						if ( $field_data->general_setting->field_name === $field_name ) {
							return $field_data;
						}
					}
				}
			}
		}
	}
}

if ( ! function_exists( 'ur_install_extensions' ) ) {
	/**
	 * This function return boolean according to string to avoid colision of 1, true, yes.
	 *
	 * @param [string] $name Name of the extension.
	 * @param [string] $slug Slug of the extension.
	 *
	 * @throws Exception Extension Download and activation unsuccessful message.
	 */
	function ur_install_extensions( $name, $slug ) {
		try {
			$plugin = 'user-registration-pro' === $slug ? plugin_basename( sanitize_text_field( wp_unslash( $slug . '/user-registration.php' ) ) ) : plugin_basename( sanitize_text_field( wp_unslash( $slug . '/' . $slug . '.php' ) ) );
			$status = array(
				'install' => 'plugin',
				'slug'    => sanitize_key( wp_unslash( $slug ) ),
			);

			if ( ! current_user_can( 'install_plugins' ) ) {
				$status['errorMessage'] = esc_html__( 'Sorry, you are not allowed to install plugins on this site.', 'user-registration' );

				/* translators: %1$s: Activation error message */
				throw new Exception( sprintf( __( '<strong>Activation error:</strong> %1$s', 'user-registration' ), $status['errorMessage'] ) );
			}

			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

			if ( file_exists( WP_PLUGIN_DIR . '/' . $slug ) ) {
				$plugin_data          = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
				$status['plugin']     = $plugin;
				$status['pluginName'] = $plugin_data['Name'];

				if ( current_user_can( 'activate_plugin', $plugin ) && is_plugin_inactive( $plugin ) ) {
					$result = activate_plugin( $plugin );

					if ( is_wp_error( $result ) ) {
						$status['errorCode']    = $result->get_error_code();
						$status['errorMessage'] = $result->get_error_message();

						/* translators: %1$s: Activation error message */
						throw new Exception( sprintf( __( '<strong>Activation error:</strong> %1$s', 'user-registration' ), $status['errorMessage'] ) );
					}

					$status['success'] = true;
					$status['message'] = $name . ' has been installed and activated successfully';

					return $status;
				}
			}

			$api = json_decode(
				UR_Updater_Key_API::version(
					array(
						'license'   => get_option( 'user-registration_license_key' ),
						'item_name' => $name,
						'item_id'   => 167196,
					)
				)
			);

			if ( is_wp_error( $api ) ) {
				$status['errorMessage'] = $api->get_error_message();

				/* translators: %1$s: Activation error message */
				throw new Exception( sprintf( __( '<strong>Activation error:</strong> %1$s', 'user-registration' ), $status['errorMessage'] ) );
			}

			$status['pluginName'] = $api->name;
			$api->version         = isset( $api->new_version ) ? $api->new_version : '1.0.0';

			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $api->download_link );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$status['debug'] = $skin->get_upgrade_messages();
			}

			if ( is_wp_error( $result ) ) {
				$status['errorCode']    = $result->get_error_code();
				$status['errorMessage'] = $result->get_error_message();

				/* translators: %1$s: Activation error message */
				throw new Exception( sprintf( __( '<strong>Activation error:</strong> %1$s', 'user-registration' ), $status['errorMessage'] ) );
			} elseif ( is_wp_error( $skin->result ) ) {
				$status['errorCode']    = $skin->result->get_error_code();
				$status['errorMessage'] = $skin->result->get_error_message();

				/* translators: %1$s: Activation error message */
				throw new Exception( sprintf( __( '<strong>Activation error:</strong> %1$s', 'user-registration' ), $status['errorMessage'] ) );
			} elseif ( $skin->get_errors()->get_error_code() ) {
				$status['errorMessage'] = $skin->get_error_messages();

				/* translators: %1$s: Activation error message */
				throw new Exception( sprintf( __( '<strong>Activation error:</strong> %1$s', 'user-registration' ), $status['errorMessage'] ) );
			} elseif ( is_null( $result ) ) {
				global $wp_filesystem;

				$status['errorCode']    = 'unable_to_connect_to_filesystem';
				$status['errorMessage'] = esc_html__( 'Unable to connect to the filesystem. Please confirm your credentials.', 'user-registration' );

				// Pass through the error from WP_Filesystem if one was raised.
				if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->get_error_code() ) {
					$status['errorMessage'] = esc_html( $wp_filesystem->errors->get_error_message() );
				}

				/* translators: %1$s: Activation error message */
				throw new Exception( sprintf( __( '<strong>Activation error:</strong> %1$s', 'user-registration' ), $status['errorMessage'] ) );
			}

			$install_status = install_plugin_install_status( $api );

			if ( current_user_can( 'activate_plugin', $install_status['file'] ) ) {
				if ( is_plugin_inactive( $install_status['file'] ) ) {
					if ( 'user-registration-pro/user-registration.php' === $install_status['file'] ) {
						$status['plugin'] = 'user-registration-pro/user-registration.php';
						if ( ! is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
							setcookie( 'urm_license_status', 'pro_activated', time() + 300, '/', '', is_ssl(), false );
						}
						activate_plugin( $install_status['file'] );
					}
					$status['activateUrl'] =
						esc_url_raw(
							add_query_arg(
								array(
									'action'   => 'activate',
									'plugin'   => $install_status['file'],
									'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $install_status['file'] ),
								),
								admin_url( 'admin.php?page=user-registration-addons' )
							)
						);
				} else {
					$status['deActivateUrl'] =
						esc_url_raw(
							add_query_arg(
								array(
									'action'   => 'deactivate',
									'plugin'   => $install_status['file'],
									'_wpnonce' => wp_create_nonce( 'deactivate-plugin_' . $install_status['file'] ),
								),
								admin_url( 'admin.php?page=user-registration-addons' )
							)
						);
				}
			}

			$status['success'] = true;
			$status['message'] = $name . ' has been installed and activated successfully';

			return $status;
		} catch ( Exception $e ) {

			$message           = $e->getMessage();
			$status['success'] = false;
			$status['message'] = $message;

			return $status;
		}
	}
}

add_action( 'user_registration_init', 'ur_profile_picture_migration_script' );

if ( ! function_exists( 'ur_profile_picture_migration_script' ) ) {

	/**
	 * Update usermeta from profile_pic_url to attachemnt id and move files to new directory.
	 *
	 * @since 1.5.0.
	 */
	function ur_profile_picture_migration_script() {

		if ( ! get_option( 'ur_profile_picture_migrated', false ) ) {

			$users = get_users(
				array(
					'meta_key' => 'user_registration_profile_pic_url',
				)
			);

			foreach ( $users as $user ) {
				$user_registration_profile_pic_url = get_user_meta( $user->ID, 'user_registration_profile_pic_url', true );

				if ( ! is_numeric( $user_registration_profile_pic_url ) ) {
					$user_registration_profile_pic_attachment = attachment_url_to_postid( $user_registration_profile_pic_url );
					if ( 0 != $user_registration_profile_pic_attachment ) {
						update_user_meta( $user->ID, 'user_registration_profile_pic_url', absint( $user_registration_profile_pic_attachment ) );
					}
				}
			}

			update_option( 'ur_profile_picture_migrated', true );
		}
	}
}

add_action( 'user_registration_init', 'ur_size_to_limit_length_migration_script' );

if ( ! function_exists( 'ur_size_to_limit_length_migration_script' ) ) {

	/**
	 * Update text field advance settings from size to limit length.
	 *
	 * @since 3.1.2.
	 */
	function ur_size_to_limit_length_migration_script() {

		if ( ! get_option( 'ur_size_to_limit_length_migrated', false ) ) {

			$all_forms = ur_get_all_user_registration_form();

			foreach ( $all_forms as $key => $value ) {

				$form_id            = $key;
				$post               = ( $form_id ) ? get_post( $form_id ) : '';
				$post_content       = isset( $post->post_content ) ? $post->post_content : '';
				$post_content_array = json_decode( $post_content );

				foreach ( $post_content_array as $post_content_row ) {
					foreach ( $post_content_row as $post_content_grid ) {
						foreach ( $post_content_grid as $field ) {

							if ( isset( $field->field_key ) && 'text' === $field->field_key ) {
								if ( isset( $field->advance_setting ) ) {
									if ( isset( $field->advance_setting->size ) && ! empty( $field->advance_setting->size ) ) {
										$field->advance_setting->limit_length             = true;
										$field->advance_setting->limit_length_limit_count = $field->advance_setting->size;
										$field->advance_setting->limit_length_limit_mode  = 'characters';
									}
								}
							}
						}
					}
					$post_content       = wp_json_encode( $post_content_array );
					$post->post_content = $post_content;
				}
				wp_update_post( $post );
			}

			update_option( 'ur_size_to_limit_length_migrated', true );
		}
	}
}

add_action( 'delete_user', 'ur_delete_user_files_on_user_delete', 10, 3 );

if ( ! function_exists( 'ur_delete_user_files_on_user_delete' ) ) {

	/**
	 * Delete user uploaded files when user is deleted.
	 *
	 * @param [type] $user_id User Id.
	 * @param [type] $reassign  Reassign to another user ( admin ).
	 * @param [type] $user User Data.
	 */
	function ur_delete_user_files_on_user_delete( $user_id, $reassign, $user ) {

		// Return if reassign is set.
		if ( null !== $reassign ) {
			return;
		}

		// Delete user uploaded file when user is deleted.
		if ( class_exists( 'URFU_Uploaded_Data' ) ) {
			$post = get_post( ur_get_form_id_by_userid( $user_id ) );

			$form_data_object = json_decode( $post->post_content );

			$file_fields = URFU_Uploaded_Data::get_file_field( $form_data_object );

			foreach ( $file_fields as $field ) {

				$meta_key = isset( $field['key'] ) ? $field['key'] : '';

				$attachment_ids = get_user_meta( $user->ID, 'user_registration_' . $meta_key, true );

				if ( is_string( $attachment_ids ) ) {
					$attachment_ids = explode( ',', $attachment_ids );
				}

				foreach ( $attachment_ids as $attachment_id ) {
					$file_path = get_attached_file( $attachment_id );

					if ( file_exists( $file_path ) ) {
						unlink( $file_path );
					}
				}
			}
		}

		// Delete user uploaded profile image when user is deleted.
		$profile_pic_attachment_id = get_user_meta( $user_id, 'user_registration_profile_pic_url', true );

		$pic_path = get_attached_file( $profile_pic_attachment_id );

		if ( file_exists( $pic_path ) ) {
			unlink( $pic_path );
		}
	}
}

if ( ! function_exists( 'ur_format_field_values' ) ) {

	/**
	 * Get field type by meta key
	 *
	 * @param int    $field_meta_key Field key or meta key.
	 * @param string $field_value Field's value .
	 */
	function ur_format_field_values( $field_meta_key, $field_value ) {
		if ( strpos( $field_meta_key, 'user_registration_' ) ) {
			$field_meta_key = substr( $field_meta_key, 0, strpos( $field_meta_key, 'user_registration_' ) );
		}

		$user_id = isset( $_GET['user'] ) ? sanitize_text_field( wp_unslash( $_GET['user'] ) ) : get_current_user_id(); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = isset( $_GET['user_id'] ) ? sanitize_text_field( wp_unslash( $_GET['user_id'] ) ) : $user_id; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : ur_get_form_id_by_userid( $user_id ); //phpcs:ignore.

		$field_name = ur_get_field_data_by_field_name( $form_id, $field_meta_key );

		$field_key   = isset( $field_name['field_key'] ) ? $field_name['field_key'] : '';
		$field_value = ur_format_field_values_using_field_key( $field_key, $field_value );

		return $field_value;
	}
}

if ( ! function_exists( 'ur_generate_required_pages' ) ) {
	/**
	 * Generate required pages based on missing page options.
	 *
	 * @param string $missing_pages JSON string of missing page options.
	 *
	 * @return array|WP_Error Array of created pages or WP_Error on failure.
	 */
	function ur_generate_required_pages( $missing_pages ) {
		// Include admin functions for ur_create_page.
		include_once untrailingslashit( plugin_dir_path( UR_PLUGIN_FILE ) ) . '/includes/admin/functions-ur-admin.php';

		$missing_pages_array = json_decode( $missing_pages, true );

		if ( ! is_array( $missing_pages_array ) ) {
			return new WP_Error( 'invalid_pages', __( 'Invalid pages data provided.', 'user-registration' ) );
		}

		// Check if membership module is activated.
		$is_membership_activated = ur_check_module_activation( 'membership' );

		// Define page configurations.
		$page_configs = array(
			'user_registration_login_page_id'              => array(
				'name'                => 'login',
				'title'               => __( 'Login', 'user-registration' ),
				'content'             => '[user_registration_login]',
				'requires_membership' => false,
			),
			'user_registration_lost_password_page_id'      => array(
				'name'                => 'lost-password',
				'title'               => __( 'Lost Password', 'user-registration' ),
				'content'             => '[user_registration_lost_password]',
				'requires_membership' => false,
			),
			'user_registration_member_registration_page_id' => array(
				'name'                => 'membership-registration',
				'title'               => __( 'Membership Registration', 'user-registration' ),
				'content'             => '[user_registration_form id="' . get_option( 'user_registration_default_form_page_id', 0 ) . '"]',
				'requires_membership' => true,
			),
			'user_registration_thank_you_page_id'          => array(
				// Same slug the setup wizard uses, so the thank-you URL does not depend on which flow created the page.
				'name'                => 'thankyou',
				'title'               => __( 'Membership Thank You', 'user-registration' ),
				'content'             => '[user_registration_membership_thank_you]',
				'requires_membership' => true,
			),
			'user_registration_myaccount_page_id'          => array(
				'name'                => 'my-account',
				'title'               => __( 'My Account', 'user-registration' ),
				'content'             => '[user_registration_my_account]',
				'requires_membership' => false,
			),
			'user_registration_membership_pricing_page_id' => array(
				'name'                => 'membership-pricing',
				'title'               => __( 'Membership Pricing', 'user-registration' ),
				'content'             => '[user_registration_groups]',
				'requires_membership' => true,
			),
		);

		$created_pages = array();

		foreach ( $missing_pages_array as $page_option ) {
			if ( ! isset( $page_configs[ $page_option ] ) ) {
				continue;
			}

			$config = $page_configs[ $page_option ];

			// Skip membership pages if membership module is not activated.
			if ( $config['requires_membership'] && ! $is_membership_activated ) {
				continue;
			}

			// Create the page.
			$page_id = ur_create_page(
				$config['name'],
				$page_option,
				$config['title'],
				$config['content']
			);

			if ( $page_id ) {
				$created_pages[] = array(
					'option'  => $page_option,
					'page_id' => $page_id,
					'title'   => $config['title'],
					'url'     => get_permalink( $page_id ),
				);
			}
		}

		return $created_pages;
	}
}

if ( ! function_exists( 'ur_format_field_values_using_field_key' ) ) {
	/**
	 * Format field values using field key.
	 *
	 * @param string $field_key Field key.
	 * @param mixed  $field_value Field Value.
	 */
	function ur_format_field_values_using_field_key( $field_key, $field_value ) {

		switch ( $field_key ) {
			case 'checkbox':
			case 'multi_select2':
				if ( empty( $field_value ) ) {
					$field_value = '';
				} elseif ( is_array( $field_value ) && ! empty( $field_value ) ) {
					$field_value = implode( ', ', $field_value );
				} elseif ( ! empty( json_decode( $field_value ) ) ) { // phpcs:ignore;
					$field_value = is_array( json_decode( $field_value ) ) ? implode( ', ', json_decode( $field_value ) ) : $field_value;
				}
				break;
			case 'country':
				$countries = UR_Form_Field_Country::get_instance()->get_country();
				if ( ! isset( $countries[ $field_value ] ) ) {
					$key = array_search( $field_value, $countries, true );
					if ( $key ) {
						$field_value = $key;
					}
				}
				$field_value = isset( $countries[ $field_value ] ) ? $countries[ $field_value ] : '';
				break;
			case 'file':
				$attachment_ids = is_array( $field_value ) ? $field_value : explode( ',', $field_value );
				$links          = array();

				foreach ( $attachment_ids as $attachment_id ) {
					if ( is_numeric( $attachment_id ) ) {
						$attachment_url = '<a href="' . wp_get_attachment_url( $attachment_id ) . '">' . basename( get_attached_file( $attachment_id ) ) . '</a>';
						array_push( $links, $attachment_url );
					} elseif ( ur_is_valid_url( $attachment_id ) ) {
						$attachment_url = '<a href="' . $attachment_id . '">' . $attachment_id . '</a>';
						array_push( $links, $attachment_url );
					} else {
						array_push( $links, $attachment_id );
					}
				}
				$field_value = implode( ', ', $links );

				break;
			case 'privacy_policy':
				if ( ur_string_to_bool( $field_value ) ) {
					$field_value = 'Checked';
				} else {
					$field_value = 'Not Checked';
				}
				break;
			case 'wysiwyg':
				$field_value = html_entity_decode( $field_value );
				break;
			case 'profile_picture':
				$field_value = '<img class="profile-preview" alt="Profile Picture" width="50px" height="50px" src="' . ( is_numeric( $field_value ) ? esc_url( wp_get_attachment_url( $field_value ) ) : esc_url( $field_value ) ) . '" />';
				$field_value = wp_kses_post( $field_value );
				break;
			case 'signature':
				$field_value = '<img class="profile-preview" alt="Signature" width="50px" height="50px" src="' . ( is_numeric( $field_value ) ? esc_url( wp_get_attachment_url( $field_value ) ) : esc_url( $field_value ) ) . '" />';
				$field_value = wp_kses_post( $field_value );
				break;
			default:
				$field_value = $field_value;
				break;
		}

		return $field_value;
	}
}

if ( ! function_exists( 'ur_find_my_account_in_page' ) ) {

	/**
	 * Find My Account Shortcode.
	 *
	 * @param int $login_page_id Login Page ID.
	 *
	 * @return int If matched then 1 else 0.
	 * @since  2.2.7
	 */
	function ur_find_my_account_in_page( $login_page_id ) {
		global $wpdb;
		$post_table      = $wpdb->prefix . 'posts';
		$post_meta_table = $wpdb->prefix . 'postmeta';

		$matched = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$post_table} WHERE ID = '{$login_page_id}' AND ( post_content LIKE '%[user_registration_login%' OR post_content LIKE '%[user_registration_my_account%' OR post_content LIKE '%[woocommerce_my_account%' OR post_content LIKE '%<!-- wp:user-registration/myaccount%' OR post_content LIKE '%<!-- wp:user-registration/login%')" ) //phpcs:ignore.
		);

		if ( $matched <= 0 ) {
			$matched = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$post_meta_table} WHERE post_id = '{$login_page_id}' AND ( meta_value LIKE '%[user_registration_login%' OR meta_value LIKE '%[user_registration_my_account%' OR meta_value LIKE '%[woocommerce_my_account%' OR meta_value LIKE '%<!-- wp:user-registration/myaccount%' OR meta_value LIKE '%<!-- wp:user-registration/login%' )" ) //phpcs:ignore.
			);
		}
		/**
		 * Filters the result of finding "My Account" in a page.
		 *
		 * @param bool $matched The result of finding "My Account" in a page.
		 * @param int $login_page_id The ID of the associated login page.
		 */
		$matched = apply_filters( 'user_registration_find_my_account_in_page', $matched, $login_page_id );

		return $matched;
	}
}

if ( ! function_exists( 'ur_find_lost_password_in_page' ) ) {

	/**
	 * Find Lost Password Shortcode.
	 *
	 * @param int $lost_password_page_id Lost Password Page ID.
	 *
	 * @return int If matched then 1 else 0.
	 * @since  4.0
	 */
	function ur_find_lost_password_in_page( $lost_password_page_id ) {
		global $wpdb;
		$post_table      = $wpdb->prefix . 'posts';
		$post_meta_table = $wpdb->prefix . 'postmeta';

		$matched = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$post_table} WHERE ID = '{$lost_password_page_id}' AND ( post_content LIKE '%[user_registration_lost_password%' OR post_content LIKE '%<!-- wp:user-registration/lost_password%' OR post_content LIKE '%<!-- wp:user-registration/lost_password%')" ) //phpcs:ignore.
		);

		if ( $matched <= 0 ) {
			$matched = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$post_meta_table} WHERE post_id = '{$lost_password_page_id}' AND ( meta_value LIKE '%[user_registration_lost_password%' OR meta_value LIKE '%<!-- wp:user-registration/lost_password%' OR meta_value LIKE '%<!-- wp:user-registration/lost_password%' )" ) //phpcs:ignore.
			);
		}
		/**
		 * Filters the result of finding "Lost Password" in a page.
		 *
		 * @param bool $matched The result of finding "Lost Password" in a page.
		 * @param int $lost_password_page_id The ID of the associated lost password page.
		 */
		$matched = apply_filters( 'user_registration_find_lost_password_in_page', $matched, $lost_password_page_id );

		return $matched;
	}
}

if ( ! function_exists( 'ur_find_reset_password_in_page' ) ) {
	/**
	 * Find whether the reset password form exists in the given page.
	 *
	 * @param int $reset_password_page_id The ID of the associated reset password page.
	 * @return bool Whether the "Reset Password" form was found in the page.
	 */
	function ur_find_reset_password_in_page( $reset_password_page_id ) {
		global $wpdb;

		$shortcode_like = '%[user_registration_reset_password_form%';
		$block_like     = '%<!-- wp:user-registration/reset_password_form%';

		// Check in post_content.
		$matched = (int) $wpdb->get_var(
			$wpdb->prepare(
				"
			SELECT COUNT(*)
			FROM {$wpdb->posts}
			WHERE ID = %d
			AND (
				post_content LIKE %s
				OR post_content LIKE %s
			)
			",
				$reset_password_page_id,
				$shortcode_like,
				$block_like
			)
		);

		// If not found in post_content, check postmeta.
		if ( $matched <= 0 ) {
			$matched = (int) $wpdb->get_var(
				$wpdb->prepare(
					"
				SELECT COUNT(*)
				FROM {$wpdb->postmeta}
				WHERE post_id = %d
				AND (
					meta_value LIKE %s
					OR meta_value LIKE %s
				)
				",
					$reset_password_page_id,
					$shortcode_like,
					$block_like
				)
			);
		}

		/**
		 * Filters whether the reset password form was found in this page.
		 *
		 * @param bool $matched                Whether the "Reset Password" form was found in the page.
		 * @param int  $reset_password_page_id The ID of the associated reset password page.
		 */
		$matched = apply_filters( 'user_registration_find_reset_password_in_page', $matched > 0, $reset_password_page_id );

		return (bool) $matched;
	}
}

if ( ! function_exists( 'ur_get_license_plan' ) ) {

	/**
	 * Get a license plan.
	 *
	 * @return bool|object Plan on success, false on failure.
	 * @since  2.2.4
	 */
	function ur_get_license_plan() {
		$license_key = get_option( 'user-registration_license_key' );

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( $license_key ) {
			$license_data = get_transient( 'ur_pro_license_plan' );

			if ( false === $license_data ) {
				$license_data = json_decode(
					UR_Updater_Key_API::check(
						array(
							'license' => $license_key,
						)
					)
				);

				if ( ! empty( $license_data->item_name ) ) {
					$license_data->item_plan = strtolower( str_replace( 'LifeTime', '', str_replace( 'User Registration', '', $license_data->item_name ) ) );
					set_transient( 'ur_pro_license_plan', $license_data, WEEK_IN_SECONDS );
				}
			}

			return isset( $license_data ) ? $license_data : false;
		}

		return false;
	}
}

if ( ! function_exists( 'ur_get_json_file_contents' ) ) {

	/**
	 * UR Get json file contents.
	 *
	 * @param mixed $file File path.
	 * @param mixed $to_array Returned data in array.
	 *
	 * @since  2.2.4
	 */
	function ur_get_json_file_contents( $file, $to_array = false ) {
		if ( $to_array ) {
			return json_decode( ur_file_get_contents( $file ), true );
		}

		return json_decode( ur_file_get_contents( $file ) );
	}
}

if ( ! function_exists( 'ur_is_valid_url' ) ) {

	/**
	 * UR file get contents.
	 *
	 * @param mixed $url URL.
	 */
	function ur_is_valid_url( $url ) {
		// Must start with http:// or https://.
		if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
			return false;
		}

		// Must pass validation.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'ur_file_get_contents' ) ) {

	/**
	 * UR file get contents.
	 *
	 * @param mixed $file File path.
	 *
	 * @since  2.2.4
	 */
	function ur_file_get_contents( $file ) {

		if ( $file ) {
			global $wp_filesystem;
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
			$local_file = preg_replace( '/\\\\|\/\//', '/', plugin_dir_path( UR_PLUGIN_FILE ) . $file );

			if ( $wp_filesystem->exists( $local_file ) ) {
				$response = $wp_filesystem->get_contents( $local_file );

				return $response;
			}
		}
	}
}

if ( ! function_exists( 'crypt_the_string' ) ) {
	/**
	 * Encrypt/Decrypt the provided string.
	 * Encrypt while setting token and updating to database, decrypt while comparing the stored token.
	 *
	 * @param string $string String to encrypt/decrypt.
	 * @param string $action Encrypt/decrypt action. 'e' for encrypt and 'd' for decrypt.
	 *
	 * @return string Encrypted/Decrypted string.
	 */
	function crypt_the_string( $string, $action = 'e' ) {
		$secret_key = get_option( 'ur_secret_key' );
		$secret_iv  = get_option( 'ur_secret_iv' );

		if ( empty( $secret_key ) || empty( $secret_iv ) ) {
			$secret_key = ur_generate_random_key();
			$secret_iv  = ur_generate_random_key();
			update_option( 'ur_secret_key', $secret_key );
			update_option( 'ur_secret_iv', $secret_iv );
		}

		$output         = false;
		$encrypt_method = 'AES-256-CBC';
		$key            = hash( 'sha256', $secret_key );
		$iv             = substr( hash( 'sha256', $secret_iv ), 0, 16 );

		if ( 'e' == $action ) {
			if ( function_exists( 'openssl_encrypt' ) ) {
				$output = base64_encode( openssl_encrypt( $string, $encrypt_method, $key, 0, $iv ) );
			} else {
				$output = base64_encode( $string );
			}
		} elseif ( 'd' == $action ) {
			if ( function_exists( 'openssl_decrypt' ) ) {
				$output = openssl_decrypt( base64_decode( $string ), $encrypt_method, $key, 0, $iv );
			} else {
				$output = base64_decode( $string );
			}
		}

		return $output;
	}
} //phpcs:ignore.
if ( ! function_exists( 'ur_generate_random_key' ) ) {
	/**
	 * Function to generate the random key.
	 *
	 * @since 3.0.2.1
	 */
	function ur_generate_random_key() {
		$length              = 32;
		$allow_special_chars = true;
		$key                 = wp_generate_password( $length, $allow_special_chars );

		return $key;
	}
}

if ( ! function_exists( 'ur_clean_tmp_files' ) ) {
	/**
	 * Clean up the tmp folder - remove all old files every day (filterable interval).
	 */
	function ur_clean_tmp_files() {
		$files = glob( trailingslashit( ur_get_tmp_dir() ) . '*' );

		if ( ! is_array( $files ) || empty( $files ) ) {
			return;
		}
		/**
		 * Filters the lifespan of temporary files cleanup.
		 *
		 * @param int $lifespan The default lifespan of temporary files cleanup in seconds.
		 *
		 * @return int Modified lifespan for temporary files cleanup in seconds.
		 */
		$lifespan = (int) apply_filters( 'user_registration_clean_tmp_files_lifespan', DAY_IN_SECONDS );

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}

			// In some cases filemtime() can return false, in that case - pretend this is a new file and do nothing.
			$modified = (int) filemtime( $file );
			if ( empty( $modified ) ) {
				$modified = time();
			}

			if ( ( time() - $modified ) >= $lifespan ) {
				@unlink( $file ); // phpcs:ignore.WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
}

if ( ! function_exists( 'ur_get_tmp_dir' ) ) {
	/**
	 * Get tmp dir for files.
	 *
	 * @return string
	 */
	function ur_get_tmp_dir() {
		$tmp_root = UR_UPLOAD_PATH . 'temp-uploads';

		if ( ! file_exists( $tmp_root ) || ! wp_is_writable( $tmp_root ) ) {
			wp_mkdir_p( $tmp_root );
		}

		$index = trailingslashit( $tmp_root ) . 'index.html';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore.WordPress.WP.AlternativeFunctions
		}

		return $tmp_root;
	}
}

if ( ! function_exists( 'ur_get_user_roles' ) ) {
	/**
	 * Returns an array of all roles associated with the user.
	 *
	 * @param [int] $user_id User Id.
	 *
	 * @returns array
	 */
	function ur_get_user_roles( $user_id ) {
		$roles = array();

		if ( $user_id ) {
			$user_meta = get_userdata( $user_id );
			$roles     = isset( $user_meta->roles ) ? $user_meta->roles : array();
		}

		$user_roles = array_map( 'ucfirst', $roles );

		return $user_roles;
	}
}

if ( ! function_exists( 'ur_upload_profile_pic' ) ) {
	/**
	 * Upload Profile Picture
	 *
	 * @param [array] $valid_form_data Valid Form Data.
	 * @param [int]   $user_id User Id.
	 */
	function ur_upload_profile_pic( $valid_form_data, $user_id ) {
		$attachment_id = array();
		/**
		 * Filters the URL for uploading profile pictures during user registration.
		 *
		 * This filter hook allows developers to customize the URL for uploading profile pictures
		 * during the user registration process. By default, the profile pictures are uploaded
		 * to the 'profile-pictures' directory within the UR_UPLOAD_PATH constant. Developers can
		 * use this filter to modify the upload URL based on specific requirements or preferences.
		 *
		 * @param string $upload_url The default URL for uploading profile pictures.
		 */
		$upload_path = apply_filters( 'user_registration_profile_pic_upload_url', UR_UPLOAD_PATH . 'profile-pictures' ); /*Get path of upload dir of WordPress*/

		// Checks if the upload directory exists and create one if not.
		if ( ! file_exists( $upload_path ) ) {
			wp_mkdir_p( $upload_path );
		}
		$valid_extensions = array( 'image/jpeg', 'image/jpg', 'image/gif', 'image/png' );
		$upload_file      = $valid_form_data['profile_pic_url']->value;
		$valid_ext        = array();

		foreach ( $valid_extensions as $key => $value ) {
			$image_extension   = explode( '/', $value );
			$valid_ext[ $key ] = isset( $image_extension[1] ) ? $image_extension[1] : '';

			if ( 'jpeg' === $valid_ext[ $key ] ) {
				$index               = count( $valid_extensions );
				$valid_ext[ $index ] = 'jpg';
			}
		}

		if ( ! is_numeric( $upload_file ) ) {
			$upload = ur_maybe_unserialize( crypt_the_string( $upload_file, 'd' ) );
			if ( function_exists( 'mime_content_type' ) ) {
				$upload_file_type = isset( $upload['file_path'] ) ? mime_content_type( $upload['file_path'] ) : '';
			} else {
				$upload_file_info = isset( $upload['file_path'] ) ? wp_check_filetype( $upload['file_path'] ) : '';
				$upload_file_type = ! empty( $upload_file_info ) ? $upload_file_info['type'] : '';
			}

			if ( isset( $upload['file_name'] ) && isset( $upload['file_path'] ) && isset( $upload['file_extension'] ) && in_array( $upload_file_type, $valid_extensions ) && in_array( $upload['file_extension'], $valid_ext ) ) {
				$upload_path = $upload_path . '/';
				$file_name   = wp_unique_filename( $upload_path, $upload['file_name'] );
				$file_path   = $upload_path . sanitize_file_name( $file_name );
				// Check the type of file. We'll use this as the 'post_mime_type'.
				$filetype = wp_check_filetype( basename( $file_name ), null );
				$moved    = '';

				if ( basename( $upload['file_path'] ) === $upload['file_name'] ) {
					$moved = rename( $upload['file_path'], $file_path );
				}

				if ( $moved ) {
					$attachment_id = wp_insert_attachment(
						array(
							'guid'           => $file_path,
							'post_mime_type' => $filetype['type'],
							'post_title'     => preg_replace( '/\.[^.]+$/', '', sanitize_file_name( $file_name ) ),
							'post_content'   => '',
							'post_status'    => 'inherit',
						),
						$file_path
					);

					if ( ! is_wp_error( $attachment_id ) ) {
						include_once ABSPATH . 'wp-admin/includes/image.php';

						// Generate and save the attachment metas into the database.
						wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file_path ) );
					}
				}
			}
		} else {
			// A numeric value is sent when the user keeps their existing profile picture
			// (the template pre-populates the hidden field with the stored attachment ID).
			// Only allow it if the attachment actually belongs to this user; a bare numeric
			// ID referencing another user's media must be rejected.
			if ( (int) get_post_field( 'post_author', $upload_file ) !== (int) $user_id ) {
				return;
			}
			$attachment_id = $upload_file;
		}
		$attachment_id = ! empty( $attachment_id ) ? $attachment_id : '';
		update_user_meta( $user_id, 'user_registration_profile_pic_url', $attachment_id );
	}
}

/**
 * Check given string is valid url or not.
 */
if ( ! function_exists( 'ur_is_valid_url' ) ) {
	/**
	 * Checks if url is valid.
	 *
	 * @param [string] $url URL.
	 *
	 * @return bool
	 */
	function ur_is_valid_url( $url ) {

		// Must start with http:// or https://.
		if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
			return false;
		}

		// Must pass validation.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'ur_option_checked' ) ) {
	/**
	 * Returns whether a setting checkbox or toggle is enabled.
	 *
	 * @param string $option_name Option Name.
	 * @param string $default Default Value.
	 *
	 * @return boolean
	 */
	function ur_option_checked( $option_name = '', $default = '' ) {

		if ( empty( $option_name ) ) {
			return false;
		}

		$option_value = get_option( $option_name, $default );

		// Handling Backward Compatibility.
		if ( 'yes' === $option_value ) {
			return true;
		} elseif ( 'no' === $option_value ) {
			return false;
		}

		return ur_string_to_bool( $option_value );
	}
}

if ( ! function_exists( 'ur_check_captch_keys' ) ) {
	/**
	 * Check the site key and secret key for the selected captcha type, are valid or not.
	 *
	 * @return bool
	 */
	function ur_check_captch_keys( $context = 'register', $form_id = 0, $form_save_action = false ) {
		$recaptcha_type      = get_option( 'user_registration_captcha_setting_recaptcha_version', 'v2' );
		$invisible_recaptcha = ur_option_checked( 'user_registration_captcha_setting_invisible_recaptcha_v2', false );

		if ( 'login' === $context ) {
			$recaptcha_type = get_option( 'user_registration_login_options_configured_captcha_type', $recaptcha_type );
		} elseif ( 'register' === $context && $form_id ) {
			if ( $form_save_action ) {
				if ( isset( $_POST['data']['form_setting_data'] ) ) { // phpcs:ignore
					foreach ( $_POST['data']['form_setting_data'] as $value ) { //phpcs:ignore
						if ( 'user_registration_form_setting_configured_captcha_type' === $value['name'] ) {
							$recaptcha_type = $value['value'];
						}
					}
				}
			} else {
				$recaptcha_type = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_configured_captcha_type', $recaptcha_type );
			}
		}

		$site_key   = '';
		$secret_key = '';

		if ( 'v2' === $recaptcha_type ) {
			if ( $invisible_recaptcha ) {
				$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_key' );
				$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_secret' );
			} else {
				$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key' );
				$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret' );
			}
		} elseif ( 'v3' === $recaptcha_type ) {
			$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_v3' );
			$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_v3' );
		} elseif ( 'hCaptcha' === $recaptcha_type ) {
			$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_hcaptcha' );
			$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha' );
		} elseif ( 'cloudflare' === $recaptcha_type ) {
			$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_cloudflare' );
			$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_cloudflare' );
		}

		if ( ! empty( $site_key ) && ! empty( $secret_key ) ) {
			return true;
		}

		return false;
	}
}


if ( ! function_exists( 'ur_premium_settings_tab' ) ) {

	/**
	 * Settings tab list to display as premium tabs.
	 *
	 * @since 3.0
	 */
	function ur_premium_settings_tab() {

		$premium_tabs = array(
			'email'              => array(
				'templates'    => array(
					'label'  => esc_html__( 'Email Templates', 'user-registration' ),
					'plugin' => 'user-registration-email-templates',
					'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration Email Templates', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Create emails branded that look professional and consistent.',
						'description'  => array(
							'Choose from 6 ready-made email templates',
							'Customize layout, colors, and content',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/email-templates/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'email-templates' ) ),
					),
				),
				'custom-email' => array(
					'label'  => esc_html__( 'Custom Email', 'user-registration' ),
					'plugin' => 'user-registration-email-custom-email',
					'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration - Custom Email', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Customize and manage email notifications for key membership and registration events.',
						'description'  => array(
							'Configure scheduled emails for members',
							'Choose recipients and personalize email content',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/email-notifications/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'email-notifications' ) ),
					),
				),
			),
			'registration_login' => array(
				'social-connect'  => array(
					'label'  => esc_html__( 'Social Connect', 'user-registration' ),
					'plugin' => 'user-registration-social-connect',
					'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration - Social Connect', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Allow users to sign up or log in using social accounts.',
						'description'  => array(
							'Supports 5 major platforms, including Facebook and Google ',
							'Configure each social platform individually',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/social-connect/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'social-connect' ) ),
					),
				),
				'profile-connect' => array(
					'label'  => esc_html__( 'Profile Connect', 'user-registration' ),
					'plugin' => 'user-registration-profile-connect',
					'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration Profile Connect', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Import users from existing registrations into URM.',
						'description'  => array(
							'Select which forms or sources to import users from',
							'Map fields to keep user data consistent',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/profile-connect/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'profile-connect' ) ),
					),
				),
				'invite-code'     => array(
					'label'  => esc_html__( 'Invite Codes', 'user-registration' ),
					'plugin' => 'user-registration-invite-codes',
					'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration Invite Codes', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Enable invite-only signups using custom codes.',
						'description'  => array(
							'Customize popup content and appearance',
							'Customize layout, colors, and content',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/invite-codes/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'invite-codes' ) ),
					),
				),
				'file-upload'     => array(
					'label'  => esc_html__( 'File Upload', 'user-registration' ),
					'plugin' => 'user-registration-file-upload',
					'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration - File Upload', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Let users upload files during registration.',
						'description'  => array(
							'Collect profile photos or verification documents',
							'Support 10+ file types, including PDF and PNG',
							'Set upload size limits for better control',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/file-upload/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'file-upload' ) ),
					),
				),
			),
			'my_account'         => array(
				'customize-my-account' => array(
					'label'  => esc_html__( 'Customize My Account', 'user-registration' ),
					'plugin' => 'user-registration-customize-my-account',
					'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration customize my account', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Personalize the user dashboard to fit your site.',
						'description'  => array(
							'Edit or replace default dashboard content',
							'Add custom links and account sections',
							'Style individual dashboard elements',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/customize-my-account/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'customize-my-account' ) ),
					),
				),
			),
			'integration'        => array(
				'email-marketing' => array(
					'is_collection' => true,
					'collections'   => array(
						'activecampaign' => array(
							'label'  => esc_html__( 'Active Campaign', 'user-registration' ),
							'plugin' => 'user-registration-activecampaign',
							'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
							'name'   => esc_html__( 'User Registration ActiveCampaign', 'user-registration' ),
							'upsell' => array(
								'excerpt'      => 'Sync members with ActiveCampaign for advanced email automation.',
								'description'  => array(
									'Automatically subscribe users to ActiveCampaign lists upon registration',
									'Auto-update subscriber details when members edit their profiles',
								),
								'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/activecampaign/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'activecampaign' ) ),
							),
						),
						'brevo'          => array(
							'label'  => esc_html__( 'Brevo', 'user-registration' ),
							'plugin' => 'user-registration-brevo',
							'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
							'name'   => esc_html__( 'User Registration Brevo', 'user-registration' ),
							'upsell' => array(
								'excerpt'      => 'Connect members with Brevo (formerly Sendinblue) for email campaigns.',
								'description'  => array(
									'Sync member data to Brevo contact lists automatically',
									'Use conditional logic for targeted list segmentation',
								),
								'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/brevo/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'brevo' ) ),
							),
						),
						'convertkit'     => array(
							'label'  => esc_html__( 'Kit (Previously Convertkit)', 'user-registration' ),
							'plugin' => 'user-registration-convertkit',
							'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
							'name'   => esc_html__( 'User Registration convertkit', 'user-registration' ),
							'upsell' => array(
								'excerpt'      => 'Grow your email list with Kit integration.',
								'description'  => array(
									'Map signup form fields to Kit custom fields',
									'Subscribe users to specific Kit forms, tags, or sequences',
								),
								'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/kit/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'kit' ) ),
							),
						),
						'klaviyo'        => array(
							'label'  => esc_html__( 'Klaviyo', 'user-registration' ),
							'plugin' => 'user-registration-klaviyo',
							'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
							'name'   => esc_html__( 'User Registration Klaviyo', 'user-registration' ),
							'upsell' => array(
								'excerpt'      => 'Power your email marketing with Klaviyo integration.',
								'description'  => array(
									'Auto-sync member details when profiles are updated',
									'Automatically unsubscribe deleted users from Klaviyo',
								),
								'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/klaviyo/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'klaviyo' ) ),
							),
						),
						'mailchimp'      => array(
							'label'  => esc_html__( 'Mailchimp', 'user-registration' ),
							'plugin' => 'user-registration-mailchimp',
							'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
							'name'   => esc_html__( 'User Registration - Mailchimp', 'user-registration' ),
							'upsell' => array(
								'excerpt'      => 'Automatically sync users to Mailchimp audiences upon registration.',
								'description'  => array(
									'Add custom tags to segment your membership lists',
									'Map User Registration fields to Mailchimp merge tags',
								),
								'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/mailchimp/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'mailchimp' ) ),
							),

						),
						'mailerlite'     => array(
							'label'  => esc_html__( 'Mailerlite', 'user-registration' ),
							'plugin' => 'user-registration-mailerlite',
							'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
							'name'   => esc_html__( 'User Registration MailerLite', 'user-registration' ),
							'upsell' => array(
								'excerpt'      => ' Integrate with MailerLite for streamlined email marketing.',
								'description'  => array(
									'Auto-sync member profile updates to MailerLite',
									'Create multiple connections for different campaigns',
								),
								'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/mailerlite/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'mailerlite' ) ),
							),
						),
						'mailpoet'       => array(
							'label'  => esc_html__( 'Mailpoet', 'user-registration' ),
							'plugin' => 'user-registration-mailpoet',
							'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
							'name'   => esc_html__( 'User Registration MailPoet', 'user-registration' ),
							'upsell' => array(
								'excerpt'      => 'Connect member signup forms with MailPoet subscriber lists.',
								'description'  => array(
									'Use conditional logic for targeted list building',
									'Perfect for WordPress-native email marketing',
								),
								'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/mailpoet/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'mailpoet' ) ),
							),
						),
						'zapier'         => array(
							'label'  => esc_html__( 'Zapier', 'user-registration' ),
							'plugin' => 'user-registration-zapier',
							'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
							'name'   => esc_html__( 'User Registration Zapier', 'user-registration' ),
							'upsell' => array(
								'excerpt'      => 'Connect with 1,500+ apps through Zapier automation.',
								'description'  => array(
									'Transfer registration data to Google Docs, Trello, Slack, HubSpot, and more',
									'Trigger actions on user signup, profile update, or deletion',
									'Create automated workflows without coding ',
								),
								'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/zapier/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'zapier' ) ),
							),
						),
					),
					'plan'          => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'plugin'        => '',
				),
				'pdf-submission'  => array(
					'label'  => esc_html__( 'PDF Form Submission', 'user-registration' ),
					'plugin' => 'user-registration-pdf-form-submission',
					'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration PDF Form Submission', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Generate and download registration data in PDF format.',
						'description'  => array(
							'Automatically attach PDF files to admin and user emails on form submission',
							'Customize PDF templates with header logos and branding',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/pdf-form-submission/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'pdf-form-submission' ) ),
					),
				),
				'sms-integration' => array(
					'label'  => esc_html__( 'SMS Integration', 'user-registration' ),
					'plugin' => 'user-registration-sms-integration',
					'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration SMS Integration', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Send SMS OTP for login and verification via Twilio.',
						'description'  => array(
							'Connect with Twilio for SMS delivery',
							'Enable OTP-based login and registration verification',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/sms-integration/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'sms-integration' ) ),
					),
				),
				'google-sheets'   => array(
					'label'  => esc_html__( 'Google Sheets', 'user-registration' ),
					'plugin' => 'user-registration-google-sheets',
					'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration Google Sheets', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Store and manage form data in spreadsheet format in Google Sheets.',
						'description'  => array(
							'Map User Registration fields to Google Sheets columns',
							'Use conditional logic to filter which submissions sync',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/google-sheets/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'google-sheets' ) ),
					),
				),
				'salesforce'      => array(
					'label'  => esc_html__( 'Salesforce', 'user-registration' ),
					'plugin' => 'user-registration-salesforce',
					'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration Salesforce', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Sync member data with Salesforce CRM automatically.',
						'description'  => array(
							'Map registration form fields to Salesforce fields',
							'Support for multiple Salesforce account connections',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/salesforce/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'salesforce' ) ),
					),

				),
				'geolocation'     => array(
					'label'  => esc_html__( 'Geolocation', 'user-registration' ),
					'plugin' => 'user-registration-geolocation',
					'plan'   => array( 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration Geolocation', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Capture geolocation data automatically when users register.',
						'description'  => array(
							'View user location data in admin dashboard',
							'Add Google Maps as address field in registration forms',
							'Send geolocation data via smart tags in emails',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/geolocation/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'geolocation' ) ),
					),
				),
				'woocommerce'     => array(
					'label'  => esc_html__( 'WooCommerce', 'user-registration' ),
					'plugin' => 'user-registration-woocommerce',
					'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration - WooCommerce', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Add WooCommerce billing and shipping fields to signup form.',
						'description'  => array(
							'View and edit WooCommerce-related details in one place',
							'Let users view their order history right from their account page.',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/woocommerce-integration/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'woocommerce-integration' ) ),
					),
				),
				'popup'           => array(
					'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'plugin' => 'user-registration-pro',
					'upsell' => array(
						'excerpt'     => 'Display registration or login forms in popups.',
						'description' => array(
							'Customize popup content and appearance',
							'Control where the popup shows up',
						),
					),
				),
				'cloud-storage'   => array(
					'is_collection' => true,
					'collections'   => array(
						'google-drive' => array(
							'label'  => esc_html__( 'Google Drive', 'user-registration' ),
							'plugin' => 'user-registration-cloud-storage',
							'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
							'name'   => esc_html__( 'User Registration Cloud Storage', 'user-registration' ),
							'upsell' => array(
								'excerpt'      => 'Store user-uploaded files directly in your preferred cloud storage service.',
								'description'  => array(
									'Connect signup forms to Dropbox or Google Drive',
									'Keep your WordPress server clean by offloading storage',
								),
								'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/cloud-storage-integration/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'cloud-storage-integration' ) ),
							),
						),
						'dropbox'      => array(
							'label'  => esc_html__( 'Cloud Storage', 'user-registration' ),
							'plugin' => 'user-registration-cloud-storage',
							'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
							'name'   => esc_html__( 'User Registration Cloud Storage', 'user-registration' ),
							'upsell' => array(
								'excerpt'      => 'Store user-uploaded files directly in your preferred cloud storage service.',
								'description'  => array(
									'Connect signup forms to Dropbox or Google Drive',
									'Keep your WordPress server clean by offloading storage',
								),
								'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/cloud-storage-integration/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'cloud-storage-integration' ) ),
							),
						),
					),
					'plan'          => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'plugin'        => 'user-registration-cloud-storage',
				),
			),
			'security'           => array(
				'2fa' => array(
					'label'  => esc_html__( 'Two Factor Authentication', 'user-registration' ),
					'plugin' => 'user-registration-two-factor-authentication',
					'plan'   => array( 'personal', 'plus', 'professional', 'themegrill agency' ),
					'name'   => esc_html__( 'User Registration - Two Factor Authentication', 'user-registration' ),
					'upsell' => array(
						'excerpt'      => 'Verify user logins with one-time passwords.',
						'description'  => array(
							'Send OTPs via email or SMS',
							'Configure verification limits and rules',
						),
						'feature_link' => ur_utm_url( 'https://wpuserregistration.com/features/two-factor-authentication/', array( 'source' => 'settings', 'medium' => 'button', 'content' => 'two-factor-authentication' ) ),
					),
				),
			),
		);

		/**
		 * Filters the premium settings tabs for customization or extension.
		 *
		 * The 'user_registration_premium_settings_tab' filter allows developers to modify
		 * or extend the premium settings tabs defined in the plugin. Developers can customize
		 * the array of premium tabs based on their specific requirements, adding, removing,
		 * or altering tabs as needed.
		 *
		 * @param array $premium_tabs An array of premium settings tabs for User Registration.
		 */
		return apply_filters( 'user_registration_premium_settings_tab', $premium_tabs );
	}
}

add_filter( 'user_registration_upgrade_to_pro_setting', 'ur_get_premium_settings_tab' );

if ( ! function_exists( 'ur_get_premium_settings_tab' ) ) {

	/**
	 * Premium settings tabs.
	 *
	 * @since 5.0
	 */
	function ur_get_premium_settings_tab( $settings ) {
		global $current_tab;
		global $current_section;

		$license_data = ur_get_license_plan();
		$license_plan = ! empty( $license_data->item_plan ) ? $license_data->item_plan : false;
		$premium_tabs = ur_premium_settings_tab();

		if ( ! isset( $current_section ) ) {
			$current_section = apply_filters( 'user_registration_settings_' . $current_tab . '_default_section', '' );
		}

		$is_upgradable_section = isset( $premium_tabs[ $current_tab ] [ $current_section ] );
		if ( $is_upgradable_section ) {
			$section_details = $premium_tabs[ $current_tab ][ $current_section ];
			if ( isset( $section_details['is_collection'] ) && ur_string_to_bool( $section_details['is_collection'] ) ) {
				$settings['sections'] = array();
				foreach ( $section_details['collections'] as $detail ) {
					if ( ! empty( $license_plan ) ) {
						$license_plan = trim( str_replace( 'lifetime', '', strtolower( $license_plan ) ) );
						if ( ! in_array( $license_plan, $detail['plan'], true ) ) {
							if ( is_plugin_active( $detail['plugin'] . '/' . $detail['plugin'] . '.php' ) ) {
								continue;
							}
							/* translators: %s: License Plan Name. */
							$description = sprintf( __( 'You have been subscribed to %s plan. Please upgrade to higher plans to use this feature.', 'user-registration' ), ucfirst( $license_plan ) );
							$button_text = esc_html__( 'Upgrade Plan', 'user-registration' );

							$settings['sections'][ $detail['plugin'] ] = array_merge(
								$detail,
								array(
									'type'        => 'card',
									'title'       => $detail['label'],
									'before_desc' => $description,
								)
							);
						} else {
							$plugin_name = $detail['name'];
							$action      = '';
							if ( file_exists( WP_PLUGIN_DIR . '/' . $detail['plugin'] ) ) {
								if ( ! is_plugin_active( $detail['plugin'] . '/' . $detail['plugin'] . '.php' ) ) {
									$action = 'Activate';
								} else {
									continue;
								}
							} else {
								$action = 'Install';
							}
							/* translators: %s: Addon Name. */
							$description = sprintf( __( 'Please %1$s %2$s addon to use this feature.', 'user-registration' ), $action, ucwords( str_replace( '-', ' ', $detail['plugin'] ) ) );

							$button_class = 'user-registration-settings-addon-' . strtolower( $action );
							$button_attrs = array(
								'data-slug' => $detail['plugin'],
								'data-name' => $plugin_name,
							);
							$button_title = sprintf(
								/* translators: %s: Addon Name. */
								esc_html__( '%s Addon', 'user-registration' ),
								$action
							);

							$settings['sections'][ $detail['plugin'] ] = array_merge(
								$detail,
								array(
									'type'        => 'card',
									'is_premium'  => false,
									'title'       => $detail['label'],
									'before_desc' => $description,
									'settings'    => array(
										array(
											'id'    => 'ur-install-addon__button',
											'type'  => 'button',
											'class' => $button_class,
											'attrs' => $button_attrs,
											'title' => $button_title,
										),
									),
								)
							);
						}
					} else {
						if ( is_plugin_active( $detail['plugin'] . '/' . $detail['plugin'] . '.php' ) ) {
							continue;
						}
						$description            = esc_html__( 'You are currently using the free version of our plugin. Please upgrade to premium version to use this feature.', 'user-registration' );
						$current_section_detail = $detail ? array_merge(
							array(
								'type'       => 'card',
								'is_premium' => true,
								'title'      => $detail['label'],
								'class'      => 'ur-upgrade--link',
							),
							$detail
						) : array();

						$settings['sections'][ str_replace( 'user-registration-', '', $detail['plugin'] ) ] = $current_section_detail ?? array();
					}
				}
			} else { // scalar section.
				$detail = $section_details;
				if ( ! empty( $license_plan ) ) {
					$license_plan = trim( str_replace( 'lifetime', '', strtolower( $license_plan ) ) );
					if ( 'custom-email' === $current_section ) {
						$feature_slug       = 'user-registration-custom-email';
						$is_feature_enabled = ur_check_module_activation( 'custom-email' );

						if ( in_array( $license_plan, $detail['plan'], true ) && ! $is_feature_enabled ) {
							$description  = esc_html__( 'Please activate the Custom Email feature to use this functionality.', 'user-registration' );
							$button_class = 'user-registration-settings-feature-activate';
							$button_attrs = array(
								'data-slug' => $feature_slug,
								'data-type' => 'feature',
								'data-name' => $detail['name'],
							);
							$button_title = esc_html__( 'Activate Addon', 'user-registration' );

							$settings['sections']['premium_setting_section']['before_desc'] = $description;
							$settings['sections']['premium_setting_section']['desc']        = false;
							$settings['sections']['premium_setting_section']['settings']    = array(
								array(
									'id'    => 'ur-activate-feature__button',
									'type'  => 'button',
									'class' => $button_class,
									'attrs' => $button_attrs,
									'title' => $button_title,
								),
							);
							return $settings;
						}
					}

					if ( 'sms-integration' === $current_section ) {
						$feature_slug       = 'user-registration-sms-integration';
						$is_feature_enabled = ur_check_module_activation( 'sms-integration' );

						if ( in_array( $license_plan, $detail['plan'], true ) && $is_feature_enabled ) {
							unset( $settings['sections']['premium_setting_section'] );
							return $settings;
						}

						if ( in_array( $license_plan, $detail['plan'], true ) && ! $is_feature_enabled ) {
							$description  = esc_html__( 'Please activate the SMS Integration feature to use this functionality.', 'user-registration' );
							$button_class = 'user-registration-settings-feature-activate';
							$button_attrs = array(
								'data-slug' => $feature_slug,
								'data-type' => 'feature',
								'data-name' => $detail['name'],
							);
							$button_title = esc_html__( 'Activate Addon', 'user-registration' );

							$settings['sections']['premium_setting_section']['before_desc'] = $description;
							$settings['sections']['premium_setting_section']['desc']        = false;
							$settings['sections']['premium_setting_section']['settings']    = array(
								array(
									'id'    => 'ur-activate-feature__button',
									'type'  => 'button',
									'class' => $button_class,
									'attrs' => $button_attrs,
									'title' => $button_title,
								),
							);
							return $settings;
						}
					}

					if ( ! in_array( $license_plan, $detail['plan'], true ) ) {
						if ( is_plugin_active( $detail['plugin'] . '/' . $detail['plugin'] . '.php' ) ) {
							return array();
						}
						/* translators: %s: License Plan Name. */
						$description = sprintf( __( 'You have been subscribed to %s plan. Please upgrade to higher plans to use this feature.', 'user-registration' ), ucfirst( $license_plan ) );
						$settings['sections']['premium_setting_section']['before_desc'] = $description;
						$upgradable_plans                                        = implode( 'plan, ', $detail['plan'] );
						$settings['sections']['premium_setting_section']['desc'] = sprintf( __( 'To unlock this setting, consider upgrading to %s', 'user-registration' ), $upgradable_plans );
					} else {
						$plugin_name = $detail['name'];
						$action      = '';
						if ( 'user-registration-email-custom-email' === $detail['plugin'] || 'user-registration-sms-integration' === $detail['plugin'] ) {
							$action = 'Activate';
						} elseif ( file_exists( WP_PLUGIN_DIR . '/' . $detail['plugin'] ) ) {
							if ( ! is_plugin_active( $detail['plugin'] . '/' . $detail['plugin'] . '.php' ) ) {
								$action = 'Activate';
							} else {
								return array();
							}
						} else {
							$action = 'Install';
						}
						/* translators: %s: Addon Name. */
						$description = sprintf( __( 'Please %1$s %2$s addon to use this feature.', 'user-registration' ), $action, ucwords( str_replace( '-', ' ', $detail['plugin'] ) ) );

						/*
						translators: %s: Action Name. */
						// $button = '<a href="#" class="user-registration-settings-addon-' . strtolower( $action ) . '" data-slug="' . $detail['plugin'] . '" data-name="' . $plugin_name . '">' . sprintf( esc_html__( '%s Addon', 'user-registration' ), $action ) . '</a>';
						$button_class = 'user-registration-settings-addon-' . strtolower( $action );
						$button_attrs = array(
							'data-slug' => $detail['plugin'],
							'data-name' => $plugin_name,
						);
						$button_title = sprintf( esc_html__( '%s Addon', 'user-registration' ), $action );

						$settings['sections']['premium_setting_section']['before_desc'] = $description;
						$settings['sections']['premium_setting_section']['desc']        = false;
						$settings['sections']['premium_setting_section']['settings']    = array(
							array(
								'id'    => 'ur-install-addon__button',
								'type'  => 'button',
								'class' => $button_class,
								'attrs' => $button_attrs,
								'title' => $button_title,
							),
						);
					}
				} else {
					if ( is_plugin_active( $detail['plugin'] . '/' . $detail['plugin'] . '.php' ) ) {
						return array();
					}
					$description = esc_html__( 'You are currently using the free version of our plugin. Please upgrade to premium version to use this feature.', 'user-registration' );
					$settings['sections']['premium_setting_section']['title']       = $detail['label'];
					$settings['sections']['premium_setting_section']['before_desc'] = $description;

					if ( ! empty( $detail['upsell'] ) ) {
						$settings['sections']['premium_setting_section']['before_desc'] = '';
						$settings['sections']['premium_setting_section']['upsell']      = $detail['upsell'];
					}
				}
			}
			return $settings;
		}
		return $settings;
	}
}

if ( ! function_exists( 'ur_is_ajax_login_enabled' ) ) {
	/**
	 * Check whether the ajax login is enabled or not.
	 *
	 * @return bool
	 */
	function ur_is_ajax_login_enabled() {
		return ur_option_checked( 'ur_login_ajax_submission', false );
	}
}

if ( ! function_exists( 'ur_process_login' ) ) {
	/**
	 * Process the login form.
	 *
	 * @param string $nonce_value Nonce.
	 *
	 * @throws Exception Login errors.
	 *
	 * @since 3.0
	 */
	function ur_process_login( $nonce_value ) {
		try {
			// Custom error messages.
			$messages = array(
				'empty_username'     => get_option( 'user_registration_message_username_required', esc_html__( 'Username is required.', 'user-registration' ) ),
				'empty_password'     => get_option( 'user_registration_message_empty_password', null ),
				'invalid_username'   => get_option( 'user_registration_message_invalid_username', null ),
				'unknown_email'      => get_option( 'user_registration_message_unknown_email', esc_html__( 'A user could not be found with this email address.', 'user-registration' ) ),
				'pending_approval'   => get_option( 'user_registration_message_pending_approval', null ),
				'denied_access'      => get_option( 'user_registration_message_denied_account', null ),
				'user_disabled'      => esc_html__( 'Sorry! You are disabled.Please Contact Your Administrator.', 'user-registration' ),
				'incorrect_password' => get_option( 'user_registration_message_incorrect_password', esc_html__( 'The password you entered for the %label% %email% is incorrect.', 'user-registration' ) ),
			);

			$post = $_POST; // phpcs:ignore.

			$recaptcha_value     = isset( $post['g-recaptcha-response'] ) ? ur_clean( wp_unslash( $post['g-recaptcha-response'] ) ) : '';
			$captcha_response    = isset( $post['CaptchaResponse'] ) ? $post['CaptchaResponse'] : ''; //phpcs:ignore.
			$recaptcha_enabled   = ur_option_checked( 'user_registration_login_options_enable_recaptcha', false );
			$recaptcha_type      = get_option( 'user_registration_captcha_setting_recaptcha_version', 'v2' );
			$recaptcha_type      = get_option( 'user_registration_login_options_configured_captcha_type', $recaptcha_type );
			$invisible_recaptcha = ur_option_checked( 'user_registration_captcha_setting_invisible_recaptcha_v2', false );

			$login_data = array(
				'user_password' => isset( $post['password'] ) ? $post['password'] : '', //phpcs:ignore.
				'remember'      => isset( $post['rememberme'] ),
			);

			$username = isset( $post['username'] ) ? trim( sanitize_user( wp_unslash( $post['username'] ) ) ) : '';

			// Check if the entered value is a valid email address.
			$user_details = null;
			if ( is_email( $username ) ) {
				$user_details = get_user_by( 'email', $username );
			} else {
				$user_details = get_user_by( 'login', $username );
			}

			if ( 'v2' === $recaptcha_type && ! $invisible_recaptcha ) {
				$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key' );
				$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret' );
			} elseif ( 'v2' === $recaptcha_type && $invisible_recaptcha ) {
				$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_key' );
				$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_secret' );
			} elseif ( 'v3' === $recaptcha_type ) {
				$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_v3' );
				$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_v3' );
			} elseif ( 'hCaptcha' === $recaptcha_type ) {
				$recaptcha_value = isset( $post['h-captcha-response'] ) ? ur_clean( wp_unslash( $post['h-captcha-response'] ) ) : '';
				$site_key        = get_option( 'user_registration_captcha_setting_recaptcha_site_key_hcaptcha' );
				$secret_key      = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha' );
			} elseif ( 'cloudflare' === $recaptcha_type ) {
				$recaptcha_value = isset( $post['cf-turnstile-response'] ) ? ur_clean( wp_unslash( $post['cf-turnstile-response'] ) ) : '';
				$site_key        = get_option( 'user_registration_captcha_setting_recaptcha_site_key_cloudflare' );
				$secret_key      = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_cloudflare' );
			}

			if ( ur_is_ajax_login_enabled() ) {
				$recaptcha_value = $captcha_response;
			}

			if ( $recaptcha_enabled && ! empty( $site_key ) && ! empty( $secret_key ) ) {
				if ( ! empty( $recaptcha_value ) ) {
					if ( 'hCaptcha' === $recaptcha_type ) {
						$data = wp_remote_get( 'https://hcaptcha.com/siteverify?secret=' . $secret_key . '&response=' . $recaptcha_value );
						$data = json_decode( wp_remote_retrieve_body( $data ) );

						if ( empty( $data->success ) || ( isset( $data->score ) && $data->score < apply_filters( 'user_registration_hcaptcha_threshold', 0.5 ) ) ) {
							throw new Exception( '<strong>' . esc_html__( 'ERROR:', 'user-registration' ) . '</strong>' . esc_html__( 'Error on hCaptcha. Contact your site administrator.', 'user-registration' ) );
						}
					} elseif ( 'cloudflare' === $recaptcha_type ) {
						$url    = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
						$params = array(
							'method' => 'POST',
							'body'   => array(
								'secret'   => $secret_key,
								'response' => $recaptcha_value,
							),
						);
						$data   = wp_safe_remote_post( $url, $params );
						$data   = json_decode( wp_remote_retrieve_body( $data ) );

						if ( empty( $data->success ) ) {
							throw new Exception( '<strong>' . esc_html__( 'ERROR:', 'user-registration' ) . '</strong>' . esc_html__( 'Error on Cloudflare. Contact your site administrator.', 'user-registration' ) );
						}
					} else {
						$data = wp_remote_get( 'https://www.google.com/recaptcha/api/siteverify?secret=' . $secret_key . '&response=' . $recaptcha_value );
						$data = json_decode( wp_remote_retrieve_body( $data ) );
						if ( empty( $data->success ) || ( isset( $data->score ) && $data->score <= get_option( 'user_registration_captcha_setting_recaptcha_threshold_score_v3', apply_filters( 'user_registration_recaptcha_v3_threshold', 0.5 ) ) ) ) {
							throw new Exception( '<strong>' . esc_html__( 'ERROR:', 'user-registration' ) . '</strong>' . esc_html__( 'Error on google reCaptcha. Contact your site administrator.', 'user-registration' ) );
						}
					}
				} else {
					throw new Exception( '<strong>' . esc_html__( 'ERROR:', 'user-registration' ) . '</strong>' . get_option( 'user_registration_form_submission_error_message_recaptcha', esc_html__( 'Captcha code error, please try again.', 'user-registration' ) ) );
				}
			}

			if ( 'previous-page' === get_option( 'user_registration_login_options_redirect_after_login', 'no' ) ) {
				if ( ! empty( $post['previous_page'] ) ) {
					$referer = $post['previous_page'];
					if ( $referer && false === strpos( $referer, 'wp-login.php' ) && $referer !== get_permalink() ) {
						$post['redirect'] = $referer;
					}
				}
			}

			// Handles the role based redirection.
			if ( ur_string_to_bool( get_option( 'user_registration_pro_role_based_redirection', false ) ) ) {
				$registration_redirect = get_option( 'ur_pro_settings_redirection_after_login', array() );

				foreach ( $registration_redirect as $role => $page_id ) {

					$roles = isset( $user_details->roles ) ? (array) $user_details->roles : array();

					if ( 0 !== $page_id && in_array( $role, $roles ) ) {
						$redirect_url     = get_permalink( $page_id );
						$post['redirect'] = $redirect_url;
					}
				}
			}

			/**
			 * Executes an action before validating the username during the login process.
			 *
			 * The 'user_registration_login_process_before_username_validation' action allows developers to perform
			 * actions before validating the username during the login process.
			 *
			 * @param WP_Post $post The WordPress post object.
			 * @param string $username The entered username.
			 * @param string $nonce_value The nonce value for security validation.
			 * @param array $messages Array of messages for communication.
			 */
			do_action( 'user_registration_login_process_before_username_validation', $post, $username, $nonce_value, $messages );

			$validation_error = new WP_Error();
			/**
			 * Filters the login errors during the user registration process.
			 *
			 * The 'user_registration_process_login_errors' filter allows developers to modify
			 * the validation error messages related to the login credentials during the user
			 * registration process. It provides an opportunity to customize the error messages
			 * based on the original validation error, username, and password submitted.
			 *
			 * @param WP_Error $validation_error The original validation error object.
			 * @param string $username The sanitized username submitted during registration.
			 * @param string $password The sanitized password submitted during registration.
			 */
			$validation_error = apply_filters( 'user_registration_process_login_errors', $validation_error, sanitize_user( wp_unslash( $post['username'] ) ), sanitize_user( $post['password'] ) );

			if ( $validation_error->get_error_code() ) {
				throw new Exception( '<strong>' . esc_html__( 'ERROR:', 'user-registration' ) . '</strong>' . $validation_error->get_error_message() );
			}

			if ( empty( $username ) ) {
				throw new Exception( '<strong>' . esc_html__( 'ERROR:', 'user-registration' ) . '</strong>' . $messages['empty_username'] );
			}

			$login_option = get_option( 'user_registration_general_setting_login_options_with', '' );

			// Validate login format and resolve user_login before attempting authentication.
			if ( 'email' === $login_option ) {
				if ( ! is_email( $username ) ) {
					throw new Exception( '<strong>' . esc_html__( 'ERROR:', 'user-registration' ) . '</strong> ' . esc_html__( 'Please enter a valid email address to log in.', 'user-registration' ) );
				}
				$user_by_email = get_user_by( 'email', $username );
				if ( empty( $user_by_email ) ) {
					if ( empty( $messages['unknown_email'] ) ) {
						$messages['unknown_email'] = esc_html__( 'A user could not be found with this email address.', 'user-registration' );
					}
					throw new Exception( '<strong>' . esc_html__( 'ERROR: ', 'user-registration' ) . '</strong>' . $messages['unknown_email'] );
				}
				$login_data['user_login'] = $user_by_email->user_login;
			} elseif ( 'username' === $login_option ) {
				if ( is_email( $username ) ) {
					throw new Exception( '<strong>' . esc_html__( 'ERROR:', 'user-registration' ) . '</strong> ' . esc_html__( 'Please enter your username to log in.', 'user-registration' ) );
				}
				$user_by_login            = get_user_by( 'login', $username );
				$login_data['user_login'] = isset( $user_by_login->user_login ) ? $user_by_login->user_login : $username;
			} else {
				// Both username and email are allowed.
				if ( is_email( $username ) && apply_filters( 'user_registration_get_username_from_email', true ) ) {
					$user_by_email = get_user_by( 'email', $username );
					if ( isset( $user_by_email->user_login ) ) {
						$login_data['user_login'] = $user_by_email->user_login;
					} else {
						$user_by_login = get_user_by( 'login', $username );
						if ( isset( $user_by_login->user_login ) ) {
							$login_data['user_login'] = $user_by_login->user_login;
						} else {
							if ( empty( $messages['unknown_email'] ) ) {
								$messages['unknown_email'] = esc_html__( 'A user could not be found with this email address.', 'user-registration' );
							}
							throw new Exception( '<strong>' . esc_html__( 'ERROR: ', 'user-registration' ) . '</strong>' . $messages['unknown_email'] );
						}
					}
				} else {
					$login_data['user_login'] = $username;
				}
			}

			// On multisite, ensure user exists on current site, if not add them before allowing login.
			if ( is_multisite() ) {
				$user_data = get_user_by( 'login', $login_data['user_login'] );

				if ( $user_data && ! is_user_member_of_blog( $user_data->ID, get_current_blog_id() ) ) {
					add_user_to_blog( get_current_blog_id(), $user_data->ID, 'customer' );
				}
			}

			// Perform the login.
			$user = wp_signon( apply_filters( 'user_registration_login_credentials', $login_data ), is_ssl() );

			if ( is_wp_error( $user ) ) {
				// Set custom error messages.
				if ( ! empty( $user->errors['empty_username'] ) && ! empty( $messages['empty_username'] ) ) {
					$user->errors['empty_username'][0] = sprintf( '<strong>%s:</strong> %s', __( 'ERROR', 'user-registration' ), $messages['empty_username'] );
				}
				if ( ! empty( $user->errors['empty_password'] ) && ! empty( $messages['empty_password'] ) ) {
					$user->errors['empty_password'][0] = sprintf( '<strong>%s:</strong> %s', __( 'ERROR', 'user-registration' ), $messages['empty_password'] );
				}
				if ( ! empty( $user->errors['invalid_username'] ) && ! empty( $messages['invalid_username'] ) ) {
					$user->errors['invalid_username'][0] = sprintf( '<strong>%s:</strong> %s', __( 'ERROR', 'user-registration' ), $messages['invalid_username'] );
				}
				if ( ! empty( $user->errors['invalid_email'] ) && ! empty( $messages['unknown_email'] ) ) {
					$user->errors['invalid_email'][0] = sprintf( '<strong>%s:</strong> %s', __( 'ERROR', 'user-registration' ), $messages['unknown_email'] );
				}
				if ( ! empty( $user->errors['pending_approval'] ) && ! empty( $messages['pending_approval'] ) ) {
					$user->errors['pending_approval'][0] = sprintf( '<strong>%s:</strong> %s', __( 'ERROR', 'user-registration' ), $messages['pending_approval'] );
				}
				if ( ! empty( $user->errors['denied_access'] ) && ! empty( $messages['denied_access'] ) ) {
					$user->errors['denied_access'][0] = sprintf( '<strong>%s:</strong> %s', __( 'ERROR', 'user-registration' ), $messages['denied_access'] );
				}
				if ( ! empty( $user->errors['incorrect_password'] ) && ! empty( $messages['incorrect_password'] ) ) {
					// Replace the label placeholder with username or email address based on the entered value.
					if ( is_email( $login_data['user_login'] ) ) {
						$label = esc_html__( 'email address', 'user-registration' );
					} else {
						$label = esc_html__( 'username', 'user-registration' );
					}

					$messages['incorrect_password'] = str_replace( '%label%', $label, $messages['incorrect_password'] );
					$messages['incorrect_password'] = str_replace( '%email%', $login_data['user_login'], $messages['incorrect_password'] );

					$user->errors['incorrect_password'][0] = sprintf( '<strong>%s:</strong> %s', __( 'ERROR', 'user-registration' ), $messages['incorrect_password'] );
				}

				$message = $user->get_error_message();
				$message = str_replace( '<strong>' . esc_html( $login_data['user_login'] ) . '</strong>', '<strong>' . esc_html( $username ) . '</strong>', $message );
				throw new Exception( $message );
			} elseif ( isset( $user->ID ) && $is_disabled = get_user_meta( $user->ID, 'ur_disable_users', true ) ) {
				wp_logout();
				throw new Exception( '<strong>' . esc_html__( 'ERROR: ', 'user-registration' ) . '</strong>' . $messages['user_disabled'] );

			} else {

				if ( in_array( 'administrator', $user->roles, true ) && ur_option_checked( 'user_registration_login_options_prevent_core_login', false ) ) {
					$redirect = admin_url();
				} elseif ( ! empty( $post['redirect'] ) ) {
					$redirect = esc_url_raw( wp_unslash( $post['redirect'] ) );
				} elseif ( wp_get_raw_referer() ) {
					if ( get_permalink( get_option( 'user_registration_login_page_id' ) ) === wp_get_raw_referer() || '/login/' === wp_get_raw_referer() ) {
						$redirect = ur_get_my_account_url();
					} else {
						$redirect = wp_get_raw_referer();
					}
				} else {
					$redirect = get_home_url();
				}

				/**
				 * Filters the login redirection.
				 *
				 * @param string $redirect The original redirect URL after successful login.
				 * @param WP_User $user The user object representing the newly registered user.
				 */
				$redirect = apply_filters( 'user_registration_login_redirect', $redirect, $user );

				// Validate above the branch: the AJAX response is navigated to client side, so a fix on the header redirect alone would not cover it.
				$redirect = wp_validate_redirect( $redirect, get_home_url() );

				if ( ur_is_ajax_login_enabled() && empty( $_POST['resubmitted'] ) ) { // phpcs:ignore
					wp_send_json_success( array( 'message' => $redirect ) );
				} else {
					wp_safe_redirect( $redirect );
					exit;
				}
			}
		} catch ( Exception $e ) {
			$status_code = $e->getCode();
			$message     = $e->getMessage();

			if ( $status_code >= 200 && $status_code < 300 ) {
				if ( ur_is_ajax_login_enabled() && empty( $_POST['resubmitted'] ) ) { // phpcs:ignore
					wp_send_json_success(
						array(
							'message' => $message,
							'status'  => true,
						)
					);
				}

				/**
				 * Filters the error messages displayed on the login screen.
				 *
				 * @param string $message The original error message displayed on the login screen.
				 */
				add_filter(
					'user_registration_passwordless_login_notice',
					function ( $err_msg ) use ( $message ) {
						return $message;
					},
					10,
					1
				);
			} else {

				if ( ur_is_ajax_login_enabled() && empty( $_POST['resubmitted'] ) ) { // phpcs:ignore
					wp_send_json_error(
						array(
							'message' => apply_filters( 'login_errors', $message ),
						)
					);
				}

				$error_message = apply_filters( 'login_errors', $message );
				$error_key     = 'ur_login_error_' . uniqid();
				set_transient( $error_key, $error_message, 300 ); // 5 minutes

				/**
				 * Triggered when a user fails to log in during the user registration process.
				 */
				do_action( 'user_registration_login_failed' );

				$referer = wp_get_raw_referer();

				// wp_validate_redirect() returns an empty string for empty input, not the fallback, so guard it explicitly.
				$redirect_url = $referer ? wp_validate_redirect( $referer, ur_get_my_account_url() ) : ur_get_my_account_url();
				$redirect_url = add_query_arg( 'urm_error', $error_key, $redirect_url );
				wp_safe_redirect( $redirect_url );
				exit;

			}
		}
	}
}

if ( ! function_exists( 'ur_process_registration' ) ) {
	/**
	 * Process Registration.
	 *
	 * @param string $nonce_value Nonce value.
	 * @return void
	 */
	function ur_process_registration( $nonce_value ) {
		/**
		 * Filter to modify user capability.
		 * Default value is 'create_users'.
		 */
		$logger = ur_get_logger();

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		$logger->notice(
			sprintf( '[Form #%d] =============== ***USER REGISTRATION STARTED*** ===============', $form_id ),
			array(
				'source'  => 'form-submission',
				'form_id' => $form_id,
			)
		);

		$logger->debug(
			sprintf( '[Form #%d] Function == ***%s()*** - Started execution.', $form_id, __FUNCTION__ ),
			array(
				'source'   => 'form-submission',
				'form_id'  => $form_id,
				'function' => __FUNCTION__,
			)
		);

		$logger->info(
			sprintf( '[Form #%d] Checking user capability permissions...', $form_id ),
			array(
				'source'  => 'form-submission',
				'form_id' => $form_id,
			)
		);

		$current_user_capability = apply_filters( 'ur_registration_user_capability', 'create_users' );

		if ( is_user_logged_in() && ! current_user_can( 'administrator' ) && ! current_user_can( $current_user_capability ) ) { //phpcs:ignore
			$logger->warning(
				sprintf( '[Form #%d] User is already logged in and does not have permission to register a new account.', $form_id ) . "\n  ",
				array(
					'source'  => 'form-submission',
					'form_id' => $form_id,
				)
			);

			wp_send_json_error(
				array(
					'message' => __( 'You are already logged in.', 'user-registration' ),
				)
			);
		}

		$logger->info(
			sprintf( '[Form #%d] Processing form submission.', $form_id ),
			array(
				'source'  => 'form-submission',
				'form_id' => $form_id,
			)
		);

		$captcha_response = isset( $_POST['captchaResponse'] ) ? ur_clean( wp_unslash( $_POST['captchaResponse'] ) ) : ''; //phpcs:ignore

		$recaptcha_enabled   = ur_string_to_bool( ur_get_form_setting_by_key( $form_id, 'user_registration_form_setting_enable_recaptcha_support', false ) );
		$recaptcha_type      = get_option( 'user_registration_captcha_setting_recaptcha_version', 'v2' );
		$recaptcha_type      = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_configured_captcha_type', $recaptcha_type );
		$invisible_recaptcha = ur_option_checked( 'user_registration_captcha_setting_invisible_recaptcha_v2', false );

		if ( 'v2' === $recaptcha_type && ! $invisible_recaptcha ) {
			$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key' );
			$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret' );
		} elseif ( 'v2' === $recaptcha_type && $invisible_recaptcha ) {
			$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_key' );
			$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_secret' );
		} elseif ( 'v3' === $recaptcha_type ) {
			$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_v3' );
			$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_v3' );
		} elseif ( 'hCaptcha' === $recaptcha_type ) {
			$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_hcaptcha' );
			$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha' );
		} elseif ( 'cloudflare' === $recaptcha_type ) {
			$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_cloudflare' );
			$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_cloudflare' );
		}

		if ( $recaptcha_enabled && ! empty( $site_key ) && ! empty( $secret_key ) ) {
			if ( ! empty( $captcha_response ) ) {
				if ( 'hCaptcha' === $recaptcha_type ) {
					$data = wp_safe_remote_get( 'https://hcaptcha.com/siteverify?secret=' . $secret_key . '&response=' . $captcha_response );
					$data = json_decode( wp_remote_retrieve_body( $data ) );

					/**
					 * Filter to modify hcaptcha threshold.
					 * Default value is 0.5
					 */
					if ( empty( $data->success ) || ( isset( $data->score ) && $data->score < apply_filters( 'user_registration_hcaptcha_threshold', 0.5 ) ) ) {
						$logger->error(
							sprintf( '[Form #%d] hCaptcha validation failed. Submission could not be verified.', $form_id ) . "\n  ",
							array(
								'source'  => 'form-submission',
								'form_id' => $form_id,
							)
						);

						wp_send_json_error(
							array(
								'message' => __( 'Error on hCaptcha. Contact your site administrator.', 'user-registration' ),
							)
						);
					}
				} elseif ( 'cloudflare' === $recaptcha_type ) {
					$url    = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
					$params = array(
						'method' => 'POST',
						'body'   => array(
							'secret'   => $secret_key,
							'response' => $captcha_response,
						),
					);
					$data   = wp_safe_remote_post( $url, $params );
					$data   = json_decode( wp_remote_retrieve_body( $data ) );

					if ( empty( $data->success ) ) {
						$logger->error(
							sprintf( '[Form #%d] Cloudflare Turnstile verification failed. Submission could not be verified.', $form_id ) . "\n  ",
							array(
								'source'  => 'form-submission',
								'form_id' => $form_id,
							)
						);

						wp_send_json_error(
							array(
								'message' => __( 'Error on Cloudflare Turnstile. Contact your site administrator.', 'user-registration' ),
							)
						);
					}
				} else {
					$data = wp_remote_get( 'https://www.google.com/recaptcha/api/siteverify?secret=' . $secret_key . '&response=' . $captcha_response );
					$data = json_decode( wp_remote_retrieve_body( $data ) );

					/**
					 * Filter to modify V3 recaptcha threshold.
					 * Default value is 0.5
					 */
					if ( empty( $data->success ) || ( isset( $data->score ) && $data->score < apply_filters( 'user_registration_recaptcha_v3_threshold', 0.5 ) ) ) {
						$logger->error(
							sprintf( '[Form #%d] Google reCAPTCHA verification failed. Submission could not be verified.', $form_id ) . "\n  ",
							array(
								'source'  => 'form-submission',
								'form_id' => $form_id,
							)
						);

						wp_send_json_error(
							array(
								'message' => __( 'Error on google reCaptcha. Contact your site administrator.', 'user-registration' ),
							)
						);
					}
				}
			} else {
				$logger->error(
					sprintf( '[Form #%d] Captcha response is missing from the submitted form data.', $form_id ) . "\n  ",
					array(
						'source'  => 'form-submission',
						'form_id' => $form_id,
					)
				);

				wp_send_json_error(
					array(
						'message' => get_option( 'user_registration_form_submission_error_message_recaptcha', __( 'Captcha code error, please try again.', 'user-registration' ) ),
					)
				);
			}
		}

		if ( ! is_user_logged_in() && ! ur_users_can_register() ) {
			$logger->warning(
				sprintf( '[Form #%d] Registration is disabled by the site administrator.', $form_id ) . "\n",
				array(
					'source'  => 'form-submission',
					'form_id' => $form_id,
				)
			);

			wp_send_json_error(
				array(
					'message' => __( 'Registration is currently disabled.', 'user-registration' ),
				)
			);
		}

		if ( is_user_logged_in() ) {
			/**
			 * Filter to modify user capability.
			 * Default value is 'create_users'.
			 */
			$current_user_capability = apply_filters( 'ur_registration_user_capability', 'create_users' );

			if ( ! current_user_can( $current_user_capability ) ) {
				global $wp;

				$logger->warning(
					sprintf( '[Form #%d] Logged-in user does not have the required capability to proceed with registration.', $form_id ) . "\n",
					array(
						'source'  => 'form-submission',
						'form_id' => $form_id,
					)
				);

				$user_ID      = get_current_user_id();
				$user         = get_user_by( 'ID', $user_ID );
				$current_url  = home_url( add_query_arg( array(), $wp->request ) );
				$display_name = ! empty( $user->data->display_name ) ? $user->data->display_name : $user->data->user_email;

				wp_send_json_error(
					array(
						/**
						 * Filter to modify register pre form message.
						 */
						'message' => apply_filters(
							'ur_register_pre_form_message',
							'<p class="alert" id="ur_register_pre_form_message">' .
							/* translators: %1$1s - Link to logout. */
							sprintf( __( 'You are currently logged in as %1$1s. %2$2s', 'user-registration' ), '<a href="#" title="' . $display_name . '">' . $display_name . '</a>', '<a href="' . wp_logout_url( $current_url ) . '" title="' . __( 'Log out of this account.', 'user-registration' ) . '">' . __( 'Logout', 'user-registration' ) . '  &raquo;</a>' ) . '</p>',
							$user_ID
						),
					)
				);
			}
		}

		$form_data = array();

		$logger->info(
			sprintf( '[Form #%d] Receiving submitted form data.', $form_id ),
			array(
				'source'  => 'form-submission',
				'form_id' => $form_id,
			)
		);

		if ( isset( $_POST['form_data'] ) ) {
			$form_data = json_decode( wp_unslash( $_POST['form_data'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$loggable_form_data = array();

		if ( is_array( $form_data ) ) {
			foreach ( $form_data as $field ) {
				$log_field = clone $field;

				if ( isset( $log_field->field_type ) && 'password' === $log_field->field_type ) {
					$log_field->value = '********';
				}

				$loggable_form_data[] = $log_field;
			}
		}

		$logger->info(
			sprintf( '[Form #%d] Form data received successfully.', $form_id ) . "\n" . wp_json_encode( $loggable_form_data, JSON_PRETTY_PRINT ),
			array(
				'source'  => 'form-submission',
				'form_id' => $form_id,
			)
		);

		UR_Frontend_Form_Handler::handle_form( $form_data, $form_id );

		$logger->success(
			sprintf( '[Form #%d] Form submission processed successfully.', $form_id ) . "\n",
			array(
				'source'  => 'form-submission',
				'form_id' => $form_id,
			)
		);
	}
}

if ( ! function_exists( 'paypal_generate_redirect_url' ) ) {
	/**
	 * Regenerate PayPal redirect URL to pay after
	 *
	 * @param int $user_id User Id.
	 *
	 * @return string redirect url
	 */
	function paypal_generate_redirect_url( $user_id ) {

		// Check an user was created and passed.
		if ( empty( $user_id ) ) {
			return '#';
		}

		// Filter redirect url for other payment add-ons.
		if ( 'paypal_standard' !== get_user_meta( $user_id, 'ur_payment_method', true ) ) {
			return apply_filters( 'user_registration_payment_generate_redirect_url', '#', $user_id );
		}

		$form_id   = get_user_meta( $user_id, 'ur_form_id', true );
		$user_data = get_user_by( 'id', $user_id );

		// Get data from saved user and for.
		$currency       = get_user_meta( $user_id, 'ur_payment_currency', true );
		$payment_mode   = get_user_meta( $user_id, 'ur_payment_mode', true );
		$payment_type   = get_user_meta( $user_id, 'ur_payment_type', true );
		$receiver_email = get_user_meta( $user_id, 'ur_payment_recipient', true );
		$total_amount   = get_user_meta( $user_id, 'ur_payment_total_amount', true );
		$ur_cart_items  = json_decode( get_user_meta( $user_id, 'ur_cart_items', true ) );

		$cancel_url = ur_get_single_post_meta( $form_id, 'user_registration_paypal_cancel_url', home_url() );
		$return_url = ur_get_single_post_meta( $form_id, 'user_registration_paypal_return_url', wp_login_url() );

		if ( empty( $form_id ) || empty( $currency ) || empty( $payment_mode ) || empty( $payment_type ) || empty( $receiver_email ) || empty( $total_amount ) ) {
			return '#';
		}

		// Build the return URL with hash.
		$query_args = 'form_id=' . absint( $form_id ) . '&user_id=' . absint( $user_id ) . '&hash=' . wp_hash( $form_id . ',' . $user_id );

		$return_url = esc_url_raw(
			add_query_arg(
				array(
					'user_registration_return' => base64_encode( $query_args ),
				),
				$return_url
			)
		);

		$redirect   = ( 'production' === $payment_mode ) ? 'https://www.paypal.com/cgi-bin/webscr/?' : 'https://www.sandbox.paypal.com/cgi-bin/webscr/?';
		$cancel_url = ! empty( $cancel_url ) ? esc_url_raw( $cancel_url ) : home_url();

		// Subscription.
		$paypal_recurring_enabled = ur_string_to_bool( ur_get_single_post_meta( $form_id, 'user_registration_enable_paypal_standard_subscription', false ) );
		if ( $paypal_recurring_enabled ) {
			$transaction = '_xclick-subscriptions';
		} elseif ( 'donation' === $payment_type ) {
			$transaction = '_donations';
		} else {
			$transaction = '_cart';
		}
		// Setup PayPal arguments.
		$paypal_args = array(
			'bn'            => 'UserRegistration_SP',
			'business'      => sanitize_email( $receiver_email ),
			'cancel_return' => $cancel_url,
			'cbt'           => get_bloginfo( 'name' ),
			'charset'       => get_bloginfo( 'charset' ),
			'cmd'           => $transaction,
			'currency_code' => strtoupper( $currency ),
			'custom'        => absint( $form_id ),
			'invoice'       => absint( $user_id ),
			'notify_url'    => add_query_arg( 'user-registration-listener', 'IPN', home_url( 'index.php' ) ),
			'return'        => $return_url,
			'rm'            => '2',
			'tax'           => 0,
			'upload'        => '1',
			'amount'        => $total_amount,
			'sra'           => '1',
			'src'           => '1',
			'no_note'       => '1',
			'no_shipping'   => '1',
			'shipping'      => '0',
		);

		// Add cart items.
		if ( '_cart' === $transaction ) {

			// Product/service.
			$i = 1;

			if ( ! empty( $ur_cart_items ) ) {
				$i = 1;
				foreach ( $ur_cart_items as $key => $payment_items ) {
					$quantity = isset( $payment_items->quantity ) ? $payment_items->quantity : '';
					$amount   = isset( $payment_items->amount ) ? $payment_items->amount : '';

					if ( is_object( $payment_items->value ) ) {

						foreach ( $payment_items->value as $label => $value ) {

							if ( ! empty( $quantity ) ) {
								$paypal_args[ 'item_name_' . $i ] = $label;
								$paypal_args[ 'amount_' . $i ]    = $quantity * $value;

							} else {
								$paypal_args[ 'item_name_' . $i ] = $label;
								$paypal_args[ 'amount_' . $i ]    = $value;
							}
							++$i;
						}
					} elseif ( ! empty( $quantity ) ) {
						$paypal_args[ 'item_name_' . $i ] = $payment_items->extra_params->label;
						$paypal_args[ 'amount_' . $i ]    = $amount;

					} else {

						$paypal_args[ 'item_name_' . $i ] = $payment_items->extra_params->label;
						$paypal_args[ 'amount_' . $i ]    = $payment_items->value;
					}
					++$i;
				}
			}
		} elseif ( '_donations' === $transaction ) {

			// Combine a donation name from all payment fields names.
			$item_names = array();

			if ( ! empty( $ur_cart_items ) ) {
				foreach ( $ur_cart_items as $key => $payment_items ) {
					if ( is_object( $payment_items->value ) ) {
						foreach ( $payment_items->value as $label => $value ) {
							$item_names[] = $label;
						}
					} else {
						$item_names[] = $payment_items->extra_params->label;
					}
				}
			}

			$paypal_args['item_name'] = implode( '; ', $item_names );
			$paypal_args['amount']    = $total_amount;
		} else {
			$customer_email          = isset( $user_data->user_email ) ? $user_data->email : '';
			$post_data               = ur_get_post_content( $form_id );
			$subscription_plan_field = ur_get_form_data_by_key( $post_data, 'subscription_plan' );

			if ( ! empty( $subscription_plan_field ) ) {
				if ( isset( $subscription_plan_field['subscription_plan']->general_setting->options ) ) {
					$plan_lists = $subscription_plan_field['subscription_plan']->general_setting->options;
					if ( ! empty( $plan_lists ) ) {

						foreach ( $plan_lists as $plan ) {
							$interval_count   = $plan->interval_count;
							$plan_name        = $plan->label;
							$recurring_period = $plan->recurring_period;
						}
					}
				}
			} else {
				$plan_name        = ur_get_single_post_meta( $form_id, 'user_registration_paypal_plan_name', '' );
				$recurring_period = ur_get_single_post_meta( $form_id, 'user_registration_paypal_recurring_period' );
				$interval_count   = ur_get_single_post_meta( $form_id, 'user_registration_paypal_interval_count', '1' );
			}
			$paypal_args['email']     = $customer_email;
			$paypal_args['a3']        = $total_amount;
			$paypal_args['item_name'] = ! empty( $plan_name ) ? $plan_name : '';
			$paypal_args['t3']        = ! empty( $recurring_period ) ? strtoupper( substr( $recurring_period, 0, 1 ) ) : '';
			$paypal_args['p3']        = ! empty( $interval_count ) ? $interval_count : 1;
		}

		// Build query.
		$redirect .= http_build_query( $paypal_args );
		$redirect  = str_replace( '&amp;', '&', $redirect );

		return $redirect;
	}
}

if ( ! function_exists( 'ur_generate_onetime_token' ) ) {
	/**
	 * Generate a one-time token for the given user ID and action.
	 *
	 * @param int    $user_id The ID of the user for whom to generate the token.
	 * @param string $action The action for which to generate the token.
	 * @param int    $key_length The length of the random key to be generated. Defaults to 32.
	 * @param int    $expiration_time The duration of the token's validity in minutes. Defaults to 60.
	 *
	 * @return string The generated one-time token.
	 */
	function ur_generate_onetime_token( $user_id = 0, $action = '', $key_length = 32, $expiration_time = 60 ) {
		$time = time();
		$key  = wp_generate_password( $key_length, false );

		// Concatenate the key, action, and current time to form the token string.
		$string = $key . $action . $time;

		// Generate the token hash.
		$token = wp_hash( $string );

		// Set the token expiration time in seconds.
		$expiration = apply_filters( $action . '_onetime_token_expiration', $expiration_time * 60 );

		// Set the user meta values for the token and expiration time.
		update_user_meta( $user_id, $action . '_token' . $user_id, $token );
		update_user_meta( $user_id, $action . '_token_expiration' . $user_id, $time + $expiration );

		return $token;
	}
}

if ( ! function_exists( 'ur_get_current_page_url' ) ) {
	/**
	 * Get the current page URL.
	 *
	 * @return string The URL of the current page.
	 */
	function ur_get_current_page_url() {
		$page_url = '';

		if ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ) {
			$page_url .= 'https://';
		} else {
			$page_url .= 'http://';
		}

		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$page_url .= sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		}

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$page_url .= sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}

		return $page_url;
	}
}

if ( ! function_exists( 'ur_is_passwordless_login_enabled' ) ) {
	/**
	 * Check whether the passwordless login is enabled or not.
	 *
	 * @return bool
	 */
	function ur_is_passwordless_login_enabled() {
		return ur_option_checked( 'user_registration_pro_passwordless_login', false );
	}
}

if ( ! function_exists( 'ur_is_user_registration_pro_passwordless_login_default_login_area_enabled' ) ) {
	/**
	 * Check whether the passwordless login as default login is enabled or not.
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	function ur_is_user_registration_pro_passwordless_login_default_login_area_enabled() {
		return ur_option_checked( 'user_registration_pro_passwordless_login_default_login_area', false );
	}
}

if ( ! function_exists( 'ur_get_ip_address' ) ) {

	/**
	 * Get current user IP Address.
	 *
	 * @return string
	 */
	function ur_get_ip_address() {
		if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) { // WPCS: input var ok, CSRF ok.
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );  // WPCS: input var ok, CSRF ok.
		} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) { // WPCS: input var ok, CSRF ok.
			// Proxy servers can send through this header like this: X-Forwarded-For: client1, proxy1, proxy2
			// Make sure we always only send through the first IP in the list which should always be the client IP.
			$forwarded_ip = trim( current( preg_split( '/,/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) ); // WPCS: input var ok, CSRF ok.
			// Remove the port from '<IPv4>:<port>', '[<IPv6>]' and '[<IPv6>]:<port>' while leaving bare IPv4/IPv6 intact.
			$forwarded_ip = preg_replace( '/([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\:.*|\[([^]]+)\].*/', '$1$2', $forwarded_ip );
			return (string) rest_is_ip_address( $forwarded_ip );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) { // @codingStandardsIgnoreLine
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // @codingStandardsIgnoreLine
		}

		return '';
	}
}

if ( ! function_exists( 'ur_get_all_pages' ) ) {
	/**
	 * Returns map of published pages as id->title format.
	 *
	 * @return array
	 */
	function ur_get_all_pages() {
		$pages = get_pages();

		$pages_array = array();

		foreach ( $pages as $page ) {
			$pages_array[ $page->ID ] = $page->post_title;
		}

		return $pages_array;
	}
}


if ( ! function_exists( 'user_registration_process_email_content' ) ) {
	/**
	 * Returns email content wrapped in email template.
	 *
	 * @param string $email_content Email Content.
	 * @param string $template Email Template id.
	 */
	function user_registration_process_email_content( $email_content, $template = '' ) {

		if ( UR_PRO_ACTIVE ) {
			// Delegate to pro function if available to use header/footer wrapper.
			if ( function_exists( 'ur_pro_user_registration_process_email_content' ) ) {
				return ur_pro_user_registration_process_email_content( $email_content, $template );
			}
		}

		// Check if email template is selected.
		if ( '' !== $template && 'none' !== $template ) {
			/**
			 * Filters the email template message.
			 *
			 * The 'user_registration_email_template_message' filter allows developers to modify
			 * the content of the email template used during the user registration process. It provides
			 * an opportunity to customize the email content based on the original content and the template.
			 *
			 * @param string $email_content The original content of the email template.
			 * @param string $template The template being used for the email.
			 */
			$email_content = apply_filters( 'user_registration_email_template_message', $email_content, $template );
		} else {
			$default_width = '50%';

			/**
			 * Filters to change the email body width.
			 *
			 * The 'user_registration_email_body_width' filter allows developers to modify
			 * the width of the email body used during the user registration process. It provides
			 * an opportunity to customize the width of the email body based as per user requirements.
			 *
			 * @param string $default_width The default width.
			 */
			$email_body_width = apply_filters( 'user_registration_email_body_width', $default_width );
			ob_start();
			?>
			<div class="user-registration-email-body" style="padding: 100px 0; background-color: #ebebeb;">
				<table class="user-registration-email" border="0" cellpadding="0" cellspacing="0"
						style="width: <?php echo esc_attr( $email_body_width ); ?>; margin: 0 auto; background: #ffffff; padding: 30px 30px 26px; border: 0.4px solid #d3d3d3; border-radius: 11px; font-family: 'Segoe UI', sans-serif; ">
					<tbody>
					<tr>
						<td colspan="2" style="text-align: left;">
							<?php echo wp_kses_post( $email_content ); ?>
						</td>
					</tr>
					</tbody>
				</table>
			</div>
			<?php
			$email_content = wp_kses_post( ob_get_clean() );
		}

		return $email_content;
	}
}

if ( ! function_exists( 'ur_wrap_email_body_content' ) ) {
	/**
	 * Wrap email body content with responsive email wrapper.
	 *
	 * @param string $body_content Email body content to wrap.
	 *
	 * @return string Wrapped email content.
	 */
	function ur_wrap_email_body_content( $body_content ) {
		// Check if we're in editor context - exclude CSS when displaying editor on settings page.
		$is_preview       = isset( $_GET['ur_email_preview'] );
		$current_screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_settings_page = $current_screen && 'user-registration_page_user-registration-settings' === $current_screen->id;
		$is_email_action  = isset( $_REQUEST['action'] ) && ( // phpcs:ignore
				'ur_send_test_email' === $_REQUEST['action'] || // phpcs:ignore
				strpos( $_REQUEST['action'], 'email' ) !== false // phpcs:ignore
			);

		$is_editor_context = is_admin() && ! $is_preview && $is_settings_page && ! $is_email_action &&
							! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) &&
							! ( defined( 'DOING_AJAX' ) && DOING_AJAX && $is_email_action );

		$responsive_styles = '';
		if ( ! $is_editor_context ) {
			$responsive_styles = '<div style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;line-height:0;font-size:0;overflow:hidden;mso-hide:all;">
		<style type="text/css">
			@media only screen and (max-width: 600px) {
				.email-wrapper-outer {
					padding: 20px 0 !important;
				}
				.email-wrapper-inner {
					width: 100% !important;
					max-width: 100% !important;
					margin: 0 !important;
					border-radius: 0 !important;
				}
				.email-header {
					padding: 20px 15px !important;
					border-radius: 0 !important;
				}
				.email-body {
					padding: 25px 15px !important;
				}
				.email-footer {
					padding: 20px 15px !important;
				}
				.email-logo img {
					max-width: 150px !important;
					max-height: 50px !important;
				}
				.email-header-text {
					font-size: 16px !important;
					margin-top: 10px !important;
				}
				.email-footer p {
					font-size: 12px !important;
				}
				.email-footer a {
					font-size: 13px !important;
				}
			}
			@media only screen and (max-width: 480px) {
				.email-wrapper-outer {
					padding: 10px 0 !important;
				}
				.email-header {
					padding: 15px 10px !important;
				}
				.email-body {
					padding: 20px 10px !important;
				}
				.email-footer {
					padding: 15px 10px !important;
				}
				.email-logo img {
					max-width: 120px !important;
					max-height: 40px !important;
				}
				.email-header-text {
					font-size: 14px !important;
				}
			}
		</style>
		</div>';
		}

		$is_preview  = isset( $_GET['ur_email_preview'] ) && 'email_template_option' === sanitize_text_field( wp_unslash( $_GET['ur_email_preview'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$email_width = $is_preview ? '600px' : 'unset';
		$max_width   = '600px';

		return $responsive_styles . '
	<div class="email-wrapper-outer" style="font-family: Arial, sans-serif; padding: 100px 0;">
	<div class="email-wrapper-inner" style="width: ' . esc_attr( $email_width ) . '; max-width: ' . esc_attr( $max_width ) . '; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
	<div class="email-body" style="padding: 30px; background-color: #ffffff;">' . $body_content . '</div></div></div>';
	}
}

if ( ! function_exists( 'ur_unwrap_email_body_content' ) ) {
	/**
	 * Unwrap email body content by removing wrapper HTML and style tags.
	 * Used when loading email content into the editor.
	 *
	 * @param string $wrapped_content Wrapped email content.
	 *
	 * @return string Unwrapped email content.
	 */
	function ur_unwrap_email_body_content( $wrapped_content ) {
		if ( empty( $wrapped_content ) ) {
			return $wrapped_content;
		}

		$content = $wrapped_content;

		// Remove style tags (including those with type="text/css").
		$content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );

		if ( strpos( $content, 'email-wrapper-outer' ) !== false || strpos( $content, 'email-body' ) !== false ) {
			if ( preg_match( '/<div[^>]*class=["\'][^"\']*email-body[^"\']*["\'][^>]*>(.*)<\/div>\s*<\/div>\s*<\/div>/is', $content, $matches ) ) {
				$content = $matches[1];
			} elseif ( preg_match( '/<div[^>]*class=["\'][^"\']*email-body[^"\']*["\'][^>]*>(.*?)<\/div>/is', $content, $matches ) ) {
				$content = $matches[1];
			} else {
				$content = preg_replace( '/<div[^>]*class=["\'][^"\']*email-wrapper-outer[^"\']*["\'][^>]*>/is', '', $content );
				$content = preg_replace( '/<div[^>]*class=["\'][^"\']*email-wrapper-inner[^"\']*["\'][^>]*>/is', '', $content );
				$content = preg_replace( '/<div[^>]*class=["\'][^"\']*email-body[^"\']*["\'][^>]*>/is', '', $content );
				$content = preg_replace( '/(<\/div>\s*){1,3}\s*$/is', '', $content );
			}
		}

		$content = trim( $content );

		return $content;
	}
}

if ( ! function_exists( 'ur_email_preview_link' ) ) {

	/**
	 * Get link for preview email button used on email settings.
	 *
	 * @param string $label Label.
	 * @param string $email_id Email id.
	 */
	function ur_email_preview_link( $label, $email_id ) {
		$url = add_query_arg(
			array(
				'ur_email_preview' => $email_id,
			),
			home_url()
		);

		return '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $label ) . '" class="button user-registration-email-preview urm-btn-comp-v7 " style="min-width:70px; ">' . esc_html( $label ) . '</a>';
	}
}

add_action( 'user_registration_after_user_meta_update', 'ur_parse_and_update_hidden_field', 10, 3 );

if ( ! function_exists( 'ur_parse_and_update_hidden_field' ) ) {
	/**
	 * Parse the hidden field value and update.
	 *
	 * @param array $form_data form data.
	 * @param int   $form_id form id.
	 * @param int   $user_id user id.
	 */
	function ur_parse_and_update_hidden_field( $form_data, $form_id, $user_id ) {
		$values = array(
			'form_id'      => $form_id,
			'process_type' => 'ur_parse_after_meta_update',
		);

		foreach ( $form_data as $key => $value ) {
			if ( ! isset( $value->field_name ) ) {
				continue;
			}

			$field_value = $value->value ?? '';

			if ( 'user_email' === $value->field_name ) {
				$values['email'] = ur_format_field_values( $value->field_name, $field_value );
			}

			$values[ $value->field_name ] = ur_format_field_values( $value->field_name, $field_value );
		}

		foreach ( $form_data as $key => $value ) {
			if ( isset( $value->extra_params['field_key'] ) && 'hidden' === $value->extra_params['field_key'] ) {
				$content    = $value->value;
				$field_name = 'user_registration_' . $value->field_name;
				if ( '' !== $content ) {
					/**
					 * Filters the processed content of smart tags.
					 *
					 * The 'user_registration_process_smart_tags' filter allows developers to modify
					 * the content of smart tags processed during the user registration process. It provides
					 * an opportunity to customize the content based on the original content and the values
					 * of the smart tags.
					 *
					 * @param string $content The original content containing smart tags.
					 * @param array $values The values of smart tags processed during registration.
					 */
					$content = apply_filters( 'user_registration_process_smart_tags', $content, $values );
					update_user_meta( $user_id, $field_name, $content );
				}
			}
		}
	}
}

if ( ! function_exists( 'ur_maybe_unserialize' ) ) {
	/**
	 * UR Unserialize data.
	 *
	 * @param string $data Data that might be unserialized.
	 * @param array  $options Options.
	 *
	 * @return mixed Unserialized data can be any type.
	 *
	 * @since 3.0.2
	 */
	function ur_maybe_unserialize( $data, $options = array() ) {

		if ( is_serialized( $data ) ) {
			if ( version_compare( PHP_VERSION, '7.1.0', '>=' ) ) {
				$options = wp_parse_args( $options, array( 'allowed_classes' => false ) );

				return @unserialize( trim( $data ), $options ); //phpcs:ignore.
			}

			return @unserialize( trim( $data ) ); //phpcs:ignore.
		}

		return $data;
	}
}

if ( ! function_exists( 'user_registration_conditional_user_meta_filter' ) ) {
	/**
	 * Filter user meta field when conditinal logic applied.
	 *
	 * @param array $valid_form_data Form Data.
	 * @param int   $user_id User Id.
	 * @param int   $form_id Form Id.
	 *
	 * @return array array of form data.
	 *
	 * @since 3.0.4
	 */
	function user_registration_conditional_user_meta_filter( $valid_form_data, $user_id, $form_id ) {
		if ( $user_id <= 0 ) {
			return $valid_form_data;
		}

		$field_name   = '';
		$hidden_field = isset( $_POST['urcl_hide_fields'] ) ? ur_clean( $_POST['urcl_hide_fields'] ) : array(); //phpcs:ignore.

		if ( empty( $hidden_field ) ) {
			return $valid_form_data;
		}

		$hidden_array_field = json_decode( stripslashes( $hidden_field ) );

		if ( isset( $_POST['action'] ) && 'user_registration_user_form_submit' === $_POST['action'] ) { //phpcs:ignore.
			foreach ( $hidden_array_field as $field ) {
				$field_name = $field;
				if ( in_array( $field_name, array_keys( $valid_form_data ) ) ) {
					unset( $valid_form_data[ $field_name ] );
				}
			}
		} else {
			foreach ( $hidden_array_field as $field ) {
				$field_name = 'user_registration_' . $field;
				if ( in_array( $field_name, array_keys( $valid_form_data ) ) ) {
					unset( $valid_form_data[ $field_name ] );
				}
			}
		}

		return $valid_form_data;
	}
}

add_filter( 'user_registration_before_user_meta_update', 'user_registration_conditional_user_meta_filter', 10, 3 );
add_filter( 'user_registration_before_save_profile_details', 'user_registration_conditional_user_meta_filter', 10, 3 );

if ( ! function_exists( 'ur_get_ip_address' ) ) {
	/**
	 * Get current user IP Address.
	 *
	 * @return string
	 */
	function ur_get_ip_address() {
		if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) { // WPCS: input var ok, CSRF ok.
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );  // WPCS: input var ok, CSRF ok.
		} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) { // WPCS: input var ok, CSRF ok.
			// Proxy servers can send through this header like this: X-Forwarded-For: client1, proxy1, proxy2.
			// Make sure we always only send through the first IP in the list which should always be the client IP.
			$forwarded_ip = trim( current( preg_split( '/,/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) ); // WPCS: input var ok, CSRF ok.
			// Remove the port from '<IPv4>:<port>', '[<IPv6>]' and '[<IPv6>]:<port>' while leaving bare IPv4/IPv6 intact.
			$forwarded_ip = preg_replace( '/([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\:.*|\[([^]]+)\].*/', '$1$2', $forwarded_ip );
			return (string) rest_is_ip_address( $forwarded_ip );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) { // @codingStandardsIgnoreLine
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // @codingStandardsIgnoreLine
		}

		return '';
	}
}

if ( ! function_exists( 'ur_get_all_page_slugs' ) ) {
	/**
	 * Get all the page slugs.
	 */
	function ur_get_all_page_slugs() {
		$args = array(
			'post_type'      => 'page',
			'posts_per_page' => - 1,
		);

		$pages = get_pages( $args );

		$slugs = array();

		foreach ( $pages as $page ) {
			$slugs[] = $page->post_name;
		}

		return $slugs;
	}
}

if ( ! function_exists( 'ur_add_links_to_top_nav' ) ) {
	/**
	 * Add plugin specific links to the admin bar menu.
	 *
	 * @param [WP_Admin_Bar] $wp_admin_bar Admin Bar.
	 *
	 * @return void
	 */
	function ur_add_links_to_top_nav( $wp_admin_bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_user_registration' ) ) {
			return;
		}

		if ( apply_filters( 'user_registration_show_link_to_admin_top_nav', false ) ) {
			return;
		}
		/**
		 * Add User Registration links in the admin top nav bar.
		 */

		$wp_admin_bar->add_menu(
			array(
				'id'     => 'user-registration-menu',
				'parent' => null,
				'group'  => null,
				'title'  => __( 'User Registration', 'user-registration' ),
				// you can use img tag with image link. it will show the image icon Instead of the title.
				'href'   => admin_url( 'admin.php?page=user-registration' ),
			)
		);

		/**
		 * Add Edit Form link in Form Preview Page.
		 */

		$form_id = 0;

		if ( isset( $_GET['ur_preview'] ) && isset( $_GET['form_id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$form_id = sanitize_text_field( wp_unslash( $_GET['form_id'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( is_page() || is_single() ) {

			if ( isset( $_GET['vc_editable'] ) ) { // phpcs:ignore
				return;
			}
			$post_content = get_the_content();

			if ( has_shortcode( $post_content, 'user_registration_form' ) ) {
				if ( preg_match( '/\[user_registration_form id="(\d+)"\]/', $post_content, $matches ) ) {
					$form_id = $matches[1];
				}
			}
		}

		if ( ! empty( $form_id ) ) {
			$wp_admin_bar->add_menu(
				array(
					'parent' => 'user-registration-menu',
					'id'     => 'ur-edit-form',
					'title'  => __( 'Edit Form', 'user-registration' ),
					'href'   => add_query_arg(
						'edit-registration',
						$form_id,
						admin_url( 'admin.php?page=add-new-registration' )
					),
					'meta'   => array(
						'target' => '_blank',
						'rel'    => 'noopener noreferrer',
					),
				)
			);
		}

		$wp_admin_bar->add_menu(
			array(
				'parent' => 'user-registration-menu',
				'id'     => 'user-registration-all-forms',
				'title'  => __( 'All Forms', 'user-registration' ),
				'href'   => admin_url( 'admin.php?page=user-registration' ),
			)
		);

		$wp_admin_bar->add_menu(
			array(
				'parent' => 'user-registration-menu',
				'id'     => 'user-registration-add-new',
				'title'  => __( 'Add New', 'user-registration' ),
				'href'   => admin_url( 'admin.php?page=add-new-registration' ),
			)
		);

		$wp_admin_bar->add_menu(
			array(
				'parent' => 'user-registration-menu',
				'id'     => 'user-registration-settings',
				'title'  => __( 'Settings', 'user-registration' ),
				'href'   => admin_url( 'admin.php?page=user-registration-settings' ),
			)
		);

		$href = ur_utm_url(
			'https://docs.wpuserregistration.com/',
			array(
				'source'  => 'admin-bar',
				'medium'  => 'menu-link',
				'content' => 'documentation',
			)
		);

		$wp_admin_bar->add_menu(
			array(
				'parent' => 'user-registration-menu',
				'id'     => 'user-registration-docs',
				'title'  => __( 'Documentation', 'user-registration' ),
				'href'   => $href,
				'meta'   => array(
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
				),
			)
		);
		/**
		 * Triggered to customize the top admin bar menu.
		 *
		 * @param WP_Admin_Bar $wp_admin_bar The WordPress admin bar object.
		 */
		do_action( 'user_registration_top_admin_bar_menu', $wp_admin_bar );
	}

	add_action( 'admin_bar_menu', 'ur_add_links_to_top_nav', 999, 1 );
}

if ( ! function_exists( 'ur_array_clone' ) ) {
	/**
	 * Clone Array or Object
	 *
	 * @param  [mixed] $array Array to clone.
	 *
	 * @since 3.0.5
	 */
	function ur_array_clone( $array ) {
		if ( is_object( $array ) ) {
			return clone $array;
		}
		if ( ! is_array( $array ) ) {
			return $array;
		}

		return array_map(
			function ( $element ) {
				return ( ( is_array( $element ) )
					? array_clone( $element )
					: ( ( is_object( $element ) )
						? clone $element
						: $element
					)
				);
			},
			$array
		);
	}

	if ( ! function_exists( 'ur_unlink_user_profile_pictures' ) ) {
		/**
		 * Remove user uploaded profile pictures and related thumbnail.
		 *
		 * @param int $id User ID.
		 */
		function ur_unlink_user_profile_pictures( $id ) {
			$profile_pic_url = get_user_meta( $id, 'user_registration_profile_pic_url', true );
			if ( ! empty( $profile_pic_url ) ) {

				$profile_id = get_post_meta( $profile_pic_url, '_wp_attachment_metadata' );

				// Unlink profile picture before removing users.
				if ( is_array( $profile_id ) && ! empty( $profile_id ) ) {
					foreach ( $profile_id as $profile ) {
						if ( is_array( $profile ) && isset( $profile['file'] ) ) {
							$base_dir  = wp_upload_dir()['basedir'];
							$file      = $profile['file'];
							$full_path = trailingslashit( $base_dir ) . $file;

							if ( file_exists( $full_path ) ) {
								unlink( $full_path );
							}

							// unlink different size thumbnails of profile picture.
							if ( isset( $profile['sizes'] ) && is_array( $profile['sizes'] ) ) {
								foreach ( $profile['sizes'] as $size ) {
									if ( is_array( $size ) && isset( $size['file'] ) ) {
										$size_file      = $size['file'];
										$full_size_path = UR_UPLOAD_PATH . 'profile-pictures/' . $size_file;

										if ( file_exists( $full_size_path ) ) {
											unlink( $full_size_path );
										}
									}
								}
							}

							// Unlink original uploaded image.
							if ( isset( $profile['original_image'] ) ) {
								$original_file = UR_UPLOAD_PATH . 'profile-pictures/' . $profile['original_image'];
								if ( file_exists( $original_file ) ) {
									unlink( $original_file );
								}
							}
						}
					}
				}
				// Remove profile pictures related metadata from DB.
				delete_post_meta( $profile_pic_url, '_wp_attachment_metadata' );
				delete_post_meta( $profile_pic_url, '_wp_attached_file' );

				// Remove attachments form media library.
				wp_delete_attachment( $profile_pic_url, true );
			}
		}
	}
	add_action( 'ur_remove_profile_pictures_and_metadata', 'ur_unlink_user_profile_pictures' );
}

if ( ! function_exists( 'ur_automatic_user_login' ) ) {
	/**
	 * Automatically login users.
	 *
	 * @param object $user The user.
	 *
	 * @since 3.1.5
	 */
	function ur_automatic_user_login( $user ) {
		delete_transient( 'urm_pending_login_' . $user->ID );
		wp_clear_auth_cookie();
		$remember = apply_filters( 'user_registration_autologin_remember_user', false );
		wp_set_auth_cookie( $user->ID, $remember );

		/**
		 * Filters the login redirection.
		 *
		 * @param string $redirect The original redirect URL after successful login.
		 * @param WP_User $user The user object representing the newly registered user.
		 */
		$redirect = apply_filters( 'user_registration_login_redirect', ur_get_my_account_url(), $user );
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			wp_redirect( esc_url_raw( $redirect ) );
			exit();
		} else {
			wp_send_json_success(
				array(
					'redirect' => esc_url_raw( $redirect ),
				)
			);
		}
	}
}

if ( ! function_exists( 'ur_resend_verification_email' ) ) {
	/**
	 * This function will send email verification email to the user.
	 *
	 * @param int $user_id User ID.
	 *
	 * @since 3.1.5
	 */
	function ur_resend_verification_email( $user_id ) {
		$user    = get_user_by( 'id', $user_id );
		$form_id = ur_get_form_id_by_userid( $user_id );

		$confirm_email = new UR_Email_Confirmation();
		$confirm_email->set_email_status( array(), $form_id, $user_id );
		/**
		 * Filter hook to modify the email attachment resending token.
		 * Default value is empty array.
		 */
		$attachments = apply_filters( 'user_registration_email_attachment_resending_token', array() );
		$name_value  = ur_get_user_extra_fields( $user_id );

		// Get selected email template id for specific form.
		$template_id = ur_get_single_post_meta( $form_id, 'user_registration_select_email_template' );

		UR_Emailer::send_mail_to_user( $user->user_email, $user->user_login, $user_id, '', $name_value, $attachments, $template_id );
	}
}


if ( ! function_exists( 'ur_merge_translations' ) ) {
	/**
	 * Merge Addons Translation in Pro text domain.
	 *
	 * @param string $source_dir Addon Language Source Directory.
	 * @param string $destination_dir Pro Language Directory.
	 * @param string $file_extension File Extentions.
	 * @param string $text_domain Existing Text Domain/ Addon slug.
	 *
	 * @since 4.1.5
	 */
	function ur_merge_translations( $source_dir, $destination_dir, $file_extension, $text_domain ) {
		$source_files = glob( $source_dir . '/*.' . $file_extension );

		foreach ( $source_files as $source_file ) {
			$language_code             = basename( $source_file, '.' . $file_extension );
			$destination_language_code = str_replace( '-' . $text_domain, '', $language_code );

			if ( 'user-registration' === $destination_language_code ) {
				$source_file_path      = $source_dir . '/' . $language_code . '.' . $file_extension; //phpcs:ignore
				$destination_file_path = $destination_dir . '/' . $destination_language_code . '.' . $file_extension; //phpcs:ignore
				if ( ! file_exists( $destination_file_path ) ) {
					touch( $destination_file_path );
				}

				$source_content = file_get_contents( $source_file_path );
				file_put_contents( $destination_file_path, $source_content, FILE_APPEND );
			}
		}
	}
}

if ( ! function_exists( 'user_registration_validate_form_field_data' ) ) {

	/**
	 * Function to validate individual form field data.
	 *
	 * @param object $data Form field data submitted by the user.
	 * @param array  $form_data Form Data.
	 * @param int    $form_id Form id.
	 * @param array  $response_array Response Array.
	 * @param array  $form_field_data Form Field Data..
	 * @param array  $valid_form_data Valid Form Data..
	 */
	function user_registration_validate_form_field_data( $data, $form_data, $form_id, $response_array, $form_field_data, $valid_form_data ) {
		$form_key_list  = wp_list_pluck( wp_list_pluck( $form_field_data, 'general_setting' ), 'field_name' );
		$form_validator = new UR_Form_Validation();

		if ( preg_match( '/^country_/', $data->field_name ) && in_array( $data->field_name, $form_key_list, true ) ) {
			$field_data = array(
				'country' => $data->value,
			);

			foreach ( $form_data as $state ) {
				switch ( $state->field_name ) {
					case $data->field_name . '_state':
						$field_data['state'] = ! empty( $state->value ) ? $state->value : '';
						break;

					default:
						break;
				}
			}
			$data->value = wp_json_encode( $field_data );
		}

		if ( in_array( $data->field_name, $form_key_list, true ) ) {
			$form_data_index    = array_search( $data->field_name, $form_key_list, true );
			$single_form_field  = $form_field_data[ $form_data_index ];
			$general_setting    = isset( $single_form_field->general_setting ) ? $single_form_field->general_setting : new stdClass();
			$single_field_key   = $single_form_field->field_key;
			$single_field_label = isset( $general_setting->label ) ? ur_string_translation( $form_id, 'user_registration_single_field_label', $general_setting->label ) : '';
			$single_field_value = isset( $data->value ) ? $data->value : '';
			$data->extra_params = array(
				'field_key' => $single_field_key,
				'label'     => $single_field_label,
			);

			/**
			 * Validate form fields according to the validations set in $validations array.
			 *
			 * @see this->get_field_validations()
			 */

			$validations = $form_validator->get_field_validations( $single_field_key );

			if ( $form_validator->is_field_required( $single_form_field, $form_data ) ) {
				array_unshift( $validations, 'required' );
			}

			if ( ! empty( $validations ) ) {
				if ( in_array( 'required', $validations, true ) || ! empty( $single_field_value ) ) {
					foreach ( $validations as $validation ) {
						$result = UR_Form_Validation::$validation( $single_field_value );

						if ( is_wp_error( $result ) ) {
							$response_array = $form_validator->add_error( $result, $single_field_label, $response_array );
							break;
						}
					}
				}
			}

			/**
			 * Hook to update form field data.
			 */
			$field_hook_name = 'user_registration_form_field_' . $single_form_field->field_key . '_params';
			/**
			 * Filter the single field params.
			 *
			 * The dynamic portion of the hook name, $field_hook_name.
			 *
			 * @param array $data The form data.
			 * @param array $single_form_field The single form field.
			 */
			$data                                 = apply_filters( $field_hook_name, $data, $single_form_field );
			$valid_form_data[ $data->field_name ] = UR_Form_Validation::get_sanitize_value( $data );

			/**
			 * Hook to custom validate form field.
			 */
			$hook        = "user_registration_validate_{$single_form_field->field_key}";
			$filter_hook = $hook . '_message';

			if ( isset( $data->field_type ) && 'email' === $data->field_type ) {
				/**
				 * Action validate email whitelist.
				 *
				 * @param array $data ->value The data value.
				 * @param string $filter_hook The dynamic Filter hook.
				 * @param array $single_form_field The single form field.
				 * @param int $form_id The form ID.
				 */
				do_action( 'user_registration_validate_email_whitelist', $data->value, $filter_hook, $single_form_field, $form_id );
			}

			if ( 'honeypot' === $single_form_field->field_key ) {
				/**
				 * Action validate honeypot container.
				 *
				 * @param array $data The data.
				 * @param string $filter_hook The dynamic Filter hook.
				 * @param int $form_id The form ID.
				 * @param array $form_data The form data.
				 */
				do_action( 'user_registration_validate_honeypot_container', $data, $filter_hook, $form_id, $form_data );
			}

			/**
			 * Slot booking backend validation.
			 *
			 * @since 4.1.0
			 */
			if ( 'date' === $single_form_field->field_key || 'timepicker' === $single_form_field->field_key ) {
				/**
				 * Action validate slot booking.
				 *
				 * @param array $form_data The form data.
				 * @param string $filter_hook The dynamic Filter hook.
				 * @param array $single_form_field The form field.
				 * @param int $form_id The form ID.
				 */
				do_action( 'user_registration_validate_slot_booking', $form_data, $filter_hook, $single_form_field, $form_id );
			}

			if (
				isset( $single_form_field->advance_setting->enable_conditional_logic ) && ur_string_to_bool( $single_form_field->advance_setting->enable_conditional_logic )
			) {
				$single_form_field->advance_setting->enable_conditional_logic = ur_string_to_bool( $single_form_field->advance_setting->enable_conditional_logic );
			}
			/**
			 * Action validate single field.
			 *
			 * The dynamic portion of the hook name, $hook.
			 *
			 * @param array $single_form_field The form field.
			 * @param array $data The form data.
			 * @param string $filter_hook The dynamic filter hook.
			 * @param int $form_id The form ID.
			 */
			do_action( $hook, $single_form_field, $data, $filter_hook, $form_id );

			/**
			 * Filter the validate message.
			 *
			 * The dynamic portion of the hook name, $filter_hook.
			 * Default value is blank string.
			 */
			$response = apply_filters( $filter_hook, '' );

			if ( ! empty( $response ) ) {
				array_push( $response_array, $response );
			}
			remove_all_filters( $filter_hook );
		}

		return array( $response_array, $valid_form_data );
	}
}

if ( ! function_exists( 'user_registration_validate_edit_profile_form_field_data' ) ) {

	/**
	 * Function to validate edit profile individual form field data.
	 *
	 * @param object $data Form field data submitted by the user.
	 * @param array  $form_data Form Data.
	 * @param int    $form_id Form id.
	 * @param array  $form_field_data Form Field Data..
	 * @param array  $form_fields Form Fields.
	 * @param int    $user_id User ID.
	 */
	function user_registration_validate_edit_profile_form_field_data( $data, $form_data, $form_id, $form_field_data, $form_fields, $user_id ) {
		$form_validator   = new UR_Form_Validation();
		$skippable_fields = $form_validator->get_update_profile_validation_skippable_fields( $form_field_data );
		$form_key_list    = wp_list_pluck( wp_list_pluck( $form_field_data, 'general_setting' ), 'field_name' );

		$single_field_name = strpos( $data->field_name, 'user_registration_' ) !== - 1 ? trim( str_replace( 'user_registration_', '', $data->field_name ) ) : $data->field_name;

		if ( ! in_array( $single_field_name, $skippable_fields, true ) && in_array( $single_field_name, $form_key_list, true ) ) {
			$form_data_index   = array_search( $single_field_name, $form_key_list, true );
			$single_form_field = $form_field_data[ $form_data_index ];

			$general_setting    = isset( $single_form_field->general_setting ) ? $single_form_field->general_setting : new stdClass();
			$single_field_key   = $single_form_field->field_key;
			$single_field_label = isset( $general_setting->label ) ? $general_setting->label : '';
			$single_field_value = isset( $data->value ) ? $data->value : '';
			$data->extra_params = array(
				'field_key' => $single_field_key,
				'label'     => $single_field_label,
			);

			/**
			 * Validate form field according to the validations set in $validations array.
			 *
			 * @see form_validator->get_field_validations()
			 */
			$validations = $form_validator->get_field_validations( $single_field_key );

			$required = isset( $single_form_field->general_setting->required ) ? $single_form_field->general_setting->required : false;

			$urcl_hide_fields = isset( $_POST['urcl_hide_fields'] ) ? (array) json_decode( stripslashes( $_POST['urcl_hide_fields'] ), true ) : array(); //phpcs:ignore;

			if ( ! in_array( $single_field_name, $urcl_hide_fields, true ) && ur_string_to_bool( $required ) ) {
				array_unshift( $validations, 'required' );
			}

			if ( ! empty( $validations ) ) {

				if ( in_array( 'required', $validations, true ) || ! empty( $single_field_value ) ) {
					foreach ( $validations as $validation ) {
						$result = UR_Form_Validation::$validation( $single_field_value );

						if ( is_wp_error( $result ) ) {
							$error_code = $result->get_error_code();
							$message    = $form_validator->get_error_message( $error_code, $single_field_label );
							ur_add_notice( $message, 'error' );
							break;
						}
					}
				}
			}

			/**
			 * Filter to allow modification of value.
			 *
			 * The dynamic portion of the hook name, $single_field_name.
			 *
			 * @param array $single_field_value The single field value.
			 */
			$single_field_value = apply_filters( 'user_registration_process_myaccount_field_' . $single_field_name, wp_unslash( $single_field_value ) );

			if ( isset( $data->field_type ) && 'email' === $data->field_type ) {
				/**
				 * Action validate email whitelist.
				 *
				 * @param array $single_field_value The data value.
				 * @param array $single_form_field The single form field.
				 * @param int $form_id The form ID.
				 */
				do_action( 'user_registration_validate_email_whitelist', sanitize_text_field( $single_field_value ), '', $single_form_field, $form_id );
			}

			/**
			 * Slot booking backend validation.
			 *
			 * @since 4.1.0
			 */
			if ( 'date' === $single_form_field->field_key || 'timepicker' === $single_form_field->field_key ) {
				/**
				 * Action validate slot booking.
				 *
				 * @param array $form_data The form data.
				 * @param array $single_form_field The field setting.
				 * @param int $form_id The form ID.
				 */
				do_action( 'user_registration_validate_slot_booking', $form_data, '', $single_form_field, $form_id );
			}

			if ( 'user_email' === $single_form_field->field_key ) {
				// Do not allow admin to update others email, case may change in future
				// if ( ! email_exists( sanitize_text_field( wp_unslash( $single_field_value ) ) ) && $user_id !== get_current_user_id() ) {
				// ur_add_notice( esc_html__( 'Email field is not editable.', 'user-registration' ), 'error' );
				// }
				// Check if email already exists before updating user details.
				if ( email_exists( sanitize_text_field( wp_unslash( $single_field_value ) ) ) && email_exists( sanitize_text_field( wp_unslash( $single_field_value ) ) ) !== $user_id ) {
					ur_add_notice( esc_html__( 'Email already exists', 'user-registration' ), 'error' );
				}
			}

			$form_validator->run_field_validations_on_profile_update( $single_field_key, $single_form_field, $data, $form_id );

			/** Action to add extra validation to edit profile fields.
			 *
			 * The dynamic portion of the hook name, $single_field_key.
			 *
			 * @param array $single_field_value The single field value.
			 * @param array $single_form_field The form field.
			 */
			do_action( 'user_registration_validate_field_' . $single_field_key, wp_unslash( $single_field_value ), $single_form_field ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
	}
}

if ( ! function_exists( 'user_registration_edit_profile_row_template' ) ) {

	/**
	 * Generate edit profile individual row template
	 *
	 * @param array  $data Form row data.
	 * @param array  $profile User profile data.
	 * @param string $current_row Current row id.
	 * @param string $row_count Current row count.
	 */
	function user_registration_edit_profile_row_template( $data, $profile, $current_row = '', $row_count = '' ) {

		$user_id = ! empty( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : get_current_user_id(); // phpcs:ignore
		$form_id = ur_get_form_id_by_userid( $user_id );
		$width   = floor( 100 / count( $data ) ) - count( $data );
		$is_edit = isset( $_REQUEST['action'] ) && ( $_REQUEST['action'] === 'edit' || $_REQUEST['action'] === 'view' ) && $user_id !== get_current_user_id(); // phpcs:ignore

		foreach ( $data as $grid_key => $grid_data ) {
			$found_field = false;

			foreach ( $grid_data as $grid_data_key => $single_item ) {
				if ( ! isset( $single_item->general_setting->field_name ) ) {
					continue;
				}

				$key = 'user_registration_' . $single_item->general_setting->field_name;
				if ( isset( $single_item->field_key ) ) {
					$found_field = isset( $profile[ $key ] );
				}
				if ( $found_field ) {
					break;
				}
			}

			if ( $found_field ) {
				?>
				<div class="ur-form-grid ur-grid-<?php echo esc_attr( ( $grid_key + 1 ) ); ?>" style="width:<?php echo esc_attr( $width ); ?>%;">
				<?php
			}

			foreach ( $grid_data as $grid_data_key => $single_item ) {

				if ( ! isset( $single_item->general_setting->field_name ) ) {
					continue;
				}

				$key = 'user_registration_' . $single_item->general_setting->field_name;

				if ( $found_field ) {
					$form_id = ur_get_form_id_by_userid( $user_id );
					$field   = isset( $profile[ $key ] ) ? $profile[ $key ] : array();

					$field['input_class']       = array( 'ur-edit-profile-field ' );
					$advance_data               = array(
						'general_setting' => (object) $single_item->general_setting,
						'advance_setting' => (object) $single_item->advance_setting,
					);
					$field['custom_attributes'] = isset( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] ) ? $field['custom_attributes'] : array();
					$field_id                   = $single_item->general_setting->field_name;
					$cl_props                   = null;

					// If the conditional logic addon is installed.
					if ( class_exists( 'UserRegistrationConditionalLogic' ) ) {
						// Migrate the conditional logic to logic_map schema.
						$single_item = class_exists( 'URCL_Field_Settings' ) ? URCL_Field_Settings::migrate_to_logic_map_schema( $single_item ) : $single_item;

						$cl_enabled = isset( $single_item->advance_setting->enable_conditional_logic ) && ur_string_to_bool( $single_item->advance_setting->enable_conditional_logic );
						$cl_props   = sprintf( 'data-conditional-logic-enabled="%s"', esc_attr( $cl_enabled ) );

						if ( $cl_enabled && isset( $single_item->advance_setting->cl_map ) ) {
							$cl_map   = esc_attr( $single_item->advance_setting->cl_map );
							$cl_props = sprintf( 'data-conditional-logic-enabled="%s" data-conditional-logic-map="%s"', esc_attr( $cl_enabled ), esc_attr( $cl_map ) );
						}
					}

					if ( 'profile_picture' === $single_item->field_key ) {
						continue;
					}

					// unset invite code.
					if ( 'invite_code' === $single_item->field_key ) {
						continue;
					}
					// unset learndash code.
					if ( 'learndash_course' === $single_item->field_key ) {
						continue;
					}

					if ( 'country' === $single_item->field_key && isset( $single_item->advance_setting->enable_state ) ) {
						$field['enable_state'] = ur_string_to_bool( $single_item->advance_setting->enable_state );
					}

					// Unset multiple choice and single item.
					if ( 'subscription_plan' === $single_item->field_key || 'multiple_choice' === $single_item->field_key || 'single_item' === $single_item->field_key || 'captcha' === $single_item->field_key || 'stripe_gateway' === $single_item->field_key ) {
						continue;
					}

					?>
					<div
						class="ur-field-item field-<?php echo esc_attr( $single_item->field_key ); ?> <?php echo esc_attr( ! empty( $single_item->advance_setting->custom_class ) ? $single_item->advance_setting->custom_class : '' ); ?>" <?php echo $cl_props; //PHPCS:ignore?>
						data-field-id="<?php echo esc_attr( $field_id ); ?>"
						data-ref-id="<?php echo esc_attr( $key ); ?>">
						<?php
						$readonly_fields = ur_readonly_profile_details_fields();
						if ( $is_edit ) {
							unset( $readonly_fields['user_pass'] );
						}

						if ( isset( $field['field_key'] ) && array_key_exists( $field['field_key'], $readonly_fields ) ) {
							$field['custom_attributes']['readonly'] = 'readonly';
							if ( isset( $readonly_fields[ $field['field_key'] ] ['value'] ) ) {
								$field['value'] = $readonly_fields[ $field['field_key'] ] ['value'];
							}
							if ( isset( $readonly_fields[ $field['field_key'] ] ['message'] ) ) {
								$field['custom_attributes']['title'] = $readonly_fields[ $field['field_key'] ] ['message'];
								$field['input_class'][]              = 'user-registration-help-tip';
							}
						}

						if ( 'number' === $single_item->field_key ) {
							$field['min']  = isset( $advance_data['advance_setting']->min ) ? $advance_data['advance_setting']->min : '';
							$field['max']  = isset( $advance_data['advance_setting']->max ) ? $advance_data['advance_setting']->max : '';
							$field['step'] = isset( $advance_data['advance_setting']->step ) ? $advance_data['advance_setting']->step : '';
						}
						$length_validation_fields = array(
							'text',
							'textarea',
							'display_name',
							'first_name',
							'last_name',
							'description',
							'nickname',
						);
						if ( in_array( $single_item->field_key, $length_validation_fields, true ) ) {
							if ( isset( $advance_data['advance_setting']->limit_length ) && $advance_data['advance_setting']->limit_length ) {
								if ( isset( $advance_data['advance_setting']->limit_length_limit_count ) && isset( $advance_data['advance_setting']->limit_length_limit_mode ) ) {
									if ( 'characters' === $advance_data['advance_setting']->limit_length_limit_mode ) {
										$field['max-characters'] = $advance_data['advance_setting']->limit_length_limit_count;
									} elseif ( 'words' === $advance_data['advance_setting']->limit_length_limit_mode ) {
										$field['max-words'] = $advance_data['advance_setting']->limit_length_limit_count;
									}
								}
							}

							if ( isset( $advance_data['advance_setting']->minimum_length ) && $advance_data['advance_setting']->minimum_length ) {
								if ( isset( $advance_data['advance_setting']->minimum_length_limit_count ) && isset( $advance_data['advance_setting']->minimum_length_limit_mode ) ) {
									if ( 'characters' === $advance_data['advance_setting']->minimum_length_limit_mode ) {
										$field['min-characters'] = $advance_data['advance_setting']->minimum_length_limit_count;
									} elseif ( 'words' === $advance_data['advance_setting']->minimum_length_limit_mode ) {
										$field['min-words'] = $advance_data['advance_setting']->minimum_length_limit_count;
									}
								}
							}
						}

						if ( 'range' === $single_item->field_key ) {
							$field['range_min']             = ( isset( $advance_data['advance_setting']->range_min ) && '' !== $advance_data['advance_setting']->range_min ) ? $advance_data['advance_setting']->range_min : '0';
							$field['range_max']             = ( isset( $advance_data['advance_setting']->range_max ) && '' !== $advance_data['advance_setting']->range_max ) ? $advance_data['advance_setting']->range_max : '10';
							$field['range_step']            = isset( $advance_data['advance_setting']->range_step ) ? $advance_data['advance_setting']->range_step : '1';
							$field['enable_payment_slider'] = isset( $advance_data['advance_setting']->enable_payment_slider ) ? $advance_data['advance_setting']->enable_payment_slider : 'false';

							if ( ur_string_to_bool( $advance_data['advance_setting']->enable_prefix_postfix ) ) {
								if ( ur_string_to_bool( $advance_data['advance_setting']->enable_text_prefix_postfix ) ) {
									$field['range_prefix']  = isset( $advance_data['advance_setting']->range_prefix ) ? $advance_data['advance_setting']->range_prefix : '';
									$field['range_postfix'] = isset( $advance_data['advance_setting']->range_postfix ) ? $advance_data['advance_setting']->range_postfix : '';
								} else {
									$field['range_prefix']  = $field['range_min'];
									$field['range_postfix'] = $field['range_max'];
								}
							}

							// to hide the range as payment slider in edit profile.
							if ( ur_string_to_bool( $field['enable_payment_slider'] ) ) {
								continue;
							}
						}

						if ( 'phone' === $single_item->field_key ) {
							$field['phone_format'] = $single_item->general_setting->phone_format;
							if ( 'smart' === $field['phone_format'] ) {
								unset( $field['input_mask'] );
							}
						}

						if ( 'password' === $single_item->field_key ) {
							$field['size'] = $advance_data['advance_setting']->size;
						}

						if ( isset( $single_item->general_setting->hide_label ) ) {
							if ( ur_string_to_bool( $single_item->general_setting->hide_label ) ) {
								unset( $field['label'] );
							}
						}

						if ( 'select' === $single_item->field_key ) {
							$option_data         = isset( $advance_data['advance_setting']->options ) ? explode( ',', $advance_data['advance_setting']->options ) : array();
							$option_advance_data = isset( $advance_data['general_setting']->options ) ? $advance_data['general_setting']->options : $option_data;
							$options             = array();

							if ( is_array( $option_advance_data ) ) {
								foreach ( $option_advance_data as $index_data => $option ) {
									$options[ $option ] = ur_string_translation( $form_id, 'user_registration_' . $advance_data['general_setting']->field_name . '_option_' . ( ++$index_data ), $option );
								}
								$field['options'] = $options;
							}

							$field['placeholder'] = $single_item->general_setting->placeholder;

						}

						if ( 'radio' === $single_item->field_key ) {
							if ( isset( $advance_data['general_setting']->image_choice ) && ur_string_to_bool( $advance_data['general_setting']->image_choice ) ) {
								$option_advance_data = isset( $advance_data['general_setting']->image_options ) ? $advance_data['general_setting']->image_options : array();
								$options             = array();
								if ( is_array( $option_advance_data ) ) {
									foreach ( $option_advance_data as $index_data => $option ) {
										$options[ $option->label ] = array(
											'label' => ur_string_translation( $form_id, 'user_registration_' . $advance_data['general_setting']->field_name . '_option_' . ( ++$index_data ), $option->label ),
											'image' => $option->image,
										);
									}
									$field['image_options'] = $options;
								}
							} else {
								$option_advance_data = isset( $advance_data['general_setting']->options ) ? $advance_data['general_setting']->options : array();
								$options             = array();

								if ( is_array( $option_advance_data ) ) {
									foreach ( $option_advance_data as $index_data => $option ) {
										$options[ $option ] = ur_string_translation( $form_id, 'user_registration_' . $advance_data['general_setting']->field_name . '_option_' . ( ++$index_data ), $option );
									}
									$field['options'] = $options;
								}
							}
						}

						if ( 'file' === $single_item->field_key ) {
							if ( isset( $single_item->general_setting->max_files ) ) {
								$field['max_files'] = $single_item->general_setting->max_files;
							} else {
								$field['max_files'] = 1;
							}

							if ( isset( $advance_data['advance_setting']->max_upload_size ) ) {
								$field['max_upload_size'] = $advance_data['advance_setting']->max_upload_size;
							}

							if ( isset( $advance_data['advance_setting']->valid_file_type ) ) {
								$field['valid_file_type'] = $advance_data['advance_setting']->valid_file_type;
							}

							// Remove files attachment id from user meta if file is deleted by admin.
							if ( isset( $field['value'] ) && '' !== $field['value'] ) {
								$attachment_ids = is_array( $field['value'] ) ? $field['value'] : explode( ',', $field['value'] );

								foreach ( $attachment_ids as $attachment_key => $attachment_id ) {
									$attachment_url = get_attached_file( $attachment_id );

									// Check to see if file actually exists or not.
									if ( '' !== $attachment_url && file_exists( $attachment_url ) ) {
										continue;
									}
									unset( $attachment_ids[ $attachment_key ] );
								}

								$field['value'] = ! empty( $attachment_ids ) ? implode( ',', $attachment_ids ) : '';

								$user_id = get_current_user_id();

								if ( current_user_can( 'edit_user', $user_id ) ) {
									update_user_meta( $user_id, 'user_registration_' . $single_item->general_setting->field_name, $field['value'] );
								}
							}
						}

						if ( isset( $advance_data['general_setting']->required ) ) {
							if ( in_array( $single_item->field_key, ur_get_required_fields() )
								|| ur_string_to_bool( $advance_data['general_setting']->required ) ) {
								$field['required']                      = true;
								$field['custom_attributes']['required'] = 'required';
							}
						}

						// Add choice_limit setting valur in order to limit choice fields.
						if ( 'checkbox' === $single_item->field_key || 'multi_select2' === $single_item->field_key ) {
							if ( isset( $advance_data['general_setting']->image_choice ) && ur_string_to_bool( $advance_data['general_setting']->image_choice ) ) {
								$option_data = isset( $advance_data['general_setting']->image_options ) ? $advance_data['general_setting']->image_options : array();
								$options     = array();

								if ( is_array( $option_data ) ) {
									foreach ( $option_data as $index_data => $option ) {
										$options[ $option->label ] = array(
											'label' => ur_string_translation( $form_id, 'user_registration_' . $advance_data['general_setting']->field_name . '_option_' . ( ++$index_data ), $option->label ),
											'image' => $option->image,
										);
									}
									$field['image_options'] = $options;
								}
							} else {
								$option_data = isset( $advance_data['general_setting']->options ) ? $advance_data['general_setting']->options : array();
								$options     = array();

								if ( is_array( $option_data ) ) {
									foreach ( $option_data as $index_data => $option ) {
										$options[ $option ] = ur_string_translation( $form_id, 'user_registration_' . $advance_data['general_setting']->field_name . '_option_' . ( ++$index_data ), $option );
									}

									$field['options'] = $options;
								}
							}

							if ( isset( $advance_data['advance_setting']->choice_limit ) ) {
								$field['choice_limit'] = $advance_data['advance_setting']->choice_limit;
							}
							if ( isset( $advance_data['advance_setting']->select_all ) ) {
								$field['select_all'] = ur_string_to_bool( $advance_data['advance_setting']->select_all );
							}
						}

						if ( 'timepicker' === $single_item->field_key ) {
							$field['current_time']  = isset( $advance_data['advance_setting']->current_time ) ? $advance_data['advance_setting']->current_time : '';
							$field['time_interval'] = isset( $advance_data['advance_setting']->time_interval ) ? $advance_data['advance_setting']->time_interval : '';
							$field['time_format']   = isset( $advance_data['advance_setting']->time_format ) ? $advance_data['advance_setting']->time_format : '';
							$field['time_range']    = isset( $advance_data['advance_setting']->time_range ) ? $advance_data['advance_setting']->time_range : '';
							$field['time_min']      = ( isset( $advance_data['advance_setting']->time_min ) && '' !== $advance_data['advance_setting']->time_min ) ? $advance_data['advance_setting']->time_min : '';
							$field['time_max']      = ( isset( $advance_data['advance_setting']->time_max ) && '' !== $advance_data['advance_setting']->time_max ) ? $advance_data['advance_setting']->time_max : '';
							$timemin                = isset( $field['time_min'] ) ? strtolower( substr( $field['time_min'], - 2 ) ) : '';
							$timemax                = isset( $field['time_max'] ) ? strtolower( substr( $field['time_max'], - 2 ) ) : '';
							$minampm                = intval( $field['time_min'] ) <= 12 ? 'AM' : 'PM';
							$maxampm                = intval( $field['time_max'] ) <= 12 ? 'AM' : 'PM';
							// For slot booking.
							$field['enable_time_slot_booking'] = isset( $advance_data['advance_setting']->enable_time_slot_booking ) ? $advance_data['advance_setting']->enable_time_slot_booking : '';
							$field['target_date_field']        = isset( $advance_data['advance_setting']->target_date_field ) ? $advance_data['advance_setting']->target_date_field : '';
							// Handles the time format.
							if ( 'am' === $timemin || 'pm' === $timemin ) {
								$field['time_min'] = $field['time_min'];
							} else {
								$field['time_min'] = $field['time_min'] . '' . $minampm;
							}

							if ( 'am' === $timemax || 'pm' === $timemax ) {
								$field['time_max'] = $field['time_max'];
							} else {
								$field['time_max'] = $field['time_max'] . '' . $maxampm;
							}
						}

						if ( 'date' === $single_item->field_key ) {
							// For slot booking.
							$field['enable_date_slot_booking'] = isset( $advance_data['advance_setting']->enable_date_slot_booking ) ? $advance_data['advance_setting']->enable_date_slot_booking : false;
						}
						$field['form_id'] = $form_id;
						$filter_data      = array(
							'form_data' => $field,
							'data'      => $advance_data,
						);

						$field_key       = isset( $field['field_key'] ) ? $field['field_key'] : '';
						$form_data_array = apply_filters( 'user_registration_' . $field_key . '_frontend_form_data', $filter_data, true );
						$field           = isset( $form_data_array['form_data'] ) ? $form_data_array['form_data'] : $field;
						$value           = ! empty( $_POST[ $key ] ) ? ur_clean( wp_unslash( $_POST[ $key ] ) ) : ( isset( $field['value'] ) ? $field['value'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

						if ( isset( $field['field_key'] ) ) {
							$row_count_to_send = '' === $row_count ? $current_row : $row_count;
							$field             = user_registration_form_field( $key, $field, $value, $row_count_to_send, $is_edit );
						}
						?>
					</div>
				<?php } ?>
			<?php } ?>

			<?php if ( $found_field ) { ?>
				</div>
				<?php
			}
		}
		if ( ! $is_edit ) {
			echo apply_filters( 'user_registration_frontend_form_row_end', '', $form_id, $current_row ); // phpcs:ignore
		}
	}
}

if ( ! file_exists( 'user_registration_sanitize_profile_update' ) ) {

	/**
	 * Sanitize the data submitted by user.
	 *
	 * @param array  $submitted_data Submitted data.
	 * @param string $field_type Field Type.
	 * @param string $key Field Key.
	 */
	function user_registration_sanitize_profile_update( $submitted_data, $field_type, $key ) {

		$value = '';

		switch ( $field_type ) {
			case 'checkbox':
				if ( isset( $submitted_data[ $key ] ) && is_array( $submitted_data[ $key ] ) ) { // phpcs:ignore
					$value = wp_unslash( $submitted_data[ $key ] ); // phpcs:ignore
				} else {
					$value = (int) isset( $submitted_data[ $key ] ); // phpcs:ignore
				}
				break;

			case 'wysiwyg':
				if ( isset( $submitted_data[ $key ] ) ) { // phpcs:ignore
					$value = sanitize_text_field( htmlentities( wp_unslash( $submitted_data[ $key ] ) ) ); // phpcs:ignore
				} else {
					$value = '';
				}
				break;

			case 'email':
				if ( isset( $submitted_data[ $key ] ) ) { // phpcs:ignore
					$value = sanitize_email( wp_unslash( $submitted_data[ $key ] ) ); // phpcs:ignore
				} else {
					$user_id   = get_current_user_id();
					$user_data = get_userdata( $user_id );
					$value     = $user_data->data->user_email;
				}
				break;
			case 'profile_picture':
				if ( isset( $submitted_data['profile_pic_url'] ) ) { // phpcs:ignore
					$value = sanitize_text_field( wp_unslash( $submitted_data['profile_pic_url'] ) ); // phpcs:ignore
				} else {
					$value = '';
				}
				break;
			case 'country':
				$country_data = array();
				if ( isset( $submitted_data[ $key ] ) ) { // phpcs:ignore
					$country_data['country'] = sanitize_text_field( wp_unslash( $submitted_data[ $key ] ) ); // phpcs:ignore
				}
				if ( isset( $submitted_data[ $key . '_state' ] ) ) { // phpcs:ignore
					$country_data['state'] = sanitize_text_field( wp_unslash( $submitted_data[ $key . '_state' ] ) ); // phpcs:ignore
				}
				$value = json_encode( $country_data );
				break;
			default:
				$value = isset( $submitted_data[ $key ] ) ? $submitted_data[ $key ] : ''; // phpcs:ignore
				break;
		}

		return $value;
	}
}

if ( ! function_exists( 'ur_get_coupon_details' ) ) {
	/**
	 * This function will send email verification email to the user.
	 *
	 * @param string $coupon Coupon Code.
	 *
	 * @since 3.1.5
	 */
	function ur_get_coupon_details( $coupon ) {

		$posts = new WP_Query(
			array(
				'post_type'  => 'ur_coupons',
				'meta_key'   => 'ur_coupon_code',
				'meta_query' => array(
					array(
						'key'     => 'ur_coupon_code',
						'value'   => $coupon,
						'compare' => '=',
					),
				),
			)
		);

		if ( $posts->post_count > 0 ) {
			$posts_meta                 = get_post_meta( $posts->post->ID, 'ur_coupon_meta', true );
			$coupon_data                = json_decode( $posts_meta, true );
			$coupon_data['coupon_id']   = $posts->post->ID;
			$coupon_data['coupon_name'] = $posts->post->post_title;

			return $coupon_data;
		}

		return $posts->posts;
	}
}

if ( ! function_exists( 'ur_get_registration_field_value_by_field_name' ) ) {

	/**
	 * Get Field value by field name while registration.
	 *
	 * @param string $field_name Field Name.
	 *
	 * @since 0
	 */
	function ur_get_registration_field_value_by_field_name( $field_name ) {
		$field_value = '';

		if ( isset( $_POST['form_data'] ) ) { // phpcs:ignore
			$form_data = json_decode( wp_unslash( $_POST['form_data'] ) ); // phpcs:ignore
		}
		if ( gettype( $form_data ) != 'array' && gettype( $form_data ) != 'object' ) {
			$form_data = array();
		}
		foreach ( $form_data as $index => $single_data ) {

			if ( $field_name == $single_data->field_name ) {
				$field_value = $single_data->value;
			}
		}

		return $field_value;
	}
}


if ( ! function_exists( 'ur_get_translated_string' ) ) {
	/**
	 * Function to get translated string using WPML
	 *
	 * @param string $domain Domain.
	 * @param string $string String Value.
	 * @param string $language_code Language Code.
	 * @param string $field_key Field Key.
	 * @param string $form_id Form ID.
	 *
	 * @since 4.2.1
	 */
	function ur_get_translated_string( $domain, $string, $language_code, $field_key, $form_id = 0 ) {
		if ( function_exists( 'icl_translate' ) ) {
			$language_code     = is_array( $language_code ) ? $language_code[0] : $language_code;
			$translated_string = apply_filters( 'wpml_translate_single_string', $string, $domain, $field_key, $language_code );

			if ( false === $translated_string || $translated_string === $language_code ) {
				return $string;
			} else {
				return $translated_string;
			}
		} else {
			return $string;
		}
	}
}

add_action( 'init', 'ur_check_is_disabled' );
if ( ! function_exists( 'ur_check_is_disabled' ) ) {

	/**
	 * Check if user is disabled.
	 */
	function ur_check_is_disabled() {
		$is_auto_enable = get_user_meta( get_current_user_id(), 'ur_auto_enable_time', true );
		if ( $is_auto_enable ) {
			$current_time = current_time( 'timestamp' );
			if ( $current_time >= $is_auto_enable ) {
				delete_user_meta( get_current_user_id(), 'ur_auto_enable_time' );
				delete_user_meta( get_current_user_id(), 'ur_disable_users' );
			}
		}
		$is_disabled = get_user_meta( get_current_user_id(), 'ur_disable_users', true );
		if ( $is_disabled ) {
			wp_logout();
		}
	}
}

add_action( 'init', 'ur_check_is_denied' );

if ( ! function_exists( 'ur_check_is_denied' ) ) {
	/**
	 * Check if user is denied.
	 */
	function ur_check_is_denied() {
		$is_denied = get_user_meta( get_current_user_id(), 'ur_user_status', true );
		if ( '-1' === $is_denied ) {
			wp_logout();
		}
	}
}

add_action( 'init', 'ur_check_is_inactive' );

if ( ! function_exists( 'ur_check_is_inactive' ) ) {
	/**
	 * Check if user is denied.
	 */
	function ur_check_is_inactive() {
		if ( ! ur_check_module_activation( 'membership' ) ||
			current_user_can( 'manage_options' ) ||
			// phpcs:ignore
			( ! empty( $_POST['action'] ) && in_array(
				$_POST['action'], // phpcs:ignore
				array(
					'user_registration_membership_confirm_payment',
					'user_registration_membership_create_stripe_subscription',
				)
			) )
		) {
			return;
		}
		$members_repository = new \WPEverest\URMembership\Admin\Repositories\MembersRepository();

		$user_memberships = $members_repository->get_member_membership_by_id( get_current_user_id() );

		if ( empty( $user_memberships ) ) {
			return;
		}

		$active_memberships = array_filter(
			array_map(
				function ( $user_memberships ) {
					// 'pending' no longer forces a logout (UR-4633) — only 'inactive' does.
					if ( ! empty( $user_memberships['status'] ) && ! in_array( $user_memberships['status'], array( 'inactive' ), true ) ) {
						return $user_memberships['post_id'];
					}
				},
				$user_memberships
			)
		);

		if ( count( $active_memberships ) < 1 ) {
			wp_logout();
		}
	}
}

add_action( 'init', 'ur_backwards_compatibility_urm_enable_no_conflict' );

if ( ! function_exists( 'ur_backwards_compatibility_urm_enable_no_conflict' ) ) {
	/**
	 * Backwards compatibility: Set urm_enable_no_conflict option if not set or set to false.
	 */
	function ur_backwards_compatibility_urm_enable_no_conflict() {
		$migration_completed = get_option( 'urm_enable_no_conflict_migration_completed', false );

		if ( $migration_completed ) {
			return;
		}

		$option_value = get_option( 'urm_enable_no_conflict', null );
		if ( null === $option_value ) {
			update_option( 'urm_enable_no_conflict', false );
		}

		update_option( 'urm_enable_no_conflict_migration_completed', true );
	}
}

if ( ! function_exists( 'ur_check_is_auto_enable_user' ) ) {

	/**
	 * Check if user is auto enabled.
	 *
	 * @param int $user_id User ID.
	 */
	function ur_check_is_auto_enable_user( $user_id = 0 ) {
		if ( 0 === $user_id || '' === $user_id ) {
			return;
		}

		$is_auto_enable = get_user_meta( $user_id, 'ur_auto_enable_time', true );
		if ( ! $is_auto_enable || '' === $is_auto_enable ) {
			return;
		}
		if ( strtotime( $is_auto_enable ) < strtotime( gmdate( 'Y-m-d H:i:s' ) ) ) {
			delete_user_meta( $user_id, 'ur_auto_enable_time' );
			delete_user_meta( $user_id, 'ur_disable_users' );

			return;
		}
	}
}

// Hook the redirection to admin_init.
add_action(
	'admin_init',
	'ur_redirect_to_addons_page'
);

if ( ! function_exists( 'ur_redirect_to_addons_page' ) ) {
	/**
	 * Redirect to addons page.
	 */
	function ur_redirect_to_addons_page() {
		if ( isset( $_GET['page'] ) && 'user-registration-addons' === $_GET['page'] ) { // phpcs:ignore
			wp_safe_redirect( esc_url_raw( admin_url( 'admin.php?page=user-registration-dashboard#features' ) ) );
			exit;
		}
	}
}

if ( ! function_exists( 'ur_check_akismet_installation' ) ) {

	/**
	 * Check the configuration status of Akismet.
	 *
	 * @return string The status message indicating whether Akismet is installed, activated, or configured.
	 */
	function ur_check_akismet_installation() {
		$warning_color = '#ffcc00';

		if ( ! file_exists( WP_PLUGIN_DIR . '/akismet/akismet.php' ) ) {
			return sprintf(
				'<div class="ur-form-settings-warning"><span style="color: %s;">%s</span>%s %s %s %s</div>',
				$warning_color,
				esc_html__( 'Warning:-', 'user-registration' ),
				esc_html__( ' This feature is inactive because', 'user-registration' ),
				'<a href="' . esc_url_raw( 'https://wordpress.org/plugins/akismet/' ) . '" rel="noreferrer noopener" target="_blank">' . esc_html__( 'Akismet', 'user-registration' ) . '</a>',
				esc_html__( 'plugin has not been installed. For more', 'user-registration' ),
				'<a href="https://docs.wpuserregistration.com/docs/individual-form-settings/#10-toc-title" rel="noreferrer noopener" target="_blank">' . esc_html__( 'information.', 'user-registration' ) . '</a>'
			);
		} elseif ( ! is_plugin_active( 'akismet/akismet.php' ) ) {
			return sprintf(
				'<div class="ur-form-settings-warning"><span style="color: %s;">%s</span>%s %s %s %s</div>',
				$warning_color,
				esc_html__( 'Warning:- ', 'user-registration' ),
				esc_html__( 'This feature is inactive because', 'user-registration' ),
				'<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '" rel="noreferrer noopener" target="_blank">' . esc_html__( 'Akismet', 'user-registration' ) . '</a>',
				esc_html__( 'plugin is not activated. For more', 'user-registration' ),
				'<a href="https://docs.wpuserregistration.com/docs/individual-form-settings/#10-toc-title" rel="noreferrer noopener" target="_blank">' . esc_html__( 'information.', 'user-registration' ) . '</a>'
			);
		} elseif ( ! ur_is_akismet_configured() ) {
			return sprintf(
				'<div class="ur-form-settings-warning"><span style="color: %s;">%s</span>%s %s %s %s</div>',
				$warning_color,
				esc_html__( 'Warning:-', 'user-registration' ),
				esc_html__( 'This feature is inactive because', 'user-registration' ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=akismet-key-config' ) ) . '" rel="noreferrer noopener" target="_blank">' . esc_html__( 'Akismet', 'user-registration' ) . '</a>',
				esc_html__( 'plugin has not been properly configured. For more', 'user-registration' ),
				'<a href="https://docs.wpuserregistration.com/docs/individual-form-settings/#10-toc-title" rel="noreferrer noopener" target="_blank">' . esc_html__( 'information.', 'user-registration' ) . '</a>'
			);
		}
	}

}

if ( ! function_exists( 'ur_is_akismet_configured' ) ) {

	/**
	 * Has the Akismet plugin been configured wih a valid API key?
	 *
	 * @return bool
	 * @since 4.2.1.2
	 */
	function ur_is_akismet_configured() {

		if ( ! is_plugin_active( 'akismet/akismet.php' ) ) {
			return false;
		}
		require_once WP_PLUGIN_DIR . '/akismet/akismet.php';

		$akismet_instance = new Akismet();
		// Akismet will only allow an API key to be saved if it is a valid key.
		// We can assume that if there is an API key saved, it is valid.
		$akismet_api_key = $akismet_instance->get_api_key();

		if ( ! empty( $akismet_api_key ) ) {
			return true;
		}

		return false;
	}


}

add_filter( 'user_registration_get_akismet_validate', 'ur_get_akismet_validate', 10, 2 );
if ( ! function_exists( 'ur_get_akismet_validate' ) ) {
	/**
	 * Check if registration should be validated by Akismet for potential spam.
	 *
	 * This function checks whether the Akismet plugin is installed and configured, and if the Akismet
	 * validation option is enabled for a specific form. If validation is enabled, it prepares the
	 * necessary data for the validation request and sends it to Akismet's 'registration-check' endpoint.
	 *
	 * @param int   $form_id The form_id to check if to validate.
	 * @param array $form_data values to validate.
	 *
	 * @return bool
	 *   - true if the form_data is potentially spam according to Akismet.
	 *   - false if Akismet validation is not enabled, the plugin is not properly configured, or the form_data is not considered spam.
	 */
	function ur_get_akismet_validate( $form_id, $form_data ) {
		if ( ! file_exists( WP_PLUGIN_DIR . '/akismet/akismet.php' ) ) {
			return false;
		}

		if ( ! ur_is_akismet_configured() ) {
			return false;
		}

		$is_akismet_enabled = ur_get_single_post_meta( $form_id, 'user_registration_enable_akismet' );
		if ( $is_akismet_enabled ) {

			$form_content = ur_get_form_data_for_akismet( $form_data );

			$request = array(
				'blog'                 => home_url(),
				'user_ip'              => ur_get_ip_address(),
				'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : null, // phpcs:ignore
				// phpcs:ignore
				'referrer'             => wp_get_referer() ? wp_get_referer() : null,
				'permalink'            => ur_current_url(),
				'comment_type'         => 'registration',
				'comment_author'       => isset( $form_content['user_login'] ) ? $form_content['user_login'] : '',
				'comment_author_email' => isset( $form_content['user_email'] ) ? $form_content['user_email'] : '',
				'comment_author_url'   => isset( $form_content['user_url'] ) ? $form_content['user_url'] : '',
				'comment_content'      => isset( $form_content['other_content'] ) ? $form_content['other_content'] : '',
				'blog_lang'            => get_locale(),
				'blog_charset'         => get_bloginfo( 'charset' ),
			);

			$response = Akismet::http_post( build_query( $request ), 'comment-check' );

			return ! empty( $response ) && isset( $response[1] ) && 'true' === trim( $response[1] );
		}

		return false;
	}
}

if ( ! function_exists( 'ur_get_form_data_for_akismet' ) ) {
	/**
	 * Get user submitted form data for akismet spam monitoring.
	 *
	 * @param array $form_data User submitted form data.
	 */
	function ur_get_form_data_for_akismet( $form_data ) {
		$field_type_allowlist = ur_get_allowed_field_for_akisment();
		$entry_data           = array();
		$form_content         = array();
		$other_content        = array();

		foreach ( $form_data as $key => $field ) {
			if ( isset( $field->extra_params['field_key'] ) ) {
				$entry_data[ $field->extra_params['field_key'] ] = $field->value;
			}
		}

		foreach ( $entry_data as $field_key => $value ) {
			if ( in_array( $field_key, $field_type_allowlist ) ) {
				switch ( $field_key ) {
					case 'user_email':
						$form_content['user_email'] = $value;
						break;
					case 'user_url':
						$form_content['user_url'] = $value;
						break;
					case 'user_login':
						$form_content['user_login'] = $value;
						break;
					default:
						$other_content[] = $value;
						break;
				}
			}
		}

		if ( ! empty( $other_content ) ) {
			$form_content['other_content'] = implode( ' ', $other_content );
		}

		return $form_content;
	}
}

if ( ! function_exists( 'ur_get_allowed_field_for_akisment' ) ) {
	/**
	 * List of allowed fields for spam protection by akismet.
	 */
	function ur_get_allowed_field_for_akisment() {
		$field_type_allowlist = array(
			'user_login',
			'text',
			'textarea',
			'user_email',
			'phone',
			'address',
			'user_url',
			'wysiwyg',
			'description',
		);

		return $field_type_allowlist;
	}
}

if ( ! function_exists( 'ur_current_url' ) ) {
	/**
	 * Get the current URL.
	 *
	 * @return string
	 * @since 3.2.0
	 */
	function ur_current_url() {

		$parsed_home_url = wp_parse_url( home_url() );

		$url = $parsed_home_url['scheme'] . '://' . $parsed_home_url['host'];

		if ( ! empty( $parsed_home_url['port'] ) ) {
			$url .= ':' . $parsed_home_url['port'];
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$url .= wp_unslash( $_SERVER['REQUEST_URI'] );

		return esc_url_raw( $url );
	}
}

add_filter(
	'body_class',
	function ( $classes ) {
		$is_settings_sidebar_enabled = isset( $_COOKIE['isSidebarEnabled'] ) ? ur_string_to_bool( sanitize_text_field( wp_unslash( $_COOKIE['isSidebarEnabled'] ) ) ) : true;
		$body_class                  = ! $is_settings_sidebar_enabled ? 'ur-settings-sidebar-hidden' : 'ur-settings-sidebar-show';

		return array_merge( $classes, array( $body_class ) );
	}
);

add_filter( 'user_registration_settings_text_format', 'ur_settings_text_format', 10 );
if ( ! function_exists( 'ur_settings_text_format' ) ) {
	/**
	 * Settings text format.
	 *
	 * @param array $args Arguments.
	 *
	 * @return array
	 * @since 3.3.1
	 */
	function ur_settings_text_format( $args ) {
		// Group similar text format fields.
		$fields_to_format = array( 'description', 'tip', 'tooltip', 'tooltip_message', 'desc' );

		foreach ( $args as &$arg ) {
			if ( in_array( $arg['id'], ur_get_exclude_text_format_settings() ) ) {
				continue;
			}

			if ( isset( $arg['label'] ) ) {
				if ( strpos( $arg['label'], 'CSS' ) < 0 ) {
					$arg['label'] = ur_get_capitalized_words( $arg['label'] );
				}
			}

			if ( isset( $arg['desc_tip'] ) && ( $arg['desc_tip'] != 1 || $arg['desc_tip'] !== true ) ) { // phpcs:ignore
				$arg['desc_tip'] = ucwords( strtolower( $arg['desc_tip'] ) );
			}

			if ( isset( $arg['title'] ) ) {
				$arg['title'] = ucwords( $arg['title'] );
			}

			foreach ( $fields_to_format as $field ) {

				if ( isset( $arg[ $field ] ) ) {
					if ( strpos( trim( $arg[ $field ] ), '<div' ) !== 0 ) {
						strpos( trim( $arg[ $field ] ), '<div' );
						$arg[ $field ] = ur_format_sentence_case( strtolower( $arg[ $field ] ) );

					} else {
						$arg[ $field ] = $arg[ $field ];
					}
				}
			}

			if ( isset( $arg['options'] ) && is_array( $arg['options'] ) ) {
				foreach ( $arg['options'] as $key => $option ) {
					$arg['options'][ $key ] = ucfirst( strtolower( $option ) );
				}
			}
		}

		return $args;
	}
}
if ( ! function_exists( 'ur_format_sentence_case' ) ) {
	/**
	 * Capitalizes the first letter of the initial word and each word after a period.
	 *
	 * @param string $string String to format.
	 *
	 * @return string
	 */
	function ur_format_sentence_case( $string ) {
		$sentences = preg_split( '/(\.\s+)/', $string, - 1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $sentences as &$sentence ) {
			if ( trim( $sentence ) !== '' && $sentence !== '.' && ! preg_match( '/^\.\s*$/', $sentence ) ) { // phpcs:ignore
				$sentence = ucfirst( ltrim( $sentence ) );
			}
		}

		return implode( '', $sentences );
	}
}

if ( ! function_exists( 'ur_get_capitalized_words' ) ) {
	/**
	 * Get form data.
	 *
	 * @param string $label Label to capitalize.
	 *
	 * @return array
	 * @since 3.3.1
	 */
	function ur_get_capitalized_words( $label ) {
		$prepositions = array( 'at', 'by', 'for', 'in', 'on', 'to', 'or' );

		$words = explode( ' ', $label );

		$capitalized_words = array();

		foreach ( $words as $word ) {

			$word = trim( $word );
			// Convert the word to lowercase if it is a preposition.
			if ( in_array( strtolower( $word ), $prepositions ) ) {
				$capitalized_words[] = strtolower( $word );
				continue;
			}
			// Convert the word to uppercase if it is an abbreviation.
			if ( strpos( $word, '-' ) !== false || strpos( $word, '/' ) !== false ) {
				$separators = array( '-', '/' );
				foreach ( $separators as $separator ) {
					if ( strpos( $word, $separator ) !== false ) {
						$terms             = explode( $separator, $word );
						$capitalized_terms = array();
						foreach ( $terms as $term ) {
							$capitalized_terms[] = ucfirst( strtolower( $term ) );
						}
						$word = implode( $separator, $capitalized_terms );
						break;
					}
				}
			} else {
				$word = ucfirst( strtolower( $word ) );
			}

			$capitalized_words[] = $word;
		}

		return implode( ' ', $capitalized_words );
	}
}

add_action( 'wp_mail_failed', 'ur_email_send_failed_handler', 1 );

if ( ! function_exists( 'ur_email_send_failed_handler' ) ) {

	/**
	 * Handle errors fetch mechanism when mail send failed.
	 *
	 * @param object $error_instance WP_Error message instance.
	 */
	function ur_email_send_failed_handler( $error_instance ) {
		$error_message = '';
		$error_data    = $error_instance->get_error_data();

		ur_get_logger()->debug(
			sprintf(
				'Mail action ***wp_mail_failed*** triggered. Callback function :: ***%s()***',
				__FUNCTION__
			),
			array( 'source' => 'ur_mail_logs' )
		);

		$mail_log_data = array(
			'reason'      => $error_instance->get_error_message(),
			'to'          => isset( $error_data['to'] ) ? $error_data['to'] : '',
			'subject'     => isset( $error_data['subject'] ) ? $error_data['subject'] : '',
			'headers'     => isset( $error_data['headers'] ) ? $error_data['headers'] : array(),
			'attachments' => isset( $error_data['attachments'] ) ? $error_data['attachments'] : array(),
		);

		ur_get_logger()->error(
			'Mail send failed. Details:' . "\n" . wp_json_encode( $mail_log_data, JSON_PRETTY_PRINT ),
			array( 'source' => 'ur_mail_logs' )
		);

		if ( '' !== json_decode( $error_instance->get_error_message() ) ) {
			/* translators: %s: Status Log URL*/
			$error_message = wp_kses_post(
				sprintf(
					__( 'Please check the `ur_mail_logs` log under <a target="_blank" href="%s">Status Log</a> section.', 'user-registration' ),
					admin_url( 'admin.php?page=user-registration-status' )
				)
			);
		} else {
			$error_message = $error_instance->get_error_message();
		}

		if ( '' !== $error_message ) {
			add_filter(
				'user_registration_email_send_failed_message',
				function ( $msg ) use ( $error_message ) {
					return $error_message;
				}
			);
		}
	}
}

add_action( 'user_registration_custom_notices', 'ur_email_send_failed_notice' );

if ( ! function_exists( 'ur_email_send_failed_notice' ) ) {

	/**
	 * Add notice about email send failed to be displayed in dashboard.
	 *
	 * @param array $notices Custom notices.
	 */
	function ur_email_send_failed_notice( $notices ) {
		$failed_data = get_transient( 'user_registration_mail_send_failed_count' );

		if ( ! $failed_data ) {
			return $notices;
		}

		$failed_count  = isset( $failed_data['failed_count'] ) ? $failed_data['failed_count'] : 0;
		$error_message = isset( $failed_data['error_message'] ) ? $failed_data['error_message'] : '';

		$custom_notice = array(
			array(
				'id'                    => 'ur_email_send_failed',
				'type'                  => 'info',
				'status'                => 'active',
				'priority'              => '1',
				'title'                 => __( 'User Registration & Membership Email Send Error', 'user-registration' ),
				'message_content'       => wp_kses_post(
					sprintf(
						'<p>%s</p><p class="ur-email-send-failed-log">%s</p>',
						__( 'The last emails sent from User Registration & Membership Plugin was not delivered to the user. ', 'user-registration' ),
						$error_message
					)
				),
				'buttons'               => array(
					array(
						'title'  => __( 'I have a query', 'user-registration' ),
						'icon'   => 'dashicons-testimonial',
						'link'   => 'https://wpuserregistration.com/support',
						'class'  => 'button-secondary notice-have-query',
						'target' => '_blank',
					),
					array(
						'title'  => __( 'Visit Documentation', 'user-registration' ),
						'icon'   => 'dashicons-media-document',
						'link'   => 'https://docs.wpuserregistration.com/docs/why-are-emails-not-being-delivered/',
						'class'  => 'button-secondary notice-have-query',
						'target' => '_blank',
					),
				),
				'permanent_dismiss'     => true,
				'reopen_days'           => '1',
				'reopen_times'          => '1',
				'conditions_to_display' => array(
					array(
						'operator'    => 'AND',
						'show_notice' => $failed_count > 3 ? true : false,
					),
				),
			),
		);

		$notices = array_merge( $notices, $custom_notice );

		return $custom_notice;
	}
}

add_action( 'admin_init', 'user_registration_spam_users_detector' );

if ( ! function_exists( 'user_registration_spam_users_detector' ) ) {

	/**
	 * Count numbers of spams registered in previous hour.
	 */
	function user_registration_spam_users_detector() {
		global $wpdb;
		$activation_date   = get_option( 'user_registration_activated' );
		$current_timestamp = time();

		$spam_notice_dismissed = get_option( 'user_registration_info_ur_spam_users_detected_notice_dismissed_temporarily', false );
		$spam_notice_dismissed = ! $spam_notice_dismissed ? get_option( 'user_registration_ur_spam_users_detected_notice_dismissed', false ) : $spam_notice_dismissed;

		if ( $current_timestamp - strtotime( $activation_date ) > 86400 && ! $spam_notice_dismissed ) {
			$spam_count = get_transient( 'ur_spam_users_detected_count' );

			if ( ! $spam_count ) {

				$current_hour_time_gmt   = gmdate( 'Y-m-d H:i:s', $current_timestamp );
				$previous_hour_timestamp = $current_timestamp - 3600;
				$previous_hour_time_gmt  = gmdate( 'Y-m-d H:i:s', $previous_hour_timestamp );

				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT u.ID
						FROM {$wpdb->users} u
						LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'ur_form_id'
						WHERE um.user_id IS NULL
						AND u.user_registered BETWEEN %s AND %s",
						$previous_hour_time_gmt,
						$current_hour_time_gmt
					)
				);

				$total_users = count( $results );
				set_transient( 'ur_spam_users_detected_count', $total_users, HOUR_IN_SECONDS );
			}
		}
	}
}

add_action( 'user_registration_custom_notices', 'ur_spam_users_detected' );

if ( ! function_exists( 'ur_spam_users_detected' ) ) {

	/**
	 * Display spam users registered notice if spam users count exceeds 20 in past hour.
	 *
	 * @param array $notices Custom Notices.
	 */
	function ur_spam_users_detected( $notices ) {
		$spam_users_count      = get_transient( 'ur_spam_users_detected_count' );
		$spam_notice_dismissed = get_option( 'user_registration_info_ur_spam_users_detected_notice_dismissed_temporarily', false );
		$spam_notice_dismissed = ! $spam_notice_dismissed ? get_option( 'user_registration_ur_spam_users_detected_notice_dismissed', false ) : $spam_notice_dismissed;

		if ( ! $spam_users_count || $spam_notice_dismissed ) {
			return $notices;
		}

		$custom_notice = array(
			array(
				'id'                    => 'ur_spam_users_detected',
				'type'                  => 'info',
				'status'                => 'active',
				'priority'              => '1',
				'title'                 => __( 'Unusual User Registrations Detected', 'user-registration' ),
				'message_content'       => wp_kses_post(
					sprintf(
						'<p>%s</p><p>%s</p>',
						__( 'A significant number of users have registered on your site from sources other than the User Registration & Membership plugin\'s form.', 'user-registration' ),
						__( 'These registrations may be suspicious. Please review and disable any other methods that allow user registrations if they are not intended. Additionally, consider enabling spam protection measures in the User Registration & Membership to safeguard your site.', 'user-registration' ),
					)
				),
				'buttons'               => array(
					array(
						'title'  => __( 'It was a false alarm', 'user-registration' ),
						'icon'   => 'dashicons-no-alt',
						'class'  => 'notice-dismiss notice-dismiss-permanently',
						'target' => '',
						'link'   => '',
					),
					array(
						'title'  => __( 'I have a query', 'user-registration' ),
						'icon'   => 'dashicons-testimonial',
						'link'   => 'https://wpuserregistration.com/support',
						'class'  => 'button-secondary notice-have-query',
						'target' => '_blank',
					),
					array(
						'title'  => __( 'Visit Documentation', 'user-registration' ),
						'icon'   => 'dashicons-media-document',
						'link'   => 'https://docs.wpuserregistration.com/docs/how-to-integrate-google-recaptcha/',
						'class'  => 'button-secondary',
						'target' => '_blank',
					),
				),
				'permanent_dismiss'     => true,
				'reopen_days'           => '1',
				'reopen_times'          => '1',
				'conditions_to_display' => array(
					array(
						'operator'    => 'AND',
						'show_notice' => $spam_users_count > 20 ? true : false,
					),
				),
			),
		);

		$notices = array_merge( $notices, $custom_notice );

		return $custom_notice;
	}
}

if ( ! function_exists( 'ur_non_deletable_fields' ) ) {
	/**
	 * user registration non deletable fields.
	 */
	function ur_non_deletable_fields() {
		return apply_filters(
			'user_registration_non_deletable_fields',
			array(
				'user_email',
				'user_pass',
			)
		);
	}
}

// TODO: Remove this code once Really Simple SSL plugin resolves the conflict from their side.
if ( ! function_exists( 'ur_rsssl_anyone_can_register_conflict_resolver' ) ) {

	/**
	 * Resolve anyone can register setting conflict with Really Simple SSL Plugin.
	 *
	 * @param bool $value Option value.
	 */
	function ur_rsssl_anyone_can_register_conflict_resolver( $value ) {
		global $wpdb;

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ) ) {

			$rsssl_options = get_option( 'rsssl_options', '' );
			$rsssl_options = maybe_unserialize( $rsssl_options );

			if ( isset( $rsssl_options['disable_anyone_can_register'] ) && $rsssl_options['disable_anyone_can_register'] ) {
				$value = $wpdb->get_var( "SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = 'users_can_register';" ); // phpcs:ignore;

				if ( $value ) {
					return true;
				}
			}
		}

		return $value;
	}
}
add_filter( 'ur_register_setting_override', 'ur_rsssl_anyone_can_register_conflict_resolver', 10, 1 );

if ( ! function_exists( 'ur_users_can_register' ) ) {
	/**
	 * Whether registration is allowed, honoring the WordPress "Anyone can register" option.
	 *
	 * @since 5.2.7
	 *
	 * @return bool
	 */
	function ur_users_can_register() {
		/**
		 * Filter to override the register settings.
		 * Default value is the get_option('users_can_register')
		 */
		return (bool) apply_filters( 'ur_register_setting_override', get_option( 'users_can_register' ) );
	}
}

add_filter( 'user_registration_settings_prevent_default_login', 'ur_prevent_default_login' );
if ( ! function_exists( 'ur_prevent_default_login' ) ) {
	/**
	 * Handel error when default login screen is disabled but redirect login poage is not selected.
	 *
	 * @return @mixed
	 * @since 3.3.1
	 */
	function ur_prevent_default_login( $data ) {
		// Return if default wp_login is disabled and no redirect url is set.
		if ( isset( $data['user_registration_login_options_prevent_core_login'] ) && $data['user_registration_login_options_prevent_core_login'] ) {
			if ( isset( $data['user_registration_login_options_login_redirect_url'] ) ) {
				gettype( $data['user_registration_login_options_login_redirect_url'] );
				if ( ! $data['user_registration_login_options_login_redirect_url'] ) {
					return 'redirect_login_error';
				}
				if ( is_numeric( $data['user_registration_login_options_login_redirect_url'] ) ) {
					$is_page_my_account_page = ur_find_my_account_in_page( $data['user_registration_login_options_login_redirect_url'] );
					if ( ! $is_page_my_account_page ) {
						return 'redirect_login_not_myaccount';
					}
				}
			}
		} elseif ( isset( $data['user_registration_myaccount_page_id'] ) ) {
			if ( is_numeric( $data['user_registration_myaccount_page_id'] ) ) {
				$is_page_my_account_page = ur_find_my_account_in_page( $data['user_registration_myaccount_page_id'] );
				if ( ! $is_page_my_account_page ) {
					return 'redirect_login_not_myaccount';
				}
			}
		} elseif ( ur_check_module_activation( 'membership' ) && isset( $data['user_registration_member_registration_page_id'] ) && isset( $data['user_registration_thank_you_page_id'] ) ) {
			if ( is_numeric( $data['user_registration_thank_you_page_id'] ) && is_numeric( $data['user_registration_member_registration_page_id'] ) ) {
				$membership_service = new \WPEverest\URMembership\Admin\Services\MembershipService();
				$pages              = array(
					'user_registration_member_registration_page_id',
					'user_registration_thank_you_page_id',
				);
				$has_invalid_page   = false;
				foreach ( $pages as $k => $page ) {
					$response = $membership_service->verify_page_content( $page, $data[ $page ] );
					if ( ! $response['status'] ) {
						$has_invalid_page = true;
					}
				}

				if ( $has_invalid_page ) {
					return 'invalid_membership_pages';
				}
			}
		} elseif ( isset( $data['user_registration_membership_renewal_reminder_days_before'] ) ) {
			if ( $data['user_registration_membership_renewal_reminder_days_before'] <= 0 ) {
				return 'invalid_renewal_period';
			}
		}

		return true;
	}
}

if ( ! function_exists( 'get_forms_for_wpbakery' ) ) {

	/**
	 * Get User Registration forms list for wpbakery.
	 */
	function get_forms_for_wpbakery() {
		$user_registration_forms = array();

		if ( empty( $user_registration_forms ) ) {
			$ur_forms = ur_get_all_user_registration_form();
			if ( ! empty( $ur_forms ) ) {

				foreach ( $ur_forms as $form_value => $form_name ) {
					$user_registration_forms[ $form_name ] = $form_value;
				}
			} else {
				$user_registration_forms[0] = esc_html__( 'You have not created a form, Please Create a form first', 'user-registration' );
			}

			return $user_registration_forms;
		}
	}
}

/**
 * Create WPBakery Widget for User Registration.
 */
add_action( 'vc_before_init', 'create_wpbakery_widget_category' );

/**
 * Create WPBakery Widgets for User Registration.
 *
 * @since 3.3.2
 */
function create_wpbakery_widget_category() {
	vc_map(
		array(
			'name'        => esc_html__( 'Registration Form', 'user-registration' ),
			'base'        => 'user_registration_form',
			'icon'        => 'icon-wpb-vc_user_registration',
			'category'    => esc_html__( 'User Registration', 'user-registration' ),
			'description' => esc_html__( 'Registration Form widget for WPBakery.', 'user-registration' ),
			'params'      => array(
				array(
					'type'        => 'dropdown',
					'heading'     => esc_html__( 'Form', 'user-registration' ),
					'param_name'  => 'id',
					'value'       => get_forms_for_wpbakery(),
					'description' => esc_html__( 'Select Form.', 'user-registration' ),
				),
			),
		)
	);
	vc_map(
		array(
			'name'        => esc_html__( 'My Account', 'user-registration' ),
			'base'        => 'user_registration_my_account',
			'icon'        => 'icon-wpb-vc_user_registration',
			'category'    => esc_html__( 'User Registration', 'user-registration' ),
			'description' => esc_html__( 'My Account widget for WPBakery.', 'user-registration' ),
			'params'      => array(
				array(
					'type'        => 'textfield',
					'heading'     => esc_html__( 'Redirect URL', 'user-registration' ),
					'param_name'  => 'redirect_url',
					'value'       => '',
					'description' => esc_html__( 'Enter redirect url after login.', 'user-registration' ),
				),
				array(
					'type'        => 'textfield',
					'heading'     => esc_html__( 'Logout URL', 'user-registration' ),
					'param_name'  => 'logout_redirect',
					'value'       => '',
					'description' => esc_html__( 'Enter url which redirect after logout.', 'user-registration' ),
				),
			),
		),
	);
	vc_map(
		array(
			'name'        => esc_html__( 'Login Form', 'user-registration' ),
			'base'        => 'user_registration_login',
			'icon'        => 'icon-wpb-vc_user_registration',
			'category'    => esc_html__( 'User Registration', 'user-registration' ),
			'description' => esc_html__( 'Login Form widget for WPBakery.', 'user-registration' ),
			'params'      => array(
				array(
					'type'        => 'textfield',
					'heading'     => esc_html__( 'Redirect URL', 'user-registration' ),
					'param_name'  => 'redirect_url',
					'value'       => '',
					'description' => esc_html__( 'Enter redirect url after login.', 'user-registration' ),
				),
				array(
					'type'        => 'textfield',
					'heading'     => esc_html__( 'Logout URL', 'user-registration' ),
					'param_name'  => 'logout_redirect',
					'value'       => '',
					'description' => esc_html__( 'Enter url which redirect after logout.', 'user-registration' ),
				),
			),
		),
	);
	vc_map(
		array(
			'name'        => esc_html__( 'Edit Profile', 'user-registration' ),
			'base'        => 'user_registration_edit_profile',
			'icon'        => 'icon-wpb-vc_user_registration',
			'category'    => esc_html__( 'User Registration & Membership', 'user-registration' ),
			'description' => esc_html__( 'Edit Profile widget for WPBakery.', 'user-registration' ),
		),
	);
	vc_map(
		array(
			'name'        => esc_html__( 'Edit Password', 'user-registration' ),
			'base'        => 'user_registration_edit_password',
			'icon'        => 'icon-wpb-vc_user_registration',
			'category'    => esc_html__( 'User Registration & Membership', 'user-registration' ),
			'description' => esc_html__( 'Edit Password widget for WPBakery.', 'user-registration' ),
		),
	);

	/**
	 * Hook to add more wpbakery widget for user registration.
	 */
	do_action( 'user_registration_add_wpbakery_widget' );
}

if ( ! function_exists( 'ur_integration_addons' ) ) {
	/**
	 * List of integrations.
	 *
	 * @return array
	 * @since 3.3.1
	 */
	function ur_integration_addons() {

		$integration_list = array(
			'UR_Settings_SMS_Integration'  => array(
				'id'           => 'sms_integration',
				'type'         => 'accordian',
				'title'        => esc_html__( 'Twilio', 'user-registration' ),
				'video_id'     => '-iUMcr03FP8',
				'available_in' => 'Personal Plan',
				'activated'    => ur_check_module_activation( 'sms-integration' ),
				'display'      => array( 'settings' ),
				'connected'    => ! empty( get_option( 'ur_sms_integration_accounts', array() ) ) ? true : false,
				'plugin_name'  => esc_html__( 'Twilio', 'user-registration' ),
			),
			$integration['UR_Settings_ActiveCampaign'] = array(
				'id'           => 'activecampaign',
				'type'         => 'accordian',
				'title'        => esc_html__( 'ActiveCampaign', 'user-registration' ),
				'desc'         => '',
				'video_id'     => 'AfapJxM9klk',
				'available_in' => 'Themegrill Agency Plan or Professional Plan or Plus Plan',
				'activated'    => is_plugin_active( 'user-registration-activecampaign/user-registration-activecampaign.php' ),
				'display'      => array( 'settings', 'form_settings' ),
				'connected'    => ! empty( get_option( 'ur_activecampaign_accounts', array() ) ) ? true : false,
				'plugin_name'  => esc_html__( 'User Registration ActiveCampaign', 'user-registration' ),
			),
			$integration['UR_Settings_MailerLite'] = array(
				'id'           => 'mailerlite',
				'type'         => 'accordian',
				'title'        => esc_html__( 'MailerLite', 'user-registration' ),
				'desc'         => '',
				'video_id'     => '4f1lGgFuJx4',
				'available_in' => 'Themegrill Agency Plan or Professional Plan or Plus Plan',
				'activated'    => is_plugin_active( 'user-registration-mailerlite/user-registration-mailerlite.php' ),
				'display'      => array( 'settings', 'form_settings' ),
				'connected'    => ! empty( get_option( 'ur_mailerlite_accounts', array() ) ) ? true : false,
				'plugin_name'  => esc_html__( 'User Registration MailerLite', 'user-registration' ),
			),
			$integration['UR_Settings_klaviyo'] = array(
				'id'           => 'klaviyo',
				'type'         => 'accordian',
				'title'        => esc_html__( 'Klaviyo', 'user-registration' ),
				'desc'         => '',
				'video_id'     => 'nKOMqrkNK3Y',
				'available_in' => 'Themegrill Agency Plan or Professional Plan or Plus Plan',
				'activated'    => is_plugin_active( 'user-registration-klaviyo/user-registration-klaviyo.php' ),
				'display'      => array( 'settings', 'form_settings' ),
				'connected'    => ! empty( get_option( 'ur_klaviyo_accounts', array() ) ) ? true : false,
				'plugin_name'  => esc_html__( 'User Registration Klaviyo', 'user-registration' ),
			),
			$integration['UR_Settings_Mailchimp'] = array(
				'id'           => 'mailchimp',
				'type'         => 'accordian',
				'title'        => esc_html__( 'Mailchimp', 'user-registration' ),
				'desc'         => '',
				'video_id'     => 'iyCByez_7U8',
				'available_in' => 'Personal Plan',
				'activated'    => is_plugin_active( 'user-registration-mailchimp/user-registration-mailchimp.php' ),
				'display'      => array( 'settings', 'form_settings' ),
				'connected'    => ! empty( get_option( 'ur_mailchimp_accounts', array() ) ) ? true : false,
				'plugin_name'  => esc_html__( 'User Registration - Mailchimp', 'user-registration' ),
			),
			'User_Registration_Zapier'     => array(
				'id'           => 'zapier',
				'type'         => 'accordian',
				'title'        => esc_html__( 'Zapier', 'user-registration' ),
				'desc'         => '',
				'video_id'     => 'zxl2nsXyOmw',
				'available_in' => 'Themegrill Agency Plan or Professional Plan or Plus Plan',
				'activated'    => is_plugin_active( 'user-registration-zapier/user-registration-zapier.php' ),
				'display'      => array( 'form_settings' ),
				'connected'    => ! empty( get_option( 'ur_zapier_accounts', array() ) ) ? true : false,
				'plugin_name'  => esc_html__( 'User Registration Zapier', 'user-registration' ),
			),
			'WPEverest\URMailPoet'         => array(
				'id'           => 'mailpoet',
				'type'         => 'accordian',
				'title'        => esc_html__( 'MailPoet', 'user-registration' ),
				'desc'         => '',
				'video_id'     => '4uFlZoXlye4',
				'available_in' => 'Themegrill Agency Plan or Professional Plan or Plus Plan',
				'activated'    => is_plugin_active( 'user-registration-mailpoet/user-registration-mailpoet.php' ),
				'display'      => array( 'settings', 'form_settings' ),
				'connected'    => ur_string_to_bool( get_option( 'user_registration_integrations_mailpoet_connection', false ) ),
				'plugin_name'  => esc_html__( 'User Registration MailPoet', 'user-registration' ),
			),
			'WPEverest\URConvertKit'       => array(
				'id'           => 'convertkit',
				'type'         => 'accordian',
				'title'        => esc_html__( 'ConvertKit', 'user-registration' ),
				'desc'         => '',
				'video_id'     => '',
				'available_in' => 'Themegrill Agency Plan or Professional Plan or Plus Plan',
				'activated'    => is_plugin_active( 'user-registration-convertkit/user-registration-convertkit.php' ),
				'display'      => array( 'settings', 'form_settings' ),
				'connected'    => is_plugin_active( 'user-registration-convertkit/user-registration-convertkit.php' ) && ! empty( get_option( 'ur_convertkit_accounts', array() ) ) ? true : false,
				'plugin_name'  => esc_html__( 'User Registration ConvertKit', 'user-registration' ),
			),
			'User_Registration_Brevo'      => array(
				'id'           => 'brevo',
				'type'         => 'accordian',
				'title'        => esc_html__( 'Brevo', 'user-registration' ),
				'desc'         => '',
				'video_id'     => '',
				'available_in' => 'Themegrill Agency Plan or Professional Plan or Plus Plan',
				'activated'    => is_plugin_active( 'user-registration-brevo/user-registration-brevo.php' ),
				'display'      => array( 'settings', 'form_settings' ),
				'connected'    => is_plugin_active( 'user-registration-brevo/user-registration-brevo.php' ) && ur_string_to_bool( get_option( 'user_registration_integrations_brevo_connection', false ) ),
				'plugin_name'  => esc_html__( 'User Registration Brevo', 'user-registration' ),
			),
			'User_Registration_Salesforce' => array(
				'id'           => 'salesforce',
				'type'         => 'accordian',
				'title'        => esc_html__( 'Salesforce', 'user-registration' ),
				'desc'         => '',
				'video_id'     => '',
				'available_in' => 'Themegrill Agency Plan or Professional Plan or Plus Plan',
				'activated'    => is_plugin_active( 'user-registration-salesforce/user-registration-salesforce.php' ),
				'display'      => array( 'settings', 'form_settings' ),
				'connected'    => is_plugin_active( 'user-registration-salesforce/user-registration-salesforce.php' ) && ! empty( get_option( 'ur_salesforce_accounts', array() ) ) ? true : false,
				'plugin_name'  => esc_html__( 'User Registration Salesforce', 'user-registration' ),
			),
		);

		usort(
			$integration_list,
			function ( $a, $b ) {
				return $b['activated'] <=> $a['activated']; //phpcs:ignore;
			}
		);

		usort(
			$integration_list,
			function ( $a, $b ) {
				return $b['connected'] <=> $a['connected']; //phpcs:ignore;
			}
		);

		return $integration_list;
	}

}
if ( ! function_exists( 'ur_list_top_integrations' ) ) {
	/**
	 * List top integrations.
	 *
	 * @param array $integrations Integrations.
	 *
	 * @return array
	 * @since 3.3.1
	 */
	function ur_list_top_integrations( $integrations ) {
		$is_free = is_plugin_active( 'user-registration/user-registration.php' );
		if ( $is_free ) {
			$integration_addons = ur_integration_addons();
			foreach ( $integration_addons as $key => $addon ) {
				if ( isset( $addon['display'] ) && ! in_array( 'settings', $addon['display'] ) ) {
					continue;
				}

				$integration[ $key ] = $addon;
			}

			return $integration;
		}
	}
}
add_filter( 'user_registration_integrations_classes', 'ur_list_top_integrations' );

if ( ! function_exists( 'ur_get_captcha_integrations' ) ) {
	/**
	 * List top captchas.
	 *
	 * @return array
	 * @since 3.3.4
	 */
	function ur_get_captcha_integrations() {
		return apply_filters(
			'user_registration_captcha_integrations',
			array(
				'v2'         => 'reCaptcha v2',
				'v3'         => 'reCaptcha v3',
				'hCaptcha'   => 'hCaptcha',
				'cloudflare' => 'Cloudflare Turnstile',
			)
		);
	}
}

add_action(
	'user_registration_form_shortcode_scripts',
	function ( $atts ) {

		$form_id        = isset( $atts['id'] ) ? $atts['id'] : 0;
		$recaptcha_type = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_configured_captcha_type', 'v2' );

		add_filter(
			'user_registration_params',
			function ( $data ) use ( $recaptcha_type ) {
				$data['recaptcha_type'] = $recaptcha_type;

				return $data;
			}
		);
	},
	10,
	1
);

// Hook the end setup wizard to admin_init
add_action(
	'admin_init',
	'ur_end_setup_wizard'
);

if ( ! function_exists( 'ur_end_setup_wizard' ) ) {
	/**
	 * End to setup wizard.
	 */
	function ur_end_setup_wizard() {
		// End setup wizard when skipped to list table.
		if ( ! empty( $_REQUEST['end-setup-wizard'] ) && sanitize_text_field( wp_unslash( $_REQUEST['end-setup-wizard'] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			update_option( 'user_registration_first_time_activation_flag', false );
			update_option( 'user_registration_onboarding_skipped', true );

			if ( isset( $_REQUEST['activeStep'] ) ) { // phpcs:ignore
				update_option( 'user_registration_onboarding_skipped_step', sanitize_text_field( wp_unslash( $_REQUEST['activeStep'] ) ) ); // phpcs:ignore
			} else {
				delete_option( 'user_registration_onboarding_skipped_step' );
				update_option( 'user_registration_onboarding_skipped', false );
			}
		}
	}
}

if ( ! function_exists( 'ur_get_exclude_text_format_settings' ) ) {
	/**
	 * Exclude text format settings.
	 */
	function ur_get_exclude_text_format_settings() {
		$settings = array(
			'user_registration_form_setting_enable_recaptcha_support',
		);

		return $settings;
	}
}
if ( ! function_exists( 'ur_check_url_is_image' ) ) {

	/**
	 * Check if a URL points to a valid image.
	 *
	 * This function first checks if the URL belongs to a local WordPress attachment
	 * to avoid self-referential HTTP requests which can cause heavy server load.
	 * Only falls back to cURL for truly remote URLs (e.g., social login profile pictures).
	 *
	 * @param string $url The URL to check.
	 *
	 * @return bool True if the URL points to a valid image, false otherwise.
	 * @since x.x.x (updated)
	 */
	function ur_check_url_is_image( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}

		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id > 0 ) {
			return wp_attachment_is_image( $attachment_id );
		}

		$site_url = get_site_url();
		if ( strpos( $url, $site_url ) === 0 ) {
			$allowed_image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'svg' );
			$parsed_url               = wp_parse_url( $url );
			if ( ! empty( $parsed_url['path'] ) ) {
				$extension = strtolower( pathinfo( $parsed_url['path'], PATHINFO_EXTENSION ) );
				if ( in_array( $extension, $allowed_image_extensions, true ) ) {
					$upload_dir = wp_upload_dir();
					if ( strpos( $url, $upload_dir['baseurl'] ) === 0 ) {
						$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );

						return file_exists( $file_path );
					}

					return true;
				}
			}

			return false;
		}

		$response = wp_remote_head(
			$url,
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		return ! empty( $content_type ) && strpos( $content_type, 'image/' ) !== false;
	}


}

if ( ! function_exists( 'ur_get_user_registered_source' ) ) {
	/**
	 * Returns the user registered source/form name.
	 *
	 * @param [int] $user_id User Id.
	 *
	 * @return string
	 * @since 4.1
	 */
	function ur_get_user_registered_source( $user_id ) {
		$user_metas = get_user_meta( $user_id );
		if ( isset( $user_metas['user_registration_social_connect_bypass_current_password'] ) ) {
			$networks = array( 'facebook', 'linkedin', 'google', 'twitter' );

			foreach ( $networks as $network ) {

				if ( isset( $user_metas[ 'user_registration_social_connect_' . $network . '_username' ] ) ) {
					return ucfirst( $network );
				}
			}
		} elseif ( isset( $user_metas['ur_form_id'] ) ) {
			$form_post = get_post( $user_metas['ur_form_id'][0] );

			if ( ! empty( $form_post ) ) {
				return $form_post->post_title;
			} else {
				return '-';
			}
		}

		return '';
	}
}

if ( ! function_exists( 'ur_return_social_profile_pic' ) ) {

	/**
	 * Return social profile picture.
	 *
	 * @param string $url URL.
	 * @param int    $user_id User ID.
	 *
	 * @return mixed
	 */
	function ur_return_social_profile_pic( $url, $user_id ) {
		$source = ur_get_user_registered_source( $user_id );

		$user_meta = get_user_meta( $user_id, 'user_registration_social_connect_' . strtolower( $source ) . '_profile_pic', true );

		if ( ! empty( $user_meta ) && ur_check_url_is_image( $user_meta ) ) {
			return $user_meta;
		}

		return $url;
	}
}

add_filter( 'user_registration_profile_picture_url', 'ur_return_social_profile_pic', 10, 2 );

if ( ! function_exists( 'get_login_field_settings' ) ) {
	/**
	 * Get settings for login form
	 *
	 * @return array
	 */
	function get_login_field_settings() {
		/**
		 * Filter to add the login options settings.
		 *
		 * @param array Options to be enlisted.
		 */
		$settings = apply_filters(
			'user_registration_login_options_settings',
			array(
				'title'    => '',
				'sections' => array(
					'login_options_settings' => array(
						'title'    => __( 'General', 'user-registration' ),
						'type'     => 'card',
						'desc'     => '',
						'settings' => array(
							array(
								'title'     => __( 'Enable Remember Me', 'user-registration' ),
								'id'        => 'user_registration_login_options_remember_me',
								'type'      => 'toggle',
								'desc_tip'  => __( 'Keep users logged in on their browser.', 'user-registration' ),
								'css'       => '',
								'default'   => 'yes',
								'field-key' => 'remember-me',
							),
							array(
								'title'     => __( 'Enable Password Reset Link', 'user-registration' ),
								'id'        => 'user_registration_login_options_lost_password',
								'type'      => 'toggle',
								'desc_tip'  => __( 'Allow users to reset their password if they forget it.', 'user-registration' ),
								'css'       => '',
								'default'   => 'yes',
								'field-key' => 'lost-password',
							),
							array(
								'title'     => __( 'Lost Password Page', 'user-registration' ),
								'desc'      => __( 'Select the page where your password reset form is placed.', 'user-registration' ),
								'id'        => 'user_registration_lost_password_page_id',
								'type'      => 'single_select_page',
								'default'   => '',
								'class'     => 'ur-enhanced-select-nostd',
								'css'       => '',
								'desc_tip'  => true,
								'field-key' => 'lost-password',
							),
							array(
								'title'     => __( 'Registration URL', 'user-registration' ),
								'desc'      => __( 'Set the URL of the registration page users should be sent to.', 'user-registration' ),
								'id'        => 'user_registration_general_setting_registration_url_options',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => get_permalink( get_option( 'user_registration_registration_page_id' ) ),
								'field-key' => 'registration-setting',
							),
							array(
								'title'     => __( 'Registration URL Label', 'user-registration' ),
								'desc'      => __( 'Customize the label shown for your registration link.', 'user-registration' ),
								'id'        => 'user_registration_general_setting_registration_label',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => __( 'Not a member yet? Register now.', 'user-registration' ),
								'field-key' => 'registration-setting',
							),
							array(
								'title'     => __( 'Hide Label', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_hide_label_username_or_email',
								'type'      => 'toggle',
								'css'       => '',
								'default'   => false,
								'field-key' => 'username',
							),
							array(
								'title'     => __( 'Label', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_label_username_or_email',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => __( 'Username or Email', 'user-registration' ),
								'field-key' => 'username',
							),
							array(
								'title'     => __( 'Placeholder', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_placeholder_username_or_email',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => '',
								'field-key' => 'username',
							),
							array(
								'title'       => __( 'Invalid Username Message', 'user-registration' ),
								'desc'        => '',
								'id'          => 'user_registration_message_invalid_username',
								'type'        => 'text',
								'desc_tip'    => true,
								'css'         => '',
								'default'     => __( 'Invalid username or email.', 'user-registration' ),
								'placeholder' => 'Default message from WordPress',
								'field-key'   => 'username',
							),
							array(
								'title'     => __( 'Invalid Email Message', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_message_unknown_email',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => 'A user could not be found with this email address.',
								'field-key' => 'username',
							),
							array(
								'title'     => __( 'Required Message', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_message_username_required',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => esc_html__( 'Username is required.', 'user-registration' ),
								'field-key' => 'username',
							),
							array(
								'title'     => __( 'Hide Label', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_hide_label_password',
								'type'      => 'toggle',
								'css'       => '',
								'default'   => false,
								'field-key' => 'password',
							),
							array(
								'title'     => __( 'Label', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_label_remember_me',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => __( 'Remember Me', 'user-registration' ),
								'field-key' => 'remember-me',
							),
							array(
								'title'     => __( 'Button Text', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_label_login',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => __( 'Login', 'user-registration' ),
								'field-key' => 'login-button',
							),
							array(
								'title'     => __( 'Label', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_label_lost_your_password',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => __( 'Lost your password?', 'user-registration' ),
								'field-key' => 'lost-password',
							),
							array(
								'title'     => __( 'Label', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_label_password',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => __( 'Password', 'user-registration' ),
								'field-key' => 'password',
							),
							array(
								'title'     => __( 'Placeholder', 'user-registration' ),
								'desc'      => '',
								'id'        => 'user_registration_placeholder_password',
								'type'      => 'text',
								'desc_tip'  => true,
								'css'       => '',
								'default'   => '',
								'field-key' => 'password',
							),
							array(
								'title'       => __( 'Required Message', 'user-registration' ),
								'desc'        => '',
								'id'          => 'user_registration_message_empty_password',
								'type'        => 'text',
								'desc_tip'    => true,
								'css'         => '',
								'default'     => esc_html__( 'The password field is empty.', 'user-registration' ),
								'placeholder' => 'Default message from WordPress',
								'field-key'   => 'password',
							),
							array(
								'title'       => __( 'Invalid Password', 'user-registration' ),
								'desc'        => '',
								'id'          => 'user_registration_message_incorrect_password',
								'type'        => 'text',
								'desc_tip'    => true,
								'css'         => '',
								'default'     => esc_html__( 'The password you entered for the %label% %email% is incorrect.', 'user-registration' ), // phpcs:ignore
								'placeholder' => 'Default message from WordPress',
								'field-key'   => 'password',
							),
						),
					),
				),
			)
		);

		return $settings;
	}
}

if ( ! function_exists( 'get_login_form_settings' ) ) {
	/**
	 * Get settings for login form
	 *
	 * @return array
	 */
	function get_login_form_settings() {

		$ur_captchas         = ur_get_captcha_integrations();
		$ur_enabled_captchas = array(
			'' => __( 'Select Enabled Captcha', 'user-registration' ),
		);

		foreach ( $ur_captchas as $key => $value ) {
			if ( get_option( 'user_registration_captcha_setting_recaptcha_enable_' . $key, false ) ) {
				$ur_enabled_captchas[ $key ] = $value;
			}
		}
		/**
		 * Filter to add the login options settings.
		 *
		 * @param array Options to be enlisted.
		 */
		$settings = apply_filters(
			'user_registration_login_form_settings',
			array(
				'title'    => '',
				'sections' => array(
					'login_options_settings'          => array(
						'title'    => __( 'General', 'user-registration' ),
						'type'     => 'card',
						'desc'     => '',
						'settings' => array(
							array(
								'title'    => __( 'Form Style', 'user-registration' ),
								'desc'     => __( 'Choose how your login form looks.', 'user-registration' ),
								'id'       => 'user_registration_login_options_form_template',
								'type'     => 'select',
								'desc_tip' => true,
								'css'      => '',
								'class'    => 'ur-enhanced-select',
								'default'  => 'default',
								'options'  => array(
									'default'      => __( 'Default', 'user-registration' ),
									'bordered'     => __( 'Bordered', 'user-registration' ),
									'flat'         => __( 'Flat', 'user-registration' ),
									'rounded'      => __( 'Rounded', 'user-registration' ),
									'rounded_edge' => __( 'Rounded Edge', 'user-registration' ),
								),
							),
							array(
								'title'    => __( 'Login Methods', 'user-registration' ),
								'desc'     => __( 'Let users log in with email, username, or both.', 'user-registration' ),
								'id'       => 'user_registration_general_setting_login_options_with',
								'default'  => 'default',
								'type'     => 'select',
								'class'    => 'ur-enhanced-select',
								'css'      => '',
								'desc_tip' => true,
								'options'  => ur_login_option_with(),
							),
							array(
								'title'    => __( 'Enable Login Title', 'user-registration' ),
								'desc'     => '',
								'id'       => 'user_registration_login_title',
								'type'     => 'toggle',
								'desc_tip' => __( 'Check to enable login title in login form.', 'user-registration' ),
								'css'      => '',
								'default'  => 'no',
							),
							array(
								'title'    => __( 'Login Form Title', 'user-registration' ),
								'desc'     => __( 'This text will appear as the login form title', 'user-registration' ),
								'id'       => 'user_registration_general_setting_login_form_title',
								'type'     => 'text',
								'desc_tip' => true,
								'css'      => '',
								'default'  => __( 'Welcome', 'user-registration' ),
							),
							array(
								'title'    => __( 'Login Form Description', 'user-registration' ),
								'desc'     => __( 'This text will appear as the login form description', 'user-registration' ),
								'id'       => 'user_registration_general_setting_login_form_desc',
								'type'     => 'textarea',
								'desc_tip' => true,
								'css'      => '',
								'default'  => __( 'Please enter your details to access your account.', 'user-registration' ),
							),
						),
					),
					'login_options_settings_advanced' => array(
						'title'    => __( 'Advanced', 'user-registration' ),
						'type'     => 'card',
						'desc'     => '',
						'settings' => array(
							array(
								'title'    => __( 'Enable Ajax Login', 'user-registration' ),
								'desc'     => '',
								'id'       => 'ur_login_ajax_submission',
								'type'     => 'toggle',
								'desc_tip' => __( 'Check to enable Ajax login i.e login without page reload on submission.', 'user-registration' ),
								'css'      => '',
								'default'  => 'no',
							),
							// array(
							// 'title'    => __( 'Hide Field Labels', 'user-registration' ),
							// 'id'       => 'user_registration_login_options_hide_labels',
							// 'type'     => 'toggle',
							// 'desc_tip' => __( 'Hide input labels for a cleaner, minimal login form.', 'user-registration' ),
							// 'css'      => '',
							// 'default'  => 'no',
							// ),
							array(
								'title'    => __( 'Enable Captcha', 'user-registration' ),
								'id'       => 'user_registration_login_options_enable_recaptcha',
								'type'     => 'toggle',
								'desc_tip' => sprintf( __( 'Enable %1$s %2$s Captcha %3$s support', 'user-registration' ), '<a title="', 'Please make sure the site key and secret are not empty in setting page." href="' . admin_url() . 'admin.php?page=user-registration-settings&tab=captcha" rel="noreferrer noopener" target="_blank" style="color: #9ef01a;text-decoration:none;">', '</a>' ),
								//phpcs:ignore
								'css'      => '',
								'default'  => 'no',
							),
							array(
								'title'    => __( 'Select Captcha Type', 'user-registration' ),
								'desc'     => __( 'Choose which Captcha type to show on the login form.', 'user-registration' ),
								'id'       => 'user_registration_login_options_configured_captcha_type',
								'type'     => 'select',
								'desc_tip' => true,
								'css'      => '',
								'default'  => 'default',
								'options'  => $ur_enabled_captchas,
							),
							array(
								'title'      => __( 'Disable Default WordPress Login Screen', 'user-registration' ),
								'desc'       => '',
								'id'         => 'user_registration_login_options_prevent_core_login',
								'type'       => 'toggle',
								'desc_tip'   => __( 'Default WordPress login page wp-login.php will  be disabled.', 'user-registration' ),
								'css'        => '',
								'default'    => 'no',
								'desc_field' => __( 'Please make sure that you have created a login or my-account page which has a login form before enabling this option. Learn how to create a login form <a href="https://docs.wpuserregistration.com/docs/how-to-show-login-form/" rel="noreferrer noopener" target="_blank">here</a>.', 'user-registration' ),
							),
							array(
								'title'    => __( 'Redirect Default WordPress Login URL', 'user-registration' ),
								'desc'     => __( 'Select the custom page users are redirected to instead of wp-login.php.', 'user-registration' ),
								'id'       => 'user_registration_login_options_login_redirect_url',
								'type'     => 'single_select_page',
								'desc_tip' => true,
								'css'      => '',
								'class'    => 'ur-redirect-to-login-page ur-enhanced-select-nostd',
								'default'  => get_option( 'user_registration_myaccount_page_id', '' ),
							),
							array(
								'title'       => __( 'Account Pending Approval Message', 'user-registration' ),
								'desc'        => __( 'Message shown when the account is waiting for approval.', 'user-registration' ),
								'id'          => 'user_registration_message_pending_approval',
								'type'        => 'text',
								'desc_tip'    => true,
								'css'         => '',
								'default'     => '',
								'placeholder' => 'Default message from WordPress',
							),
							array(
								'title'       => __( 'Account Denied Message', 'user-registration' ),
								'desc'        => __( 'Message shown if the user’s account has been denied.', 'user-registration' ),
								'id'          => 'user_registration_message_denied_account',
								'type'        => 'text',
								'desc_tip'    => true,
								'css'         => '',
								'default'     => '',
								'placeholder' => 'Default message from WordPress',
							),
							array(
								'title'    => __( 'Enable Custom Redirect', 'user-registration' ),
								'desc'     => '',
								'id'       => 'user_registration_login_options_enable_custom_redirect',
								'type'     => 'toggle',
								'desc_tip' => __( 'Custom redirection settings after login or logout.', 'user-registration' ),
								'css'      => '',
								'default'  => 'no',
							),
							array(
								'type'              => 'select',
								'title'             => __( 'Redirect After Login', 'user-registration' ),
								'desc_tip'          => __( 'Select the option in which users are redirected to after login', 'user-registration' ),
								'required'          => false,
								'id'                => 'user_registration_login_options_redirect_after_login',
								'class'             => 'ur-enhanced-select',
								'input_class'       => array(),
								/**
								 * Filters the redirection options after user login.
								 *
								 * @param array $redirection_options An associative array where keys represent
								 *                                   the option values, and values represent the labels
								 *                                   for the redirection options.
								 */
								'options'           => apply_filters(
									'user_registration_redirect_after_registration_options',
									array(
										'no-redirection' => __( 'No Redirection', 'user-registration' ),
										'internal-page'  => __( 'Internal Page', 'user-registration' ),
										'external-url'   => __( 'External URL', 'user-registration' ),
										'previous-page'  => __( 'Previous Page', 'user-registration' ),
									)
								),
								'default'           => get_option( 'user_registration_login_options_redirect_after_login', 'no-redirection' ),
								'tip'               => __( 'Decide where users go after completing login.', 'user-registration' ),
								'default_value'     => 'no-redirection',
								'custom_attributes' => array(),
								'product'           => 'user-registration/user-registration.php',
							),
							array(
								'title'             => __( 'Redirect Page', 'user-registration' ),
								'id'                => 'user_registration_login_options_after_login_redirect_page',
								'type'              => 'single_select_page',
								'class'             => 'ur-enhanced-select-nostd',
								'css'               => '',
								'default'           => '',
								'display_condition' => array(
									'field'    => 'user_registration_login_options_redirect_after_login',
									'operator' => 'equals',
									'value'    => 'internal-page',
								),
							),
							array(
								'title'   => __( 'External URL', 'user-registration' ),
								'id'      => 'user_registration_login_options_after_login_redirect_external_url',
								'type'    => 'text',
								'css'     => '',
								'default' => '',
							),
							array(
								'type'              => 'select',
								'title'             => __( 'Redirect After Logout', 'user-registration' ),
								'desc_tip'          => __( 'Select the option in which users are redirected to after logout', 'user-registration' ),
								'required'          => false,
								'id'                => 'user_registration_login_options_redirect_after_logout',
								'class'             => 'ur-enhanced-select',
								'input_class'       => array(),
								/**
								 * Filters the redirection options after user logout.
								 *
								 * @param array $redirection_options An associative array where keys represent
								 *                                   the option values, and values represent the labels
								 *                                   for the redirection options.
								 */
								'options'           => apply_filters(
									'user_registration_redirect_after_registration_options',
									array(
										'no-redirection' => __( 'No Redirection', 'user-registration' ),
										'internal-page'  => __( 'Internal Page', 'user-registration' ),
										'external-url'   => __( 'External URL', 'user-registration' ),
										'previous-page'  => __( 'Previous Page', 'user-registration' ),
									)
								),
								'default'           => get_option( 'user_registration_login_options_redirect_after_logout', 'no-redirection' ),
								'tip'               => __( 'Decide where users go after completing logout.', 'user-registration' ),
								'default_value'     => 'no-redirection',
								'custom_attributes' => array(),
								'product'           => 'user-registration/user-registration.php',
							),
							array(
								'title'             => __( 'Redirect Page', 'user-registration' ),
								'id'                => 'user_registration_login_options_after_logout_redirect_page',
								'type'              => 'single_select_page',
								'class'             => 'ur-enhanced-select-nostd',
								'css'               => '',
								'default'           => '',
								'display_condition' => array(
									'field'    => 'user_registration_login_options_redirect_after_login',
									'operator' => 'equals',
									'value'    => 'internal-page',
								),
							),
							array(
								'title'   => __( 'External URL', 'user-registration' ),
								'id'      => 'user_registration_login_options_after_logout_redirect_external_url',
								'type'    => 'text',
								'css'     => '',
								'default' => '',
							),

						),
					),
				),
			)
		);

		return $settings;
	}
}

if ( ! function_exists( 'ur_check_and_sync_login_page_redirect_options' ) ) {
	/**
	 * Check and sync login page and redirect URL options to ensure consistency and avoid conflicts.
	 *
	 * @return array{ hide: bool, show_notice: bool, login_page_id: int|string, login_redirect_url: int|string }
	 */
	function ur_check_and_sync_login_page_redirect_options( $run_migration = true ) {
		$login_page_id  = get_option( 'user_registration_login_page_id', '' );
		$login_redirect = get_option( 'user_registration_login_options_login_redirect_url', '' );
		$login_page_id  = is_numeric( $login_page_id ) ? absint( $login_page_id ) : '';
		$login_redirect = is_numeric( $login_redirect ) ? absint( $login_redirect ) : $login_redirect;

		if ( empty( $login_page_id ) && ! empty( $login_redirect ) ) {
			if ( $run_migration ) {
				update_option( 'user_registration_login_page_id', $login_redirect );
			}
			$login_page_id = $login_redirect;
			return array(
				'hide'               => true,
				'show_notice'        => false,
				'login_page_id'      => $login_page_id,
				'login_redirect_url' => $login_redirect,
			);
		}

		if ( empty( $login_redirect ) && ! empty( $login_page_id ) ) {
			if ( $run_migration ) {
				update_option( 'user_registration_login_options_login_redirect_url', $login_page_id );
			}
			$login_redirect = $login_page_id;
			return array(
				'hide'               => true,
				'show_notice'        => false,
				'login_page_id'      => $login_page_id,
				'login_redirect_url' => $login_redirect,
			);
		}

		$both_same  = ( (string) $login_page_id === (string) $login_redirect );
		$both_empty = ( empty( $login_page_id ) && empty( $login_redirect ) );
		if ( $both_same || $both_empty ) {
			return array(
				'hide'               => true,
				'show_notice'        => false,
				'login_page_id'      => $login_page_id,
				'login_redirect_url' => $login_redirect,
			);
		}

		return array(
			'hide'               => false,
			'show_notice'        => true,
			'login_page_id'      => $login_page_id,
			'login_redirect_url' => $login_redirect,
		);
	}
}

add_action( 'user_registration_init', 'ur_login_page_redirect_migration_run_once' );

if ( ! function_exists( 'ur_login_page_redirect_migration_run_once' ) ) {

	/**
	 * Page redirect migration script.
	 *
	 * @return void
	 */
	function ur_login_page_redirect_migration_run_once() {
		if ( get_option( 'ur_login_page_redirect_sync_migrated', false ) ) {
			return;
		}
		ur_check_and_sync_login_page_redirect_options( true );
		update_option( 'ur_login_page_redirect_sync_migrated', true );
	}
}

if ( ! function_exists( 'ur_filter_login_form_settings_redirect_url_field' ) ) {
	/**
	 * Hide the login redirect URL field in login form settings based on the state of login page and redirect URL options, and to show a notice if there is a conflict that needs to be resolved by the user.
	 *
	 * @param array $settings Login form settings array.
	 * @return array
	 */
	function ur_filter_login_form_settings_redirect_url_field( $settings ) {
		$state = ur_check_and_sync_login_page_redirect_options( true );

		if ( ! isset( $settings['sections'] ) || ! is_array( $settings['sections'] ) ) {
			return $settings;
		}

		foreach ( $settings['sections'] as $section_key => $section ) {
			if ( empty( $section['settings'] ) || ! is_array( $section['settings'] ) ) {
				continue;
			}
			foreach ( $section['settings'] as $idx => $setting ) {
				if ( isset( $setting['id'] ) && 'user_registration_login_options_login_redirect_url' === $setting['id'] ) {
					if ( $state['hide'] ) {
						$settings['sections'][ $section_key ]['settings'][ $idx ]['display'] = 'none';
					} elseif ( $state['show_notice'] ) {
						$settings_page_url = admin_url( 'admin.php?page=user-registration-settings&tab=general&section=pages' );
						$notice_link       = '<a href="' . esc_url( $settings_page_url ) . '">' . esc_html__( 'here', 'user-registration' ) . '</a>';
						$notice            = sprintf(
						/* translators: %s: link to Login Page setting */
							__( 'It is recommended to use the same page as the global login page that is set %s. This ensures no conflicts. The above page selector may be merged and removed in further updates.', 'user-registration' ),
							$notice_link
						);
						$settings['sections'][ $section_key ]['settings'][ $idx ]['ur_notice_below'] = $notice;
					}
					return $settings;
				}
			}
		}

		return $settings;
	}
}

add_filter( 'user_registration_login_form_settings', 'ur_filter_login_form_settings_redirect_url_field', 20 );

if ( ! function_exists( 'render_login_option_settings' ) ) {

	/**
	 * Render login option settings.
	 */
	function render_login_option_settings( $section ) {
		$settings                = '';
		$section_settings        = $section['settings'];
		$repositionable_settings = array_filter(
			$section_settings,
			function ( $setting ) {
				return isset( $setting['item_position'] );
			}
		);
		$section_settings        = array_filter(
			$section_settings,
			function ( $setting ) {
				return ! isset( $setting['item_position'] );
			}
		);
		foreach ( $repositionable_settings as $setting ) {
			[ $position, $setting_id ] = $setting['item_position'];
			$offset                    = array_search( $setting_id, array_column( $section_settings, 'id' ) );
			if ( 'before' === $position ) {
				array_splice( $section_settings, $offset, 0, array( $setting ) );
			}
			if ( 'after' === $position ) {
				array_splice( $section_settings, $offset + 1, 0, array( $setting ) );
			}
		}
		foreach ( $section_settings as $key => $value ) {

			if ( ! isset( $value['type'] ) ) {
				continue;
			}

			if ( isset( $value['display'] ) && 'none' === $value['display'] ) {
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
			if ( ! isset( $value['field-key'] ) ) {
				$value['field-key'] = '';
			}
			// Capitalize Setting Label.
			$value['title'] = UR_Admin_Settings::capitalize_title( $value['title'] );

			// Custom attribute handling.
			$custom_attributes = array();

			if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
				foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
					$custom_attributes[] = esc_attr( $attribute ) . '=' . esc_attr( $attribute_value ) . '';
				}
			}

			$field_description = UR_Admin_Settings::get_field_description( $value );
			extract( $field_description ); // phpcs:ignore

			// Switch based on type.
			switch ( $value['type'] ) {
				case 'section':
					$settings .= '<div class="user-registration-login-form-global-settings form-row ur-form-settings-section">';
					$settings .= '<div class="ur-form-settings-section--field">';
					$settings .= '<h4>' . esc_html( $value['title'] ) . '</h4>';
					$settings .= '</div>';
					$settings .= '</div>';
					break;
				// Standard text inputs and subtypes like 'number'.
				case 'text':
				case 'email':
				case 'number':
				case 'password':
				case 'date':
					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );
					$settings    .= '<div class="user-registration-login-form-global-settings form-row" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings    .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings    .= '<div class="user-registration-login-form-global-settings--field">';
					$settings    .= '<input
							name="' . esc_attr( $value['id'] ) . '"
							id="' . esc_attr( $value['id'] ) . '"
							type="' . esc_attr( $value['type'] ) . '"
							style="' . esc_attr( $value['css'] ) . '"
							value="' . esc_attr( $option_value ) . '"
							class="' . esc_attr( $value['class'] ) . '"
							placeholder="' . esc_attr( $value['placeholder'] ) . '"
							' . esc_attr( implode( ' ', $custom_attributes ) ) . ' ' . wp_kses_post( $description ) . '/>';
					$settings    .= '</div>';
					$settings    .= '</div>';
					break;
				case 'nonce':
					$settings .= '<div class="user-registration-login-form-global-settings form-row" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings .= '<div class="user-registration-login-form-global-settings--field">';
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
					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );
					$settings    .= '<div class="user-registration-login-form-global-settings form-row" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings    .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings    .= '<div class="user-registration-login-form-global-settings--field">';
					$settings    .= '<input
							name="' . esc_attr( $value['id'] ) . '"
							id="' . esc_attr( $value['id'] ) . '"
							type="text"
							dir="ltr"
							style="' . esc_attr( $value['css'] ) . '"
							value="' . esc_attr( $option_value ) . '"
							class="' . esc_attr( $value['class'] ) . 'colorpick"
							placeholder="' . esc_attr( $value['placeholder'] ) . '"
							' . esc_attr( implode( ' ', $custom_attributes ) ) . '/>&lrm;' . wp_kses_post( $description );
					$settings    .= '<div id="colorPickerDiv_' . esc_attr( $value['id'] ) . '" class="colorpickdiv" style="z-index: 100;background:#eee;border:1px solid #ccc;position:absolute;display:none;"></div></div>';
					$settings    .= '</div>';
					break;

				// Textarea.
				case 'textarea':
					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );

					$settings .= '<div class="user-registration-login-form-global-settings form-row" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings .= '<div class="user-registration-login-form-global-settings--field">';
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
					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );

					$settings .= '<div class="user-registration-login-form-global-settings form-row" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings .= '<div class="user-registration-login-form-global-settings--field">';
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

				// Radio inputs.
				case 'radio':
					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );
					$settings    .= '<div class="user-registration-login-form-global-settings form-row" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings    .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings    .= '<div class="user-registration-login-form-global-settings--field">';
					$settings    .= '<fieldset>';
					$settings    .= wp_kses_post( $description );
					$settings    .= '<ul>';

					foreach ( $value['options'] as $key => $val ) {
						$settings .= '<li>';
						$settings .= '<label>';
						$settings .= '<input
									name="' . esc_attr( $value['id'] ) . '"
									value="' . esc_attr( $key ) . '"
									type="radio"
									style="' . esc_attr( $value['css'] ) . '"
									class="' . esc_attr( $value['class'] ) . '"
									' . esc_attr( implode( ' ', $custom_attributes ) ) . '
									' . esc_attr( checked( $key, $option_value, false ) ) . '
									/>' . wp_kses_post( $val ) . '</label>';
						$settings .= '</li>';
					}

					$settings .= '</ul>';
					$settings .= '</fieldset>';
					$settings .= '</div>';
					$settings .= '</div>';
					break;

				// Checkbox input.
				case 'checkbox':
					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );

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
					$settings .= '<div class="user-registration-login-form-global-settings ' . esc_attr( implode( ' ', $visbility_class ) ) . ' ' . esc_attr( $value['row_class'] ) . '" data-field-key="' . esc_attr( $value['field-key'] ) . '">';

					if ( ! isset( $value['checkboxgroup'] ) || 'start' === $value['checkboxgroup'] ) {
						$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
						$settings .= '<div class="user-registration-login-form-global-settings--field">';
						$settings .= '<fieldset>';
					} else {
						$settings .= '<div class="user-registration-login-form-global-settings--field">';
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
						'selected'         => absint( UR_Admin_Settings::get_option( $value['id'], $value['default'] ) ),
					);

					if ( isset( $value['args'] ) ) {
						$args = wp_parse_args( $value['args'], $args );
					}

					$settings .= '<div class="user-registration-login-form-global-settings single_select_page form-row" ' . ( ( isset( $value['display'] ) && 'none' === $value['display'] ) ? 'style="display:none"' : '' ) . ' data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings .= '<div class="user-registration-login-form-global-settings--field">';
					$settings .= str_replace( ' id=', " data-placeholder='" . esc_attr__( 'Select a page&hellip;', 'user-registration' ) . "' style='" . esc_attr( $value['css'] ) . "' class='" . esc_attr( $value['class'] ) . "' id=", wp_dropdown_pages( $args ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$settings .= wp_kses_post( $description );
					$settings .= '</div>';
					$settings .= '</div>';
					if ( ! empty( $value['ur_notice_below'] ) ) {
						$settings .= '<p class=" ur-p-tag ur-login-redirect-notice" style="margin-bottom: 24px; padding: 10px 12px 10px 16px; font-weight: 400; color: #383838; border-radius: 4px; font-size: 14px; line-height: 22px; font-style: normal; border-left: 4px solid #475bb2; background: #f1f2f9;">' . wp_kses_post( $value['ur_notice_below'] ) . '</p>';
					}
					break;

				case 'tinymce':
					$editor_settings = array(
						'name'                             => esc_attr( $value['id'] ),
						'id'                               => esc_attr( $value['id'] ),
						'style'                            => esc_attr( $value['css'] ),
						'default'                          => esc_attr( $value['default'] ),
						'class'                            => esc_attr( $value['class'] ),
						'quicktags'                        => array( 'buttons' => 'em,strong,link' ),
						'show-ur-registration-form-button' => false,
						// Hide Add Registration button for login form settings
						'tinymce'                          => array(
							'theme_advanced_buttons1' => 'bold,italic,strikethrough,separator,bullist,numlist,separator,blockquote,separator,justifyleft,justifycenter,justifyright,separator,link,unlink,separator,undo,redo,separator',
							'theme_advanced_buttons2' => '',
						),
						'editor_css'                       => '<style>#wp-excerpt-editor-container .wp-editor-area{height:175px; width:100%;}</style>',
					);

					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );

					// Unwrap email content if it contains wrapper HTML (for editor display).
					if ( function_exists( 'ur_unwrap_email_body_content' ) ) {
						$option_value = ur_unwrap_email_body_content( $option_value );
					}

					$settings .= '<div class="user-registration-login-form-global-settings form-row" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings .= '<div class="user-registration-login-form-global-settings--field">';
					$settings .= wp_kses_post( $description );

					// Output buffer for tinymce editor.
					ob_start();
					wp_editor( $option_value, $value['id'], $editor_settings );
					$settings .= ob_get_clean();

					$settings .= '</div>';
					$settings .= '</div>';

					break;

				case 'link':
					$settings .= '<div class="user-registration-login-form-global-settings form-row" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_attr( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings .= '<div class="user-registration-login-form-global-settings--field">';

					if ( isset( $value['buttons'] ) && is_array( $value['buttons'] ) ) {
						foreach ( $value['buttons'] as $button ) {
							$settings .= '<a
										href="' . esc_url( $button['href'] ) . '"
										class="button ' . esc_attr( $button['class'] ) . '" style="' . esc_attr( $value['css'] ) . '">' . esc_html( $button['title'] ) . '</a>';
						}
					}

					$settings .= ( isset( $value['desc'] ) && isset( $value['desc_tip'] ) && true !== $value['desc_tip'] ) ? '<p class="description" >' . wp_kses_post( $value['desc'] ) . '</p>' : '';
					$settings .= '</div>';
					$settings .= '</div>';
					break;
				// Image upload.
				case 'image':
					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );

					$settings .= '<div class="user-registration-login-form-global-settings image-upload">';

					$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_attr( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings .= '<div class="user-registration-login-form-global-settings--field">';
					$settings .= '<img src="' . esc_attr( $option_value ) . '" alt="' . esc_attr__( 'Header Logo', 'user-registration' ) . '" class="ur-image-uploader" height="auto" width="20%">';
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
					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );

					$settings .= '<div class="user-registration-login-form-global-settings radio-image">';
					$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_attr( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings .= '<div class="user-registration-login-form-global-settings--field">';
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
					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );

					$settings .= '<div class="user-registration-login-form-global-settings form-row ur-toggle-setting" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings .= '<div class="user-registration-login-form-toggle-option">';
					$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
					$settings .= '<div class="user-registration-login-form-global-settings--field">';
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
					$settings .= '</div>';
					$settings .= '</div>';
					$settings .= wp_kses_post( $description );
					$settings .= wp_kses_post( $desc_field );
					$settings .= '</div>';
					break;
				case 'radio-group':
					$option_value = UR_Admin_Settings::get_option( $value['id'], $value['default'] );
					$options      = isset( $value['options'] ) ? $value['options'] : array(); // $args['choices'] for backward compatibility. Modified since 1.5.7.

					if ( ! empty( $options ) ) {
						$settings .= '<div class="user-registration-login-form-global-settings form-row" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
						$settings .= '<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . ' ' . wp_kses_post( $tooltip_html ) . '</label>';
						$settings .= '<div class="user-registration-login-form-global-settings--field">';

						$settings .= '<ul class="ur-radio-group-list">';
						foreach ( $options as $option_index => $option_text ) {
							$class     = str_replace( ' ', '-', strtolower( $option_text ) );
							$settings .= '<li class="ur-radio-group-list--item  ' . $class . ( trim( $option_index ) === $option_value ? ' active' : '' ) . '">';

							$checked = '';

							if ( '' !== $option_value ) {
								$checked = checked( $option_value, trim( $option_index ), false );
							}

							$settings .= '<label for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_text ) . '" class="radio">';

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

							$settings .= '<input type="radio" name="' . esc_attr( $value['id'] ) . '" id="' . esc_attr( $value['id'] ) . '"	style="' . esc_attr( $value['css'] ) . '" class="' . esc_attr( $value['class'] ) . '" value="' . esc_attr( trim( $option_index ) ) . '" ' . implode( ' ', $custom_attributes ) . ' / ' . $checked . ' /> ';
							$settings .= '</label>';

							$settings .= '</li>';
						}
						$settings .= '</ul>';
						$settings .= '</div>';
						$settings .= '</div>';

					}
					break;
				// Default: run an action.
				case 'html':
					$settings .= '<div class="user-registration-login-form-global-settings form-row" data-field-key="' . esc_attr( $value['field-key'] ) . '">';
					$settings .= '<label for=' . esc_attr( $value['id'] ) . '>' . esc_html( $value['title'] ) . '</label>';
					$settings .= '<div class="user-registration-login-form-global-settings--field">';
					$settings .= $value['html_content'];
					$settings .= '</div>';
					$settings .= '</div>';
					break;
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
		echo $settings; // phpcs:ignore
	}
}

add_filter( 'user_registration_find_my_account_in_page', 'ur_find_my_account_in_custom_template', 10, 2 );

if ( ! function_exists( 'ur_find_my_account_in_custom_template' ) ) {
	/**
	 * Return true if found ur my account or login.
	 *
	 * @param string $value Value.
	 * @param int    $page_id Page ID.
	 *
	 * @return mixed
	 */
	function ur_find_my_account_in_custom_template( $value, $page_id ) {

		if ( $value ) {
			return $value;
		}

		$template_path = get_page_template();

		if ( empty( $template_path ) ) {
			return $value;
		}

		$content = ur_file_get_contents( $template_path );

		if ( empty( $content ) || ! is_string( $content ) ) {
			return $value;
		}

		if ( strpos( $content, '[user_registration_my_account' ) !== false ) {
			return true;
		}
		if ( strpos( $content, '[user_registration_login' ) !== false ) {
			return true;
		}
		if ( strpos( $content, '[woocommerce_my_account' ) !== false ) {
			return true;
		}
		if ( strpos( $content, '<!-- wp:user-registration/myaccount' ) !== false ) {
			return true;
		}
		if ( strpos( $content, '<!-- wp:user-registration/login' ) !== false ) {
			return true;
		}

		return $value;
	}
}

add_filter( 'user_registration_get_endpoint_url', 'ur_filter_get_endpoint_url', 10, 4 );

if ( ! function_exists( 'ur_filter_get_endpoint_url' ) ) {
	/**
	 * Filter the endpoint URL for WPML compatibility.
	 *
	 * This function modifies the endpoint URL when WPML is active to ensure proper translation
	 * and localization of URLs. It removes the filter temporarily to avoid infinite loops,
	 * translates the endpoint, converts the URL using WPML's convert_url method, and then
	 * re-adds the filter.
	 *
	 * @param string $url The endpoint URL.
	 * @param string $endpoint The endpoint slug.
	 * @param mixed  $value The value to add to the URL.
	 * @param string $permalink The permalink URL.
	 *
	 * @return string Modified URL if WPML is active, original urk if WPML is not active.
	 */
	function ur_filter_get_endpoint_url( $url, $endpoint, $value, $permalink ) {
		// Return early WPML is not active.
		if ( ! class_exists( 'SitePress' ) ) {
			return $url;
		}
		remove_filter( 'user_registration_get_endpoint_url', 'ur_filter_get_endpoint_url', 10 );

		$translated_endpoint = ur_get_endpoint_translation( $endpoint );
		$converted_permalink = apply_filters( 'wpml_permalink', $permalink );
		$url                 = ur_get_endpoint_url( $translated_endpoint, $value, $converted_permalink );
		add_filter( 'user_registration_get_endpoint_url', 'ur_filter_get_endpoint_url', 10, 4 );

		return $url;
	}
}

if ( ! function_exists( 'ur_get_endpoint_translation' ) ) {
	/**
	 * Get the translated endpoint.
	 *
	 * @param string $endpoint Endpoint slug.
	 *
	 * @return string
	 */
	function ur_get_endpoint_translation( $endpoint, $language = null ) {

		return apply_filters( 'wpml_get_endpoint_translation', $endpoint, $endpoint, $language );
	}
}

add_filter( 'user_registration_get_endpoint_url', 'ur_filter_get_endpoint_url', 10, 4 );

if ( ! function_exists( 'ur_register_endpoints_translations' ) ) {

	/**
	 * Register endpoints translations.
	 */
	function ur_register_endpoints_translations() {
		/**
		 * Register the endpoint translations
		 */
		if ( is_admin() || ! defined( 'ICL_SITEPRESS_VERSION' ) || ICL_PLUGIN_INACTIVE ) {
			return false;
		}

		$ur_vars = UR()->query->query_vars;

		if ( ! empty( $ur_vars ) ) {
			$query_vars = array(

				// My account actions.
				'edit-profile'    => get_endpoint_translation( 'edit-profile', $ur_vars['edit-profile'], $language ), // phpcs:ignore
				'change-password' => get_endpoint_translation( 'change-password', $ur_vars['change-password'], $language ), // phpcs:ignore
				'lost-password'   => get_endpoint_translation( 'lost-password', $ur_vars['lost-password'], $language ), // phpcs:ignore
				'user-logout'     => get_endpoint_translation( 'user-logout', $ur_vars['user-logout'], $language ), // phpcs:ignore
			);
			$query_vars = apply_filters( 'wcml_register_endpoints_query_vars', $query_vars, $ur_vars, UR()->query );

			$query_vars             = array_merge( $ur_vars, $query_vars );
			UR()->query->query_vars = $query_vars;
		}

		return UR()->query->query_vars;
	}
}

if ( ! function_exists( 'get_endpoint_translation' ) ) {
	/**
	 * Get the translated endpoint.
	 *
	 * @param string $endpoint Endpoint Slug.
	 *
	 * @return string
	 */
	function get_endpoint_translation( $endpoint, $value, $language = null ) {

		if ( function_exists( 'icl_t' ) ) {
			$trnsl = apply_filters( 'wpml_translate_single_string', $endpoint, 'UserRegistration Endpoints', $key, $language ); // phpcs:ignore

			if ( ! empty( $trnsl ) ) {
				return $trnsl;
			} else {
				return $endpoint;
			}
		} else {
			return $endpoint;
		}
	}
}

add_filter( 'user_registration_check_user_order_status', 'get_user_order_status', 10, 1 );

if ( ! function_exists( 'get_user_order_status' ) ) {

	/**
	 * Get user order status.
	 */
	function get_user_order_status( $user_id ) {
		$member_order_repository = new MembersOrderRepository();
		$member_order            = $member_order_repository->get_member_orders( $user_id );
		$status                  = '';
		if ( ! empty( $member_order ) && isset( $member_order['status'] ) ) {
			$status = $member_order['status'];
		}

		return $status;
	}
}

if ( ! function_exists( 'ur_get_sms_verification_default_message_content' ) ) {
	/**
	 * Get sms verification message content .
	 *
	 * @return array
	 * @since 4.2.1
	 */
	function ur_get_sms_verification_default_message_content() {
		$message = sprintf( __( 'Hi {{username}}, <br> Your One  Time Password (OTP) is : {{sms_otp}} <br> Enter this code to login to your account. <br> Note: This code expires in {{sms_otp_validity}} minutes. <br> Thank You!', 'user-registration' ) );

		return $message;
	}
}

if ( ! function_exists( 'ur_setting_keys' ) ) {
	/**
	 * Returns an array of default settings for User Registration and its addons.
	 *
	 * This function provides default settings for different plugins related to
	 * user registration, including general settings, login options, file uploads,
	 * PDF submissions, social login, and two-factor authentication.
	 *
	 * @return array Default settings for various User Registration addons.
	 */
	function ur_setting_keys() {
		return array(
			'user-registration/user-registration.php'     => array(
				array( 'user_registration_general_setting_disabled_user_roles', '["subscriber"]' ),
				array( 'user_registration_login_option_hide_show_password', false ),
				array( 'user_registration_myaccount_page_id', '' ),
				array( 'user_registration_my_account_layout', 'vertical' ),
				array( 'user_registration_ajax_form_submission_on_edit_profile', false ),
				array( 'user_registration_disable_profile_picture', false ),
				array(
					'user_registration_disable_logout_confirmation',
					apply_filters( 'user_registration_disable_logout_confirmation_status', true ),
				),
				array( 'user_registration_login_options_form_template', 'default' ),
				array( 'user_registration_general_setting_login_options_with', 'default' ),
				array( 'user_registration_login_title', false ),
				array( 'ur_login_ajax_submission', false ),
				array( 'user_registration_login_options_remember_me', true ),
				array( 'user_registration_login_options_lost_password', true ),
				array( 'user_registration_login_options_hide_labels', false ),
				array( 'user_registration_login_options_enable_recaptcha', false ),
				array( 'user_registration_general_setting_registration_url_options', '' ),
				array( 'user_registration_login_options_prevent_core_login', false ),
				array( 'user_registration_login_options_login_redirect_url', '' ),
				array( 'user_registration_captcha_setting_recaptcha_version', 'v2' ),
				array( 'user_registration_login_options_configured_captcha_type', 'v2' ),
				array( 'user_registration_general_setting_uninstall_option', false ),
				array( 'user_registration_allow_usage_tracking', false ),
				array( 'user_registration_hide_label_password', false ),
				array( 'user_registration_hide_label_username_or_email', false ),
			),
			'user-registration-pro/user-registration.php' => array(
				array( 'user_registration_pro_general_setting_delete_account', 'disable' ),
				array( 'user_registration_pro_general_setting_login_form', false ),
				array( 'user_registration_pro_general_setting_prevent_active_login', false ),
				array( 'user_registration_pro_general_setting_limited_login', '5' ),
				array( 'user_registration_pro_general_setting_redirect_back_to_previous_page', false ),
				array( 'user_registration_pro_general_post_submission_settings', '' ),
				array( 'user_registration_pro_general_setting_post_submission', 'disable' ),
				array( 'user_registration_pro_role_based_redirection', false ),
				array( 'user_registration_payment_currency', 'USD' ),
				array( 'user_registration_content_restriction_enable', true ),
				array( 'user_registration_content_restriction_allow_to_roles', '["administrator"]' ),
			),
			'user-registration-file-upload/user-registration-file-upload.php' => array(
				array( 'user_registration_file_upload_setting_valid_file_type', '["pdf"]' ),
				array( 'user_registration_file_upload_setting_max_file_size', '1024' ),
			),
			'user-registration-pdf-submission/user-registration-pdf-submission.php' => array(
				array( 'user_registration_pdf_template', 'default' ),
				array( 'user_registration_pdf_logo_image', '' ),
				array( 'user_registration_pdf_setting_header', '' ),
				array( 'user_registration_pdf_custom_header_text', '' ),
				array( 'user_registration_pdf_paper_size', '' ),
				array( 'user_registration_pdf_orientation', 'portrait' ),
				array( 'user_registration_pdf_font', '' ),
				array( 'user_registration_pdf_font_size', '12' ),
				array( 'user_registration_pdf_font_color', '#000000' ),
				array( 'user_registration_pdf_background_color', '#ffffff' ),
				array( 'user_registration_pdf_header_font_color', '#000000' ),
				array( 'user_registration_pdf_header_background_color', '#ffffff' ),
				array( 'user_registration_pdf_multiple_column', false ),
				array( 'user_registration_pdf_rtl', false ),
				array( 'user_registration_pdf_print_user_default_fields', false ),
				array( 'user_registration_pdf_hide_empty_fields', false ),
			),
			'user-registration-social-connect/user-registration-social-connect.php' => array(
				array( 'user_registration_social_setting_enable_facebook_connect', '' ),
				array( 'user_registration_social_setting_enable_twitter_connect', '' ),
				array( 'user_registration_social_setting_enable_google_connect', '' ),
				array( 'user_registration_social_setting_enable_linkedin_connect', '' ),
				array( 'user_registration_social_setting_enable_social_registration', false ),
				array( 'user_registration_social_setting_display_social_buttons_in_registration', false ),
				array( 'user_registration_social_setting_default_user_role', 'subscriber' ),
				array( 'user_registration_social_login_position', 'bottom' ),
				array( 'user_registration_social_login_template', 'ursc_theme_4' ),
			),
			'user-registration-two-factor-authentication/user-registration-two-factor-authentication.php' => array(
				array( 'user_registration_tfa_enable_disable', false ),
				array( 'user_registration_tfa_roles', '["subscriber"]' ),
				array( 'user_registration_tfa_otp_length', '6' ),
				array( 'user_registration_tfa_otp_expiry_time', '10' ),
				array( 'user_registration_tfa_otp_resend_limit', '3' ),
				array( 'user_registration_tfa_incorrect_otp_limit', '5' ),
				array( 'user_registration_tfa_login_hold_period', '60' ),
			),
		);
	}
}
/**
 * Trigger logging cleanup using the logging class.
 *
 * @since x.x.x
 */
function ur_cleanup_logs() {
	$logger = ur_get_logger();

	if ( is_callable( array( $logger, 'clear_expired_logs' ) ) ) {
		$logger->clear_expired_logs();
	}
}

add_action( 'user_registration_cleanup_logs', 'ur_cleanup_logs' );

if ( ! function_exists( 'ur_sanitize_value_by_type' ) ) {
	/**
	 * Sanitize value by option type.
	 *
	 * @param mixed $option Option.
	 * @param mixed $raw_value Raw Value.
	 *
	 * @return array
	 * @since 4.2.1
	 */
	function ur_sanitize_value_by_type( $option, $raw_value ) {

		// Format the value based on option type.
		switch ( $option['type'] ) {

			case 'checkbox':
			case 'toggle':
				$value = ur_string_to_bool( $raw_value );
				break;
			case 'textarea':
				$value = wp_kses_post( trim( $raw_value ) );
				break;
			case 'multiselect':
				$value = array_filter( array_map( 'ur_clean', (array) $raw_value ) );
				break;
			case 'select':
				$allowed_values = empty( $option['options'] ) ? array() : array_keys( $option['options'] );
				if ( empty( $option['default'] ) && empty( $allowed_values ) ) {
					$value = null;
					break;
				}
				$default = ( empty( $option['default'] ) ? $allowed_values[0] : $option['default'] );
				$value   = in_array( $raw_value, $allowed_values ) ? sanitize_text_field( $raw_value ) : sanitize_text_field( $default );
				break;
			case 'tinymce':
				$value = wpautop( $raw_value );
				break;

			default:
				$value = ur_clean( $raw_value );
				break;
		}

		return $value;
	}
}


if ( ! function_exists( 'ur_save_settings_options' ) ) {

	/**
	 * Save settings options.
	 *
	 * @param mixed $option Option.
	 * @param mixed $raw_value Raw Value.
	 *
	 * @return void
	 */
	function ur_save_settings_options( $section, $form_data ) {
		$update_options = array();
		foreach ( $section['settings'] as $option ) {
			if ( empty( $option['id'] ) ) {
				continue;
			}

			$option_id    = $option['id'];
			$option_name  = '';
			$setting_name = '';

			// Parse array notation (e.g., option_name[setting_name]).
			if ( str_contains( $option_id, '[' ) && str_contains( $option_id, ']' ) ) {
				if ( preg_match( '/^([^[\]]+)\[([^[\]]+)\]$/', $option_id, $matches ) ) {
					$option_name  = sanitize_text_field( $matches[1] );
					$setting_name = sanitize_text_field( $matches[2] );
				}
			} else {
				$option_name = sanitize_text_field( $option_id );
			}

			if ( isset( $form_data[ $option_id ] ) ) {
				$value = ur_sanitize_value_by_type( $option, $form_data[ $option_id ] );
				if ( $option_name && $setting_name ) {
					if ( ! isset( $update_options[ $option_name ] ) || ! is_array( $update_options[ $option_name ] ) ) {
						$existing                       = get_option( $option_name, array() );
						$update_options[ $option_name ] = is_array( $existing ) ? $existing : array();
					}
					$update_options[ $option_name ][ $setting_name ] = $value;
				} elseif ( $option_name ) {
					$update_options[ $option_name ] = $value;
				}
			}
		}
		foreach ( $update_options as $name => $value ) {
			update_option( $name, $value );
		}
	}
}

if ( ! function_exists( 'user_registration_profile_details_form_fields' ) ) {

	/**
	 * Get the user registration form fields to include in view profile.
	 *
	 * @param int   $form_id Id of the form through which user was registered.
	 * @param array $fields_to_include Fields to include.
	 *
	 * @return array
	 */
	function user_registration_profile_details_form_fields( $form_id, $fields_to_include = array() ) {

		$post_content_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();

		$form_field_data_array = array();
		foreach ( $post_content_array as $row_index => $row ) {
			foreach ( $row as $grid_index => $grid ) {
				foreach ( $grid as $field_index => $field ) {
					if ( isset( $field->general_setting->field_name ) ) {
						$field->field_key = isset( $field->field_key ) ? $field->field_key : '';
						$form_field_data_array[ $field->general_setting->field_name ] = array(
							'field_key' => $field->field_key,
							'label'     => $field->general_setting->label,
						);
						if ( in_array( $field->field_key, $fields_to_include ) ) {
							$form_field_data_array[ $field->general_setting->field_name ] = array(
								'field_key' => $field->field_key,
								'label'     => $field->general_setting->label,
							);
						}
					}
				}
			}
		}

		return $form_field_data_array;
	}
}
if ( ! function_exists( 'user_registration_profile_details_form_field_datas' ) ) {
	/**
	 * Get the user registration form fields data for fields included in view profile.
	 *
	 * @param int   $form_id Id of the form through which user was registered.
	 * @param array $user_data All the datas of the user.
	 * @param array $form_field_data_array All the fields to be included in profile details page.
	 * @param array $field_to_include Field to include.
	 *
	 * @return array
	 */
	function user_registration_profile_details_form_field_datas( $form_id, $user_data, $form_field_data_array, $field_to_include = array() ) {

		$user_data_to_show = array();
		foreach ( $user_data as $key => $value ) {

			if ( ! empty( $field_to_include ) && ! in_array( $key, $field_to_include ) ) {
				continue;
			}

			if ( isset( $form_field_data_array[ $key ] ) && '' !== $value ) {

				$user_data_to_show[ $key ] = array(
					'field_key' => $form_field_data_array[ $key ]['field_key'],
					'label'     => $form_field_data_array[ $key ]['label'],
					'value'     => $value,
				);

			}

			$fields_to_exclude = array_merge(
				ur_exclude_profile_details_fields(),
				apply_filters(
					'user_registration_pro_excluded_fields_in_view_details_page',
					array(
						'profile_picture',
						'privacy_policy',
						'password',
					)
				)
			);

			if ( isset( $user_data_to_show[ $key ]['field_key'] ) ) {
				if ( 'file' === $user_data_to_show[ $key ]['field_key'] && '' !== $user_data_to_show[ $key ]['value'] ) {
					$upload_data = array();
					$file_data   = is_string( $value ) ? explode( ',', $value ) : $value;

					foreach ( $file_data as $attachment_key => $attachment_id ) {
						$file      = isset( $attachment_id ) ? wp_get_attachment_url( $attachment_id ) : '';
						$file_link = '<a href="' . esc_url( $file ) . '" rel="noreferrer noopener" target="_blank" >' . esc_html( basename( get_attached_file( $attachment_id ) ) ) . '</a>';
						$file_link = apply_filters( 'user_registration_membership_frontend_listing_file_link', $file_link, $attachment_id );
						array_push( $upload_data, $file_link );
					}
					// Check if value contains array.
					if ( is_array( $upload_data ) ) {
						$value = implode( ',', $upload_data );
					}

					$user_data_to_show[ $key ]['value'] = $value;
				}

				// For Country Field.
				if ( 'country' === $user_data_to_show[ $key ]['field_key'] && '' !== $user_data_to_show[ $key ]['value'] ) {
					$country_class                      = ur_load_form_field_class( $user_data_to_show[ $key ]['field_key'] );
					$countries                          = $country_class::get_instance()->get_country();
					$user_data_to_show[ $key ]['value'] = isset( $countries[ $value ] ) ? $countries[ $value ] : $value;
				}

				// For checkbox and multiselect field.
				if ( ( 'checkbox' === $user_data_to_show[ $key ]['field_key'] || 'multi_select2' === $user_data_to_show[ $key ]['field_key'] ) && '' !== $user_data_to_show[ $key ]['value'] ) {
					$user_data_to_show[ $key ]['value'] = is_array( $user_data_to_show[ $key ]['value'] ) ? implode( ',', $user_data_to_show[ $key ]['value'] ) : $user_data_to_show[ $key ]['value'];
				}

				if ( in_array( $key, $fields_to_exclude ) ) {
					unset( $user_data_to_show[ $key ] );
				}
			}
		}

		return $user_data_to_show;
	}
}

if ( ! function_exists( 'ur_has_membership_plans' ) ) {
	/**
	 * Check whether at least one active membership plan exists.
	 *
	 * A deactivated plan stays published and only has its status flag turned off,
	 * so the post status alone is not enough. This mirrors the active check in
	 * MembershipService::prepare_membership_data().
	 *
	 * @return bool
	 */
	function ur_has_membership_plans() {
		if ( ! post_type_exists( 'ur_membership' ) ) {
			return false;
		}

		$memberships = get_posts(
			array(
				'post_type'              => 'ur_membership',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $memberships as $membership ) {
			$membership_content = json_decode( wp_unslash( $membership->post_content ), true );

			if ( ! empty( $membership_content['status'] ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'ur_is_membership_registration_type' ) ) {
	/**
	 * Check whether the setup wizard's registration type is a membership type
	 * (as opposed to "Advanced Registration", stored as 'normal').
	 *
	 * @return bool
	 */
	function ur_is_membership_registration_type() {
		$membership_type = get_option( 'urm_onboarding_membership_type', 'normal' );

		return in_array( $membership_type, array( 'free_membership', 'paid_membership' ), true );
	}
}

if ( ! function_exists( 'ur_should_show_membership_requirements' ) ) {
	/**
	 * Membership-registration-type users always need these; Advanced Registration
	 * users only need them once a membership plan is created.
	 *
	 * @return bool
	 */
	function ur_should_show_membership_requirements() {
		return ur_is_membership_registration_type() || ur_has_membership_plans();
	}
}

if ( ! function_exists( 'ur_get_site_assistant_data' ) ) {
	/**
	 * Get site assistant data with all options status.
	 *
	 * @return array
	 */
	function ur_get_site_assistant_data() {
		// Check for required pages.
		$required_pages = array(
			'user_registration_login_page_id'              => 'Login Page',
			'user_registration_lost_password_page_id'      => 'Lost Password Page',
			'user_registration_member_registration_page_id' => 'Membership Registration Page',
			'user_registration_thank_you_page_id'          => 'Membership Thank You Page',
			'user_registration_myaccount_page_id'          => 'My Account Page',
			'user_registration_membership_pricing_page_id' => 'Membership Pricing Page',
		);

		$has_membership_plans = ur_has_membership_plans();

		// Advanced Registration users only need membership pages once a plan exists.
		$show_membership_requirements = ur_is_membership_registration_type() || $has_membership_plans;

		$missing_pages_data = array();

		foreach ( $required_pages as $option_name => $page_name ) {
			$page_id         = get_option( $option_name, 0 );
			$is_page_missing = ! $page_id || ! get_post( $page_id );

			// For login page, also check if login redirect URL is set.
			if ( 'user_registration_login_page_id' === $option_name ) {
				$login_redirect_url = get_option( 'user_registration_login_options_login_redirect_url', '' );
				if ( empty( $login_redirect_url ) ) {
					$is_page_missing = true;
				}
			}

			if ( $is_page_missing ) {
				// Only include membership pages if membership requirements should be shown.
				$is_membership_page = in_array(
					$option_name,
					array(
						'user_registration_member_registration_page_id',
						'user_registration_thank_you_page_id',
						'user_registration_membership_pricing_page_id',
					),
					true
				);

				if ( ! $is_membership_page || $show_membership_requirements ) {
					$missing_pages_data[] = array(
						'name'   => $page_name,
						'option' => $option_name,
					);
				}
			}
		}

		// Get payment setup handled status.
		$payment_setup_handled = ur_get_payment_setup_handled_status();

		// Get payment connection statuses.
		$payment_connections = ur_get_payment_connection_statuses();
		$enabled_features    = (array) get_option( 'user_registration_enabled_features', array() );
		$membership_enabled  = in_array( 'user-registration-membership', $enabled_features, true );

		$default_form_id = (int) get_option( 'user_registration_default_form_page_id', 0 );
		if ( ! $default_form_id ) {
			$default_form_id = (int) get_option( 'user_registration_registration_form', 0 );
		}

		$default_form_post    = $default_form_id ? get_post( $default_form_id ) : null;
		$default_form_content = $default_form_post ? (string) $default_form_post->post_content : '';

		$default_form_has_membership = false !== strpos( $default_form_content, '"field_key":"membership"' );

		$membership_field_skipped = (bool) get_option( 'user_registration_membership_field_skipped', false );

		$membership_field_handled = ( ! $membership_enabled ) || $default_form_has_membership || $membership_field_skipped;

		$site_assistant_data = array(
			'users_can_register'                => ur_users_can_register(),
			'has_default_form'                  => ! empty( $default_form_post ),
			'missing_pages'                     => $missing_pages_data,
			'disabled_emails_handled'           => ! ur_option_checked( 'user_registration_email_setting_disable_email' ),
			'test_email_sent'                   => get_option( 'user_registration_successful_test_mail', false ),
			'spam_protection_handled'           => ur_string_to_bool( get_option( 'user_registration_captcha_setting_v2_connection_status', false ) ) || ur_string_to_bool( get_option( 'user_registration_spam_protection_skipped', false ) ),
			'payment_setup_handled'             => $payment_setup_handled,
			'payment_connections'               => $payment_connections,
			'membership_enabled'                => $membership_enabled,
			'default_form_has_membership_field' => $default_form_has_membership,
			'membership_field_handled'          => $membership_field_handled,
			'has_membership_plans'              => $has_membership_plans,
		);

		return apply_filters( 'ur_site_assistant_data', $site_assistant_data );
	}
}

if ( ! function_exists( 'ur_get_payment_setup_handled_status' ) ) {
	/**
	 * Get payment setup handled status.
	 * Returns true if ALL payment methods are connected or if payment setup is skipped.
	 *
	 * @return bool
	 */
	function ur_get_payment_setup_handled_status() {
		$payment_connections = ur_get_payment_connection_statuses();

		// Check if ALL payment methods are connected.
		$all_payments_connected = true;
		foreach ( $payment_connections as $connection ) {
			if ( ! $connection['is_connected'] ) {
				$all_payments_connected = false;
				break;
			}
		}

		// Check if payment setup is skipped.
		$is_skipped = get_option( 'user_registration_payment_setup_skipped', false );

		return $all_payments_connected || $is_skipped;
	}
}

if ( ! function_exists( 'ur_is_paypal_old_installation' ) ) {
	/**
	 * Determines if the current site has legacy PayPal (IPN/NVP) data.
	 *
	 * Checks for the presence of actual legacy option values in the DB as the
	 * source of truth, avoiding reliance on urm_is_new_installation which can
	 * be incorrectly set for users who upgrade from an older version.
	 *
	 * @return bool True for old/legacy PayPal installations, false for new ones.
	 */
	function ur_is_paypal_old_installation() {
		$has_live_legacy_data = '' !== get_option( 'user_registration_global_paypal_email_address', '' )
			|| '' !== get_option( 'user_registration_global_paypal_test_email_address', '' )
			|| '' !== get_option( 'user_registration_global_paypal_live_email_address', '' )
			|| '' !== get_option( 'user_registration_global_paypal_cancel_url', '' )
			|| '' !== get_option( 'user_registration_global_paypal_return_url', '' );

		if ( $has_live_legacy_data ) {
			if ( ! ur_string_to_bool( get_option( 'urm_is_legacy_paypal_user', false ) ) ) {
				update_option( 'urm_is_legacy_paypal_user', 1 );
			}
			return true;
		}

		return ur_string_to_bool( get_option( 'urm_is_legacy_paypal_user', false ) );
	}
}

if ( ! function_exists( 'ur_get_payment_connection_statuses' ) ) {
	/**
	 * Get payment connection statuses for available modules.
	 *
	 * @return array
	 */
	function ur_get_payment_connection_statuses() {
		$connections = array();

		// Check Stripe connection (available in free version).
		if ( ur_check_module_activation( 'stripe' ) || ur_should_show_membership_requirements() ) {
			$connections['stripe'] = array(
				'name'         => 'Stripe',
				'is_connected' => ur_string_to_bool( get_option( 'urm_stripe_connection_status', false ) ),
				'settings_url' => admin_url( '/admin.php?page=user-registration-settings&tab=payment&method=stripe' ),
			);
		}

		// Check PayPal connection (available in free version).
		if ( ur_check_module_activation( 'payments' ) || ur_should_show_membership_requirements() ) {
			$connections['paypal'] = array(
				'name'         => 'PayPal',
				'is_connected' => ur_string_to_bool( get_option( 'urm_paypal_connection_status', false ) ),
				'settings_url' => admin_url( '/admin.php?page=user-registration-settings&tab=payment&method=paypal' ),
			);
		}

		// Check Bank connection (membership only).
		if ( ur_should_show_membership_requirements() ) {
			$connections['bank'] = array(
				'name'         => 'Bank Payment',
				'is_connected' => ur_string_to_bool( get_option( 'urm_bank_connection_status', false ) ),
				'settings_url' => admin_url( '/admin.php?page=user-registration-settings&tab=payment&method=bank' ),
			);
		}

		return apply_filters( 'ur_site_assistant_payment_connections', $connections );
	}
}

if ( ! function_exists( 'ur_get_login_page_url' ) ) {
	/**
	 * Get the appropriate login page URL based on User Registration settings.
	 *
	 * @return string|false The login page URL or false if none found
	 */
	function ur_get_login_page_url() {
		// Step 1: Check user_registration_login_page_id option first.
		$login_page_id = get_option( 'user_registration_login_page_id' );
		if ( ! empty( $login_page_id ) ) {
			$page_url = get_permalink( $login_page_id );
			if ( $page_url ) {
				return $page_url;
			}
		}

		// Step 2: Check user_registration_login_options_login_redirect_url option.
		$login_redirect_url = get_option( 'user_registration_login_options_login_redirect_url' );
		if ( ! empty( $login_redirect_url ) ) {
			// If it's a page ID, get the permalink.
			if ( is_numeric( $login_redirect_url ) ) {
				$page_url = get_permalink( $login_redirect_url );
				if ( $page_url ) {
					return $page_url;
				}
			} else {
				// If it's already a URL, validate and return it.
				if ( ur_is_valid_url( $login_redirect_url ) ) {
					return esc_url_raw( $login_redirect_url );
				}
			}
		}

		// Step 3: Find all pages with login shortcode or block.
		$login_pages = ur_find_pages_with_login_functionality();
		if ( ! empty( $login_pages ) ) {
			// Return the first found login page URL.
			return get_permalink( $login_pages[0]->ID );
		}

		return false;
	}
}

if ( ! function_exists( 'ur_find_pages_with_login_functionality' ) ) {
	/**
	 * Find all pages in the system that contain login shortcodes or blocks.
	 *
	 * @return array Array of WP_Post objects containing login functionality
	 */
	function ur_find_pages_with_login_functionality() {
		global $wpdb;

		$login_pages = array();

		// Search for pages with login shortcodes and blocks.
		$patterns = array(
			'%[user_registration_login]%',
			'%<!-- wp:user-registration/login-form%',
		);

		// Build the SQL query.
		$pattern_conditions = array();
		foreach ( $patterns as $pattern ) {
			$pattern_conditions[] = 'post_content LIKE ' . $wpdb->prepare( '%s', $pattern );
		}

		$where_clause = implode( ' OR ', $pattern_conditions );

		$query = "
			SELECT DISTINCT p.*
			FROM {$wpdb->posts} p
			WHERE p.post_type = 'page'
			AND p.post_status = 'publish'
			AND ({$where_clause})
			ORDER BY p.post_title ASC
		";

		$results = $wpdb->get_results( $query ); // phpcs:ignore

		// Merge and deduplicate results.
		$all_results  = array_merge( $results );
		$unique_pages = array();

		foreach ( $all_results as $page ) {
			if ( ! isset( $unique_pages[ $page->ID ] ) ) {
				$unique_pages[ $page->ID ] = $page;
			}
		}

		return array_values( $unique_pages );
	}
}

if ( ! function_exists( 'ur_get_login_page_info' ) ) {
	/**
	 * Get detailed information about login page options and available pages.
	 *
	 * @return array Array containing login page information
	 */
	function ur_get_login_page_info() {
		$info = array(
			'login_redirect_url'             => get_option( 'user_registration_login_options_login_redirect_url' ),
			'login_page_id'                  => get_option( 'user_registration_login_page_id' ),
			'login_pages_with_functionality' => ur_find_pages_with_login_functionality(),
			'recommended_login_page_url'     => ur_get_login_page_url(),
		);

		// Add additional context.
		$info['login_redirect_url_set'] = ! empty( $info['login_redirect_url'] );
		$info['login_page_id_set']      = ! empty( $info['login_page_id'] );
		$info['has_login_pages']        = ! empty( $info['login_pages_with_functionality'] );

		return $info;
	}
}

if ( ! function_exists( 'ur_should_show_site_assistant_menu' ) ) {
	/**
	 * Check if site assistant menu should be shown.
	 * Returns false if all options are handled and set.
	 *
	 * @return bool
	 */
	function ur_should_show_site_assistant_menu() {
		$site_assistant_data = ur_get_site_assistant_data();

		return (
			! $site_assistant_data['users_can_register']
			|| ! $site_assistant_data['has_default_form']
			|| ! empty( $site_assistant_data['missing_pages'] )
			|| ! $site_assistant_data['disabled_emails_handled']
			|| ! $site_assistant_data['test_email_sent']
			|| ! $site_assistant_data['spam_protection_handled']
			|| ! $site_assistant_data['payment_setup_handled']
		);
	}

}

if ( ! function_exists( 'ur_site_assistant_config_count' ) ) {
	/**
	 * Check if site assistant menu should be shown.
	 * Returns false if all options are handled and set.
	 *
	 * @return bool
	 */
	function ur_site_assistant_config_count() {
		$site_assistant_data = ur_get_site_assistant_data();

		$checks = array(
			! $site_assistant_data['users_can_register'],
			! $site_assistant_data['has_default_form'],
			! empty( $site_assistant_data['missing_pages'] ),
			! $site_assistant_data['disabled_emails_handled'],
			! $site_assistant_data['test_email_sent'],
			! $site_assistant_data['spam_protection_handled'],
			! $site_assistant_data['payment_setup_handled'],
		);

		$count = count( array_filter( $checks ) );

		return $count;
	}
}

if ( ! function_exists( 'ur_get_membership_details' ) ) {
	/**
	 * Get membership details.
	 *
	 * @return array
	 * @since 5.0.0
	 */
	function ur_get_membership_details() {
		$membership_service = new MembershipService();
		$memberships        = $membership_service->list_active_memberships();

		return $memberships;
	}
}

if ( ! function_exists( 'urm_process_profile_fields' ) ) {
	/**
	 * Helper function for profile fields processing.
	 *
	 * @param array $profile User profile data.
	 * @param array $single_field Single field data.
	 * @param array $form_data The form data.
	 * @param int   $form_id The form ID.
	 * @param int   $user_id The user id.
	 * @param bool  $is_admin_user Whether the user is an admin.
	 */
	function urm_process_profile_fields( $profile, $single_field, $form_data, $form_id, $user_id, $is_admin_user = false ) {

		foreach ( $profile as $key => $field ) {

			if ( ! isset( $field['type'] ) ) {
				$field['type'] = 'text';
			}
			// Unset hidden field value.
			if ( ( isset( $field['field_key'] ) && 'hidden' === $field['field_key'] ) || ( 'range' === $field['type'] && ur_string_to_bool( $field['enable_payment_slider'] ) ) ) {
				$key = array_search( $field, $profile, true );

				if ( false !== ( $key ) ) {
					unset( $profile[ $key ] );
				}
			}
			if ( ! $is_admin_user && 'hidden' === $field['type'] ) {
				$key = array_search( $field, $profile, true );

				if ( false !== ( $key ) ) {
					unset( $profile[ $key ] );
				}
			}
			// Get Value.
			switch ( $field['type'] ) {
				case 'checkbox':
					if ( isset( $single_field[ $key ] ) ) {
						// Serialize values fo checkbox field.
						$single_field[ $key ] = ( json_decode( $single_field[ $key ] ) !== null ) ? json_decode( $single_field[ $key ] ) : sanitize_text_field( $single_field[ $key ] );
					}
					break;
				case 'wysiwyg':
					if ( isset( $single_field[ $key ] ) ) {
						$single_field[ $key ] = sanitize_text_field( htmlentities( $single_field[ $key ] ) );
					} else {
						$single_field[ $key ] = '';
					}
					break;
				case 'signature':
					if ( isset( $single_field[ $key ] ) ) {
						$single_field[ $key ] = apply_filters( 'user_registration_process_signature_field_data', $single_field[ $key ] );
					} else {
						$single_field[ $key ] = $field['default'];
					}
					break;
				default:
					if ( 'repeater' !== $field['type'] ) {
						$single_field[ $key ] = isset( $single_field[ $key ] ) ? $single_field[ $key ] : '';
					}
					break;
			}
		}
		if ( isset( $field['field_key'] ) && 'country' === $field['field_key'] && isset( $single_field[ $key ] ) ) {
			$single_field[ $key ] = json_encode(
				array(
					'country' => sanitize_text_field( $single_field[ $key ] ),
					'state'   => sanitize_text_field(
						isset( $single_field[ $key . '_state' ] ) ? $single_field[ $key . '_state' ] : ''
					),
				)
			);
		}

		/**
		 * Action hook to perform validation of edit profile form.
		 *
		 * @param array $profile User profile data.
		 * @param array $form_data The form data.
		 * @param int $form_id The form ID.
		 * @param int $user_id The user id.
		 */
		do_action( 'user_registration_validate_profile_update', $profile, $form_data, $form_id, $user_id );
		/**
		 * Action after the save profile validation.
		 *
		 * @param int The user ID.
		 * @param array The profile data.
		 */
		do_action( 'user_registration_after_save_profile_validation', $user_id, $profile );

		return array( $profile, $single_field );
	}
}

if ( ! function_exists( 'ur_get_non_urm_user_profile_fields' ) ) {
	/**
	 * Fallback profile fields for users with no resolvable UR registration form.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array
	 */
	function ur_get_non_urm_user_profile_fields( $user_id ) {
		if ( ! get_userdata( $user_id ) ) {
			return array();
		}

		return apply_filters(
			'user_registration_non_urm_user_profile_fields',
			array(
				'user_registration_user_login' => array(
					'label'     => __( 'Username', 'user-registration' ),
					'type'      => 'text',
					'field_key' => 'user_login',
					'required'  => true,
				),
				'user_registration_first_name' => array(
					'label'     => __( 'First Name', 'user-registration' ),
					'type'      => 'text',
					'field_key' => 'first_name',
					'required'  => false,
				),
				'user_registration_user_email' => array(
					'label'     => __( 'User Email', 'user-registration' ),
					'type'      => 'email',
					'field_key' => 'user_email',
					'required'  => true,
				),
				'user_registration_last_name'  => array(
					'label'     => __( 'Last Name', 'user-registration' ),
					'type'      => 'text',
					'field_key' => 'last_name',
					'required'  => false,
				),
			),
			$user_id
		);
	}
}

if ( ! function_exists( 'urm_update_user_profile_data' ) ) {
	/**
	 * Helper function to update user profile data.
	 *
	 * @param object $user The user object.
	 * @param array  $profile User profile data.
	 * @param array  $single_field Single field data.
	 * @param int    $form_id The form ID.
	 */
	function urm_update_user_profile_data( $user, $profile, $single_field, $form_id ) {

		$user_data = array();
		/**
		 * Filter to modify the email change confirmation.
		 * Default vallue is 'true'.
		 */
		$is_email_change_confirmation = (bool) apply_filters( 'user_registration_email_change_confirmation', true );
		$email_updated                = false;
		$pending_email                = '';
		$user_id                      = $user->ID;
		/**
		 * Filter to modify the field settings.
		 *
		 * The dynamic portion of the hook name, $value->field_key.
		 *
		 * @param array $value The field value.
		 */
		$profile = apply_filters( 'user_registration_before_save_profile_details', $profile, $user_id, $form_id );

		foreach ( $profile as $key => $field ) {
			$new_key = str_replace( 'user_registration_', '', $key );

			if ( $is_email_change_confirmation && 'user_email' === $new_key ) {
				if ( $user ) {
					if ( sanitize_email( wp_unslash( $single_field[ $key ] ) ) !== $user->user_email ) {
						$email_updated = true;
						$pending_email = sanitize_email( wp_unslash( $single_field[ $key ] ) );
					}
					continue;
				}
			}

			if ( in_array( $new_key, ur_get_user_table_fields() ) ) {
				if ( 'display_name' === $new_key ) {
					$user_data['display_name'] = sanitize_text_field( ( $single_field[ $key ] ) );
				} else {
					$user_data[ $new_key ] = sanitize_text_field( $single_field[ $key ] );
				}
			} else {
				$update_key = $key;

				if ( in_array( $new_key, ur_get_registered_user_meta_fields() ) ) {
					$update_key = str_replace( 'user_', '', $new_key );
				}
				$disabled = isset( $field['custom_attributes']['disabled'] ) ? $field['custom_attributes']['disabled'] : '';

				if ( 'disabled' !== $disabled ) {
					update_user_meta( $user_id, $update_key, $single_field[ $key ] );
				}
			}
		}

		if ( count( $user_data ) > 0 ) {
			$user_data['ID'] = $user_id;
			wp_update_user( $user_data );
		}

		return array( $email_updated, $pending_email );
	}
}

if ( ! function_exists( 'ur_pro_get_form_fields' ) ) {
	/**
	 * Get form fields.
	 *
	 * @param int $form_id Registration Form ID.
	 *
	 * @return array|WP_Error
	 */
	function ur_pro_get_form_fields( $form_id ) {
		$form   = get_post( $form_id );
		$fields = array();

		if ( $form && 'user_registration' === $form->post_type ) {
			$form_field_array = json_decode( $form->post_content );

			if ( $form_field_array ) {

				foreach ( $form_field_array as $post_content_row ) {
					foreach ( $post_content_row as $post_content_grid ) {
						foreach ( $post_content_grid as $field ) {
							if ( isset( $field->field_key ) && ! in_array( $field->field_key, ur_pro_get_excluded_fields() ) ) {
								$field_name        = isset( $field->general_setting->field_name ) ? $field->general_setting->field_name : '';
								$field_label       = isset( $field->general_setting->label ) ? $field->general_setting->label : '';
								$field_description = isset( $field->general_setting->description ) ? $field->general_setting->description : '';
								$placeholder       = isset( $field->general_setting->placeholder ) ? $field->general_setting->placeholder : '';
								$options           = isset( $field->general_setting->options ) ? $field->general_setting->options : array();
								$field_key         = isset( $field->field_key ) ? ( $field->field_key ) : '';
								$field_type        = isset( $field->field_key ) ? ur_get_field_type( $field_key ) : '';
								$required          = isset( $field->general_setting->required ) ? $field->general_setting->required : '';
								$required          = ur_string_to_bool( $required );
								$enable_cl         = isset( $field->advance_setting->enable_conditional_logic ) && ur_string_to_bool( $field->advance_setting->enable_conditional_logic );
								$cl_map            = isset( $field->advance_setting->cl_map ) ? $field->advance_setting->cl_map : '';
								$custom_attributes = isset( $field->general_setting->custom_attributes ) ? $field->general_setting->custom_attributes : array();
								$default           = '';

								if ( isset( $field->general_setting->default_value ) ) {
									$default = $field->general_setting->default_value;
								} elseif ( isset( $field->advance_setting->default_value ) ) {
									$default = $field->advance_setting->default_value;
								}

								if ( empty( $field_label ) ) {
									$field_label_array = explode( '_', $field_name );
									$field_label       = join( ' ', array_map( 'ucwords', $field_label_array ) );
								}

								if ( ! empty( $field_name ) ) {
									$extra_params = array();

									switch ( $field_key ) {

										case 'radio':
										case 'select':
											$advanced_options        = isset( $field->advance_setting->options ) ? $field->advance_setting->options : '';
											$advanced_options        = explode( ',', $advanced_options );
											$extra_params['options'] = ! empty( $options ) ? $options : $advanced_options;
											$extra_params['options'] = array_map( 'trim', $extra_params['options'] );

											$extra_params['options'] = array_combine( $extra_params['options'], $extra_params['options'] );

											break;

										case 'checkbox':
											$advanced_options        = isset( $field->advance_setting->choices ) ? $field->advance_setting->choices : '';
											$advanced_options        = explode( ',', $advanced_options );
											$extra_params['options'] = ! empty( $options ) ? $options : $advanced_options;
											$extra_params['options'] = array_map( 'trim', $extra_params['options'] );

											$extra_params['options'] = array_combine( $extra_params['options'], $extra_params['options'] );

											break;

										case 'date':
											$date_format       = isset( $field->advance_setting->date_format ) ? $field->advance_setting->date_format : '';
											$min_date          = isset( $field->advance_setting->min_date ) ? str_replace( '/', '-', $field->advance_setting->min_date ) : '';
											$max_date          = isset( $field->advance_setting->max_date ) ? str_replace( '/', '-', $field->advance_setting->max_date ) : '';
											$set_current_date  = isset( $field->advance_setting->set_current_date ) ? $field->advance_setting->set_current_date : '';
											$enable_date_range = isset( $field->advance_setting->enable_date_range ) ? $field->advance_setting->enable_date_range : '';
											$extra_params['custom_attributes']['data-date-format'] = $date_format;

											if ( isset( $field->advance_setting->enable_min_max ) && ur_string_to_bool( $field->advance_setting->enable_min_max ) ) {
												$extra_params['custom_attributes']['data-min-date'] = '' !== $min_date ? date_i18n( $date_format, strtotime( $min_date ) ) : '';
												$extra_params['custom_attributes']['data-max-date'] = '' !== $max_date ? date_i18n( $date_format, strtotime( $max_date ) ) : '';
											}
											$extra_params['custom_attributes']['data-default-date'] = $set_current_date;
											$extra_params['custom_attributes']['data-mode']         = $enable_date_range;
											break;

										case 'country':
											$class_name              = ur_load_form_field_class( $field_key );
											$extra_params['options'] = $class_name::get_instance()->get_selected_countries( $form_id, $field_name );
											break;

										case 'file':
											$extra_params['max_files'] = isset( $field->general_setting->max_files ) ? $field->general_setting->max_files : '';
											break;

										case 'phone':
											$extra_params['phone_format'] = isset( $field->general_setting->phone_format ) ? $field->general_setting->phone_format : '';
											break;
										case 'learndash_course':
											$extra_params['learndash_field_type'] = isset( $field->general_setting->learndash_field_type ) ? $field->general_setting->learndash_field_type : '';
											if ( isset( $field->advance_setting->enroll_type ) ) {
												if ( 'courses' === $field->advance_setting->enroll_type ) {
													$extra_params['options'] = function_exists( 'get_courses_list' ) ? get_courses_list() : array();
												} else {
													$extra_params['options'] = function_exists( 'get_groups_list' ) ? get_groups_list() : array();
												}
											}
											break;

										default:
											break;
									}

									$extra_params['default'] = $default;

									$fields[ 'user_registration_' . $field_name ] = array(
										'label'       => ur_string_translation( $form_id, 'user_registration_' . $field_name . '_label', $field_label ),
										'description' => ur_string_translation( $form_id, 'user_registration_' . $field_name . '_description', $field_description ),
										'type'        => $field_type,
										'placeholder' => ur_string_translation( $form_id, 'user_registration_' . $field_name . '_placeholder', $placeholder ),
										'field_key'   => $field_key,
										'required'    => $required,
									);

									if ( true === $enable_cl ) {
										$fields[ 'user_registration_' . $field_name ]['enable_conditional_logic'] = $enable_cl;
										$fields[ 'user_registration_' . $field_name ]['cl_map']                   = $cl_map;
									}

									if ( count( $custom_attributes ) > 0 ) {
										$extra_params['custom_attributes'] = $custom_attributes;
									}

									if ( isset( $fields[ 'user_registration_' . $field_name ] ) && count( $extra_params ) > 0 ) {
										$fields[ 'user_registration_' . $field_name ] = array_merge( $fields[ 'user_registration_' . $field_name ], $extra_params );
									}
									$filter_data = array(
										'fields'     => $fields,
										'field'      => $field,
										'field_name' => $field_name,
									);

									$filtered_data_array = apply_filters( 'user_registration_profile_account_filter_' . $field_key, $filter_data, $form_id );
									if ( isset( $filtered_data_array['fields'] ) ) {
										$fields = $filtered_data_array['fields'];
									}
								}// End if().
							}
						}// End foreach().
					}// End foreach().
				}// End foreach().
			}
		} else {
			return new WP_Error( 'form-not-found', __( 'Form not found!', 'user-registration' ) );
		}

		return apply_filters( 'user_registration_pro_form_field_list', $fields );
	}
}

if ( ! function_exists( 'ur_pro_get_excluded_fields' ) ) {
	/**
	 * Get Excluded fields.
	 */
	function ur_pro_get_excluded_fields() {
		$excluded_fields = array(
			'user_confirm_password',
			'user_confirm_email',
			'section_title',
		);

		return apply_filters( 'user_registration_pro_excluded_fields', $excluded_fields );
	}
}

if ( ! function_exists( 'ur_membership_table_exists' ) ) {
	/**
	 * Check whether a membership module table exists.
	 *
	 * Mirrors the guard the membership repositories apply before querying, e.g.
	 * OrdersRepository::get_all() and SubscriptionRepository::query().
	 *
	 * @param string $table Fully qualified table name.
	 *
	 * @return bool
	 * @since x.x.x
	 */
	function ur_membership_table_exists( $table ) {
		global $wpdb;

		static $checked = array();

		if ( empty( $table ) ) {
			return false;
		}

		if ( ! isset( $checked[ $table ] ) ) {
			$checked[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}

		return $checked[ $table ];
	}
}

if ( ! function_exists( 'ur_has_membership_plans' ) ) {
	/**
	 * Check whether at least one published membership plan exists.
	 *
	 * The `ur_membership` post type is only registered when the membership module
	 * is loaded, so fall back to a direct query when it is not.
	 *
	 * @return bool
	 * @since x.x.x
	 */
	function ur_has_membership_plans() {
		static $has_plans = null;

		if ( null !== $has_plans ) {
			return $has_plans;
		}

		if ( post_type_exists( 'ur_membership' ) ) {
			$has_plans = (bool) get_posts(
				array(
					'post_type'      => 'ur_membership',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			return $has_plans;
		}

		global $wpdb;

		$has_plans = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s LIMIT 1",
				'ur_membership',
				'publish'
			)
		);

		return $has_plans;
	}
}

if ( ! function_exists( 'ur_has_payment_entries' ) ) {
	/**
	 * Check whether the Payments page has at least one record to display.
	 *
	 * Mirrors the two sources merged by OrdersListTable::prepare_items():
	 * membership orders (only when the membership module is active, and only rows
	 * whose plan post and user still exist, matching the INNER JOINs in
	 * OrdersRepository::get_all()) and normal registration payments, which are
	 * stored as the `ur_payment_status` user meta.
	 *
	 * @return bool
	 * @since x.x.x
	 */
	function ur_has_payment_entries() {
		global $wpdb;

		static $has_entries = null;

		if ( null !== $has_entries ) {
			return $has_entries;
		}

		$has_entries = false;

		// Membership orders, gated exactly as OrdersListTable gates its repository.
		if ( function_exists( 'ur_check_module_activation' ) && ur_check_module_activation( 'membership' ) ) {
			$orders_table = class_exists( 'WPEverest\URMembership\TableList' )
				? \WPEverest\URMembership\TableList::orders_table()
				: $wpdb->prefix . 'ur_membership_orders';

			if ( ur_membership_table_exists( $orders_table ) ) {
				$orders_query = "SELECT o.ID
					FROM {$orders_table} o
					INNER JOIN {$wpdb->posts} p ON o.item_id = p.ID
					INNER JOIN {$wpdb->users} u ON o.user_id = u.ID
					LIMIT 1";

				$has_entries = (bool) $wpdb->get_var( $orders_query ); // phpcs:ignore
			}
		}

		// Payments made through a registration form.
		if ( ! $has_entries ) {
			$has_entries = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1",
					'ur_payment_status'
				)
			);
		}

		return $has_entries;
	}
}

if ( ! function_exists( 'ur_has_subscription_entries' ) ) {
	/**
	 * Check whether at least one subscription record exists.
	 *
	 * Mirrors SubscriptionRepository::query(), which counts the subscriptions
	 * table directly without joining anything.
	 *
	 * @return bool
	 * @since x.x.x
	 */
	function ur_has_subscription_entries() {
		global $wpdb;

		static $has_entries = null;

		if ( null !== $has_entries ) {
			return $has_entries;
		}

		$subscriptions_table = class_exists( 'WPEverest\URMembership\TableList' )
			? \WPEverest\URMembership\TableList::subscriptions_table()
			: $wpdb->prefix . 'ur_membership_subscriptions';

		$has_entries = ur_membership_table_exists( $subscriptions_table )
			&& (bool) $wpdb->get_var( "SELECT ID FROM {$subscriptions_table} LIMIT 1" ); // phpcs:ignore

		return $has_entries;
	}
}

if ( ! function_exists( 'ur_has_payment_enabled_form' ) ) {
	/**
	 * Check whether any published registration form collects a payment.
	 *
	 * There is no per-form "payments enabled" flag; payment fields live inside the
	 * form's post_content JSON, so this matches on the `"field_key":"..."` markers
	 * the same way MembershipRepository::get_membership_forms() does.
	 *
	 * @return bool
	 * @since x.x.x
	 */
	function ur_has_payment_enabled_form() {
		global $wpdb;

		static $has_form = null;

		if ( null !== $has_form ) {
			return $has_form;
		}

		/**
		 * Field keys that on their own make a registration form charge the user.
		 *
		 * Deliberately narrower than user_registration_payment_fields(): `total_field`
		 * and `quantity_field` are payment fields but never trigger a gateway by
		 * themselves. This list matches the gateway check in
		 * UR_Pro_Payments_Frontend::payment_process_after_registration().
		 *
		 * @param array $field_keys Charging field keys.
		 *
		 * @since x.x.x
		 */
		$field_keys = apply_filters(
			'user_registration_payments_menu_field_keys',
			array( 'single_item', 'multiple_choice', 'subscription_plan' )
		);

		$conditions = array();

		foreach ( (array) $field_keys as $field_key ) {
			$pattern      = '%' . $wpdb->esc_like( '"field_key":"' . $field_key . '"' ) . '%';
			$conditions[] = 'post_content LIKE ' . $wpdb->prepare( '%s', $pattern );
		}

		if ( ! empty( $conditions ) ) {
			$where_clause = implode( ' OR ', $conditions );

			$fields_query = "SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'user_registration'
				AND post_status = 'publish'
				AND ({$where_clause})
				LIMIT 1";

			$has_form = (bool) $wpdb->get_var( $fields_query ); // phpcs:ignore

			if ( $has_form ) {
				return $has_form;
			}
		}

		$has_form = false;

		// A `range` field only charges when its payment slider is enabled. The stored
		// value is boolean-ish, so candidate forms have to be confirmed in PHP.
		if ( ! function_exists( 'ur_get_form_fields' ) ) {
			return $has_form;
		}

		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'user_registration'
				AND post_status = 'publish'
				AND post_content LIKE %s",
				'%' . $wpdb->esc_like( 'enable_payment_slider' ) . '%'
			)
		);

		foreach ( $candidates as $form_id ) {
			foreach ( (array) ur_get_form_fields( $form_id ) as $field ) {
				if ( isset( $field->field_key, $field->advance_setting->enable_payment_slider )
					&& 'range' === $field->field_key
					&& ur_string_to_bool( $field->advance_setting->enable_payment_slider ) ) {
					$has_form = true;

					return $has_form;
				}
			}
		}

		return $has_form;
	}
}

if ( ! function_exists( 'ur_should_show_payments_menu' ) ) {
	/**
	 * Whether the Payments submenu should be registered.
	 *
	 * Shown when payments are actually in use: the Payments feature is enabled, the
	 * page already has records, a membership plan exists, or a registration form
	 * collects a payment. Onboarding's "Advanced Registration" path matches none of
	 * these, which is what keeps the menu hidden there.
	 *
	 * @return bool
	 * @since x.x.x
	 */
	function ur_should_show_payments_menu() {
		$show = ( function_exists( 'ur_check_module_activation' ) && ur_check_module_activation( 'payments' ) )
			|| ur_has_payment_entries()
			|| ur_has_membership_plans()
			|| ur_has_payment_enabled_form();

		/**
		 * Filter whether the Payments submenu is registered.
		 *
		 * @param bool $show Whether to show the menu.
		 *
		 * @since x.x.x
		 */
		return (bool) apply_filters( 'user_registration_show_payments_menu', $show );
	}
}

if ( ! function_exists( 'ur_should_show_subscriptions_menu' ) ) {
	/**
	 * Whether the Subscriptions submenu should be registered.
	 *
	 * The membership module alone is not enough: onboarding enables it before the
	 * user picks a registration type, so also require something to manage — a
	 * membership plan or an existing subscription record.
	 *
	 * @return bool
	 * @since x.x.x
	 */
	function ur_should_show_subscriptions_menu() {
		$show = function_exists( 'ur_check_module_activation' )
			&& ur_check_module_activation( 'membership' )
			&& ( ur_has_membership_plans() || ur_has_subscription_entries() );

		/**
		 * Filter whether the Subscriptions submenu is registered.
		 *
		 * @param bool $show Whether to show the menu.
		 *
		 * @since x.x.x
		 */
		return (bool) apply_filters( 'user_registration_show_subscriptions_menu', $show );
	}
}

/**
 * Get the count of membership rules.
 * This function checks for content access rules with rule_type = 'membership'.
 *
 * @return int The count of membership rules.
 * @since 4.4.8
 */
if ( ! function_exists( 'ur_get_membership_rules_count' ) ) {
	/**
	 * Get membership rules count.
	 *
	 * @return void
	 */
	function ur_get_membership_rules_count() {
		// Check if membership module is active.
		if ( ! function_exists( 'ur_check_module_activation' ) || ! ur_check_module_activation( 'membership' ) ) {
			return 0;
		}

		// Check if post type exists, if not query directly from database.
		if ( post_type_exists( 'urcr_access_rule' ) ) {
			$membership_rules = get_posts(
				array(
					'post_type'      => 'urcr_access_rule',
					'post_status'    => 'any',
					'posts_per_page' => - 1,
					'meta_query'     => array(
						array(
							'key'   => 'urcr_rule_type',
							'value' => 'membership',
						),
					),
				)
			);

			return is_array( $membership_rules ) ? count( $membership_rules ) : 0;
		} else {
			// Post type not registered yet, query directly from database.
			global $wpdb;
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = %s
					AND pm.meta_key = %s
					AND pm.meta_value = %s",
					'urcr_access_rule',
					'urcr_rule_type',
					'membership'
				)
			);

			return absint( $count );
		}
	}

	if ( ! function_exists( 'ur_get_coupon_meta_by_code' ) ) {

		/**
		 * Fetch coupon meta using coupon code stored in post_content
		 *
		 * @param string $coupon_code Coupon code.
		 *
		 * @return array|bool
		 *
		 * @since xx.xx.xx
		 */
		function ur_get_coupon_meta_by_code( $coupon_code ) {
			global $wpdb;

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_content = %s
				LIMIT 1",
					'ur_coupons',
					$coupon_code
				)
			);

			if ( empty( $post_id ) ) {
				return false;
			}

			$coupon_meta = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value
				FROM {$wpdb->postmeta}
				WHERE post_id = %d
				AND meta_key = %s
				LIMIT 1",
					$post_id,
					'ur_coupon_meta'
				)
			);

			if ( empty( $coupon_meta ) ) {
				return false;
			}

			return json_decode( ur_maybe_unserialize( $coupon_meta ) );
		}
	}

	if ( ! function_exists( 'ur_get_coupon_meta_by_code' ) ) {

		/**
		 * Fetch coupon meta using coupon code stored in post_content
		 *
		 * @param string $coupon_code Coupon Code.
		 *
		 * @return array|bool
		 *
		 * @since xx.xx.xx
		 */
		function ur_get_coupon_meta_by_code( $coupon_code ) {
			global $wpdb;

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_content = %s
				LIMIT 1",
					'ur_coupons',
					$coupon_code
				)
			);

			if ( empty( $post_id ) ) {
				return false;
			}

			$coupon_meta = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value
				FROM {$wpdb->postmeta}
				WHERE post_id = %d
				AND meta_key = %s
				LIMIT 1",
					$post_id,
					'ur_coupon_meta'
				)
			);

			if ( empty( $coupon_meta ) ) {
				return false;
			}

			return json_decode( ur_maybe_unserialize( $coupon_meta ) );
		}
	}
}


if ( ! function_exists( 'ur_get_linked_membership_form_id' ) ) {

	/**
	 * Get linked membership form id.
	 *
	 * @since 5.0.0
	 */
	function ur_get_linked_membership_form_id() {
		$page_id    = get_option( 'user_registration_member_registration_page_id' );
		$post_by_id = get_post( $page_id );
		$matches    = array();

		preg_match_all( '/\[user_registration_form\s+id="(\d+)"\]/', $post_by_id->post_content, $matches );
		$membership_form_id = $matches[1][0];

		return $membership_form_id;
	}
}

if ( ! function_exists( 'urcr_get_custom_rules_count' ) ) {

	/**
	 * Get count of content restriction rules.
	 *
	 * @param array|string $types Rule types to count. Options: 'custom', 'membership', 'migrated'. Can be array of types or single string. Empty array/string counts all rules.
	 *
	 * @return int Count of rules.
	 * @since 1.0.0
	 */
	function urcr_get_custom_rules_count( $types = array() ) {
		// Check if content restriction post type exists.
		if ( ! post_type_exists( 'urcr_access_rule' ) ) {
			return 0;
		}

		// Get all content access rules.
		$all_rules = get_posts(
			array(
				'post_type'      => 'urcr_access_rule',
				'post_status'    => 'any',
				'posts_per_page' => - 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $all_rules ) ) {
			return 0;
		}

		// Convert string to array for backward compatibility.
		if ( is_string( $types ) ) {
			$types = empty( $types ) ? array() : array( $types );
		}

		// Ensure $types is an array.
		if ( ! is_array( $types ) ) {
			$types = array();
		}

		// If types array is empty, return count of all rules.
		if ( empty( $types ) ) {
			return count( $all_rules );
		}

		// Count rules based on types (rules matching any of the provided types).
		$rules_count = 0;
		foreach ( $all_rules as $rule_id ) {
			$rule_type     = get_post_meta( $rule_id, 'urcr_rule_type', true );
			$membership_id = get_post_meta( $rule_id, 'urcr_membership_id', true );
			$is_migrated   = get_post_meta( $rule_id, 'urcr_is_migrated', true );

			$matches = false;

			foreach ( $types as $type ) {
				switch ( $type ) {
					case 'custom':
						// Count rules that are NOT membership rules.
						if ( 'membership' !== $rule_type && empty( $membership_id ) ) {
							$matches = true;
							break 2; // Break out of switch and foreach.
						}
						break;

					case 'membership':
						// Count rules that ARE membership rules.
						if ( 'membership' === $rule_type || ! empty( $membership_id ) ) {
							$matches = true;
							break 2; // Break out of switch and foreach.
						}
						break;

					case 'migrated':
						// Count rules that are migrated.
						if ( ! empty( $is_migrated ) ) {
							$matches = true;
							break 2; // Break out of switch and foreach.
						}
						break;
				}
			}

			if ( $matches ) {
				++$rules_count;
			}
		}

		return $rules_count;
	}
}


if ( ! function_exists( 'urm_array_key_exists_recursive' ) ) {

	/**
	 * Check key exists.
	 *
	 * @param string $needle Key to search.
	 * @param array  $haystack Array.
	 * @return void
	 */
	function urm_array_key_exists_recursive( $needle, array $haystack ) {
		foreach ( $haystack as $key => $value ) {
			if ( $key === $needle ) {
				return $value;
			}

			if ( is_array( $value ) && urm_array_key_exists_recursive( $needle, $value ) ) {
				return $value;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'urm_is_premium_setting_section' ) ) {
	/**
	 * Check if premium settings.
	 *
	 * @param string $id Setting id.
	 */
	function urm_is_premium_setting_section( $id ) {
		$premium_tabs      = ur_premium_settings_tab();
		$premium_tab       = urm_array_key_exists_recursive( $id, $premium_tabs );
		$show_premium_icon = false;

		if ( ! empty( $premium_tab ) ) {
			$license_data = ur_get_license_plan();
			$license_plan = ! empty( $license_data->item_plan ) ? $license_data->item_plan : false;
			$license_plan = trim( str_replace( 'lifetime', '', strtolower( $license_plan ) ) );

			if ( ! empty( $premium_tab[ $id ]['plan'] ) ) {
				if ( in_array( $license_plan, $premium_tab[ $id ]['plan'], true ) ) {
					$show_premium_icon = false;
				} elseif ( file_exists( WP_PLUGIN_DIR . '/' . $premium_tab[ $id ]['plugin'] ) && is_plugin_active( $premium_tab[ $id ]['plugin'] . '/' . $premium_tab[ $id ]['plugin'] . '.php' ) ) {
					$show_premium_icon = false;
				} else {
					$show_premium_icon = true;
				}
			} else {
				$show_premium_icon = $premium_tab ? true : false;
			}
		}
		return $show_premium_icon;
	}
}

if ( ! function_exists( 'urm_check_if_plus_and_above_plan' ) ) {

	/**
	 * Check if user's license is plus or above plan.
	 */
	function urm_check_if_plus_and_above_plan() {
		$license_data = ur_get_license_plan();
		$license_plan = ! empty( $license_data->item_plan ) ? $license_data->item_plan : false;
		$license_plan = trim( str_replace( 'lifetime', '', strtolower( $license_plan ) ) );

		$available_plans = array( 'plus', 'professional', 'themegrill agency' );

		if ( in_array( $license_plan, $available_plans, true ) ) {
			return true;
		}

		return false;
	}
}


if ( ! function_exists( 'ur_get_country_lists' ) ) {

	/**
	 * Get country lists.
	 *
	 * @since 5.0.0
	 */
	function ur_get_country_lists() {
		$countries = include UR_ABSPATH . 'includes/pro/country-and-state/countries.php';
		return $countries;
	}
}

if ( ! function_exists( 'ur_get_state_lists' ) ) {

	/**
	 * Get State lists.
	 *
	 * @since 5.0.0
	 */
	function ur_get_state_lists() {
		$states_json = ur_file_get_contents( '/assets/extensions-json/states.json' );
		$states      = json_decode( $states_json, true );
		return $states;
	}
}

if ( ! function_exists( 'ur_get_currency_symbols' ) ) {

	/**
	 * Get all available Currency symbols.
	 *
	 * Currency symbols and names should follow the Unicode CLDR recommendation (http://cldr.unicode.org/translation/currency-names)
	 *
	 * @since 6.0.0
	 *
	 * @return array
	 */
	function ur_get_currency_symbols() {
		/**
		 * Filters currency symbols.
		 *
		 * @since 6.0.0
		 *
		 * @param string[] $currency_symbols Currency code to currency symbol index array.
		 */
		$symbols = apply_filters(
			'ur_currency_symbols',
			array(
				'AED' => '&#x62f;.&#x625;',
				'AFN' => '&#x60b;',
				'ALL' => 'L',
				'AMD' => 'AMD',
				'ANG' => '&fnof;',
				'AOA' => 'Kz',
				'ARS' => '&#36;',
				'AUD' => '&#36;',
				'AWG' => 'Afl.',
				'AZN' => 'AZN',
				'BAM' => 'KM',
				'BBD' => '&#36;',
				'BDT' => '&#2547;&nbsp;',
				'BGN' => '&#1083;&#1074;.',
				'BHD' => '.&#x62f;.&#x628;',
				'BIF' => 'Fr',
				'BMD' => '&#36;',
				'BND' => '&#36;',
				'BOB' => 'Bs.',
				'BRL' => '&#82;&#36;',
				'BSD' => '&#36;',
				'BTC' => '&#3647;',
				'BTN' => 'Nu.',
				'BWP' => 'P',
				'BYR' => 'Br',
				'BYN' => 'Br',
				'BZD' => '&#36;',
				'CAD' => '&#36;',
				'CDF' => 'Fr',
				'CHF' => '&#67;&#72;&#70;',
				'CLP' => '&#36;',
				'CNY' => '&yen;',
				'COP' => '&#36;',
				'CRC' => '&#x20a1;',
				'CUC' => '&#36;',
				'CUP' => '&#36;',
				'CVE' => '&#36;',
				'CZK' => '&#75;&#269;',
				'DJF' => 'Fr',
				'DKK' => 'DKK',
				'DOP' => 'RD&#36;',
				'DZD' => '&#x62f;.&#x62c;',
				'EGP' => 'EGP',
				'ERN' => 'Nfk',
				'ETB' => 'Br',
				'EUR' => '&euro;',
				'FJD' => '&#36;',
				'FKP' => '&pound;',
				'GBP' => '&pound;',
				'GEL' => '&#x20be;',
				'GGP' => '&pound;',
				'GHS' => '&#x20b5;',
				'GIP' => '&pound;',
				'GMD' => 'D',
				'GNF' => 'Fr',
				'GTQ' => 'Q',
				'GYD' => '&#36;',
				'HKD' => '&#36;',
				'HNL' => 'L',
				'HRK' => 'kn',
				'HTG' => 'G',
				'HUF' => '&#70;&#116;',
				'IDR' => 'Rp',
				'ILS' => '&#8362;',
				'IMP' => '&pound;',
				'INR' => '&#8377;',
				'IQD' => '&#x639;.&#x62f;',
				'IRR' => '&#xfdfc;',
				'IRT' => '&#x062A;&#x0648;&#x0645;&#x0627;&#x0646;',
				'ISK' => 'kr.',
				'JEP' => '&pound;',
				'JMD' => '&#36;',
				'JOD' => '&#x62f;.&#x627;',
				'JPY' => '&yen;',
				'KES' => 'KSh',
				'KGS' => '&#x441;&#x43e;&#x43c;',
				'KHR' => '&#x17db;',
				'KMF' => 'Fr',
				'KPW' => '&#x20a9;',
				'KRW' => '&#8361;',
				'KWD' => '&#x62f;.&#x643;',
				'KYD' => '&#36;',
				'KZT' => '&#8376;',
				'LAK' => '&#8365;',
				'LBP' => '&#x644;.&#x644;',
				'LKR' => '&#xdbb;&#xdd4;',
				'LRD' => '&#36;',
				'LSL' => 'L',
				'LYD' => '&#x644;.&#x62f;',
				'MAD' => '&#x62f;.&#x645;.',
				'MDL' => 'MDL',
				'MGA' => 'Ar',
				'MKD' => '&#x434;&#x435;&#x43d;',
				'MMK' => 'Ks',
				'MNT' => '&#x20ae;',
				'MOP' => 'P',
				'MRU' => 'UM',
				'MUR' => '&#x20a8;',
				'MVR' => '.&#x783;',
				'MWK' => 'MK',
				'MXN' => '&#36;',
				'MYR' => '&#82;&#77;',
				'MZN' => 'MT',
				'NAD' => 'N&#36;',
				'NGN' => '&#8358;',
				'NIO' => 'C&#36;',
				'NOK' => '&#107;&#114;',
				'NPR' => '&#8360;',
				'NZD' => '&#36;',
				'OMR' => '&#x631;.&#x639;.',
				'PAB' => 'B/.',
				'PEN' => 'S/',
				'PGK' => 'K',
				'PHP' => '&#8369;',
				'PKR' => '&#8360;',
				'PLN' => '&#122;&#322;',
				'PRB' => '&#x440;.',
				'PYG' => '&#8370;',
				'QAR' => '&#x631;.&#x642;',
				'RMB' => '&yen;',
				'RON' => 'lei',
				'RSD' => '&#1088;&#1089;&#1076;',
				'RUB' => '&#8381;',
				'RWF' => 'Fr',
				'SAR' => '&#x631;.&#x633;',
				'SBD' => '&#36;',
				'SCR' => '&#x20a8;',
				'SDG' => '&#x62c;.&#x633;.',
				'SEK' => '&#107;&#114;',
				'SGD' => '&#36;',
				'SHP' => '&pound;',
				'SLL' => 'Le',
				'SOS' => 'Sh',
				'SRD' => '&#36;',
				'SSP' => '&pound;',
				'STN' => 'Db',
				'SYP' => '&#x644;.&#x633;',
				'SZL' => 'L',
				'THB' => '&#3647;',
				'TJS' => '&#x405;&#x41c;',
				'TMT' => 'm',
				'TND' => '&#x62f;.&#x62a;',
				'TOP' => 'T&#36;',
				'TRY' => '&#8378;',
				'TTD' => '&#36;',
				'TWD' => '&#78;&#84;&#36;',
				'TZS' => 'Sh',
				'UAH' => '&#8372;',
				'UGX' => 'UGX',
				'USD' => '&#36;',
				'UYU' => '&#36;',
				'UZS' => 'UZS',
				'VEF' => 'Bs F',
				'VES' => 'Bs.S',
				'VND' => '&#8363;',
				'VUV' => 'Vt',
				'WST' => 'T',
				'XAF' => 'CFA',
				'XCD' => '&#36;',
				'XOF' => 'CFA',
				'XPF' => 'Fr',
				'YER' => '&#xfdfc;',
				'ZAR' => '&#82;',
				'ZMW' => 'ZK',
			)
		);

		return $symbols;
	}
}

if ( ! function_exists( 'ur_get_currencies_with_symbols' ) ) {

	/**
	 * Get full list of currency codes with symbols.
	 *
	 * @since 6.0.0
	 *
	 * @return array
	 */
	function ur_get_currencies_with_symbols() {
		$currencies = ur_get_currencies();

		foreach ( $currencies as $key => $value ) {
			$currencies[ $key ] = sprintf( '%s (%s)', $value, html_entity_decode( ur_get_currency_symbol( $key ) ) );
		}

		/**
		 * Filters list of currency codes with symbols.
		 *
		 * @since 6.0.0
		 *
		 * @param array $currencies List of currency codes with symbols.
		 */
		return apply_filters( 'ur_currencies_with_symbols', $currencies );
	}

}

if ( ! function_exists( 'ur_get_currencies' ) ) {

	/**
	 * Get full list of currency codes.
	 *
	 * Currency symbols and names should follow the Unicode CLDR recommendation (http://cldr.unicode.org/translation/currency-names)
	 *
	 * @since 6.0.0
	 *
	 * @return array
	 */
	function ur_get_currencies() {
		$currencies = array_unique(
		/**
		 * Filters full list of currency codes.
		 *
		 * @since 6.0.0
		 *
		 * @param string[] $currencies Full list of currency codes.
		 */
			apply_filters(
				'ur_currencies',
				array(
					'AED' => __( 'United Arab Emirates dirham', 'user-registration' ),
					'AFN' => __( 'Afghan afghani', 'user-registration' ),
					'ALL' => __( 'Albanian lek', 'user-registration' ),
					'AMD' => __( 'Armenian dram', 'user-registration' ),
					'ANG' => __( 'Netherlands Antillean guilder', 'user-registration' ),
					'AOA' => __( 'Angolan kwanza', 'user-registration' ),
					'ARS' => __( 'Argentine peso', 'user-registration' ),
					'AUD' => __( 'Australian dollar', 'user-registration' ),
					'AWG' => __( 'Aruban florin', 'user-registration' ),
					'AZN' => __( 'Azerbaijani manat', 'user-registration' ),
					'BAM' => __( 'Bosnia and Herzegovina convertible mark', 'user-registration' ),
					'BBD' => __( 'Barbadian dollar', 'user-registration' ),
					'BDT' => __( 'Bangladeshi taka', 'user-registration' ),
					'BGN' => __( 'Bulgarian lev', 'user-registration' ),
					'BHD' => __( 'Bahraini dinar', 'user-registration' ),
					'BIF' => __( 'Burundian franc', 'user-registration' ),
					'BMD' => __( 'Bermudian dollar', 'user-registration' ),
					'BND' => __( 'Brunei dollar', 'user-registration' ),
					'BOB' => __( 'Bolivian boliviano', 'user-registration' ),
					'BRL' => __( 'Brazilian real', 'user-registration' ),
					'BSD' => __( 'Bahamian dollar', 'user-registration' ),
					'BTC' => __( 'Bitcoin', 'user-registration' ),
					'BTN' => __( 'Bhutanese ngultrum', 'user-registration' ),
					'BWP' => __( 'Botswana pula', 'user-registration' ),
					'BYR' => __( 'Belarusian ruble (old)', 'user-registration' ),
					'BYN' => __( 'Belarusian ruble', 'user-registration' ),
					'BZD' => __( 'Belize dollar', 'user-registration' ),
					'CAD' => __( 'Canadian dollar', 'user-registration' ),
					'CDF' => __( 'Congolese franc', 'user-registration' ),
					'CHF' => __( 'Swiss franc', 'user-registration' ),
					'CLP' => __( 'Chilean peso', 'user-registration' ),
					'CNY' => __( 'Chinese yuan', 'user-registration' ),
					'COP' => __( 'Colombian peso', 'user-registration' ),
					'CRC' => __( 'Costa Rican col&oacute;n', 'user-registration' ),
					'CUC' => __( 'Cuban convertible peso', 'user-registration' ),
					'CUP' => __( 'Cuban peso', 'user-registration' ),
					'CVE' => __( 'Cape Verdean escudo', 'user-registration' ),
					'CZK' => __( 'Czech koruna', 'user-registration' ),
					'DJF' => __( 'Djiboutian franc', 'user-registration' ),
					'DKK' => __( 'Danish krone', 'user-registration' ),
					'DOP' => __( 'Dominican peso', 'user-registration' ),
					'DZD' => __( 'Algerian dinar', 'user-registration' ),
					'EGP' => __( 'Egyptian pound', 'user-registration' ),
					'ERN' => __( 'Eritrean nakfa', 'user-registration' ),
					'ETB' => __( 'Ethiopian birr', 'user-registration' ),
					'EUR' => __( 'Euro', 'user-registration' ),
					'FJD' => __( 'Fijian dollar', 'user-registration' ),
					'FKP' => __( 'Falkland Islands pound', 'user-registration' ),
					'GBP' => __( 'Pound sterling', 'user-registration' ),
					'GEL' => __( 'Georgian lari', 'user-registration' ),
					'GGP' => __( 'Guernsey pound', 'user-registration' ),
					'GHS' => __( 'Ghana cedi', 'user-registration' ),
					'GIP' => __( 'Gibraltar pound', 'user-registration' ),
					'GMD' => __( 'Gambian dalasi', 'user-registration' ),
					'GNF' => __( 'Guinean franc', 'user-registration' ),
					'GTQ' => __( 'Guatemalan quetzal', 'user-registration' ),
					'GYD' => __( 'Guyanese dollar', 'user-registration' ),
					'HKD' => __( 'Hong Kong dollar', 'user-registration' ),
					'HNL' => __( 'Honduran lempira', 'user-registration' ),
					'HRK' => __( 'Croatian kuna', 'user-registration' ),
					'HTG' => __( 'Haitian gourde', 'user-registration' ),
					'HUF' => __( 'Hungarian forint', 'user-registration' ),
					'IDR' => __( 'Indonesian rupiah', 'user-registration' ),
					'ILS' => __( 'Israeli new shekel', 'user-registration' ),
					'IMP' => __( 'Manx pound', 'user-registration' ),
					'INR' => __( 'Indian rupee', 'user-registration' ),
					'IQD' => __( 'Iraqi dinar', 'user-registration' ),
					'IRR' => __( 'Iranian rial', 'user-registration' ),
					'IRT' => __( 'Iranian toman', 'user-registration' ),
					'ISK' => __( 'Icelandic kr&oacute;na', 'user-registration' ),
					'JEP' => __( 'Jersey pound', 'user-registration' ),
					'JMD' => __( 'Jamaican dollar', 'user-registration' ),
					'JOD' => __( 'Jordanian dinar', 'user-registration' ),
					'JPY' => __( 'Japanese yen', 'user-registration' ),
					'KES' => __( 'Kenyan shilling', 'user-registration' ),
					'KGS' => __( 'Kyrgyzstani som', 'user-registration' ),
					'KHR' => __( 'Cambodian riel', 'user-registration' ),
					'KMF' => __( 'Comorian franc', 'user-registration' ),
					'KPW' => __( 'North Korean won', 'user-registration' ),
					'KRW' => __( 'South Korean won', 'user-registration' ),
					'KWD' => __( 'Kuwaiti dinar', 'user-registration' ),
					'KYD' => __( 'Cayman Islands dollar', 'user-registration' ),
					'KZT' => __( 'Kazakhstani tenge', 'user-registration' ),
					'LAK' => __( 'Lao kip', 'user-registration' ),
					'LBP' => __( 'Lebanese pound', 'user-registration' ),
					'LKR' => __( 'Sri Lankan rupee', 'user-registration' ),
					'LRD' => __( 'Liberian dollar', 'user-registration' ),
					'LSL' => __( 'Lesotho loti', 'user-registration' ),
					'LYD' => __( 'Libyan dinar', 'user-registration' ),
					'MAD' => __( 'Moroccan dirham', 'user-registration' ),
					'MDL' => __( 'Moldovan leu', 'user-registration' ),
					'MGA' => __( 'Malagasy ariary', 'user-registration' ),
					'MKD' => __( 'Macedonian denar', 'user-registration' ),
					'MMK' => __( 'Burmese kyat', 'user-registration' ),
					'MNT' => __( 'Mongolian t&ouml;gr&ouml;g', 'user-registration' ),
					'MOP' => __( 'Macanese pataca', 'user-registration' ),
					'MRU' => __( 'Mauritanian ouguiya', 'user-registration' ),
					'MUR' => __( 'Mauritian rupee', 'user-registration' ),
					'MVR' => __( 'Maldivian rufiyaa', 'user-registration' ),
					'MWK' => __( 'Malawian kwacha', 'user-registration' ),
					'MXN' => __( 'Mexican peso', 'user-registration' ),
					'MYR' => __( 'Malaysian ringgit', 'user-registration' ),
					'MZN' => __( 'Mozambican metical', 'user-registration' ),
					'NAD' => __( 'Namibian dollar', 'user-registration' ),
					'NGN' => __( 'Nigerian naira', 'user-registration' ),
					'NIO' => __( 'Nicaraguan c&oacute;rdoba', 'user-registration' ),
					'NOK' => __( 'Norwegian krone', 'user-registration' ),
					'NPR' => __( 'Nepalese rupee', 'user-registration' ),
					'NZD' => __( 'New Zealand dollar', 'user-registration' ),
					'OMR' => __( 'Omani rial', 'user-registration' ),
					'PAB' => __( 'Panamanian balboa', 'user-registration' ),
					'PEN' => __( 'Sol', 'user-registration' ),
					'PGK' => __( 'Papua New Guinean kina', 'user-registration' ),
					'PHP' => __( 'Philippine peso', 'user-registration' ),
					'PKR' => __( 'Pakistani rupee', 'user-registration' ),
					'PLN' => __( 'Polish z&#x142;oty', 'user-registration' ),
					'PRB' => __( 'Transnistrian ruble', 'user-registration' ),
					'PYG' => __( 'Paraguayan guaran&iacute;', 'user-registration' ),
					'QAR' => __( 'Qatari riyal', 'user-registration' ),
					'RON' => __( 'Romanian leu', 'user-registration' ),
					'RSD' => __( 'Serbian dinar', 'user-registration' ),
					'RUB' => __( 'Russian ruble', 'user-registration' ),
					'RWF' => __( 'Rwandan franc', 'user-registration' ),
					'SAR' => __( 'Saudi riyal', 'user-registration' ),
					'SBD' => __( 'Solomon Islands dollar', 'user-registration' ),
					'SCR' => __( 'Seychellois rupee', 'user-registration' ),
					'SDG' => __( 'Sudanese pound', 'user-registration' ),
					'SEK' => __( 'Swedish krona', 'user-registration' ),
					'SGD' => __( 'Singapore dollar', 'user-registration' ),
					'SHP' => __( 'Saint Helena pound', 'user-registration' ),
					'SLL' => __( 'Sierra Leonean leone', 'user-registration' ),
					'SOS' => __( 'Somali shilling', 'user-registration' ),
					'SRD' => __( 'Surinamese dollar', 'user-registration' ),
					'SSP' => __( 'South Sudanese pound', 'user-registration' ),
					'STN' => __( 'S&atilde;o Tom&eacute; and Pr&iacute;ncipe dobra', 'user-registration' ),
					'SYP' => __( 'Syrian pound', 'user-registration' ),
					'SZL' => __( 'Swazi lilangeni', 'user-registration' ),
					'THB' => __( 'Thai baht', 'user-registration' ),
					'TJS' => __( 'Tajikistani somoni', 'user-registration' ),
					'TMT' => __( 'Turkmenistan manat', 'user-registration' ),
					'TND' => __( 'Tunisian dinar', 'user-registration' ),
					'TOP' => __( 'Tongan pa&#x2bb;anga', 'user-registration' ),
					'TRY' => __( 'Turkish lira', 'user-registration' ),
					'TTD' => __( 'Trinidad and Tobago dollar', 'user-registration' ),
					'TWD' => __( 'New Taiwan dollar', 'user-registration' ),
					'TZS' => __( 'Tanzanian shilling', 'user-registration' ),
					'UAH' => __( 'Ukrainian hryvnia', 'user-registration' ),
					'UGX' => __( 'Ugandan shilling', 'user-registration' ),
					'USD' => __( 'United States (US) dollar', 'user-registration' ),
					'UYU' => __( 'Uruguayan peso', 'user-registration' ),
					'UZS' => __( 'Uzbekistani som', 'user-registration' ),
					'VEF' => __( 'Venezuelan bol&iacute;var', 'user-registration' ),
					'VES' => __( 'Bol&iacute;var soberano', 'user-registration' ),
					'VND' => __( 'Vietnamese &#x111;&#x1ed3;ng', 'user-registration' ),
					'VUV' => __( 'Vanuatu vatu', 'user-registration' ),
					'WST' => __( 'Samoan t&#x101;l&#x101;', 'user-registration' ),
					'XAF' => __( 'Central African CFA franc', 'user-registration' ),
					'XCD' => __( 'East Caribbean dollar', 'user-registration' ),
					'XOF' => __( 'West African CFA franc', 'user-registration' ),
					'XPF' => __( 'CFP franc', 'user-registration' ),
					'YER' => __( 'Yemeni rial', 'user-registration' ),
					'ZAR' => __( 'South African rand', 'user-registration' ),
					'ZMW' => __( 'Zambian kwacha', 'user-registration' ),
				)
			)
		);

		return $currencies;
	}
}

if ( ! function_exists( 'ur_get_currency_symbol' ) ) {

	/**
	 * Get Currency symbol.
	 *
	 * Currency symbols and names should follow the Unicode CLDR recommendation (http://cldr.unicode.org/translation/currency-names)
	 *
	 * @since 6.0.0
	 *
	 * @param string $currency Currency. (default: '').
	 *
	 * @return string
	 */
	function ur_get_currency_symbol( $currency = '' ) {
		$symbols = ur_get_currency_symbols();

		$currency_symbol = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : '';

		/**
		 * Filters currency symbol.
		 *
		 * @since 6.0.0
		 *
		 * @param string $currency_symbol Currency symbol.
		 * @param string $currency Currency.
		 */
		return apply_filters( 'ur_currency_symbol', $currency_symbol, $currency );
	}
}

if ( ! function_exists( 'ur_get_currency_name_by_key' ) ) {

	/**
	 * Get currency name by key.
	 *
	 * @param string $currency_key Currency Key.
	 */
	function ur_get_currency_name_by_key( $currency_key ) {
		$curreny_details = ur_get_currencies();

		$name = sprintf( '%s (%s)', $curreny_details[ $currency_key ], html_entity_decode( ur_get_currency_symbol( $currency_key ) ) );

		return apply_filters( 'ur_get_currency_name_by_key', $name );
	}
}

if ( ! function_exists( 'ur_get_currency_by_key' ) ) {

	/**
	 * Get currency by key.
	 *
	 * @param string $currency_key Currency Key.
	 */
	function ur_get_currency_by_key( $currency_key ) {
		$curreny_details = ur_get_currencies();

		$name = sprintf( '%s', $curreny_details[ $currency_key ] );

		return apply_filters( 'ur_get_currency_by_key', $name );
	}
}

if ( ! function_exists( 'ur_format_country_field_data' ) ) {

	/**
	 * Format country field data for display.
	 *
	 * @param string $country_code The country code.
	 * @param string $state_code   The state code.
	 *
	 * @return string Formatted country and state name.
	 */
	function ur_format_country_field_data( $country_code, $state_code ) {
		$country_list = \UR_Form_Field_Country::get_instance()->get_country();

		$country_name = isset( $country_list[ $country_code ] )
			? $country_list[ $country_code ]
			: $country_code;

		$states_json = ur_file_get_contents( '/assets/extensions-json/states.json' );
		$state_list  = json_decode( $states_json, true );

		$states = isset( $state_list[ $country_code ] ) ? $state_list[ $country_code ] : array();

		$state_name = isset( $states[ $state_code ] )
			? $states[ $state_code ]
			: $state_code;

		$final = array();

		if ( ! empty( $country_name ) ) {
			$final[] = $country_name;
		}

		if ( ! empty( $state_name ) ) {
			$final[] = $state_name;
		}

		$value = implode( ', ', $final );

		return $value;
	}
}

add_action( 'user_registration_after_stripe_settings_updated', 'user_registration_create_product_and_price_for_stripe' );

if ( ! function_exists( 'user_registration_create_product_and_price_for_stripe' ) ) {
	/**
	 * Create product and price in Stripe for all the subscription type memberships which are created before the stripe payment gateway integration.
	 *
	 * @param array $data The data array.
	 */
	function user_registration_create_product_and_price_for_stripe( $data ) {
		if ( ! function_exists( 'urm_is_payment_gateway_configured' ) || ! urm_is_payment_gateway_configured( 'stripe' ) ) {
			return;
		}

		$stripe_service        = new StripeService();
		$membership_repository = new MembershipRepository();
		$memberships           = $membership_repository->get_all_memberships_without_status_filter();

		foreach ( $memberships as $membership ) {
			$stripe_service->sync_product_and_price_in_stripe( $membership );
		}
	}
}

if ( ! function_exists( 'ur_maybe_flush_rewrite_rules' ) ) {
	// Flush only when one of the given account endpoints is missing from the saved rules.
	function ur_maybe_flush_rewrite_rules( $endpoints ) {
		if ( ! get_option( 'permalink_structure' ) ) {
			return;
		}

		$rules = get_option( 'rewrite_rules', array() );

		foreach ( (array) $endpoints as $endpoint ) {
			if ( ! isset( $rules[ '(.?.+?)/' . $endpoint . '(/(.*))?/?$' ] ) ) {
				flush_rewrite_rules();
				return;
			}
		}
	}
}
