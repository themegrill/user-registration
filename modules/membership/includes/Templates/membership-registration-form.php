<div class="notice-container">
	<div class="notice_red">
		<span class="notice_message"></span>
		<span class="close_notice">&times;</span>
	</div>

	<?php
		// Determine redirect URL for membership registration (e.g., form setting / thank you page).
	if ( function_exists( 'ur_get_form_redirect_url' ) ) {
		$membership_redirect_url = ur_get_form_redirect_url( $form_id );
	} else {
		$membership_redirect_url = '';
	}
		// Avoid deprecated notice: ensure this is always a string before passing to esc_url().
	if ( null === $membership_redirect_url ) {
		$membership_redirect_url = '';
	}
	?>
	<input type="hidden" id="urm-redirect-url" name="ur-redirect-url"
			value="<?php echo esc_url( $membership_redirect_url ); ?>"/>
</div>
<?php

use GeoIp2\WebService\Client;
use WPEverest\URMembership\Local_Currency\Admin\CoreFunctions;
use WPEverest\URMembership\Local_Currency\Admin\Api;

$base_currency = get_option( 'user_registration_payment_currency', 'USD' );

$is_coupon_addon_activated        = ur_check_module_activation( 'coupon' );
$is_tax_calculation_enabled       = ur_check_module_activation( 'taxes' ) && get_option( 'user_registration_tax_calculation_during_checkout', false );
$is_team_addon_activated          = UR_PRO_ACTIVE && ur_check_module_activation( 'team' );
$membership_ids_link_with_coupons = array();
if ( $is_coupon_addon_activated && function_exists( 'ur_get_membership_ids_link_with_coupons' ) ) :
	$membership_ids_link_with_coupons = ur_get_membership_ids_link_with_coupons();
endif;

// Hide the whole field when the only active plan is free, keeping its inputs in the DOM to submit.
$always_show_membership_field = true;
$single_plan                  = null;
$hide_membership_field        = false;

