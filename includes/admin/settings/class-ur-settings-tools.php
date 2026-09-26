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
