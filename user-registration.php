<?php //phpcs:ignore

/**
 * Plugin Name: User Registration & Membership
 * Plugin URI: https://wpuserregistration.com/
 * Description: The most flexible User Registration and Membership plugin for WordPress.
 * Version: 5.2.8
 * Author: WPEverest
 * Author URI: https://wpuserregistration.com
 * Text Domain: user-registration
 * Domain Path: /languages/
 *
 * @package UserRegistration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( ! class_exists( 'UserRegistration' ) ) :

	/**
	 * Main UserRegistration Class.
	 *
	 * @class   UserRegistration
	 * @version 1.0.0
	 */
	final class UserRegistration {


		/**
		 * Plugin version.
		 *
		 * @var string
		 */
		public $version = '5.2.8';

		/**
		 * Session instance.
		 *
		 * @var UR_Session|UR_Session_Handler
		 */
		public $session = null;

		/**
		 * Query instance.
		 *
		 * @var UR_Query
		 */
		public $query = null;

		/**
		 * Instance of this class.
		 *
		 * @var object
		 */
		protected static $_instance = null;

		/**
		 * Instance of this form.
		 *
		 * @var object
		 */
		public $form = null;

		/**
		 * UTM Campaign.
		 *
		 * @var string
		 */
		public $utm_campaign = 'lite-version';

		/**
		 * Return an instance of this class.
		 *
		 * @return object A single instance of this class.
		 */
		public static function instance() {
			// If the single instance hasn't been set, set it now.
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}

		/**
		 * Cloning is forbidden.
		 *
		 * @since 1.0
		 */
		public function __clone() {
			_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'user-registration' ), '1.0' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 *
		 * @since 1.0
		 */
		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'user-registration' ), '1.0' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * UserRegistration Constructor.
		 */
		public function __construct() {
			$this->define_constants();
			$this->includes();
			$this->init_hooks();
			add_action( 'plugins_loaded', array( $this, 'objects' ), 1 );
			add_action( 'in_plugin_update_message-' . UR_PLUGIN_BASENAME, array( __CLASS__, 'in_plugin_update_message' ), 10, 2 );

			do_action( 'user_registration_loaded' );
		}

		/**
		 * Hook into actions and filters.
		 */
		private function init_hooks() {
			register_activation_hook( __FILE__, array( 'UR_Install', 'install' ) );
			register_shutdown_function( array( $this, 'log_errors' ) );
			add_action( 'after_setup_theme', array( $this, 'include_template_functions' ), 11 );
			add_action( 'init', array( $this, 'init' ), 0 );
			add_action( 'init', array( 'UR_Shortcodes', 'init' ) );

			add_filter( 'plugin_action_links_' . UR_PLUGIN_BASENAME, array( __CLASS__, 'plugin_action_links' ) );
			add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
		}

		/**
		 * Instantiate the WPML compatibility layer when WPML is active.
		 *
		 * Loading the service only when SitePress is present avoids any overhead
		 * on non-multilingual sites. The class itself uses WPML's documented
		 * filter API (no `new SitePress()`), so it cannot reintroduce the
		 * duplicate-JOIN "Not unique table/alias" errors.
		 *
		 * @since 5.2.1
		 */
		public function init_wpml_compat() {
			if ( ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress', false ) ) && class_exists( 'UR_WPML' ) ) {
				UR_WPML::instance();
			}
		}

		/**
		 * Ensures fatal errors are logged so they can be picked up in the status report.
		 *
		 * @since 3.0.5
		 */
		public function log_errors() {
			$error = error_get_last();

			if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				$logger = ur_get_logger();

				$raw_message = isset( $error['message'] ) ? trim( wp_strip_all_tags( $error['message'] ) ) : __( 'Unknown error', 'user-registration' );
				$file        = isset( $error['file'] ) ? $error['file'] : __( 'Unknown file', 'user-registration' );
				$line        = isset( $error['line'] ) ? absint( $error['line'] ) : 0;

				if ( false !== strpos( $file, 'wp-content/' ) ) {
					$file = substr( $file, strpos( $file, 'wp-content/' ) );
				}

				$message = $raw_message;
				$trace   = '';

				if ( false !== strpos( $raw_message, 'Stack trace:' ) ) {
					$parts = explode( 'Stack trace:', $raw_message, 2 );

					$message = trim( $parts[0] );
					$trace   = trim( $parts[1] );

					// Remove trailing "thrown in ..." because file/line is already shown separately.
					$trace = preg_replace( '/thrown in .*$/s', '', $trace );
					$trace = trim( $trace );
				}

				$log  = "================================================================\n";
				$log .= sprintf(
					/* translators: %s: error timestamp */
					__( '[%s] CRITICAL  Fatal error', 'user-registration' ),
					gmdate( 'Y-m-d H:i:s' )
				) . "\n";
				$log .= "----------------------------------------------------------------\n";
				$log .= sprintf(
					/* translators: %s: error message */
					__( 'Message : %s', 'user-registration' ),
					$message
				) . "\n";
				$log .= sprintf(
					/* translators: 1: file path, 2: line number */
					__( 'File    : %1$s:%2$d', 'user-registration' ),
					$file,
					$line
				) . "\n";
				$log .= __( 'Status  : FAILED', 'user-registration' ) . "\n";

				if ( ! empty( $trace ) ) {
					$log .= "\n" . __( 'Trace:', 'user-registration' ) . "\n";
					$log .= $trace . "\n";
				}

				$log .= '================================================================';

				$logger->critical(
					$log,
					array(
						'source' => 'fatal-errors',
						'type'   => $error['type'],
						'file'   => $file,
						'line'   => $line,
					)
				);
			}
		}

		/**
		 * Define FT Constants.
		 */
		private function define_constants() {
			$upload_dir = apply_filters( 'user_registration_upload_dir', wp_upload_dir() );
			$this->define( 'UR_LOG_DIR', $upload_dir['basedir'] . '/ur-logs/' );
			$this->define( 'UR_UPLOAD_PATH', $upload_dir['basedir'] . '/user_registration_uploads/' );
			$this->define( 'UR_UPLOAD_URL', $upload_dir['baseurl'] . '/user_registration_uploads/' );
			$this->define( 'UR_DS', DIRECTORY_SEPARATOR );
			$this->define( 'UR_PLUGIN_FILE', __FILE__ );
			$this->define( 'UR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
			$this->define( 'UR_ASSETS_URL', UR_PLUGIN_URL . 'assets' );
			$this->define( 'UR_ABSPATH', __DIR__ . UR_DS );
			$this->define( 'UR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
			$this->define( 'UR_VERSION', $this->version );
			$this->define( 'UR_TEMPLATE_DEBUG_MODE', false );
			$this->define( 'UR_TEMPLATE_PATH', UR_ABSPATH . 'templates/' );
			$this->define( 'UR_ASSET_PATH', plugins_url( 'assets/', UR_PLUGIN_FILE ) );
			$this->define( 'UR_FORM_PATH', UR_ABSPATH . 'includes' . UR_DS . 'form' . UR_DS );
			$this->define( 'UR_SESSION_CACHE_GROUP', 'ur_session_id' );
			$this->define( 'UR_PRO_ACTIVE', false );
			$this->define( 'UR_DEV', false );
		}

		/**
		 * Define constant if not already set.
		 *
		 * @param string      $name Name.
		 * @param string|bool $value Value.
		 */
		private function define( $name, $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		/**
		 * What type of request is this?
		 *
		 * @param  string $type admin, ajax, cron or frontend.
		 * @return bool
		 */
		private function is_request( $type ) {
			switch ( $type ) {
				case 'admin':
					return is_admin();
				case 'ajax':
					return defined( 'DOING_AJAX' );
				case 'cron':
					return defined( 'DOING_CRON' );
				case 'frontend':
					return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
			}
		}

		/**
		 * Includes.
		 */
		private function includes() {

			if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
				require_once __DIR__ . '/vendor/autoload.php';
			} else {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						sprintf(
							/* translators: 1: composer command. 2: plugin directory */
							esc_html__( 'Your installation of the User Registration is incomplete. Please run %1$s within the %2$s directory.', 'user-registration' ),
							'`composer install`',
							'`' . esc_html( str_replace( ABSPATH, '', __DIR__ ) ) . '`'
						)
					);
				}

				/**
				 * Outputs an admin notice if composer install has not been ran.
				 */
				add_action(
					'admin_notices',
					function () {
						?>
					<div class="notice notice-error">
						<p>
							<?php
							printf(
								/* translators: 1: composer command. 2: plugin directory */
								esc_html__( 'Your installation of the  User Registration is incomplete. Please run %1$s within the %2$s directory.', 'user-registration' ),
								'<code>composer install</code>',
								'<code>' . esc_html( str_replace( ABSPATH, '', __DIR__ ) ) . '</code>'
							);
							?>
						</p>
					</div>
						<?php
					}
				);
			}

			/**
			 * Class autoloader.
			 */
			include_once UR_ABSPATH . 'includes/class-ur-autoloader.php';

			/**
			 * Interfaces.
			 */
			include_once UR_ABSPATH . 'includes/interfaces/class-ur-logger-interface.php';
			include_once UR_ABSPATH . 'includes/interfaces/class-ur-log-handler-interface.php';

			/**
			 * Abstract classes
			 */
			include_once UR_ABSPATH . 'includes/abstracts/abstract-ur-form-field.php';
			include_once UR_ABSPATH . 'includes/abstracts/abstract-ur-field-settings.php';
			include_once UR_ABSPATH . 'includes/abstracts/abstract-ur-log-handler.php';
			include_once UR_ABSPATH . 'includes/abstracts/abstract-ur-session.php';

			/**
			 * Core classes.
			 */
			include_once UR_ABSPATH . 'includes/functions-ur-core.php';
			include_once UR_ABSPATH . 'modules/functions-ur-modules.php';
			include_once UR_ABSPATH . 'includes/functions-ur-form.php';
			include_once UR_ABSPATH . 'includes/class-ur-install.php';
			include_once UR_ABSPATH . 'includes/class-ur-post-types.php'; // Registers post types.
			include_once UR_ABSPATH . 'includes/class-ur-user-approval.php';
			include_once UR_ABSPATH . 'includes/class-ur-smart-tags.php'; // User Approval class.
			include_once UR_ABSPATH . 'includes/class-ur-emailer.php';
			include_once UR_ABSPATH . 'includes/class-ur-ajax.php';
			include_once UR_ABSPATH . 'includes/class-ur-query.php';
			include_once UR_ABSPATH . 'includes/class-ur-email-confirmation.php';
			include_once UR_ABSPATH . 'includes/class-ur-email-approval.php';
			include_once UR_ABSPATH . 'includes/class-ur-privacy.php';
			include_once UR_ABSPATH . 'includes/class-ur-form-block.php';
			include_once UR_ABSPATH . 'includes/class-ur-cache-helper.php';
			include_once UR_ABSPATH . 'includes/class-ur-wpml.php';

			/**
			 * Block classes.
			 */
			include_once UR_ABSPATH . 'includes/blocks/class-ur-blocks.php';
			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-abstract.php';
			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-registration-form.php';
			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-login-form.php';
			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-myaccount.php';
			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-edit-profile.php';
			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-edit-password.php';
			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-login-logout-menu.php';
			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-membership-listing.php';
			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-thank-you.php';
			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-membership-buy-now.php';

			/**
			 * Navigation menu item classes.
			 */
			include_once UR_ABSPATH . 'includes/menu-items/abstract-ur-nav-menu-item.php';
			include_once UR_ABSPATH . 'includes/menu-items/class-ur-login-logout-nav-menu-item.php';

			// Validation classes.
			include_once UR_ABSPATH . 'includes/validation/class-ur-validation.php';
			include_once UR_ABSPATH . 'includes/validation/class-ur-form-validation.php';
			include_once UR_ABSPATH . 'includes/validation/class-ur-setting-validation.php';

			include_once UR_ABSPATH . 'includes/RestApi/class-ur-rest-api.php';

			/**
			 * Config classes.
			 */
			include_once UR_ABSPATH . 'includes/admin/class-ur-config.php';

			if ( ur_check_module_activation( 'membership' ) ) {
				/** include modules */
				include_once UR_ABSPATH . 'modules/membership/user-registration-membership.php';

				if ( ur_check_module_activation( 'masteriyo-course-integration' ) && ( is_plugin_active( 'learning-management-system/lms.php' )
				|| is_plugin_active( 'learning-management-system-pro/lms.php' ) ) ) {
					include_once UR_ABSPATH . 'modules/masteriyo/user-registration-masteriyo.php';
				}
			}

			if ( ( ur_check_module_activation( 'membership' ) || ur_check_module_activation( 'payments' ) ) ) {
				include_once UR_ABSPATH . 'modules/payment-history/Orders.php';
			}

			include_once UR_ABSPATH . 'modules/content-restriction/user-registration-content-restriction.php';

			if ( ur_check_module_activation( 'membership' ) && ur_check_module_activation( 'content-restriction' ) && ur_check_module_activation( 'content-drip' ) ) {
				include_once UR_ABSPATH . 'modules/content-drip/user-registration-content-drip.php';
			}

			include_once UR_ABSPATH . 'includes/blocks/block-types/class-ur-block-content-restriction.php';

			/**
			 * Elementor classes.
			 */
			if ( class_exists( '\Elementor\Plugin' ) ) {
				include_once UR_ABSPATH . 'includes/3rd-party/elementor/class-ur-elementor.php';
			}
			/**
			 * Oxygen classes.
			 */
			if ( in_array( 'oxygen/functions.php', get_option( 'active_plugins', array() ), true ) ) {
				include_once UR_ABSPATH . 'includes/3rd-party/oxygen/class-ur-oxygen.php';
			}
			// Divi builder compatiblity.
			if ( class_exists( 'WPEverest\URM\DiviBuilder\Builder' ) ) {
				WPEverest\URM\DiviBuilder\Builder::init();
			}

			/**
			 * Plugin/Addon Updater.
			 */
			include_once UR_ABSPATH . 'includes/class-ur-plugin-updater.php';

			if ( $this->is_request( 'admin' ) ) {
				include_once UR_ABSPATH . 'includes/admin/class-ur-admin.php';
				include_once UR_ABSPATH . 'includes/abstracts/abstract-ur-meta-boxes.php';

				include_once UR_ABSPATH . 'includes/admin/class-ur-admin-embed-wizard.php';
			}

			if ( $this->is_request( 'frontend' ) ) {
				$this->frontend_includes();
			}

			if ( $this->is_request( 'frontend' ) || $this->is_request( 'cron' ) ) {
				include_once UR_ABSPATH . 'includes/class-ur-session-handler.php';
			}
			include_once UR_ABSPATH . 'includes/class-ur-cron.php';
			include_once UR_ABSPATH . 'includes/stats/class-ur-stats.php';
			include_once UR_ABSPATH . 'includes/stats/class-ur-formbricks.php';
			include_once UR_ABSPATH . 'includes/class-ur-captcha-conflict-manager.php';

			$this->query = new UR_Query();

			if ( class_exists( 'WPEverest\URM\Analytics\Analytics' ) ) {
				WPEverest\URM\Analytics\Analytics::get_instance();
			}
		}

		/**
		 * Include required frontend files.
		 */
		public function frontend_includes() {
			include_once UR_ABSPATH . 'includes/functions-ur-notice.php';
			include_once UR_ABSPATH . 'includes/class-ur-form-handler.php';                   // Form Handlers.
			include_once UR_ABSPATH . 'includes/class-ur-frontend-scripts.php';               // Frontend Scripts.
			include_once UR_ABSPATH . 'includes/frontend/class-ur-frontend.php';
			include_once UR_ABSPATH . 'includes/class-ur-preview.php';
		}

		/**
		 * Function used to Init UserRegistration Template Functions - This makes them pluggable by plugins and themes.
		 */
		public function include_template_functions() {
			include_once UR_ABSPATH . 'includes/functions-ur-template.php';
		}

		/**
		 * Setup Objects.
		 *
		 * @since 1.7.2
		 */
		public function objects() {
			$this->form = new UR_Form_Handler();

			// Initialize captcha conflict manager only on frontend
			if ( $this->is_request( 'frontend' ) ) {
				new UR_Captcha_Conflict_Manager();
			}
		}

		/**
		 * Init UserRegistration when WordPress Initialises.
		 */
		public function init() {
			// Before init action.
			do_action( 'before_user_registration_init' );

			// Set up localisation.
			$this->load_plugin_textdomain();

			// Boot WPML compatibility (only when WPML is active).
			$this->init_wpml_compat();

			// Session class, handles session data for users - can be overwritten if custom handler is needed.
			if ( $this->is_request( 'frontend' ) || $this->is_request( 'cron' ) || $this->is_request( 'admin' ) ) {
				$session_class = apply_filters( 'user_registration_session_handler', 'UR_Session_Handler' );
				$this->session = new $session_class();
			}

			// Init action.
			do_action( 'user_registration_init' );
		}

		/**
		 * Load Localisation files.
		 *
		 * Note: the first-loaded translation file overrides any following ones if the same translation is present.
		 *
		 * Locales found in:
		 *      - WP_LANG_DIR/user-registration/user-registration-LOCALE.mo
		 *      - WP_LANG_DIR/plugins/user-registration-LOCALE.mo
		 */
		public function load_plugin_textdomain() {
			$locale = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$locale = apply_filters( 'plugin_locale', $locale, 'user-registration' );

			unload_textdomain( 'user-registration', true );
			load_textdomain( 'user-registration', WP_LANG_DIR . '/user-registration/user-registration-' . $locale . '.mo' );
			load_plugin_textdomain( 'user-registration', false, plugin_basename( __DIR__ ) . '/languages' );
		}

		/**
		 * Get the plugin url.
		 *
		 * @return string
		 */
		public function plugin_url() {
			return untrailingslashit( plugins_url( '/', __FILE__ ) );
		}

		/**
		 * Get the plugin path.
		 *
		 * @return string
		 */
		public function plugin_path() {
			return untrailingslashit( plugin_dir_path( __FILE__ ) );
		}

		/**
		 * Get the template path.
		 *
		 * @return string
		 */
		public function template_path() {
			return apply_filters( 'user_registration_template_path', 'user-registration/' );
		}

		/**
		 * Get Ajax URL.
		 *
		 * @return string
		 */
		public function ajax_url() {
			return admin_url( 'admin-ajax.php', 'relative' );
		}

		/**
		 * Display action links in the Plugins list table.
		 *
		 * @param  array $actions Plugin Action links.
		 * @return array
		 */
		public static function plugin_action_links( $actions ) {
			$new_actions = array(
				'settings' => '<a href="' . admin_url( 'admin.php?page=user-registration-settings' ) . '" aria-label="' . esc_attr__( 'View User Registration & Membership settings', 'user-registration' ) . '">' . esc_html__( 'Settings', 'user-registration' ) . '</a>',
			);

			return array_merge( $new_actions, $actions );
		}

		/**
		 * Display row meta in the Plugins list table.
		 *
		 * @param  array  $plugin_meta Plugin Row Meta.
		 * @param  string $plugin_file Plugin Row Meta.
		 * @return array
		 */
		public static function plugin_row_meta( $plugin_meta, $plugin_file ) {
			if ( UR_PLUGIN_BASENAME === $plugin_file ) {
				$new_plugin_meta = array(
					'docs'    => '<a href="' . esc_url( apply_filters( 'user_registration_docs_url', 'https://docs.wpuserregistration.com/' ) ) . '" area-label="' . esc_attr__( 'View User Registration & Membership documentation', 'user-registration' ) . '">' . esc_html__( 'Docs', 'user-registration' ) . '</a>',
					'support' => '<a href="' . esc_url( apply_filters( 'user_registration_support_url', 'https://wpuserregistration.com/support/' ) ) . '" area-label="' . esc_attr__( 'Visit free customer support', 'user-registration' ) . '">' . __( 'Free support', 'user-registration' ) . '</a>',
				);

				return array_merge( $plugin_meta, $new_plugin_meta );
			}

			return (array) $plugin_meta;
		}


		/**
		 * Update notice
		 *
		 * @param array $args Plugin args.
		 */
		public static function in_plugin_update_message( $plugin_data, $response ) {
			if ( empty( $response ) || empty( $response->new_version ) ) {
				return;
			}
			$new_version = (string) $response->new_version;

			$transient_name = 'ur_upgrade_notice_' . $new_version;
			$upgrade_notice = get_transient( $transient_name );

			if ( false === $upgrade_notice ) {
				$http_response = wp_safe_remote_get( 'https://plugins.svn.wordpress.org/user-registration/trunk/readme.txt' );

				if ( ! is_wp_error( $http_response ) && ! empty( $http_response['body'] ) ) {
					$upgrade_notice = self::parse_update_notice( $http_response['body'], $new_version );
					set_transient( $transient_name, $upgrade_notice, 3 * DAY_IN_SECONDS );
				}
			}

			echo wp_kses_post( $upgrade_notice );
		}

		/**
		 * Parse update notice from readme.
		 *
		 * @param string $content Readme content.
		 * @param string $new_version New version.
		 */
		private static function parse_update_notice( $content, $new_version ) {
			$upgrade_notice = '';

			// Match all version blocks under "== Upgrade Notice =="
			$blocks_regex = '~=\s*([\d\.]+)\s*=(.*?)(?==\s*[\d\.]+\s*=|$)~s';
			if ( preg_match_all( $blocks_regex, $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$version_line = trim( $match[1] );
					$block_text   = trim( $match[2] );

					// Only process the block if it matches $new_version
					if ( $version_line !== $new_version ) {
						continue;
					}

					$notices = (array) preg_split( '~[\r\n]+~', $block_text );

					$upgrade_notice .= '<div class="ur_plugin_upgrade_notice">';
					$upgrade_notice .= '<div class="ur_plugin_upgrade_notice_body">';

					foreach ( $notices as $line ) {
						$line = trim( $line );
						if ( empty( $line ) ) {
							continue;
						}

						$line = preg_replace(
							'~\[\s*([^\]]+)\s*\]\s*\(\s*([^\)]+)\s*\)~',
							'<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
							$line
						);

						// Convert headings
						if ( preg_match( '~^###\s*(.*)~', $line, $heading ) ) {
							$line = '<p class="upgrade-title" style="font-size:13px;font-weight:600;">' . $heading[1] . '</p>';
						} elseif ( preg_match( '~^##\s*(.*)~', $line, $heading ) ) {
							$line = '<p class="upgrade-heading" style="font-size:14px;font-weight:600;">' . $heading[1] . '</p>';
						} else {
							$line = '<p style="font-size:12px;">' . $line . '</p>';
						}

						$upgrade_notice .= wp_kses_post( $line );
					}

					$upgrade_notice .= '</div>';
					$upgrade_notice .= '</div>';

					break;
				}
			}

			return wp_kses_post( $upgrade_notice );
		}
	}

