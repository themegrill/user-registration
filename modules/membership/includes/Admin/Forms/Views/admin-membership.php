<?php
/**
 * Form View: Membership Field.
 *
 * @package UserRegistrationMembership
 */

use WPEverest\URMembership\Admin\Services\ {
	MembershipGroupService,
	MembershipService
};

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


// Get membership listing option.
$membership_list_options = isset( $this->admin_data->general_setting->membership_listing_option )
	&& ! empty( $this->admin_data->general_setting->membership_listing_option )
	? $this->admin_data->general_setting->membership_listing_option
	: 'all';

// Initialize variables.
$memberships = array();

// Fetch memberships based on listing option.
if ( 'group' === $membership_list_options ) {
	$membership_group_service = new MembershipGroupService();
	$default_group            = isset( $this->field_defaults['default_group'] )
								&& ! empty( $this->field_defaults['default_group'] )
		? $this->field_defaults['default_group']
		: 0;
	$selected_group_id        = isset( $this->admin_data->general_setting->membership_group )
								&& ! empty( $this->admin_data->general_setting->membership_group )
		? $this->admin_data->general_setting->membership_group
		: $default_group;
	$selected_group_id        = trim( $selected_group_id );
	$group_status             = false;

	if ( ! empty( $selected_group_id ) ) {
		$group        = $membership_group_service->get_membership_group_by_id( $selected_group_id );
		$content      = isset( $group['post_content'] )
			? json_decode( wp_unslash( $group['post_content'] ), true )
			: array();
		$group_status = isset( $content['status'] )
			? ur_string_to_bool( $content['status'] )
			: false;

		if ( $group_status ) {
			$memberships = $membership_group_service->get_group_memberships( $selected_group_id );
		}
	}
} elseif ( 'selected' === $membership_list_options ) {
	$membership_service = new MembershipService();
	$all_memberships    = $membership_service->list_active_memberships();
	$selected_ids       = isset( $this->admin_data->general_setting->membership_active_memberships )
		? $this->admin_data->general_setting->membership_active_memberships
		: array();
	$selected_ids       = is_array( $selected_ids ) ? $selected_ids : (array) maybe_unserialize( $selected_ids );
	$selected_ids       = array_filter( array_map( 'absint', $selected_ids ) );
	$memberships        = array_filter(
		$all_memberships,
		function ( $m ) use ( $selected_ids ) {
			$id = isset( $m['ID'] ) ? (int) $m['ID'] : ( isset( $m['id'] ) ? (int) $m['id'] : 0 );
			return $id && in_array( $id, $selected_ids, true );
		}
	);
	$memberships        = array_values( $memberships );
} else {
	$membership_service = new MembershipService();
	$memberships        = $membership_service->list_active_memberships();
}

// Set empty label style based on whether memberships array is empty
$empty_label_style = empty( $memberships ) ? '' : " style='display:none;'";

// Get currency configuration.
$currency   = get_option( 'user_registration_payment_currency', 'USD' );
$currencies = ur_payment_integration_get_currencies();
$symbol     = isset( $currencies[ $currency ]['symbol'] )
	? $currencies[ $currency ]['symbol']
	: '$';

// Get payment gateway configuration.
$payment_gateways = get_option(
	'ur_membership_payment_gateways',
	array(
		'paypal' => __( 'PayPal', 'user-registration' ),
		'stripe' => __( 'Stripe', 'user-registration' ),
		'bank'   => __( 'Bank', 'user-registration' ),
	)
);

// Map payment gateway keys to their image filenames.
$gateway_images = array(
	'paypal'    => 'paypal-logo.png',
	'stripe'    => 'stripe-logo.png',
	'bank'      => 'bank-logo.png',
	'authorize' => 'authorize-logo.png',
	'mollie'    => 'mollie-logo.png',
);

// Get plugin URL for images.
$plugin_url = UR()->plugin_url();

// Get field label.
$field_label = esc_html( $this->get_general_setting_data( 'label' ) );

// A lone plan of any type is auto-selected and rendered without a selectable radio.
$memberships_count = count( $memberships );
$is_single_plan    = 1 === $memberships_count;

