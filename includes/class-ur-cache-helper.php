<?php
/**
 * Cache Helper Class
 *
 * @class   UR_Cache_Helper
 * @since   1.5.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UR_Cache_Helper Class.
 */
class UR_Cache_Helper {

	/**
	 * Hook in methods.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'user_registration_before_registration_form', array( __CLASS__, 'flush_w3tc_cache' ) );
		add_action( 'user_registration_before_registration_form', array( __CLASS__, 'flush_wpsuper_cache' ) );
		add_action( 'user_registration_before_registration_form', array( __CLASS__, 'flush_wprocket_cache' ) );
		add_action( 'user_registration_before_registration_form', array( __CLASS__, 'disable_page_cache_on_registration_form' ), 10, 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_disable_cache_for_dynamic_pages' ), 0 );

		// Flush page cache for a post at the moment its content restriction is applied during frontend rendering.
		// Mirrors the same pattern used for the registration form (user_registration_before_registration_form).
		add_action( 'urcr_pre_content_restriction_applied', array( __CLASS__, 'flush_post_cache_on_restriction' ), 10, 2 );
	}

	/**
	 * Flush already set cache by w3total cache plugin on registration page.
	 */
	public static function flush_w3tc_cache() {
		if ( function_exists( 'w3tc_pgcache_flush_post' ) ) {
			$post_id = get_the_ID();
			w3tc_pgcache_flush_post( $post_id );
		}
	}

	/**
	 * Flush already set cache by wp super cache plugin on registration page.
	 */
	public static function flush_wpsuper_cache() {
		if ( function_exists( 'wpsc_delete_post_cache' ) ) {
			$post_id = get_the_ID();
			wpsc_delete_post_cache( $post_id );
		}
	}

	/**
	 * Flush already set cache by wp rocket cache plugin on registration page.
	 */
	public static function flush_wprocket_cache() {
		if ( function_exists( 'rocket_clean_post' ) ) {
			$post_id = get_the_ID();
			rocket_clean_post( $post_id );
		}
	}

	/**
	 * Flush page cache for the post whose content restriction is being applied during frontend rendering.
	 * Fires on urcr_pre_content_restriction_applied — mirrors flush_w3tc_cache/flush_wpsuper_cache/flush_wprocket_cache
	 * which fire on user_registration_before_registration_form.
	 *
	 * Uses direct function calls only (no do_action) — firing action-based cache hooks mid-render
	 * can trigger handlers that manipulate output buffers and corrupt the page response.
	 *
	 * @param mixed   $restriction_rule The matched access rule data.
	 * @param WP_Post $post             The post being restricted.
	 */
	public static function flush_post_cache_on_restriction( $restriction_rule, $post ) {
		$post_id = is_object( $post ) && isset( $post->ID ) ? $post->ID : absint( $post );
		if ( ! $post_id ) {
			return;
		}

		if ( function_exists( 'w3tc_pgcache_flush_post' ) ) {
			w3tc_pgcache_flush_post( $post_id );
		}

		if ( function_exists( 'wpsc_delete_post_cache' ) ) {
			wpsc_delete_post_cache( $post_id );
		}

		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
	}

	/**
	 * Set constants to prevent caching by some plugins.
	 *
	 * @param  mixed $return Value to return. Previously hooked into a filter.
	 * @return mixed
	 */
	public static function set_nocache_constants( $return = true ) {
		ur_maybe_define_constant( 'DONOTCACHEPAGE', true );
		ur_maybe_define_constant( 'DONOTCACHEOBJECT', true );
		ur_maybe_define_constant( 'DONOTCACHEDB', true );
		return $return;
	}

