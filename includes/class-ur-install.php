<?php

/**
 * Installation related functions and actions.
 *
 * @package UserRegistration\Classes
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'UR_Install' ) ) {
	return;
}
/**
 * UR_Install Class.
 */
class UR_Install {


	/**
	 * DB updates and callbacks that need to be run per version.
	 *
	 * @var array
	 */
	private static $db_updates = array(
		'1.0.0'   => array(
			'ur_update_100_db_version',
		),
		'1.2.0'   => array(
			'ur_update_120_usermeta',
			'ur_update_120_db_version',
		),
		'1.3.0'   => array(
			'ur_update_130_db_version',
			'ur_update_130_post',
		),
		'1.4.0'   => array(
			'ur_update_140_db_version',
			'ur_update_140_option',
		),
		'1.4.2'   => array(
			'ur_update_142_db_version',
			'ur_update_142_option',
		),
		'1.5.8.1' => array(
			'ur_update_1581_db_version',
			'ur_update_1581_meta_key',
		),
		'1.6.0'   => array(
			'ur_update_160_db_version',
			'ur_update_160_option_migrate',
		),
		'1.6.2'   => array(
			'ur_update_162_db_version',
			'ur_update_162_meta_key',
		),
		'5.2.9'   => array(
			'ur_update_529_legacy_payment_fields_flag',
			'ur_update_529_db_version',
		),
	);

	/**
	 * Background update class.
	 *
	 * @var object
	 */
	private static $background_updater;

	/**
	 * Hook in tabs.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'init', array( __CLASS__, 'init_background_updater' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'install_actions' ) );
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'wpmu_drop_tables' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
	}

	/**
	 * Init background updates.
	 */
	public static function init_background_updater() {
		include_once __DIR__ . '/class-ur-background-updater.php';
		self::$background_updater = new UR_Background_Updater();
	}

	/**
	 * Check UserRegistration version and run the updater is required.
	 *
	 * This check is done on all requests and runs if the versions do not match.
	 */
	public static function check_version() {
		if ( ! defined( 'IFRAME_REQUEST' ) && version_compare( get_option( 'user_registration_version' ), UR()->version, '<' ) ) {
			self::install();
			/**
			 * Fires an action hook after updating the User Registration plugin to a new version.
			 */
			do_action( 'user_registration_updated' );
		}
	}

