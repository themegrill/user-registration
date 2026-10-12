<?php

/**
 * Display notices in admin.
 *
 * @class    UR_Admin_Notices
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Admin_Notices Class.
 */
class UR_Admin_Notices
{

	/**
	 * Stores notices.
	 *
	 * @var array
	 */
	private static $notices = array();
	/**
	 * Stores notices.
	 *
	 * @var array
	 */
	private static $custom_notices = array();

	/**
	 * Array of notices - name => callback
	 *
	 * @var array
	 */
	private static $core_notices = array(
		'install'               => 'install_notice',
		'continue_setup_wizard' => 'continue_setup_wizard_notice',
	);

	/**
	 * Constructor.
	 */
	public static function init()
	{
		self::$notices = get_option('user_registration_admin_notices', array());

		add_action('wp_loaded', array(__CLASS__, 'hide_notices'));
		add_action('shutdown', array(__CLASS__, 'store_notices'));

		if (current_user_can('manage_user_registration')) {
			add_action('admin_print_styles', array(__CLASS__, 'add_notices'));
			add_action('in_admin_header', array(__CLASS__, 'hide_unrelated_notices'));
		}

		add_action('admin_init', array(__CLASS__, 'user_registration_install_pages_notice'));
		add_action('admin_notices', array(__CLASS__, 'php_deprecation_notice'));
		add_action('admin_init', array(__CLASS__, 'same_membership_in_group'));
		add_action('admin_notices', array(__CLASS__, 'registration_disabled_notice'));

		/**
		 * Render Notice with Logo and Buttons.
		 */
		add_action('admin_notices', array(__CLASS__, 'render_custom_notices'));
	}


	/**
	 * Display install pages notice if the user has skipped getting started.
	 *
	 * @since 2.2.3
	 */
	public static function user_registration_install_pages_notice()
	{

		if (isset($_POST['user_registration_myaccount_page_id'])) { //phpcs:ignore.
			$my_account_page = $_POST['user_registration_myaccount_page_id']; //phpcs:ignore.
		} else {
			$my_account_page = get_option('user_registration_myaccount_page_id', 0);
		}

		if (get_option('user_registration_onboarding_skipped', false) && !ur_string_to_bool(get_option('urm_is_new_installation', false))) {
			self::add_notice('continue_setup_wizard');
		} elseif (! $my_account_page && ! get_option('user_registration_first_time_activation_flag', false)) {
			if (get_option('user_registration_install_pages_notice_removed', false)) {
				self::remove_notice('install');
			} else {
				self::add_notice('install');
			}
		} else {
			self::remove_notice('install');
		}

		$matched        = 0;
		$myaccount_page = array();

		if ($my_account_page) {
			$myaccount_page = get_post($my_account_page);
		}

		if (! empty($myaccount_page)) {
			$matched = ur_find_my_account_in_page($myaccount_page->ID);
		}

		if (0 === $matched) {
			$my_account_setting_link = admin_url() . 'admin.php?page=user-registration-settings#user_registration_myaccount_page_id';
			$urm_show_message        = apply_filters('user_registration_membership_show_my_account_notice', true);
			if ($urm_show_message) {
				$message = sprintf(
					/* translators: %1$s - My account Link. */
					__('Please choose a <strong title="A page with [user_registration_my_account] shortcode">My Account</strong> page in <a href="%1$s" style="text-decoration:none;">General Settings</a>. <br/><strong>Got Stuck? Read</strong> <a href="https://docs.wpuserregistration.com/docs/how-to-show-account-profile/" style="text-decoration:none;" rel="noreferrer noopener" target="_blank">How to setup My Account page</a>.', 'user-registration'),
					$my_account_setting_link
				);
				self::add_custom_notice('select_my_account', $message);
			} else {
				self::remove_notice('select_my_account');
			}
		} else {
			self::remove_notice('select_my_account');
		}
	}

