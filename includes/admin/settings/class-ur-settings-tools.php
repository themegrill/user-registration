<?php
/**
 * UserRegistration Tools Settings Page
 *
 * Hosts Logs and System Info as sections of the Settings page's own rail,
 * between Advanced and License, replacing the old standalone Tools admin page.
 *
 * @package UserRegistration/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'UR_Settings_Tools' ) ) :

	/**
	 * UR_Settings_Tools Class.
	 */
	class UR_Settings_Tools extends UR_Settings_Page {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id    = 'tools';
			$this->label = __( 'Tools', 'user-registration' );
			parent::__construct();

			add_filter( "user_registration_get_sections_{$this->id}", array( $this, 'get_sections_callback' ), 1, 1 );
			add_filter( 'user_registration_settings_hide_save_button', array( $this, 'hide_save_button' ) );
			add_filter( "user_registration_settings_form_method_tab_{$this->id}", array( $this, 'get_form_method' ) );
			add_action( "user_registration_settings_header_actions_{$this->id}", array( $this, 'output_header_actions' ) );
			add_filter( "user_registration_settings_header_title_{$this->id}", array( $this, 'get_header_title' ) );
			add_filter( "user_registration_settings_header_icon_{$this->id}", array( $this, 'get_header_icon' ) );
		}

		/**
		 * "Delete all logs" in the options header, only while the list of
		 * 2+ log sources is on screen (a single log has its own Delete).
		 */
		public function output_header_actions() {
			global $current_section;

			if ( 'system_info' === $current_section ) {
				?>
				<div class="user-registration-options-header--top__right ur-system-info-actions">
					<button type="button" class="button button-primary ur-system-info-copy">
						<svg width="16" height="16" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
							<path d="M20 8H10C8.89543 8 8 8.89543 8 10V20C8 21.1046 8.89543 22 10 22H20C21.1046 22 22 21.1046 22 20V10C22 8.89543 21.1046 8 20 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M4 16C2.9 16 2 15.1 2 14V4C2 2.9 2.9 2 4 2H14C15.1 2 16 2.9 16 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<?php esc_html_e( 'Copy system info', 'user-registration' ); ?>
					</button>
					<span class="ur-copied-tip" aria-hidden="true"></span>
					<span id="ur-system-info-copy-status" class="screen-reader-text" role="status" aria-live="polite"></span>
				</div>
				<?php
				return;
			}

			include_once dirname( __DIR__ ) . '/class-ur-log-list-table.php';

			$viewing_single = ! empty( $_GET['log'] ) || ! empty( $_REQUEST['log_file'] ); // phpcs:ignore WordPress.Security.NonceVerification

			if ( ( $current_section && 'logs' !== $current_section ) || $viewing_single || count( UR_Log_List_Table::scan_sources() ) < 2 ) {
				return;
			}

			$url = wp_nonce_url(
				add_query_arg(
					array(
						'page'       => 'user-registration-settings',
						'tab'        => 'tools',
						'section'    => 'logs',
						'handle_all' => 'delete-all-logs',
					),
					admin_url( 'admin.php' )
				),
				'remove_all_logs'
			);
			?>
			<div class="user-registration-options-header--top__right">
				<a class="button button-tertiary ur-log-delete-link" href="<?php echo esc_url( $url ); ?>" data-confirm="<?php esc_attr_e( 'Delete all log files permanently? This can’t be undone.', 'user-registration' ); ?>">
					<?php esc_html_e( 'Delete all logs', 'user-registration' ); ?>
				</a>
			</div>
			<?php
		}

		/**
		 * The options header names the open section (Logs, System Info)
		 * rather than the tab.
		 *
		 * @param string $title Tab label.
		 * @return string
		 */
		public function get_header_title( $title ) {
			global $current_section;

			$sections = $this->get_sections();
			$section  = $current_section ? $current_section : 'logs';

			return isset( $sections[ $section ] ) ? $sections[ $section ] : $title;
		}

		/**
		 * Icon for the open section; add-on sections keep the tab icon.
		 *
		 * @param string $icon Tab icon name.
		 * @return string
		 */
		public function get_header_icon( $icon ) {
			global $current_section;

			$section = $current_section ? $current_section : 'logs';

			return in_array( $section, array( 'logs', 'system_info' ), true ) ? $section : $icon;
		}

		/**
		 * Tools has no settings to save: its search, filter, sort and bulk
		 * actions are plain GET requests, which also keeps them clear of the
		 * Settings save flow that reacts to any POST carrying a nonce.
		 *
		 * @return string
		 */
		public function get_form_method() {
			return 'get';
		}

		/**
		 * Sections shown under the Tools rail row: the two built-in tabs,
		 * plus anything an add-on registers via the (previously dead)
		 * user_registration_admin_status_tabs filter.
		 *
		 * @param array $sections Sections.
		 * @return array
		 */
		public function get_sections_callback( $sections ) {
			$sections['logs']        = __( 'Logs', 'user-registration' );
			$sections['system_info'] = __( 'System Info', 'user-registration' );

			/**
			 * Filter to add extra Tools tabs/sections (e.g. from Pro or an add-on).
			 * Historically applied but never rendered; now feeds this rail row.
			 *
			 * @param array $sections Slug => label.
			 */
			$sections = apply_filters( 'user_registration_admin_status_tabs', $sections );

			// Setup Wizard is intentionally never part of this rail; it stays
			// WP-sidebar-only for new installs (see class-ur-admin-welcome.php).
			unset( $sections['setup_wizard'] );

			return $sections;
		}

		/**
		 * Hide the page-level Save button on every Tools section: none of
		 * Logs, System Info, or an add-on's tab is a settings form.
		 *
		 * @param bool $hide Current value.
		 * @return bool
		 */
		public function hide_save_button( $hide ) {
			global $current_tab;

			return 'tools' === $current_tab ? true : $hide;
		}

		/**
		 * Render the active section's content.
		 */
		public function output() {
			global $current_section;

			$section = $current_section ? $current_section : 'logs';

			if ( 'logs' === $section ) {
				UR_Admin_Status::status_logs_file();
				return;
			}

			if ( 'system_info' === $section ) {
				UR_Admin_Status::system_info();
				return;
			}

			// An add-on-registered tab: give it a content hook, and fall
			// back to an explicit empty state if nothing renders into it.
			ob_start();
			/**
			 * Fires to render a Tools tab registered by an add-on.
			 *
			 * @param string $section The active section slug.
			 */
			do_action( 'user_registration_status_tab_content_' . $section, $section );
			$content = ob_get_clean();

			if ( '' === trim( $content ) ) {
				?>
				<div class="user-registration-card">
					<p><?php esc_html_e( 'Nothing to show here yet.', 'user-registration' ); ?></p>
				</div>
				<?php
				return;
			}

			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- already-rendered add-on markup, same trust boundary as any other action-hooked settings output.
		}

		/**
		 * Tools has no settings fields of its own to save.
		 */
		public function save() {}
	}

endif;

return new UR_Settings_Tools();
