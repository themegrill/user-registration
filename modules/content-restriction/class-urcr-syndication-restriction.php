<?php
/**
 * Apply the existing content access decision to feeds and public discovery.
 *
 * @package UserRegistrationContentRestriction/Classes
 */
defined( 'ABSPATH' ) || exit;

class URCR_Syndication_Restriction {
	/** Register filters before WordPress builds feed queries. */
	public static function init() {
		add_filter( 'posts_results', array( __CLASS__, 'filter_posts' ), PHP_INT_MAX, 2 );
		add_action( 'wp', array( __CLASS__, 'filter_feed_comments' ), PHP_INT_MAX );
		add_filter( 'the_content_feed', array( __CLASS__, 'filter_feed_content' ), PHP_INT_MAX );
		add_filter( 'the_excerpt_rss', array( __CLASS__, 'filter_feed_content' ), PHP_INT_MAX );
		add_filter( 'oembed_response_data', array( __CLASS__, 'filter_oembed' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'filter_sitemap_query' ) );
	}

	/**
	 * Remove inaccessible posts before a feed, search or sitemap renders metadata.
	 *
	 * @param WP_Post[] $posts Queried posts.
	 * @param WP_Query  $query Query being filtered.
	 * @return WP_Post[]
	 */
	public static function filter_posts( $posts, $query ) {
		if ( ! $query->is_feed() && ! $query->is_embed() && ! $query->is_search() && ! $query->get( 'urcr_sitemap' ) ) {
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
		$wp_query->comments = array_values( array_filter( (array) $wp_query->comments, function ( $comment ) {
			return urcr_is_content_access_granted( $comment->comment_post_ID );
		} ) );
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
}
URCR_Syndication_Restriction::init();
