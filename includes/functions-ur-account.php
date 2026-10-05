<?php
/**
 * UserRegistration Account Functions
 *
 * Functions for account specific things.
 *
 * @package  UserRegistration/Functions
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'get_avatar', 'ur_replace_gravatar_image', 99, 6 );
add_filter( 'ajax_query_attachments_args', 'ur_show_current_user_attachments' );

/**
 * Limit media library access to own uploads.
 *
 * @since 1.5.8
 *
 * @param  array $query User Queries.
 *
 * @return array
 */
function ur_show_current_user_attachments( $query ) {
	$user_id = get_current_user_id();

	if ( $user_id && ! current_user_can( 'edit_others_posts' ) ) {
		$query['author'] = $user_id;
	}

	return $query;
}

/**
 * Returns the url to the lost password endpoint url.
 *
 * @param  string $default_url Default lost password URL.
 *
 * @return string
 */
function ur_lostpassword_url( $default_url = '' ) {

	// Don't redirect to the user registration endpoint on global network admin lost passwords.
	if ( is_multisite() && isset( $_GET['redirect_to'] ) && false !== strpos( wp_unslash( $_GET['redirect_to'] ), network_admin_url() ) ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return $default_url;
	}

	// Don't  change default url if admin side login form.
	if ( 'wp-login.php' === $GLOBALS['pagenow'] ) {
		return $default_url;
	}

	$lost_password_page = get_option( 'user_registration_lost_password_page_id', false );

	if ( $lost_password_page && ! empty( get_post( $lost_password_page ) ) ) {
		return get_permalink( ur_get_translated_page_id( $lost_password_page ) );
	} else {
		$ur_account_page_url = ur_get_page_permalink( 'myaccount' );

		$ur_account_page_exists = ur_get_page_id( 'myaccount' ) > 0;
		$lost_password_endpoint = get_option( 'user_registration_myaccount_lost_password_endpoint', 'lost-password' );
		$lost_password_page     = get_option( 'user_registration_general_setting_lost_password_page', '' );

		$ur_login_page_exists = ur_get_page_id( 'login' ) > 0;

		if ( ! $ur_account_page_exists && $ur_login_page_exists ) {
			update_option( 'user_registration_login_page_id', ur_get_page_id( 'login' ) );
		}

		if ( $ur_account_page_exists && ! empty( $lost_password_endpoint ) ) {
			return ur_get_endpoint_url( $lost_password_endpoint, '', $ur_account_page_url );
		} elseif ( $ur_login_page_exists && ! empty( $lost_password_endpoint ) ) {
			return ur_get_endpoint_url( $lost_password_endpoint, '', get_permalink( ur_get_page_id( 'login' ) ) );
		} elseif ( ! empty( $lost_password_endpoint ) && 'lost-password' !== $lost_password_endpoint ) {
			return str_replace( 'lost-password', $lost_password_endpoint, $default_url );
		} else {
			return $default_url;
		}
	}
}

add_filter( 'lostpassword_url', 'ur_lostpassword_url', 20, 1 );

/**
 * Returns the URL to the reset password endpoint.
 *
 * @param  string $default_url Default reset password URL.
 *
 * @return string
 */
function ur_resetpassword_url( $default_url = '' ) {

	// Don't redirect to the user registration endpoint on global network admin resets.
	if ( is_multisite() && isset( $_GET['redirect_to'] ) && false !== strpos( wp_unslash( $_GET['redirect_to'] ), network_admin_url() ) ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return $default_url;
	}

	// Don't change default URL if admin side reset form is being used.
	if ( 'wp-login.php' === $GLOBALS['pagenow'] ) {
		return $default_url;
	}

	// The lost-password page is the primary page for the reset flow.
	$lost_password_page = get_option( 'user_registration_lost_password_page_id', false );

	if ( $lost_password_page && ! empty( get_post( $lost_password_page ) ) ) {
		return get_permalink( ur_get_translated_page_id( $lost_password_page ) );
	}

	// Legacy fallback: a separate reset-password page (deprecated option).
	$reset_password_page = get_option( 'user_registration_reset_password_page_id', false );

	if ( $reset_password_page && ! empty( get_post( $reset_password_page ) ) ) {
		return get_permalink( ur_get_translated_page_id( $reset_password_page ) );
	}

	return ur_lostpassword_url();
}
add_filter( 'retrieve_password_url', 'ur_resetpassword_url', 20, 1 );

