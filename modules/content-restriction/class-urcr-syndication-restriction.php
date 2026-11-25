<?php
/**
 * Apply the existing content access decision to feeds and public discovery.
 *
 * @package UserRegistrationContentRestriction/Classes
 */

defined( 'ABSPATH' ) || exit;

/** Restrict syndicated content using the shared access decision. */
class URCR_Syndication_Restriction {

	/**
	 * Nesting depth while rendering a core/latest-posts block in full-post mode.
	 *
	 * @var int
	 */
	private static $latest_posts_depth = 0;

	/** Register filters before WordPress builds feed queries. */
	public static function init() {
		add_filter( 'posts_results', array( __CLASS__, 'filter_posts' ), PHP_INT_MAX, 2 );
		add_action( 'wp', array( __CLASS__, 'filter_feed_comments' ), PHP_INT_MAX );
		add_filter( 'the_content_feed', array( __CLASS__, 'filter_feed_content' ), PHP_INT_MAX );
		add_filter( 'the_excerpt_rss', array( __CLASS__, 'filter_feed_content' ), PHP_INT_MAX );
		add_filter( 'oembed_response_data', array( __CLASS__, 'filter_oembed' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'filter_sitemap_query' ) );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_excerpt' ), PHP_INT_MAX, 2 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), PHP_INT_MAX );
		add_filter( 'pre_render_block', array( __CLASS__, 'enter_latest_posts_block' ), PHP_INT_MAX, 2 );
		add_filter( 'render_block_core/latest-posts', array( __CLASS__, 'leave_latest_posts_block' ), PHP_INT_MAX );
	}

	/**
	 * Remove inaccessible posts before a feed, search, sitemap or full-content Latest Posts block renders them.
	 *
	 * @param WP_Post[] $posts Queried posts.
	 * @param WP_Query  $query Query being filtered.
	 * @return WP_Post[]
	 */
	public static function filter_posts( $posts, $query ) {
		$is_latest_posts_query = self::$latest_posts_depth > 0 && ! $query->is_main_query();

		if ( ! $query->is_feed() && ! $query->is_embed() && ! $query->is_search() && ! $query->get( 'urcr_sitemap' ) && ! $is_latest_posts_query ) {
			return $posts;
		}
		return array_values( array_filter( $posts, 'urcr_is_content_access_granted' ) );
	}

	/** Remove comments on inaccessible posts from global and per-post feeds. */
	public static function filter_feed_comments() {
		global $wp_query;
		if ( ! is_feed() || ! $wp_query instanceof WP_Query || ! $wp_query->is_comment_feed() ) {
			return;
		}
		$wp_query->comments      = array_values(
			array_filter(
				(array) $wp_query->comments,
				function ( $comment ) {
					return urcr_is_content_access_granted( $comment->comment_post_ID );
				}
			)
		);
		$wp_query->comment_count = count( $wp_query->comments );
	}

	/**
	 * Protect direct feed content rendering when another filter adds a post.
	 *
	 * @param string $content Feed content or excerpt.
	 * @return string
	 */
	public static function filter_feed_content( $content ) {
		global $post;
		return urcr_is_content_access_granted( $post ) ? $content : '';
	}

	/**
	 * Decline oEmbed metadata for inaccessible content.
	 *
	 * @param array $data Embed response data.
	 * @param int   $post_id Embedded post ID.
	 * @return array|false
	 */
	public static function filter_oembed( $data, $post_id ) {
		return urcr_is_content_access_granted( $post_id ) ? $data : false;
	}

	/**
	 * Send sitemap posts through the same access filter.
	 *
	 * @param array $args Sitemap query arguments.
	 * @return array
	 */
	public static function filter_sitemap_query( $args ) {
		$args['urcr_sitemap'] = true;
		return $args;
	}

