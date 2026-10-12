<?php
/**
 * Lost password form
 *
 * This template can be overridden by copying it to yourtheme/user-registration/myaccount/form-lost-password.php.
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

$form_template  = get_option( 'user_registration_login_options_form_template', 'default' );
$template_class = '';

if ( 'bordered' === $form_template ) {
	$template_class = 'ur-frontend-form--bordered';

} elseif ( 'flat' === $form_template ) {
	$template_class = 'ur-frontend-form--flat';

} elseif ( 'rounded' === $form_template ) {
	$template_class = 'ur-frontend-form--rounded';

} elseif ( 'rounded_edge' === $form_template ) {
	$template_class = 'ur-frontend-form--rounded ur-frontend-form--rounded-edge';
}

?>


<div class="user-registration-message-container">
	<?php
	ur_print_notices();
	?>
</div>

<div class="ur-frontend-form login <?php echo esc_attr( $template_class ); ?>" id="ur-frontend-form">
	<form method="post" class="user-registration-ResetPassword ur_lost_reset_password">
		<div class="ur-form-row">
			<div class="ur-form-grid">
				<div class="ur-lost-password-content-container">

				<p class="ur-lost-password-title">
				<?php
				echo esc_html(
					/**
					 * Filter to modify the user registration lost password title.
					 *
					 * @param string message content to override the lost password message.
					 * @return string message content for lost password.
					 */
					apply_filters( 'user_registration_lost_password_title', esc_html__( 'Lost your password? ', 'user-registration' ) )
				);
				?>
				</p>
				<p class="ur-lost-password-message">
				<?php

				echo esc_html(
					/**
					 * Filter to modify the user registration lost password message.
					 *
					 * @param string message content to override the lost password message.
					 * @return string message content for lost password.
					 */
					apply_filters( 'user_registration_lost_password_message', esc_html__( 'No worries, we’ll send you reset instructions via email.', 'user-registration' ) )
				);
				?>
					</p>
				</div>

				<p class="user-registration-form-row user-registration-form-row--first form-row form-row-first">
					<label for="user_login">
						<?php

							echo esc_html(
								/**
								 * Filter to modify the user registration lost password email label.
								 *
								 * @param string Email username label.
								 * @return string Email username label.
								 *
								 * @since 4.2.1
								 */
								apply_filters( 'user_registration_forgot_password_email_label', esc_html__( 'Username or Email', 'user-registration' ) )
							);
							?>
						<abbr class="required" title="required">*</abbr></label>

					<div class="ur-input-with-icon">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="15" viewBox="0 0 14 15" fill="none" class="input-icon">
					<path d="M2.33561 2.83301H11.6689C12.3106 2.83301 12.8356 3.35801 12.8356 3.99967V10.9997C12.8356 11.6413 12.3106 12.1663 11.6689 12.1663H2.33561C1.69395 12.1663 1.16895 11.6413 1.16895 10.9997V3.99967C1.16895 3.35801 1.69395 2.83301 2.33561 2.83301Z" stroke="#858585" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M12.8356 4L7.00228 8.08333L1.16895 4" stroke="#858585" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<input class="form-control user-registration-Input user-registration-Input--text" type="text" name="user_login" id="user_login" autocomplete="username" placeholder="<?php echo esc_html( apply_filters( 'user_registration_lost_password_user_login_placeholder', __( 'Enter your email', 'user-registration' ) ) ); ?>" required />
				</div>

				</p>

				<div class="clear"></div>

				<?php
				if ( ! empty( $recaptcha_node ) ) {
					echo '<div id="ur-recaptcha-node"> ' . $recaptcha_node . '</div>';  //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>

				<?php
				/**
				 * Fires the rendering of user registration lost password form.
				 */
				do_action( 'user_registration_lostpassword_form' );

				/**
				 * Filter to modify the lost password button text.
				 *
				 * @param string text for lost password button.
				 * @return string text for lost password button.
				 */
				$reset_button = apply_filters( 'user_registration_lost_password_button_text', __( 'Reset password', 'user-registration' ) );
				?>

				<p class="user-registration-form-row form-row">
					<input type="hidden" name="ur_reset_password" value="true" />
					<input type="submit" class="user-registration-Button button ur-reset-password-btn" value="<?php echo esc_attr( $reset_button ); ?> "/>
				</p>

				<?php wp_nonce_field( 'lost_password' ); ?>
			</div>
		</div>
	</form>
</div>