	/**
	 * Install actions when a update button is clicked within the admin area.
	 *
	 * This function is hooked into admin_init to affect admin only.
	 */
	public static function install_actions() {
		if ( ! empty( $_GET['do_update_user_registration'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification
			self::update();
			UR_Admin_Notices::add_notice( 'update' );
		}
		if ( ! empty( $_GET['force_update_user_registration'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification
			/**
			 * Fires an action hook to initiate a forced update for User Registration via admin area.
			 *
			 * This action hook, 'wp_{blog_id}_ur_updater_cron', is triggered when the 'force_update_user_registration'
			 */
			do_action( 'wp_' . get_current_blog_id() . '_ur_updater_cron' );
			wp_safe_redirect( admin_url( 'admin.php?page=user-registration-settings' ) );
			exit;
		}
	}

	/**
	 * Install UR.
	 */
	public static function install() {
		if ( ! is_blog_installed() ) {
			return;
		}

		// Check if we are not already running this routine.
		if ( 'yes' === get_transient( 'ur_installing' ) ) {
			return;
		}

		// If we made it till here nothing is running yet, lets set the transient now.
		set_transient( 'ur_installing', 'yes', MINUTE_IN_SECONDS * 10 );
		ur_maybe_define_constant( 'UR_INSTALLING', true );

		self::remove_admin_notices();
		self::create_options();
		self::create_tables();
		self::create_roles();
		self::setup_environment();

		$hasposts = get_posts( 'post_type=user_registration' );

		if ( 0 === count( $hasposts ) ) {
			update_option( 'user_registration_first_time_activation_flag', true );
		}
		$first_time_flag = get_option( 'user_registration_first_time_activation_flag', null );

		if (
		null === get_option( 'urm_is_new_installation', null )
		&& null !== $first_time_flag
		) {
			add_option( 'urm_is_new_installation', 1 );
		}

		if ( null === get_option( 'urm_is_legacy_paypal_user', null ) ) {
			$has_legacy_paypal_data = '' !== get_option( 'user_registration_global_paypal_email_address', '' )
				|| '' !== get_option( 'user_registration_global_paypal_test_email_address', '' )
				|| '' !== get_option( 'user_registration_global_paypal_live_email_address', '' )
				|| '' !== get_option( 'user_registration_global_paypal_cancel_url', '' )
				|| '' !== get_option( 'user_registration_global_paypal_return_url', '' );
			add_option( 'urm_is_legacy_paypal_user', $has_legacy_paypal_data ? 1 : 0 );
		}

		if ( null === get_option( 'urm_is_legacy_payment_fields_user', null ) ) {
			add_option( 'urm_is_legacy_payment_fields_user', ur_site_has_any_frozen_payment_field() ? 1 : 0 );
		}

		if ( null === get_option( 'urm_is_legacy_ecommerce_addons_user', null ) ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$has_legacy_ecommerce_addons = ur_check_module_activation( 'stripe' )
				|| ur_check_module_activation( 'payments' )
				|| is_plugin_active( 'user-registration-stripe/user-registration-stripe.php' );
			add_option( 'urm_is_legacy_ecommerce_addons_user', $has_legacy_ecommerce_addons ? 1 : 0 );
		}

		self::create_files();
		self::update_ur_version();
		self::maybe_update_db_version();
		self::maybe_add_installation_date();
		self::maybe_run_migrations();

		$path = UR_UPLOAD_PATH . 'profile-pictures';

		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0777, true );
		}

		delete_transient( 'ur_installing' );
		/**
		 * Fires an action hook to flush rewrite rules after User Registration plugin activation or settings update.
		 *
		 * The 'user_registration_flush_rewrite_rules' action is triggered to ensure that any changes
		 * tasks or actions when rewrite rules need to be flushed.
		 */
		do_action( 'user_registration_flush_rewrite_rules' );
		/**
		 * Fires an action hook after the User Registration plugin has been successfully installed or updated.
		 *
		 * The 'user_registration_installed' action allows developers to execute custom code or tasks
		 * after the installation or update of the User Registration plugin is completed.
		 */
		do_action( 'user_registration_installed' );
		set_transient( '_ur_activation_redirect', 1, 30 );
	}

	/**
	 * Reset any notices added to admin.
	 *
	 * @since 1.2.0
	 */
	private static function remove_admin_notices() {
		include_once __DIR__ . '/admin/notifications/class-ur-admin-notices.php';
		UR_Admin_Notices::remove_all_notices();
	}

	/**
	 * Setup UR environment - post types, taxonomies, endpoints.
	 *
	 * @since 1.2.0
	 */
	private static function setup_environment() {
		UR_Post_Types::register_post_types();
		UR()->query->init_query_vars();
		UR()->query->add_endpoints();
	}

	/**
	 * Is this a brand new UR install?
	 *
	 * @since  1.2.0
	 * @return boolean
	 */
	private static function is_new_install() {
		return is_null( get_option( 'user_registration_version', null ) ) && is_null( get_option( 'user_registration_db_version', null ) );
	}

	/**
	 * Is a DB update needed?
	 *
	 * @since  1.2.0
	 * @return boolean
	 */
	private static function needs_db_update() {
		$current_db_version = get_option( 'user_registration_db_version', null );
		$updates            = self::get_db_update_callbacks();

		return ! is_null( $current_db_version ) && version_compare( $current_db_version, max( array_keys( $updates ) ), '<' );
	}

	/**
	 * See if we need the wizard or not.
	 */
	private static function maybe_enable_setup_wizard() {
		/**
		 * Applies a filter to determine whether to enable the setup wizard for User Registration.
		 *
		 * The 'user_registration_enable_setup_wizard' filter allows developers to control
		 * whether the setup wizard should be enabled based on certain conditions.
		 *
		 * @param bool $default_value The default value indicating whether it's a new installation, obtained using self::is_new_install().
		 */
		if ( apply_filters( 'user_registration_enable_setup_wizard', self::is_new_install() ) ) {
			UR_Admin_Notices::add_notice( 'install' );
		}
	}

	/**
	 * See if we need to show or run database updates during install.
	 *
	 * @since 1.2.0
	 */
	private static function maybe_update_db_version() {
		if ( self::needs_db_update() ) {
			/**
			 * Checks if database updates are needed during installation and takes appropriate actions.
			 */
			if ( apply_filters( 'user_registration_enable_auto_update_db', false ) ) {
				self::init_background_updater();
				self::update();
			} else {
				UR_Admin_Notices::add_notice( 'update' );
			}
		} else {
			self::update_db_version();
		}
	}


	/**
	 * Add more cron schedules.
	 *
	 * @param  array $schedules List of WP scheduled cron jobs.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['monthly'] = array(
			'interval' => 2635200,
			'display'  => __( 'Monthly', 'user-registration' ),
		);
		return $schedules;
	}

	/**
	 * Create cron jobs (clear them first).
	 */
	private static function create_cron_jobs() {
		wp_clear_scheduled_hook( 'user_registration_cleanup_logs' );
		wp_clear_scheduled_hook( 'user_registration_cleanup_sessions' );
		wp_schedule_event( time() + ( 3 * HOUR_IN_SECONDS ), 'daily', 'user_registration_cleanup_logs' );
		wp_schedule_event( time() + ( 6 * HOUR_IN_SECONDS ), 'twicedaily', 'user_registration_cleanup_sessions' );
	}



	/**
	 * May be add installation date. Donot insert on every update.
	 *
	 * @since 1.5.8
	 */
	private static function maybe_add_installation_date() {

		$activated_date = get_option( 'user_registration_activated' );
		$installed_date = get_option( 'user_registration_installation_date' );

		if ( empty( $activated_date ) ) {
			update_option( 'user_registration_activated', current_time( 'Y-m-d' ) );
			update_option( 'user_registration_updated_at', current_time( 'Y-m-d' ) );
		} else {
			update_option( 'user_registration_updated_at', current_time( 'Y-m-d' ) );
		}

		if ( empty( $installed_date ) ) {
			update_option( 'user_registration_installation_date', current_time( 'Y-m-d' ) );
		}
	}


	/**
	 * Run Migrations.
	 *
	 * @throws Exception If callable function not defined.
	 *
	 * @return void
	 */
	public static function maybe_run_migrations() {

		include_once 'functions-ur-update.php';

		// Migrations for User Registration ( Free ).
		$migration_updates = array(
			'3.0'   => array(
				'ur_update_30_option_migrate',
			),
			'3.2.2' => array(
				'ur_update_322_option_migrate',
			),
			'5.0'   => array(
				'urm_update_50_option_migrate',
			),
			'5.1.5' => array(
				'ur_update_515_redirect_thank_you_page_migrate',
			),
		);

		if ( defined( 'UR_PRO_ACTIVE' ) && UR_PRO_ACTIVE ) {
			// Migrations for User Registration ( Pro ).
			$migration_updates = array(
				'4.0'   => array(
					'ur_update_30_option_migrate',
					'ur_pro_update_40_option_migrate',
				),
				'4.2.0' => array(
					'ur_pro_module_addons_migrate',
				),
				'6.0'   => array(
					'urm_update_50_option_migrate',
				),
			);
		}

		$current_migration_version = get_option( 'user_registration_migration_version', null );
		$current_migration_version = ! is_null( $current_migration_version ) ? $current_migration_version : '2.3.4';

		foreach ( $migration_updates as $version => $update_callbacks ) {
			if ( version_compare( $current_migration_version, $version, '<' ) ) {
				foreach ( $update_callbacks as $update_callback ) {
					try {
						if ( function_exists( $update_callback ) ) {
							if ( is_callable( $update_callback ) ) {
								call_user_func( $update_callback );
							}
						} else {
							throw new Exception( 'Migration function ' . $update_callback . '() not found.' );
						}
					} catch ( Exception $e ) {
						ur_get_logger()->debug( $e->getMessage() );
					}
				}
				update_option( 'user_registration_migration_version', $version );
			}
		}
	}

	/**
	 * Update UR version to current.
	 */
	private static function update_ur_version() {
		delete_option( 'user_registration_version' );
		add_option( 'user_registration_version', UR()->version );
	}

	/**
	 * Get list of DB update callbacks.
	 *
	 * @since  1.2.0
	 * @return array
	 */
	public static function get_db_update_callbacks() {
		$updates            = self::$db_updates;
		$current_db_version = get_option( 'user_registration_db_version' );

		$db_needs_update = array( '1.2.2', '1.2.3', '1.2.4' );

		if ( in_array( $current_db_version, $db_needs_update ) ) {
			$updates['1.2.5'] = array(
				'ur_update_125_usermeta',
				'ur_update_125_db_version',
			);
		}
		return $updates;
	}

	/**
	 * Push all needed DB updates to the queue for processing.
	 */
	private static function update() {
		$current_db_version = get_option( 'user_registration_db_version' );
		$update_queued      = false;

		foreach ( self::get_db_update_callbacks() as $version => $update_callbacks ) {
			if ( version_compare( $current_db_version, $version, '<' ) ) {
				foreach ( $update_callbacks as $update_callback ) {
					self::$background_updater->push_to_queue( $update_callback );
					$update_queued = true;
				}
			}
		}

		if ( $update_queued ) {
			self::$background_updater->save()->dispatch();
		}
	}

	/**
	 * Update DB version to current.
	 *
	 * @param string|null $version New UserRegistration DB version or null.
	 */
	public static function update_db_version( $version = null ) {
		delete_option( 'user_registration_db_version' );
		add_option( 'user_registration_db_version', is_null( $version ) ? UR()->version : $version );
	}

	/**
	 * Create pages that the plugin relies on, storing page IDs in variables.
	 */
	public static function create_pages() {
		include_once __DIR__ . '/admin/functions-ur-admin.php';
		/**
		 * Creates and configures pages related with customizable content.
		 *
		 * The 'user_registration_create_pages' filter allows developers to customize the pages
		 * created during the setup process. By default, it includes a 'My Account' page with the
		 * 'user_registration_my_account' shortcode, and if a default registration form page is set,
		 * it includes a 'Registration' page with the 'user_registration_form' shortcode.
		 *
		 * Developers can customize the page structure and content using this filter.
		 */
		$pages = apply_filters(
			'user_registration_create_pages',
			array(
				'myaccount' => array(
					'name'    => _x( 'my-account', 'Page slug', 'user-registration' ),
					'title'   => _x( 'My Account', 'Page title', 'user-registration' ),
					'content' => '[' . apply_filters( 'user_registration_my_account_shortcode_tag', 'user_registration_my_account' ) . ']',
				),
			)
		);

		$default_form_page_id = get_option( 'user_registration_default_form_page_id' );

		if ( $default_form_page_id ) {
			$pages['registration'] = array(
				'name'    => _x( 'registration', 'Page slug', 'user-registration' ),
				'title'   => _x( 'Registration', 'Page title', 'user-registration' ),
				/**
				 * Applies a filter to customize the shortcode tag used for the User Registration form.
				 *
				 * The 'user_registration_form_shortcode_tag' filter allows developers to modify
				 * the default shortcode tag ('user_registration_form') used to render the registration form.
				 * Developers can use this filter to change the tag or add additional attributes to the form shortcode.
				 *
				 * @param string $default_shortcode_tag The default shortcode tag for the User Registration form.
				 */
				'content' => '[' . apply_filters( 'user_registration_form_shortcode_tag', 'user_registration_form' ) . ' id="' . esc_attr( $default_form_page_id ) . '"]',
			);
		}

		foreach ( $pages as $key => $page ) {
			ur_create_page( esc_sql( $page['name'] ), 'user_registration_' . $key . '_page_id', wp_kses_post( ( $page['title'] ) ), wp_kses_post( $page['content'] ) );
		}
	}

	/**
	 * Default options.
	 *
	 * Sets up the default options used on the settings page.
	 */
	private static function create_options() {
		// Include settings so that we can run through defaults.
		include_once __DIR__ . '/admin/class-ur-admin-settings.php';

		$settings = UR_Admin_Settings::get_settings_pages();

		if ( ! empty( $settings ) ) {
			foreach ( $settings as $section ) {
				if ( ! method_exists( $section, 'get_settings' ) ) {
					continue;
				}
				$subsections = array_unique( array_merge( array( '' ), array_keys( $section->get_sections() ) ) );

				foreach ( $subsections as $subsection ) {
					$subsection_settings = $section->get_settings( $subsection );
					if ( ! empty( $subsection_settings ) ) {
						foreach ( $section->get_settings( $subsection ) as $value ) {
							if ( isset( $value['default'] ) && isset( $value['id'] ) ) {
								$autoload = isset( $value['autoload'] ) ? (bool) $value['autoload'] : true;
								add_option( $value['id'], $value['default'], '', ( $autoload ? 'yes' : 'no' ) );
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Create form on first install.
	 */
	public static function create_form() {
		$hasposts = get_posts( 'post_type=user_registration' );

		if ( 0 === count( $hasposts ) ) {
			update_option( 'user_registration_first_time_activation_flag', true );
			$post_content = '[[[{"field_key":"user_login","general_setting":{"label":"Username","description":"","field_name":"user_login","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":"","username_length":"","username_character":"1"},"icon":"ur-icon ur-icon-user"}],[{"field_key":"user_email","general_setting":{"label":"User Email","description":"","field_name":"user_email","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-email"}]],[[{"field_key":"user_pass","general_setting":{"label":"User Password","description":"","field_name":"user_pass","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password"}],[{"field_key":"user_confirm_password","general_setting":{"label":"Confirm Password","description":"","field_name":"user_confirm_password","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password-confirm"}]]]';

			// Insert default form.
			$default_post_id = wp_insert_post(
				array(
					'post_type'      => 'user_registration',
					'post_title'     => esc_html__( 'Registration form', 'user-registration' ),
					'post_content'   => $post_content,
					'post_status'    => 'publish',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			);

			update_option( 'user_registration_default_form_page_id', $default_post_id );
		}
	}

	/**
	 * Set up the database tables which the plugin needs to function.
	 *
	 * When adding or removing a table, make sure to update the list of tables in UR_Install::get_tables().
	 *
	 * Tables:
	 *        user_registration_sessions - Table for storing sessions data.
	 */
	private static function create_tables() {
		global $wpdb;

		$wpdb->hide_errors();

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "
CREATE TABLE {$wpdb->prefix}user_registration_sessions (
  session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_key char(32) NOT NULL,
  session_value longtext NOT NULL,
  session_expiry BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY  (session_key),
  UNIQUE KEY session_id (session_id)
) $collate;
		";

		dbDelta( $sql );
	}

	/**
	 * Return a list of UserRegistration tables. Used to make sure all UR tables are dropped when uninstalling the plugin
	 * in a single site or multi site environment.
	 *
	 * @return array UR tables.
	 */
	public static function get_tables() {
		global $wpdb;
		$prefix = $wpdb->prefix;
		if ( is_multisite() ) {
			$prefix = $wpdb->base_prefix;
		}

		$tables = array(
			"{$prefix}user_registration_sessions",
		);

		return $tables;
	}

	/**
	 * Drop UsageMonitor tables.
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = self::get_tables();

		foreach ( $tables as $table ) {
			$wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore
		}
	}

	/**
	 * Uninstall tables when MU blog is deleted.
	 *
	 * @param  array $tables List of tables that will be deleted by WP.
	 * @return string[]
	 */
	public static function wpmu_drop_tables( $tables ) {
		return array_merge( $tables, self::get_tables() );
	}

	/**
	 * Create roles and capabilities.
	 */
	public static function create_roles() {
		global $wp_roles;

		if ( ! class_exists( 'WP_Roles' ) ) {
			return;
		}

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles(); // @codingStandardsIgnoreLine
		}

		$capabilities = self::get_core_capabilities();

		foreach ( $capabilities as $cap_group ) {
			foreach ( $cap_group as $cap ) {
				$wp_roles->add_cap( 'administrator', $cap );
			}
		}
	}

	/**
	 * Get capabilities for UserRegistration.
	 *
	 * @return array
	 */
	private static function get_core_capabilities() {
		$capabilities = array();

		$capabilities['core'] = array(
			'manage_user_registration',
		);

		$capability_types = array( 'user_registration' );

		foreach ( $capability_types as $capability_type ) {

			$capabilities[ $capability_type ] = array(
				// Post type.
				"edit_{$capability_type}",
				"read_{$capability_type}",
				"delete_{$capability_type}",
				"edit_{$capability_type}s",
				"edit_others_{$capability_type}s",
				"publish_{$capability_type}s",
				"read_private_{$capability_type}s",
				"delete_{$capability_type}s",
				"delete_private_{$capability_type}s",
				"delete_published_{$capability_type}s",
				"delete_others_{$capability_type}s",
				"edit_private_{$capability_type}s",
				"edit_published_{$capability_type}s",

				// Terms.
				"manage_{$capability_type}_terms",
				"edit_{$capability_type}_terms",
				"delete_{$capability_type}_terms",
				"assign_{$capability_type}_terms",
			);
		}

		return $capabilities;
	}

	/**
	 * Remove UserRegistration roles.
	 */
	public static function remove_roles() {
		global $wp_roles;

		if ( ! class_exists( 'WP_Roles' ) ) {
			return;
		}

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles(); // phpcs:ignore
		}

		$capabilities = self::get_core_capabilities();

		foreach ( $capabilities as $cap_group ) {
			foreach ( $cap_group as $cap ) {
				$wp_roles->remove_cap( 'administrator', $cap );
			}
		}
	}

	/**
	 * Create files/directories.
	 */
	private static function create_files() {
		// Bypass if filesystem is read-only and/or non-standard upload system is used.
		if ( apply_filters( 'user_registration_install_skip_create_files', false ) ) {
			return;
		}

		// Install files and folders for uploading files and prevent hotlinking.
		$upload_dir = wp_upload_dir();

		$files = array(
			array(
				'base'    => UR_LOG_DIR,
				'file'    => '.htaccess',
				'content' => 'deny from all',
			),
			array(
				'base'    => UR_LOG_DIR,
				'file'    => 'index.html',
				'content' => '',
			),
		);

		foreach ( $files as $file ) {
			if ( wp_mkdir_p( $file['base'] ) && ! file_exists( trailingslashit( $file['base'] ) . $file['file'] ) ) {
				$file_handle = @fopen( trailingslashit( $file['base'] ) . $file['file'], 'w' );

				if ( $file_handle ) {
					fwrite( $file_handle, $file['content'] );
					fclose( $file_handle );
				}
			}
		}
	}

	/**
	 * create_membership_form
	 *
	 * @param $group_id
	 *
	 * @return int|void|WP_Error
	 */
	public static function create_membership_form( $group_id ) {
		$membership_repository = new \WPEverest\URMembership\Admin\Repositories\MembershipRepository();
		$has_posts             = $membership_repository->get_membership_forms();
		$membership_field_name = 'membership_field_' . ur_get_random_number();
		update_option( 'ur_membership_default_membership_field_name', $membership_field_name );

		if ( 0 === count( $has_posts ) ) {
			$post_content = '[[[{"field_key":"user_login","general_setting":{"label":"Username","description":"","field_name":"user_login","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":"","username_length":"","username_character":"1"},"icon":"ur-icon ur-icon-user"}],[{"field_key":"user_email","general_setting":{"label":"User Email","description":"","field_name":"user_email","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-email"}]],[[{"field_key":"user_pass","general_setting":{"label":"User Password","description":"","field_name":"user_pass","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password"}],[{"field_key":"user_confirm_password","general_setting":{"label":"Confirm Password","description":"","field_name":"user_confirm_password","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password-confirm"}]],[[{"field_key":"membership","general_setting":{"membership_group":"' . $group_id . '","label":"Membership Field","description":"","field_name":"' . $membership_field_name . '","hide_label":"false","membership_listing_option":"all"},"advance_setting":{},"icon":"ur-icon ur-icon-membership-field"}]]]';
			// Insert default form.
			$default_post_id = wp_insert_post(
				array(
					'post_type'      => 'user_registration',
					'post_title'     => esc_html__( 'Registration form', 'user-registration' ),
					'post_content'   => $post_content,
					'post_status'    => 'publish',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			);

			update_option( 'user_registration_default_membership_form_id', $default_post_id );
			return $default_post_id;
		}
	}

	public static function create_default_membership() {
		$post_content          = '{"description":"Default membership.","type":"free","status":true}';
		$default_membership_id = wp_insert_post(
			array(
				'post_type'      => 'ur_membership',
				'post_title'     => esc_html__( 'Default Membership', 'user-registration' ),
				'post_content'   => $post_content,
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		update_post_meta( $default_membership_id, 'ur_membership', '{"type":"free","cancel_subscription":"immediately","role":"subscriber","amount":0}' );
		return $default_membership_id;
	}
	public static function create_default_membership_group( $memberships ) {
		$membership_ids = array_column( $memberships, 'ID' );

		$post_content             = '{"description":"","status":true}';
		$membership_group_service = new \WPEverest\URMembership\Admin\Services\MembershipGroupService();
		$default_post_id          = $membership_group_service->get_default_group_id();

		if ( ! empty( $default_post_id ) ) {
			return $default_post_id;
		}
		// Insert default form.
		$default_post_id = wp_insert_post(
			array(
				'post_type'      => 'ur_membership_groups',
				'post_title'     => esc_html__( 'Default Group', 'user-registration' ),
				'post_content'   => $post_content,
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		add_post_meta( $default_post_id, 'urmg_memberships', json_encode( $membership_ids ) );
		add_post_meta( $default_post_id, "urm_form_group_$default_post_id", json_encode( $membership_ids ) );
		add_post_meta( $default_post_id, 'urmg_default_group', $default_post_id );
		update_option( 'user_registration_default_membership_form_id', $default_post_id );

		return $default_post_id;
	}
}

UR_Install::init();
