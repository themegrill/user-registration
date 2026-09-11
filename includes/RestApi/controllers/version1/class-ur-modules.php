<?php
/**
 * Modules controller class.
 *
 * @since 3.2.0
 *
 * @package  UserRegistration/Classes
 */

defined( 'ABSPATH' ) || exit;

/**
 * UR_ModulesClass
 */
class UR_Modules {
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
	protected $rest_base = 'modules';

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
				'callback'            => array( __CLASS__, 'ur_get_modules' ),
				'permission_callback' => array( __CLASS__, 'check_admin_plugin_activation_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ur_activate_module' ),
				'permission_callback' => array( __CLASS__, 'check_admin_plugin_activation_permissions' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/deactivate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ur_deactivate_module' ),
				'permission_callback' => array( __CLASS__, 'check_admin_plugin_activation_permissions' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ur_bulk_activate_modules' ),
				'permission_callback' => array( __CLASS__, 'check_admin_plugin_activation_permissions' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-deactivate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ur_bulk_deactivate_modules' ),
				'permission_callback' => array( __CLASS__, 'check_admin_plugin_activation_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/activate-license',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'activate_license' ),
				'permission_callback' => array( __CLASS__, 'check_admin_plugin_activation_permissions' ),
			)
		);
	}

	/**
	 * Get Addons Lists.
	 *
	 * @since 3.2.0
	 *
	 * @return array Module lists.
	 */
	public static function ur_get_modules() {
		$raw_section = ur_file_get_contents( '/assets/extensions-json/all-features.json' );

		if ( is_wp_error( $raw_section ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $raw_section->get_error_message(),
				),
				400
			);
		}

		// Get Features Lists.
		$section_data = json_decode( $raw_section );