/**
 * Get My Account menu items.
 *
 * @return array
 */
function ur_get_account_menu_items() {
	$endpoints = array(
		'edit-profile'  => get_option( 'user_registration_myaccount_edit_profile_endpoint', 'edit-profile' ),
		'edit-password' => get_option( 'user_registration_myaccount_change_password_endpoint', 'edit-password' ),
		'user-logout'   => get_option( 'user_registration_logout_endpoint', 'user-logout' ),
	);

	$items = array(
		'dashboard'     => __( 'Dashboard', 'user-registration' ),
		'edit-profile'  => __( 'Profile Details', 'user-registration' ),
		'edit-password' => __( 'Change Password', 'user-registration' ),
		'user-logout'   => __( 'Logout', 'user-registration' ),
	);

	// Remove missing endpoints.
	foreach ( $endpoints as $endpoint_id => $endpoint ) {
		if ( empty( $endpoint ) ) {
			unset( $items[ $endpoint_id ] );
		}
	}
	/**
	 * Applies a filter to modify the account menu items.
	 *
	 * The 'user_registration_account_menu_items' filter allows developers to modify
	 * the account menu items in User Registration.
	 *
	 * @param array $items Default array of account menu items.
	 */
	return apply_filters( 'user_registration_account_menu_items', $items );
}

/**
 * Get the endpoint whose content a bare My Account URL renders.
 *
 * @since xx.xx.xx
 *
 * @return string 'edit-profile' on new installations, 'dashboard' otherwise, unless filtered.
 */
function ur_get_account_default_endpoint() {
	$default_endpoint = ur_string_to_bool( get_option( 'urm_is_new_installation', false ) ) ? 'edit-profile' : 'dashboard';

	/**
	 * Filters the endpoint a bare My Account URL shows and highlights.
	 *
	 * The endpoint's `user_registration_account_{endpoint}_endpoint` action renders its content.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $default_endpoint Default endpoint slug.
	 */
	return apply_filters( 'user_registration_account_default_endpoint', $default_endpoint );
}

/**
 * Get account menu item classes.
 *
 * @param  string $endpoint Endpoint.
 *
 * @return string
 */
function ur_get_account_menu_item_classes( $endpoint ) {
	global $wp;

	$classes = array(
		'user-registration-MyAccount-navigation-link',
		'user-registration-MyAccount-navigation-link--' . $endpoint,
	);

	// Set current item class.
	$current = isset( $wp->query_vars[ $endpoint ] );
	if ( ur_get_account_default_endpoint() === $endpoint && ( isset( $wp->query_vars['page'] ) || empty( $wp->query_vars ) ) ) {
		$current = true; // The default endpoint has no URL segment, so needs a custom check.
	}

	if ( $current ) {
		$classes[] = 'is-active';
	}
	/**
	 * Applies a filter to modify the classes for an account menu item.
	 *
	 * The 'user_registration_account_menu_item_classes' filter allows developers to modify
	 * the classes for an account menu item.
	 *
	 * @param array $classes Default array of classes for the account menu item.
	 * @param string $endpoint The endpoint for the account menu item.
	 */
	$classes = apply_filters( 'user_registration_account_menu_item_classes', $classes, $endpoint );

	return implode( ' ', array_map( 'sanitize_html_class', $classes ) );
}

/**
 * Get account endpoint URL.
 *
 * @since 2.6.0
 *
 * @param string $endpoint Endpoint.
 *
 * @return string
 */
function ur_get_account_endpoint_url( $endpoint ) {
	if ( 'dashboard' === $endpoint ) {
		return ur_get_page_permalink( 'myaccount' );
	}
	if ( 'user-logout' === $endpoint ) {
		return ur_logout_url( ur_get_page_permalink( 'myaccount' ) );
	}
	if ( 'ur-login-logout' === $endpoint ) {
		return '#ur_login_logout#';
	}
	return ur_get_endpoint_url( $endpoint, '', ur_get_page_permalink( 'myaccount' ) );
}

