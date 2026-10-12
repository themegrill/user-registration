<?php

namespace WPEverest\URMembership\Admin\Services;

use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\MembersOrderRepository;
use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\OrdersRepository;
use WPEverest\URMembership\Admin\Repositories\SubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository;
use WPEverest\URMembership\Admin\Services\MembershipService;

class UpgradeMembershipService {

	protected $members_subscription_repository, $members_orders_repository, $membership_repository, $orders_repository, $subscription_repository, $membership_group_repository, $membership_service;

	public function __construct() {
		$this->members_subscription_repository = new MembersSubscriptionRepository();
		$this->subscription_repository         = new SubscriptionRepository();
		$this->members_orders_repository       = new MembersOrderRepository();
		$this->membership_repository           = new MembershipRepository();
		$this->orders_repository               = new OrdersRepository();
		$this->membership_group_repository     = new MembershipGroupRepository();
		$this->membership_service              = new MembershipService();
	}

	/**
	 * Handle Paid to Paid membership Upgrade
	 *
	 * @param $current_membership_details
	 * @param $selected_membership_details
	 * @param $subscription
	 *
	 * @return false[]
	 */
	public function calculate_chargeable_amount( $selected_amount, $current_amount, $upgrade_type ) {
		if ( 'full' === $upgrade_type ) {
			return $selected_amount;
		}
		if ( $selected_amount > $current_amount ) {
			return $selected_amount - $current_amount;
		}

		return $selected_amount;
	}

	public function handle_paid_to_paid_membership_upgrade( $current_membership_details, $selected_membership_details, $subscription ) {
		$upgrade_data = $this->get_upgrade_details( $current_membership_details );
		$upgrade_type = ! empty( $upgrade_data['upgrade_type'] ) ? $upgrade_data['upgrade_type'] : '';

		$response = array(
			'status' => false,
		);
		if ( empty( $upgrade_type ) ) {
			$response['status']  = true;
			$response['message'] = __( 'Membership upgrade is not enabled for this plan', 'user-registration' );
		}
		$response['status']            = true;
		$response['chargeable_amount'] = $this->calculate_chargeable_amount(
			$selected_membership_details['amount'],
			$current_membership_details['amount'],
			$upgrade_type
		);

		return $response;
	}

	public function handle_paid_to_subscription_membership_upgrade( $current_membership_details, $selected_membership_details, $subscription ) {
		$selected_membership_amount = $selected_membership_details['amount'];
		$current_membership_amount  = $current_membership_details['amount'];

		$upgrade_data         = $this->get_upgrade_details( $current_membership_details );
				$upgrade_type = ! empty( $upgrade_data['upgrade_type'] ) ? $upgrade_data['upgrade_type'] : '';

		$remaining_subscription_value = $selected_membership_details['subscription']['value'];
		$delayed_until                = '';

		$chargeable_amount = $this->calculate_chargeable_amount(
			$selected_membership_amount,
			$current_membership_amount,
			$upgrade_type
		);

		if ( 'partial' === $upgrade_type && $selected_membership_amount < $current_membership_amount ) {
			$delayed_until = $subscription['expiry_date'];
		}

		return array(
			'status'                       => true,
			'chargeable_amount'            => $chargeable_amount,
			'remaining_subscription_value' => $remaining_subscription_value,
			'delayed_until'                => $delayed_until,
		);
	}

