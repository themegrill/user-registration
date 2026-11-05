<?php
/**
 * Export Users
 *
 * @package  UserRegistration/Admin
 * @since    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Admin_Export_Users Class.
 */
class UR_Admin_Export_Users {

	/**
	 * Constructor
	 */
	public function __construct() {

		add_action( 'admin_init', array( $this, 'export_csv' ) );
	}

	/**
	 * Outputs Export Users Page
	 *
	 * @return void
	 */
	public static function output() {
		$all_forms = ur_get_all_user_registration_form();
		include_once __DIR__ . '/views/html-admin-page-export-users.php';
	}

	/**
	 * Neutralize spreadsheet formula characters to prevent CSV/formula injection
	 * when the exported file is opened in spreadsheet software.
	 *
	 * @param mixed $value Cell value.
	 * @return mixed
	 */
	private function sanitize_csv_cell( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Exports users data along with extra information in CSV format.
	 *
	 * @param int $form_id Form ID.
	 * @return void
	 */
	public function export_csv( $form_id ) {

		// Check for non empty $_POST.
		if ( ! isset( $_POST['user_registration_export_users'] ) ) {
			return;
		}

		// Nonce check.
		if ( empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'user-registration-settings' ) ) {
			die( esc_html__( 'Action failed. Please refresh the page and retry.', 'user-registration' ) );
		}
		$form_id = isset( $_POST['export_users'] ) ? wp_unslash( $_POST['export_users'] ) : 0; //phpcs:ignore.

		// Return if form id is not set and current user doesnot have export capability.
		if ( ! isset( $form_id ) || ! current_user_can( 'export' ) ) {
			return;
		}
		$checked_additional_fields = array();
		if ( isset( $_POST['all_fields_dict'] ) ) {
			$all_fields                = sanitize_text_field( wp_unslash( $_POST['all_fields_dict'] ) );
			$all_fields                = (array) json_decode( $all_fields );
			$all_fields                = array_keys( $all_fields );
			$all_add_fields            = array( 'user_id', 'user_role', 'ur_user_status', 'date_created', 'date_created_gmt' );
			$checked_fields 		   = isset( $_POST['csv-export-custom-fields'] ) ? ur_clean( $_POST['csv-export-custom-fields'] ) : array(); //phpcs:ignore.
			$checked_additional_fields = isset( $_POST['all_selected_fields_dict'] ) ? ur_clean( $_POST['all_selected_fields_dict'] ) :	array(); //phpcs:ignore.
			if ( empty( $checked_fields ) && empty( $checked_additional_fields ) ) {
				$checked_fields            = $all_fields;
				$checked_additional_fields = $all_add_fields;
			}

			$unchecked_fields = array_diff( $all_fields, $checked_fields );
		} else {
			$unchecked_fields          = array();
			$checked_additional_fields = array( 'user_id', 'user_role', 'ur_user_status', 'date_created', 'date_created_gmt' );
		}
		$from_date     = isset( $_POST['from_date'] ) ? sanitize_text_field( wp_unslash( $_POST['from_date'] ) ) : '';
		$to_date       = isset( $_POST['to_date'] ) ? sanitize_text_field( wp_unslash( $_POST['to_date'] ) ) : '';
		$export_format = isset( $_POST['export_format'] ) ? sanitize_text_field( wp_unslash( $_POST['export_format'] ) ) : 'csv';

		// Build the meta_query to filter users by form ID properly.
		$form_meta_query = array(
			array(
				'key'     => 'ur_form_id',
				'value'   => $form_id,
				'compare' => '=',
			),
		);

		$check_users = get_users(
			array(
				'fields'     => 'ID',
				'meta_query' => $form_meta_query, //phpcs:ignore
				'number'     => 1,
			)
		);

		if ( count( $check_users ) === 0 ) {
			echo '<div id="message" class="updated inline notice notice-error"><p><strong>' . esc_html__( 'No users found with this form id.', 'user-registration' ) . '</strong></p></div>';
			return;
		}

		// Batch size — kept small to avoid bulk usermeta cache exhausting memory.
		$batch_size = apply_filters( 'user_registration_export_users_batch_size', 50 );
		$offset     = 0;

		// Open the file for writing.
		$form_name = str_replace( ' &#8211; ', '-', get_the_title( $form_id ) ); //phpcs:ignore;
		$form_name = str_replace( '&#8211;', '-', $form_name );
		$form_name = strtolower( str_replace( ' ', '-', $form_name ) );
		$file_name = $form_name . '-' . current_time( 'Y-m-d_H:i:s' ) . '.csv';

		// Force download.
		header( 'Content-Type: application/force-download' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Type: application/download' );
		header( "Content-Disposition: attachment;filename=\"{$file_name}\";charset=utf-8" );
		header( 'Content-Transfer-Encoding: binary' );

		// Open file handle.
		$handle = fopen( 'php://output', 'w' );

		// Handle UTF-8 chars conversion for CSV.
		fprintf( $handle, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		// Get the columns.
		$columns = $this->generate_columns( $form_id, $unchecked_fields, $checked_additional_fields );
		fputcsv( $handle, array_map( array( $this, 'sanitize_csv_cell' ), array_values( $columns ) ), ',', '"', '\\' );

		// ID breaks ties on identical registration times so offset batches never skip or repeat users.
		$user_query_args = array(
			'fields'     => 'ID',
			'meta_query' => $form_meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Users are matched by their registration form.
			'number'     => $batch_size,
			'orderby'    => array(
				'registered' => 'ASC',
				'ID'         => 'ASC',
			),
		);

		// Filter by date in the query so users outside the range are never loaded.
		$date_query = array();
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_date ) ) {
			$date_query['after'] = $from_date . ' 00:00:00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to_date ) ) {
			$date_query['before'] = $to_date . ' 23:59:59';
		}
		if ( ! empty( $date_query ) ) {
			$date_query['inclusive']       = true;
			$user_query_args['date_query'] = array( $date_query );
		}

		// Loop over users in batches.
		while ( true ) {
			// Fetch only user IDs to avoid bulk-loading all usermeta in one query.
			$user_ids = get_users( array_merge( $user_query_args, array( 'offset' => $offset ) ) );

			// If no users are found, break the loop.
			if ( empty( $user_ids ) ) {
				break;
			}

			// Load full user objects individually — prevents the single bulk
			// usermeta SQL query from exhausting available memory.
			$users = array_map( 'get_userdata', $user_ids );

			// Generate rows for this batch.
			$rows = $this->generate_rows( $users, $form_id, $unchecked_fields, $checked_additional_fields, $from_date, $to_date );

			// Write the rows to the CSV.
			foreach ( $rows as $row ) {
				fputcsv( $handle, array_map( array( $this, 'sanitize_csv_cell' ), $row ), ',', '"', '\\' );
			}

			// Free memory without wiping a persistent object cache for the whole site.
			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			} else {
				wp_cache_flush();
			}

			// Increase the offset for the next batch.
			$offset += $batch_size;
		}

