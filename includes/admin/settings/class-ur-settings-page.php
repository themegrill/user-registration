<?php
/**
 * UserRegistration Settings Page/Tab
 *
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'UR_Settings_Page', false ) ) :

	/**
	 * UR_Settings_Page.
	 */
	abstract class UR_Settings_Page {

		/**
		 * Setting page id.
		 *
		 * @var string
		 */
		protected $id = '';

		/**
		 * Setting page label.
		 *
		 * @var string
		 */
		protected $label = '';

		/**
		 * List of sections.
		 *
		 * @var array
		 */
		protected $sections = array();

		/**
		 * Constructor.
		 */
		public function __construct() {
			// nav link (left sidebar).
			add_filter( 'user_registration_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );

			// vertical tab-like view for sections.
			add_action( 'user_registration_section_parts_' . $this->id, array( $this, 'output_section_parts' ) );
			add_action( 'user_registration_sections_' . $this->id, array( $this, 'output_sections' ), 10, 1 );

			// main content : options fields as UI.
			add_action( 'user_registration_settings_' . $this->id, array( $this, 'output' ) );

			// default section. ( automatically selects first section if not set ).
			add_filter( "user_registration_settings_{$this->id}_default_section", array( $this, 'get_default_section' ) );

			// save settings.
			add_action( 'user_registration_settings_save_' . $this->id, array( $this, 'save' ) );
		}

		/**
		 * Get default section.
		 *
		 * @param string $default_section Default Section.
		 */
		public function get_default_section( $default_section ) {
			return $this->get_sections() ? array_key_first( $this->get_sections() ) : $default_section;
		}

		/**
		 * Get settings page ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return $this->id;
		}

		/**
		 * Get settings page label.
		 *
		 * @return string
		 */
		public function get_label() {
			return $this->label;
		}

		/**
		 * Add this page to settings.
		 *
		 * @param  array $pages Pages.
		 * @return mixed
		 */
		public function add_settings_page( $pages ) {
			$pages[ $this->id ] = $this->label;

			return $pages;
		}

		/**
		 * Get settings array.
		 *
		 * @return array
		 */
		public function get_settings() {
			/**
			 * Filter to retrieve the settings
			 *
			 * @param array Array of settings to be retrieved.
			 */
			$settings = apply_filters( 'user_registration_get_settings_' . $this->id, array() );
			/**
			 * Backward compatibility: previous settings section.
			 */
			return $settings;
		}

		/**
		 * Get sections.
		 *
		 * @return array
		 */
		public function get_sections() {
			/**
			 * Filter to retrieve the sections.
			 *
			 * @param array Array of sections to retrieve.
			 */
			$sections = apply_filters( 'user_registration_get_sections_' . $this->id, $this->sections );

			return $sections;
		}

		/**
		 * Output sections.
		 */
		public function output_sections() {
			global $current_section;

			$active_section = $current_section;

			if ( 'email' === $this->id && is_string( $current_section ) && 0 === strpos( $current_section, 'ur_settings_' ) && method_exists( $this, 'get_emails' ) ) {
				$emails = $this->get_emails();
				if ( is_array( $emails ) ) {
					foreach ( $emails as $email ) {
						if ( ! isset( $email->id, $email->receiver ) ) {
							continue;
						}
						if ( 'ur_settings_' . $email->id !== $current_section ) {
							continue;
						}
						$receiver = strtolower( $email->receiver );
						if ( 'admin' === $receiver ) {
							$active_section = 'to-admin';
						} elseif ( 'user' === $receiver ) {
							$active_section = 'to-user';
						}
						break;
					}
				}
			}

			if ( 'email' === $this->id && is_string( $current_section ) && 0 === strpos( $current_section, 'ur_settings_custom_email_' ) ) {
				$active_section = 'custom-email';
			}
			$sections = $this->get_sections();

			if ( empty( $sections ) ) {
				return;
			}

			echo '<ul class="subsubsub  ur-scroll-ui__items" style="display: flex; flex-direction: column;">';

			$array_keys = array_keys( $sections );

			global $tabs;
			$tab_slugs = is_array( $tabs ) ? array_keys( $tabs ) : array();

			foreach ( $sections as $id => $label ) {
				$premium_tabs      = ur_premium_settings_tab();
				$premium_tab       = urm_array_key_exists_recursive( $id, $premium_tabs );
				$show_premium_icon = false;
				$show_section      = true;
				if ( ! empty( $premium_tab ) ) {
					$license_data = ur_get_license_plan();
					$license_plan = ! empty( $license_data->item_plan ) ? $license_data->item_plan : false;
					$license_plan = trim( str_replace( 'lifetime', '', strtolower( $license_plan ) ) );

					if ( ! empty( $premium_tab[ $id ]['plan'] ) ) {
						$id_bc = str_replace( '-', '_', $id );

						if ( isset( $premium_tab[ $id ]['plugin'] ) && is_plugin_active( $premium_tab[ $id ]['plugin'] . '/' . $premium_tab[ $id ]['plugin'] . '.php' ) && ( in_array( $premium_tab[ $id ]['plugin'], $tab_slugs ) || in_array( $id, $tab_slugs ) || in_array( $id_bc, $tab_slugs ) ) ) {
							$show_section = false;
						}
						// woocommerce compatibility.
						if ( 'woocommerce' === $id && ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
							$show_section = false;
						}

						if ( in_array( $license_plan, $premium_tab[ $id ]['plan'], true ) ) {
							$show_premium_icon = false;
						} elseif ( file_exists( WP_PLUGIN_DIR . '/' . $premium_tab[ $id ]['plugin'] ) && is_plugin_active( $premium_tab[ $id ]['plugin'] . '/' . $premium_tab[ $id ]['plugin'] . '.php' ) ) {
							$show_premium_icon = false;
						} else {
							$show_premium_icon = true;
						}
					} else {
						$show_premium_icon = $premium_tab ? true : false;
					}
				}
				if ( $show_section ) {
					ob_start();
					?>
					<li <?php echo ( $active_section === $id ? ' class="current" ' : '' ); ?>>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=' . $this->id . '&section=' . sanitize_title( $id ) ) ); ?>" class="<?php echo( $active_section === $id ? 'current' : '' ); ?> ur-scroll-ui__item">
							<span class="timeline"></span>
							<span class="submenu"><?php echo esc_html( $label ); ?></span>
							<?php if ( $show_premium_icon ) : ?>
								<img style="width: 14px; height: 14px;margin-left: 4px;" src="<?php echo esc_url( UR()->plugin_url() . '/assets/images/icons/ur-pro-icon.png' ); ?>" />
								<?php
							endif;
							?>
						</a>
					</li>
					<?php
					echo ob_get_clean(); // phpcs:ignore
				}
			}

			echo '</ul>';
		}

		/**
		 * Get section parts.
		 *
		 * @return array
		 */
		public function get_section_parts() {
			return apply_filters(
				'user_registration_get_section_parts_' . $this->id,
				array()
			);
		}

		/**
		 * Output section parts.
		 *
		 * @return void
		 */
		public function output_section_parts() {
			global $current_section;
			global $current_section_part;
			$sections = $this->get_section_parts();
			if ( empty( $sections ) ) {
				return;
			}
			echo '<ul class="subsubsub user-registration-settings-parts" style="display: flex;">';

			foreach ( $sections as $id => $label ) {
				ob_start();
				?>
				<li <?php echo ( $current_section_part === $id ? ' class="current ur-scroll-ui__item" ' : 'ur-scroll-ui__item' ); ?>>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=' . $this->id . '&section=' . $current_section . '&part=' . sanitize_title( $id ) ) ); ?>" class="<?php echo( $current_section_part === $id ? 'current' : '' ); ?> ur-scroll-ui__item">
						<span class="submenu"><?php echo esc_html( $label ); ?></span>
					</a>
				</li>
				<?php
				echo ob_get_clean(); // phpcs:ignore
			}

			echo '</ul>';
		}
		/**
		 * Output the settings.
		 */
		public function output() {
			$settings = $this->get_settings();
			UR_Admin_Settings::output_fields( $settings );
		}

		/**
		 * Upgrade to pro settings.
		 *
		 * @return array
		 */
		public function upgrade_to_pro_setting() {
			global $current_section;
			global $current_tab;
			add_filter( 'user_registration_settings_hide_save_button', '__return_true' );
			$title   = ucwords( str_replace( '-', ' ', $current_section ?? '' ) );
			$setting = ucwords( str_replace( '_', ' ', $current_tab ?? '' ) );

			$default = array(
				'title'    => '',
				'sections' => array(),
			);

			$premium_settings_sections = array(
				'premium_setting_section' => array(
					'type'       => 'card',
					'is_premium' => true,
					'title'      => $title,
					'class'      => 'ur-upgrade--link',

				),
			);

			$premium_tab_settings = ur_premium_settings_tab();

			foreach ( $premium_tab_settings as $tab_key => $tab_value ) {
				if ( $tab_key !== $current_tab ) {
					continue;
				}
				if ( isset( $tab_value[ $current_section ]['is_collection'] ) ) {
					foreach ( $tab_value[ $current_section ]['collections'] as $current_tab_key => $current_tab_value ) {
						$default['sections'][ $current_tab_key ] = array_merge( $premium_settings_sections['premium_setting_section'], $current_tab_value );
					}
				} else {
					foreach ( $tab_value as $section_key => $section_value ) {
						if ( $section_key === $current_section ) {
							$default['sections']['premium_setting_section'] = array_merge( $premium_settings_sections['premium_setting_section'], $section_value );
						}
					}
				}
			}

			if ( empty( $default['sections'] ) ) {
				$default['sections'] = array_merge(
					$default['sections'],
					array(
						'premium_setting_section' => array(
							'type'        => 'card',
							'is_premium'  => true,
							'title'       => $title,
							'before_desc' => "$setting > $title is only available in User Registration & Membership Pro.",
							'desc'        => 'To unlock this setting, consider upgrading to <a href="' . esc_url( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'ur-settings-desc', 'medium' => 'upgrade-link' ) ) ) . '">Pro</a>.',
							'class'       => 'ur-upgrade--link',
						),
					)
				);
			}

			$settings = apply_filters(
				'user_registration_upgrade_to_pro_setting',
				$default
			);

			return $settings;
		}

		/**
		 * Save settings.
		 */
		public function save() {
			global $current_section;

			$settings = $this->get_settings();
			UR_Admin_Settings::save_fields( $settings );

			if ( $current_section ) {
				/**
				 * Action to update the options.
				 *
				 * @param mixed $current_section Section to be updated.
				 */
				do_action( 'user_registration_update_options_' . $this->id . '_' . $current_section );
			}
		}
	}

endif;