if ( ! empty( $memberships ) ) {
	if ( is_user_logged_in() ) {
		$membership_service = new WPEverest\URMembership\Admin\Services\MembershipService();
		$fetched_data       = $membership_service->fetch_membership_details_from_intended_actions( $_GET );
		if ( isset( $fetched_data['status'] ) && $fetched_data['status'] ) {
			$memberships = $fetched_data['memberships'] ?? array();
		}
	} else {
		// Checkout page for user registering into the site.
		$memberships = apply_filters( 'user_registration_membership_lists', $memberships );
	}

	if ( isset( $attributes['always_show_membership_field'] ) ) {
		$always_show_membership_field = ur_string_to_bool( $attributes['always_show_membership_field'] );
	} elseif ( ! empty( $attributes['form_id'] ) && function_exists( 'ur_get_form_field_data' ) ) {
		$form_id_raw = $attributes['form_id'];
		$form_id_int = is_array( $form_id_raw ) ? ( ! empty( $form_id_raw ) ? absint( reset( $form_id_raw ) ) : 0 ) : absint( $form_id_raw );
		$form_fields = $form_id_int > 0 ? ur_get_form_field_data( $form_id_int ) : array();
		foreach ( (array) $form_fields as $field ) {
			if ( isset( $field->field_key, $field->advance_setting->always_show_membership_field ) && 'membership' === $field->field_key ) {
				$always_show_membership_field = ur_string_to_bool( $field->advance_setting->always_show_membership_field );
				break;
			}
		}
	}

	// A lone plan of any type is auto-selected and rendered as a static summary, without a radio.
	$single_plan = 1 === count( $memberships ) ? reset( $memberships ) : null;

	// Only a lone free plan can hide the field entirely.
	$is_single_free_plan   = null !== $single_plan && 'free' === ( $single_plan['type'] ?? 'free' );
	$hide_membership_field = ! $always_show_membership_field && $is_single_free_plan;
}
?>
<!--user registration section-->
<div id="ur-membership-registration" class="ur_membership_registration_container ur-form-container"<?php echo $hide_membership_field ? ' style="display:none;"' : ''; ?>>

	<!--	membership-->
	<div id="urm-membership-list" class="ur_membership_frontend_input_container radio">

		<label
			class="ur-label ur_membership_input_label required"><?php echo esc_html( $attributes['label'] ); ?>
			<abbr class="required" title="required">*</abbr>
			<?php

			if ( ! empty( $attributes['tooltip'] ) && ur_string_to_bool( $attributes['tooltip'] ) !== false ) :

				$tooltip = esc_html( $attributes['tooltip_message'] );
				?>
				<span class="ur-portal-tooltip tooltipstered" data-tip="<?php echo $tooltip; ?>"></span>
			<?php endif ?>
		</label>
		<span class="description">
			<?php

			echo esc_html( $attributes['description'] );
			?>
		</span>
		<?php
		if ( is_plugin_active( 'user-registration-pro/user-registration.php' ) && ur_check_module_activation( 'local-currency' ) ) {
			$pricing_zone       = CoreFunctions::ur_get_all_pricing_zone_data();
			$switch_currency    = ur_string_to_bool( get_option( 'user_registration_switch_local_currency_option', 0 ) );
			$enable_geolocation = ur_string_to_bool( get_option( 'user_registration_local_currency_by_geolocation', '0' ) );

			$currency_data = Api::ur_get_local_currency_by_geolocation( $enable_geolocation );

			$local_currency_by_country = $currency_data['local_currency'];
			$pricing_zone_by_country   = $currency_data['pricing_zone'];
			$country_code              = $currency_data['country_code'];

			if ( $switch_currency ) :
				?>
			<label
				class="ur-label ur_membership_local_currency"><?php echo __( 'Switch Currency', 'user-registration' ); ?></label>
				<select id="ur-local-currency-switch-currency" name="ur_local_currency_switch_currency">
					<?php

					echo '<option value="' . $base_currency . '">' . ur_get_currency_name_by_key( $base_currency ) . '</option>';
					foreach ( $pricing_zone as $key => $zone ) {
						if ( empty( $zone['meta']['ur_local_currency'] ) ) {
							continue;
						}

						$currency_code = $zone['meta']['ur_local_currency'][0];

						echo '<option value="' . esc_attr( $currency_code ) . '" ' .
							selected( $local_currency_by_country, $currency_code, false ) . '>' .
							esc_html( ur_get_currency_name_by_key( $currency_code ) ) .
						'</option>';
					}
					?>
				</select>
				<?php
			endif;
		}
		if ( ! empty( $memberships ) ) :
			foreach ( $memberships as $m => $membership ) :
				$local_currency_details = array();
				$enabled_zones          = array();
				if (
					is_plugin_active( 'user-registration-pro/user-registration.php' ) &&
					ur_check_module_activation( 'local-currency' )
				) {
					$local_currency_details = CoreFunctions::ur_get_local_currency_details_for_membership( $membership['ID'] );

					if ( ! empty( $local_currency_details['zones'] ) && is_array( $local_currency_details['zones'] ) ) {
						foreach ( $local_currency_details['zones'] as $zone_id => $zone ) {

							if ( isset( $zone['enable'] ) && (int) $zone['enable'] === 1 ) {
								$ur_local_currency                   = get_post_meta( $zone_id, 'ur_local_currency', true );
								$ur_local_currencies_conversion_type = ! empty( $pricing_zone[ $zone_id ]['meta']['ur_local_currencies_conversion_type'] ) ? $pricing_zone[ $zone_id ]['meta']['ur_local_currencies_conversion_type'] : 'manual';

								$exchange_rates = array();

								if ( 'automatic' == $ur_local_currencies_conversion_type ) {
									$all_exchange_rates = Api::ur_get_exchange_rate();

									if ( ! empty( $all_exchange_rates['base'] ) && $base_currency == $all_exchange_rates['base']
									) {
										$exchange_rates = $all_exchange_rates['rates'];
									}
								}

								$rate = '';

								if ( 'exchange' == $zone['pricing_method'] ) {
									$rate = ( ! empty( $pricing_zone[ $zone_id ]['meta']['ur_local_currencies_exchange_rate'] )
										? $pricing_zone[ $zone_id ]['meta']['ur_local_currencies_exchange_rate']
										: ''
									);

									if ( 'automatic' == $ur_local_currencies_conversion_type && ! empty( $exchange_rates[ $pricing_zone[ $zone_id ]['meta']['ur_local_currency'][0] ] ) ) {
										$rate = $exchange_rates[ $pricing_zone[ $zone_id ]['meta']['ur_local_currency'][0] ];
									}
								}

								if ( isset( $ur_local_currency[0] ) ) {
									$enabled_zones[ $ur_local_currency[0] ] = array(
										'pricing_method' => ! empty( $zone['pricing_method'] ) ? $zone['pricing_method'] : '',
										'rate'           => isset( $zone['manual_price'] ) && '' !== $zone['manual_price']
											? number_format( (float) $zone['manual_price'], 2, '.', '' )
											: $rate,
										'ID'             => absint( $zone_id ),
									);
								}
							}
						}
					}
				}

				$converted_amount = 0;
				$final_period     = 0;

				if ( ! empty( $local_currency_by_country ) && isset( $enabled_zones[ $local_currency_by_country ] ) ) {
					if ( ! empty( $enabled_zones[ $local_currency_by_country ]['pricing_method'] ) && 'manual' == $enabled_zones[ $local_currency_by_country ]['pricing_method'] ) {
						$converted_amount = $enabled_zones[ $local_currency_by_country ]['rate'];
					} else {
						$converted_amount = $membership['amount'] * $enabled_zones[ $local_currency_by_country ]['rate'];
					}

					$converted_amount = number_format( $converted_amount, 2 );

					$period_text  = html_entity_decode( $membership['period'] );
					$every_needle = ' ' . __( 'every', 'user-registration' ) . ' ';
					$every_pos    = strpos( $period_text, $every_needle );
					if ( false !== $every_pos ) {
						$duration = substr( $period_text, $every_pos );
					} else {
						// Legacy slash format: "$200.00 / 2 Weeks".
						$parts    = explode( '/', $period_text, 2 );
						$duration = isset( $parts[1] ) ? ' / ' . trim( $parts[1] ) : '';
					}

					$currency_symbol = ur_get_currency_symbol( $local_currency_by_country );

					$final_period = $currency_symbol . $converted_amount . $duration;
				}

				$trial_label       = '';
				$trial_end_display = '';
				if ( ! empty( $membership['trial_status'] ) && 'on' === $membership['trial_status'] && ! empty( $membership['trial_data'] ) ) {
					$trial_value    = intval( $membership['trial_data']['value'] ?? 0 );
					$trial_duration = sanitize_text_field( $membership['trial_data']['duration'] ?? 'day' );
					if ( $trial_value > 0 ) {
						/* translators: 1: number of trial periods, 2: duration unit (day/week/month/year) */
						$trial_label   = sprintf( esc_html__( '%1$d-%2$s free trial', 'user-registration' ), $trial_value, $trial_duration );
						$interval_map  = array(
							'day'   => 'D',
							'week'  => 'W',
							'month' => 'M',
							'year'  => 'Y',
						);
						$interval_char = $interval_map[ $trial_duration ] ?? 'D';
						try {
							$end_date = new DateTime();
							$end_date->add( new DateInterval( 'P' . $trial_value . $interval_char ) );
							$trial_end_display = $end_date->format( 'M j' );
						} catch ( Exception $e ) {
							$trial_end_display = '';
						}
					}
				}

				$urm_default_pg   = apply_filters( 'user_registration_membership_default_payment_gateway', '' );
				$has_team_pricing = $is_team_addon_activated && ! empty( $membership['team_pricing'] );
				?>
				<label class="ur_membership_input_label ur-label <?php echo $has_team_pricing ? 'ur-has-team-pricing' : 'ur-normal-pricing'; ?><?php echo $single_plan ? ' ur-membership-option-static' : ''; ?>"
						for="ur-membership-select-membership-<?php echo esc_attr( $membership['ID'] ); ?>"
						data-membership-id="<?php echo esc_attr( $membership['ID'] ); ?>">
					<input class="ur_membership_input_class ur_membership_radio_input ur-frontend-field"
							data-key-name="ur-membership-id"
							id="ur-membership-select-membership-<?php echo esc_attr( $membership['ID'] ); ?>"
							type="radio"
							name="urm_membership"
							data-name=<?php echo esc_attr( $attributes['field_name'] ); ?>
							data-label=<?php echo esc_attr( $attributes['type'] ); ?>
							required="required"
							value="<?php echo esc_attr( $membership['ID'] ); ?>"
							data-urm-pg='<?php echo esc_attr( ( $membership['active_payment_gateways'] ?? '' ) ); ?>'
							data-urm-pg-type="<?php echo esc_attr( $membership['type'] ); ?>"
							data-urm-membership-amount="<?php echo esc_attr( $membership['amount'] ); ?>"
							data-urm-pg-calculated-amount="<?php echo esc_attr( $membership['calculated_amount'] ); ?>"
							<?php
							echo isset( $_GET['action'] ) && 'upgrade' === $_GET['action'] && $membership['amount'] > $membership['calculated_amount'] ? 'data-urm-upgrade-type="' . esc_attr__( 'Prorated', 'user-registration' ) . '"' : '';
							?>
							data-urm-default-pg="<?php echo $urm_default_pg; ?>"
							data-urm-local-currency-details = "<?php echo esc_attr( json_encode( $enabled_zones ) ); ?>"
							data-urm-converted-amount = "<?php echo esc_attr( $converted_amount ); ?>"
							data-has-coupon-link="<?php echo esc_attr( in_array( $membership['ID'], $membership_ids_link_with_coupons ) ? 'yes' : 'no' ); ?>"
						<?php echo ( $single_plan || ( isset( $_GET['membership_id'] ) && ! empty( $_GET['membership_id'] ) && $_GET['membership_id'] == $membership['ID'] ) ) ? 'checked' : ''; ?>
							data-local-currency="<?php echo esc_attr( ! empty( $final_period ) ? $local_currency_by_country : '' ); ?>"
							data-zone-id="<?php echo esc_attr( ! empty( $final_period ) && isset( $enabled_zones[ $local_currency_by_country ]['ID'] ) ? $enabled_zones[ $local_currency_by_country ]['ID'] : '' ); ?>"
					>
					<span class="ur-membership-duration ur-membership-name-wrap">
						<span class="ur-membership-title-text"><?php echo esc_html( $membership['title'] ); ?></span>
						<?php if ( ! empty( $trial_label ) ) : ?>
							<span class="ur-membership-trial-label"><?php echo esc_html( $trial_label ); ?></span>
						<?php endif; ?>
					</span>
					<span class="ur-membership-duration ur-membership-period-span ur-membership-period-wrap">
						<span class="ur-membership-period-text"><?php echo esc_html( ! empty( $final_period ) ? $final_period : $membership['period'] ); ?></span>
						<?php if ( ! empty( $trial_end_display ) ) : ?>
							<span class="ur-membership-trial-end">
								<?php
								/* translators: %s: trial end date (e.g. "May 20") */
								printf( esc_html__( 'Trial ends %s', 'user-registration' ), esc_html( $trial_end_display ) );
								?>
							</span>
						<?php endif; ?>
					</span>
				</label>
				<!--	team pricing container-->
				<?php if ( $has_team_pricing ) : ?>
				<div class="urm-team-pricing-container" id="urm-team-pricing-container-<?php echo esc_attr( $membership['ID'] ); ?>" style="display: none;">
					<div class="urm-team-pricing-card">
						<div class="urm-team-pricing-card__body">
							<label class="ur_membership_input_label ur-label ur-team-pricing-label"
								for="ur-membership-select-membership-<?php echo esc_attr( $membership['ID'] ); ?>">
								<input class="ur_membership_input_class ur_membership_radio_input ur-frontend-field"
										data-key-name="ur-membership-id"
										id="ur-membership-select-membership-<?php echo esc_attr( $membership['ID'] ); ?>"
										type="radio"
										name="urm_membership"
										data-name=<?php echo esc_attr( $attributes['field_name'] ); ?>
										data-label=<?php echo esc_attr( $attributes['type'] ); ?>
										required="required"
										value="<?php echo esc_attr( $membership['ID'] ); ?>"
										data-urm-pg='<?php echo esc_attr( ( $membership['active_payment_gateways'] ?? '' ) ); ?>'
										data-urm-pg-type="<?php echo esc_attr( $membership['type'] ); ?>"
										data-urm-pg-calculated-amount="<?php echo esc_attr( $membership['calculated_amount'] ); ?>"
										data-has-coupon-link="<?php echo esc_attr( in_array( $membership['ID'], $membership_ids_link_with_coupons ) ? 'yes' : 'no' ); ?>"
										data-urm-default-pg="<?php echo $urm_default_pg; ?>"
									<?php echo ( $single_plan || ( isset( $_GET['membership_id'] ) && ! empty( $_GET['membership_id'] ) && $_GET['membership_id'] == $membership['ID'] ) ) ? 'checked' : ''; ?>
								>
								<span
									class="ur-membership-duration ur-membership-title ur-membership-name-wrap">
									<span class="ur-membership-title-text"><?php echo esc_html( $membership['title'] ); ?></span>
									<?php if ( ! empty( $trial_label ) ) : ?>
										<span class="ur-membership-trial-label"><?php echo esc_html( $trial_label ); ?></span>
									<?php endif; ?>
								</span>
							</label>
							<div class="urm-team-pricing-details">
								<div style="font-size:16px" class="ur-membership-duration ur-membership-price ur-membership-price-selected ur-membership-period-wrap" data-price="<?php echo esc_attr( $membership['amount'] ); ?>">
									<span class="ur-membership-period-text ur-membership-period-span"><?php echo esc_html( $membership['period'] ); ?></span>
									<?php if ( ! empty( $trial_end_display ) ) : ?>
										<span class="ur-membership-trial-end">
											<?php
											/* translators: %s: trial end date (e.g. "May 20") */
											printf( esc_html__( 'Trial ends %s', 'user-registration' ), esc_html( $trial_end_display ) );
											?>
										</span>
									<?php endif; ?>
								</div>
								<div class="urm-team-pricing-btn">
									<span class="urm-or-separator"><?php echo esc_html__( 'OR', 'user-registration' ); ?></span>
									<div class="urm-team-pricing-tiers">
										<?php
										foreach ( $membership['team_pricing'] as $index => $team ) :
											$size             = 0;
											$price            = 0;
											$min_price        = 0;
											$max_price        = 0;
											$show_seats_input = false;
											$show_tiers       = false;

											$team_plan_type       = $team['team_plan_type'] ?? 'one-time';
											$team_price           = $team['team_price'] ?? 0;
											$team_duration_value  = $team['team_duration_value'] ?? 0;
											$team_duration_period = $team['team_duration_period'] ?? 'week';
											if ( $team_duration_value > 1 ) {
												$team_duration_period = $team_duration_period . 's';
											} elseif ( $team_duration_value == 1 ) {
												$team_duration_value = 'a';
											}
											$per_seat_price = $team['per_seat_price'] ?? 0;
											if ( 'fixed' === $team['seat_model'] ) {
												$size = $team['team_size'];
												if ( 'subscription' === $team_plan_type ) {
													$price = sprintf(
														esc_html__( '%1$s / %2$s %3$s', 'user-registration' ),
														$membership['currency_symbol'] . $team_price,
														$team_duration_value,
														$team_duration_period
													);
												} else {
													$price = $membership['currency_symbol'] . $team_price;
												}
											} else {
												$size = $team['minimum_seats'] . ' - ' . $team['maximum_seats'];
												if ( 'per_seat' === $team['pricing_model'] ) {
													if ( 'subscription' === $team_plan_type ) {
														$price = sprintf(
															esc_html__( '%1$s / seat for %2$s %3$s', 'user-registration' ),
															$membership['currency_symbol'] . $per_seat_price,
															$team_duration_value,
															$team_duration_period
														);
													} else {
														$price = sprintf(
															esc_html__( '%s / seat', 'user-registration' ),
															$membership['currency_symbol'] . $per_seat_price
														);
													}
												} else {
													$tiers = $team['tiers'];
													if ( ! empty( $team['tiers'] ) ) {
														$prices     = array_column( $tiers, 'tier_per_seat_price' );
														$min_price  = min( $prices );
														$max_price  = max( $prices );
														$show_tiers = true;
													}
													$price = $membership['currency_symbol'] . $min_price . ' - ' . $membership['currency_symbol'] . $max_price;
												}

												$show_seats_input = true;
											}

											?>
											<div class="urm-team-pricing-tier"
												data-team="<?php echo esc_attr( $index ); ?>"
												data-seat-model="<?php echo esc_attr( $team['seat_model'] ?? '' ); ?>"
												data-team-size="<?php echo esc_attr( $team['team_size'] ?? '' ); ?>"
												data-fixed-price="<?php echo esc_attr( $team['team_price'] ?? '' ); ?>"
												data-minimum-seats="<?php echo esc_attr( $team['minimum_seats'] ?? '' ); ?>"
												data-maximum-seats="<?php echo esc_attr( $team['maximum_seats'] ?? '' ); ?>"
												data-pricing-model="<?php echo esc_attr( $team['pricing_model'] ?? '' ); ?>"
												data-per-seat-price="<?php echo esc_attr( $team['per_seat_price'] ?? '' ); ?>"
												data-price-tiers="<?php echo esc_attr( wp_json_encode( $team['tiers'] ?? array() ) ); ?>"
												>
												<div class="urm-team-pricing-tier-details">
													<div>
														<p><?php echo esc_html( $team['team_name'] ?? 'Tier' ); ?></p>
														<span class="ur-team-seats">
															<span class="dashicons dashicons-groups"></span>
															<?php
															echo esc_html(
																sprintf(
																	__( '%s seats included', 'user-registration' ),
																	$size
																)
															);
															?>
														</span>
													</div>
													<div style="font-size: 16px;" class="ur-membership-period-wrap">
														<span class="ur-membership-period-text"><?php echo esc_html( $price ); ?></span>
														<?php if ( ! empty( $trial_end_display ) ) : ?>
															<span class="ur-membership-trial-end">
																<?php
																/* translators: %s: trial end date (e.g. "May 20") */
																printf( esc_html__( 'Trial ends %s', 'user-registration' ), esc_html( $trial_end_display ) );
																?>
															</span>
														<?php endif; ?>
													</div>
												</div>
												<?php if ( $show_tiers && ! empty( $tiers ) ) : ?>
													<div class="ur-team-tier-seats-tier" style="margin-top: 15px;display:none">
														<p style="margin-bottom:6px">Select a tier</p>
														<?php
														$tier_radio_name = 'tier_selection_' . esc_attr( $index );
														foreach ( $tiers as $tier_index => $tier ) :
															$tier_seat = sprintf(
																esc_html__( '%1$d - %2$d seats', 'user-registration' ),
																$tier['tier_from'],
																$tier['tier_to']
															);
															if ( 'subscription' === $team_plan_type ) {
																$tier_price = sprintf(
																	esc_html__( '%1$s / seat for %2$s %3$s', 'user-registration' ),
																	$membership['currency_symbol'] . $tier['tier_per_seat_price'],
																	$team_duration_value,
																	$team_duration_period
																);
															} else {
																$tier_price = sprintf(
																	esc_html__( '%s / seat', 'user-registration' ),
																	$membership['currency_symbol'] . $tier['tier_per_seat_price']
																);
															}
															?>
															<label style="display: block; margin-bottom: 10px; cursor: pointer;">
																<input type="radio"
																	name="<?php echo esc_attr( $tier_radio_name ); ?>"
																	value="<?php echo esc_attr( $tier_index ); ?>"
																	class="ur-tier-radio-input"
																	data-tier-from="<?php echo esc_attr( $tier['tier_from'] ); ?>"
																	data-tier-to="<?php echo esc_attr( $tier['tier_to'] ); ?>"
																	data-tier-price="<?php echo esc_attr( $tier['tier_per_seat_price'] ); ?>"
																	style="margin-right: 8px;">
																<span><?php echo esc_html( $tier_seat ) . ' : ' . esc_html( $tier_price ); ?></span>
															</label>
														<?php endforeach; ?>
													</div>
												<?php endif; ?>
												<?php if ( $show_seats_input ) : ?>
													<div class="ur-team-tier-seats-wrapper" style="display:none;">
														<hr>
														<div class="ur-team-tier-seats-input-wrapper">
															<label style="width:100%;margin-bottom:0"><?php esc_html_e( 'Number of seats', 'user-registration' ); ?></label>
															<input type="number" name="no_of_seats" placeholder="<?php esc_attr_e( 'No. of seats', 'user-registration' ); ?>" class="ur-team-tier-seats-input" min="<?php esc_attr_e( $team['minimum_seats'] ); ?>" value="<?php esc_attr_e( $team['minimum_seats'] ); ?>" max="<?php esc_attr_e( $team['maximum_seats'] ); ?>">
														</div>
													</div>
												<?php endif; ?>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>
				<?php
			endforeach;
		else :
			$message = wp_kses_post( apply_filters( 'user_registration_membership_no_membership_message', __( 'No memberships found to display.', 'user-registration' ) ) );
			echo '<label data-form-id="' . absint( $form_id ) . '"  class="user-registration-error no-membership">' . $message . '</label>';
		endif;
		?>
		<span id="membership-input-notice">
		</span>
	</div>
	<!--	coupon container-->
	<?php

	if ( $is_coupon_addon_activated ) :
		?>
		<div class="ur_membership_frontend_input_container urm_hidden_payment_container urm-d-none"
			id="ur_coupon_container">

			<label class="ur_membership_input_label ur-label" for="ur-membership-coupon">
				<?php echo esc_html__( 'Coupon', 'user-registration' ); ?>
			</label>
			<div class="coupon-input-area">
				<div class="input_with_clear_btn">
					<input class="ur_membership_input_class"
							data-key-name="<?php echo esc_html__( 'coupon', 'user-registration' ); ?>"
							id="ur-membership-coupon"
							type="text"
							placeholder="<?php echo esc_html__( 'Coupon', 'user-registration' ); ?>"
							name="urm_coupon"
					>
					<span class="ur_clear_coupon">x</span>
				</div>

				<button type="button"
						class="urm_apply_coupon membership-primary-btn"><?php echo esc_html__( 'Apply Coupon', 'user-registration' ); ?></button>
			</div>
			<span id="coupon-validation-error" class="notice_red"></span>
		</div>
		<?php
	endif;
	?>

	<!--	total container-->
	<div id="urm-total_container"
		class="ur_membership_frontend_input_container urm-d-none urm_hidden_payment_container">
		<div class="urm-membership-proration-discount-value urm-d-none">
			<label class="ur_membership_input_label ur-label"
			for="ur-membership-proration-discount"><?php echo esc_html__( 'Proration Discount', 'user-registration' ); ?></label>
			<span class="ur_membership_input_class"
			id="ur-membership-proration-discount"
			data-key-name="<?php echo esc_html__( 'Proration Discount', 'user-registration' ); ?>"
			disabled
			>
			<?php echo ceil( 0 ); ?>
		</span>
		</div>
		<?php if ( $is_coupon_addon_activated ) : ?>
			<div class="urm-membership-coupons-value" style="display:none;">
				<label class="ur_membership_input_label ur-label"
				for="ur-membership-coupons"><?php echo esc_html__( 'Coupons', 'user-registration' ); ?></label>
				<span class="ur_membership_input_class"
				id="ur-membership-coupons"
				data-key-name="<?php echo esc_html__( 'Coupons', 'user-registration' ); ?>"
				disabled
				>
				<?php echo ceil( 0 ); ?>
			</span>
		</div>
		<?php endif; ?>
		<?php if ( $is_coupon_addon_activated || $is_tax_calculation_enabled ) : ?>
			<hr class="ur_membership_divider urm-pre-subtotal-divider" style="display:none;">
			<div class="urm-membership-sub-total-value" style="display:none;">
				<label class="ur_membership_input_label ur-label"
				for="ur-membership-subtotal"><?php echo esc_html__( 'Sub Total', 'user-registration' ); ?></label>
				<span class="ur_membership_input_class"
				id="ur-membership-subtotal"
				data-key-name="<?php echo esc_html__( 'Sub Total', 'user-registration' ); ?>"
				disabled
				>
				<?php echo ceil( 0 ); ?>
			</span>
		</div>
		<?php endif; ?>
		<?php if ( $is_tax_calculation_enabled ) : ?>
			<div class="urm-membership-tax-value" style="display:none;">
				<label class="ur_membership_input_label ur-label"
				for="ur-membership-tax"><?php echo esc_html__( 'Tax', 'user-registration' ); ?></label>
				<span class="ur_membership_input_class"
				id="ur-membership-tax"
				data-key-name="<?php echo esc_html__( 'Tax', 'user-registration' ); ?>"
				disabled
				>
				<?php echo ceil( 0 ); ?>
			</span>
		</div>
		<?php endif; ?>
		<?php if ( $is_coupon_addon_activated || $is_tax_calculation_enabled ) : ?>
			<hr class="ur_membership_divider urm-pre-total-divider" style="display:none;">
		<?php endif; ?>
		<div class="urm-membership-total-value">
			<label class="ur_membership_input_label ur-label"
					for="ur-membership-total"><?php echo apply_filters( 'user_registration_membership_subscription_payment_gateway_total', esc_html__( 'Total', 'user-registration' ) ); ?></label>
			<span class="ur_membership_input_class"
					id="ur-membership-total"
					data-key-name="<?php echo apply_filters( 'user_registration_membership_subscription_payment_gateway_total', esc_html__( 'Total', 'user-registration' ) ); ?>"
					disabled
			>
				<?php echo ceil( 0 ); ?>
			</span>
		</div>
		<span id="total-input-notice">
		</span>
	</div>

	<!--	payment gateway container -->
	<div
		class="ur_membership_frontend_input_container urm_hidden_payment_container ur_payment_gateway_container urm-d-none">
		<hr class="ur_membership_divider">
		<span
			class="ur_membership_input_label ur-label required"><?php echo apply_filters( 'user_registration_membership_subscription_payment_gateway_title', esc_html__( 'Select Payment Gateway', 'user-registration' ) ); ?>
		</span>
		<div id="payment-gateway-body" class="ur_membership_frontend_input_container">
			<div class="ur-membership-payment-gateway-lists">
				<?php
				$width_map = array(
					'paypal' => '70px',
					'stripe' => '50px',
					'bank'   => '40px',
				);
				foreach ( get_option( 'ur_membership_payment_gateways' ) as $g => $gateway ) :
					?>
					<label class="ur_membership_input_label ur-label"
							for="ur-membership-<?php echo esc_attr( strtolower( $g ) ); ?>">
						<input class="ur_membership_input_class pg-list"
								data-key-name="ur-payment-method"
								id="ur-membership-<?php echo esc_attr( strtolower( $g ) ); ?>"
								type="radio"
								name="urm_payment_method"
								required
								value="<?php echo esc_attr( strtolower( $g ) ); ?>"
							<?php echo 0 === $g ? 'checked' : ''; ?>
						>
						<span class="ur-membership-duration">
						<img
							src="<?php echo esc_url( apply_filters( 'user_registration_membership_payment_gateway_logo_url', plugins_url( 'assets/images/settings-icons/membership-field/' . strtolower( $g ) . '-logo.png', UR_PLUGIN_FILE ), $g, $gateway ) ); ?>"
							alt="<?php echo esc_attr( $gateway ); ?>"
							class="ur-membership-payment-gateway-logo"
							width="<?php echo isset( $width_map[ strtolower( $g ) ] ) ? $width_map[ strtolower( $g ) ] : '60px'; ?>"
						/>
					</span>
					</label>
				<?php endforeach; ?>
			</div>
			<span id="payment-gateway-notice" class="notice_red"></span>
		</div>
		<?php
		$show_bank_details_on_form = false;
		if ( isset( $attributes['user_registration_show_bank_details_on_form'] ) ) {
			$show_bank_details_on_form = ur_string_to_bool( $attributes['user_registration_show_bank_details_on_form'] );
		} elseif ( ! empty( $attributes['form_id'] ) && function_exists( 'ur_get_form_field_data' ) ) {
			$form_id_raw = $attributes['form_id'];
			$form_id_int = is_array( $form_id_raw ) ? ( ! empty( $form_id_raw ) ? absint( reset( $form_id_raw ) ) : 0 ) : absint( $form_id_raw );
			$form_fields = $form_id_int > 0 ? ur_get_form_field_data( $form_id_int ) : array();
			foreach ( (array) $form_fields as $field ) {
				if ( isset( $field->field_key, $field->general_setting->user_registration_show_bank_details_on_form ) && 'membership' === $field->field_key ) {
					$show_bank_details_on_form = ur_string_to_bool( $field->general_setting->user_registration_show_bank_details_on_form );
					break;
				}
			}
		}

		$bank_details = get_option( 'user_registration_global_bank_details', '' );
		if ( ! empty( $bank_details ) && $show_bank_details_on_form ) :
			?>
			<div id="ur-bank-details-container" class="ur-bank-details" style="display:none;">
				<p class="ur-bank-details-title"><?php esc_html_e( 'Bank Details :', 'user-registration' ); ?></p>
				<?php echo wp_kses_post( $bank_details ); ?>
			</div>
		<?php endif; ?>
	</div>
	<div class="ur_membership_frontend_input_container">
		<div class="stripe-container urm-d-none">
			<button type="button" class="stripe-card-indicator ur-stripe-element-selected"
					id="credit_card"><?php echo esc_html__( 'Credit Card', 'user-registration' ); ?></button>
			<div class="stripe-input-container">
				<div id="card-element">
				</div>
			</div>
		</div>
		<?php
		/**
		 * Fires when payment fields is rendered on membership registration form.
		 *
		 *  This action allows developers to output payment gateway fields
		 *  within the registration form.
		 */
		do_action( 'user_registration_membership_render_payment_field', $form_id );
		?>
	</div>

	<?php

	if ( ! empty( $fetched_data['current_subscription_id'] ) && ! empty( $fetched_data['current_membership_id'] ) ) {
		?>
		<input type="hidden" class="urm_membership_upgrade_data"
				data-current-subscription-id="<?php echo esc_attr( $fetched_data['current_subscription_id'] ); ?>"
				data-current-membership-id="<?php echo esc_attr( $fetched_data['current_membership_id'] ); ?>"/>
		<?php
	}
	?>

</div>

<script type="text/javascript">
	(function( $ ) {
		function urToggleBankDetails() {
			var selectedMethod = $( 'input[name="urm_payment_method"]:checked' ).val();
			var $container     = $( '#ur-bank-details-container' );

			if ( ! $container.length ) {
				return;
			}

			if ( selectedMethod === 'bank' ) {
				$container.show();
			} else {
				$container.hide();
			}
		}

		$( document ).on( 'change', 'input[name="urm_payment_method"]', urToggleBankDetails );
		$( document ).ready( urToggleBankDetails );
	})( jQuery );
</script>
