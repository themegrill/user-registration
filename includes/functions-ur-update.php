<?php
/**
 * UserRegistration Updates
 *
 * Function for updating data, used by the background updater.
 *
 * @package UserRegistration\Functions
 * @version 1.2.0
 */

use WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Update DB Version.
 */
function ur_update_100_db_version() {
	UR_Install::update_db_version( '1.0.0' );
}

/**
 * Update usermeta.
 */
function ur_update_120_usermeta() {
	global $wpdb;

	// Get usermeta.
	$usermeta = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM $wpdb->usermeta WHERE meta_key LIKE 'user_registration\_%';" );

	// Update old usermeta values.
	foreach ( $usermeta as $metadata ) {
		$user_id     = intval( $metadata->user_id );
		$json_val    = json_decode( $metadata->meta_value );
		$explode_val = explode( '__', $metadata->meta_value );

		if ( $json_val && $metadata->meta_value != $json_val ) {
			update_user_meta( $user_id, $metadata->meta_key, json_decode( $metadata->meta_value ) );
		} elseif ( $metadata->meta_value !== end( $explode_val ) ) {
			update_user_meta( $user_id, $metadata->meta_key, trim( end( $explode_val ) ) );
		}
	}

	// Delete old user keys from usermeta.
	$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key LIKE 'ur_%_params';" );
}

/**
 * Update DB Version.
 */
function ur_update_120_db_version() {
	UR_Install::update_db_version( '1.2.0' );
}

/**
 * Update usermeta.
 */
function ur_update_125_usermeta() {

	$users = get_users( array( 'fields' => array( 'ID' ) ) );

	foreach ( $users as $user_id ) {

		if ( metadata_exists( 'user', $user_id->ID, 'user_registration_user_first_name' ) ) {
			$first_name = get_user_meta( $user_id->ID, 'user_registration_user_first_name', true );
			update_user_meta( $user_id->ID, 'first_name', $first_name );
			delete_user_meta( $user_id->ID, 'user_registration_user_first_name' );
		}

		if ( metadata_exists( 'user', $user_id->ID, 'user_registration_user_last_name' ) ) {
			$last_name = get_user_meta( $user_id->ID, 'user_registration_user_last_name', true );
			update_user_meta( $user_id->ID, 'last_name', $last_name );
			delete_user_meta( $user_id->ID, 'user_registration_user_last_name' );
		}

		if ( metadata_exists( 'user', $user_id->ID, 'user_registration_user_description' ) ) {
			$description = get_user_meta( $user_id->ID, 'user_registration_user_description', true );
			update_user_meta( $user_id->ID, 'description', $description );
			delete_user_meta( $user_id->ID, 'user_registration_user_description' );
		}

		if ( metadata_exists( 'user', $user_id->ID, 'user_registration_user_nickname' ) ) {
			$nickname = get_user_meta( $user_id->ID, 'user_registration_user_nickname', true );
			update_user_meta( $user_id->ID, 'nickname', $nickname );
			delete_user_meta( $user_id->ID, 'user_registration_user_nickname' );
		}
	}
}
/**
 * Update DB Version.
 */
function ur_update_125_db_version() {
	UR_Install::update_db_version( '1.2.5' );
}

/**
 * Update DB Version.
 */
function ur_update_130_db_version() {
	UR_Install::update_db_version( '1.3.0' );
}

/**
 * Update usermeta.
 */
