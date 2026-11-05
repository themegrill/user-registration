<?php
/**
 * Add extra profile fields for users in admin
 *
 * @package  UserRegistration/Admin
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'UR_Admin_Profile', false ) ) :

	/**
	 * UR_Admin_Profile Class.
	 */
	class UR_Admin_Profile {

		/**
		 * Class Constructor.
		 */
		public function __construct() {
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_user_registration' ) ) {
				return false;
			}
			add_action( 'show_user_profile', array( $this, 'show_user_extra_fields' ) );
			add_action( 'edit_user_profile', array( $this, 'show_user_extra_fields' ) );
			add_action( 'personal_options_update', array( $this, 'update_user_profile' ) );
			add_action( 'edit_user_profile_update', array( $this, 'update_user_profile' ) );
		}

		/**
		 * Get Customer meta fields.
		 *
		 * @deprecated 1.4.1
		 * @param array $all_meta_value meta value.
		 * @param int   $form_id Form Id.
		 */
		public function get_customer_meta_fields( $all_meta_value, $form_id ) {
			ur_deprecated_function( 'UR_Admin_Profile::get_customer_meta_fields', '1.4.1', 'UR_Admin_Profile::get_user_meta_by_form_fields' );
		}

		/**
		 * Get Fields to be excluded for Admin Profile
		 */
		public function get_exclude_fields_for_admin_profile() {
			/**
			 * Filter to retrieve the fields to exclude from admin profile.
			 *
			 * @param array Array of fields to exclude
			 */
			return apply_filters( 'user_registration_exclude_fields_for_admin_profile', array() );
		}

		/**
		 * Get User extra fields from usermeta and integrate with form
		 *
		 * @param  int $user_id User Id.
		 * @return array Fields to display which are filtered through user_registration_profile_meta_fields before being returned
		 */
		public function get_user_meta_by_form_fields( $user_id ) {

			$show_fields       = array();
			$form_id           = ur_get_form_id_by_userid( $user_id );
			$all_meta_for_user = $this->get_user_meta_by_prefix( $user_id, 'user_registration_' );
			$form_fields       = $this->get_form_fields( $all_meta_for_user, $form_id );

			if ( ! empty( $form_fields ) ) {

				foreach ( $form_fields as $key => $value ) {
					if ( isset( $value['field_key'] ) && 'signature' === $value['field_key'] && isset( $_GET['user_id'] ) ) {
						unset( $form_fields[ $key ] );
					}
					// if ( isset( $value['field_key'] ) && 'membership' === $value['field_key'] && isset( $_GET['user_id'] ) ) {
					// unset( $form_fields[ $key ] );
					// }
				}

				unset( $form_fields['user_registration_profile_pic_url'] );

				if ( ! empty( $form_fields ) ) {
					/**
					 * Filter to retrieve the profile meta fields
					 *
					 * @param array Array of Profile Meta Fields
					 */
					$show_fields = apply_filters(
						'user_registration_profile_meta_fields',
						array(
							'user_registration' => array(
								'title'  => sprintf( esc_html__( 'User Extra Information', 'user-registration' ), '' ),
								'fields' => $form_fields,
							),
						)
					);
				}
			}
			return $show_fields;
		}

		/**
		 * Add cus;tomer meta fields.
		 *
		 * @deprecated 1.4.1
		 * @param array $all_meta_value Meta value.
		 * @param int   $form_id Form id.
		 */
		public function add_customer_meta_fields( $all_meta_value, $form_id ) {
			ur_deprecated_function( 'UR_Admin_Profile::add_customer_meta_fields', '1.4.1', 'UR_Admin_Profile::show_user_extra_fields' );
		}

		/**
		 * Show user extra information in users profile page.
		 *
		 * @param WP_User $user Users Data.
		 */
		public function show_user_extra_fields( $user ) {
			/**
			 * Filter Hook: user_registration_hide_user_extra_fields_to_non_admin
			 *
			 * Allow users without 'manage_options' capability to view and edit User Extra Details.
			 *
			 * @since 3.0.4
			 *
			 * @param [bool] $hide Whether to hide details.
			 *
			 * @return bool
			 */
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_user_registration', true ) ) {
				return;
			}

			$show_fields    = $this->get_user_meta_by_form_fields( $user->ID );
			$exclude_fields = $this->get_exclude_fields_for_admin_profile();
			$form_id        = ur_get_form_id_by_userid( $user->ID );
			$profile        = user_registration_form_data( $user->ID, $form_id );

			foreach ( $show_fields as $fieldset_key => $fieldset ) :
				?>
				<h2><?php echo esc_html( $fieldset['title'] ); ?></h2>
				<table class="form-table" id="<?php echo esc_attr( 'fieldset-' . $fieldset_key ); ?>">

					<?php
					$profile_field_type = array(
						'select',
						'country',
						'checkbox',
						'button',
						'textarea',
						'radio',
					);

					if ( isset( $fieldset['fields'] ) ) {

						foreach ( $fieldset['fields'] as $key => $field ) :

							$field['field_key'] = isset( $field['field_key'] ) ? $field['field_key'] : '';

							if ( in_array( $field['field_key'], $exclude_fields, true ) || in_array( $key, $exclude_fields, true ) ) {
								continue;
							}

							$field['label']       = isset( $field['label'] ) ? $field['label'] : '';
							$field['description'] = isset( $field['description'] ) ? $field['description'] : '';
							$attributes           = isset( $field['attributes'] ) ? $field['attributes'] : array();
							$attribute_string     = '';
							$date_format          = '';
							$date_mode            = '';

							foreach ( $attributes as $name => $value ) {
								if ( 'data-date-format' === $name ) {
									$date_format = $value;
								}
								if ( 'data-default-date' === $name ) {
									continue;
								}
								if ( 'data-mode' === $name ) {
									$date_mode = $value;
								}
								if ( is_bool( $value ) ) {
									if ( $value ) {
										$attribute_string .= $name . ' ';
									}
								} else {
									$attribute_string .= sprintf( '%s="%s" ', $name, $value );
								}
							}

							$field_label = $field['label'];
							$field_type  = isset( $field['type'] ) ? $field['type'] : '';

							if ( ! in_array( $field_type, $profile_field_type, true ) ) {
								$extra_params_key = str_replace( 'user_registration_', 'ur_', $key ) . '_params';
								$extra_params     = json_decode( get_user_meta( $user->ID, $extra_params_key, true ) );
								$field_label      = isset( $extra_params->label ) ? $extra_params->label : $field_label;
							}
							?>
							<?php if ( 'subscription_plan' === $field_type || 'multiple_choice' === $field_type || 'single_item' === $field_type || 'total_field' === $field_type ) { ?>
							<?php } else { ?>
								<tr>
									<th>
										<label
										for="<?php echo esc_attr( $key ); ?>">
														<?php
														echo esc_html( $field_label );
														?>
										</label>
										<p><span class="description"><?php echo wp_kses_post( $field['description'] ); ?></span></p>
									</th>
									<td>
										<?php if ( ! empty( $field['type'] ) && 'select' === $field['type'] ) : ?>
										<select name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>"
												class="<?php echo esc_attr( $field['class'] ); ?>" style="width: 25em;">
											<option><?php esc_html_e( 'Select', 'user-registration' ); ?></option>
											<?php
											if ( ! metadata_exists( 'user', $user->ID, $key ) && isset( $profile[ $key ] ) && isset( $profile[ $key ]['type'] ) && 'select' === $profile[ $key ]['type'] ) {
												$selected = isset( $profile[ $key ] ['default'] ) ? $profile[ $key ] ['default'] : '';
											} else {
												$selected = get_user_meta( $user->ID, $key, true );
											}

											foreach ( $field['options'] as $option_key => $option_value ) :
												?>
												<option value="<?php echo esc_attr( trim( $option_key ) ); ?>" <?php esc_attr( selected( $selected, trim( $option_key ), true ) ); ?>><?php echo esc_html( trim( $option_value ) ); ?></option>
											<?php endforeach; ?>
										</select>

										<?php elseif ( ! empty( $field['type'] ) && 'country' === $field['type'] ) : ?>
											<select name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>"
													class="<?php echo esc_attr( $field['class'] ); ?>" style="width: 25em;">
												<option><?php esc_html_e( 'Select', 'user-registration' ); ?></option>
												<?php
												if ( ! metadata_exists( 'user', $user->ID, $key ) && isset( $profile[ $key ] ) && isset( $profile[ $key ]['type'] ) && 'country' === $profile[ $key ]['type'] ) {
													$selected = isset( $profile[ $key ] ['default'] ) ? $profile[ $key ] ['default'] : '';
												} else {
													$raw_meta = get_user_meta( $user->ID, $key, true );

													if ( preg_match( '/^\{.*\}$/s', $raw_meta ) ) {
														$decoded  = json_decode( $raw_meta, true );
														$raw_meta = isset( $decoded['country'] ) ? $decoded['country'] : '';
													}
													$selected = esc_attr( $raw_meta );
												}
												foreach ( $field['options'] as $option_key => $option_value ) :
													?>
													<option
														value="<?php echo esc_attr( trim( $option_key ) ); ?>" <?php esc_attr( selected( $selected, $option_key, true ) ); ?>><?php echo esc_attr( trim( $option_value ) ); ?></option>
												<?php endforeach; ?>
											</select>

										<?php elseif ( ! empty( $field['type'] ) && 'radio' === $field['type'] ) : ?>
											<?php

											if ( ! metadata_exists( 'user', $user->ID, $key ) && isset( $profile[ $key ] ) && isset( $profile[ $key ]['type'] ) && 'radio' === $profile[ $key ]['type'] ) {
												$db_value = isset( $profile[ $key ]['default'] ) ? $profile[ $key ]['default'] : '';
											} else {
												$db_value = get_user_meta( $user->ID, $key, true );
											}
											if ( isset( $field['image_choice'] ) && ur_string_to_bool( $field['image_choice'] ) ) {
												if ( is_array( $field['image_options'] ) ) {
													foreach ( $field['image_options'] as $option_key => $option_value ) {
														$label_option = is_array( $option_value ) ? $option_value['label'] : $option_value->label;
														$image_option = is_array( $option_value ) ? $option_value['image'] : $option_value->image;
														?>
														<div class="user-registration-user-profile-info">
														<input type="radio"
																		name="<?php echo esc_attr( $key ); ?>"
																		id="<?php echo esc_attr( $key ) . '_' . esc_attr( $label_option ); ?>"
																		value="<?php echo esc_attr( trim( $label_option ) ); ?>"
																		class="<?php echo esc_attr( $field['class'] ); ?>" <?php esc_attr( checked( $db_value, trim( $label_option ), true ) ); ?>  >
																		<label class="user-registration-image-options" for="<?php echo esc_attr( $key ) . '_' . esc_attr( $label_option ); ?>">
														<?php if ( ! empty( $image_option ) ) { ?>
															<span class="user-registration-image-choice"><img src="<?php echo esc_url( $image_option ); ?>" alt="<?php esc_attr( $label_option ); ?>" width="200px" height="200px"></span>
															<?php
														}
														?>
														<?php echo esc_html( trim( $label_option ) ); ?>
														</label><br/>
														</div>
														<?php
													}
												}
											} elseif ( is_array( $field['options'] ) ) {
												foreach ( $field['options'] as $option_key => $option_value ) {
													?>
														<label><input type="radio"
																		name="<?php echo esc_attr( $key ); ?>"
																		id="<?php echo esc_attr( $key ); ?>"
																		value="<?php echo esc_attr( trim( $option_key ) ); ?>"
																		class="<?php echo esc_attr( $field['class'] ); ?>" <?php esc_attr( checked( $db_value, trim( $option_value ), true ) ); ?>  ><?php echo esc_html( trim( $option_value ) ); ?>
														</label><br/>
														<?php
												}
											}
											?>

										<?php elseif ( ! empty( $field['type'] ) && 'checkbox' === $field['type'] ) : ?>
											<?php

											if ( ! metadata_exists( 'user', $user->ID, $key ) && isset( $profile[ $key ] ) && isset( $profile[ $key ]['type'] ) && 'checkbox' === $profile[ $key ]['type'] ) {
												$value = isset( $profile[ $key ]['default'] ) ? $profile[ $key ]['default'] : '';
											} else {
												$value = get_user_meta( $user->ID, $key, true );
											}

											if ( isset( $field['image_choice'] ) && ur_string_to_bool( $field['image_choice'] ) && is_array( $field['image_options'] ) && array_filter( $field['image_options'] ) ) {
												foreach ( $field['image_options'] as $choice_key => $choice_value ) {
													$label_choice = is_array( $choice_value ) ? ur_sanitize_tooltip( trim( $choice_value['label'] ) ) : ur_sanitize_tooltip( trim( $choice_value->label ) );
													$image_choice = is_array( $choice_value ) ? $choice_value['image'] : $choice_value->image;
													?>
													<div class="user-registration-user-profile-info">
													<input type="checkbox"
																		name="<?php echo esc_attr( $key ); ?>[]"
																		id="<?php echo esc_attr( $key ) . '_' . esc_attr( $label_choice ); ?>"
																		value="<?php echo esc_attr( $label_choice ); ?>"
																		class="<?php echo esc_attr( $field['class'] ); ?>"
																						<?php
																						if ( is_array( $value ) && in_array( $label_choice, $value ) ) {
																							echo 'checked="checked"';
																						} elseif ( $value == $label_choice ) {
																							echo 'checked="checked"';
																						}
																						?>
														>
														<label class="user-registration-image-options" for="<?php echo esc_attr( $key ) . '_' . esc_attr( $label_choice ); ?>">
														<?php if ( ! empty( $image_choice ) ) { ?>
															<span class="user-registration-image-choice"><img src="<?php echo esc_url( $image_choice ); ?>" alt="<?php esc_attr( $label_choice ); ?>" width="200px" height="200px"></span>
															<?php
														}
														?>
													<?php echo wp_kses_post( trim( $label_choice ) ); ?></label><br/>
													</div>
														<?php
												}
											} elseif ( is_array( $field['choices'] ) && array_filter( $field['choices'] ) ) {
												foreach ( $field['choices'] as $choice ) {
													$option = ur_sanitize_tooltip( trim( $choice ) );
													?>
														<label><input type="checkbox"
																		name="<?php echo esc_attr( $key ); ?>[]"
																		id="<?php echo esc_attr( $key ); ?>"
																		value="<?php echo esc_attr( $option ); ?>"
																		class="<?php echo esc_attr( $field['class'] ); ?>"
																						<?php
																						if ( is_array( $value ) && in_array( html_entity_decode( ur_sanitize_tooltip( trim( $option ) ) ), $value ) ) {
																							echo 'checked="checked"';
																						} elseif ( $value == $option ) {
																							echo 'checked="checked"';
																						}
																						?>
														><?php if ( trim( $choice ) !== '1' ) : ?>
															<?php echo esc_html( trim( $choice ) ); ?>
														<?php endif; ?></label><br/>
														<?php
												}
											} else {
												?>
												<input type="checkbox" name="<?php echo esc_attr( $key ); ?>"
														id="<?php echo esc_attr( $key ); ?>" value="1"
														class="<?php echo esc_attr( $field['class'] ); ?>"
																		<?php
																		if ( ur_string_to_bool( $value ) ) {
																			echo 'checked="checked"';
																		}
																		?>
												>
												<?php
											}
											?>
										<?php elseif ( ! empty( $field['type'] ) && 'button' === $field['type'] ) : ?>
											<button id="<?php echo esc_attr( $key ); ?>"
													class="button <?php echo esc_attr( $field['class'] ); ?>"><?php echo esc_html( $field['text'] ); ?></button>
										<?php elseif ( ! empty( $field['type'] ) && 'privacy_policy' === $field['type'] ) : ?>
										<input checked type="checkbox" disabled="disabled"/>
										<?php elseif ( ! empty( $field['type'] ) && 'textarea' === $field['type'] ) : ?>
											<textarea name="<?php echo esc_attr( $key ); ?>"
														id="<?php echo esc_attr( $key ); ?>"

														class="<?php echo( ! empty( $field['class'] ) ? esc_attr( $field['class'] ) : 'regular-text' ); ?>"
												<?php echo esc_attr( $attribute_string ); ?>
														rows="5"
														cols="30"><?php echo esc_attr( $this->get_user_meta( $user->ID, $key ) ); ?></textarea>

														<?php elseif ( ! empty( $field['type'] ) && 'date' === $field['type'] ) : ?>
															<?php
															if ( ! metadata_exists( 'user', $user->ID, $key ) && isset( $profile[ $key ] ) && isset( $profile[ $key ]['type'] ) && 'date' === $profile[ $key ]['type'] ) {
																$value = isset( $profile[ $key ]['default'] ) ? $profile[ $key ]['default'] : '';

																if ( empty( $value ) && isset( $profile[ $key ]['custom_attributes']['data-default-date'] ) ) {
																	$date_format  = isset( $profile[ $key ]['custom_attributes']['data-date-format'] ) ? $profile[ $key ]['custom_attributes']['data-date-format'] : 'd/m/Y';
																	$default_date = isset( $profile[ $key ]['custom_attributes']['data-default-date'] ) ? absint( $profile[ $key ]['custom_attributes']['data-default-date'] ) : 1;

																	if ( 1 === $default_date ) {
																		$value = date( $date_format, time() );
																	}
																}
															} else {
																$value = $this->get_user_meta( $user->ID, $key );
															}
															$actual_value = $value;
															$value        = str_replace( '/', '-', $value );
															if ( ! strpos( $value, 'to' ) ) {
																$value = '' !== $value ? date_i18n( $date_format, strtotime( $value ) ) : '';
															} else {
																$date_range = explode( 'to', $value );
																$value      = date_i18n( $date_format, strtotime( trim( $date_range[0] ) ) ) . ' to ' . date_i18n( $date_format, strtotime( trim( $date_range[1] ) ) );
															}
															?>
											<input type="text"
													value="<?php echo esc_attr( $actual_value ); ?>"
													class="ur-flatpickr-field regular-text"
													data-id = '<?php echo esc_attr( $key ); ?>'
													<?php echo $attribute_string; //phpcs:ignore?>
													readonly />
													<input type="hidden" id="formated_date" value="<?php echo esc_attr( $value ); ?>"/>
													<input type="text" name="<?php echo esc_attr( $key ); ?>"
													id="<?php echo esc_attr( $key ); ?>"
													value="<?php echo esc_attr( $value ); ?>"
													class="<?php echo( ! empty( $field['class'] ) ? esc_attr( $field['class'] ) : 'regular-text' ); ?>"
													style="display:none"
															<?php echo $attribute_string; //phpcs:ignore?>
													/>

															<?php
										elseif ( ! empty( $field['type'] ) ) :

												$data = array(
													'key' => $key,
													'value' => $this->get_user_meta( $user->ID, $key ),
													'attribute_string' => $attribute_string,
													'field' => $field,

												);
												/**
												 * Action to display user extra field
												 *
												 * @param array $data Field Data
												 */
												do_action( 'user_registration_profile_field_' . $field['type'], $data );
										else :
											$extra_params_key = str_replace( 'user_registration_', 'ur_', $key ) . '_params';
											$extra_params     = json_decode( get_user_meta( $user->ID, $extra_params_key, true ) );

											if ( empty( $extra_params ) ) {
												if ( ! metadata_exists( 'user', $user->ID, $key ) && isset( $profile[ $key ] ) ) {
													$value = isset( $profile[ $key ]['default'] ) ? $profile[ $key ]['default'] : '';
												} else {
													$value = $this->get_user_meta( $user->ID, $key );
												}
												?>
												<input type="text" name="<?php echo esc_attr( $key ); ?>"
														id="<?php echo esc_attr( $key ); ?>"
														value="<?php echo esc_attr( $value ); ?>"
														class="<?php echo( ! empty( $field['class'] ) ? esc_attr( $field['class'] ) : 'regular-text' ); ?>"
													<?php echo esc_attr( $attribute_string ); ?>
												/>

												<?php
											} endif;
										?>
										<br/>
									</td>
								</tr>
								<?php
							}
							endforeach;
					}
					?>
			</table>
				<?php
			endforeach;

			/**
			 * Action to perform after showing user field
			 *
			 * @param WPUser $user User
			 */
			do_action( 'user_registration_after_user_extra_information', $user );
		}

			/**
			 * Save customer meta fields.
			 *
			 * @deprecated 1.4.1
			 * @param array $all_meta_value Meta value.
			 * @param int   $form_id Form id.
			 */
		public function save_customer_meta_fields( $all_meta_value, $form_id ) {
			ur_deprecated_function( 'UR_Admin_Profile::save_customer_meta_fields', '1.4.1', 'UR_Admin_Profile::update_user_profile' );
		}

			/**
			 * Save user extra fields on edit user pages.
			 *
			 * @param int $user_id User ID of the user being saved.
			 */
		public function update_user_profile( $user_id ) {

			$save_fields = $this->get_user_meta_by_form_fields( $user_id );

			if ( ! empty( $save_fields ) ) {

				$form_id = ur_get_form_id_by_userid( $user_id );

				$profile = user_registration_form_data( $user_id, $form_id );

				/**
				 * Action to perform after Admin save profile validation.
				 *
				 * @param int $user_id User ID of the user being saved.
				 * @param array $profile Form Data.
				 */
				do_action( 'user_registration_after_admin_save_profile_validation', $user_id, $profile );

				foreach ( $save_fields as $fieldset ) {
					foreach ( $fieldset['fields'] as $key => $field ) {
						if ( isset( $field['type'] ) && ( 'checkbox' === $field['type'] || 'multi_select2' === $field['type'] || 'wysiwyg' === $field['type'] ) ) {
							if ( isset( $_POST[ $key ] ) ) {  //phpcs:ignore
								if ( is_array( $_POST[ $key ] ) ) { //phpcs:ignore
									$value = array_map( 'sanitize_text_field', $_POST[ $key ] ); //phpcs:ignore
								} else {
									$value = sanitize_text_field( $_POST[ $key ] ); //phpcs:ignore
								}
								update_user_meta( absint( $user_id ), sanitize_text_field( $key ), $value );
							} else {
								update_user_meta( absint( $user_id ), sanitize_text_field( $key ), '' );
							}
							} elseif ( isset( $_POST[ $key ] ) ) { //phpcs:ignore
							update_user_meta( absint( $user_id ), sanitize_text_field( $key ), sanitize_text_field( $_POST[ $key ] ) ); //phpcs:ignore
						}
					}
				}
			}
		}

			/**
			 * Get user meta for a given key, with fallbacks to core user info for pre-existing fields.
			 *
			 * @param int    $user_id User ID of the user being edited.
			 * @param string $key  key for user meta field.
			 *
			 * @return string
			 */
		protected function get_user_meta( $user_id, $key ) {
			$value           = get_user_meta( $user_id, $key, true );
			$existing_fields = array( 'billing_first_name', 'billing_last_name' );
			if ( ! $value && in_array( $key, $existing_fields ) ) {
				$value = get_user_meta( $user_id, str_replace( 'billing_', '', $key ), true );
			} elseif ( ! $value && ( 'billing_email' === $key ) ) {
				$user  = get_userdata( $user_id );
				$value = $user->user_email;
			}

			return $value;
		}

			/**
			 * Get user meta for a given key prefix, with fallbacks to core user info for pre-existing fields.
			 *
			 * @param int    $user_id User ID of the user being edited.
			 * @param string $key_prefix Prefix.
			 * @return array
			 */
		public function get_user_meta_by_prefix( $user_id, $key_prefix ) {

			$values        = get_user_meta( $user_id );
			$return_values = array();

			if ( gettype( $values ) != 'array' ) {
				return $return_values;
			}

			foreach ( $values as $meta_key => $value ) {
				if ( substr( $meta_key, 0, strlen( $key_prefix ) ) == $key_prefix ) {
					if ( isset( $value[0] ) ) {
						$return_values[ $meta_key ] = $value[0];
					} elseif ( gettype( $values ) == 'string' ) {
						$return_values[ $meta_key ] = $value;
					}
				}
			}

			return $return_values;
		}

			/**
			 * Get User Meta fields.
			 *
			 * @deprecated 1.4.1
			 * @param array $all_meta_value Meta value.
			 * @param int   $form_id Form Id.
			 */
		public function get_user_meta_fields( $all_meta_value, $form_id ) {
			ur_deprecated_function( 'UR_Admin_Profile::get_user_meta_fields', '1.4.1', 'UR_Admin_Profile::get_form_fields' );
		}

			/**
			 * Get all the registration form fields.
			 *
			 * @param array $all_meta_value Meta value.
			 * @param int   $form_id Form Id.
			 */
		protected function get_form_fields( $all_meta_value, $form_id ) {
			$form_id            = ( $form_id ) ? $form_id : 0;
			$post_content_array = UR()->form->get_form(
				$form_id,
				array(
					'content_only' => true,
					'publish'      => true,
				)
			);

			$fields = array();
			if ( gettype( $post_content_array ) != 'array' ) {
				return $fields;
			}

			$all_meta_value_keys = array_keys( $all_meta_value );

			foreach ( $post_content_array as $post_content_row ) {
				foreach ( $post_content_row as $post_content_grid ) {
					foreach ( $post_content_grid as $field ) {
						$field_name        = isset( $field->general_setting->field_name ) ? $field->general_setting->field_name : '';
						$field_label       = isset( $field->general_setting->label ) ? $field->general_setting->label : '';
						$field_description = isset( $field->general_setting->description ) ? $field->general_setting->description : '';
						$field_key         = isset( $field->field_key ) ? $field->field_key : '';

						if ( 'stripe_gateway' === $field_name ) {
							break;
						}

						if ( '' == $field_label && isset( $field->general_setting->field_name ) ) {
							$field_label_array = explode( '_', $field->general_setting->field_name );
							$field_label       = join( ' ', array_map( 'ucwords', $field_label_array ) );
						}
						if ( '' != $field_name ) {
							$field_index = '';

							if ( in_array( 'user_registration_' . $field_name, $all_meta_value_keys ) ) {
								$field_index            = 'user_registration_' . $field_name;
								$fields[ $field_index ] = array(
									'label'       => $field_label,
									'description' => $field_description,
									'field_key'   => $field_key,
								);

							} elseif ( ! in_array( $field_name, ur_get_fields_without_prefix() ) ) {
								$field_index            = 'user_registration_' . $field_name;
								$fields[ $field_index ] = array(
									'label'       => $field_label,
									'description' => $field_description,
									'field_key'   => $field_key,
								);
							}
							switch ( $field_key ) {

								case 'select':
									// Backward compatibility. Modified since 1.5.7.
									$options     = isset( $field->advance_setting->options ) ? explode( ',', $field->advance_setting->options ) : array();
									$option_data = isset( $field->general_setting->options ) ? $field->general_setting->options : $options;
									$option_data = array_map( 'trim', $option_data );

									if ( is_array( $option_data ) && '' != $field_index ) {
										foreach ( $option_data as $index_data => $option ) {
											$fields[ $field_index ]['options'][ $option ] = $option;
										}
										$fields[ $field_index ]['type']  = 'select';
										$fields[ $field_index ]['class'] = '';
									}
									break;

								case 'radio':
									if ( isset( $field->general_setting->image_choice ) && ur_string_to_bool( $field->general_setting->image_choice ) ) {
										$option_data = isset( $field->general_setting->image_options ) ? $field->general_setting->image_options : array();
										$option_data = array_map(
											function ( $option ) {
												if ( is_array( $option ) ) {
													$option['label'] = trim( $option['label'] );
												} elseif ( is_object( $option ) ) {
													$option->label = isset( $option->label ) ? trim( $option->label ) : $option->label;
												}
												return $option;
											},
											$option_data
										);
										if ( is_array( $option_data ) && '' != $field_index ) {
											foreach ( $option_data as $index_data => $option ) {
												$label_option = is_array( $option ) ? $option['label'] : $option->label;
												$image_option = is_array( $option ) ? $option['image'] : $option->image;
												$fields[ $field_index ]['image_options'][ $label_option ] = array(
													'label' => $label_option,
													'image' => $image_option,
												);
											}
											$fields[ $field_index ]['type']         = 'radio';
											$fields[ $field_index ]['class']        = '';
											$fields[ $field_index ]['image_choice'] = isset( $field->general_setting->image_choice ) ? $field->general_setting->image_choice : false;
										}
										break;
									} else {
										$option_data = isset( $field->general_setting->options ) ? $field->general_setting->options : array();
										$option_data = array_map( 'trim', $option_data );
										if ( is_array( $option_data ) && '' != $field_index ) {
											foreach ( $option_data as $index_data => $option ) {
												$fields[ $field_index ]['options'][ $option ] = $option;
											}
											$fields[ $field_index ]['type']  = 'radio';
											$fields[ $field_index ]['class'] = '';
										}
										break;
									}

								case 'country':
									$country                           = ur_load_form_field_class( $field_key );
									$fields[ $field_index ]['options'] = $country::get_instance()->get_country();
									$fields[ $field_index ]['type']    = 'country';
									$fields[ $field_index ]['class']   = '';
									break;

								case 'textarea':
									$fields[ $field_index ]['type']  = 'textarea';
									$fields[ $field_index ]['class'] = '';
									break;

								case 'mailchimp':
								case 'mailerlite':
								case 'checkbox':
									if ( isset( $field->general_setting->image_choice ) && ur_string_to_bool( $field->general_setting->image_choice ) ) {
										$choices_data = isset( $field->general_setting->image_options ) ? $field->general_setting->image_options : array();
										$choices_data = array_map(
											function ( $choice ) {
												if ( is_array( $choice ) ) {
													$choice['label'] = trim( $choice['label'] );
												} elseif ( is_object( $choice ) ) {
													$choice->label = isset( $choice->label ) ? trim( $choice->label ) : $choice->label;
												}
												return $choice;
											},
											$choices_data
										);

										$fields[ $field_index ]['image_options'] = $choices_data;
										$fields[ $field_index ]['type']          = 'checkbox';
										$fields[ $field_index ]['class']         = '';
										$fields[ $field_index ]['image_choice']  = isset( $field->general_setting->image_choice ) ? $field->general_setting->image_choice : false;
									} else {
										$choices_data = isset( $field->general_setting->options ) ? $field->general_setting->options : array();
										$choices_data = array_map( 'trim', $choices_data );

										$fields[ $field_index ]['choices'] = $choices_data;
										$fields[ $field_index ]['type']    = 'checkbox';
										$fields[ $field_index ]['class']   = '';
									}
									break;

								case 'date':
									$fields[ $field_index ]['type'] = 'date';
									$date_format                    = isset( $field->advance_setting->date_format ) ? $field->advance_setting->date_format : '';
									$fields[ $field_index ]['attributes']['data-date-format'] = $date_format;

									if ( isset( $field->advance_setting->enable_min_max ) && ur_string_to_bool( $field->advance_setting->enable_min_max ) ) {
										if ( ! empty( $field->advance_setting->min_date ) ) {
											$min_date = str_replace( '/', '-', $field->advance_setting->min_date );
											$fields[ $field_index ]['attributes']['data-min-date'] = '' !== $min_date ? date_i18n( $date_format, strtotime( $min_date ) ) : '';
										}

										if ( ! empty( $field->advance_setting->max_date ) ) {
											$max_date = str_replace( '/', '-', $field->advance_setting->max_date );
											$fields[ $field_index ]['attributes']['data-max-date'] = '' !== $max_date ? date_i18n( $date_format, strtotime( $max_date ) ) : '';
										}
									}

									if ( ! empty( $field->advance_setting->set_current_date ) ) {
										$set_current_date = isset( $field->advance_setting->set_current_date ) ? ur_string_to_bool( $field->advance_setting->set_current_date ) : '';
										$fields[ $field_index ]['attributes']['data-default-date'] = $set_current_date;
									}

									if ( ! empty( $field->advance_setting->enable_date_range ) ) {
										$enable_date_range                                 = isset( $field->advance_setting->enable_date_range ) ? ur_string_to_bool( $field->advance_setting->enable_date_range ) : '';
										$fields[ $field_index ]['attributes']['data-mode'] = $enable_date_range;
									}

									if ( ! empty( $field->advance_setting->date_localization ) ) {
										$date_localization                                   = isset( $field->advance_setting->date_localization ) ? $field->advance_setting->date_localization : 'en';
										$fields[ $field_index ]['attributes']['data-locale'] = $date_localization;
									}
									break;

								case 'privacy_policy':
									$fields[ $field_index ]['type'] = 'privacy_policy';
									break;

								case 'multiple_choice':
									$fields[ $field_index ]['type'] = 'multiple_choice';
									break;

								case 'subscription_plan':
									$fields[ $field_index ]['type'] = 'subscription_plan';
									break;

								case 'single_item':
									$fields[ $field_index ]['type'] = 'single_item';
									break;

								case 'total_field':
									$fields[ $field_index ]['type'] = 'total_field';
									break;
							}
						}// End switch().
						$filter_data = array(
							'fields'     => $fields,
							'field'      => $field,
							'field_name' => $field_name,
						);
						/**
						 * Filter to retrieve the filtered field data.
						 *
						 * @param array $filter_data Field data.
						 */
						$filtered_data_array = apply_filters( 'user_registration_profile_field_filter_' . $field_key, $filter_data );
						if ( isset( $filtered_data_array['fields'] ) ) {
							$fields = $filtered_data_array['fields'];
						}
					}// End foreach().
				}// End foreach().
			}// End foreach().
			return $fields;
		}
	}
endif;

return new UR_Admin_Profile();