	public function handle_subscription_to_paid_or_subscription_membership_upgrade( $current_membership_details, $selected_membership_details, $subscription, $is_trial ) {
		$selected_membership_amount = $selected_membership_details['amount'];
		$current_membership_amount  = $current_membership_details['amount'];
		$upgrade_data               = $this->get_upgrade_details( $current_membership_details );
		$upgrade_type               = ! empty( $upgrade_data['upgrade_type'] ) ? $upgrade_data['upgrade_type'] : '';

		$chargeable_amount            = 0;
		$remaining_subscription_value = $selected_membership_details['subscription']['value'] ?? 0;
		$delayed_until                = '';
		$timezone                     = get_option( 'timezone_string' );
		if ( ! $timezone ) {
			$timezone = 'UTC';
		}
		$tz       = new \DateTimeZone( $timezone );
		$dateTime = \DateTime::createFromFormat( 'Y-m-d', date( 'Y-m-d' ), $tz );

		$prorate_discount = 0;

		if ( 'full' === $upgrade_type ) {
			$chargeable_amount = $selected_membership_amount;
		} elseif ( $selected_membership_amount > $current_membership_amount ) {
			$start_date                          = new \DateTime( $subscription['start_date'], $tz );
			$days_passed                         = $dateTime->diff( $start_date )->format( '%a' );
			$current_membership_duration_in_days = convert_to_days( $current_membership_details['subscription']['value'], $current_membership_details['subscription']['duration'] );
			$price_per_day                       = $current_membership_amount / $current_membership_duration_in_days;
			$prorate_discount                    = round( $current_membership_amount - ( $price_per_day * $days_passed ), 2 );
			$chargeable_amount                   = ( $is_trial ) ? $selected_membership_amount : round( $selected_membership_amount - $prorate_discount, 2 );
		} else {
			$chargeable_amount = $selected_membership_amount;
			$delayed_until     = $subscription['expiry_date'];
			if ( $is_trial ) {
				$expiry_date          = new \DateTime( $subscription['expiry_date'], $tz );
				$trial_in_days        = convert_to_days( $selected_membership_details['trial_data']['value'], $selected_membership_details['trial_data']['duration'] );
				$trial_end_date       = ! empty( $subscription['trial_end_date'] ) ? $subscription['trial_end_date'] : date( 'Y-m-d 00:00:00', strtotime( "+$trial_in_days days" ) );
				$trial_end_date_obj   = new \DateTime( $trial_end_date, $tz );
				$remaining_trial_days = $dateTime->diff( $trial_end_date_obj )->format( '%a' );
				$delayed_until        = $expiry_date->modify( "+$remaining_trial_days days" )->format( 'Y-m-d' );
			}
		}

		return array(
			'status'                       => true,
			'chargeable_amount'            => $chargeable_amount,
			'prorate_discount'             => $prorate_discount,
			'remaining_subscription_value' => $remaining_subscription_value,
			'delayed_until'                => $delayed_until,
		);
	}