function ur_update_130_post() {
	$posts = get_posts( 'post_type=user_registration' );
	foreach ( $posts as $post ) {
		$post_content       = isset( $post->post_content ) ? $post->post_content : '';
		$post_content_array = json_decode( $post_content );

		foreach ( $post_content_array as $post_content_row ) {
			foreach ( $post_content_row as $post_content_grid ) {
				foreach ( $post_content_grid as $field ) {

					if ( isset( $field->field_key ) && isset( $field->general_setting->field_name ) ) {
						switch ( $field->field_key ) {
							case 'user_username':
								$field->general_setting->field_name = $field->field_key = 'user_login';
								break;
							case 'user_password':
								$field->general_setting->field_name = $field->field_key  = 'user_pass';
								break;
							case 'user_display_name':
								$field->general_setting->field_name = $field->field_key = 'display_name';
								break;
							case 'user_description':
								$field->general_setting->field_name = $field->field_key = 'description';
								break;
							case 'user_first_name':
								$field->general_setting->field_name = $field->field_key = 'first_name';
								break;
							case 'user_last_name':
								$field->general_setting->field_name = $field->field_key = 'last_name';
								break;
							case 'user_nickname':
								$field->general_setting->field_name = $field->field_key = 'nickname';
								break;
						}
					}
				}
			}
			$post_content       = json_encode( $post_content_array );
			$post->post_content = $post_content;
		}
		wp_update_post( $post );
	}

	$mailchimp_settings = get_option( 'urmc_mailchimp_settings' );

	if ( $mailchimp_settings && is_array( $mailchimp_settings ) ) {

		if ( isset( $mailchimp_settings['data'] ) && is_array( $mailchimp_settings['data'] ) ) {

			foreach ( $mailchimp_settings['data'] as $id => $mailchimp_data ) {

				if ( isset( $mailchimp_data['fields'] ) ) {

					foreach ( $mailchimp_data['fields'] as $key => $field ) {

						switch ( $field ) {
							case 'user_username':
								$mailchimp_data['fields'][ $key ] = 'user_login';
								break;
								$mailchimp_data['fields'][ $key ] = 'user_pass';
								break;
							case 'user_display_name':
								$mailchimp_data['fields'][ $key ] = 'display_name';
								break;
							case 'user_description':
								$mailchimp_data['fields'][ $key ] = 'description';
								break;
							case 'user_first_name':
								$mailchimp_data['fields'][ $key ] = 'first_name';
								break;
							case 'user_last_name':
								$mailchimp_data['fields'][ $key ] = 'last_name';
								break;
							case 'user_nickname':
								$mailchimp_data['fields'][ $key ] = 'nickname';
								break;
						}
					}
				}
				$mailchimp_settings['data'][ $id ] = $mailchimp_data;
			}
		}
		update_option( 'urmc_mailchimp_settings', $mailchimp_settings );
	}
}

/**
 * Update DB Version.
 */
function ur_update_140_db_version() {
	UR_Install::update_db_version( '1.4.0' );
}

/**
 * Delete unused option.
 */
function ur_update_140_option() {
	$unused_options = array(
		'user_registration_general_setting_default_user_role',
		'user_registration_general_setting_enable_strong_password',
		'user_registration_general_setting_form_submit_label',
	);

	foreach ( $unused_options as $unused_option ) {
		delete_option( $unused_option );
	}
}

/**
 * Update DB Version.
 */
function ur_update_142_db_version() {
	UR_Install::update_db_version( '1.4.2' );
}

/**
 * Replace option name user_registration_myaccount_edit_account_endpoint to user_registration_myaccount_change_password_endpoint.
 */
function ur_update_142_option() {
	$value = get_option( 'user_registration_myaccount_edit_account_endpoint' );
	update_option( 'user_registration_myaccount_change_password_endpoint', $value );
	delete_option( 'user_registration_myaccount_edit_account_endpoint' );
}

/**
 * Update DB Version.
 */
function ur_update_1581_db_version() {
	UR_Install::update_db_version( '1.5.8.1' );
}

/**
 * Update DB Version.
 */
function ur_update_160_db_version() {
	UR_Install::update_db_version( '1.6.0' );
}

/**
 * Replace user meta key profile_pic_id to user_registration_profile_pic_id.
 *
 * @since 1.4.8.1
 *
 * @return void.
 */
function ur_update_1581_meta_key() {
	$users = get_users(
		array(
			'meta_key' => 'profile_pic_id',
		)
	);

	foreach ( $users as $user ) {
		$profile_picture_id = get_user_meta( $user->ID, 'profile_pic_id', true );
		update_user_meta( $user->ID, 'user_registration_profile_pic_id', $profile_picture_id );
		delete_user_meta( $user->ID, 'profile_pic_id' );
	}

	// Change ur_ prefix to user_registration_ for review notice skipped option.
	$value = get_option( 'ur_review_notice_dismissed' );
	update_option( 'user_registration_review_notice_dismissed', $value );
	delete_option( 'ur_review_notice_dismissed' );
}

/**
 * Migrate the redirect option from global settings to form-wise settings.
 *
 * @since  1.6.0
 *
 * @return void.
 */
function ur_update_160_option_migrate() {
	$redirect_url = get_option( 'user_registration_general_setting_redirect_options' );

	// Get all posts with user_registration post type.
	$posts = get_posts( 'post_type=user_registration' );

	foreach ( $posts as $post ) {

		// Update global setting to all user registration posts meta.
		update_post_meta( $post->ID, 'user_registration_form_setting_redirect_options', $redirect_url );
	}
}

/**
 * Update DB Version.
 */
function ur_update_162_db_version() {
	UR_Install::update_db_version( '1.6.2' );
}

/**
 * Replace user meta key profile_pic_id to user_registration_profile_pic_id.
 *
 * @since 1.4.8.1
 *
 * @return void.
 */
