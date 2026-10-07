<?php
/**
 * URMembership Interfaces.
 *
 * @package  URMembership/SubscriptionInterface
 * @category Interface
 * @author   WPEverest
 */

namespace WPEverest\URMembership\Admin\Interfaces;

interface SubscriptionInterface extends BaseInterface {

	/**
	 * Cancel subscription by subscription ID
	 *
	 * @param int  $subscription_id Subscription ID.
	 * @param bool $send_email      Whether to send cancellation emails.
	 * @param bool $is_upgrade      Whether this cancel is part of an upgrade.
	 * @param bool $force_cancel    Force immediate gateway cancel.
	 *
	 * @return mixed
	 */
	public function cancel_subscription_by_id( $subscription_id, $send_email = true, $is_upgrade = false, $force_cancel = false );

}
