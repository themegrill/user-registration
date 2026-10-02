<?php
/**
 * Edit account form
 *
 * This template can be overridden by copying it to yourtheme/user-registration/myaccount/form-edit-profile.php.
 *
 * HOWEVER, on occasion UserRegistration will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.wpuserregistration.com/docs/how-to-edit-user-registration-template-files-such-as-login-form/
 * @package UserRegistration/Templates
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id = get_current_user_id();
$form_id = ur_get_form_id_by_userid( $user_id );

/**
 * Deprecated in version 3.1.3. Use 'user_registration_before_edit_profile_form_data' instead.
 *
 * @deprecated 3.1.3 Use 'user_registration_before_edit_profile_form_data' instead.
 *
 * @param array array value.
 * @param string deprecated_version.
 * @param string hook_name to be used instead.
 */
do_action_deprecated( 'user_registration_before_edit_profile_form', array(), '3.1.3', 'user_registration_before_edit_profile_form_data' );

/**
 * Fires before rendering the edit profile form with additional data.
 *
 * @param int $user_id User id of the current profile being edited.
 * @param int $form_id Form id through which user registered.
 */
do_action( 'user_registration_before_edit_profile_form_data', $user_id, $form_id );
$layout = get_option( 'user_registration_my_account_layout', 'vertical' );

$is_edit_action = false;

if ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) {
	$is_edit_action = true;
}

?>
<div class="user-registration-MyAccount-content__header">
	<div class="user-registration-MyAccount-content__header-content">
		<?php
		if ( $is_edit_action ) {
			?>
			<a class="urm-back-button" href="<?php echo esc_url( ur_get_account_endpoint_url( 'edit-profile' ) ); ?>">
				<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
			</a>
			<h1><?php echo esc_html__( 'Edit Profile', 'user-registration' ); ?></h1>
			<?php
		} else {
			?>
			<h1><?php echo wp_kses_post( $endpoint_label ); ?></h1>
			<?php
		}
		?>
	</div>
	<?php
	if ( ! $is_edit_action ) {
		?>
		<div class="user-registration-MyAccount-content__header-buttons">
			<a href="<?php echo esc_url( add_query_arg( 'action', 'edit', ur_get_account_endpoint_url( 'edit-profile' ) ) ); ?>" class="user-registration-Button button-secondary urm-profile-action-toggle"><?php esc_html_e( 'Edit Profile', 'user-registration' ); ?></a>
			<a href="<?php echo esc_url( ur_get_account_endpoint_url( 'edit-password' ) ); ?>" class="user-registration-Button button-secondary urm-profile-change-password-btn"><?php esc_html_e( 'Change Password', 'user-registration' ); ?></a>
		</div>
		<?php
	}
	?>
</div>
<div class="user-registration-message-container">
	<?php
	ur_print_notices();
	?>