	/**
	 * Notices function.
	 */
	public static function notices() {
		if ( ! function_exists( 'w3tc_pgcache_flush' ) || ! function_exists( 'w3_instance' ) ) {
			return;
		}

		$config   = w3_instance( 'W3_Config' );
		$enabled  = $config->get_integer( 'dbcache.enabled' );
		$settings = array_map( 'trim', $config->get_array( 'dbcache.reject.sql' ) );

		if ( $enabled && ! in_array( '_ur_session_', $settings, true ) ) {
			?>
			<div class="error">
				<p><?php echo wp_kses_post( sprintf( __( 'In order for <strong>database caching</strong> to work with User Registration you must add %1$s to the "Ignored Query Strings" option in <a href="%2$s">W3 Total Cache settings</a>.', 'user-registration' ), '<code>_ur_session_</code>', esc_url( admin_url( 'admin.php?page=w3tc_dbcache' ) ) ) ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Option names holding the ID of a page whose markup depends on the current user.
	 *
	 * @since 5.2.9
	 *
	 * @return string[] List of option names.
	 */
	protected static function get_dynamic_page_options() {
		return array(
			'user_registration_myaccount_page_id',
			'user_registration_login_page_id',
			'user_registration_registration_page_id',
			'user_registration_member_registration_page_id',
			'user_registration_lost_password_page_id',
			'user_registration_reset_password_page_id',
			'user_registration_thank_you_page_id',
			'user_registration_membership_pricing_page_id',
		);
	}

	/**
	 * Block names whose rendered markup depends on the current user.
	 *
	 * @since 5.2.9
	 *
	 * @return string[] List of block names.
	 */
	protected static function get_dynamic_block_names() {
		return array(
			'user-registration/login-form',
			'user-registration/myaccount',
			'user-registration/registration-form',
			'user-registration/edit-profile',
			'user-registration/edit-password',
			'user-registration/membership-listing',
			'user-registration/membership-buy-now',
			'user-registration/thank-you',
			'user-registration/login-logout-menu',
			'user-registration/form-selector',
		);
	}

	/**
	 * Shortcode tags whose rendered markup depends on the current user.
	 *
	 * Tags are resolved through the same filters they are registered with, so a renamed tag is
	 * still detected. Tags a given edition does not register simply never match.
	 *
	 * @since 5.2.9
	 *
	 * @return string[] List of shortcode tags.
	 */
	protected static function get_dynamic_shortcode_tags() {
		$shortcodes = array(
			'user_registration_form',
			'user_registration_my_account',
			'user_registration_login',
			'user_registration_lost_password',
			'user_registration_reset_password_form',
			'user_registration_edit_profile',
			'user_registration_edit_password',
			'user_registration_popup',
			'user_registration_view_profile_details',
			'user_registration_groups',
			'user_registration_membership_listing',
			'user_registration_membership_thank_you',
		);

		$tags = array();

		foreach ( $shortcodes as $shortcode ) {
			/** This filter is documented in includes/class-ur-shortcodes.php */
			$tags[] = apply_filters( "{$shortcode}_shortcode_tag", $shortcode );
		}

		return $tags;
	}

	/**
	 * Check whether the queried post is one of the pages configured in User Registration settings.
	 *
	 * Page IDs are read from the options directly. ur_get_page_id() is deliberately not used here:
	 * it falls back to the current post when the account page option is empty, which would match
	 * every singular request on the site.
	 *
	 * @since 5.2.9
	 *
	 * @param int $post_id Queried post ID.
	 * @return bool
	 */
	protected static function is_dynamic_ur_page( $post_id ) {
		$page_ids = array();

		foreach ( self::get_dynamic_page_options() as $option ) {
			$page_id = absint( get_option( $option, 0 ) );

			if ( 0 < $page_id ) {
				$page_ids[] = $page_id;
			}
		}

		if ( in_array( $post_id, $page_ids, true ) ) {
			return true;
		}

		// Resolving translations costs two queries per page ID under WPML, so only reach for it when
		// a multilingual plugin is active and the request could actually be a translated UR page.
		if ( ! function_exists( 'pll_current_language' ) && ! class_exists( 'SitePress', false ) ) {
			return false;
		}

		if ( 'page' !== get_post_type( $post_id ) ) {
			return false;
		}

		foreach ( $page_ids as $page_id ) {
			if ( absint( ur_get_translated_page_id( $page_id ) ) === $post_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the post content places a User Registration form or account screen.
	 *
	 * Only the stored content is inspected. It is never run through the_content, so nothing
	 * is rendered twice during template_redirect.
	 *
	 * @since 5.2.9
	 *
	 * @param int $post_id Queried post ID.
	 * @return bool
	 */
	protected static function post_has_dynamic_ur_content( $post_id ) {
		$post = get_post( $post_id );

		if ( ! is_a( $post, 'WP_Post' ) || '' === trim( $post->post_content ) ) {
			return false;
		}

		// has_block() matches raw markup, so a block left behind by a deactivated module would
		// otherwise keep the page out of the cache forever. Blocks register on init, before this runs.
		$block_registry = WP_Block_Type_Registry::get_instance();

		foreach ( self::get_dynamic_block_names() as $block_name ) {
			if ( ! $block_registry->is_registered( $block_name ) ) {
				continue;
			}

			if ( has_block( $block_name, $post->post_content ) ) {
				return true;
			}
		}

		foreach ( self::get_dynamic_shortcode_tags() as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the current request renders User Registration markup that differs per user.
	 *
	 * Detection is limited to singular requests because only there is there a single post whose
	 * stored content can be inspected before rendering starts.
	 *
	 * @since 5.2.9
	 *
	 * @return bool
	 */
	public static function is_dynamic_ur_request() {
		$is_dynamic = false;

		if ( is_singular() ) {
			$post_id = absint( get_queried_object_id() );

			if ( 0 < $post_id ) {
				$is_dynamic = self::is_dynamic_ur_page( $post_id ) || self::post_has_dynamic_ur_content( $post_id );
			}
		}

		/**
		 * Filters whether the current request renders user specific User Registration markup.
		 *
		 * Page builders, widgets and template parts keep their markup outside the post content,
		 * so those placements cannot be detected before rendering starts. Return true to send
		 * the no cache headers for such a request.
		 *
		 * @since 5.2.9
		 *
		 * @param bool $is_dynamic Whether user specific User Registration markup is expected.
		 */
		return (bool) apply_filters( 'user_registration_is_dynamic_page', $is_dynamic );
	}

	/**
	 * Ask page cache plugins not to store the response being generated.
	 *
	 * Safe to call while the page is rendering: page caches read DONOTCACHEPAGE in the output
	 * buffer callback that runs on shutdown, long after the response headers were sent, so a
	 * late call still keeps the response out of the cache.
	 *
	 * @since 5.2.9
	 *
	 * @param string $context Where the request was flagged. Currently 'page', 'shortcode',
	 *                        'registration' or 'membership'. Treat it as an open set: callers may
	 *                        pass their own string.
	 * @return bool Whether caching was disabled for this response.
	 */
	public static function disable_page_cache( $context = 'page' ) {
		/**
		 * Filters whether User Registration keeps the current response out of the page cache.
		 *
		 * Return false to leave the page cache active, for instance on sites that place a login
		 * form in a header or footer on every page and configure their cache to vary on the
		 * logged in cookie instead.
		 *
		 * @since 5.2.9
		 *
		 * @param bool   $disable Whether to disable page caching. Default true.
		 * @param string $context Where the request was flagged.
		 */
		if ( ! apply_filters( 'user_registration_disable_page_cache', true, $context ) ) {
			return false;
		}

		self::set_nocache_constants();

		// LiteSpeed Cache does not read DONOTCACHEPAGE, it exposes its own control API.
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_control_set_nocache', 'User Registration renders user specific content' );
		}

		return true;
	}

	/**
	 * Keep the response out of the page cache while a registration form is rendered.
	 *
	 * @since 5.2.9
	 */
	public static function disable_page_cache_on_registration_form() {
		self::disable_page_cache( 'registration' );
	}

	/**
	 * Send response headers that stop browsers, proxies and CDNs from storing the response.
	 *
	 * @since 5.2.9
	 */
	protected static function send_nocache_headers() {
		if ( headers_sent() ) {
			return;
		}

		nocache_headers();

		// Add no-store and vary headers for extra safety.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'Vary: Cookie, Authorization' );

		// Some CDNs respect these additional headers.
		header( 'Surrogate-Control: no-store' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Accel-Expires: 0' );
	}

	/**
	 * Prevent caching for User Registration pages whose markup depends on the current user.
	 *
	 * Covers the login, account, registration, lost password, reset password and thank you pages,
	 * whether the form is placed by the configured page ID, a shortcode or a block. Without this
	 * a page cache stores whichever branch rendered first and replays it to every visitor, so a
	 * logged out visitor can be served the logged in notice, or a stale login nonce.
	 */
	public static function maybe_disable_cache_for_dynamic_pages() {
		if ( ! self::is_dynamic_ur_request() ) {
			return;
		}

		if ( self::disable_page_cache( 'page' ) ) {
			self::send_nocache_headers();
		}
	}
}

UR_Cache_Helper::init();