endif;

/**
 * Check to see if UR already defined and resolve conflicts while installing PRO version.
 *
 * @since 2.0.4
 */

if ( ! function_exists( 'UR' ) ) {

	/**
	 * Main instance of UserRegistration.
	 *
	 * Returns the main instance of FT to prevent the need to use globals.
	 *
	 * @since  1.0.0
	 * @return UserRegistration
	 */
	function UR() {
		return UserRegistration::instance();
	}
} else {

	if ( ! function_exists( 'user_registration_pro_activated' ) ) {
		/**
		 * When Pro version is activated, deactivate free version.
		 */
		function user_registration_pro_activated() {
			set_transient( 'user_registration_pro_activated', true );
			user_registration_free_deactivate();
		}
	}
	add_action( 'activate_user-registration-pro/user-registration.php', 'user_registration_pro_activated' );

	if ( ! function_exists( 'user_registration_free_activated' ) ) {
		/**
		 * When user activates free version, set the value that is to be used to handle both Free and Pro activation conflict.
		 */
		function user_registration_free_activated() {

			set_transient( 'user_registration_free_activated', true );
		}
	}
	add_action( 'activate_user-registration/user-registration.php', 'user_registration_free_activated' );

	if ( ! function_exists( 'user_registration_free_deactivated' ) ) {
		/**
		 * When user deactivates free version, remove the value that was used to handle both Free and Pro activation conflict.
		 */
		function user_registration_free_deactivated() {

			global $user_registration_free_activated, $user_registration_free_deactivated;

			$user_registration_free_activated   = (bool) get_transient( 'user_registration_free_activated' );
			$user_registration_free_deactivated = true;

			delete_transient( 'user_registration_free_activated' );
		}
	}
	add_action( 'deactivate_user-registration/user-registration.php', 'user_registration_free_deactivated' );

	if ( ! function_exists( 'user_registration_free_deactivate' ) ) {
		/**
		 * Deactivate Free version if Pro is already activated.
		 *
		 * @since 1.0.0
		 */
		function user_registration_free_deactivate() {

			$plugin = 'user-registration/user-registration.php';

			deactivate_plugins( $plugin );
			do_action( 'user_registration_free_deactivate', $plugin );
			delete_transient( 'user_registration_pro_activated' );
		}
	}

	add_action( 'admin_init', 'user_registration_free_deactivate' );

	if ( ! function_exists( 'user_registration_free_notice' ) ) {
		/**
		 * When user wants to activate Free version alongside Pro, then display the message.
		 */
		function user_registration_free_notice() {

			global $user_registration_free_activated, $user_registration_free_deactivated;

			if (
				empty( $user_registration_free_activated ) ||
				empty( $user_registration_free_deactivated )
			) {
				return;
			}

			echo '<div class="notice-warning notice is-dismissible"><p>' . wp_kses_post( __( 'As <strong>User Registration & Membership Pro</strong> is active, <strong>User Registration & Membership Free</strong> is now not needed.', 'user-registration' ) ) . '</p></div>';

			if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			unset( $user_registration_free_activated, $user_registration_free_deactivated );
		}
	}
	add_action( 'admin_notices', 'user_registration_free_notice' );

	// Do not process the plugin code further.
	return;
}
/**
 * Development: Hot reload support for webpack dev server.
 * Enable by setting SCRIPT_DEBUG to true in wp-config.php
 */
if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
	add_filter(
		'script_loader_src',
		function ( $src, $handle ) {
			$dev_scripts = array(
				'user-registration-welcome',
				'user-registration-dashboard',
				'user-registration-blocks',
				'user-registration-form-templates',
				'user-registration-divi-builder',
				'user-registration-content-access-rules',
			);

			if ( in_array( $handle, $dev_scripts, true ) ) {
				$src = str_replace(
					UR_PLUGIN_URL . 'chunks/',
					'http://localhost:3000/',
					$src
				);
			}

			return $src;
		},
		10,
		2
	);
}

// Global for backwards compatibility.
$GLOBALS['user-registration'] = UR();
