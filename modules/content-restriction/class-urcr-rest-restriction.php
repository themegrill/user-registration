<?php
/**
 * Enforce content restriction on WordPress and WooCommerce REST API responses.
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
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'restrict_woocommerce_response' ), PHP_INT_MAX, 3 );
		foreach ( array( 'product', 'product_variation' ) as $post_type ) {
			// Legacy WC controllers pass WP_Post; newer controllers pass WC_Product.
			add_filter( 'woocommerce_rest_prepare_' . $post_type, array( __CLASS__, 'restrict_response' ), PHP_INT_MAX, 3 );
			add_filter( 'woocommerce_rest_prepare_' . $post_type . '_object', array( __CLASS__, 'restrict_woocommerce_product' ), PHP_INT_MAX, 3 );
		}
	}

	/**
	 * Check WC REST products before field selection can remove their identifiers.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WC_Product       $product  WooCommerce product.
	 * @param WP_REST_Request  $request  REST request.
	 * @return WP_REST_Response
	 */
	public static function restrict_woocommerce_product( $response, $product, $request ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $response;
		}

		return self::restrict_response( $response, get_post( $product->get_id() ), $request );
	}

	/**
	 * Check a product and, for variations, its parent against content restriction.
	 *
	 * @param WP_Post $post Product post.
	 * @return bool
	 */
	private static function is_product_access_granted( $post ) {
		if ( ! function_exists( 'urcr_is_content_access_granted' ) ) {
			return true;
		}

		if ( 'product_variation' === $post->post_type && $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( $parent instanceof WP_Post && ! urcr_is_content_access_granted( $parent ) ) {
				return false;
			}
		}

		return urcr_is_content_access_granted( $post );
	}

	/**
	 * Remove restricted product objects, including nested Store API cart items.
	 *
	 * @param array $data                  REST response data.
	 * @param bool  $restricted_cart_items Whether a restricted cart item was found.
	 * @param bool  $cart_item             Whether this object is a cart item.
	 * @return array|null Null for a restricted product.
	 */
	private static function restrict_product_data( $data, &$restricted_cart_items, $cart_item = false ) {
		// Product response objects are identified by both id and sku, including an empty SKU.
		if ( isset( $data['id'] ) && array_key_exists( 'sku', $data ) ) {
			$post = get_post( $data['id'] );
			if ( $post instanceof WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
				if ( ! self::is_product_access_granted( $post ) ) {
					if ( $cart_item ) {
						$restricted_cart_items = true;
					}
					return null;
				}

				// These references can themselves include restricted variation attributes.
				foreach ( array( 'related_ids', 'upsell_ids', 'cross_sell_ids', 'grouped_products', 'variations' ) as $field ) {
					if ( ! isset( $data[ $field ] ) || ! is_array( $data[ $field ] ) ) {
						continue;
					}
					$data[ $field ] = array_values(
						array_filter(
							$data[ $field ],
							function ( $item ) {
								$related = get_post( is_array( $item ) ? ( $item['id'] ?? 0 ) : $item );
								return ! $related instanceof WP_Post || self::is_product_access_granted( $related );
							}
						)
					);
				}
			}
		}

		$is_list = array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 );
		foreach ( $data as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			$value = self::restrict_product_data( $value, $restricted_cart_items, 'items' === $key || ( $is_list && $cart_item ) );
			if ( null === $value ) {
				unset( $data[ $key ] );
			} else {
				$data[ $key ] = $value;
			}
		}

		return $is_list ? array_values( $data ) : $data;
	}

	/**
	 * Guard WooCommerce responses which bypass core rest_prepare_product filters.
	 * Runs inside dispatch(), so embedded and batch subrequests are also checked.
	 *
	 * @param mixed           $response Callback response.
	 * @param array           $handler  Matched route handler.
	 * @param WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public static function restrict_woocommerce_response( $response, $handler, $request ) {
		$route = $request->get_route();
		if ( ! preg_match( '#^/wc/store(?:/v[0-9]+)?/#', $route )
			&& ! preg_match( '#^/wc/v[0-9]+/products(?:/[0-9]+)?(?:/variations(?:/[0-9]+)?)?/?$#', $route ) ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response = rest_ensure_response( $response );
		$data     = $response->get_data();
		if ( $response->get_status() >= 400 || ( ! is_array( $data ) && ! is_object( $data ) ) ) {
			return $response;
		}

		// Store API checkout and variation responses contain nested stdClass objects.
		$data = json_decode( wp_json_encode( $data ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'urcr_rest_invalid_response', __( 'Unable to read product response.', 'user-registration' ), array( 'status' => 500 ) );
		}
		$restricted_cart_items = false;
		$restricted_data       = self::restrict_product_data( $data, $restricted_cart_items );
		// Do not return a partial cart with totals which no longer match its items.
		if ( $restricted_cart_items && preg_match( '#^/wc/store(?:/v[0-9]+)?/(?:cart|checkout)(?:/|$)#', $route ) ) {
			return new WP_Error( 'urcr_rest_product_restricted', __( 'You do not have access to a product in this cart.', 'user-registration' ), array( 'status' => 403 ) );
		}
		$data = $restricted_data;
		if ( null === $data ) {
			return new WP_Error( 'urcr_rest_product_restricted', __( 'Product not found.', 'user-registration' ), array( 'status' => 404 ) );
		}
		$response->set_data( $data );

		return $response;
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

		$is_product = in_array( $post->post_type, array( 'product', 'product_variation' ), true );
		if ( ! function_exists( 'urcr_is_content_access_granted' )
			|| ( $is_product ? self::is_product_access_granted( $post ) : urcr_is_content_access_granted( $post ) ) ) {
			return $response;
		}

		// Products have many sensitive fields; retain only the identifier in core collections.
		if ( $is_product ) {
			$response->set_data( array( 'id' => $post->ID ) );
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
