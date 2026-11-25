<?php
/**
 * Connect an AI assistant: the AI user, its application password, the connection steps and the health check.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Makes the AI user and its password in one step, builds what each AI app needs, and checks the connection is ready. */
class UR_Abilities_Connect {
	/** Name of the nonce the Connect card sends with every request. */
	const NONCE = 'ur_ai_connect';

	/** Option that keeps the last AI call, so the page can show when an AI app last connected. */
	const LAST_CALL_OPTION = 'user_registration_ai_last_call';

	/** Route of the MCP Adapter's default server. */
	const ROUTE = 'mcp/mcp-adapter-default-server';

	/** Hook the Connect card and its requests. */
	public static function init() {
		add_filter( 'user_registration_settings_advanced_ai_integration', array( __CLASS__, 'add_settings' ), 15 );
		add_filter( 'user_registration_admin_field_ur_ai_connect', array( __CLASS__, 'render' ), 10, 2 );
		add_action( 'wp_ajax_ur_ai_connect_create', array( __CLASS__, 'ajax_create' ) );
		add_action( 'wp_ajax_ur_ai_connect_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_ur_ai_connect_revoke', array( __CLASS__, 'ajax_revoke' ) );
	}

	/**
	 * Get the AI user, if one was made.
	 *
	 * @since xx.xx.xx
	 *
	 * @return WP_User|null
	 */
	public static function ai_user() {
		$users = get_users(
			array(
				'role'   => UR_Abilities_Role::ROLE,
				'number' => 1,
			)
		);

		return $users ? $users[0] : null;
	}

