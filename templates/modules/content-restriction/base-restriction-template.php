<?php
defined( 'ABSPATH' ) || exit;

// Set default values if not provided
$message    = isset( $message ) ? $message : '';
$login_url  = isset( $login_url ) ? $login_url : '';
$signup_url = isset( $signup_url ) ? $signup_url : '';

// Get URLs if not provided
if ( empty( $login_url ) ) {
	$login_page_id = get_option( 'user_registration_login_page_id' );
	$login_url     = $login_page_id ? get_permalink( $login_page_id ) : wp_login_url();
}

if ( empty( $signup_url ) ) {
	$registration_page_id = get_option( 'user_registration_member_registration_page_id' );
	$signup_url           = $registration_page_id ? get_permalink( $registration_page_id ) : ( isset( $login_page_id ) && $login_page_id ? get_permalink( $login_page_id ) : wp_registration_url() );

	if ( ! $registration_page_id ) {
		$default_form_page_id = get_option( 'user_registration_default_form_page_id' );
		if ( $default_form_page_id ) {
			$signup_url = get_permalink( $default_form_page_id );
		}
	}
}

?>
<style>
	/* Hide page title for whole site restrictions */
	body.urcr-hide-page-title .wp-block-post-title,
	body.urcr-hide-page-title .entry-header,
	body.urcr-hide-page-title .page-header,
	body.urcr-hide-page-title .entry-title,
	body.urcr-hide-page-title .page-title,
	body.urcr-hide-page-title h1.entry-title,
	body.urcr-hide-page-title h1.page-title,
	body.urcr-hide-page-title .post-title,
	body.urcr-hide-page-title .single-post-title,
	body.urcr-hide-page-title .single-page-title,
	body.urcr-hide-page-title article header.entry-header,
	body.urcr-hide-page-title article .entry-title {
		display: none !important;
	}
	.urcr-access-card {
		display: flex;
		flex-direction: column;
		background-color: #ffffff;
		border: 1px solid #f1f5f9;
		border-radius: 7px;
		padding: 32px;
		max-width: 800px !important;
		width: 100%;
		box-shadow: 0 6px 26px 0 rgba(10, 10, 10, 0.06);
		margin: 24px auto !important;
		box-sizing: border-box;
	}
	.urcr-access-card h3 {
		font-weight: 700;
		font-size: 28px;
		line-height: 36px;
		color: #1a1a1a;
		margin: 0 0 20px;
	}
	.urcr-access-card p {
		font-weight: 400;
		font-size: 16px;
		line-height: 24px;
		color: #6B6B6B;
		margin: 0 0 12px;
	}
	.urcr-access-card p:last-child {
		margin: 12px 0 0;
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 8px 20px;
	}
	.urcr-access-card p > a {
		font-size: 16px;
		line-height: 24px;
		padding: 14px 32px;
		font-weight: 500;
		border-radius: 4px;
		text-decoration: none;
		margin: 0;
		cursor: pointer;
		background: transparent;
		color: #4e4e4e;
		transition: all .3s ease;
	}
	.urcr-access-card p > a.urcr-signup-link {
		background: var(--ur-button-background-normal-color, #475bb2);
		color: var(--ur-button-text-normal-color, #ffffff);
	}
	.urcr-access-card p > a.urcr-signup-link:hover {
		background: var(--ur-button-background-hover-color, #38488e);
		color: var(--ur-button-text-hover-color, #ffffff);
	}
	.urcr-access-card p > a.urcr-access-button {
		background: transparent;
		text-decoration: underline;
		padding: 14px 16px;
	}
	.urcr-access-card p > a.urcr-access-button:hover {
		color: #475bb2;
	}
	.urcr-access-heading {
		font-size: 28px;
		font-weight: 700;
		color: #1a1a1a;
		margin: 0 0 16px 0;
		line-height: 1.2;
	}
	.urcr-access-description {
		font-size: 16px;
		color: #6B6B6B;
		margin: 0 0 40px 0;
		line-height: 1.5;
	}
	.urcr-access-description br {
		display: none;
	}
	.urcr-actions {
		display: block;
		text-align: center;
	}
	.urcr-actions br {
		display: none;
	}
	.urcr-actions a {
		text-decoration: none;
		margin: 0;
		display: block;
		text-align: left;
	}
	@media (max-width: 480px) {
		.urcr-access-card {
			padding: 24px;
		}
		.urcr-access-card h3 {
			font-size: 22px;
			line-height: 30px;
		}
		.urcr-access-card p {
			font-size: 15px;
			line-height: 25px;
		}
		.urcr-access-heading {
			font-size: 24px;
		}
	}
</style>
<div class="urcr-access-card">
	<?php echo wp_kses_post( $message ); ?>
</div>