function ur_update_162_meta_key() {
	$users = get_users(
		array(
			'meta_key' => 'user_registration_profile_pic_id',
		)
	);

	foreach ( $users as $user ) {
		$profile_picture_id = get_user_meta( $user->ID, 'user_registration_profile_pic_id', true );
		if ( $profile_picture_id ) {
			$profile_picture_url = wp_get_attachment_thumb_url( $profile_picture_id );
			update_user_meta( $user->ID, 'user_registration_profile_pic_url', $profile_picture_url );
		}
		delete_user_meta( $user->ID, 'user_registration_profile_pic_id' );
	}

	// Delete Redirect options form general setting as previous version refered to do so.
	delete_option( 'user_registration_general_setting_redirect_options' );
}

/**
 * Snapshot the legacy-payment-fields flag for a site upgrading from before it existed.
 *
 * The fresh-install path (UR_Install::install()) only runs on activation, so a site
 * updating from an earlier version never gets this option set and falls back to a
 * live form scan on every check. Initialize it here the same way, once, on update.
 *
 * @since 5.2.9
 *
 * @return void.
 */
function ur_update_529_legacy_payment_fields_flag() {
	if ( null === get_option( 'urm_is_legacy_payment_fields_user', null ) ) {
		add_option( 'urm_is_legacy_payment_fields_user', ur_site_has_any_frozen_payment_field() ? 1 : 0 );
	}
}

/**
 * Update DB Version.
 */
function ur_update_529_db_version() {
	UR_Install::update_db_version( '5.2.9' );
}

/**
 * Set Redirect after Registration option in form settings.
 * Change Integration to Captcha Option names.
 *
 * @return void
 */
function ur_update_30_option_migrate() {

	// Get all posts with user_registration post type.
	$posts = get_posts( 'post_type=user_registration' );

	foreach ( $posts as $post ) {

		$redirect_url = ur_get_single_post_meta( $post->ID, 'user_registration_form_setting_redirect_options', get_option( 'user_registration_general_setting_redirect_options', '' ) );

		if ( ! empty( $redirect_url ) ) {
			update_post_meta( $post->ID, 'user_registration_form_setting_redirect_after_registration', 'external-url' );
		}
	}

	// Recaptcha Migrations.
	$recaptcha_type                 = get_option( 'user_registration_integration_setting_recaptcha_version', 'v2' );
	$invisible_recaptcha            = get_option( 'user_registration_integration_setting_invisible_recaptcha_v2', 'no' );
	$recaptcha_invisible_site_key   = get_option( 'user_registration_integration_setting_recaptcha_invisible_site_key' );
	$recaptcha_invisible_secret_key = get_option( 'user_registration_integration_setting_recaptcha_invisible_site_secret' );
	$recaptcha_site_key             = get_option( 'user_registration_integration_setting_recaptcha_site_key' );
	$recaptcha_secret_key           = get_option( 'user_registration_integration_setting_recaptcha_site_secret' );
	$recaptcha_site_key_v3          = get_option( 'user_registration_integration_setting_recaptcha_site_key_v3' );
	$recaptcha_site_secret_v3       = get_option( 'user_registration_integration_setting_recaptcha_site_secret_v3' );
	$recaptcha_threshold_score_v3   = get_option( 'user_registration_integration_setting_recaptcha_threshold_score_v3' );
	$site_key_hcaptcha              = get_option( 'user_registration_integration_setting_recaptcha_site_key_hcaptcha' );
	$site_secret_hcaptcha           = get_option( 'user_registration_integration_setting_recaptcha_site_secret_hcaptcha' );
	$auto_generated_pass_email      = get_option( 'user_registration_enable_pro_auto_generated_password_email', true );

	update_option( 'user_registration_captcha_setting_recaptcha_version', $recaptcha_type );
	update_option( 'user_registration_captcha_setting_invisible_recaptcha_v2', $invisible_recaptcha );
	update_option( 'user_registration_captcha_setting_recaptcha_invisible_site_key', $recaptcha_invisible_site_key );
	update_option( 'user_registration_captcha_setting_recaptcha_invisible_site_secret', $recaptcha_invisible_secret_key );
	update_option( 'user_registration_captcha_setting_recaptcha_site_key', $recaptcha_site_key );
	update_option( 'user_registration_captcha_setting_recaptcha_site_secret', $recaptcha_secret_key );
	update_option( 'user_registration_captcha_setting_recaptcha_site_key_v3', $recaptcha_site_key_v3 );
	update_option( 'user_registration_captcha_setting_recaptcha_site_secret_v3', $recaptcha_site_secret_v3 );
	update_option( 'user_registration_captcha_setting_recaptcha_threshold_score_v3', $recaptcha_threshold_score_v3 );
	update_option( 'user_registration_captcha_setting_recaptcha_site_key_hcaptcha', $site_key_hcaptcha );
	update_option( 'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha', $site_secret_hcaptcha );
	update_option( 'user_registration_enable_auto_generated_password_email', $auto_generated_pass_email );

	delete_option( 'user_registration_integration_setting_recaptcha_version' );
	delete_option( 'user_registration_integration_setting_invisible_recaptcha_v2' );
	delete_option( 'user_registration_integration_setting_recaptcha_invisible_site_key' );
	delete_option( 'user_registration_integration_setting_recaptcha_invisible_site_secret' );
	delete_option( 'user_registration_integration_setting_recaptcha_site_key' );
	delete_option( 'user_registration_integration_setting_recaptcha_site_secret' );
	delete_option( 'user_registration_integration_setting_recaptcha_site_key_v3' );
	delete_option( 'user_registration_integration_setting_recaptcha_site_secret_v3' );
	delete_option( 'user_registration_integration_setting_recaptcha_threshold_score_v3' );
	delete_option( 'user_registration_integration_setting_recaptcha_site_key_hcaptcha' );
	delete_option( 'user_registration_integration_setting_recaptcha_site_secret_hcaptcha' );
	delete_option( 'user_registration_enable_pro_auto_generated_password_email' );
}