	/**
	 * Make the AI user if there is none, and an application password for it.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $app_name Name of the AI app. It is the name of the application password, so it shows in the activity log.
	 * @return array|WP_Error login, password (shown once), code (the login for the connection), endpoint and snippets.
	 */
	public static function create_connection( $app_name ) {
		if ( ! wp_is_application_passwords_available() ) {
			return new WP_Error( 'ur_ai_app_passwords_off', __( 'Application passwords are turned off on this site. Your host or a security plugin may have turned them off.', 'user-registration' ), array( 'status' => 400 ) );
		}

		UR_Abilities_Role::ensure_role();

		$user = self::ai_user();

		if ( ! $user ) {
			$user = self::create_user();

			if ( is_wp_error( $user ) ) {
				return $user;
			}
		}

		$app_name = substr( sanitize_text_field( $app_name ), 0, 100 );
		$created  = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => '' === $app_name ? __( 'AI assistant', 'user-registration' ) : $app_name ) );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$code     = base64_encode( $user->user_login . ':' . $created[0] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds the Basic login header, not hidden code.
		$endpoint = rest_url( self::ROUTE );

		return array(
			'login'    => $user->user_login,
			'password' => WP_Application_Passwords::chunk_password( $created[0] ),
			'code'     => $code,
			'endpoint' => $endpoint,
			'snippets' => self::snippets( $endpoint, $code ),
		);
	}

	/**
	 * Make the AI user. It gets a long random password nobody sees, and it cannot sign in with a password anyway.
	 *
	 * @return WP_User|WP_Error
	 */
	private static function create_user() {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$login = 'ur-ai-assistant';
		$count = 1;

		while ( username_exists( $login ) ) {
			++$count;
			$login = 'ur-ai-assistant-' . $count;
		}

		$id = wp_insert_user(
			array(
				'user_login'           => $login,
				'user_pass'            => wp_generate_password( 64 ),
				'user_email'           => $login . '@' . ( false !== strpos( $host, '.' ) ? $host : 'example.invalid' ),
				'display_name'         => __( 'User Registration AI', 'user-registration' ),
				'role'                 => UR_Abilities_Role::ROLE,
				'show_admin_bar_front' => 'false',
			)
		);

		return is_wp_error( $id ) ? $id : get_userdata( $id );
	}

	/**
	 * Remove every application password of the AI user, which cuts every AI app off at once.
	 *
	 * @since xx.xx.xx
	 *
	 * @return int How many passwords were removed.
	 */
	public static function revoke_connections() {
		$user  = self::ai_user();
		$count = 0;

		if ( $user ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $item ) {
				$count += WP_Application_Passwords::delete_application_password( $user->ID, $item['uuid'] ) ? 1 : 0;
			}
		}

		return $count;
	}

	/**
	 * Build what each AI app needs to connect.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $endpoint Address of the connection.
	 * @param string $code     The login, as one code.
	 * @return array claude, cursor, codex and prompt.
	 */
	public static function snippets( $endpoint, $code ) {
		$name = sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$name = '' === $name ? 'my-site' : $name;

		return array(
			'claude' => 'claude mcp add --transport http --scope user ' . $name . ' ' . $endpoint . ' --header "Authorization: Basic ' . $code . '"',
			'cursor' => wp_json_encode(
				array(
					'mcpServers' => array(
						$name => array(
							'url'     => $endpoint,
							'headers' => array( 'Authorization' => 'Basic ' . $code ),
						),
					),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			),
			'codex'  => "[mcp_servers.{$name}]\nurl = \"{$endpoint}\"\nhttp_headers = { Authorization = \"Basic {$code}\" }",
			'prompt' => 'Add an MCP server to your own configuration. Name: ' . $name . '. Type: HTTP. URL: ' . $endpoint . '. Header: Authorization: Basic ' . $code . '. Then list my registration forms to check it works.',
		);
	}

	/**
	 * Check the site is ready for an AI app to connect.
	 *
	 * @since xx.xx.xx
	 *
	 * @return array Checks, each with id, label, ok and fix.
	 */
	public static function checks() {
		$user      = self::ai_user();
		$has_pass  = $user && ! empty( WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
		$abilities = function_exists( 'wp_get_abilities' ) ? array_filter(
			array_keys( wp_get_abilities() ),
			function ( $name ) {
				return 0 === strpos( $name, 'user-registration/' );
			}
		) : array();

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$routes  = rest_get_server()->get_routes();
		$secure  = is_ssl() || in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		$adapter = is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) && isset( $routes[ '/' . self::ROUTE ] );

		return array(
			array(
				'id'    => 'wordpress',
				'label' => __( 'WordPress 6.9 or newer', 'user-registration' ),
				'ok'    => function_exists( 'wp_register_ability' ),
				'fix'   => __( 'Update WordPress. Version 6.9 adds the Abilities API.', 'user-registration' ),
			),
			array(
				'id'    => 'adapter',
				'label' => __( 'MCP Adapter plugin is active and answering', 'user-registration' ),
				'ok'    => $adapter,
				'fix'   => __( 'Install and activate the MCP Adapter plugin.', 'user-registration' ),
			),
			array(
				'id'    => 'secure',
				'label' => __( 'The site address is secure', 'user-registration' ),
				'ok'    => $secure,
				'fix'   => __( 'Use https. Application passwords need it on a live site.', 'user-registration' ),
			),
			array(
				'id'    => 'passwords',
				'label' => __( 'Application passwords are available', 'user-registration' ),
				'ok'    => wp_is_application_passwords_available(),
				'fix'   => __( 'Your host or a security plugin has turned application passwords off. Turn them back on.', 'user-registration' ),
			),
			array(
				'id'    => 'enabled',
				'label' => __( 'AI abilities are switched on', 'user-registration' ),
				'ok'    => ur_option_checked( 'user_registration_enable_abilities', false ),
				'fix'   => __( 'Switch on Enable AI Abilities above.', 'user-registration' ),
			),
			array(
				'id'    => 'visible',
				'label' => __( 'The AI can see at least one ability', 'user-registration' ),
				'ok'    => ! empty( $abilities ),
				'fix'   => __( 'Switch on at least one ability in AI Abilities Access.', 'user-registration' ),
			),
			array(
				'id'    => 'user',
				'label' => __( 'A user for the AI exists, with a password', 'user-registration' ),
				'ok'    => (bool) $has_pass,
				'fix'   => __( 'Create the AI user and password below.', 'user-registration' ),
			),
		);
	}

	/**
	 * Get the last time an AI app made a call.
	 *
	 * @since xx.xx.xx
	 *
	 * @return array|null time (Unix), app and ability, or null when there has been none.
	 */
	public static function last_call() {
		$call = get_option( self::LAST_CALL_OPTION );

		return is_array( $call ) && ! empty( $call['time'] ) ? $call : null;
	}

	/**
	 * Starter prompts that only look, to check the connection works.
	 *
	 * @since xx.xx.xx
	 *
	 * @return array
	 */
	public static function prompts() {
		return array(
			__( 'List my registration forms.', 'user-registration' ),
			__( 'Who is waiting for approval?', 'user-registration' ),
			__( 'How many people registered in the last 30 days?', 'user-registration' ),
			__( 'How much did we earn this month?', 'user-registration' ),
			__( 'Which membership plans do I have?', 'user-registration' ),
		);
	}

	/**
	 * Add the Connect card to the AI Integration page, under the master switch.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $settings Settings of the AI Integration section.
	 * @return array
	 */
	public static function add_settings( $settings ) {
		if ( ! is_array( $settings ) || empty( $settings['sections'] ) ) {
			return $settings;
		}

		$card = array(
			'title'    => __( 'Connect an AI Assistant', 'user-registration' ),
			'type'     => 'card',
			'desc'     => '',
			'settings' => array( array( 'type' => 'ur_ai_connect' ) ),
		);

		$sections = array();

		foreach ( $settings['sections'] as $key => $section ) {
			$sections[ $key ] = $section;

			if ( 'ai_integration' === $key ) {
				$sections['ai_connect'] = $card;
			}
		}

		$settings['sections'] = $sections;

		return $settings;
	}

	/**
	 * Draw the Connect card.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $html  Markup so far.
	 * @param array  $field The field.
	 * @return string
	 */
	public static function render( $html, $field = array() ) {
		self::enqueue();

		ob_start();
		?>
		<div class="ur-ai-connect">
			<p class="ur-ai-connect__lead"><?php esc_html_e( 'Check the site is ready, then make a user just for the AI. It has no admin rights, so a leaked password cannot reach the rest of your site.', 'user-registration' ); ?></p>

			<h4><?php esc_html_e( 'Is the site ready?', 'user-registration' ); ?></h4>
			<ul class="ur-ai-connect__checks" id="ur-ai-checks"><?php echo self::checks_html( self::checks() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in checks_html(). ?></ul>
			<p><button type="button" class="button" id="ur-ai-check"><?php esc_html_e( 'Check again', 'user-registration' ); ?></button> <span class="ur-ai-connect__last" id="ur-ai-last"><?php echo esc_html( self::last_call_text() ); ?></span></p>

			<h4><?php esc_html_e( 'Connect an AI app', 'user-registration' ); ?></h4>
			<p>
				<label for="ur-ai-app"><?php esc_html_e( 'Name of the AI app', 'user-registration' ); ?></label>
				<input type="text" id="ur-ai-app" value="Claude" maxlength="100" />
				<button type="button" class="button button-primary" id="ur-ai-create"><?php esc_html_e( 'Create AI user and password', 'user-registration' ); ?></button>
				<button type="button" class="button" id="ur-ai-revoke"><?php esc_html_e( 'Remove all AI passwords', 'user-registration' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'The name shows in the activity log, so you can tell apps apart. The password is shown once. Copy it now.', 'user-registration' ); ?></p>
			<div class="ur-ai-connect__result" id="ur-ai-result" hidden></div>

			<h4><?php esc_html_e( 'Then ask it something', 'user-registration' ); ?></h4>
			<ul class="ur-ai-connect__prompts">
				<?php foreach ( self::prompts() as $prompt ) : ?>
					<li><span><?php echo esc_html( $prompt ); ?></span> <button type="button" class="button button-small ur-ai-copy" data-copy="<?php echo esc_attr( $prompt ); ?>"><?php esc_html_e( 'Copy', 'user-registration' ); ?></button></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php

		return $html . ob_get_clean();
	}

	/**
	 * Build the list of checks.
	 *
	 * @param array $checks Checks from self::checks().
	 * @return string
	 */
	private static function checks_html( $checks ) {
		$html = '';

		foreach ( $checks as $check ) {
			$html .= '<li class="' . ( $check['ok'] ? 'is-ok' : 'is-bad' ) . '"><span class="ur-ai-connect__mark" aria-hidden="true">' . ( $check['ok'] ? '&#10003;' : '&#10005;' ) . '</span><span>' . esc_html( $check['label'] ) . ( $check['ok'] ? '' : ' <em>' . esc_html( $check['fix'] ) . '</em>' ) . '</span></li>';
		}

		return $html;
	}

	/**
	 * Say when an AI app last made a call.
	 *
	 * @return string
	 */
	private static function last_call_text() {
		$call = self::last_call();

		if ( ! $call ) {
			return __( 'No AI app has connected yet.', 'user-registration' );
		}

		/* translators: 1: name of the AI app, 2: how long ago, for example 2 minutes. */
		return sprintf( __( 'Last AI activity: %1$s, %2$s ago.', 'user-registration' ), '' === (string) $call['app'] ? __( 'an AI app', 'user-registration' ) : $call['app'], human_time_diff( (int) $call['time'], time() ) );
	}

	/** Add the script and styles of the card. */
	private static function enqueue() {
		$css = '.ur-ai-connect h4{margin:20px 0 8px;font-size:14px}.ur-ai-connect h4:first-of-type{margin-top:8px}.ur-ai-connect__checks{margin:0;padding:0;list-style:none}'
			. '.ur-ai-connect__checks li{display:flex;gap:10px;align-items:baseline;padding:3px 0}.ur-ai-connect__checks .is-ok .ur-ai-connect__mark{color:#4cc741;font-weight:700}.ur-ai-connect__checks .is-bad .ur-ai-connect__mark{color:#ff4f55;font-weight:700}'
			. '.ur-ai-connect__checks em{display:block;font-style:normal;color:#646a7a;font-size:12px}.ur-ai-connect__last{margin-left:10px;color:#646a7a}'
			. '.ur-ai-connect input[type=text]{margin:0 8px 0 6px;min-width:200px}.ur-ai-connect__result{margin-top:12px;padding:14px;border:1px solid #e1e4ed;border-radius:4px;background:#f8f9fb}'
			. '.ur-ai-connect__result pre{margin:6px 0 10px;padding:10px;background:#fff;border:1px solid #e1e4ed;border-radius:4px;white-space:pre-wrap;word-break:break-all;font-size:12px}'
			. '.ur-ai-connect__tabs{display:flex;gap:6px;margin:10px 0 4px;flex-wrap:wrap}.ur-ai-connect__tabs .button.is-active{border-color:#475bb2;color:#475bb2}'
			. '.ur-ai-connect__prompts{margin:0;padding:0;list-style:none}.ur-ai-connect__prompts li{display:flex;gap:10px;align-items:center;padding:4px 0}.ur-ai-connect__secret{font-weight:600}';

		wp_register_style( 'user-registration-ai-connect', false, array(), UR_VERSION );
		wp_enqueue_style( 'user-registration-ai-connect' );
		wp_add_inline_style( 'user-registration-ai-connect', $css );
		wp_enqueue_script( 'user-registration-ai-connect', UR()->plugin_url() . '/assets/js/admin/ai-connect.js', array( 'jquery' ), UR_VERSION, true );
		wp_localize_script(
			'user-registration-ai-connect',
			'urAiConnect',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'working'   => __( 'Working...', 'user-registration' ),
					'copied'    => __( 'Copied', 'user-registration' ),
					'copy'      => __( 'Copy', 'user-registration' ),
					'failed'    => __( 'Something went wrong. Try again.', 'user-registration' ),
					'password'  => __( 'Password (shown once)', 'user-registration' ),
					'user'      => __( 'User', 'user-registration' ),
					'readyText' => __( 'Ready to paste', 'user-registration' ),
					'confirm'   => __( 'Remove every AI password? AI apps already connected stop working until you make a new one.', 'user-registration' ),
					'removed'   => __( 'Removed.', 'user-registration' ),
					'tabs'      => array(
						'claude' => 'Claude',
						'cursor' => 'Cursor',
						'codex'  => 'Codex',
						'prompt' => __( 'Ready prompt', 'user-registration' ),
					),
				),
			)
		);
	}

	/**
	 * Check a request from the card: the nonce and the rights to manage the site.
	 *
	 * @param bool $create Whether the request makes a user.
	 */
	private static function guard( $create = false ) {
		check_ajax_referer( self::NONCE, 'security' );

		if ( ! current_user_can( 'manage_options' ) || ( $create && ! current_user_can( 'create_users' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'user-registration' ) ), 403 );
		}
	}

	/** Make the AI user and password, and answer with what the AI app needs. */
	public static function ajax_create() {
		self::guard( true );

		$app    = isset( $_POST['app'] ) ? sanitize_text_field( wp_unslash( $_POST['app'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::guard().
		$result = self::create_connection( $app );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result + array( 'checks' => self::checks_html( self::checks() ) ) );
	}

	/** Run the checks again. */
	public static function ajax_check() {
		self::guard();

		wp_send_json_success(
			array(
				'checks' => self::checks_html( self::checks() ),
				'last'   => self::last_call_text(),
			)
		);
	}

	/** Remove every AI password. */
	public static function ajax_revoke() {
		self::guard();

		wp_send_json_success(
			array(
				'removed' => self::revoke_connections(),
				'checks'  => self::checks_html( self::checks() ),
			)
		);
	}
}