	/**
	 * Fetch upgrade paths for selected memberships in the group.
	 *
	 * @param string $memberships Membership List.
	 * @param string $upgrade_process Upgrade Process.
	 */
	public function fetch_upgrade_paths( $memberships, $upgrade_process = 'automatic' ) {
		$upgrade_type = 'full';

		if ( 'automatic' === $upgrade_process ) {
			$paths = array();
			foreach ( $memberships as $current ) {
				if ( empty( $current['ID'] ) ) {
					continue;
				}

				$current_id     = (int) $current['ID'];
				$current_meta   = isset( $current['meta_value'] ) ? $current['meta_value'] : array();
				$current_amount = isset( $current_meta['amount'] ) ? (float) $current_meta['amount'] : 0.0;

				$options = array();
				foreach ( $memberships as $target ) {
					if ( empty( $target['ID'] ) ) {
						continue;
					}

					$target_id = (int) $target['ID'];

					if ( $target_id === $current_id ) {
						continue;
					}

					$target_meta   = isset( $target['meta_value'] ) ? $target['meta_value'] : array();
					$target_amount = isset( $target_meta['amount'] ) ? (float) $target_meta['amount'] : 0.0;

					$target_membership_detais = $this->membership_service->prepare_single_membership_data( $this->membership_repository->get_single_membership_by_ID( $target_id ) );
					$target_label             = $target_membership_detais['post_title'];

					if ( $target_amount <= $current_amount ) {
						continue;
					}

					$chargeable = $this->calculate_chargeable_amount(
						$target_amount,
						$current_amount,
						$upgrade_type
					);

					$options[] = array(
						'membership_id'     => $target_id,
						'label'             => $target_label,
						'chargeable_amount' => (float) $chargeable,
						'target_amount'     => (float) $target_amount,
						'current_amount'    => (float) $current_amount,
					);
				}

				usort(
					$options,
					function ( $a, $b ) {
						if ( $a['chargeable_amount'] < $b['chargeable_amount'] ) {
							return -1;
						}
						if ( $a['chargeable_amount'] > $b['chargeable_amount'] ) {
							return 1;
						}
						if ( $a['target_amount'] < $b['target_amount'] ) {
							return -1;
						}
						if ( $a['target_amount'] > $b['target_amount'] ) {
							return 1;
						}

						return (int) $a['membership_id'] <=> (int) $b['membership_id'];
					}
				);

				$paths[ $current_id ] = $options;
			}

			uasort(
				$paths,
				function ( $a, $b ) {
					$count_a = is_array( $a ) ? count( $a ) : 0;
					$count_b = is_array( $b ) ? count( $b ) : 0;

					if ( $count_a < $count_b ) {
						return 1;
					}
					if ( $count_a > $count_b ) {
						return -1;
					}

					return 0;
				}
			);

			uasort(
				$paths,
				function ( $a, $b ) {
					$count_a = is_array( $a ) ? count( $a ) : 0;
					$count_b = is_array( $b ) ? count( $b ) : 0;

					if ( $count_a < $count_b ) {
						return 1;
					}
					if ( $count_a > $count_b ) {
						return -1;
					}

					return 0;
				}
			);

			return $paths;
		} else {
			$paths     = array();
			$to_remove = array();

			foreach ( $memberships as $current ) {
				if ( empty( $current['ID'] ) ) {
					continue;
				}

				array_push( $to_remove, $current['ID'] );

				$current_id     = (int) $current['ID'];
				$current_meta   = isset( $current['meta_value'] ) ? $current['meta_value'] : array();
				$current_amount = isset( $current_meta['amount'] ) ? (float) $current_meta['amount'] : 0.0;

				$options = array();

				foreach ( $memberships as $target ) {

					if ( empty( $target['ID'] ) ) {
						continue;
					}

					if ( in_array( $target['ID'], $to_remove ) ) {
						continue;
					}

					$target_id = (int) $target['ID'];

					if ( $target_id === $current_id ) {
						continue;
					}

					$target_meta   = isset( $target['meta_value'] ) ? $target['meta_value'] : array();
					$target_amount = isset( $target_meta['amount'] ) ? (float) $target_meta['amount'] : 0.0;

					$target_membership_detais = $this->membership_service->prepare_single_membership_data( $this->membership_repository->get_single_membership_by_ID( $target_id ) );
					$target_label             = $target_membership_detais['post_title'];

					$options[] = array(
						'membership_id'  => $target_id,
						'label'          => $target_label,
						'target_amount'  => (float) $target_amount,
						'current_amount' => (float) $current_amount,
					);
				}

				$paths[ $current_id ] = $options;
			}

			return $paths;
		}

		return array();
	}