/**
 * Set user registration installed date option.
 *
 * @return void
 */
function ur_update_322_option_migrate() {

	$activation_date = get_option( 'user_registration_activated', false );

	update_option( 'user_registration_installation_date', $activation_date );
	$days_to_validate = strtotime( $activation_date );
	$days_to_validate = strtotime( '+1 day', $days_to_validate );
	$days_to_validate = date_i18n( 'Y-m-d', $days_to_validate );

	$current_date = date_i18n( 'Y-m-d' );

	if ( $activation_date && $current_date >= $days_to_validate ) {
		update_option( 'user_registration_quick_setup_completed', true );
	}
}

/**
 * URM v5.0 migration functions.
 *
 * @return void
 */
function urm_update_50_option_migrate() {

	$args      = array(
		'order'         => 'ASC',
		'numberposts'   => -1,
		'status'        => 'publish',
		'post_type'     => array( 'user_registration' ),
		'orderby'       => 'ID',
		'order'         => 'DESC',
		'no_found_rows' => true,
		'nopaging'      => true,
	);
	$all_forms = get_posts( $args );

	if ( count( $all_forms ) > 1 ) {
		$enabled_features = get_option( 'user_registration_enabled_features', array() );
		if ( ! isset( $enabled_features['user-registration-multiple-registration'] ) ) {
			$enabled_features[] = 'user-registration-multiple-registration';
			update_option( 'user_registration_enabled_features', $enabled_features );
		}
	}

	$group_args = array(
		'order'         => 'ASC',
		'numberposts'   => -1,
		'status'        => 'publish',
		'post_type'     => array( 'ur_membership_groups' ),
		'orderby'       => 'ID',
		'order'         => 'DESC',
		'no_found_rows' => true,
		'nopaging'      => true,
	);

	$all_groups = get_posts( $group_args );

	if ( count( $all_groups ) > 0 ) {
		$enabled_features = get_option( 'user_registration_enabled_features', array() );
		if ( ! isset( $enabled_features['user-registration-membership-groups'] ) ) {
			$enabled_features[] = 'user-registration-membership-groups';
			update_option( 'user_registration_enabled_features', $enabled_features );
		}
	}
}

/**
 * Migrate global thank you page to per-form redirect settings (v5.1.5).
 * Runs only for forms that contain the membership field.
 *
 * @return void
 */
function ur_update_515_redirect_thank_you_page_migrate() {

	if ( ! function_exists( 'ur_check_module_activation' ) || ! ur_check_module_activation( 'membership' ) ) {
		return;
	}

	$thank_you_page_id = get_option( 'user_registration_thank_you_page_id', '' );

	$posts = get_posts(
		array(
			'post_type'      => 'user_registration',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		)
	);

	foreach ( $posts as $post ) {
		$form_content = isset( $post->post_content ) ? $post->post_content : '';
		if ( false === strpos( $form_content, '"field_key":"membership"' ) ) {
			continue;
		}

		if ( empty( $thank_you_page_id ) || ! get_post_status( $thank_you_page_id ) ) {
			update_post_meta( $post->ID, 'user_registration_form_setting_redirect_after_registration', 'no-redirection' );
		} else {
			if ( 'no-redirection' === ( get_post_meta( $post->ID, 'user_registration_form_setting_redirect_after_registration', true ) ) ) {
				update_post_meta( $post->ID, 'user_registration_form_setting_redirect_after_registration', 'internal-page' );
				update_post_meta( $post->ID, 'user_registration_form_setting_redirect_page', $thank_you_page_id );
			} else {
				update_post_meta( $post->ID, 'user_registration_form_setting_redirect_after_registration', 'internal-page' );

				if ( empty( get_post_meta( $post->ID, 'user_registration_form_setting_redirect_page', true ) ) ) {
					update_post_meta( $post->ID, 'user_registration_form_setting_redirect_page', $thank_you_page_id );
				}
			}

		}
	}
}
