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
			add_action( 'admin_footer', array( $this, 'output_setup_wizard_script' ) );
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
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
							<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
							<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
						</svg>
						<?php esc_html_e( 'Copy System Info', 'user-registration' ); ?>
					</button>
					<span class="ur-copied-tip" aria-hidden="true"><?php esc_html_e( 'Copied!', 'user-registration' ); ?></span>
					<span id="ur-system-info-copy-status" class="screen-reader-text" role="status" aria-live="polite"></span>
				</div>
				<?php
				return;
			}

			$viewing_single = ! empty( $_GET['log'] ) || ! empty( $_REQUEST['log_file'] ); // phpcs:ignore WordPress.Security.NonceVerification

			if ( ( $current_section && 'logs' !== $current_section ) || $viewing_single ) {
				return;
			}

			include_once dirname( __DIR__ ) . '/class-ur-log-list-table.php';

			$sources = UR_Log_List_Table::scan_sources();

			if ( count( $sources ) < 2 ) {
				return;
			}

			$total_file_count = 0;
			foreach ( $sources as $source_item ) {
				$total_file_count += count( $source_item['files'] );
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
				<a class="button button-tertiary ur-log-delete-all" href="<?php echo esc_url( $url ); ?>" data-files="<?php echo esc_attr( $total_file_count ); ?>" data-sources="<?php echo esc_attr( count( $sources ) ); ?>">
					<?php esc_html_e( 'Delete All Logs', 'user-registration' ); ?>
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
		 * Form method for Tools tab.
		 *
		 * Built-in sections (logs, system info) use GET, while add-on sections with
		 * settings fields use POST to allow saving options.
		 *
		 * @return string
		 */
		public function get_form_method() {
			global $current_section;

			if ( in_array( $current_section, array( '', 'logs', 'system_info', 'setup_wizard' ), true ) ) {
				return 'get';
			}

			return 'post';
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
			$sections['logs']         = __( 'Logs', 'user-registration' );
			$sections['system_info']  = __( 'System Info', 'user-registration' );
			$sections['setup_wizard'] = __( 'Setup Wizard', 'user-registration' );

			/**
			 * Filter to add extra Tools tabs/sections (e.g. from Pro or an add-on).
			 * Historically applied but never rendered; now feeds this rail row.
			 *
			 * @param array $sections Slug => label.
			 */
			return apply_filters( 'user_registration_admin_status_tabs', $sections );
		}

		/**
		 * Hide the page-level Save button on built-in Tools sections.
		 *
		 * @param bool $hide Current value.
		 * @return bool
		 */
		public function hide_save_button( $hide ) {
			global $current_tab, $current_section;

			if ( 'tools' === $current_tab ) {
				if ( in_array( $current_section, array( '', 'logs', 'system_info', 'setup_wizard' ), true ) ) {
					return true;
				}

				$settings = apply_filters( 'user_registration_get_settings_tools', array(), $current_section );
				return empty( $settings );
			}

			return $hide;
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

			if ( 'setup_wizard' === $section ) {
				?>
				<script>
					window.location.href = '<?php echo esc_js( admin_url( 'admin.php?page=user-registration-welcome&tab=setup-wizard' ) ); ?>';
				</script>
				<?php
				return;
			}

			ob_start();
			/**
			 * Fires to render a Tools tab registered by an add-on.
			 *
			 * @param string $section The active section slug.
			 */
			do_action( 'user_registration_status_tab_content_' . $section, $section );
			$content = ob_get_clean();

			if ( '' !== trim( $content ) ) {
				echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- already-rendered add-on markup, same trust boundary as any other action-hooked settings output.
				return;
			}

			$settings = apply_filters( 'user_registration_get_settings_tools', array(), $section );
			if ( ! empty( $settings ) ) {
				UR_Admin_Settings::output_fields( $settings );
				return;
			}
			?>
			<div class="user-registration-card">
				<p><?php esc_html_e( 'Nothing to show here yet.', 'user-registration' ); ?></p>
			</div>
			<?php
		}

		/**
		 * Output confirmation modal script for Setup Wizard rail link.
		 */
		public function output_setup_wizard_script() {
			global $current_tab;

			if ( 'tools' !== $current_tab ) {
				return;
			}

			$wizard_url = admin_url( 'admin.php?page=user-registration-welcome&tab=setup-wizard' );
			?>
			<script>
				( function () {
					document.addEventListener( 'click', function ( e ) {
						var link = e.target.closest ? e.target.closest( 'a[href*="section=setup_wizard"], a[href*="tab=setup-wizard"]' ) : null;
						if ( ! link ) {
							return;
						}
						e.preventDefault();
						e.stopPropagation();

						var wizardUrl = '<?php echo esc_js( $wizard_url ); ?>';

						if ( typeof Swal !== 'undefined' ) {
							Swal.fire( {
								title: '<?php echo esc_js( __( 'Proceed to Setup Wizard?', 'user-registration' ) ); ?>',
								text: '<?php echo esc_js( __( 'You are about to leave this page and open the Setup Wizard.', 'user-registration' ) ); ?>',
								showCancelButton: true,
								focusCancel: true,
								confirmButtonText: '<?php echo esc_js( __( 'Confirm', 'user-registration' ) ); ?>',
								cancelButtonText: '<?php echo esc_js( __( 'Cancel', 'user-registration' ) ); ?>',
								buttonsStyling: false,
								customClass: {
									popup: 'ur-tools-modal',
									title: 'ur-tools-modal__title',
									htmlContainer: 'ur-tools-modal__content',
									actions: 'ur-tools-modal__actions',
									cancelButton: 'ur-tools-delete-modal__cancel',
									confirmButton: 'ur-tools-delete-modal__confirm'
								}
							} ).then( function ( result ) {
								if ( result.isConfirmed || result.value ) {
									window.location.href = wizardUrl;
								}
							} );
						} else if ( window.confirm( '<?php echo esc_js( __( 'You are about to leave this page and open the Setup Wizard.', 'user-registration' ) ); ?>' ) ) {
							window.location.href = wizardUrl;
						}
					} );
				}() );
			</script>
			<?php
		}

		/**
		 * Save settings for custom Tools sections registered by add-ons.
		 */
		public function save() {
			global $current_section;

			if ( ! in_array( $current_section, array( '', 'logs', 'system_info', 'setup_wizard' ), true ) ) {
				$settings = apply_filters( 'user_registration_get_settings_tools', array(), $current_section );
				if ( ! empty( $settings ) ) {
					UR_Admin_Settings::save_fields( $settings );
				}
				do_action( 'user_registration_settings_save_tools_section_' . $current_section );
			}
		}
	}

endif;

return new UR_Settings_Tools();
