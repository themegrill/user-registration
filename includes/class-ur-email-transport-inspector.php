<?php
/**
 * Email Transport Inspector.
 *
 * Works out how mail actually leaves this site — PHP's mail(), an SMTP
 * connection, or a plugin that bypasses wp_mail() entirely — and, when
 * something has taken the route over, which plugin, theme or snippet did it.
 *
 * Everything here is read-only: no mail is sent, and the throwaway PHPMailer
 * it builds is never handed to a transport.
 *
 * @class    UR_Email_Transport_Inspector
 * @package  UserRegistration/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'UR_Email_Transport_Inspector' ) ) :

	/**
	 * UR_Email_Transport_Inspector Class.
	 */
	class UR_Email_Transport_Inspector {

		/**
		 * Cached inspection for this request — every check needs the same
		 * answer and building it fires filters, so do it once.
		 *
		 * @var array|null
		 */
		private static $inspection = null;

		/**
		 * Hooks that can divert or replace outgoing mail, in the order WordPress
		 * itself consults them.
		 *
		 * @var array
		 */
		private static $transport_hooks = array( 'pre_wp_mail', 'phpmailer_init' );

		/**
		 * UR's own mail hooks. These are the plugin doing its normal job, not a
		 * third party taking over, so provenance reporting has to ignore them.
		 *
		 * @var array
		 */
		private static $own_callbacks = array(
			'UR_Emailer::ur_sender_email',
			'UR_Emailer::ur_sender_name',
			'UR_Emailer::ur_get_content_type',
			'ur_email_send_failed_handler',
		);

		/**
		 * Route the site's mail actually takes.
		 *
		 * Returns an array with:
		 *  - route     'php_mail' | 'smtp' | 'api' | 'replaced'
		 *  - owner     array|null  Attribution for whatever took the route over.
		 *  - smtp      array|null  { host, port, encryption, auth } when route is 'smtp'.
		 *  - php_mail  array       { usable, reason } viability of PHP's mail().
		 *
		 * @return array
		 */
		public static function inspect() {
			if ( null !== self::$inspection ) {
				return self::$inspection;
			}

			$inspection = array(
				'route'       => 'php_mail',
				'owner'       => null,
				'smtp'        => null,
				'php_mail'    => self::php_mail_viability(),
				'diverted_by' => null,
			);

			// A `pre_wp_mail` listener *might* short-circuit wp_mail() before
			// PHPMailer exists — but one whose own connection is unconfigured
			// returns null and lets the normal path continue. Only a real send
			// tells them apart, so record it as a possible diversion and keep
			// working out the route underneath, which runs if it declines.
			$inspection['diverted_by'] = self::first_third_party_owner( 'pre_wp_mail' );

			// A plugin can also replace the pluggable wp_mail() outright rather
			// than hooking anything.
			$replacement = self::wp_mail_replacement();

			if ( $replacement ) {
				$inspection['route'] = 'replaced';
				$inspection['owner'] = $replacement;
				self::$inspection    = $inspection;

				return $inspection;
			}

			$phpmailer = self::probe_phpmailer();

			if ( $phpmailer && isset( $phpmailer->Mailer ) && 'mail' !== $phpmailer->Mailer ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$inspection['route'] = 'smtp';
				$inspection['owner'] = self::transport_switcher();
				$inspection['smtp']  = self::smtp_details( $phpmailer );
			}

			self::$inspection = $inspection;

			return $inspection;
		}

		/**
		 * Whether the transport takes mail over in a way we can't see into.
		 * A `pre_wp_mail` listener replaces wp_mail() wholesale and does its own
		 * addressing internally, so no amount of probing from out here reveals
		 * the sender it will end up using.
		 *
		 * @return bool
		 */
		public static function is_opaque() {
			$inspection = self::inspect();

			return ! empty( $inspection['diverted_by'] ) || 'replaced' === $inspection['route'];
		}

		/**
		 * Whatever might be diverting mail away from the normal route, or null.
		 *
		 * @return array|null { type, name }
		 */
		public static function diverter() {
			$inspection = self::inspect();

			return $inspection['diverted_by'];
		}

		/**
		 * The sender a message would actually leave with, resolved the same way
		 * wp_mail() resolves it: apply the sender filters, then let
		 * `phpmailer_init` listeners have the last word.
		 *
		 * Deliberately generic — no plugin's settings are read. Anything that
		 * overrides the sender through either of WordPress's two documented
		 * seams is caught regardless of which plugin it is; anything that
		 * bypasses both is reported as unknown rather than guessed at.
		 *
		 * @param string $address Configured From address.
		 * @param string $name    Configured From name.
		 * @return array { address, name, resolved } — resolved is false when the
		 *               transport is opaque and this is only the configured value.
		 */
		public static function effective_sender( $address, $name ) {
			// UR attaches its own sender filters only between these two actions,
			// so they have to be read inside that window to match a real send.
			do_action( 'user_registration_email_send_before' );

			$effective_address = (string) apply_filters( 'wp_mail_from', $address );
			$effective_name    = (string) apply_filters( 'wp_mail_from_name', $name );

			do_action( 'user_registration_email_send_after' );

			$probed = self::probe_sender( $effective_address, $effective_name );

			if ( $probed ) {
				$effective_address = $probed['address'];
				$effective_name    = $probed['name'];
			}

			return array(
				'address'  => $effective_address,
				'name'     => $effective_name,
				'resolved' => ! self::is_opaque(),
			);
		}

		/**
		 * Seed a PHPMailer with the sender wp_mail() would have set, fire
		 * `phpmailer_init`, and see what the listeners left behind. Catches
		 * plugins that call setFrom() there instead of using the filters.
		 *
		 * @param string $address Sender address going in.
		 * @param string $name    Sender name going in.
		 * @return array|null { address, name }
		 */
		private static function probe_sender( $address, $name ) {
			if ( ! class_exists( 'PHPMailer\PHPMailer\PHPMailer' ) ) {
				require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
				require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
				require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			}

			try {
				$phpmailer = new PHPMailer\PHPMailer\PHPMailer( true );

				if ( is_email( $address ) ) {
					$phpmailer->setFrom( $address, $name, false );
				}

				/** This action is documented in wp-includes/pluggable.php */
				do_action_ref_array( 'phpmailer_init', array( &$phpmailer ) );

				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return array(
					'address' => '' !== $phpmailer->From ? $phpmailer->From : $address,
					'name'    => '' !== $phpmailer->FromName ? $phpmailer->FromName : $name,
				);
				// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			} catch ( Exception $e ) {
				return null;
			} catch ( Error $e ) {
				return null;
			}
		}

		/**
		 * Build a PHPMailer and let every `phpmailer_init` listener configure it,
		 * exactly as wp_mail() would. Checking whether anything merely *hooked*
		 * the action is useless — WooCommerce alone hooks it a dozen times just
		 * to set an alt body — so the transport it ends up with is the only
		 * reliable signal.
		 *
		 * @return PHPMailer\PHPMailer\PHPMailer|null
		 */
		private static function probe_phpmailer() {
			static $phpmailer = null;

			if ( null !== $phpmailer ) {
				return $phpmailer;
			}

			if ( ! class_exists( 'PHPMailer\PHPMailer\PHPMailer' ) ) {
				require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
				require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
				require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			}

			try {
				$phpmailer = new PHPMailer\PHPMailer\PHPMailer( true );

				/** This action is documented in wp-includes/pluggable.php */
				do_action_ref_array( 'phpmailer_init', array( &$phpmailer ) );
			} catch ( Exception $e ) {
				$phpmailer = null;
			} catch ( Error $e ) {
				$phpmailer = null;
			}

			return $phpmailer;
		}

		/**
		 * Which `phpmailer_init` listener actually moved the transport off PHP
		 * mail(). Replaying the listeners one at a time onto a clean PHPMailer
		 * is the only way to tell the plugin that switched the transport from
		 * the several that merely decorated the message.
		 *
		 * @return array|null
		 */
		private static function transport_switcher() {
			foreach ( self::hook_callbacks( 'phpmailer_init' ) as $callback ) {
				if ( ! class_exists( 'PHPMailer\PHPMailer\PHPMailer' ) ) {
					break;
				}

				try {
					$candidate = new PHPMailer\PHPMailer\PHPMailer( true );
					call_user_func_array( $callback['function'], array( &$candidate ) );

					if ( isset( $candidate->Mailer ) && 'mail' !== $candidate->Mailer ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						return self::describe_source( $callback['file'] );
					}
				} catch ( Exception $e ) {
					continue;
				} catch ( Error $e ) {
					continue;
				}
			}

			return null;
		}

		/**
		 * First callback on a hook that isn't one of UR's own.
		 *
		 * @param string $hook Hook name.
		 * @return array|null
		 */
		private static function first_third_party_owner( $hook ) {
			foreach ( self::hook_callbacks( $hook ) as $callback ) {
				if ( ! $callback['is_own'] ) {
					return self::describe_source( $callback['file'] );
				}
			}

			return null;
		}

		/**
		 * Flatten a hook's callbacks into { function, file, is_own }, resolving
		 * each one to the file that declared it.
		 *
		 * @param string $hook Hook name.
		 * @return array
		 */
		private static function hook_callbacks( $hook ) {
			global $wp_filter;

			if ( ! in_array( $hook, self::$transport_hooks, true ) && ! isset( $wp_filter[ $hook ] ) ) {
				return array();
			}

			if ( empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
				return array();
			}

			$flattened = array();

			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( ! isset( $callback['function'] ) || ! is_callable( $callback['function'] ) ) {
						continue;
					}

					$flattened[] = array(
						'function' => $callback['function'],
						'file'     => self::callback_file( $callback['function'] ),
						'is_own'   => self::is_own_callback( $callback['function'] ),
					);
				}
			}

			return $flattened;
		}

		/**
		 * Whether a callback belongs to User Registration itself.
		 *
		 * @param callable $callback Callback.
		 * @return bool
		 */
		private static function is_own_callback( $callback ) {
			if ( is_string( $callback ) ) {
				return in_array( $callback, self::$own_callbacks, true );
			}

			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];

				return in_array( $class . '::' . $callback[1], self::$own_callbacks, true );
			}

			return false;
		}

		/**
		 * File that declared a callback.
		 *
		 * @param callable $callback Callback.
		 * @return string Empty string when it can't be resolved.
		 */
		private static function callback_file( $callback ) {
			try {
				if ( is_array( $callback ) && 2 === count( $callback ) ) {
					$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];
					$reflection = new ReflectionMethod( $class, $callback[1] );
				} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
					$reflection = new ReflectionMethod( $callback );
				} elseif ( is_object( $callback ) && ! $callback instanceof Closure && method_exists( $callback, '__invoke' ) ) {
					$reflection = new ReflectionMethod( $callback, '__invoke' );
				} else {
					$reflection = new ReflectionFunction( $callback );
				}

				return (string) $reflection->getFileName();
			} catch ( ReflectionException $e ) {
				return '';
			}
		}

		/**
		 * Turn a file path into something an admin can act on — the plugin's
		 * display name, the theme, or "custom code" for a snippet.
		 *
		 * @param string $file Absolute file path.
		 * @return array|null { type, name }
		 */
		private static function describe_source( $file ) {
			if ( empty( $file ) ) {
				return null;
			}

			$file = wp_normalize_path( $file );

			$mu_dir = wp_normalize_path( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );

			if ( 0 === strpos( $file, $mu_dir ) ) {
				return array(
					'type' => 'mu_plugin',
					'name' => __( 'a must-use plugin', 'user-registration' ),
				);
			}

			$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );

			if ( 0 === strpos( $file, $plugin_dir ) ) {
				$relative = ltrim( substr( $file, strlen( $plugin_dir ) ), '/' );
				$slug     = strtok( $relative, '/' );

				return array(
					'type' => 'plugin',
					'name' => self::plugin_name( $slug ),
				);
			}

			$theme_root = wp_normalize_path( get_theme_root() );

			if ( 0 === strpos( $file, $theme_root ) ) {
				return array(
					'type' => 'theme',
					'name' => wp_get_theme()->get( 'Name' ),
				);
			}

			return array(
				'type' => 'custom',
				'name' => __( 'custom code on your site', 'user-registration' ),
			);
		}

		/**
		 * Display name for a plugin directory, falling back to the directory
		 * name when its header can't be read.
		 *
		 * @param string $slug Plugin directory name.
		 * @return string
		 */
		private static function plugin_name( $slug ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			foreach ( get_plugins() as $plugin_file => $data ) {
				if ( strtok( $plugin_file, '/' ) === $slug && ! empty( $data['Name'] ) ) {
					return $data['Name'];
				}
			}

			return $slug;
		}

		/**
		 * A plugin that redefined the pluggable wp_mail() instead of hooking it.
		 * Note this can silently lose the race against core's own definition, in
		 * which case wp_mail() is core's and there is no replacement to report.
		 *
		 * @return array|null
		 */
		private static function wp_mail_replacement() {
			if ( ! function_exists( 'wp_mail' ) ) {
				return null;
			}

			try {
				$reflection = new ReflectionFunction( 'wp_mail' );
				$core_file  = wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' );
				$actual     = wp_normalize_path( (string) $reflection->getFileName() );

				return $actual === $core_file ? null : self::describe_source( $actual );
			} catch ( ReflectionException $e ) {
				return null;
			}
		}

		/**
		 * Whether PHP's mail() could deliver anything at all. Both failures here
		 * are silent at runtime — mail() simply returns false — and neither is
		 * visible anywhere in wp-admin.
		 *
		 * @return array { usable, reason }
		 */
		private static function php_mail_viability() {
			if ( ! function_exists( 'mail' ) ) {
				return array(
					'usable' => false,
					'reason' => 'disabled',
				);
			}

			$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

			if ( in_array( 'mail', $disabled, true ) ) {
				return array(
					'usable' => false,
					'reason' => 'disabled',
				);
			}

			// On Linux, PHP shells out to the binary named in sendmail_path. If
			// that binary isn't installed — common on slim containers — mail()
			// can never deliver, however correct every setting looks.
			$sendmail_path = trim( (string) ini_get( 'sendmail_path' ) );

			if ( '' !== $sendmail_path && ! self::is_windows() ) {
				$parts  = preg_split( '/\s+/', $sendmail_path );
				$binary = isset( $parts[0] ) ? $parts[0] : '';

				if ( '' !== $binary && ! @is_executable( $binary ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return array(
						'usable' => false,
						'reason' => 'no_sendmail',
						'binary' => $binary,
					);
				}
			}

			return array(
				'usable' => true,
				'reason' => '',
			);
		}

		/**
		 * Whether PHP is running on Windows, where sendmail_path doesn't apply.
		 *
		 * @return bool
		 */
		private static function is_windows() {
			return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
		}

		/**
		 * Connection facts for a configured SMTP transport. Credentials are
		 * never included — only whether authentication is switched on.
		 *
		 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Configured instance.
		 * @return array
		 */
		private static function smtp_details( $phpmailer ) {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return array(
				'host'       => isset( $phpmailer->Host ) ? (string) $phpmailer->Host : '',
				'port'       => isset( $phpmailer->Port ) ? (int) $phpmailer->Port : 0,
				'encryption' => isset( $phpmailer->SMTPSecure ) && '' !== $phpmailer->SMTPSecure ? strtoupper( (string) $phpmailer->SMTPSecure ) : '',
				'auth'       => ! empty( $phpmailer->SMTPAuth ),
			);
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		/**
		 * Whether the configured SMTP host accepts a connection on its port.
		 * Hosts that silently block outbound 587/465 are otherwise close to
		 * undiagnosable from inside wp-admin.
		 *
		 * Capped at a few seconds so a firewalled host slows the scan rather
		 * than hanging it.
		 *
		 * @param string $host Hostname.
		 * @param int    $port Port.
		 * @return array { reachable, error }
		 */
		public static function probe_smtp_port( $host, $port ) {
			if ( empty( $host ) || empty( $port ) || ! function_exists( 'stream_socket_client' ) ) {
				return array(
					'reachable' => null,
					'error'     => '',
				);
			}

			$error_code    = 0;
			$error_message = '';

			$socket = @stream_socket_client( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'tcp://' . $host . ':' . (int) $port,
				$error_code,
				$error_message,
				4
			);

			if ( ! $socket ) {
				return array(
					'reachable' => false,
					'error'     => $error_message,
				);
			}

			fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A network socket, not a file; WP_Filesystem has no equivalent.

			return array(
				'reachable' => true,
				'error'     => '',
			);
		}
	}

endif;
