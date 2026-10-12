<?php
/**
 * My Account navigation
 *
 * This template can be overridden by copying it to yourtheme/user-registration/myaccount/navigation.php.
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
	exit; // Exit if accessed directly.
}

/**
 * Action to fire before the rendering of user registration account navigation.
 */
do_action( 'user_registration_before_account_navigation' );
$logout_confirmation = apply_filters( 'user_registration_disable_logout_confirmation_status', ur_option_checked( 'user_registration_disable_logout_confirmation', true ) );

$layout = get_option( 'user_registration_my_account_layout', 'vertical' );

if ( 'vertical' === $layout ) {
	?>
	<div class="user-registration-MyAccount-navigation--wrapper">
		<?php
		$is_profile_pic_on_form = ! ur_option_checked( 'user_registration_disable_profile_picture', false );
		if ( $is_profile_pic_on_form ) {
			?>
			<div class='user-registration-profile-header-nav'>
				<div class='user-registration-img-container'>
					<?php
					$gravatar_image      = get_avatar_url( get_current_user_id(), $args = null );
					$profile_picture_url = get_user_meta( get_current_user_id(), 'user_registration_profile_pic_url', true );
					$user_id             = ! empty( $values['user_id'] ) ? $values['user_id'] : get_current_user_id();
					if ( is_numeric( $profile_picture_url ) ) {
						$profile_picture_url = wp_get_attachment_url( $profile_picture_url );
					}

					$profile_picture_url = apply_filters( 'user_registration_profile_picture_url', $profile_picture_url, $user_id );
					$image               = ( ! empty( $profile_picture_url ) ) ? $profile_picture_url : $gravatar_image;
					?>
					<img class="profile-preview" alt="profile-picture" src="<?php echo esc_url( $image ); ?>" />
				</div>
				<header>
					<h3>
						<?php
						$user = wp_get_current_user();
						echo esc_html( $user->user_login );
						?>
					</h3>
				</header>
			</div>
			<?php
		}
		?>
	<?php
}
?>

<nav class="user-registration-MyAccount-navigation">
	<ul>
		<?php foreach ( ur_get_account_menu_items() as $endpoint => $label ) : ?>
			<?php
			$actual_endpoint = $endpoint;

			if ( ! ur_is_account_endpoint_listed( $actual_endpoint ) ) {
				continue;
			}
			?>
			<li class="<?php echo esc_attr( ur_get_account_menu_item_classes( $endpoint ) ); ?>">
				<a href="<?php echo esc_url( ur_get_account_endpoint_url( $endpoint ) ); ?>" <?php echo 'user-logout' === $actual_endpoint && ! $logout_confirmation ? esc_attr( 'class=ur-logout' ) : ''; ?> ><?php echo esc_html( $label ); ?></a>
			</li>
		<?php endforeach; ?>

	</ul>
</nav>

<?php

if ( 'vertical' === $layout ) {
	?>
	</div>
	<?php
}

/**
 * Action to fire after the rendering of user registration account navigation.
 */
do_action( 'user_registration_after_account_navigation' ); ?>