		// Close the file.
		fclose( $handle );

		exit;
	}

	/**
	 * Generate Column for CSV export.
	 *
	 * @param int   $form_id  Form ID.
	 * @param array $unchecked_fields Unchecked Fields.
	 * @param array $checked_additional_fields Checked Fields.
	 * @return array    $columns  CSV Export Columns.
	 */
	public function generate_columns( $form_id, $unchecked_fields = array(), $checked_additional_fields = array() ) {

		// Checked additional fields.
		$checked_additional_field_columns = array();
		$user_id_column                   = array();
		foreach ( $checked_additional_fields as $key => $value ) {
			if ( 'user_id' === $value ) {
				$user_id_column = array(
					'user_id' => __( 'User ID', 'user-registration' ),
				);
			} elseif ( 'user_role' === $value ) {
				$checked_additional_field_columns['user_role'] = __( 'User Role', 'user-registration' );
			} elseif ( 'ur_user_status' === $value ) {
				$checked_additional_field_columns['ur_user_status'] = __( 'User Status', 'user-registration' );
			} elseif ( 'date_created' === $value ) {
				$checked_additional_field_columns['date_created'] = __( 'User Registered', 'user-registration' );
			} elseif ( 'date_created_gmt' === $value ) {
				$checked_additional_field_columns['date_created_gmt'] = __( 'User Registered GMT', 'user-registration' );
			}
		}
		// Filter for excluding File Upload Field.
		add_filter( 'user_registration_meta_key_label', array( __CLASS__, 'exclude_field_key' ), 10, 3 );
		$columns = ur_get_meta_key_label( $form_id );
		remove_filter( 'user_registration_meta_key_label', array( __CLASS__, 'exclude_field_key' ) );

		$exclude_columns = array_merge(
			$unchecked_fields,
			array(
				'user_confirm_password',
				'user_confirm_email',
			)
		);

		/**
		 * Filter the columns to exclude for csv export.
		 *
		 * @param array $exclude_columns Columns to Exclude.
		 */
		$exclude_columns = apply_filters(
			'user_registration_csv_export_exclude_columns',
			$exclude_columns
		);

		foreach ( $exclude_columns as $exclude_column ) {
			unset( $columns[ $exclude_column ] );
		}

		$columns = array_merge( $user_id_column, $columns );
		$columns = array_merge( $columns, $checked_additional_field_columns );

		/**
		 * Filter the columns for csv export.
		 *
		 * @param array $columns Columns to Export.
		 */
		return apply_filters( 'user_registration_csv_export_columns', $columns );
	}

	/**
	 * Generate rows for CSV export
	 *
	 * @param obj    $users   Users Data.
	 * @param int    $form_id Form ID.
	 * @param array  $unchecked_fields Unchecked Fields.
	 * @param array  $checked_additional_fields Checked Fields.
	 * @param string $from_date From date.
	 * @param string $to_date To date.
	 * @return array    $rows    CSV export rows.
	 */
	public function generate_rows( $users, $form_id, $unchecked_fields = array(), $checked_additional_fields = array(), $from_date = '', $to_date = '' ) {

		$rows = array();

		// Cache expensive calls outside the user loop to prevent repeated DB queries.
		$user_form_fields        = ur_get_form_fields( $form_id );
		$columns                 = $this->generate_columns( $form_id, $unchecked_fields );
		$columns_with_additional = $this->generate_columns( $form_id, $unchecked_fields, $checked_additional_fields );
		$user_table_data         = ur_get_user_table_fields();
		$user_meta_data          = ur_get_registered_user_meta_fields();

		// Detect profile picture field once for all users.
		$urm_form_has_profile_picture = false;
		$user_profile_picture_key     = '';
		foreach ( $user_form_fields as $field_key => $field_data ) {
			if ( isset( $field_data->field_key ) && 'profile_picture' === $field_data->field_key ) {
				$urm_form_has_profile_picture = true;
				$user_profile_picture_key     = $field_key;
				break;
			}
		}

		foreach ( $users as $user ) {

			if ( ! isset( $user->data->ID ) ) {
				continue;
			}

			$user_form_id      = get_user_meta( $user->data->ID, 'ur_form_id', true );
			$user_status       = get_user_meta( $user->data->ID, 'ur_user_status', true );
			$user_email_status = get_user_meta( $user->data->ID, 'ur_confirm_email', true );
			$status            = ur_get_user_status( $user_status, $user_email_status );
			// If the user is not submitted by selected registration form.
			if ( $user_form_id !== $form_id ) {
				continue;
			}
			$user_id_row    = array();
			$user_extra_row = ur_get_user_extra_fields( $user->data->ID, 'export_users' );

			if ( $urm_form_has_profile_picture && ! empty( $user_profile_picture_key ) ) {
				$profile_picture_id = get_user_meta( $user->data->ID, 'user_registration_profile_pic_url', true );

				if ( is_numeric( $profile_picture_id ) ) {
					$profile_picture_url = wp_get_attachment_url( $profile_picture_id );
				} else {
					$profile_picture_url = '';
				}

				// Assign profile picture URL to user extra row.
				$user_extra_row[ $user_profile_picture_key ] = $profile_picture_url;
			}

			foreach ( $user_extra_row as $user_extra_data_key => $user_extra_data ) {

				if ( ! isset( $columns[ $user_extra_data_key ] ) ) {

					// Remove the rows value that are not in columns.
					unset( $user_extra_row[ $user_extra_data_key ] );
				} else {
					$field_data = ur_get_field_data_by_field_name( $form_id, $user_extra_data_key );
					if ( isset( $field_data['field_key'] ) && 'file' === $field_data['field_key'] ) {
						$attachment_ids = is_array( $user_extra_data ) ? $user_extra_data : explode( ',', $user_extra_data );
						$file_link      = '';
						foreach ( $attachment_ids as $attachment_id ) {
							if ( is_numeric( $attachment_id ) ) {
								$file_path = wp_get_attachment_url( $attachment_id );
								if ( $file_path ) {
									$file_link .= esc_url( $file_path ) . ' ; ';
								}
							} elseif ( ur_is_valid_url( $attachment_id ) ) {
								$file_link .= esc_url( $attachment_id ) . ' ; ';
							}
						}
						$user_extra_row[ $user_extra_data_key ] = $file_link;
					} elseif ( isset( $field_data['field_key'] ) && ( 'checkbox' === $field_data['field_key'] || 'multi_select2' === $field_data['field_key'] ) ) {
						$user_extra_row[ $user_extra_data_key ] = is_array( $user_extra_data ) ? implode( ',', $user_extra_data ) : $user_extra_data;
					}
				}
			}

			$user_table_data_row = array();

			// Get user table data that are on column.
			foreach ( $user_table_data as $data ) {
				if ( isset( $columns[ $data ] ) ) {
					$user_table_data_row = array_merge( $user_table_data_row, array( $data => $user->$data ) );
				}
			}

			$user_meta_data_row = array();

			// Get user meta table data that are on column.
			foreach ( $user_meta_data as $meta_data ) {
				if ( isset( $columns[ $meta_data ] ) ) {
					$user_meta_data_row = array_merge( $user_meta_data_row, array( $meta_data => get_user_meta( $user->data->ID, $meta_data, true ) ) );
				}
			}

			$user_extra_row = array_merge( $user_extra_row, $user_table_data_row );
			$user_extra_row = array_merge( $user_extra_row, $user_meta_data_row );

			$profile = user_registration_form_data( $user->ID, $form_id );

			$user_before_merge_value = array();
			foreach ( $user_extra_row as $key => $value ) {
				if ( ! metadata_exists( 'user', $user->ID, 'user_registration_' . $key ) && empty( $value ) ) {
					$profile_key = 'user_registration_' . $key;

					if ( isset( $profile[ $profile_key ]['default'] ) ) {
						$default_value = $profile[ $profile_key ]['default'];

						// Handle array values properly.
						if ( is_array( $default_value ) ) {
							// Filter out empty values and join the remaining values into a string.
							$default_value = implode(
								', ',
								array_filter(
									$default_value,
									function ( $v ) {
										return ! empty( $v );
									}
								)
							);
						} else {
							// If it's not an array, sanitize the value.
							$default_value = esc_html( $default_value );
						}

						// Only set non-empty default values.
						if ( ! empty( $default_value ) ) {
							$user_before_merge_value[ $key ] = $default_value;
						}
					}
				}
			}

			// Merge only non-empty values from $user_before_merge_value.
			if ( ! empty( $user_before_merge_value ) ) {
				foreach ( $user_before_merge_value as $key => $value ) {
					if (
						! isset( $user_extra_row[ $key ] ) || '' === $user_extra_row[ $key ] || ( is_array( $user_extra_row[ $key ] ) && empty( $user_extra_row[ $key ] ) )
					) {
						$user_extra_row[ $key ] = $value;
					}
				}
			}

			// Get user additional checked row.
			$user_additional_checked_row = array();
			foreach ( $checked_additional_fields as $key => $value ) {
				if ( 'user_id' === $value ) {
					$user_id_row = array( 'user_id' => $user->data->ID );
				} elseif ( 'user_role' === $value ) {
					$user_additional_checked_row['user_role'] = is_array( $user->roles ) ? implode( ',', $user->roles ) : $user->roles;
				} elseif ( 'ur_user_status' === $value ) {
					$user_additional_checked_row['ur_user_status'] = is_array( $status ) ? implode( ',', $status ) : $status;
				} elseif ( 'date_created' === $value ) {
					$user_additional_checked_row['date_created'] = $user->data->user_registered;
				} elseif ( 'date_created_gmt' === $value ) {
					$user_additional_checked_row['date_created_gmt'] = get_gmt_from_date( $user->data->user_registered );
				}
			}
			$user_registered_date = $user->data->user_registered;
			$from_date            = '' !== $from_date ? $from_date : '';
			$to_date              = '' !== $to_date ? $to_date : gmdate( 'Y-m-d' );
			if ( gmdate( 'Y-m-d', strtotime( $user_registered_date ) ) >= gmdate( 'Y-m-d', strtotime( $from_date ) ) && gmdate( 'Y-m-d', strtotime( $user_registered_date ) ) <= gmdate( 'Y-m-d', strtotime( $to_date ) ) ) {
				$user_row = array_merge( $user_id_row, $user_extra_row );
				$user_row = array_merge( $user_row, $user_additional_checked_row );
				/**
				 * Reorder rows according to the values in column.
				 *
				 * @see https://stackoverflow.com/a/44774818/9520912
				 */
				$user_row = array_merge( array_fill_keys( array_keys( $columns_with_additional ), '' ), $user_row );
				$rows[]   = $user_row;
			}
		}

		/**
		 * Filter the rows for csv export.
		 *
		 * @param array $rows Rows to Export.
		 * @param array $users Users to Export.
		 */
		return apply_filters( 'user_registration_csv_export_rows', $rows, $users );
	}

	/**
	 * Customise Filter for unset file upload field.
	 *
	 * @param array $key_label Field Key and Label Array.
	 * @param int   $form_id Form ID.
	 * @param array $post_content_array Post Content Array.
	 * @return array
	 */
	public static function exclude_field_key( $key_label, $form_id, $post_content_array ) {

		/**
		 * Filter the field keys to exclude for export.
		 *
		 * @param array Array of fields.
		 */
		$exclude_field_keys = apply_filters( 'user_registration_export_user_exclude_field_keys', array( 'html', 'section_title' ) );

		foreach ( $post_content_array as $post_content_row ) {
			foreach ( $post_content_row as $post_content_grid ) {
				foreach ( $post_content_grid as $field ) {
					if ( isset( $field->field_key ) && isset( $field->general_setting->field_name ) ) {
						if ( in_array( $field->field_key, $exclude_field_keys, true ) ) {
							unset( $key_label[ $field->general_setting->field_name ] );
						}
					}
				}
			}
		}
		return $key_label;
	}
}

new UR_Admin_Export_Users();
