<?php
/**
 * Import / Export Forms
 *
 * @package  UserRegistration/Admin
 * @since    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Admin_Import_Export_Forms Class.
 */
class UR_Admin_Import_Export_Forms {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'export_json' ) );
	}

	/**
	 * Outputs Export Users Page
	 *
	 * @return void
	 */
	public static function output() {
		$all_forms = ur_get_all_user_registration_form();
		include_once dirname( __FILE__ ) . '/views/html-admin-page-import-export-forms.php';
	}

	/**
	 * Exports form data along with settings in JSON format.
	 *
	 * @return void
	 */
	public function export_json() {

		global $wpdb;

		// Check for non empty $_POST.
		if ( ! isset( $_POST['user_registration_export_form'] ) ) {
			return;
		}

		// Nonce check.
		if ( empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'user-registration-settings' ) ) {
			die( esc_html__( 'Action failed. Please refresh the page and retry.', 'user-registration' ) );
		}

		$form_ids = isset( $_POST['formid'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['formid'] ) ) : array();
		// Return if form id is not set and current user doesnot have export capability.
		if ( empty( $form_ids ) || ! current_user_can( 'export' ) ) {
			return;
		}
		$export_all_forms = array();
		foreach ( $form_ids as $key => $form_id ) {
			$form_id         = absint( wp_unslash( $form_id ) );
			$form_post       = get_post( $form_id );
			$meta_key_prefix = 'user_registration';
			$form_post_meta  = $this->get_post_meta_by_prefix( $form_id, $meta_key_prefix );

			$form_post->post_content = str_replace( '\\', '\\\\', $form_post->post_content );

			$export_data = array(
				'form_post'      => array(
					'post_content' => $form_post->post_content,
					'post_title'   => $form_post->post_title,
					'post_name'    => $form_post->post_name,
					'post_type'    => $form_post->post_type,
					'post_status'  => $form_post->post_status,
				),
				'form_post_meta' => (array) $form_post_meta,
			);

			if ( ob_get_contents() ) {
				ob_clean();
			}
			$export_all_forms[] = $export_data;
		}
		if ( count( $form_ids ) > 1 ) {
			$file_name = 'user-registration-forms-' . current_time( 'Y-m-d-H:i:s' ) . '.json';
		} else {
			$form_name = strtolower( str_replace( ' ', '-', get_the_title( $form_ids[0] ) ) );
			$file_name = $form_name . '-' . current_time( 'Y-m-d_H:i:s' ) . '.json';
		}
		$forms['forms'] = $export_all_forms;
		// Force download.
		header( 'Content-Type: application/force-download' );

		// Disposition / Encoding on response body.
		header( "Content-Disposition: attachment;filename=\"{$file_name}\";charset=utf-8" );
		header( 'Content-type: application/json' );
		echo wp_json_encode( $forms );
		exit();
	}


	/**
	 * Get post meta for a given key prefix.
	 *
	 * @param int    $post_id User ID of the user being edited.
	 * @param string $key_prefix Prefix.
	 * @return array
	 */
	protected function get_post_meta_by_prefix( $post_id, $key_prefix ) {

		$values        = get_post_meta( $post_id );
		$return_values = array();

		if ( gettype( $values ) !== 'array' ) {
			return $return_values;
		}

		foreach ( $values as $meta_key => $value ) {
			if ( substr( $meta_key, 0, strlen( $key_prefix ) ) === $key_prefix ) {
				if ( isset( $value[0] ) ) {
					$return_values[ $meta_key ] = $value[0];
				} elseif ( 'string' === gettype( $values ) ) {
					$return_values[ $meta_key ] = $value;
				}
			}
		}

		return $return_values;
	}

	/**
	 * Import Form from backend.
	 */
	public static function import_form() {

		// Check for $_FILES set or not.
		if ( isset( $_FILES['jsonfile'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing

			$filename = isset( $_FILES['jsonfile']['name'] ) ? esc_html( sanitize_text_field( wp_unslash( $_FILES['jsonfile']['name'] ) ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing
			$ext      = pathinfo( $filename, PATHINFO_EXTENSION ); // Get file extention.

			// Check for file format.
			if ( 'json' === $ext ) {
				$post_ids = array();
				// read json file.
				$form_datas_obj = json_decode( file_get_contents( $_FILES['jsonfile']['tmp_name'] ) ); // @codingStandardsIgnoreLine
				// check for non empty json file.
				if ( ! empty( $form_datas_obj ) ) {
					// check for non empty post data array.
					if ( ! empty( $form_datas_obj->forms ) || ! empty( $form_datas_obj->form_post ) ) {
						if ( ! empty( $form_datas_obj->form_post ) ) {
							// For the importing old form.
							$arr['forms'] = array( $form_datas_obj );
							$form_datas   = (object) $arr;
						} else {
							$form_datas = $form_datas_obj;
						}
						// If Form Title already exist concat it with imported tag.
						foreach ( $form_datas->forms as $key => $form_data ) {
							$args  = array( 'post_type' => 'user_registration' );
							$forms = get_posts( $args );
							foreach ( $forms as $key => $form_obj ) {
								if ( $form_data->form_post->post_title === $form_obj->post_title ) {
									$form_data->form_post->post_title = sanitize_text_field($form_data->form_post->post_title) . ' (Imported)';
									break;
								}
							}
							$form_data->form_post->post_title = sanitize_text_field($form_data->form_post->post_title);
							$post_id = wp_insert_post( $form_data->form_post );

							// Check for any error while inserting.
							if ( is_wp_error( $post_id ) ) {
								return $post_id;
							}
							array_push( $post_ids, $post_id );
							if ( $post_id ) {

								// check for non empty post_meta array.
								if ( ! empty( $form_data->form_post_meta ) ) {
									$all_roles = ur_get_default_admin_roles();

									foreach ( $form_data->form_post_meta  as $meta_key => $meta_value ) {

										// If user role does not exists in new site then set default as subscriber.
										if ( 'user_registration_form_setting_default_user_role' === $meta_key ) {
											$meta_value = array_key_exists( $meta_value, $all_roles ) ? $meta_value : 'subscriber';
										}

										if ( is_serialized( $meta_value ) ) {
											$meta_value = ur_maybe_unserialize( $meta_value );
										}

										add_post_meta( $post_id, $meta_key, self::sanitize_meta_value( $meta_value ) );
									}
								}
							}
						}
						if ( 1 === count( $post_ids ) ) {
							wp_send_json_success(
								array(
									'message' => sprintf( "%s <a href='%s'>%s</a>", esc_html__( 'Imported Successfully.', 'user-registration' ), esc_url( admin_url( 'admin.php?page=add-new-registration&edit-registration=' . $post_id ) ), esc_html__( 'View Form', 'user-registration' ) ),
								)
							);
						} else {
							wp_send_json_success(
								array(
									'message' => __( 'Imported Successfully.', 'user-registration' ),
								)
							);
						}
					} else {
						wp_send_json_error(
							array(
								'message' => __( 'Invalid file content. Please export file from user registration plugin.', 'user-registration' ),
							)
						);
					}
				} else {
					wp_send_json_error(
						array(
							'message' => __( 'Invalid file content. Please export file from user registration plugin.', 'user-registration' ),
						)
					);
				}
			} else {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid file format. Only Json File Allowed.', 'user-registration' ),
					)
				);
			}
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'Please select json file to import form data.', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Sanitize a form post meta value being imported.
	 *
	 * sanitize_text_field() returns an empty string for anything that isn't a
	 * scalar, so calling it directly on an unserialized meta value silently
	 * wipes any array/object meta (field lists, condition rules, etc.) instead
	 * of sanitizing it. Recurse into arrays/objects and only sanitize their
	 * scalar leaves.
	 *
	 * json_decode() turns a JSON object literal into stdClass, but form meta
	 * that was ever a plain PHP array (e.g. role_based_redirection) is read
	 * back elsewhere as an array, not stdClass - so objects are normalized
	 * back to arrays here rather than preserved as-is.
	 *
	 * @param mixed $meta_value Meta value, possibly already unserialized.
	 * @return mixed Sanitized value; objects are returned as arrays.
	 */
	private static function sanitize_meta_value( $meta_value ) {
		if ( is_object( $meta_value ) ) {
			$meta_value = (array) $meta_value;
		}

		if ( is_array( $meta_value ) ) {
			return array_map( array( __CLASS__, 'sanitize_meta_value' ), $meta_value );
		}

		if ( is_string( $meta_value ) ) {
			return sanitize_text_field( $meta_value );
		}

		return $meta_value;
	}
}

new UR_Admin_Import_Export_Forms();