</div>
<div class="user-registration-MyAccount-content__body">
	<div class="ur-frontend-form login ur-edit-profile" id="ur-frontend-form">
		<form class="user-registration-EditProfileForm edit-profile" action="" method="post" enctype="multipart/form-data" data-form-id="<?php echo esc_attr( $form_id ); ?>">
			<div class="ur-form-row">
				<div class="ur-form-grid">
					<?php

					if ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) {
						?>
							<div class="user-registration-profile-fields"  data-action="edit">
							<?php
							/**
							 * Fires before rendering of profile detail title.
							 */
							do_action( 'user_registration_before_profile_detail_title' );
							?>
							<h2>
							<?php
							$urm_my_account_layout = get_option( 'user_registration_my_account_layout', 'vertical' );

							if ( 'horizontal' === $urm_my_account_layout ) {
								/**
								 * Filter to modify the profile detail title.
								 *
								 * @param string Profile detail title content.
								 * @return string modified profile detail title.
								 */
								$profile_detail_title = apply_filters( 'user_registation_profile_detail_title', __( 'Profile Detail', 'user-registration' ) );
								echo esc_html( $profile_detail_title );
							}
							?>
							</h2>

							<?php

							$is_sync_profile           = ur_option_checked( 'user_registration_sync_profile_picture', false );
							$is_profile_field_disabled = ur_option_checked( 'user_registration_disable_profile_picture', false );
							$is_profile_pic_on_form    = false;
							if ( $is_sync_profile ) {
								foreach ( $form_data_array as $data ) {
									foreach ( $data as $grid_key => $grid_data ) {
										foreach ( $grid_data as $grid_data_key => $single_item ) {
											if ( isset( $single_item->field_key ) && 'profile_picture' === $single_item->field_key ) {
												$is_profile_pic_on_form = true;
											}
										}
									}
								}
							} else {
								$is_profile_pic_on_form = ! $is_profile_field_disabled;
							}
							if ( $is_profile_pic_on_form ) {
								?>
							<div class="user-registration-profile-header">
								<div class="user-registration-img-container" style="width:100%">
									<?php
									$gravatar_image      = get_avatar_url( get_current_user_id(), $args = null );
									$profile_picture_url = get_user_meta( get_current_user_id(), 'user_registration_profile_pic_url', true );

									if ( is_numeric( $profile_picture_url ) ) {
										$profile_picture_url = wp_get_attachment_url( $profile_picture_url );
									}

									$profile_picture_url          = apply_filters( 'user_registration_profile_picture_url', $profile_picture_url, $user_id );
									$image                        = ( ! empty( $profile_picture_url ) ) ? $profile_picture_url : $gravatar_image;
									$max_size                     = wp_max_upload_size();
									$max_upload_size              = $max_size;
									$crop_picture                 = false;
									$profile_pic_args             = array();
									$edit_profile_valid_file_type = 'image/jpeg,image/gif,image/png';

									foreach ( $form_data_array as $data ) {
										foreach ( $data as $grid_key => $grid_data ) {
											foreach ( $grid_data as $grid_data_key => $single_item ) {

												if ( isset( $single_item->field_key ) && 'profile_picture' === $single_item->field_key ) {
													$profile_pic_args             = (array) $single_item->advance_setting;
													$edit_profile_valid_file_type = isset( $single_item->advance_setting->valid_file_type ) && '' !== $single_item->advance_setting->valid_file_type ? implode( ', ', $single_item->advance_setting->valid_file_type ) : $edit_profile_valid_file_type;
													$max_upload_size              = isset( $single_item->advance_setting->max_upload_size ) && '' !== $single_item->advance_setting->max_upload_size ? $single_item->advance_setting->max_upload_size : $max_size;
													$crop_picture                 = isset( $single_item->advance_setting->enable_crop_picture ) ? ur_string_to_bool( $single_item->advance_setting->enable_crop_picture ) : false;
												}
											}
										}
									}

									?>
										<img class="profile-preview" alt="profile-picture" src="<?php echo esc_url( $image ); ?>" style='max-width:96px; max-height:96px;' >

										<p class="user-registration-tips"><?php echo esc_html__( 'Max size: ', 'user-registration' ) . esc_attr( size_format( $max_upload_size ) ); ?></p>
										</div>
										<header>
											<div class="button-group">
												<button type="button" class="button user_registration_profile_picture_upload hide-if-no-js">
													<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
														<path d="M2 19.142V5.807a2.857 2.857 0 0 1 2.858-2.858h6.668a.953.953 0 1 1 0 1.905H4.858a.952.952 0 0 0-.953.953v13.335a.952.952 0 0 0 .953.953h13.336a.952.952 0 0 0 .952-.953v-6.667a.953.953 0 0 1 1.905 0v6.667A2.857 2.857 0 0 1 18.194 22H4.858A2.857 2.857 0 0 1 2 19.142Z"/>
														<path d="M20.095 4.973a1.069 1.069 0 0 0-1.823-.755l-8.586 8.587a.955.955 0 0 0-.241.405l-.557 1.9 1.903-.555.112-.04a.956.956 0 0 0 .294-.2l8.585-8.587.071-.078c.156-.19.242-.43.242-.677Zm1.905 0c0 .789-.313 1.545-.87 2.102l-8.586 8.587c-.34.34-.759.587-1.22.721l.002.001-2.737.8a1.432 1.432 0 0 1-1.785-1.017c-.064-.248-.06-.51.012-.755l.8-2.737c.119-.402.324-.775.6-1.088l.123-.13 8.586-8.586.107-.102A2.973 2.973 0 0 1 22 4.973Z"/>
													</svg>
												</button>
												<?php

												if ( has_action( 'uraf_profile_picture_buttons' ) ) {
													?>
													<div class="uraf-profile-picture-upload">
														<p class="form-row " id="profile_pic_url_field" data-priority="">
															<span class="uraf-profile-picture-upload-node" style="height: 0;width: 0;margin: 0;padding: 0;float: left;border: 0;overflow: hidden;">
															<input type="file" id="ur-profile-pic" name="profile-pic" class="profile-pic-upload" size="<?php echo esc_attr( $max_upload_size ); ?>" accept="<?php echo esc_attr( $edit_profile_valid_file_type ); ?>" style="<?php echo esc_attr( ( $gravatar_image !== $image ) ? 'display:none;' : '' ); ?>" data-crop-picture="<?php echo esc_attr( $crop_picture ); ?>"/>
															<?php echo '<input type="text" class="uraf-profile-picture-input input-text ur-frontend-field" name="profile_pic_url" id="profile_pic_url" value="' . get_user_meta( get_current_user_id(), 'user_registration_profile_pic_url', true ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
															</span>
															<?php
															/**
															 * Fires to display buttons for user profile picture.
															 *
															 * @param array $profile_pic_args Array of buttons to be added.
															 */
															do_action( 'uraf_profile_picture_buttons', $profile_pic_args );
															?>
														</p>
														<div style="clear:both; margin-bottom: 20px"></div>
													</div>

													<?php
												} else {
													?>
													<input type="hidden" name="profile-pic-url" id="profile_pic_url" value="<?php echo esc_attr( get_user_meta( get_current_user_id(), 'user_registration_profile_pic_url', true ) ); ?>" />
													<input type="hidden" name="profile-default-image" value="<?php echo esc_url( $gravatar_image ); ?>" />
													<input type="file" id="ur-profile-pic" name="profile-pic" class="profile-pic-upload" accept="image/jpeg,image/gif,image/png" style="display:none" />
													<button type="button" class="button profile-pic-remove" data-attachment-id="" style="display:none"><?php esc_html_e( 'Remove', 'user-registration' ); ?></button>
													<?php
												}
												?>

											</div>
										</header>
									</div>
								<?php } ?>
							<?php
							/**
							 * Fires at the start of rendering user registration edit profile form.
							 */
							do_action( 'user_registration_edit_profile_form_start' );
							?>
							<div class="user-registration-profile-fields__field-wrapper">

								<?php
								foreach ( $form_data_array as $index => $data ) {
									$row_id = ( ! empty( $row_ids ) ) ? absint( $row_ids[ $index ] ) : $index;

									$row_cl_enabled = '';
									$row_cl_map     = '';

									// If the conditional logic addon is installed.
									if ( class_exists( 'UserRegistrationConditionalLogic' ) ) {
										$form_row_data = get_post_meta( $form_id, 'user_registration_form_row_data', true );
										$row_datas     = ! empty( $form_row_data ) ? json_decode( $form_row_data ) : array();
										foreach ( $row_datas as $individual_row_data ) {
											$conditional_logic_enabled = false;
											$conditional_settings      = array();

											if ( isset( $individual_row_data->row_id ) && $row_id == $individual_row_data->row_id && isset( $individual_row_data->conditional_logic_enabled ) ) {

												$row_cl_enabled = ur_string_to_bool( $individual_row_data->conditional_logic_enabled ) ? ur_string_to_bool( $individual_row_data->conditional_logic_enabled ) : '';
												$row_cl_map     = isset( $individual_row_data->cl_map ) ? $individual_row_data->cl_map : array();
											}
										}
									}

									$row_cl_map_attr = is_array( $row_cl_map ) ? wp_json_encode( $row_cl_map ) : $row_cl_map;

									ob_start();
									echo '<div class="ur-form-row" data-row-id="' . esc_attr( $row_id ) . '" data-conditional-logic-enabled="' . esc_attr( $row_cl_enabled ) . '" data-conditional-logic-map="' . esc_attr( $row_cl_map_attr ) . '">';
									user_registration_edit_profile_row_template( $data, $profile );
									echo '</div>';
									$row_template = ob_get_clean();

									$row_template = apply_filters( 'user_registration_frontend_edit_profile_form_row_template', $row_template, $form_id, $profile, $row_id, $data );

									echo $row_template; // phpcs:ignore
								}
								?>

							</div>
							<?php
							do_action( 'user_registration_edit_profile_form' );
							$submit_btn_class =
							/**
							 * Filter to modify the form update button class.
							 *
							 * @param array array value.
							 * @return array form update button classes.
							 */
							apply_filters( 'user_registration_form_update_btn_class', array() );
							?>
							<p>
								<?php
								/**
								 * Filter to modify the profile update button text.
								 *
								 * @param string Text content to be modified.
								 * @return string button text.
								 */
								$submit_btn_text = apply_filters( 'user_registration_profile_update_button', __( 'Save changes', 'user-registration' ) );
								if ( ur_option_checked( 'user_registration_ajax_form_submission_on_edit_profile', false ) ) {
									?>
									<button type="submit" class="user-registration-submit-Button btn button <?php echo esc_attr( implode( ' ', $submit_btn_class ) ); ?>" name="save_account_details" ><span></span>
										<?php echo esc_html( $submit_btn_text ); ?>
									</button>
									<?php
								} else {
									wp_nonce_field( 'save_profile_details' );
									?>
									<input type="submit" class="user-registration-Button button <?php echo esc_attr( implode( ' ', $submit_btn_class ) ); ?>" name="save_account_details" value="<?php echo esc_attr( $submit_btn_text ); ?>"/>
									<?php
									echo apply_filters( 'user_registration_edit_profile_extra_data_div', '', $form_id ); // phpcs:ignore.
									?>
									<input type="hidden" name="action" value="save_profile_details" />
									<?php
								}
								?>
							</p>
						</div>
						<?php
					} else {
						?>
						<div class="user-registration-profile-fields" data-action="view">
							<?php
							/**
							 * Fires before rendering of profile detail title.
							 */
							do_action( 'user_registration_before_profile_detail_title' );
							?>
							<h2>
							<?php
							$urm_my_account_layout = get_option( 'user_registration_my_account_layout', 'vertical' );

							?>
							</h2>
							<?php
							$is_profile_pic_on_form = ! ur_option_checked( 'user_registration_disable_profile_picture', false );
							if ( $is_profile_pic_on_form ) {
								?>
									<div class="user-registration-profile-header">
										<div class="user-registration-img-container" style="width:100%">
										<?php
										$gravatar_image      = get_avatar_url( get_current_user_id(), $args = null );
										$profile_picture_url = get_user_meta( get_current_user_id(), 'user_registration_profile_pic_url', true );

										if ( is_numeric( $profile_picture_url ) ) {
											$profile_picture_url = wp_get_attachment_url( $profile_picture_url );
										}

										$profile_picture_url = apply_filters( 'user_registration_profile_picture_url', $profile_picture_url, $user_id );
										$image               = ( ! empty( $profile_picture_url ) ) ? $profile_picture_url : $gravatar_image;
										?>
											<img class="profile-preview" alt="profile-picture" src="<?php echo esc_url( $image ); ?>" style='max-width:96px; max-height:96px;' >
										</div>
									</div>
								<?php
							}
							?>
							<?php
								$user            = get_userdata( $user_id );
								$form_id         = ur_get_form_id_by_userid( $user_id );
								$form_data_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();

								// Apply field visibility filter to the read-only view too, so fields hidden on Profile Details are not rendered here.
								$form_data_array = apply_filters( 'user_registration_profile_account_filter_all_fields', $form_data_array, $form_id, current_user_can( 'manage_options' ) );

								$row_ids = array();
							if ( ! empty( $form_data_array ) ) {
								$row_ids       = get_post_meta( $form_id, 'user_registration_form_row_ids', true );
								$form_row_data = json_decode( get_post_meta( $form_id, 'user_registration_form_row_data', true ), true );
							}
								$row_ids = ! empty( $row_ids ) ? json_decode( $row_ids ) : array();

							?>
							<div class="user-registration-profile-fields__field-wrapper">
								<?php
								foreach ( $form_data_array as $index => $row_data ) {
									$row_id = $index;
									$ignore = false;
									if ( ! empty( $row_ids ) && isset( $row_ids[ $index ] ) ) {
										$row_id = absint( $row_ids[ $index ] );
									}

									if ( ! empty( $form_row_data ) ) {
										foreach ( $form_row_data as $key => $value ) {
											if ( $value['row_id'] == $row_id && isset( $value['type'] ) && 'repeater' === $value['type'] ) {
												$ignore = true;
											}
										}
									}

									if ( ! $ignore ) {
										echo '<div class="ur-form-row" data-row-id="' . esc_attr( $row_id ) . '">';
										echo '<div class="ur-form-grid">';

										foreach ( $row_data as $grid_key => $grid_data ) {
											foreach ( $grid_data as $grid_data_key => $single_item ) {
												if ( ! isset( $single_item->general_setting->field_name ) ) {
													continue;
												}

												$field_name = $single_item->general_setting->field_name;
												$field_key  = isset( $single_item->field_key ) ? $single_item->field_key : '';

												/**
												 * Return fields to skip display in User view page.
												 *
												 * @since 4.1
												 */
												$skip_fields = apply_filters(
													'user_registration_single_user_view_skip_form_fields',
													array(
														'user_confirm_email',
														'user_pass',
														'user_confirm_password',
														'html',
														'section_title',
														'billing_address_title',
														'shipping_address_title',
														'profile_picture',
														'captcha',
														'multiple_choice',
														'single_item',
														'quantity_field',
														'stripe_gateway',
														'authorize_net_gateway',
														'total_field',
														'subscription_plan',
														'membership',
														'password',
														'privacy_policy',
													)
												);

												if ( in_array( $field_key, $skip_fields, true ) ) {
													continue;
												}

												echo '<div class="ur-field-item">';
												echo '<label class="ur-label">' . esc_html( $single_item->general_setting->label ) . '</label>';

												$value = '';

												$user_metadata_details = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );

												if ( in_array(
													$field_key,
													array(
														'user_login',
														'user_email',
														'display_name',
														'user_url',
													),
													true
												) ) {
													$value = $user->$field_key;
												} elseif ( 'multi_select2' === $field_key ) {
													$values = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );

													if ( ! empty( $values ) ) {
														$value = implode( ',', $values );
													}
												} elseif ( 'country' === $field_key ) {
													$value   = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );
													$value   = is_array( $value ) ? '' : (string) $value;
													$is_json = preg_match( '/^\{.*\}$/s', $value ) ? true : false;
													if ( $is_json ) {
														$country_data = json_decode( $value, true );
														$country_code = isset( $country_data['country'] ) ? $country_data['country'] : '';
														$state_code   = isset( $country_data['state'] ) ? $country_data['state'] : '';
														$value        = ur_format_country_field_data( $country_code, $state_code );
													} else {
														$country_class = ur_load_form_field_class( $field_key );
														$countries     = $country_class::get_instance()->get_country();
														$value         = isset( $countries[ $value ] ) ? $countries[ $value ] : $value;
													}
												} elseif ( 'signature' === $field_key ) {
													$value = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );
													$value = wp_get_attachment_url( $value );
												} elseif ( 'membership' === $field_key ) {
													$membership_id = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );
													$value         = get_the_title( $membership_id );
												} else {
													$value = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );

													// For Woocommerce fields.
													$value = empty( $value ) ? get_user_meta( $user->ID, $field_name, true ) : $value;
												}

												$checkbox_fields = array(
													'checkbox',
													'privacy_policy',
													'mailerlite',
													'separate_shipping',
												);

												// Mark checkbox fields as Checked/Unchecked.
												if ( in_array( $field_key, $checkbox_fields, true ) ) {
													$value = is_array( $value ) ? implode( ', ', $value ) : esc_attr( $value );
												}

												// Display the default values in user entry page if field visibility is used.
												if ( ! metadata_exists( 'user', $user_id, 'user_registration_' . $field_name ) && ! in_array( $field_key, $skip_fields ) ) {
													$profile       = user_registration_form_data( $user_id, $form_id );
													$profile_index = 'user_registration_' . $field_name;

													if ( isset( $profile[ $profile_index ]['default'] ) ) {
														$default_value = $profile[ $profile_index ]['default'];

														if ( is_array( $default_value ) ) {
															$value = implode( ', ', $default_value );
														} else {
															$value = esc_html( $default_value );
														}
													} elseif ( metadata_exists( 'user', $user_id, $field_name ) ) {
														$value = get_user_meta( $user_id, $field_name, true );
													} else {
														$value = '';
													}

													if ( empty( $value ) && isset( $profile[ $profile_index ]['type'] ) && 'date' === $profile[ $profile_index ]['type'] ) {
														if ( isset( $profile[ $profile_index ]['custom_attributes']['data-default-date'] ) && 1 === absint( $profile[ $profile_index ]['custom_attributes']['data-default-date'] ) ) {
															$date_format = isset( $profile[ $profile_index ]['custom_attributes']['data-date-format'] ) ? $profile[ $profile_index ]['custom_attributes']['data-date-format'] : 'd/m/Y';
															$value       = date( $date_format, time() );
														}
													}
												}

												/**
												 * Modify value for the single field.
												 *
												 * @since 4.1
												 */
												$value = apply_filters( 'user_registration_single_user_view_field_value', $value, $field_name, $field_key );

												$non_text_fields = apply_filters(
													'user_registration_single_user_view_non_text_fields',
													array(
														'file',
													)
												);

												if ( is_string( $value ) && ! in_array( $field_key, $non_text_fields, true ) ) {
													if ( 'wysiwyg' === $field_key ) {
														echo wp_kses_post(
															'<div class="single-field__wysiwyg"> ' . html_entity_decode( $value ) . '</div>'
														);
													} elseif ( 'signature' === $field_key ) {
														echo wp_kses_post(
															'<div class="single-field__signature"><img src="' . esc_url( $value ) . '" width="100%" style="position:relative;top:4px;" /></div>'
														);
													} elseif ( 60 > strlen( $value ) ) {
														printf(
															'<input type="text" value="%s" disabled>',
															esc_attr( $value )
														);
													} else {
														printf(
															'<textarea rows="6" disabled>%s</textarea>',
															esc_attr( $value )
														);
													}
												} else {
													$field_value = get_user_meta( $user_id, 'user_registration_' . $field_key, true );
													do_action( 'user_registration_single_user_view_output_' . $field_key . '_field', $user_id, $single_item, $field_value );
												}
												echo '</div>';
											}
										}
										echo '</div>';
										echo '</div>';
									}
								}
								?>
							</div>
						</div>
						<?php
					}
					?>
				</div>

			</div>
		</form>
	</div>

	<?php
	/**
	 * Fires after rendering the user registration edit profile form.
	 */
	do_action( 'user_registration_after_edit_profile_form' );
	?>
</div>
