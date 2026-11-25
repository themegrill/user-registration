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
		add_action( 'wp_ajax_ur_ai_connect_remove', array( __CLASS__, 'ajax_remove' ) );
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
	 * Build the list of apps already connected, so a reload shows what exists instead of asking to start over.
	 *
	 * @since xx.xx.xx
	 *
	 * @return string Escaped markup, empty while there is no AI user.
	 */
	private static function connections_html() {
		$user = self::ai_user();

		if ( ! $user ) {
			return '';
		}

		$items = WP_Application_Passwords::get_user_application_passwords( $user->ID );
		$title = '<h4 class="ur-ai-conn__title">' . esc_html__( 'Active connections', 'user-registration' ) . '</h4>';

		if ( ! $items ) {
			/* translators: %s: login of the AI user. */
			return $title . '<p class="ur-ai-conn__empty">' . esc_html( sprintf( __( 'No AI app is connected yet. The AI user %s is ready.', 'user-registration' ), $user->user_login ) ) . '</p>';
		}

		/* translators: %s: login of the AI user. */
		$html = $title . '<p class="ur-ai-conn__lead">' . esc_html( sprintf( __( 'These apps reach your site as the AI user %s.', 'user-registration' ), $user->user_login ) ) . '</p><ul class="ur-ai-conn">';

		foreach ( $items as $item ) {
			/* translators: 1: how long ago the password was made, 2: when it was last used. */
			$meta  = sprintf( __( 'Added %1$s ago. %2$s', 'user-registration' ), human_time_diff( (int) $item['created'], time() ), empty( $item['last_used'] ) ? __( 'Never used.', 'user-registration' ) : sprintf( /* translators: %s: how long ago. */ __( 'Last used %s ago.', 'user-registration' ), human_time_diff( (int) $item['last_used'], time() ) ) );
			$html .= '<li><span><strong>' . esc_html( $item['name'] ) . '</strong><em>' . esc_html( $meta ) . '</em></span><button type="button" class="ur-ai-link ur-ai-remove-one" data-uuid="' . esc_attr( $item['uuid'] ) . '">' . esc_html__( 'Remove', 'user-registration' ) . '</button></li>';
		}

		return $html . '</ul>';
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
			'desktop' => wp_json_encode(
				array(
					'mcpServers' => array(
						$name => array(
							'command' => 'npx',
							'args'    => array( '-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${UR_AI_AUTH}' ),
							'env'     => array( 'UR_AI_AUTH' => 'Basic ' . $code ),
						),
					),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			),
			'claude'  => 'claude mcp add --transport http --scope user ' . $name . ' ' . $endpoint . ' --header "Authorization: Basic ' . $code . '"',
			'cursor'  => wp_json_encode(
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
			'codex'   => "[mcp_servers.{$name}]\nurl = \"{$endpoint}\"\nhttp_headers = { Authorization = \"Basic {$code}\" }",
			'prompt'  => 'Add an MCP server to your own configuration. Name: ' . $name . '. Type: HTTP. URL: ' . $endpoint . '. Header: Authorization: Basic ' . $code . '. Then list my registration forms to check it works.',
		);
	}

	/**
	 * The AI apps the drawer offers, in tab order.
	 *
	 * @since xx.xx.xx
	 *
	 * @return array Key => label, snippet (key in snippets()) and intro.
	 */
	public static function apps() {
		return array(
			'claude' => array(
				'label'    => 'Claude',
				'intro'    => __( 'One password works for both. Use the part for the Claude you run.', 'user-registration' ),
				'snippets' => array(
					array(
						'key'   => 'claude',
						'label' => 'Claude Code',
						'hint'  => __( 'Paste this command into your terminal. It saves the connection for every folder.', 'user-registration' ),
					),
					array(
						'key'   => 'desktop',
						'label' => 'Claude Desktop',
						'hint'  => __( 'Add this to claude_desktop_config.json (Settings, Developer, Edit Config), then restart Claude Desktop. It needs Node.js installed.', 'user-registration' ),
					),
				),
			),
			'codex'  => array(
				'label'    => 'Codex',
				'intro'    => __( 'Add this to ~/.codex/config.toml, then restart Codex.', 'user-registration' ),
				'snippets' => array( array( 'key' => 'codex' ) ),
			),
			'cursor' => array(
				'label'    => 'Cursor',
				'intro'    => __( 'Add this to ~/.cursor/mcp.json, or to Settings, MCP, then reload Cursor.', 'user-registration' ),
				'snippets' => array( array( 'key' => 'cursor' ) ),
			),
			'other'  => array(
				'label'    => __( 'Other', 'user-registration' ),
				'intro'    => __( 'Paste this into any AI app that can add an MCP server. It asks the app to set itself up.', 'user-registration' ),
				'snippets' => array( array( 'key' => 'prompt' ) ),
			),
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
	 * Questions to try once connected, for each kind of action. Reads only look. Writes and deletes change the site,
	 * so the matching ability has to be switched on in AI Abilities Access first.
	 *
	 * @since xx.xx.xx
	 *
	 * @return array Level (read, write, delete) => label, hint and questions.
	 */
	public static function prompts() {
		return array(
			'read'   => array(
				'label'     => __( 'Read', 'user-registration' ),
				'hint'      => __( 'These only look. Nothing on your site changes.', 'user-registration' ),
				'questions' => array(
					__( 'Give me a quick summary of my site: how many forms, members and membership plans I have.', 'user-registration' ),
					__( 'Who registered in the last 7 days, and which form did they use?', 'user-registration' ),
					__( 'Which members are still waiting for approval?', 'user-registration' ),
					__( 'How much did my memberships earn this month compared to last month?', 'user-registration' ),
					__( 'Which membership plans do I have, and how many members are on each?', 'user-registration' ),
				),
			),
			'write'  => array(
				'label'     => __( 'Write', 'user-registration' ),
				'hint'      => __( 'These change your site. Switch on the matching ability in AI Abilities Access first.', 'user-registration' ),
				'questions' => array(
					__( 'Create a registration form called Newsletter Signup with name and email fields.', 'user-registration' ),
					__( 'Approve the newest member who is waiting for approval.', 'user-registration' ),
					__( 'Extend the subscription of my newest member by 30 days.', 'user-registration' ),
					__( 'Resend the verification email to my latest member who has not verified yet.', 'user-registration' ),
				),
			),
			'delete' => array(
				'label'     => __( 'Delete', 'user-registration' ),
				'hint'      => __( 'These remove things for good. Switch on the matching ability first, and read the preview before you confirm.', 'user-registration' ),
				'questions' => array(
					__( 'Delete the member with the email test@example.com.', 'user-registration' ),
					__( 'Delete the membership plan called Test Plan.', 'user-registration' ),
					__( 'Delete the cancelled subscription of the member test@example.com.', 'user-registration' ),
				),
			),
		);
	}

	/**
	 * Add the Connect part to the AI Integration card, under the master switch.
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

		// The Connect part lives inside the AI Integration card, under the master switch.
		if ( isset( $settings['sections']['ai_integration']['settings'] ) ) {
			$settings['sections']['ai_integration']['settings'][] = array(
				'type'              => 'ur_ai_connect',
				// Shown only while the master switch is on, and the page shows or hides it as the switch is flipped.
				'display_condition' => array(
					'field'    => 'user_registration_enable_abilities',
					'operator' => 'equals',
					'value'    => 'yes',
					'case'     => 'insensitive',
				),
			);
		}

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

		$condition = class_exists( 'UR_Admin_Settings' ) && is_array( $field ) ? UR_Admin_Settings::get_display_condition_attributes( $field ) : array(
			'attrs'         => '',
			'initial_style' => '',
		);

		// With the settings tabs on, the Connect content is a tab of its own and there is no row or button here: only the
		// content, which the tabs script moves into that tab. With the tabs off, the row and its button open the drawer.
		$inline = class_exists( 'UR_Abilities_Tabs' ) && UR_Abilities_Tabs::enabled();

		ob_start();
		?>
		<?php if ( ! $inline ) : ?>
		<div class="ur-ai-connect user-registration-global-settings"<?php echo $condition['attrs'] . $condition['initial_style']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by the settings page. ?>>
			<label class="ur-label"><?php esc_html_e( 'Connect an AI Assistant', 'user-registration' ); ?> <?php echo ur_help_tip( __( 'Makes a separate AI user with no admin rights, so a leaked password cannot reach the rest of your site.', 'user-registration' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ur_help_tip(). ?></label>
			<div class="user-registration-global-settings--field ur-ai-connect__field">
				<button type="button" class="button button-primary" id="ur-ai-open"><?php esc_html_e( 'Connect', 'user-registration' ); ?></button>
				<span class="ur-ai-connect__last" id="ur-ai-last-card"><?php echo esc_html( self::last_call_text() ); ?></span>
			</div>
		</div>
		<?php endif; ?>

		<div class="ur-ai-drawer-overlay" id="ur-ai-overlay" hidden></div>
		<aside class="ur-ai-drawer" id="ur-ai-drawer" role="dialog" aria-modal="true" aria-labelledby="ur-ai-drawer-title" hidden>
			<header class="ur-ai-drawer__header">
				<h2 id="ur-ai-drawer-title"><?php esc_html_e( 'Connect an AI Assistant', 'user-registration' ); ?></h2>
				<button type="button" class="ur-ai-drawer__close" id="ur-ai-close" aria-label="<?php esc_attr_e( 'Close', 'user-registration' ); ?>">&times;</button>
			</header>
			<div class="ur-ai-drawer__body ur-ai-connect">
			<div class="ur-ai-topbar">
					<div class="ur-ai-apps ur-ai-apps--center" role="tablist">
						<?php foreach ( self::apps() as $key => $app ) : ?>
							<button type="button" role="tab" class="ur-ai-apps__tab<?php echo 'claude' === $key ? ' is-active' : ''; ?>" data-app="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $app['label'] ); ?></button>
						<?php endforeach; ?>
					</div>
				<div class="ur-ai-ready" id="ur-ai-ready">
					<?php
					$checks  = self::checks();
					$summary = self::checks_summary( $checks );
					?>
					<button type="button" class="ur-ai-ready__status <?php echo $summary['ok'] ? 'is-ok' : 'is-bad'; ?>" id="ur-ai-ready-status" aria-haspopup="true" aria-expanded="false" aria-controls="ur-ai-ready-pop"><?php echo esc_html( $summary['text'] ); ?></button>
					<div class="ur-ai-ready__pop" id="ur-ai-ready-pop" role="dialog" aria-label="<?php esc_attr_e( 'Site checks', 'user-registration' ); ?>" hidden>
						<div class="ur-ai-ready__pophead"><strong><?php esc_html_e( 'Site checks', 'user-registration' ); ?></strong><button type="button" class="button button-small" id="ur-ai-check"><?php esc_html_e( 'Check again', 'user-registration' ); ?></button></div>
						<ul class="ur-ai-connect__checks" id="ur-ai-checks"><?php echo self::checks_html( $checks ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in checks_html(). ?></ul>
					</div>
				</div>
			</div>

			<section class="ur-ai-connect-main">
				<div class="ur-ai-panel">
					<h5 id="ur-ai-panel-title"></h5>
					<p id="ur-ai-panel-intro"></p>
					<label class="ur-ai-consent">
						<input type="checkbox" id="ur-ai-consent" />
						<span id="ur-ai-consent-text" data-alt="<?php echo esc_attr( self::consent_text( __( 'Generate password', 'user-registration' ) ) ); ?>"><?php echo wp_kses( self::consent_text( __( 'Generate prompt', 'user-registration' ) ), array( 'strong' => array() ) ); ?></span>
					</label>
					<div class="ur-ai-panel__actions">
						<button type="button" class="button button-primary" id="ur-ai-create" data-alt="<?php esc_attr_e( 'Generate password', 'user-registration' ); ?>" disabled><?php esc_html_e( 'Generate prompt', 'user-registration' ); ?></button>
						<button type="button" class="ur-ai-link" id="ur-ai-manual-toggle" data-alt="<?php esc_attr_e( 'Back to the prompt', 'user-registration' ); ?>"><?php esc_html_e( 'Prefer manual configuration?', 'user-registration' ); ?></button>
					</div>
					<div id="ur-ai-prompts" data-empty="<?php esc_attr_e( 'Your setup prompt will appear here after you generate it.', 'user-registration' ); ?>"></div>
					<p class="description" id="ur-ai-once" hidden><?php esc_html_e( 'The password is shown once. Copy it now. The app name shows in the activity log.', 'user-registration' ); ?></p>
					<dl class="ur-ai-manual" id="ur-ai-manual" hidden>
						<dt><?php esc_html_e( 'Server address', 'user-registration' ); ?></dt>
						<dd><code id="ur-ai-m-endpoint"><?php echo esc_html( rest_url( self::ROUTE ) ); ?></code></dd>
						<dt><?php esc_html_e( 'User', 'user-registration' ); ?></dt>
						<dd><code id="ur-ai-m-user">-</code></dd>
						<dt><?php esc_html_e( 'Application password', 'user-registration' ); ?></dt>
						<dd><code id="ur-ai-m-pass">-</code> <button type="button" class="button button-small" id="ur-ai-copy-pass" hidden><?php esc_html_e( 'Copy', 'user-registration' ); ?></button></dd>
						<dt><?php esc_html_e( 'How it signs in', 'user-registration' ); ?></dt>
						<dd><?php esc_html_e( 'HTTP header Authorization: Basic, with the user and the application password.', 'user-registration' ); ?></dd>
					</dl>
				</div>
			</section>

			<section class="ur-ai-active">
				<div id="ur-ai-connections"><?php echo self::connections_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in connections_html(). ?></div>
				<p class="ur-ai-connect__remove"><button type="button" class="ur-ai-link" id="ur-ai-revoke"><?php esc_html_e( 'Remove all AI passwords', 'user-registration' ); ?></button></p>
				<p class="ur-ai-connect__notice" id="ur-ai-result" role="status" hidden></p>
			</section>

			<section class="ur-ai-tryasking">
				<?php $asks = self::prompts(); ?>
				<p class="ur-ai-try"><span class="ur-ai-try__label"><?php esc_html_e( 'Try asking', 'user-registration' ); ?></span> <span class="ur-ai-try__text" id="ur-ai-ask-text"><?php echo esc_html( $asks['read']['questions'][0] ); ?></span></p>
				<p class="ur-ai-try__hint" id="ur-ai-ask-hint" hidden></p>
				<p class="ur-ai-try__actions"><button type="button" class="ur-ai-link" id="ur-ai-ask-copy"><?php esc_html_e( 'Copy', 'user-registration' ); ?></button><button type="button" class="ur-ai-link" id="ur-ai-ask-next"><?php esc_html_e( 'Another question', 'user-registration' ); ?></button></p>
			</section>
			</div>
		</aside>
		<?php

		return $html . ob_get_clean();
	}

	/**
	 * The words next to the agreement box. Once the AI user exists, only a new password is made.
	 *
	 * @param string $button Label of the button the sentence names.
	 * @return string
	 */
	private static function consent_text( $button ) {
		if ( self::ai_user() ) {
			/* translators: %s: label of the button. */
			return sprintf( __( 'I understand that clicking <strong>%s</strong> will create a new application password for the existing AI user.', 'user-registration' ), esc_html( $button ) );
		}

		/* translators: %s: label of the button. */
		return sprintf( __( 'I understand that clicking <strong>%s</strong> will create a user and an application password for this site, just for the AI.', 'user-registration' ), esc_html( $button ) );
	}

	/**
	 * Say in one line how the checks went, so the list itself can stay folded away while everything passes.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $checks Checks from self::checks().
	 * @return array ok (whether everything passed) and text.
	 */
	public static function checks_summary( $checks ) {
		$failed = count(
			array_filter(
				$checks,
				function ( $check ) {
					return empty( $check['ok'] );
				}
			)
		);

		if ( 0 === $failed ) {
			return array(
				'ok'   => true,
				'text' => __( 'Your site is ready', 'user-registration' ),
			);
		}

		return array(
			'ok'   => false,
			/* translators: %d: number of checks that did not pass. */
			'text' => sprintf( _n( '%d thing needs attention', '%d things need attention', $failed, 'user-registration' ), $failed ),
		);
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
		$css = '.user-registration-global-settings.ur-ai-connect .user-registration-global-settings--field.ur-ai-connect__field{display:flex;flex-direction:row;align-items:center;justify-content:flex-start;gap:12px;text-align:left}.ur-ai-connect__field .button{margin:0}.ur-ai-connect__last{color:#646a7a}'
			. '.user-registration-global-settings.ur-ai-connect > label.ur-label{display:flex;align-items:center}.user-registration-global-settings.ur-ai-connect > label.ur-label .user-registration-help-tip{display:inline-flex;align-items:center;justify-content:center;flex:none;width:16px;height:16px;margin:0 0 0 6px;padding:0;top:0 !important;line-height:1;vertical-align:middle}.user-registration-global-settings.ur-ai-connect > label.ur-label .user-registration-help-tip::after{width:16px !important;height:16px !important;padding:0 !important;box-sizing:border-box;background-size:contain !important}'
			. '.ur-ai-drawer-overlay{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.05);backdrop-filter:blur(1px)}'
			. '.ur-ai-drawer{position:fixed;top:var(--wp-admin--admin-bar--height,32px);right:0;bottom:0;z-index:100001;width:760px;max-width:100vw;display:flex;flex-direction:column;background:#fff;box-shadow:-8px 0 30px rgba(45,53,89,.15);color:#2d3559;transform:translateX(100%);transition:transform .25s ease}'
			. '.ur-ai-drawer.is-open{transform:none}.ur-ai-drawer[hidden],.ur-ai-drawer-overlay[hidden]{display:none}'
			. '.ur-ai-drawer__header{display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid #e1e4ed}.ur-ai-drawer__header h2{margin:0;font-size:18px;font-weight:600;color:#2d3559}'
			. '.ur-ai-drawer__close{border:0;background:none;font-size:28px;line-height:1;color:#646a7a;cursor:pointer;padding:0 4px}.ur-ai-drawer__close:hover{color:#2d3559}'
			. '.ur-ai-drawer__body{flex:1;overflow-y:auto;padding:8px 24px 32px}'
			. ''
			. ''
			. '.ur-ai-connect-main{margin:0 0 24px}.ur-ai-apps--center{margin:0 auto 22px}.ur-ai-active{margin:0 0 24px}.ur-ai-tryasking{margin:0;padding:14px 0 0;border-top:1px solid #f0f2f7;color:#646a7a;font-size:13px;line-height:1.5}.ur-ai-tryasking p{margin:0}.ur-ai-try__label{margin-right:6px;font-weight:600;color:#8a90a2}.ur-ai-try__text::before{content:"\201C"}.ur-ai-try__text::after{content:"\201D"}.ur-ai-try__hint{margin-top:2px !important;font-size:12px;color:#8a90a2}.ur-ai-try__actions{margin-top:6px !important}.ur-ai-try__actions .ur-ai-link{margin-right:14px;font-size:12px}.ur-ai-connect .ur-ai-conn__title{margin:0 0 6px;font-size:15px;font-weight:600;color:#2d3559}'
			. '.ur-ai-topbar{position:relative;display:flex;align-items:center;justify-content:center;margin:0 0 22px}.ur-ai-topbar .ur-ai-apps--center{margin:0}.ur-ai-ready{position:absolute;top:50%;right:0;transform:translateY(-50%);z-index:5}.ur-ai-ready__status{display:inline-flex;align-items:center;margin:0;padding:6px 14px;border:1px solid #e1e4ed;border-radius:999px;background:#fff;font-size:13px;font-weight:600;cursor:pointer;line-height:1.4}.ur-ai-ready__status:hover{border-color:#c5cbe0}.ur-ai-ready__status.is-ok{color:#2e7d32}.ur-ai-ready__status.is-ok::before{content:"\2713";margin-right:8px}.ur-ai-ready__status.is-bad{color:#c62828;border-color:#f1c4c4;background:#fff8f8}.ur-ai-ready__status.is-bad::before{content:"\2715";margin-right:8px}.ur-ai-ready__status.is-loading{color:#646a7a}.ur-ai-ready__status.is-loading::before{content:"";display:inline-block;width:12px;height:12px;margin-right:8px;border:2px solid #e1e4ed;border-top-color:#475bb2;border-radius:50%;animation:urAiSpin .7s linear infinite}'
			. '.ur-ai-ready__pop{position:absolute;top:calc(100% + 8px);right:0;width:340px;max-width:80vw;padding:14px 16px;border:1px solid #e1e4ed;border-radius:8px;background:#fff;box-shadow:0 8px 28px rgba(45,53,89,.16)}.ur-ai-ready__pop[hidden]{display:none}.ur-ai-ready__pophead{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 8px;padding:0 0 10px;border-bottom:1px solid #f0f2f7;color:#2d3559}.ur-ai-ready__pop .ur-ai-connect__checks li{padding:4px 0;font-size:13px}@media (max-width:760px){.ur-ai-topbar{flex-direction:column;gap:12px}.ur-ai-ready{position:static;transform:none}.ur-ai-ready__pop{left:50%;right:auto;transform:translateX(-50%)}}'
			. ''
			. '.ur-ai-connect h4{display:flex;align-items:center;gap:10px;margin:24px 0 10px;font-size:15px;font-weight:600;color:#2d3559}'
			. ''
			. '.ur-ai-connect__label{display:block;margin-bottom:4px;font-weight:500}.ur-ai-drawer .ur-ai-connect input[type=text]{width:100%;margin:0;box-sizing:border-box}'
			. '.ur-ai-connect__checks{margin:0;padding:0;list-style:none}'
			. '.ur-ai-connect__checks li{display:flex;gap:10px;align-items:baseline;padding:3px 0}.ur-ai-connect__checks .is-ok .ur-ai-connect__mark{color:#4cc741;font-weight:700}.ur-ai-connect__checks .is-bad .ur-ai-connect__mark{color:#ff4f55;font-weight:700}'
			. '.ur-ai-connect__checks em{display:block;font-style:normal;color:#646a7a;font-size:12px}.ur-ai-connect__last{margin-left:10px;color:#646a7a}'
			. '.ur-ai-connect h4 .ur-ai-connect__recheck{margin:0 0 0 auto;font-weight:400}'
			. '@keyframes urAiSpin{to{transform:rotate(360deg)}}.ur-ai-connect__checks .is-loading .ur-ai-connect__mark{position:relative;color:transparent;width:12px;height:12px;flex:none}'
			. '.ur-ai-connect__checks .is-loading .ur-ai-connect__mark::after{content:"";position:absolute;inset:0;border:2px solid #e1e4ed;border-top-color:#475bb2;border-radius:50%;animation:urAiSpin .7s linear infinite}.ur-ai-connect__checks .is-loading em{display:none}'
			. '.ur-ai-conn{margin:0 0 8px;padding:0;list-style:none;border:1px solid #e1e4ed;border-radius:4px}.ur-ai-conn li{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border-bottom:1px solid #f0f2f7}.ur-ai-conn li:last-child{border-bottom:0}'
			. '.ur-ai-conn em{display:block;font-style:normal;font-size:12px;color:#646a7a}.ur-ai-conn__lead,.ur-ai-conn__empty{margin:0 0 8px;color:#2d3559}.ur-ai-conn__empty{margin-bottom:8px;color:#646a7a}'
			. '.ur-ai-apps{display:flex;gap:4px;flex-wrap:wrap;padding:4px;border:1px solid #e1e4ed;border-radius:999px;width:fit-content;max-width:100%}'
			. '.ur-ai-apps__tab{border:0;background:none;padding:6px 14px;border-radius:999px;color:#646a7a;font-size:13px;cursor:pointer}.ur-ai-apps__tab:hover{color:#2d3559}.ur-ai-apps__tab.is-active{background:#eef1fb;color:#475bb2;font-weight:600}'
			. '.ur-ai-panel{margin-top:18px}.ur-ai-panel h5{margin:0 0 6px;font-size:15px;font-weight:600;color:#2d3559}.ur-ai-panel p{margin:0 0 12px}'
			. '.ur-ai-consent{display:flex;gap:10px;align-items:flex-start;margin:0 0 14px;line-height:1.5}.ur-ai-consent input{margin:3px 0 0;flex:none}'
			. '.ur-ai-panel__actions{display:flex;align-items:center;gap:18px;margin-bottom:14px}.ur-ai-panel__actions .button{height:34px;line-height:32px;padding:0 16px;margin:0}'
			. '.ur-ai-link{border:0;background:none;padding:0;color:#475bb2;text-decoration:underline;cursor:pointer;font-size:13px}.ur-ai-connect__remove{margin:18px 0 0}.ur-ai-connect__notice{margin:8px 0 0;color:#646a7a}'
			. '.ur-ai-prompt{position:relative}#ur-ai-prompts[hidden]{display:none}.ur-ai-prompt{margin-bottom:14px}.ur-ai-prompt__label{margin:0 0 2px;font-weight:600;color:#2d3559}.ur-ai-prompt__hint{margin:0 0 8px;color:#646a7a}.ur-ai-prompt pre{margin:0;min-height:80px;max-height:260px;overflow:auto;padding:14px 14px 48px;border:1px solid #e1e4ed;border-radius:4px;background:#f8f9fb;white-space:pre-wrap;word-break:break-all;font-size:12px;color:#2d3559}'
			. '.ur-ai-prompt pre:empty:before{content:attr(data-empty);color:#8a90a2}.ur-ai-prompt .button{position:absolute;right:10px;bottom:10px;height:30px;line-height:28px;padding:0 14px;margin:0}'
			. '.ur-ai-manual{margin:12px 0 0;padding:12px 14px;border:1px solid #e1e4ed;border-radius:4px}.ur-ai-manual dt{margin-top:8px;font-weight:600}.ur-ai-manual dt:first-child{margin-top:0}.ur-ai-manual dd{margin:2px 0 0;word-break:break-all}'
			. ''
			. ''
			. '';

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
				'apps'    => self::apps(),
				// With the settings tabs on, the Connect content is a tab of its own, so the button switches tab instead of opening the drawer.
				'inline'  => class_exists( 'UR_Abilities_Tabs' ) && UR_Abilities_Tabs::enabled(),
				'asks'    => self::prompts(),
				'i18n'    => array(
					'working'    => __( 'Working...', 'user-registration' ),
					'copied'     => __( 'Copied', 'user-registration' ),
					'copy'       => __( 'Copy', 'user-registration' ),
					'failed'     => __( 'Something went wrong. Try again.', 'user-registration' ),
					/* translators: %s: name of the AI app. */
					'title'      => __( 'Connect this site to %s', 'user-registration' ),
					'confirm'    => __( 'Remove every AI password? AI apps already connected stop working until you make a new one.', 'user-registration' ),
					'confirmOne' => __( 'Remove this AI app? It stops working until you make a new password for it.', 'user-registration' ),
					'ready'      => __( 'Your site is ready', 'user-registration' ),
					/* translators: %d: number of checks that did not pass. */
					'attention1' => __( '1 thing needs attention', 'user-registration' ),
					/* translators: %d: number of checks that did not pass. */
					'attentionN' => __( '%d things need attention', 'user-registration' ),
					'checking'   => __( 'Checking...', 'user-registration' ),
					'removed'    => __( 'Removed.', 'user-registration' ),
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

		wp_send_json_success(
			$result + array(
				'checks'      => self::checks_html( self::checks() ),
				'connections' => self::connections_html(),
				'consent'     => array(
					'prompt'   => self::consent_text( __( 'Generate prompt', 'user-registration' ) ),
					'password' => self::consent_text( __( 'Generate password', 'user-registration' ) ),
				),
			)
		);
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
				'removed'     => self::revoke_connections(),
				'checks'      => self::checks_html( self::checks() ),
				'connections' => self::connections_html(),
			)
		);
	}

	/** Remove the password of one AI app, and only of the AI user. */
	public static function ajax_remove() {
		self::guard();

		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::guard().
		$user = self::ai_user();

		if ( ! $user || '' === $uuid || ! WP_Application_Passwords::delete_application_password( $user->ID, $uuid ) ) {
			wp_send_json_error( array( 'message' => __( 'That connection was not found.', 'user-registration' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'checks'      => self::checks_html( self::checks() ),
				'connections' => self::connections_html(),
			)
		);
	}
}