	/**
	 * Render Custom Notices.
	 *
	 * @since 3.3.0
	 */
	public static function render_custom_notices()
	{
		self::custom_notices();
		$active_notices = self::get_active_high_priority_notices(self::$custom_notices);
		$valid_notice   = self::get_single_valid_notice($active_notices);
		if (! empty($valid_notice)) {
			$notice_id           = $valid_notice['type'] . '_' . $valid_notice['id'];
			$notice_type         = $valid_notice['type'];
			$notice_header       = $valid_notice['title'];
			$notice_content      = $valid_notice['message_content'];
			$notice_target_links = $valid_notice['buttons'];
			$permanent_dismiss   = $valid_notice['permanent_dismiss'];
			include __DIR__ . '/views/html-notice-banner.php';
		}
	}

	/**
	 * Get one valid notice after checking all conditions.
	 *
	 * @since 3.3.0
	 *
	 * @param array $notices Notices.
	 * @return array
	 */
	public static function get_single_valid_notice($notices)
	{
		$valid_notice = array();

		foreach ($notices as $key => $notice) {
			$conditions      = $notice['conditions_to_display'][0];
			$operator        = 'AND';
			$valid_condition = array();

			$notice_id    = $notice['type'] . '_' . $notice['id'];
			$reopen_days  = $notice['reopen_days'];
			$reopen_times = $notice['reopen_times'];
			foreach ($conditions as $key => $value) {

				if ('operator' == $key) {
					$operator = $value;
				} else {
					$valid = self::validate_notice_conditions($key, $value);
					if ($valid && $operator == 'OR') {
						array_push($valid_condition, $valid);
						break;
					} elseif ($valid && $operator == 'AND') {
						array_push($valid_condition, $valid);
						continue;
					} elseif (! $valid && $operator == 'AND') {
						break;
					}
				}
			}

			if ('AND' === $operator && (count($conditions) - 1) === count($valid_condition)) {
				$show_notice = self::show_promotional_notice($notice_id, $reopen_days, $reopen_times);
				if ($show_notice) {
					$valid_notice = $notice;
					break;
				}
			} elseif ('OR' === $operator && 1 == count($valid_condition)) {
				$show_notice = self::show_promotional_notice($notice_id, $reopen_days, $reopen_times);
				if ($show_notice) {
					$valid_notice = $notice;
					break;
				}
			}
		}

		return $valid_notice;
	}