	/**
	 * Check whether the current visitor can read a post, cached per blog, user and post for the request.
	 *
	 * @since x.x.x
	 *
	 * @param WP_Post $post Post to check.
	 * @return bool
	 */
	private static function is_access_granted( WP_Post $post ) {
		static $granted = array();

		// Include blog ID so multisite switch_to_blog() cannot reuse another site's decision.
		$key = get_current_blog_id() . ':' . get_current_user_id() . ':' . $post->ID;

		if ( ! isset( $granted[ $key ] ) ) {
			$granted[ $key ] = urcr_is_content_access_granted( $post );
		}

		return $granted[ $key ];
	}

	/**
	 * Blank the excerpt of a post the visitor may not read, whichever query or theme block asks for it.
	 *
	 * @since x.x.x
	 *
	 * @param string           $excerpt Excerpt.
	 * @param int|WP_Post|null $post    Post the excerpt belongs to.
	 * @return string
	 */
	public static function filter_excerpt( $excerpt, $post = null ) {
		if ( '' === $excerpt ) {
			return $excerpt;
		}

		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return $excerpt;
		}

		return self::is_access_granted( $post ) ? $excerpt : '';
	}

	/**
	 * Replace the body of a restricted post when rendered outside the single main post flow.
	 *
	 * @since x.x.x
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		static $filtering = false;

		if ( '' === $content ) {
			return $content;
		}

		// Prevent re-entry if a restriction message shortcode triggers the_content again.
		if ( $filtering ) {
			return '';
		}

		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			return $content;
		}

		// The single main post is already handled by URCR_Frontend and urcr_apply_content_restriction().
		if ( is_singular() && is_main_query() && (int) get_queried_object_id() === (int) $post->ID ) {
			return $content;
		}

		if ( self::is_access_granted( $post ) ) {
			return $content;
		}

		$filtering = true;

		try {
			return self::get_restriction_message();
		} finally {
			$filtering = false;
		}
	}

	/**
	 * Track when a core/latest-posts block renders full post content, which bypasses the_content in WP 6.9+.
	 *
	 * @since x.x.x
	 *
	 * @param string|null $pre_render   Short-circuit output.
	 * @param array       $parsed_block Parsed block data.
	 * @return string|null
	 */
	public static function enter_latest_posts_block( $pre_render, $parsed_block ) {
		if ( null !== $pre_render ) {
			return $pre_render;
		}

		$attrs = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();

		if (
			isset( $parsed_block['blockName'] )
			&& 'core/latest-posts' === $parsed_block['blockName']
			&& ! empty( $attrs['displayPostContent'] )
			&& isset( $attrs['displayPostContentRadio'] )
			&& 'full_post' === $attrs['displayPostContentRadio']
		) {
			++self::$latest_posts_depth;
		}

		return $pre_render;
	}

	/**
	 * Clear the full-content Latest Posts guard once the block finishes rendering.
	 *
	 * @since x.x.x
	 *
	 * @param string $block_content Rendered block HTML.
	 * @return string
	 */
	public static function leave_latest_posts_block( $block_content ) {
		if ( self::$latest_posts_depth > 0 ) {
			--self::$latest_posts_depth;
		}

		return $block_content;
	}

	/**
	 * Build the restriction message shown when a secondary loop renders a restricted post's content.
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	private static function get_restriction_message() {
		$message = get_option( 'user_registration_content_restriction_message', '' );

		if ( empty( $message ) ) {
			if ( class_exists( 'URCR_Admin_Assets' ) ) {
				$message = URCR_Admin_Assets::get_default_message();
			} else {
				$message = esc_html__( 'This content is restricted!', 'user-registration' );
			}
		}

		$message = apply_filters( 'user_registration_process_smart_tags', $message );

		if ( function_exists( 'apply_shortcodes' ) ) {
			$message = apply_shortcodes( $message );
		} elseif ( function_exists( 'do_shortcode' ) ) {
			$message = do_shortcode( $message );
		}

		if ( function_exists( 'urcr_get_template' ) ) {
			ob_start();
			urcr_get_template(
				'base-restriction-template.php',
				array(
					'message' => $message,
				)
			);
			return ob_get_clean();
		}

		return '<div class="urcr-access-card">' . wp_kses_post( $message ) . '</div>';
	}
}
URCR_Syndication_Restriction::init();
