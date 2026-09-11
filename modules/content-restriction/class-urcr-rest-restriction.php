<?php
/**
 * Enforce content restriction on WordPress core REST API responses.
 *
 * The template based restriction flow hangs off template_redirect and
 * template_include, neither of which run during a REST request, so restricted
 * content would otherwise be served in full to anonymous requesters.
 *
 * @since 5.2.8
 *
 * @package UserRegistrationContentRestriction/Classes
 */

defined( 'ABSPATH' ) || exit;

/**
 * URCR_REST_Restriction Class
 */
class URCR_REST_Restriction {

	/**
	 * Hook in the REST response filters.
	 *
	 * @since 5.2.8
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_response_filters' ) );
	}

	/**
	 * Register a prepare filter for every publicly readable post type.
	 *
	 * @since 5.2.8
	 */
	public static function register_response_filters() {
		$post_types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);

		/**
		 * Filter the post types whose REST responses are checked against content restriction.
		 *
		 * @since 5.2.8
		 *
		 * @param array $post_types Post type names.
		 */
		$post_types = apply_filters( 'urcr_rest_restricted_post_types', $post_types );

		foreach ( (array) $post_types as $post_type ) {
			add_filter( 'rest_prepare_' . $post_type, array( __CLASS__, 'restrict_response' ), PHP_INT_MAX, 3 );
		}
	}

	/**
	 * Strip the content of a restricted post from its REST response.
	 *
	 * @since 5.2.8
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_Post          $post     Post being prepared.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function restrict_response( $response, $post, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post ) {
			return $response;
		}

		if ( ! function_exists( 'urcr_is_content_access_granted' ) || urcr_is_content_access_granted( $post ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		// Content bearing fields across posts, pages and attachments.
		foreach ( array( 'content', 'excerpt', 'description', 'caption' ) as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			$data[ $field ] = array(
				'rendered'  => '',
				'protected' => true,
			);
		}

		foreach ( array( 'source_url', 'media_details' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$data[ $field ] = is_array( $data[ $field ] ) ? array() : '';
			}
		}

		// An attachment's guid is its file URL, so it leaks the media source on its own.
		if ( 'attachment' === $post->post_type && isset( $data['guid'] ) ) {
			$data['guid'] = array( 'rendered' => '' );
		}

		/**
		 * Filter the REST response served for a restricted post.
		 *
		 * @since 5.2.8
		 *
		 * @param array           $data    Sanitized response data.
		 * @param WP_Post         $post    Restricted post.
		 * @param WP_REST_Request $request Request object.
		 */
		$data = apply_filters( 'urcr_rest_restricted_response_data', $data, $post, $request );

		$response->set_data( $data );

		return $response;
	}
}

URCR_REST_Restriction::init();