	/**
	 * Validate all conditions to display notice.
	 *
	 * @since 3.3.0
	 *
	 * @param  string $key Condition type.
	 * @param  mixed  $value Condition value.
	 * @return bool
	 */
	public static function validate_notice_conditions($key, $value)
	{
		$valid = false;

		switch ($key) {
			case 'wp_version':
				$wp_version = get_bloginfo('version');
				preg_match('/([<>!=]=?)(\d+(\.\d+)+)/', $value, $matches);
				$numeric_operator   = $matches[1];
				$version_to_compare = $matches[2];

				$valid = version_compare($wp_version, $version_to_compare, $numeric_operator);
				break;
			case 'php_version':
				$php_version = phpversion();
				preg_match('/([<>!=]=?)(\d+(\.\d+)+)/', $value, $matches);
				$numeric_operator   = $matches[1];
				$version_to_compare = $matches[2];

				$valid = version_compare($php_version, $version_to_compare, $numeric_operator);
				break;
			case 'products':
				$valid = ur_check_products_version($value);
				break;
			case 'functions':
				$valid = ur_check_all_functions($value);
				break;
			case 'db_conditions':
				// code...
				break;
			case 'user_count':
				$cache_key          = 'total_registration_count';
				$total_registration = wp_cache_get($cache_key);

				if ($total_registration === false) {
					global $wpdb;
					$form_id_meta_key = 'ur_form_id';
					$table_usermeta   = $wpdb->usermeta;

					$total_registration = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(DISTINCT user_id)
							FROM $table_usermeta
							WHERE meta_key = %s",
							$form_id_meta_key
						)
					);
					wp_cache_set($cache_key, $total_registration, '', 3600);
				}
				$valid = ur_check_numeric_operator($total_registration, $value);
				break;
			case 'activation_days':
				$valid = ur_check_activation_date($value);
				break;
			case 'updation_days':
				$valid = ur_check_updation_date($value);
				break;
			case 'option_exists':
				$valid = ! empty(get_option($value, true));
				break;
			case 'show_notice':
				$valid = $value;
				break;
		}

		return $valid;
	}

	/**
	 * Get only active notice with sorting high priority first.
	 *
	 * @since 3.3.0
	 *
	 * @param  array $notices Notices.
	 */
	public static function get_active_high_priority_notices($notices)
	{
		$highestPriorityNotices = array_filter(
			$notices,
			function ($notice) {
				return 'active' === $notice['status'];
			}
		);

		usort(
			$highestPriorityNotices,
			function ($a, $b) {
				return $a['priority'] - $b['priority'];
			}
		);

		return $highestPriorityNotices;
	}

	/**
	 * Get All Custom Notices.
	 *
	 * @since 3.3.0
	 */
	public static function custom_notices()
	{
		if (empty(self::$custom_notices)) {
			self::$custom_notices = apply_filters(
				'user_registration_custom_notices',
				array(
					array(
						'id'                    => 'ur_20_user_registered_review_notice',
						'type'                  => 'review',
						'status'                => 'active',
						'priority'              => '2',
						'title'                 => __('Bravo! 💪 Well done.', 'user-registration'),
						'message_content'       => wp_kses_post(
							sprintf(
								"<p>%s</p><p>%s</p><p class='extra-pad'>%s</p>",
								__("Congratulations! 👏 You've registered 20 users using our User Registration & Membership plugin, way to go! 🎉", 'user-registration'),
								__('Please share your experience with us by leaving a review. Your feedback will help us improve and serve you better. ', 'user-registration'),
								__(
									'Once again, thank you for choosing us! ❤️',
									'user-registration'
								)
							)
						),
						'buttons'               => array(
							array(
								'title'  => __("Sure, I'd love to!", 'user-registration'),
								'icon'   => 'dashicons-external',
								'link'   => 'https://wordpress.org/support/plugin/user-registration/reviews/#postform',
								'class'  => 'button-primary',
								'target' => '_blank',
							),
							array(
								'title'  => __('I already did!', 'user-registration'),
								'icon'   => 'dashicons-smiley',
								'link'   => '#',
								'class'  => 'button-secondary notice-dismiss notice-dismiss-permanently',
								'target' => '',
							),
							array(
								'title'  => __('Maybe later', 'user-registration'),
								'icon'   => 'dashicons-dismiss',
								'link'   => '#',
								'class'  => 'button-secondary notice-dismiss notice-dismiss-temporarily',
								'target' => '',
							),
							array(
								'title'  => __('I have a query', 'user-registration'),
								'icon'   => 'dashicons-testimonial',
								'link'   => 'https://wpuserregistration.com/support',
								'class'  => 'button-secondary notice-have-query',
								'target' => '_blank',
							),
						),
						'permanent_dismiss'     => true,
						'reopen_days'           => '1',
						'reopen_times'          => '3',
						'conditions_to_display' => array(
							array(
								'operator'        => 'AND',
								'show_notice'     => ! ur_check_notice_already_permanent_dismissed('review'),
								'user_count'      => '>=20',
								'activation_days' => '7',
							),
						),
					),
					array(
						'id'                    => 'ur_early_review_notice',
						'type'                  => 'review',
						'status'                => 'active',
						'priority'              => '2',
						'title'                 => __('Bravo! 💪 Well done.', 'user-registration'),
						'message_content'       => wp_kses_post(
							sprintf(
								"<p>%s</p><p>%s</p><p class='extra-pad'>%s</p>",
								__('( The above word is just to draw your attention. <span class="dashicons dashicons-smiley smile-icon"></span> )', 'user-registration'),
								__('Hope you are having nice experience with <strong>User Registration & Membership</strong> plugin. Please provide this plugin a nice review.', 'user-registration'),
								__(
									'<strong>What benefit would you have?</strong> <br>
								Basically, it would encourage us to release updates regularly with new features & bug fixes so that you can keep on using the plugin without any issues and also to provide free support like we have been doing. <span class="dashicons dashicons-smiley smile-icon"></span>',
									'user-registration'
								)
							)
						),
						'buttons'               => array(
							array(
								'title'  => __("Sure, I'd love to!", 'user-registration'),
								'icon'   => 'dashicons-external',
								'link'   => 'https://wordpress.org/support/plugin/user-registration/reviews/#postform',
								'class'  => 'button-primary',
								'target' => '_blank',
							),
							array(
								'title'  => __('I already did!', 'user-registration'),
								'icon'   => 'dashicons-smiley',
								'link'   => '#',
								'class'  => 'button-secondary notice-dismiss notice-dismiss-permanently',
								'target' => '',
							),
							array(
								'title'  => __('Maybe later', 'user-registration'),
								'icon'   => 'dashicons-dismiss',
								'link'   => '#',
								'class'  => 'button-secondary notice-dismiss notice-dismiss-temporarily',
								'target' => '',
							),
							array(
								'title'  => __('I have a query', 'user-registration'),
								'icon'   => 'dashicons-testimonial',
								'link'   => 'https://wpuserregistration.com/support',
								'class'  => 'button-secondary notice-have-query',
								'target' => '_blank',
							),
						),
						'permanent_dismiss'     => true,
						'reopen_days'           => '1',
						'reopen_times'          => '3',
						'conditions_to_display' => array(
							array(
								'operator'        => 'AND',
								'show_notice'     => ! ur_check_notice_already_permanent_dismissed('review'),
								'user_count'      => '<20',
								'activation_days' => '7',
							),
						),
					),
					array(
						'id'                    => 'ur_survey_form',
						'type'                  => 'survey',
						'status'                => 'active',
						'priority'              => '4',
						'title'                 => __('User Registration & Membership Plugin Survey', 'user-registration'),
						'message_content'       => wp_kses_post(
							sprintf(
								"<p>%s</p><p class='extra-pad'>%s</p>",
								__(
									'<strong>Hey there!</strong> <br>
									We would be grateful if you could spare a moment and help us fill this survey. This survey will take approximately 4 minutes to complete.',
									'user-registration'
								),
								__(
									'<strong>What benefit would you have?</strong> <br>
									We will take your feedback from the survey and use that information to make the plugin better. As a result, you will have a better plugin as you wanted. <span class="dashicons dashicons-smiley smile-icon"></span>',
									'user-registration'
								)
							)
						),
						'buttons'               => array(
							array(
								'title'  => __("Sure, I'd love to!", 'user-registration'),
								'icon'   => 'dashicons-external',
								'link'   => 'https://forms.office.com/pages/responsepage.aspx?id=c04iBAejyEWvNQDb6GzDCILyv8m6NoBDvJVtRTCcOvBUNk5OSTA4OEs1SlRPTlhFSFZXRFA0UFEwRCQlQCN0PWcu',
								'class'  => 'button-primary',
								'target' => '_blank',
							),
							array(
								'title'  => __('I already did!', 'user-registration'),
								'icon'   => 'dashicons-smiley',
								'link'   => '#',
								'class'  => 'button-secondary notice-dismiss notice-dismiss-permanently',
								'target' => '',
							),
							array(
								'title'  => __('Maybe later', 'user-registration'),
								'icon'   => 'dashicons-dismiss',
								'link'   => '#',
								'class'  => 'button-secondary notice-dismiss notice-dismiss-temporarily',
								'target' => '',
							),
							array(
								'title'  => __('I have a query', 'user-registration'),
								'icon'   => 'dashicons-testimonial',
								'link'   => 'https://wpuserregistration.com/support',
								'class'  => 'button-secondary notice-have-query',
								'target' => '_blank',
							),
						),
						'permanent_dismiss'     => true,
						'reopen_days'           => '1',
						'reopen_times'          => '3',
						'conditions_to_display' => array(
							array(
								'operator'        => 'AND',
								'show_notice'     => ! ur_check_notice_already_permanent_dismissed('survey'),
								'activation_days' => '10',
								'option_exists'   => 'user_registration_license_key',
							),
						),
					),
					// array(
					// 'id'                    => 'ur_allow_usage',
					// 'type'                  => 'allow-usage',
					// 'status'                => 'active',
					// 'priority'              => '3',
					// 'title'                 => __( 'Contribute to the enhancement', 'user-registration' ),
					// 'message_content'       => wp_kses_post(
					// sprintf(
					// '<p>%s</p>',
					// __(
					// 'Help us improve the plugin\'s features by sharing <a href="https://docs.wpuserregistration.com/global-settings/advanced-settings/#allow-usage-tracking" target="_blank">non-sensitive plugin data</a> with us.',
					// 'user-registration'
					// )
					// )
					// ),
					// 'buttons'               => array(
					// array(
					// 'title'  => __( 'Allow', 'user-registration' ),
					// 'icon'   => 'dashicons-smiley',
					// 'link'   => 'https://forms.office.com/pages/responsepage.aspx?id=c04iBAejyEWvNQDb6GzDCILyv8m6NoBDvJVtRTCcOvBUNk5OSTA4OEs1SlRPTlhFSFZXRFA0UFEwRCQlQCN0PWcu',
					// 'class'  => 'button-primary ur-allow-usage',
					// 'target' => '_blank',
					// ),
					// array(
					// 'title'  => __( 'No, Thanks', 'user-registration' ),
					// 'icon'   => 'dashicons-dismiss',
					// 'link'   => '#',
					// 'class'  => 'button-secondary notice-dismiss notice-dismiss-permanently ur-deny-usage',
					// 'target' => '',
					// ),
					// ),
					// 'permanent_dismiss'     => false,
					// 'reopen_days'           => '1',
					// 'reopen_times'          => '3',
					// 'conditions_to_display' => array(
					// array(
					// 'operator'      => 'AND',
					// 'show_notice'   => ! ur_check_notice_already_permanent_dismissed( 'allow-usage' ),
					// 'updation_days' => '1',
					// 'option_exists' => 'user_registration_allow_usage_tracking',
					// ),
					// ),
					// ),
				)
			);
		}

		$notices = apply_filters('user_registration_get_remote_notices', false);
		if ($notices && is_array($notices)) {
			self::$custom_notices = array_merge(self::$custom_notices, $notices);
		}
	}

	/**
	 * Check whether notice is showable or not.
	 *
	 * @param string $notice_type Notice Type.
	 * @param string $days Number of days for temparary dismissed.
	 * @return bool
	 */
	public static function show_promotional_notice($notice_id, $reopen_days = '1', $reopen_times = 1)
	{
		// Show only to Admins.
		if (! current_user_can('manage_options')) {
			return false;
		}

		$notice_dismissed = ur_option_checked('user_registration_' . $notice_id . '_notice_dismissed', false);

		$notice_dismissed_temporarily = json_decode(get_option('user_registration_' . $notice_id . '_notice_dismissed_temporarily', ''), true);
		$reopened_times               = isset($notice_dismissed_temporarily) ? $notice_dismissed_temporarily['reopen_times'] : 0;
		$last_dismiss                 = isset($notice_dismissed_temporarily) ? $notice_dismissed_temporarily['last_dismiss'] : '';

		if ($notice_dismissed) {
			return false;
		}

		// Return if dismissed date is less than a day.
		if (! empty($notice_dismissed_temporarily)) {
			if ($reopen_times == $reopened_times) {
				return false;
			}

			$days_to_validate = strtotime($last_dismiss);
			$days_to_validate = strtotime('+' . $reopen_days . ' day', $days_to_validate);
			$days_to_validate = date_i18n('Y-m-d', $days_to_validate);

			$current_date = date_i18n('Y-m-d');

			if ($current_date < $days_to_validate) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Add PHP Deprecation notice.
	 */
	public static function php_deprecation_notice()
	{
		$php_version  = explode('-', PHP_VERSION)[0];
		$base_version = '7.2';

		if (version_compare($php_version, $base_version, '<')) {
			$last_prompt_date = get_option('user_registration_php_deprecated_notice_last_prompt_date', '');

			if (empty($last_prompt_date) || strtotime($last_prompt_date) < strtotime('-1 day')) {
				$prompt_limit = 3;
				$prompt_count = get_option('user_registration_php_deprecated_notice_prompt_count', 0);

				if ($prompt_count < $prompt_limit) {
					include __DIR__ . '/views/html-notice-php-deprecation.php';
				}
			}
		}
	}

	/**
	 * Store notices to DB
	 */
	public static function store_notices()
	{
		update_option('user_registration_admin_notices', self::get_notices());
	}

	/**
	 * Get notices.
	 *
	 * @return array
	 */
	public static function get_notices()
	{
		return self::$notices;
	}

	/**
	 * Remove all notices.
	 */
	public static function remove_all_notices()
	{
		self::$notices = array();
	}

	/**
	 * Show a notice.
	 *
	 * @param string $name Name.
	 */
	public static function add_notice($name)
	{
		self::$notices = array_unique(array_merge(self::get_notices(), array($name)));
	}

	/**
	 * Remove a notice from being displayed.
	 *
	 * @param string $name Name.
	 */
	public static function remove_notice($name)
	{
		self::$notices = array_diff(self::get_notices(), array($name));
		delete_option('user_registration_admin_notice_' . $name);
	}

	/**
	 * See if a notice is being shown.
	 *
	 * @param  string $name Name.
	 * @return boolean
	 */
	public static function has_notice($name)
	{
		return in_array($name, self::get_notices(), true);
	}

	/**
	 * Hide a notice if the GET variable is set.
	 */
	public static function hide_notices()
	{
		if (isset($_GET['ur-hide-notice']) && isset($_GET['_ur_notice_nonce'])) {
			if (! wp_verify_nonce(sanitize_key(wp_unslash($_GET['_ur_notice_nonce'])), 'user_registration_hide_notices_nonce')) {
				wp_die(esc_html__('Action failed. Please refresh the page and retry.', 'user-registration'));
			}

			if (! current_user_can('manage_options')) {
				wp_die(esc_html__('Cheatin&#8217; huh?', 'user-registration'));
			}

			$hide_notice = sanitize_text_field(wp_unslash($_GET['ur-hide-notice']));
			self::remove_notice($hide_notice);

			// Remove the onboarding skipped checker if install notice is removed.
			if ('continue_setup_wizard' === $hide_notice) {
				delete_option('user_registration_onboarding_skipped');
				delete_option('user_registration_onboarding_skipped_step');
			}

			if ('install' === $hide_notice) {
				update_option('user_registration_install_pages_notice_removed', true);
			}

			/**
			 * Action to hide notice
			 */
			do_action('user_registration_hide_' . $hide_notice . '_notice');
		}
	}

	/**
	 * Add notices + styles if needed.
	 */
	public static function add_notices()
	{
		$notices = self::get_notices();

		if ($notices) {
			wp_enqueue_style('user-registration-activation', UR()->plugin_url() . '/assets/css/activation.css', array(), UR_VERSION);

			// Add RTL support.
			wp_style_add_data('user-registration-activation', 'rtl', 'replace');

			foreach ($notices as $notice) {
				/**
				 * Filter to modify the display of admin notice
				 *
				 * @param boolean
				 * @param $notice Notice
				 */
				if (! empty(self::$core_notices[$notice]) && apply_filters('user_registration_show_admin_notice', true, $notice)) {
					add_action('admin_notices', array(__CLASS__, self::$core_notices[$notice]));
				} else {
					add_action('admin_notices', array(__CLASS__, 'output_custom_notices'));
				}
			}
		}
	}

	/**
	 * Remove Notices other than user registration on user registration builder page.
	 *
	 * @since 1.4.5
	 */
	public static function hide_unrelated_notices()
	{
		global $wp_filter;

		// Array to define pages where notices are to be excluded.
		$pages_to_exclude = array(
			'add-new-registration',
			'user-registration-settings',
			'user-registration-email-templates',
			'user-registration-mailchimp',
			'user-registration-dashboard',
			'user-registration-login-forms',
		);

		/**
		 * Filter to modify the Pages to exclude notice from
		 *
		 * @param boolean
		 * @param string $pages_to_exclude Pages to Exclude
		 */
		$pages_to_exclude = apply_filters('user_registration_notice_excluded_pages', $pages_to_exclude);

		// Return on other than user registraion builder page.
		if (empty($_REQUEST['page']) || ! in_array($_REQUEST['page'], $pages_to_exclude)) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		foreach (array('user_admin_notices', 'admin_notices', 'all_admin_notices') as $wp_notice) {

			if (! empty($wp_filter[$wp_notice]->callbacks) && is_array($wp_filter[$wp_notice]->callbacks)) {

				foreach ($wp_filter[$wp_notice]->callbacks as $priority => $hooks) {
					foreach ($hooks as $name => $arr) {
						// Always keep the registration disabled notice, it blocks every registration form.
						if (is_string($name) && false !== strpos($name, 'registration_disabled_notice')) {
							continue;
						}

						// Remove all notices if the page is form builder page.
						if ('add-new-registration' === $_REQUEST['page'] || 'user-registration-dashboard' === $_REQUEST['page']) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							unset($wp_filter[$wp_notice]->callbacks[$priority][$name]);
						} else { // phpcs:ignore
							// Remove all notices except user registration plugins notices.
							if (null !== $name) {
								if (strstr($name, 'user_registration_error_notices')) {
									if (! isset($_REQUEST['tab']) || 'license' !== $_REQUEST['tab']) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
										unset($wp_filter[$wp_notice]->callbacks[$priority][$name]);
									}
								} elseif (strpos($name, 'user_registration_') || strpos($name, 'UR_Admin_Notices')) {
									continue;
								} else {
									unset($wp_filter[$wp_notice]->callbacks[$priority][$name]);
								}
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Add a custom notice.
	 *
	 * @param string $name Name.
	 * @param string $notice_html Notice.
	 */
	public static function add_custom_notice($name, $notice_html)
	{
		self::add_notice($name);
		update_option('user_registration_admin_notice_' . sanitize_text_field($name), wp_kses_post($notice_html));
	}

	/**
	 * Output any stored custom notices.
	 */
	public static function output_custom_notices()
	{
		$notices = self::get_notices();

		if ($notices) {
			foreach ($notices as $notice) {
				if (empty(self::$core_notices[$notice])) {
					$notice_html = get_option('user_registration_admin_notice_' . $notice);

					if ($notice_html) {
						include 'views/html-notice-custom.php';
					}
				}
			}
		}
	}

	/**
	 * If we have just installed, show a message with the install pages button.
	 */
	public static function install_notice()
	{
		include 'views/html-notice-install.php';
	}

	/**
	 * If user have skipped setup wizard display the notice.
	 */
	public static function continue_setup_wizard_notice()
	{

		$first_time_activation = get_option('user_registration_first_time_activation_flag', false);
		$onboarding_completed     = true;
		$onboarding_complete_text = '';

		if (! $first_time_activation) {
			$onboard_skipped           = get_option('user_registration_onboarding_skipped', false);
			$onboard_skipped_step      = get_option('user_registration_onboarding_skipped_step', false);
			$registration_form_page_id = get_option('user_registration_registration_page_id', false);
			$my_account_page_id        = get_option('user_registration_myaccount_page_id', false);
			$install_pages_done        = ($registration_form_page_id || $my_account_page_id) ? true : false;
			$onboard_skipped_step      = 'install_page' === $onboard_skipped_step ? 'install_pages' : $onboard_skipped_step;

			if (('install_pages' === $onboard_skipped_step) && $install_pages_done) {
				$onboard_skipped_step .= '&installed';
			}

			if ($onboard_skipped) {
				/* translators: % s: continue wizard URL */
				$onboarding_complete_text  = sprintf(__('<a href="%s" class="button-primary" style="margin-right: 5px;">Continue Setup Wizard</a>', 'user-registration'), esc_url(admin_url('/admin.php?page=user-registration-welcome&tab=setup-wizard')));
				$onboarding_complete_text .= sprintf(__('<a class="button-secondary skip" href="%s">Skip setup</a>', 'user-registration'), esc_url(wp_nonce_url(add_query_arg('ur-hide-notice', 'continue_setup_wizard'), 'user_registration_hide_notices_nonce', '_ur_notice_nonce')));
				$onboarding_completed      = false;
			} else {
				$onboarding_completed = true;
			}
		} else {
			$onboarding_completed = false;
		}

		if (! $onboarding_completed) {
			$notice  = '<div id="message" class="updated user-registration-message ur-connect">';
			$notice .= '<p>' . wp_kses_post('It appears that the setup wizard was skipped. To ensure the User Registration & Membership Plugin is properly configured, please proceed with the setup wizard.', 'user-registration') . '</p>';
			$notice .= '<div class="submit">' . wp_kses_post($onboarding_complete_text) . '</div>';
			$notice .= '</div>';

			echo wp_kses_post($notice);
		}
	}

	/**
	 * Notify admins when the WordPress "Anyone can register" option blocks registration.
	 *
	 * @since 5.2.7
	 */
	public static function registration_disabled_notice()
	{
		if (ur_users_can_register() || ! current_user_can('manage_options')) {
			return;
		}

		$message = '<strong>' . esc_html__('User Registration & Membership: ', 'user-registration') . '</strong>';
		$message .= sprintf(
			/* translators: %1$s - WordPress general settings link. */
			__('Your registration forms are showing "Registration is currently disabled." because the WordPress <strong>Anyone can register</strong> option is turned off. Enable it in <a href="%1$s">General Settings</a> to let visitors register.', 'user-registration'),
			esc_url(admin_url('options-general.php#users_can_register'))
		);

		echo '<div class="notice notice-warning is-dismissible ur-registration-disabled-notice"><p>' . wp_kses_post($message) . '</p></div>';
	}

	/**
	 * If user have same membership in multiple groupds display the notice.
	 */
	public static function same_membership_in_group()
	{

		if (! ur_check_module_activation('membership')) {
			return;
		}

		$groups = get_posts(
			array(
				'post_type'      => 'ur_membership_groups',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		if (count($groups) < 2) {
			return;
		}

		$group_memberships = array();

		foreach ($groups as $group) {
			$memberships = get_post_meta($group->ID, 'urmg_memberships', true);

			if (empty($memberships)) {
				continue;
			}

			$memberships = is_string($memberships)
				? array_filter(array_map('absint', explode(',', trim($memberships, '[]'))))
				: (array) $memberships;

			if ($memberships) {
				$group_memberships[$group->ID] = $memberships;
			}
		}

		$group_ids = array_keys($group_memberships);

		self::remove_notice('membership_group_conflict');

		for ($i = 0; $i < count($group_ids); $i++) {
			for ($j = $i + 1; $j < count($group_ids); $j++) {

				if (array_intersect(
					$group_memberships[$group_ids[$i]],
					$group_memberships[$group_ids[$j]]
				)) {
					$message = '<strong>' . __('User Registration & Membership: ', 'user-registration') . '</strong>';
					$message .= __(
						'We’ve detected that a membership plan is assigned to multiple groups. Each plan must belong to only one group. Please update your settings.',
						'user-registration'
					);

					self::add_custom_notice('membership_group_conflict', $message);
					return;
				}
			}
		}
	}
}

UR_Admin_Notices::init();
