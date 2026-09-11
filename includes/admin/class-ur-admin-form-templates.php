<?php
/**
 * UserRegistration From Templates
 *
 * @package  UserRegistration/Admin/From Templates
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
/**
 * UR_Admin_Form_Templates class
 */
class UR_Admin_Form_Templates {

	/**
	 * Get default template.
	 *
	 * @return array
	 */
	private static function get_default_template() {
		$template        = new stdClass();
		$template->title = __( 'Start From Scratch', 'user-registration' );
		$template->slug  = 'blank';
		$template->image = untrailingslashit( plugin_dir_url( UR_PLUGIN_FILE ) ) . '/assets/images/templates/blank.png';
		$template->plan  = array( 'free' );

		return array( $template );
	}

	/**
	 * Get section content for the template screen.
	 *
	 * @return array
	 */
	public static function get_template_data() {
		$template_data = get_transient( 'user_registration_templates_data' );

		$template_url = 'https://assets.wpeverest.com/wpuserregistration/forms/';

		if ( false === $template_data ) {

			$template_json_url = $template_url . 'templates1.json';

			try {
				$content       = wp_remote_get( $template_json_url );
				$content_json  = wp_remote_retrieve_body( $content );
				$template_data = json_decode( $content_json );
			} catch ( Exception $e ) {
				$e->getMessage();
			}

			// Removing directory so the templates can be reinitialized.
			$folder_path = untrailingslashit( plugin_dir_path( UR_PLUGIN_FILE ) . '/assets/images/templates' );
			if ( isset( $template_data->templates ) ) {

				foreach ( $template_data->templates as $template_tuple ) {

					$image_url = isset( $template_tuple->image ) ? $template_tuple->image : ( $template_url . 'images/' . $template_tuple->slug . '.png' );

					$template_tuple->image = $image_url;

					$temp_name     = explode( '/', $image_url );
					$relative_path = $folder_path . '/' . end( $temp_name );
					$exists        = file_exists( $relative_path );

					// If it exists, utilize this file instead of remote file.
					if ( $exists ) {
						$template_tuple->image = untrailingslashit( plugin_dir_url( UR_PLUGIN_FILE ) ) . '/assets/images/templates/' . untrailingslashit( $template_tuple->slug ) . '.png';
					}
				}

				set_transient( 'ur_template_section_list', $template_data, WEEK_IN_SECONDS );
			}
		}

		/**
		 * Filter the Template section data
		 *
		 * @param array $template_data->templates templates data
		 */
		return isset( $template_data->templates ) ? apply_filters( 'user_registration_template_section_data', $template_data->templates ) : self::get_default_template();
	}

	/**
	 * Load the template view.
	 */
	public static function load_template_view() {
		echo '<hr class="wp-header-end">';
		echo user_registration_plugin_main_header();
		echo "<div id='user-registration-form-templates'></div>";
		wp_register_script( 'ur-templates', UR()->plugin_url() . '/chunks/form_templates.js', array( 'wp-element', 'react', 'react-dom', 'wp-api-fetch', 'wp-i18n', 'wp-blocks' ), UR()->version, true );
		wp_localize_script(
			'ur-templates',
			'ur_templates_script',
			array(
				'security'     => wp_create_nonce( 'wp_rest' ),
				'siteURL'      => esc_url( home_url( '/' ) ),
				'utm_campaign' => UR()->utm_campaign,
			)
		);
		wp_enqueue_script( 'ur-templates' );
	}
}