		if ( empty( $section_data->features ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => esc_html__( 'Cannot access Features. Please try again some time later.', 'user-registration' ),
				),
				400
			);
		}

		$features_lists   = $section_data->features;
		$enabled_features = get_option( 'user_registration_enabled_features', array() );

		foreach ( $features_lists as $key => $feature ) {
			if ( in_array( $feature->slug, $enabled_features, true ) ) {
				$feature->status = 'active';
			} else {
				$feature->status = 'inactive';
			}
			$feature->link = ur_utm_url(
				$feature->link,
				array(
					'source'  => 'dashboard-all-features',
					'medium'  => 'button',
					'content' => isset( $feature->slug ) ? $feature->slug : '',
				)
			);
			$feature->type = 'feature';

			if ( in_array( 'free', $feature->plan ) ) {
				$feature->required_plan = __( 'Free', 'user-registration' );
			} else {
				$feature->required_plan = __( 'Personal', 'user-registration' );
			}
			if ( 'user-registration-content-restriction' === $feature->slug && ! UR_PRO_ACTIVE ) {
				$feature->setting_url = 'admin.php?page=user-registration-settings&tab=content_restriction';
			}
			$features_lists[ $key ] = $feature;
		}

		// Get Addons Lists.
		$admin_addons = new UR_Admin_Addons();
		$addons_lists = $admin_addons->get_section_data( 'all_extensions' );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed_plugin_slugs = array_keys( get_plugins() );

		foreach ( $addons_lists as $key => $addon ) {
			$addon_file = $addon->slug . '/' . $addon->slug . '.php';
			if ( in_array( $addon_file, $installed_plugin_slugs, true ) ) {
				if ( is_plugin_active( $addon_file ) ) {
					$addon->status = 'active';
				} else {
					$addon->status = 'inactive';
				}
			} else {
				$addon->status = 'not-installed';
			}

			if ( in_array( 'free', $addon->plan ) ) {
				$addon->required_plan = __( 'Free', 'user-registration' );
			} if ( in_array( 'personal', $addon->plan ) ) {
				$addon->required_plan = __( 'Personal', 'user-registration' );
			} elseif ( in_array( 'plus', $addon->plan ) ) {
				$addon->required_plan = __( 'Plus', 'user-registration' );
			} else {
				$addon->required_plan = __( 'Professional', 'user-registration' );
			}
			$addon->link          = ur_utm_url(
				$addon->link,
				array(
					'source'  => 'dashboard-all-features',
					'medium'  => 'button',
					'content' => isset( $addon->slug ) ? $addon->slug : '',
				)
			);
			$addon->type          = 'addon';
			$addons_lists[ $key ] = $addon;
		}

		$modules_lists = array_merge( $features_lists, $addons_lists );

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'modules_lists' => $modules_lists,
			),
			200
		);
	}

	/**
	 * Active a module.
	 *
	 * @since 3.2.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public static function ur_activate_module( $request ) {

		if ( ! isset( $request['slug'] ) || empty( trim( $request['slug'] ) ) ) { //phpcs:ignore

			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => esc_html__( 'Module slug is a required field', 'user-registration' ),
				),
				400
			);
		}

		$slug = is_array( $request['slug'] ) ? current( $request['slug'] ) : $request['slug'];
		$type = isset( $request['type'] ) ? $request['type'] : '';

		$slug        = sanitize_key( wp_unslash( $request['name'] ) );
		$name        = sanitize_text_field( $request['name'] );
		$plugin_slug = wp_unslash( $request['slug'] ) . '/' . wp_unslash( $request['slug'] ) . '.php'; // phpcs:ignore
		$plugin      = plugin_basename( sanitize_text_field( $plugin_slug ) );

		$status = array();

		if ( 'addon' === $type ) {
			$status = self::ur_install_addons( $slug, $name, $plugin );
		} else {
			$status = self::ur_enable_feature( sanitize_text_field( $request['slug'] ) );
		}

		if ( isset( $status['success'] ) && ! $status['success'] ) {

			if ( isset( $status['errorMessage'] ) && ! empty( $status['errorMessage'] ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => $status['errorMessage'],
					),
					400
				);
			} else {

				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __( "Module couldn't be activated at the moment. Please try again later.", 'user-registration' ),
					),
					400
				);
			}
		} else {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Module Activated Successfully', 'user-registration' ),
				),
				200
			);
		}
	}


	/**
	 * Handler for installing or activating a addon.
	 *
	 * @since 3.2.0
	 *
	 * @param string $slug Slug of the addon to install/activate.
	 * @param string $name Name of the addon to install/activate.
	 * @param string $plugin Basename of the addon to install/activate.
	 *
	 * @see Plugin_Upgrader
	 *
	 * @global WP_Filesystem_Base $wp_filesystem Subclass
	 */
	public static function ur_install_addons( $slug, $name, $plugin ) {

		$status = array(
			'install' => 'plugin',
			'slug'    => $slug,
		);

		$status = self::ur_install_individual_addon( $slug, $plugin, $name, $status );

		return $status;
	}

	/**
	 * Enable a feature.
	 *
	 * @since 3.2.0
	 *
	 * @param string $slug Slug of the feature to enable.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public static function ur_enable_feature( $slug ) {

		// Logic to enable Feature.
		$enabled_features = get_option( 'user_registration_enabled_features', array() );

		if ( in_array( $slug, array( 'user-registration-payments', 'user-registration-stripe', 'user-registration-authorize-net' ) ) && ! in_array( 'user-registration-payment-history', $enabled_features ) ) {
			$enabled_features[] = 'user-registration-payment-history';
		}

		if ( $slug === 'user-registration-membership-groups' ) {
			$group_installation_flag = get_option( 'urm_group_module_installation_flag', false );

			if ( ! $group_installation_flag ) {
				update_option( 'urm_group_module_installation_flag', true );
			}
		}

		if ( $slug === 'user-registration-content-restriction' ) {
			update_option( 'user_registration_content_restriction_enable', true );
		}

		$enabled_features[] = $slug;
		update_option( 'user_registration_enabled_features', $enabled_features );

		/**
		 * Track module installation.
		 *
		 * @since 4.0
		 */
		do_action( 'user_registration_feature_track_data_for_tg_user_tracking', $slug );

		return array( 'success' => true );
	}

	/**
	 * Deactive a module.
	 *
	 * @since 3.2.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public static function ur_deactivate_module( $request ) {
		if ( ! isset( $request['slug'] ) || empty( trim( $request['slug'] ) ) ) { //phpcs:ignore

			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => esc_html__( 'Addon slug is a required field', 'user-registration' ),
				),
				400
			);
		}

		$slug = is_array( $request['slug'] ) ? current( $request['slug'] ) : $request['slug'];
		$type = isset( $request['type'] ) ? $request['type'] : '';

		$status = array();

		if ( 'addon' === $type ) {
			$slug   = $slug . '/' . $slug . '.php';
			$status = self::ur_deactivate_addon( $slug );
		} else {
			$status = self::ur_disable_feature( $slug );
		}

		if ( isset( $status['success'] ) && ! $status['success'] ) {

			if ( 'user-registration-multiple-registration' === $slug ) {
				$message = __( "You have multiple registration forms, so you can't deactivate the plugin.", 'user-registration' );
			} else {
				$message = __( "Module couldn't be deactivated. Please try again later.", 'user-registration' );
			}

			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $message,
				),
				400
			);
		} else {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => esc_html__( 'Module deactivated successfully', 'user-registration' ),
				),
				200
			);
		}
	}

	/**
	 * Deactive a addon.
	 *
	 * @since 3.2.0
	 *
	 * @param string $slug Slug of the addon to deactivate.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public static function ur_deactivate_addon( $slug ) {
		deactivate_plugins( $slug );
		$active_plugins = get_option( 'active_plugins', array() );

		return in_array( $slug, $active_plugins, true ) ? array( 'success' => false ) : array( 'success' => true );
	}

	/**
	 * Disable a feature.
	 *
	 * @since 3.2.0
	 *
	 * @param string $slug Slug of the feature to disable.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public static function ur_disable_feature( $slug ) {

		// Logic to disable Feature.
		$enabled_features = get_option( 'user_registration_enabled_features', array() );
		$enabled_features = array_values( array_diff( $enabled_features, array( $slug ) ) );
		if ( 'user-registration-multiple-registration' === $slug ) {
			$all_forms = ur_get_all_user_registration_form();

			if ( count( $all_forms ) > 1 ) {
				return array( 'success' => false );
			}
		}

		if ( $slug === 'user-registration-content-restriction' ) {
			update_option( 'user_registration_content_restriction_enable', false );
		}

		update_option( 'user_registration_enabled_features', $enabled_features );

		return in_array( $slug, $enabled_features, true ) ? array( 'success' => false ) : array( 'success' => true );
	}

	/**
	 * Bulk Activate modules.
	 *
	 * @since 3.2.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public static function ur_bulk_activate_modules( $request ) {

		if ( ! isset( $request['moduleData'] ) || empty( $request['moduleData'] ) ) {

			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => esc_html__( 'Please select addons to activate', 'user-registration' ),
				),
				400
			);
		}

		$feature_slugs = array();
		$addon_slugs   = array();

		foreach ( $request['moduleData'] as $slug => $addon ) {
			if ( 'addon' === $addon['type'] ) {
				array_push( $addon_slugs, $addon );
			} else {
				$feature_slugs[ $slug ] = $addon['name'];
			}
		}

		$failed_modules = array();

		if ( ! empty( $addon_slugs ) ) {
			$failed_modules = array_merge( $failed_modules, self::ur_bulk_install_addons( $addon_slugs ) );
		}

		if ( ! empty( $feature_slugs ) ) {
			$failed_modules = array_merge( $failed_modules, self::ur_bulk_enable_feature( $feature_slugs ) );
		}

		if ( count( $failed_modules ) > 0 ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					/* translators: 1: Failed Addon Names */
					'message' => sprintf( __( '%1$s activation failed. Please try again sometime later.', 'user-registration' ), implode( ', ', $failed_modules ) ),
				),
				400
			);
		} else {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'All of the selected modules have been activated successfully.', 'user-registration' ),
				),
				200
			);
		}
	}

	/**
	 * Handler for installing bulk extension.
	 *
	 * @since 1.2.0
	 *
	 * @param array $addon_data Datas of addons to activate.
	 *
	 * @see Plugin_Upgrader
	 *
	 * @global WP_Filesystem_Base $wp_filesystem Subclass
	 */
	public static function ur_bulk_install_addons( $addon_data ) {

		$failed_addon = array();

		foreach ( $addon_data as $addon ) {
			$slug        = isset( $addon['name'] ) ? sanitize_key( wp_unslash( $addon['name'] ) ) : '';
			$plugin_slug = isset( $addon['slug'] ) ? sanitize_text_field( $addon['slug'] ) : '';
			$name        = isset( $addon['name'] ) ? sanitize_text_field( $addon['name'] ) : '';
			$plugin      = plugin_basename( sanitize_text_field( $plugin_slug ) );
			$status      = array(
				'install' => 'plugin',
				'slug'    => $slug,
			);
			$status      = self::ur_install_individual_addon( $slug, $plugin, $name, $status );

			if ( isset( $status['success'] ) && '' === $status['success'] ) {
				array_push( $failed_addon, $name );
				continue;
			}
		}

		return $failed_addon;
	}

	/**
	 * Bulk enable features.
	 *
	 * @since 3.2.0
	 *
	 * @param array $feature_data Data of the features to enable.
	 */
	public static function ur_bulk_enable_feature( $feature_data ) {
		$failed_to_enable = array(); // Add Names of failed feature enable process.

		// Logic to enable Feature.
		$enabled_features = get_option( 'user_registration_enabled_features', array() );

		foreach ( $feature_data as $slug => $name ) {
			array_push( $enabled_features, $slug );

			if ( $slug === 'user-registration-content-restriction' ) {
				update_option( 'user_registration_content_restriction_enable', true );
			}
		}

		update_option( 'user_registration_enabled_features', $enabled_features );

		return $failed_to_enable;
	}

	/**
	 * Bulk Deactivate Modules.
	 *
	 * @since 3.2.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public static function ur_bulk_deactivate_modules( $request ) {

		if ( ! isset( $request['moduleData'] ) || empty( $request['moduleData'] ) ) {

			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => esc_html__( 'Please select a module to deactivate', 'user-registration' ),
				),
				400
			);
		}

		$feature_slugs = array();
		$addon_slugs   = array();

		foreach ( $request['moduleData'] as $slug => $module ) {
			if ( isset( $module['type'] ) && 'addon' === $module['type'] ) {
				array_push( $addon_slugs, $module['slug'] );
			} else {
				array_push( $feature_slugs, $slug );
			}
		}

		$deactivated_count = 0;

		if ( ! empty( $addon_slugs ) ) {
			$deactivated_count += count( self::ur_bulk_deactivate_addon( $addon_slugs ) );
		}

		if ( ! empty( $feature_slugs ) ) {
			$deactivated_count += self::ur_bulk_disable_feature( $feature_slugs );
		}

		if ( count( $request['moduleData'] ) === $deactivated_count ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => esc_html__( 'All of the selected modules have been deactivated.', 'user-registration' ),
				),
				200
			);
		} else {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => esc_html__( 'Some of the selected modules may not have been deactivated. Please try again later', 'user-registration' ),
				),
				400
			);
		}
	}

	/**
	 * Bulk Deactivate addons.
	 *
	 * @since 3.2.0
	 *
	 * @param array $addon_slugs Slugs of the addons to deactivate.
	 */
	public static function ur_bulk_deactivate_addon( $addon_slugs ) {

		deactivate_plugins( $addon_slugs );

		$active_plugins = get_option( 'active_plugins', array() );

		return array_diff( $addon_slugs, $active_plugins );
	}

	/**
	 * Bulk disable features.
	 *
	 * @since 3.2.0
	 *
	 * @param array $feature_slugs Slugs of the features to disable.
	 */
	public static function ur_bulk_disable_feature( $feature_slugs ) {

		// Logic to enable Feature.
		$enabled_features = get_option( 'user_registration_enabled_features', array() );
		$enabled_features = array_values( array_diff( $enabled_features, $feature_slugs ) );

		if ( in_array( 'user-registration-content-restriction', $feature_slugs, true ) ) {
			update_option( 'user_registration_content_restriction_enable', false );
		}

		update_option( 'user_registration_enabled_features', $enabled_features );

		return count( $feature_slugs );
	}

	/**
	 * Handler for installing a extension.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Slug of the addon to install.
	 * @param string $plugin Plugin file of the addon to install.
	 * @param string $name Name of the addon to install.
	 * @param array  $status Staus array to track addon installation status.
	 *
	 * @see Plugin_Upgrader
	 *
	 * @global WP_Filesystem_Base $wp_filesystem Subclass
	 */
	public static function ur_install_individual_addon( $slug, $plugin, $name, $status ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			$plugin_data          = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
			$status['plugin']     = $plugin;
			$status['pluginName'] = $plugin_data['Name'];

			if ( is_plugin_inactive( $plugin ) ) {
				$enabled_features = get_option( 'user_registration_enabled_features', array() );

				$result = activate_plugin( $plugin );

				if ( is_wp_error( $result ) ) {
					$status['errorCode']    = $result->get_error_code();
					$status['errorMessage'] = $result->get_error_message();
					$status['success']      = false;
					return $status;
				}

				if ( in_array( $slug, array( 'userregistrationstripe', 'userregistrationauthorizenet' ) ) && ! in_array( 'user-registration-payment-history', $enabled_features ) ) {
					$enabled_features[] = 'user-registration-payment-history';
					update_option( 'user_registration_enabled_features', array_unique( $enabled_features ) );
				}
				$status['success'] = true;
				$status['message'] = __( 'Addons activated successfully', 'user-registration' );
				return $status;
			}
		}

		$api = json_decode(
			UR_Updater_Key_API::version(
				array(
					'license'   => get_option( 'user-registration_license_key' ),
					'item_name' => ! empty( $name ) ? sanitize_text_field( wp_unslash( $name ) ) : '',
				)
			)
		);

		if ( is_wp_error( $api ) ) {
			$status['success']      = false;
			$status['errorMessage'] = $api['msg'];
			return $status;
		} elseif ( empty( $api ) ) {
			$status['success']      = false;
			$status['errorMessage'] = __( 'Couldn\'t fetch addon data at the moment. Please try again later', 'user-registration' );
			return $status;
		}

		$status['pluginName'] = $api->name;

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$status['debug'] = $skin->get_upgrade_messages();
		}

		if ( is_wp_error( $result ) ) {
			$status['success']      = false;
			$status['errorCode']    = $result->get_error_code();
			$status['errorMessage'] = $result->get_error_message();
			return $status;
		} elseif ( is_wp_error( $skin->result ) ) {
			$status['success']      = false;
			$status['errorCode']    = $skin->result->get_error_code();
			$status['errorMessage'] = $skin->result->get_error_message();
			return $status;
		} elseif ( $skin->get_errors()->get_error_code() ) {
			$status['success']      = false;
			$status['errorMessage'] = $skin->get_error_messages();
			return $status;
		} elseif ( is_null( $result ) ) {
			global $wp_filesystem;
			$status['success']      = false;
			$status['errorCode']    = 'unable_to_connect_to_filesystem';
			$status['errorMessage'] = esc_html__( 'Unable to connect to the filesystem. Please confirm your credentials.', 'user-registration' );

			// Pass through the error from WP_Filesystem if one was raised.
			if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->get_error_code() ) {
				$status['errorMessage'] = esc_html( $wp_filesystem->errors->get_error_message() );
			}
			return $status;
		}

		$api->version   = isset( $api->new_version ) ? $api->new_version : '';
		$install_status = install_plugin_install_status( $api );
		activate_plugin( $plugin );
		$enabled_features = get_option( 'user_registration_enabled_features', array() );

		if ( in_array( $slug, array( 'userregistrationstripe', 'userregistrationauthorizenet' ) ) && ! in_array( 'user-registration-payment-history', $enabled_features ) ) {
			$enabled_features[] = 'user-registration-payment-history';
			update_option( 'user_registration_enabled_features', $enabled_features );
		}

		$status['success'] = true;
		$status['message'] = __( 'Addon installed Successfully', 'user-registration' );
		return $status;
	}

	/**
	 * Check if a given request has access to update a setting
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public static function check_admin_plugin_activation_permissions( $request ) {
		return current_user_can( 'activate_plugin' );
	}

	/**
	 * Check if a given request has access to update a setting
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public static function check_admin_plugin_installation_permissions( $request ) {
		return current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugin' );
	}


	/**
	 * Activate the plugin license.
	 *
	 * @since 3.3.2
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public static function activate_license( $request ) {
		if ( isset( $request['licenseActivationKey'] ) ) {
			$user_registration_updater   = new UR_Plugin_Updater();
			$user_registration_activator = $user_registration_updater->activate_license( $request['licenseActivationKey'] );

			if ( isset( $user_registration_activator ) && $user_registration_activator ) {
				return new \WP_REST_Response(
					array(
						'status'  => true,
						'message' => esc_html__( 'User Registration & Membership Pro activated successfully.', 'user-registration' ),
						'code'    => 200,
					),
					200
				);
			} else {
				return new \WP_REST_Response(
					array(
						'status'  => true,
						'message' => esc_html__( 'Please enter the valid license key.', 'user-registration' ),
						'code'    => 400,
					),
					200
				);
			}
		}
	}
}