	/**
	 * Build upgrade order html for user.
	 *
	 * @param array $upgrade_paths Upgrade Paths for the memberships inside the group.
	 */
	public function build_upgrade_order( $upgrade_paths ) {

		if ( empty( $upgrade_paths ) ) {
			return '';
		}

		// Stored / computed paths are low → high; UI shows highest at top.
		$upgrade_paths = array_reverse( $upgrade_paths, true );

		ob_start();
		foreach ( $upgrade_paths as $membership_id => $path ) {
			$membership_details = $this->membership_service->prepare_single_membership_data(
				$this->membership_repository->get_single_membership_by_ID( $membership_id )
			);

			$current_label   = $membership_details['post_title'];
			$membership_meta = isset( $membership_details['meta_value'] ) ? $membership_details['meta_value'] : array();
			$target_amount   = isset( $membership_meta['amount'] ) ? (float) $membership_meta['amount'] : 0.0;

			$currencies = ur_payment_integration_get_currencies();
			$currency   = get_option( 'user_registration_payment_currency', 'USD' );

			$symbol = $currencies[ $currency ]['symbol'];
			$amount = ( ! empty( $currencies[ $currency ]['symbol_pos'] ) && 'left' === $currencies[ $currency ]['symbol_pos'] ) ? $symbol . number_format( $target_amount, 2 ) : number_format( $target_amount, 2 ) . $symbol;

			$current_term = '';

			$duration       = $membership_meta['subscription']['duration'] ?? '';
			$duration_value = trim( absint( $membership_meta['subscription']['value'] ?? 0 ) > 1 ?? '' );

			if ( ! empty( $duration ) ) {
				$duration     = ! empty( $duration_value ) ? $duration_value . ' ' . $duration : $duration;
				$current_term = 'subscription' === $membership_meta['type'] ? $amount . ' / ' . $duration : $amount;
			} else {
				$current_term = $amount ?? '';
			}

			?>
			<li class="ur-sortable-item" data-id="<?php echo esc_attr( $membership_id ); ?>">
				<span class="ur-drag-handle">
					<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
						<path d="M7 12c0-1.227.985-2.222 2.2-2.222 1.215 0 2.2.995 2.2 2.222a2.211 2.211 0 0 1-2.2 2.222C7.985 14.222 7 13.227 7 12Zm0-7.778C7 2.995 7.985 2 9.2 2c1.215 0 2.2.995 2.2 2.222a2.211 2.211 0 0 1-2.2 2.222A2.21 2.21 0 0 1 7 4.222Zm0 15.556c0-1.227.985-2.222 2.2-2.222 1.215 0 2.2.994 2.2 2.222A2.211 2.211 0 0 1 9.2 22C7.985 22 7 21.005 7 19.778ZM13.6 12c0-1.227.985-2.222 2.2-2.222 1.215 0 2.2.995 2.2 2.222a2.211 2.211 0 0 1-2.2 2.222c-1.215 0-2.2-.995-2.2-2.222Zm0-7.778C13.6 2.995 14.585 2 15.8 2c1.215 0 2.2.995 2.2 2.222a2.211 2.211 0 0 1-2.2 2.222 2.21 2.21 0 0 1-2.2-2.222Zm0 15.556c0-1.227.985-2.222 2.2-2.222 1.215 0 2.2.994 2.2 2.222A2.211 2.211 0 0 1 15.8 22c-1.215 0-2.2-.995-2.2-2.222Z"></path>
					</svg>
				</span>
				<div class="ur-drag-label">
					<div class="ur-item-label" style="font-weight: 500;"><?php echo esc_html( $current_label ); ?></div>
					<?php if ( ! empty( $current_term ) ) : ?>
					<div class="ur-item-label"><?php echo esc_html( $current_term ); ?></div>
					<?php endif; ?>
				</div>
			</li>
			<?php
		}
		return ob_get_clean();
	}

	/**
	 * Get upgrade details
	 */
	public function get_upgrade_details( $membership_details ) {
		$membership_group_repository = new MembershipGroupRepository();
		$membership_group_service    = new MembershipGroupService();
		$membership_id               = $membership_details['ID'];
		$group_details               = $membership_group_repository->get_membership_group_by_membership_id( $membership_details['ID'] );

		$upgrade_details = array();

		if ( ! empty( $group_details ) ) {
			if ( $membership_group_service->check_if_upgrade_allowed( $group_details['ID'] ) ) {
				if ( isset( $group_details['mode'] ) && 'upgrade' === $group_details['mode'] ) {
					if ( isset( $group_details['upgrade_path'] ) && '' !== $group_details['upgrade_path'] ) {
						$upgrade_details['upgrade_type'] = $group_details['upgrade_type'] ?? '';
						$upgrade_paths                   = json_decode( $group_details['upgrade_path'], true );

						if ( isset( $upgrade_paths[ $membership_id ] ) && ! empty( $upgrade_paths[ $membership_id ] ) ) {
							$upgrade_details['upgrade_path'] = $upgrade_paths;
						}
					}
				} elseif ( ! isset( $group_details['mode'] ) || ( isset( $group_details['mode'] ) && empty( $group_details['mode'] ) ) ) {
					$upgrade_details = $membership_details['upgrade_settings'];
				}
			} else {
				$upgrade_details = $membership_details['upgrade_settings'];
			}
		} else {
			$upgrade_details = $membership_details['upgrade_settings'];
		}

		return $upgrade_details;
	}
}
