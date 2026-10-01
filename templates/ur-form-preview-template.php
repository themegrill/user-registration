<?php
defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
			<html <?php language_attributes(); ?>>
				<head>
					<?php wp_head(); ?>
					<meta name="viewport" content="width=device-width"/>
					<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
					<title>
						<?php esc_html_e( 'User Registration & Membership - Setup Wizard', 'user-registration' ); ?>
					</title>
					<?php
						wp_print_head_scripts();
						$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					?>
				</head>
				<body class="ur-multi-device-form-preview">
			<div id="nav-menu-header">
			<div class="ur-brand-logo ur-px-2">

			<img src="<?php echo esc_url( UR()->plugin_url() . '/assets/images/logo.svg' ); ?>" alt="Logo">
		</div>
		<span class="ur-form-title"><?php esc_html_e( 'Form Preview', 'user-registration' ); ?></span>

		<div class="ur-form-preview-devices">
		<svg class="ur-form-preview-device active" data-device="desktop" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
		<path fill-rule="evenodd" clip-rule="evenodd" d="M10.7574 14.6212H16.0604C17.3156 14.6212 18.3332 13.6037 18.3332 12.3485V4.77273C18.3332 3.51753 17.3156 2.5 16.0604 2.5H3.93923C2.68404 2.5 1.6665 3.51753 1.6665 4.77273V12.3485C1.6665 13.6037 2.68404 14.6212 3.93923 14.6212H9.24226V16.1364H6.96953C6.55114 16.1364 6.21196 16.4755 6.21196 16.8939C6.21196 17.3123 6.55114 17.6515 6.96953 17.6515H13.0301C13.4485 17.6515 13.7877 17.3123 13.7877 16.8939C13.7877 16.4755 13.4485 16.1364 13.0301 16.1364H10.7574V14.6212ZM3.93923 4.01515C3.52083 4.01515 3.18166 4.35433 3.18166 4.77273V12.3485C3.18166 12.7669 3.52083 13.1061 3.93923 13.1061H16.0604C16.4788 13.1061 16.818 12.7669 16.818 12.3485V4.77273C16.818 4.35433 16.4788 4.01515 16.0604 4.01515H3.93923Z" fill="#475BB2"/>
		</svg>
		<svg class="ur-form-preview-device" data-device="tablet" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
		<path d="M10.1517 13.7877C9.73328 13.7877 9.3941 14.1269 9.3941 14.5453C9.3941 14.9637 9.73328 15.3029 10.1517 15.3029H10.1593C10.5777 15.3029 10.9168 14.9637 10.9168 14.5453C10.9168 14.1269 10.5777 13.7877 10.1593 13.7877H10.1517Z" fill="#383838"/>
		<path fill-rule="evenodd" clip-rule="evenodd" d="M5.60622 1.6665C4.35103 1.6665 3.3335 2.68404 3.3335 3.93923V16.0604C3.3335 17.3156 4.35103 18.3332 5.60622 18.3332H14.6971C15.9523 18.3332 16.9699 17.3156 16.9699 16.0604V3.93923C16.9699 2.68404 15.9523 1.6665 14.6971 1.6665H5.60622ZM4.84865 3.93923C4.84865 3.52083 5.18783 3.18166 5.60622 3.18166H14.6971C15.1155 3.18166 15.4547 3.52083 15.4547 3.93923V16.0604C15.4547 16.4788 15.1155 16.818 14.6971 16.818H5.60622C5.18783 16.818 4.84865 16.4788 4.84865 16.0604V3.93923Z" fill="#383838"/>
		</svg>
		<svg class="ur-form-preview-device" data-device="mobile" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
		<path d="M10.2271 13.7877C9.80871 13.7877 9.46953 14.1269 9.46953 14.5453C9.46953 14.9637 9.80871 15.3029 10.2271 15.3029H10.2347C10.6531 15.3029 10.9923 14.9637 10.9923 14.5453C10.9923 14.1269 10.6531 13.7877 10.2347 13.7877H10.2271Z" fill="#383838"/>
		<path fill-rule="evenodd" clip-rule="evenodd" d="M6.43923 1.6665C5.18404 1.6665 4.1665 2.68404 4.1665 3.93923V16.0604C4.1665 17.3156 5.18404 18.3332 6.43923 18.3332H14.015C15.2702 18.3332 16.2877 17.3156 16.2877 16.0604V3.93923C16.2877 2.68404 15.2702 1.6665 14.015 1.6665H6.43923ZM5.68166 3.93923C5.68166 3.52083 6.02083 3.18166 6.43923 3.18166H14.015C14.4334 3.18166 14.7726 3.52083 14.7726 3.93923V16.0604C14.7726 16.4788 14.4334 16.818 14.015 16.818H6.43923C6.02083 16.818 5.68166 16.4788 5.68166 16.0604V3.93923Z" fill="#383838"/>
		</svg>

		</div>

		<div class="major-publishing-actions wp-clearfix">
			<div class="publishing-action">
				<input type="text" onfocus="this.select();" readonly="readonly"
						value='[user_registration_form id="<?php echo esc_attr( $form_id ); ?>"]'
						class="code" size="35">
						<button id="copy-shortcode" type="button" class="button button-primary button-large ur-copy-shortcode"
	data-tip="<?php esc_attr_e( 'Copy Shortcode!', 'user-registration' ); ?>"
	data-copied="<?php esc_attr_e( 'Copied!', 'user-registration' ); ?>">
	<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true" role="img">
		<path fill="#383838" fill-rule="evenodd"
			d="M3.116 3.116A1.25 1.25 0 0 1 4 2.75h9A1.25 1.25 0 0 1 14.25 4v1a.75.75 0 0 0 1.5 0V4A2.75 2.75 0 0 0 13 1.25H4A2.75 2.75 0 0 0 1.25 4v9A2.75 2.75 0 0 0 4 15.75h1a.75.75 0 0 0 0-1.5H4A1.25 1.25 0 0 1 2.75 13V4c0-.332.132-.65.366-.884ZM9.75 11c0-.69.56-1.25 1.25-1.25h9c.69 0 1.25.56 1.25 1.25v9c0 .69-.56 1.25-1.25 1.25h-9c-.69 0-1.25-.56-1.25-1.25v-9ZM11 8.25A2.75 2.75 0 0 0 8.25 11v9A2.75 2.75 0 0 0 11 22.75h9A2.75 2.75 0 0 0 22.75 20v-9A2.75 2.75 0 0 0 20 8.25h-9Z"
			clip-rule="evenodd" />
	</svg>
</button>

			</div>
		</div>
	</div>
	<svg class="ur-form-preview-sidepanel-toggler" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 12">
					<path fill-rule="evenodd" d="M.91.41a.833.833 0 0 1 1.18 0l5 5a.833.833 0 0 1 0 1.18l-5 5a.833.833 0 1 1-1.18-1.18L5.323 6 .91 1.59a.833.833 0 0 1 0-1.18Z" clip-rule="evenodd"/>
			</svg>


			<div class="ur-form-preview-main-content ur-form-preview-overlay">

				<div class="ur-form-preview-form">
					<?php
					echo $form_content; // phpcs:ignore
					?>
				</div>
				<aside class="ur-form-side-panel">
				<?php
					echo $side_panel_content; // phpcs:ignore
				?>
				</aside>


			</div>
</body>

<?php
wp_footer();
if ( function_exists( 'wp_print_media_templates' ) ) {
	wp_print_media_templates();
}
wp_print_footer_scripts();
wp_print_scripts( 'ur-form-preview-admin-script' );
wp_print_scripts( 'ur-form-preview-tooltipster' );
wp_print_scripts( 'ur-form-preview-copy' );
?>
</html>
<?php
