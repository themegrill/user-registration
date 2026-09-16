<?php
/**
 * Blocks controller class.
 *
 * @since 3.2.0
 *
 * @package  UserRegistration/Classes
 */

defined( 'ABSPATH' ) || exit;

/**
 * UR_AddonsClass
 */
class UR_Form_Templates {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'user-registration/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'form-templates';

	/**
	 * Register routes.
	 *
	 * @since 2.1.4
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_templates_data' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/create',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_templates' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/favorite',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_favorite_action' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/favorite_forms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_favorites' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);
	}

	/**
	 * Get Template Lists.
	 *
	 * @since 4.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_templates_data( WP_REST_Request $request ) {

		$headers      = $request->get_headers( 'cookie' );
		$url          = isset( $headers['referer'][0] ) ? $headers['referer'][0] : '';
		$parsed_url   = parse_url( $url );
		$query_string = isset( $parsed_url['query'] ) ? $parsed_url['query'] : '';
		$query_params = array();
		parse_str( $query_string, $query_params );
		if ( isset( $query_params['refresh'] ) ) {
			delete_transient( 'user_registration_templates_data' );
		}
		$template_url      = 'https://assets.wpeverest.com/wpuserregistration/forms/';
		$template_json_url = $template_url . 'templates1.json';

		$transient_key    = 'user_registration_templates_data';
		$cache_expiration = DAY_IN_SECONDS;

		$template_data = get_transient( $transient_key );

		if ( false === $template_data ) {
			try {
				$response = wp_remote_get( $template_json_url );

				if ( is_wp_error( $response ) ) {
					return new WP_Error( 'http_request_failed', __( 'Failed to fetch templates.', 'user-registration' ) );
				}

				$content_json  = wp_remote_retrieve_body( $response );
				$template_data = json_decode( $content_json );

				if ( empty( $template_data ) ) {
					return new WP_Error( 'no_templates', __( 'No templates found.', 'user-registration' ), array( 'status' => 404 ) );
				}

				set_transient( $transient_key, $template_data, $cache_expiration );
			} catch ( Exception $e ) {
				return new WP_Error( 'exception_occurred', __( 'An error occurred while fetching templates.', 'user-registration' ), array( 'status' => 500 ) );
			}
		}

		$folder_path = untrailingslashit( plugin_dir_path( UR_PLUGIN_FILE ) . '/assets/images/templates' );

		foreach ( $template_data as $templates ) {
			foreach ( $templates as $template ) {
				foreach ( $template->templates as $temp ) {
					$image_url      = isset( $temp->image ) ? $temp->image : ( $template_url . 'images/' . $temp->slug . '.png' );
					$temp->imageUrl = $image_url;

					$temp_name     = explode( '/', $image_url );
					$relative_path = $folder_path . '/' . end( $temp_name );
					$exists        = file_exists( $relative_path );

					if ( $exists ) {
						$temp->imageUrl = untrailingslashit( plugin_dir_url( UR_PLUGIN_FILE ) ) . '/assets/images/templates/' . $temp->slug . '.png';
					}

					$user_id = get_current_user_id();
					if ( $user_id ) {
						$user_favorites = get_option( 'user_registration_user_favorite_templates', array() );
						$favorite_slugs = isset( $user_favorites[ $user_id ] ) ? $user_favorites[ $user_id ] : array();

						if ( in_array( $temp->slug, $favorite_slugs ) && ! in_array( 'Favorites', $temp->categories ) ) {
							array_unshift( $temp->categories, 'Favorites' );
						}
					}
				}
			}
		}

		// New sites never see CDN templates that still carry form-level payment fields.
		if ( isset( $template_data->templates ) && function_exists( 'ur_exclude_legacy_payment_form_templates' ) ) {
			$template_data->templates = ur_exclude_legacy_payment_form_templates( $template_data->templates );
		}

		return rest_ensure_response( $template_data );
	}

	/**
	 * Create a Template.
	 *
	 * @since 3.0.3
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Request|WP_Error
	 */
	public static function create_templates( WP_REST_Request $request ) {
		// Retrieve and sanitize parameters.
		$title = sanitize_text_field( wp_unslash( $request->get_param( 'title' ) ) );
		$slug  = sanitize_text_field( wp_unslash( $request->get_param( 'slug' ) ) );

		// Check if the title parameter is empty.
		if ( empty( $title ) ) {
			return new WP_Error(
				'invalid_template_name',
				__( 'The template name is required and cannot be empty.', 'user-registration' ),
				array( 'status' => 400 )
			);
		}

		// Ensure the slug is also not empty (optional check based on your needs).
		if ( empty( $slug ) ) {
			return new WP_Error(
				'invalid_template_slug',
				__( 'The template slug is required and cannot be empty.', 'user-registration' ),
				array( 'status' => 400 )
			);
		}

		if ( function_exists( 'ur_get_legacy_payment_form_template_slugs' )
			&& in_array( $slug, ur_get_legacy_payment_form_template_slugs(), true ) ) {
			return new WP_Error(
				'legacy_payment_template_unavailable',
				__( 'This template is no longer available. Use a membership form to collect payments instead.', 'user-registration' ),
				array( 'status' => 403 )
			);
		}

		// Create the form using the title and slug.
		$form_id = UR()->form->create( $title, $slug );

		// Check if form creation was successful.
		if ( $form_id ) {
			$data = array(
				'id'       => $form_id,
				'redirect' => add_query_arg(
					array(
						'tab'     => 'fields',
						'form_id' => $form_id,
					),
					admin_url( 'admin.php?page=add-new-registration&edit-registration=' . $form_id )
				),
			);

			return new \WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} else {
			// Handle the case where form creation failed.
			return new WP_Error(
				'form_creation_failed',
				__( 'Something went wrong, please try again later.', 'user-registration' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Add or Remove templates from favourites.
	 *
	 * @since 3.0.3
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Request|WP_Error
	 */
	public static function handle_favorite_action( WP_REST_Request $request ) {

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'User not logged in.', 'user-registration' ),
				),
				401
			);
		}

		$action = $request->get_param( 'action' );
		$slug   = sanitize_text_field( $request->get_param( 'slug' ) );

		$user_favorites = get_option( 'user_registration_user_favorite_templates' );

		if ( ! is_array( $user_favorites ) ) {
			$user_favorites = array();
		}

		if ( ! isset( $user_favorites[ $user_id ] ) ) {
			$user_favorites[ $user_id ] = array();
		}

		if ( 'add_favorite' === $action ) {
			if ( ! in_array( $slug, $user_favorites[ $user_id ] ) ) {
				$user_favorites[ $user_id ][] = $slug;
			}
		} elseif ( 'remove_favorite' === $action ) {
			if ( ( $key = array_search( $slug, $user_favorites[ $user_id ] ) ) !== false ) {
				unset( $user_favorites[ $user_id ][ $key ] );
			}
		} else {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid action.', 'user-registration' ),
				),
				400
			);
		}

		update_option( 'user_registration_user_favorite_templates', $user_favorites );

		return new WP_REST_Response(
			array(
				'success'   => true,
				'favorites' => $user_favorites,
			),
			200
		);
	}

	/**
	 * Retrieves the favorite forms of user.
	 *
	 * @since 4.0
	 *
	 * @param  WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Request|WP_Error
	 */
	public function handle_get_favorites( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'User not logged in.', 'user-registration' ),
				),
				401
			);
		}
		$user_favorites = get_option( 'user_registration_user_favorite_templates' );
		if ( ! is_array( $user_favorites ) || ! isset( $user_favorites[ $user_id ] ) ) {
			return new WP_REST_Response( array(), 200 );
		}
		return new WP_REST_Response(
			array(
				'success'   => true,
				'favorites' => $user_favorites[ $user_id ],
			),
			200
		);
	}

	/**
	 * Check if a given request has access to update a setting
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public static function check_admin_permissions( $request ) {
		return current_user_can( 'manage_options' );
	}
}
