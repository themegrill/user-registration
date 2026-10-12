<?php
/**
 * Admin View: Page - Forms
 *
 * @package UserRegistration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>
<div class="wrap user-registration__wrap ur-form-container">
	<h1 style="display:none"></h1> <!-- To manage notices -->
	<div id="menu-management-liquid" class="ur-form-subcontainer">
		<div class="ur-loading-container">
			<div class="ur-circle-loading"></div>
		</div>
		<div id="menu-management">
			<div class="menu-edit">
				<?php
				// Builder-only notice, since it's specific to this form's fields. Inside .menu-edit (fixed-position) so it doesn't scroll away.
				if ( ! empty( $form_id ) && function_exists( 'ur_form_has_legacy_payment_fields' ) && ur_form_has_legacy_payment_fields( $form_id ) ) {
					?>
					<div class="notice notice-warning inline" style="margin: 0;">
						<p><?php esc_html_e( 'This form uses legacy payment fields. New forms can no longer add them, and current form will keep working as expected.', 'user-registration' ); ?></p>
					</div>
					<?php
				}
				?>
				<input type="hidden" name="ur_form_id" id="ur_form_id" value="<?php echo esc_attr( $form_id ); ?>"/>
				<div id="nav-menu-header">
					<div class="ur-brand-logo ur-px-2">
						<img src="<?php echo esc_url( UR()->plugin_url() . '/assets/images/logo.svg' ); ?>" alt="">
					</div>
					<?php
					if ( ! empty( $form_data ) ) {
						?>
						<span class="ur-form-title"><?php echo isset( $form_data->post_title ) ? esc_html( $form_data->post_title ) : ''; ?></span>
						<span class="ur-editing-tag"><?php esc_html_e( 'Now Editing', 'user-registration' ); ?></span>
						<?php
					}
					?>
					<div class="major-publishing-actions wp-clearfix">
						<div class="publishing-action">
							<?php
							if ( ! empty( $form_data ) ) {
								?>
								<input type="text" onfocus="this.select();" readonly="readonly"
									value='[user_registration_form id=<?php echo '"' . esc_attr( $form_id ) . '"'; ?>]'
									class=" code" size="35">

								<button id="copy-shortcode" class="button button-primary button-large ur-copy-shortcode " href="#" data-tip="<?php esc_attr_e( 'Copy Shortcode!', 'user-registration' ); ?>" data-copied="<?php esc_attr_e( 'Copied!', 'user-registration' ); ?>">
									<!-- <svg xmlns="http://www.w3.org/2000/svg" width="14" height="16" viewBox="0 0 14 16">
										<path fill-rule="evenodd" d="M2 13h4v1H2v-1zm5-6H2v1h5V7zm2 3V8l-3 3 3 3v-2h5v-2H9zM4.5 9H2v1h2.5V9zM2 12h2.5v-1H2v1zm9 1h1v2c-.02.28-.11.52-.3.7-.19.18-.42.28-.7.3H1c-.55 0-1-.45-1-1V4c0-.55.45-1 1-1h3c0-1.11.89-2 2-2 1.11 0 2 .89 2 2h3c.55 0 1 .45 1 1v5h-1V6H1v9h10v-2zM2 5h8c0-.55-.45-1-1-1H8c-.55 0-1-.45-1-1s-.45-1-1-1-1 .45-1 1-.45 1-1 1H3c-.55 0-1 .45-1 1z"/>
									</svg> -->
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
											<path fill="#383838" fill-rule="evenodd" d="M3.116 3.116A1.25 1.25 0 0 1 4 2.75h9A1.25 1.25 0 0 1 14.25 4v1a.75.75 0 0 0 1.5 0V4A2.75 2.75 0 0 0 13 1.25H4A2.75 2.75 0 0 0 1.25 4v9A2.75 2.75 0 0 0 4 15.75h1a.75.75 0 0 0 0-1.5H4A1.25 1.25 0 0 1 2.75 13V4c0-.332.132-.65.366-.884ZM9.75 11c0-.69.56-1.25 1.25-1.25h9c.69 0 1.25.56 1.25 1.25v9c0 .69-.56 1.25-1.25 1.25h-9c-.69 0-1.25-.56-1.25-1.25v-9ZM11 8.25A2.75 2.75 0 0 0 8.25 11v9A2.75 2.75 0 0 0 11 22.75h9A2.75 2.75 0 0 0 22.75 20v-9A2.75 2.75 0 0 0 20 8.25h-9Z" clip-rule="evenodd"/>
									</svg>
								</button>
								<?php
							}
							?>
							<button id="ur-full-screen-mode" class="button button-secondary button-large button-icon closed" title="<?php esc_attr_e( 'Fullscreen', 'user-registration' ); ?>"><span class="ur-fs-open-label dashicons dashicons-editor-expand"></span><span class="ur-fs-close-label dashicons dashicons-editor-contract"></span></button>
							<?php
							if ( isset( $preview_link ) ) {
								?>
								<a href="<?php echo esc_url( ( $preview_link ) ); ?>" rel="noreferrer noopener" target="_blank" class="button button-secondary button-large" title="<?php esc_attr_e( 'Preview Form', 'user-registration' ); ?>"><?php esc_html_e( 'Preview', 'user-registration' ); ?></a>
							<?php } ?>
				<button name="embed_form" data-form_id="<?php echo esc_html( isset( $_GET['edit-registration'] ) ? absint( sanitize_text_field( wp_unslash( $_GET['edit-registration'] ) ) ) : 0 ); ?>" class="button button-large ur-embed-form-button" type="button" value="<?php esc_attr_e( 'Embed', 'user-registration' ); ?>"><?php esc_html_e( 'Embed', 'user-registration' ); ?></button>
							<button type="button" name="save_form" id="save_form_footer" class="button button-primary button-large menu-form ur_save_form_action_button"> <?php echo esc_html( $save_label ); ?> </button>
						</div><!-- END .publishing-action -->
					</div><!-- END .major-publishing-actions -->
				</div><!-- END .nav-menu-header -->
				<div id="post-body">
					<div class="ur-registered-from">
						<div class="ur-registered-inputs">
							<nav class="nav-tab-wrapper ur-tabs">
								<ul class="ur-tab-lists">
									<li><a href="#ur-tab-registered-fields"
										class="nav-tab active"><?php esc_html_e( 'Fields', 'user-registration' ); ?></a>
									</li>
									<li><a href="#ur-tab-field-options" class="nav-tab"><?php esc_html_e( 'Field Options', 'user-registration' ); ?></a>
									</li>

									<?php
									/**
									 * Filter to add form builder tabs.
									 */
									do_action( 'user_registration_form_bulder_tabs' ); // TODO:: Needs refactor. Move after field-settings and sort.
									?>

									<li><a href="#ur-tab-field-settings"
										class="nav-tab"><?php esc_html_e( 'Form Settings', 'user-registration' ); ?></a>
									</li>
								</ul>
								<div style="clear:both"></div>

								<div class="ur-tab-contents" >
									<div id="ur-tab-registered-fields" class="ur-tab-content">
										<div class="ur-sticky-wrapper">
											<div class="ur-search-input ur-search-fields">
												<input id="ur-search-fields" class="ur-type-text" type="text" placeholder="Search Fields..." />
												<svg xmlns="http://www.w3.org/2000/svg" height="24px" width="24px" viewBox="0 0 24 24" fill="#a1a4b9"><path d="M21.71,20.29,18,16.61A9,9,0,1,0,16.61,18l3.68,3.68a1,1,0,0,0,1.42,0A1,1,0,0,0,21.71,20.29ZM11,18a7,7,0,1,1,7-7A7,7,0,0,1,11,18Z"/></svg>
											</div>
										</div>
										<div class="ur-fields-not-found" hidden>
											<img src="<?php echo esc_url( plugin_dir_url( UR_PLUGIN_FILE ) . 'assets/images/not-found.png' ); ?>" />
											<h3 class="ur-fields-not-found-title"><?php esc_html_e( 'Whoops!', 'user-registration' ); ?></h3>
											<span><?php esc_html_e( 'There is not any field that you were searching for.', 'user-registration' ); ?></span>
										</div>
										<h2 class='ur-toggle-heading closed'><?php esc_html_e( 'Default User Fields', 'user-registration' ); ?></h2>
										<hr/>
										<?php $this->get_registered_user_form_fields(); ?>
										<h2 class='ur-toggle-heading closed'><?php esc_html_e( 'Extra Fields', 'user-registration' ); ?></h2>
										<hr/>
										<?php $this->get_registered_other_form_fields(); ?>
										<?php do_action( 'user_registration_extra_fields' ); ?>
									</div>
									<div id="ur-tab-field-options" class="ur-tab-content">
									</div>
									<div id="ur-tab-field-settings" class="ur-tab-content">

										<form method="post" id="ur-field-settings" onsubmit="return false;" style='display:none'>
											<div id="ur-field-all-settings">
												<?php ur_admin_form_settings( $form_id ); ?>
												<?php
												/**
												 * Action to add settings after form.
												 *
												 * @param int $form_id Form if to add the settings.
												 */
												do_action( 'user_registration_after_form_settings', $form_id );
												?>
											</div>
										</form>
									</div>
									<?php
									/**
									 * Action to add form builder content.
									 *
									 * @param int $form_id Form id to add form builder content.
									 */
									do_action( 'user_registration_form_bulder_content', $form_id );
									?>
								</div>
							</nav>
							<button id="ur-collapse" class="close">
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
									<path fill="#6B6B6B" d="M16.5 22a1.003 1.003 0 0 1-.71-.29l-9-9a1 1 0 0 1 0-1.42l9-9a1.004 1.004 0 1 1 1.42 1.42L8.91 12l8.3 8.29A.999.999 0 0 1 16.5 22Z"/>
								</svg>
							</button>
						</div>
						<?php
						/**
						 * Filter to add the builder class.
						 *
						 * @param array.
						 */
						$builder_class = apply_filters( 'user_registration_builder_class', array() );
						$builder_class = implode( ' ', $builder_class );
						?>
						<div class='ur-builder-wrapper <?php echo esc_attr( $builder_class ); ?>'>
							<?php
							if ( ! empty( $form_data ) && isset( $_GET['edit-registration'] ) && is_numeric( $_GET['edit-registration'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
								$this->get_edit_form_field( $form_data );
							} else {
								?>
								<div class="ur-selected-inputs">
									<div class="ur-builder-wrapper-content">
										<div class="user-registration-editable-title ur-form-name-wrapper ur-my-4" >
											<input name="ur-form-name" id="ur-form-name" type="text" class="user-registration-editable-title__input ur-form-name regular-text menu-item-textbox ur-editing" autofocus="autofocus" onfocus="this.select()" value="<?php esc_html_e( 'Untitled', 'user-registration' ); ?>" data-editing="false">
											<span id="ur-form-name-edit-button" class="user-registration-editable-title__icon ur-edit-form-name dashicons dashicons-edit"></span>
										</div>
										<div class="ur-input-grids">

										</div>
									</div>
								</div>
							<?php } ?>
							<div class="ur-builder-wrapper-footer">
								<a href='#' class="ur-button-quick-links">
									<span class="user-registration-help-tip" data-tip="Need Help ?"></span>
									<svg xmlns="http://www.w3.org/2000/svg" fill="#000" viewBox="0 0 24 24">
										<path d="M20.182 12a8.182 8.182 0 1 0-16.364 0 8.182 8.182 0 0 0 16.364 0ZM22 12c0 5.523-4.477 10-10 10S2 17.523 2 12 6.477 2 12 2s10 4.477 10 10Z"/>
										<path d="M10.085 7.043a3.636 3.636 0 0 1 5.479 3.14l-.012.254c-.116 1.25-1.066 2.087-1.757 2.547a7.302 7.302 0 0 1-1.533.77c-.013.005-.023.01-.031.012l-.01.004h-.003l-.002.002a.91.91 0 0 1-.578-1.725h.002l.013-.005.067-.025a5.504 5.504 0 0 0 1.066-.546c.627-.418.96-.862.96-1.289v-.002a1.818 1.818 0 0 0-3.534-.606.91.91 0 0 1-1.715-.603 3.637 3.637 0 0 1 1.588-1.928Zm1.924 8.593a.91.91 0 1 1 0 1.819H12a.91.91 0 1 1 0-1.819h.009Z"/>
									</svg>
								</a>

								<div class="ur-quick-links-content" hidden>
									<div class="ur-quick-links-content__header">
										<div class="ur-quick-links-content__header-text">
											<h4 class="ur-quick-links-content__title"><?php esc_html_e( 'How can we help?', 'user-registration' ); ?></h4>
											<p class="ur-quick-links-content__subtitle"><?php esc_html_e( 'Choose an option below', 'user-registration' ); ?></p>
										</div>
										<button class="ur-quick-links-content__close-btn">
											<svg xmlns="http://www.w3.org/2000/svg" fill="#000" viewBox="0 0 24 24">
												<path d="M19.561 2.418a1.428 1.428 0 1 1 2.02 2.02L4.44 21.583a1.428 1.428 0 1 1-2.02-2.02L19.56 2.418Z"/>
												<path d="M2.418 2.418a1.428 1.428 0 0 1 2.02 0l17.144 17.143a1.428 1.428 0 1 1-2.02 2.02L2.418 4.44a1.428 1.428 0 0 1 0-2.02Z"/>
											</svg>
										</button>
									</div>
									<div class="ur-quick-links-content__body">
										<!-- <div class="ur-quick-links-content__item">
											<div class="ur-quick-links-content__item-icon">
												<svg xmlns="http://www.w3.org/2000/svg" fill="#000" viewBox="0 0 24 24">
													<path d="M14 7a1 1 0 1 1 0 2h-4a1 1 0 0 1 0-2h4Zm-3 14v-9a1 1 0 1 1 2 0v9a1 1 0 1 1-2 0Z"/>
													<path d="M11 8V3a1 1 0 1 1 2 0v5a1 1 0 1 1-2 0Zm10 7a1 1 0 1 1 0 2h-4a1 1 0 1 1 0-2h4Zm-3-3V3a1 1 0 1 1 2 0v9a1 1 0 1 1-2 0Z"/>
													<path d="M18 21v-5a1 1 0 1 1 2 0v5a1 1 0 1 1-2 0ZM7 13a1 1 0 1 1 0 2H3a1 1 0 1 1 0-2h4Zm-3-3V3a1 1 0 0 1 2 0v7a1 1 0 1 1-2 0Z"/>
													<path d="M4 21v-7a1 1 0 1 1 2 0v7a1 1 0 1 1-2 0Z"/>
												</svg>
											</div>
											<div class="ur-quick-links-content__item-content">
												<div class="ur-quick-links-content__item-main">
													<h4 class="ur-quick-links-content__item-title"><?php esc_html_e( 'Keyboard Shortcuts', 'user-registration' ); ?></h4>
													<p class="ur-quick-links-content__item-desc"><?php esc_html_e( 'Quick navigation keys', 'user-registration' ); ?></p>
												</div>
												<span class="ur-quick-links-content__item-badge" id="ur-keyboard-shortcut-link">?</span>
											</div>
										</div> -->
										<a href="<?php echo esc_url_raw( 'https://docs.wpuserregistration.com' ); ?>" rel="noreferrer noopener" target='_blank' class="ur-quick-links-content__item" role="link">
											<div class="ur-quick-links-content__item-icon">
												<svg xmlns="http://www.w3.org/2000/svg" fill="#000" viewBox="0 0 24 24">
													<path d="M7.111 3.818c-.354 0-.693.144-.943.4a1.38 1.38 0 0 0-.39.964v10.761c.413-.2.867-.307 1.333-.307h11.111V3.818H7.112Zm-1.333 15c0 .362.14.709.39.964.25.256.59.4.943.4h11.111v-2.727H7.112c-.355 0-.694.143-.944.399a1.38 1.38 0 0 0-.39.964ZM20 20.182c0 .482-.187.944-.52 1.285a1.758 1.758 0 0 1-1.258.533H7.112a3.076 3.076 0 0 1-2.2-.932A3.218 3.218 0 0 1 4 18.818V5.182c0-.844.328-1.653.911-2.25A3.076 3.076 0 0 1 7.111 2h11.111c.472 0 .924.192 1.257.533.334.34.521.803.521 1.285v16.364Z"/>
													<path d="M15.556 10.182a.9.9 0 0 1 .888.909.9.9 0 0 1-.888.909H8.444a.9.9 0 0 1-.888-.91.9.9 0 0 1 .888-.908h7.112Zm-1.778-3.637a.9.9 0 0 1 .889.91.9.9 0 0 1-.89.909H8.445a.9.9 0 0 1-.888-.91.9.9 0 0 1 .888-.909h5.334Z"/>
												</svg>
											</div>
											<div class="ur-quick-links-content__item-content">
												<div class="ur-quick-links-content__item-main">
													<h4 class="ur-quick-links-content__item-title"><?php esc_html_e( 'Documentation', 'user-registration' ); ?></h4>
													<p class="ur-quick-links-content__item-desc"><?php esc_html_e( 'Full documentation', 'user-registration' ); ?></p>
												</div>
											</div>
										</a>
										<a href="<?php echo esc_url_raw( 'https://wpuserregistration.com/support' ); ?>" rel="noreferrer noopener" target='_blank' class="ur-quick-links-content__item" role="link">
											<div class="ur-quick-links-content__item-icon">
												<svg xmlns="http://www.w3.org/2000/svg" fill="#000" viewBox="0 0 24 24">
													<path d="M6.187 3.863a10 10 0 1 1 1.631 17.22.908.908 0 0 0-.462-.042l-3.05.892-.021.006a1.82 1.82 0 0 1-2.248-2.123l.026-.098.948-2.929a.907.907 0 0 0-.043-.499A10 10 0 0 1 6.187 3.863Zm6.463-.02a8.182 8.182 0 0 0-8.17 11.38l.15.329.025.06c.2.506.246 1.06.129 1.591a.916.916 0 0 1-.023.084l-.937 2.892L6.9 19.28a2.727 2.727 0 0 1 1.396.044l.181.062.061.026a8.182 8.182 0 1 0 4.113-15.57Z"/>
													<path d="M8.372 11.09a.91.91 0 1 1 0 1.819h-.009a.91.91 0 1 1 0-1.819h.01Zm3.636 0a.91.91 0 1 1 0 1.819H12a.91.91 0 1 1 0-1.819h.008Zm3.637 0a.91.91 0 1 1 0 1.819h-.01a.91.91 0 0 1 0-1.819h.01Z"/>
												</svg>
											</div>
											<div class="ur-quick-links-content__item-content">
												<div class="ur-quick-links-content__item-main">
													<h4 class="ur-quick-links-content__item-title"><?php esc_html_e( 'Get Support', 'user-registration' ); ?></h4>
													<p class="ur-quick-links-content__item-desc"><?php esc_html_e( 'Chat with our team', 'user-registration' ); ?></p>
												</div>
											</div>
										</a>
										<a href="<?php echo esc_url_raw( 'https://youtube.com/playlist?list=PLcrB6drBDePkshUw7r5BNVLRwpr8RaXyy&si=102v1m7B-6bQo0Hq' ); ?>" rel="noreferrer noopener" target='_blank' class="ur-quick-links-content__item" role="link">
											<div class="ur-quick-links-content__item-icon">
												<svg xmlns="http://www.w3.org/2000/svg" fill="#000" viewBox="0 0 24 24">
													<path d="M4.504 4.632c4.653-.79 9.396-.84 14.06-.148l.932.148.092.02c.455.129.87.376 1.205.718a2.808 2.808 0 0 1 .712 1.289 23.926 23.926 0 0 1-.012 9.736 2.808 2.808 0 0 1-.7 1.235c-.334.342-.75.589-1.205.717a.882.882 0 0 1-.092.02 44.757 44.757 0 0 1-14.992 0 .884.884 0 0 1-.092-.02 2.707 2.707 0 0 1-1.205-.717 2.806 2.806 0 0 1-.712-1.289 23.92 23.92 0 0 1 0-9.682l.012-.054c.125-.467.366-.893.7-1.235.334-.342.75-.59 1.205-.717a.9.9 0 0 1 .092-.021ZM19.14 6.457a42.993 42.993 0 0 0-14.28 0 .902.902 0 0 0-.37.228.937.937 0 0 0-.229.397 22.022 22.022 0 0 0 0 8.835.914.914 0 0 0 .597.625c4.73.796 9.553.796 14.282 0a.902.902 0 0 0 .598-.625 22.02 22.02 0 0 0 0-8.835.903.903 0 0 0-.598-.625Z"/>
													<path d="M9.738 7.902a.89.89 0 0 1 .915.011l4.535 2.79c.273.168.44.47.44.797a.935.935 0 0 1-.44.797l-4.535 2.79a.89.89 0 0 1-.915.011.934.934 0 0 1-.46-.809V8.711c0-.335.176-.644.46-.81Zm1.355 4.745 1.865-1.147-1.865-1.148v2.295Z"/>
												</svg>
											</div>
											<div class="ur-quick-links-content__item-content">
												<div class="ur-quick-links-content__item-main">
													<h4 class="ur-quick-links-content__item-title"><?php esc_html_e( 'Video Tutorials', 'user-registration' ); ?></h4>
													<p class="ur-quick-links-content__item-desc"><?php esc_html_e( 'Step-by-step guide', 'user-registration' ); ?></p>
												</div>
											</div>
										</a>
										<!-- <a href="https://docs.wpuserregistration.com/docs/how-to-show-login-form/" rel="noreferrer noopener" target='_blank' class="ur-quick-links-content__item" role="link">
											<div class="ur-quick-links-content__item-icon">
												<svg xmlns="http://www.w3.org/2000/svg" fill="#000" viewBox="0 0 24 24">
													<path d="M10.2 15.636a1.81 1.81 0 0 1 1.8-1.818c.994 0 1.8.814 1.8 1.818a1.81 1.81 0 0 1-1.8 1.819 1.81 1.81 0 0 1-1.8-1.819Z"/>
													<path d="M19.2 12a.905.905 0 0 0-.9-.91H5.7c-.497 0-.9.408-.9.91v7.273c0 .502.403.909.9.909h12.6c.497 0 .9-.407.9-.91V12Zm1.8 7.273C21 20.779 19.791 22 18.3 22H5.7C4.209 22 3 20.779 3 19.273V12c0-1.506 1.209-2.727 2.7-2.727h12.6c1.491 0 2.7 1.22 2.7 2.727v7.273Z"/>
													<path d="M15.6 10.182V7.455c0-.965-.38-1.89-1.055-2.571A3.581 3.581 0 0 0 12 3.818a3.58 3.58 0 0 0-2.545 1.066A3.655 3.655 0 0 0 8.4 7.454v2.728a.905.905 0 0 1-.9.909.905.905 0 0 1-.9-.91V7.456c0-1.447.57-2.834 1.582-3.857A5.372 5.372 0 0 1 12 2c1.432 0 2.805.575 3.818 1.598A5.482 5.482 0 0 1 17.4 7.455v2.727a.905.905 0 0 1-.9.909.905.905 0 0 1-.9-.91Z"/>
												</svg>
											</div>
											<div class="ur-quick-links-content__item-content">
												<div class="ur-quick-links-content__item-main">
													<h4 class="ur-quick-links-content__item-title"><?php esc_html_e( 'Create Login Form', 'user-registration' ); ?></h4>
													<p class="ur-quick-links-content__item-desc"><?php esc_html_e( 'Step-by-step guide', 'user-registration' ); ?></p>
												</div>
											</div>
										</a> -->

									</div>
									<!-- <div class="ur-quick-links-content__footer">
										<div class="ur-quick-links-footer-text">
											<span><?php esc_html_e( 'Press', 'user-registration' ); ?></span>
											<span class="ur-quick-links-content__item-badge">?</span>
											<span><?php esc_html_e( 'for shortcuts', 'user-registration' ); ?></span>
										</div>
									</div> -->
								</div>
								<?php
								/**
								 * Filter to add form builder wrapper for footer.
								 */
								do_action( 'user_registration_form_builder_wrapper_footer' );
								?>
							</div>
						</div>
					</div>
				</div><!-- /#post-body -->
			</div><!-- /.menu-edit -->
		</div><!-- /#menu-management -->
	</div>
</div>