/**
 * Custom function to override get_gavatar function.
 *
 * @param [type] $avatar Avatar of user.
 * @param [type] $id_or_email ID or email of user.
 * @param [type] $size Size of avatar.
 * @param [type] $default Default avatar.
 * @param [type] $alt Alt.
 * @param array  $args Args.
 */
function ur_replace_gravatar_image( $avatar, $id_or_email, $size, $default, $alt, $args = array() ) {
	global $wp_filter;

	remove_all_filters( 'get_avatar' );

	add_filter( 'get_avatar', 'ur_replace_gravatar_image', 100, 6 );

	// Process the user identifier.
	$user = false;
	if ( is_numeric( $id_or_email ) ) {
		$user = get_user_by( 'id', absint( $id_or_email ) );
	} elseif ( is_string( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
	} elseif ( $id_or_email instanceof WP_User ) {
		// User Object.
		$user = $id_or_email;
	} elseif ( $id_or_email instanceof WP_Post ) {
		// Post Object.
		$user = get_user_by( 'id', (int) $id_or_email->post_author );
	} elseif ( $id_or_email instanceof WP_Comment ) {

		if ( ! empty( $id_or_email->user_id ) ) {
			$user = get_user_by( 'id', (int) $id_or_email->user_id );
		}
	}

	if ( ! $user || is_wp_error( $user ) ) {
		return $avatar;
	}

	$profile_picture_url = get_user_meta( $user->ID, 'user_registration_profile_pic_url', true );

	// Track if we've already validated the image (optimization for attachment IDs).
	$is_valid_image = false;

	if ( is_numeric( $profile_picture_url ) ) {
		// Profile picture is stored as attachment ID - validate it directly here.
		$attachment_id = absint( $profile_picture_url );
		if ( wp_attachment_is_image( $attachment_id ) ) {
			$profile_picture_url = wp_get_attachment_url( $attachment_id );
			$is_valid_image      = true; // Skip ur_check_url_is_image() call later.
		} else {
			$profile_picture_url = ''; // Invalid attachment, clear it.
		}
	}
	$profile_picture_url = apply_filters( 'user_registration_profile_picture_url', $profile_picture_url, $user->ID );

	$class = array( 'avatar', 'avatar-' . (int) $args['size'], 'photo' );

	if ( ( isset( $args['found_avatar'] ) && ! $args['found_avatar'] ) || ( isset( $args['force_default'] ) && $args['force_default'] ) ) {
		$class[] = 'avatar-default';
	}

	if ( $args['class'] ) {
		if ( is_array( $args['class'] ) ) {
			$class = array_merge( $class, $args['class'] );
		} else {
			$class[] = $args['class'];
		}
	}

	if ( $profile_picture_url && ( $is_valid_image || ur_check_url_is_image( $profile_picture_url ) ) ) {
		$avatar = sprintf(
			"<img alt='%s' src='%s' srcset='%s' class='%s' height='%d' width='%d' %s/>",
			esc_attr( $args['alt'] ),
			esc_url( $profile_picture_url ),
			esc_url( $profile_picture_url ) . ' 2x',
			esc_attr( join( ' ', $class ) ),
			(int) $args['height'],
			(int) $args['width'],
			$args['extra_attr']
		);
	}

	return $avatar;
}

if ( ! function_exists( 'ur_get_user_login_option' ) ) {
	/**
	 * Returns user login option set in 'ur_login_option' meta.
	 * If the meta is not set ( old users ), login option from form is returned.
	 *
	 * @param integer $user_id User Id.
	 * @return string
	 */
	function ur_get_user_login_option( $user_id = 0 ) {

		$user_id = (int) $user_id;

		if ( 1 > $user_id ) {
			return '';
		}

		$login_option = get_user_meta( $user_id, 'ur_login_option', true );

		if ( empty( $login_option ) ) {
			$form_id = ur_get_form_id_by_userid( $user_id );

			// Handle backwards compatibility.
			$login_option = get_option( 'user_registration_general_setting_login_options', 'default' );
			$login_option = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_login_options', $login_option );
		}
		return $login_option;
	}
}
