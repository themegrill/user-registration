<?php
/**
 * User_Registration_Membership Crons
 *
 * @package User_Registration_Membership
 * @since  1.0.0
 */

namespace WPEverest\URMembership;

use WPEverest\URMembership\Admin\Services\SubscriptionService;

class Crons {

	public function __construct() {
		$this->init();
	}

	public function init() {
		if ( ur_check_module_activation( 'membership' ) ) {
			add_action( 'urm_run_delayed_subscription', array( $this, 'run_daily_delayed_membership_subscriptions' ) );
			add_action( 'urm_daily_membership_renewal_check', array( $this, 'membership_renewal_check' ), 10, 1 );
			add_action( 'urm_daily_membership_expiring_soon_check', array( $this, 'membership_expiring_soon_check' ), 10, 1 );
			add_action( 'urm_daily_membership_ended_check', array( $this, 'membership_ended_check' ), 10, 1 );
			add_action( 'urm_daily_membership_expiration_check', array( $this, 'membership_expiration_check' ), 10, 1 );

			// for both membership and non membership payments.
			add_action( 'urm_daily_payment_retry_check', array( $this, 'payment_retry_check' ), 10, 1 );
			add_action( 'urm_missed_payment_events_check', array( $this, 'membership_missed_payment_check' ), 10, 1 );

		}
	}

	/**
	 * Retry payments check for failed subscriptions.
	 */
	public function payment_retry_check() {
		$subscription_service = new SubscriptionService();
		$subscription_service->daily_payment_retry_check();
	}

	/**
	 * Run daily subscription updated for all delayed subscriptions.
	 *
	 * @return void
	 */
	public function run_daily_delayed_membership_subscriptions() {
		$subscription_service = new SubscriptionService();
		$subscription_service->run_daily_delayed_membership_subscriptions();
	}

	/**
	 * Send membership renewal email
	 *
	 * @return void
	 */
	public function membership_renewal_check() {
		// Each subscription is matched to the reminder of the renewal behaviour it was created under.
		if ( ! ur_option_checked( 'user_registration_membership_enable_renewal_reminder_user_email', true ) ) {
			return;
		}
		$subscription_service = new SubscriptionService();
		$subscription_service->daily_membership_renewal_check();
	}

	/**
	 * membership_expiring_soon_check
	 *
	 * @return void
	 */
	public function membership_expiring_soon_check() {

		if ( ! ur_option_checked( 'user_registration_membership_enable_expiring_soon_user_email', true ) ) {
			return;
		}

		$subscription_service = new SubscriptionService();
		$subscription_service->daily_membership_expiring_soon_check();
	}

	public function membership_ended_check() {

		if ( ! ur_option_checked( 'user_registration_membership_enable_membership_ended_user_email', true ) ) {
			return;
		}

		$subscription_service = new SubscriptionService();
		$subscription_service->daily_membership_ended_check();
	}

	/**
	 * membership_expiration_check
	 * Check for memberships that have passed their expiry date and mark them as expired
	 *
	 * @return void
	 */
	public function membership_expiration_check() {
		$subscription_service = new SubscriptionService();
		$subscription_service->daily_membership_expiration_check();
	}

	/**
	 * membership_missed_payment_check
	 * Check for memberships that have missed payments and add related details.
	 *
	 * @return void
	 */
	public function membership_missed_payment_check() {
		$subscription_service = new SubscriptionService();
		$subscription_service->membership_missed_payment_check();
	}
}
