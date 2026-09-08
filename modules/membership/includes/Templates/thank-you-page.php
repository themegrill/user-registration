<?php

$bank_data         = ( isset( $_GET['info'] ) && ! empty( $_GET['info'] ) ) ? wp_kses_post( $_GET['info'] ) : '';
$show_bank_data    = ( 'Free' === $bank_data || empty( $bank_data ) ) ? false : true;
$transaction_id    = ( isset( $_GET['transaction_id'] ) && ! empty( $_GET['transaction_id'] ) ) ? wp_kses_post( $_GET['transaction_id'] ) : '';
$username          = ( isset( $_GET['username'] ) && ! empty( $_GET['username'] ) ) ? wp_kses_post( $_GET['username'] ) : '';
$main_content      = ! empty( $attributes['header'] ) ? wp_kses_post( $attributes['header'] ) : sprintf(
	__( 'Thank You! Your registration was completed successfully.', 'user-registration' ),
	esc_html( $username )
);
$footer            = ! empty( $attributes['footer'] ) ? wp_kses_post( $attributes['footer'] ) : '';
$notice_message    = ! empty( $attributes['notice_message'] ) ? esc_html( $attributes['notice_message'] ) : __( 'For paid memberships there might be a delay of few minutes for your subscription status to be updated by the payment gateways.', 'user-registration' );
$transaction_info  = ! empty( $attributes['transaction_info'] ) ? esc_html( $attributes['transaction_info'] ) : __( 'Please use this transaction/order id for support regarding payments if needed.', 'user-registration' );
$is_preview        = ! empty( $attributes['is_preview'] ) ? $attributes['is_preview'] : false;
$show_notice_1     = isset( $attributes['show_notice_1'] ) ? $attributes['show_notice_1'] : true;
$show_notice_2     = isset( $attributes['show_notice_2'] ) ? $attributes['show_notice_2'] : true;
$show_heading_icon = isset( $attributes['show_heading_icon'] ) ? $attributes['show_heading_icon'] : true;
$show_headline     = isset( $attributes['show_headline'] ) ? $attributes['show_headline'] : true;
$headline_text     = ! empty( $attributes['headline_text'] ) ? esc_html( $attributes['headline_text'] ) : __( 'Thank You For Your Purchase!', 'user-registration' );
$show_redirect_btn = isset( $attributes['show_redirect_btn'] ) ? $attributes['show_redirect_btn'] : true;
$show_bank_details = isset( $attributes['show_bank_details'] ) ? $attributes['show_bank_details'] : true;
$redirect_btn_text = ! empty( $attributes['redirect_btn_text'] ) ? esc_html( $attributes['redirect_btn_text'] ) : __( 'Go to My Account', 'user-registration' );
$redirect_page_id  = ! empty( $attributes['redirect_page_id'] )
	? absint( $attributes['redirect_page_id'] )
	: ur_get_page_id( 'myaccount' );
$redirect_btn_url  = ! empty( $attributes['redirect_page_id'] )
	? get_permalink( absint( $attributes['redirect_page_id'] ) )
	: ur_get_page_permalink( 'myaccount' );

?>
<!-- Thank You Page Section -->
<div id="order-complete-section" class="ur-thank-you-page">
	<div class="thank-you-page-container">
		<div class="ur-thank-you-headline-wrapper">
			<?php if ( $show_heading_icon ) : ?>
				<div class="ur-success-icon">
					<svg width="50px" height="50px" viewBox="-102.4 -102.4 1228.80 1228.80" xmlns="http://www.w3.org/2000/svg" fill="#000000" stroke="#000000" transform="matrix(1, 0, 0, 1, 0, 0)" stroke-width="0.01024"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round" stroke="#CCCCCC" stroke-width="2.048"></g><g id="SVGRepo_iconCarrier"><path fill="#00a32a" d="M512 64a448 448 0 1 1 0 896 448 448 0 0 1 0-896zm-55.808 536.384-99.52-99.584a38.4 38.4 0 1 0-54.336 54.336l126.72 126.72a38.272 38.272 0 0 0 54.336 0l262.4-262.464a38.4 38.4 0 1 0-54.272-54.336L456.192 600.384z"></path></g></svg>
				</div>
			<?php endif; ?>

			<?php if ( $show_headline ) : ?>
				<div class="ur-headline">
					<h1><?php echo $headline_text; ?></h1>
				</div>
			<?php endif; ?>
		</div>

		<div class="ur-message">
			<p>
			<?php
				$values = array();

				// Smart tags resolve only for the logged-in visitor's own account; a requested
				// username is never trusted, so one member cannot read another's details here.
			if ( is_user_logged_in() ) {
				$user = wp_get_current_user();

				if ( $user && $user->exists() ) {
					$values['member_id'] = $user->ID;
					$values['email']     = $user->user_email;
					$values['context']   = 'thank_you_page';
					if ( ! empty( $transaction_id ) ) {
						$values['transaction_id'] = $transaction_id;
					}

					$main_content = apply_filters( 'user_registration_process_smart_tags', $main_content, $values );
				}
			}
				echo wp_kses_post( $main_content );
			?>
			</p>

			<?php if ( $show_bank_data && $show_bank_details ) : ?>
				<div class="ur-bank-details">
					<p class="ur-bank-details-title" ><?php echo __( 'Bank Details :', 'user-registration' ); ?></p>
					<?php echo wp_kses_post( $bank_data ); ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $show_redirect_btn ) : ?>
			<div class="ur-button-wrapper">
				<a href="<?php echo $redirect_btn_url; ?>" class="ur-redirect-btn">
					<?php echo $redirect_btn_text; ?>
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none">
						<path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</a>
			</div>
		<?php endif; ?>

		<div class="ur-footer">
			<p><?php echo $footer; ?></p>
		</div>

	</div>
</div>
