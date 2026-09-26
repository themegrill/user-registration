<?php
/**
 * Admin View: Settings
 *
 * @package UserRegistration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$collapse_by_default = isset( $_GET['tab'] ) && ( strpos( $_GET['tab'], 'user-registration-customize-my-account' ) !== false || strpos( $_GET['tab'], 'user-registration-invite-codes' ) !== false ); //phpcs:ignore
/**
 * Filter to add form method tabs.
 *
 * @param mixed $current_tab Currently seclected method tab.
 */
$user_registration_settings_form_method_tab = apply_filters( 'user_registration_settings_form_method_tab_' . $current_tab, 'post' );

?>

<hr class="wp-header-end">
<?php echo user_registration_plugin_main_header(); ?>
<div class="wrap user-registration">
	<form method="<?php echo esc_attr( $user_registration_settings_form_method_tab ); ?>" id="mainform" action="" enctype="multipart/form-data">
		<h1 class="screen-reader-text"><?php echo isset( $tabs[ $current_tab ] ) ? esc_html( $tabs[ $current_tab ] ) : ''; ?></h1>
		<div class="user-registration-settings" >
			<div class="user-registration-settings-wrapper">
				<header class="user-registration-header">
					<div class="user-registration-header__close user-registration-header__close--hidden">
						<svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" fill="#000" viewBox="0 0 24 24">
							<path d="M19.561 2.418a1.428 1.428 0 1 1 2.02 2.02L4.44 21.583a1.428 1.428 0 1 1-2.02-2.02L19.56 2.418Z"></path>
							<path d="M2.418 2.418a1.428 1.428 0 0 1 2.02 0l17.144 17.143a1.428 1.428 0 1 1-2.02 2.02L2.418 4.44a1.428 1.428 0 0 1 0-2.02Z"></path>
						</svg>
					</div>
					<div class="user-registration-header--top">
						<div class="ur-search-input ur-search--top-settings">
							<input id="ur-search-settings" class="ur-type-text" type="text" placeholder="<?php esc_html_e( 'Search Settings...', 'user-registration' ); ?>" fdprocessedid="8fe27c">
							<div class="user-registration-search-icon">
								<svg xmlns="http://www.w3.org/2000/svg" height="24px" width="24px" viewBox="0 0 24 24" fill="#a1a4b9"><path d="M21.71,20.29,18,16.61A9,9,0,1,0,16.61,18l3.68,3.68a1,1,0,0,0,1.42,0A1,1,0,0,0,21.71,20.29ZM11,18a7,7,0,1,1,7-7A7,7,0,0,1,11,18Z"></path></svg>
							</div>
						</div>
					</div>
					<div class="user-registration-header--nav">
						<nav class="nav-tab-wrapper ur-nav ur-nav--tab ur-nav-tab-wrapper">
							<?php
							foreach ( $tabs as $name => $label ) {
								?>
								<div class="ur-nav__tab-item">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=' . $name ) ); ?>" class="nav-tab ur-nav__link <?php echo ( $current_tab === $name ? 'nav-tab-active is-active' : '' ); ?>">
									<span class="ur-nav__link-icon">
										<?php echo ur_file_get_contents( '/assets/images/settings-icons/' . $name . '.svg' ); //phpcs:ignore ?>
									</span>
									<span class="ur-nav__link-label">
										<span>
											<?php echo esc_html( $label ); ?>
										</span>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
											<path stroke="#383838" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6"/>
										</svg>
									</span>
								</a>
								<?php if ( $current_tab === $name ) : ?>
								<div class="ur-scroll-ui">
									<?php
									do_action( 'user_registration_sections_' . $current_tab );
									?>
								</div>
								<?php endif; ?>
								</div>
								<?php
							}
							do_action( 'user_registration_settings_tabs' );
							?>
						</nav>
					</div>
				</header>
				<div class="user-registration-settings-container">
					<div class="user-registration-options-header">
						<div class="user-registration-options-header--top">
							<div class="user-registration-options-header--top__left">
								<div class="user-registration-options-header__burger">
									<svg xmlns="http://www.w3.org/2000/svg" width="24px" height="24px" viewBox="0 0 24 24" fill="none">
										<path d="M4 18L20 18" stroke="#000000" stroke-width="2" stroke-linecap="round"></path>
										<path d="M4 12L20 12" stroke="#000000" stroke-width="2" stroke-linecap="round"></path>
										<path d="M4 6L20 6" stroke="#000000" stroke-width="2" stroke-linecap="round"></path>
									</svg>
								</div>
								<?php if ( isset( $tabs[ $current_tab ] ) ) { ?>
									<span class="user-registration-options-header--top__left--icon">
										<?php echo ur_file_get_contents( '/assets/images/settings-icons/' . apply_filters( 'user_registration_settings_header_icon_' . $current_tab, $current_tab ) . '.svg' ); //phpcs:ignore ?>
									</span>
									<h3><?php echo esc_html( apply_filters( 'user_registration_settings_header_title_' . $current_tab, $tabs[ $current_tab ] ) ); ?></h3>
									<?php
								} else {
									$redirect_url = home_url( '/wp-admin/admin.php?page=user-registration-settings&tab=general' );
									?>
									<script>
									var redirect = '<?php echo esc_url_raw( $redirect_url ); ?>';
									window.location.href = redirect;
									</script>
									<?php
								}
								?>
							</div>
							<?php
							/**
							 * Action to output controls on the right of the options header of a tab.
							 */
							do_action( 'user_registration_settings_header_actions_' . $current_tab );
							?>
						</div>
						<div class="user-registration-options-header--bottom"></div>
					</div>
					<div class="user-registration-options-container">
						<?php
							do_action( 'user_registration_section_parts_' . $current_tab );
							self::show_messages();

							/**
							 * Action to show current tab.
							 */
							do_action( 'user_registration_settings_' . $current_tab );
							/**
							 * Action for current settings tab.
							 */
							do_action( 'user_registration_settings_tabs_' . $current_tab ); // @deprecated hook
						?>
					</div>
					<p class="submit">
						<?php
						$hide_save_button = apply_filters( 'user_registration_settings_hide_save_button', $GLOBALS['hide_save_button'] ?? false );
						if ( ! ur_string_to_bool( $hide_save_button ) ) :
							/**
							 * Filter to save the setting label.
							 *
							 * @param string Setting Save Label.
							 */
							$user_registration_setting_save_label = apply_filters( 'user_registration_setting_save_label', esc_attr__( 'Save Changes', 'user-registration' ) );
							?>
							<input name="save" class="<?php echo implode( ' ', apply_filters( 'user_registration_setting_save_button_classes', array( 'button-primary' ) ) ) ?>" type="submit" value="<?php echo esc_attr( $user_registration_setting_save_label ); ?>" />
						<?php endif; ?>
						<input type="hidden" name="subtab" id="last_tab" />
						<?php wp_nonce_field( 'user-registration-settings' ); ?>
					</p>
				</div>
			</div>
		</div>
	</form>
</div>
