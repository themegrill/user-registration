<?php

namespace WPEverest\URMembership\Emails;

use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Services\SubscriptionService;
use WPEverest\URMembership\Emails\Admin\UR_Settings_Membership_Cancellation_Admin_Email;
use WPEverest\URMembership\Emails\Admin\UR_Settings_Membership_Downgraded_Free_Admin_Email;
use WPEverest\URMembership\Emails\User\UR_Settings_Membership_Cancellation_User_Email;
use WPEverest\URMembership\Emails\User\UR_Settings_Membership_Downgraded_Free_User_Email;
use WPEverest\URMembership\Emails\User\UR_Settings_Membership_Ended_User_Email;
use WPEverest\URMembership\Emails\User\UR_Settings_Membership_Expiring_Soon_User_Email;
use WPEverest\URMembership\Emails\User\UR_Settings_Membership_Renewal_Reminder_User_Email;

/**
 * EmailSettings.php
 *
 * @class    EmailSettings.php
 * @date     4/24/2025 : 2:01 PM
 */
class EmailSettings {
	public function __construct() {
		add_filter( 'user_registration_email_classes', array( $this, 'add_email_settings' ), 10, 1 );
	}

	/**
	 * Add email settings
	 *
	 * @param $emails
	 *
	 * @return array
	 */
	public function add_email_settings( $emails ) {
		if ( empty( ( new MembershipRepository() )->get_all_membership() ) ) {
			return $emails;
		}

		$new_emails = array(
			'UR_Settings_Membership_Cancellation_Admin_Email'    => new UR_Settings_Membership_Cancellation_Admin_Email(),
			'UR_Settings_Membership_Cancellation_User_Email'     => new UR_Settings_Membership_Cancellation_User_Email(),
		);

		if ( UR_PRO_ACTIVE ) {
			// Only the reminder matching the site's renewal behaviour is listed; the other stays registered nowhere so its saved content is untouched.
			if ( 'automatic' === get_option( 'user_registration_renewal_behaviour', 'automatic' ) ) {
				$new_emails['UR_Settings_Membership_Renewal_Reminder_User_Email'] = new UR_Settings_Membership_Renewal_Reminder_User_Email();
			} else {
				$new_emails['UR_Settings_Membership_Expiring_Soon_User_Email'] = new UR_Settings_Membership_Expiring_Soon_User_Email();
			}
			$new_emails['UR_Settings_Membership_Ended_User_Email'] = new UR_Settings_Membership_Ended_User_Email();

			$new_emails['UR_Settings_Membership_Downgraded_Free_User_Email']  = new UR_Settings_Membership_Downgraded_Free_User_Email();
			$new_emails['UR_Settings_Membership_Downgraded_Free_Admin_Email'] = new UR_Settings_Membership_Downgraded_Free_Admin_Email();
		}

		return array_merge( $emails, $new_emails );
	}


}