?>
<div class="ur-input-type-select ur-admin-template">
	<div class="ur-label">
		<label>
			<?php echo $field_label; ?>
			<span style="color:red">*</span>
		</label>

	</div>
	<div class="ur-field" data-field-key="membership">
		<span class="empty-urmg-label"<?php echo $empty_label_style; ?>>
			<?php esc_html_e( 'No memberships found. Please create one or more memberships.', 'user-registration' ); ?>
		</span>
		<span class="urmg-loader"></span>
		<div class="urmg-container">
			<?php if ( ! empty( $memberships ) ) : ?>
				<div class="urmg-membership-plans">
					<?php foreach ( $memberships as $k => $option ) : ?>
						<?php
						// Prepare plan data.
						$plan_id        = esc_attr( trim( $option['ID'] ) );
						$plan_title     = esc_html( trim( $option['title'] ) );
						$plan_period    = isset( $option['period'] ) ? esc_html( $option['period'] ) : '';
						$plan_type      = isset( $option['type'] ) ? esc_attr( $option['type'] ) : 'free';
						$plan_amount    = isset( $option['amount'] ) ? floatval( $option['amount'] ) : 0;
						$is_first       = 0 === $k;
						$selected_class = $is_first ? 'selected' : '';

						// Trial indicator data.
						$admin_trial_label       = '';
						$admin_trial_end_display = '';
						if ( ! empty( $option['trial_status'] ) && 'on' === $option['trial_status'] && ! empty( $option['trial_data'] ) ) {
							$admin_trial_value    = intval( $option['trial_data']['value'] ?? 0 );
							$admin_trial_duration = sanitize_text_field( $option['trial_data']['duration'] ?? 'day' );
							if ( $admin_trial_value > 0 ) {
								$admin_trial_label = sprintf(
									/* translators: 1: trial length number, 2: duration unit e.g. "day" */
									esc_html__( '%1$d-%2$s free trial', 'user-registration' ),
									$admin_trial_value,
									$admin_trial_duration
								);
								$admin_interval_map  = array(
									'day'   => 'D',
									'week'  => 'W',
									'month' => 'M',
									'year'  => 'Y',
								);
								$admin_interval_char = $admin_interval_map[ $admin_trial_duration ] ?? 'D';
								try {
									$admin_end_date = new DateTime();
									$admin_end_date->add( new DateInterval( 'P' . $admin_trial_value . $admin_interval_char ) );
									$admin_trial_end_display = $admin_end_date->format( 'M j' );
								} catch ( Exception $e ) {
									$admin_trial_end_display = '';
								}
							}
						}
						?>
						<div class="urmg-plan-card <?php echo esc_attr( $selected_class ); ?><?php echo $is_single_plan ? ' ur-membership-single-plan' : ''; ?>"
							data-plan-id="<?php echo $plan_id; ?>"
							data-plan-amount="<?php echo esc_attr( $plan_amount ); ?>"
							data-plan-type="<?php echo esc_attr( $plan_type ); ?>">
							<input type="radio"
									name="urm_membership"
									value="<?php echo $plan_id; ?>"
								<?php echo $is_first ? 'checked' : ''; ?>
									disabled/>
							<div class="urmg-plan-header">
								<div>
									<div class="urmg-plan-title"><?php echo $plan_title; ?></div>
									<?php if ( ! empty( $admin_trial_label ) ) : ?>
										<span class="ur-membership-trial-label"><?php echo esc_html( $admin_trial_label ); ?></span>
									<?php endif; ?>
								</div>
								<div class="urmg-plan-price">
									<?php echo $plan_period; ?>
									<?php if ( ! empty( $admin_trial_end_display ) ) : ?>
										<span class="ur-membership-trial-end">
											<?php
											/* translators: %s: trial end date (e.g. "May 20") */
											printf( esc_html__( 'Trial ends %s', 'user-registration' ), esc_html( $admin_trial_end_display ) );
											?>
										</span>
									<?php endif; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<?php
				// Total section.
				$total_amount = esc_html( $symbol . '0.00' );
				?>
				<div class="urmg-total-container">
					<span class="urmg-total-label"><?php esc_html_e( 'Total:', 'user-registration' ); ?></span>
					<span class="urmg-total-amount" id="urmg-total-amount"><?php echo $total_amount; ?></span>
				</div>

				<?php
				// Payment Gateway Selection - only show if there's more than one membership, or if single membership is not free.
				$membership_type         = UR_PRO_ACTIVE ? 'subscription' : 'paid';
				$active_payment_gateways = urm_get_all_active_payment_gateways( $membership_type );
				$show_payment_gateways   = false;

				if ( $memberships_count > 1 ) {
					// If more than one membership, always show payment gateways
					$show_payment_gateways = true;
				} elseif ( $memberships_count === 1 ) {
					// If only one membership, check if it's not free
					$first_plan = reset( $memberships );
					$plan_type  = isset( $first_plan['type'] ) ? $first_plan['type'] : 'free';
					if ( 'free' !== $plan_type ) {
						$show_payment_gateways = true;
					}
				}

				if ( $show_payment_gateways && ! empty( $active_payment_gateways ) ) :
					?>
					<div class="urmg-payment-gateways">
						<label class="urmg-payment-gateways-label">
							<?php esc_html_e( 'Select Payment Gateway', 'user-registration' ); ?>
							<span class="required">*</span>
						</label>
						<div class="urmg-gateway-buttons">
							<?php
							$gateway_index = 0;
							foreach ( $active_payment_gateways as $gateway_key => $gateway_label ) :
								$is_first_gateway       = 0 === $gateway_index;
								$selected_gateway_class = $is_first_gateway ? 'selected' : '';
								$gateway_image_url      = urm_get_gateway_image_url( $gateway_key, $gateway_images, $plugin_url );
								?>
								<label class="urmg-gateway-btn <?php echo esc_attr( $selected_gateway_class ); ?>">
									<input type="radio"
											name="urm_payment_method"
											value="<?php echo esc_attr( $gateway_key ); ?>"
										<?php echo $is_first_gateway ? 'checked' : ''; ?>
											disabled/>
									<?php if ( ! empty( $gateway_image_url ) ) : ?>
										<img src="<?php echo $gateway_image_url; ?>"
											alt="<?php echo esc_attr( $gateway_label ); ?>"
											class="urmg-gateway-icon"/>
									<?php endif; ?>
									<span class="urmg-gateway-label"><?php echo esc_html( $gateway_label ); ?></span>
								</label>
								<?php
								++$gateway_index;
							endforeach;
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</div>
