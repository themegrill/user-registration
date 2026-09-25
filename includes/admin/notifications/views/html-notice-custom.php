<?php
/**
 * Admin View: Custom Notices
 *
 * @package UserRegistration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>
<div id="message" class="updated user-registration-message">
	<?php
	if ( 'select_my_account' !== $notice ) {
		?>
		<a class="user-registration-message-close notice-dismiss" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ur-hide-notice', $notice ), 'user_registration_hide_notices_nonce', '_ur_notice_nonce' ) ); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'user-registration' ); ?></span></a>
		<?php
	}
	?>
	<?php echo wp_kses_post( wpautop( $notice_html ) ); ?>
</div>
