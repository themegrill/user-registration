<?php
/**
 * Payment Edit View.
 *
 * @package
 */

use WPEverest\URMembership\Admin\Repositories\OrdersRepository;

defined( 'ABSPATH' ) || exit;

$order_id        = isset( $order['order_id'] ) ? $order['order_id'] : 0;
$user_id         = isset( $order['user_id'] ) ? $order['user_id'] : 0;
$is_form_payment = isset( $order['is_form_payment'] ) ? $order['is_form_payment'] : false;

$plan_details = ! empty( $order['plan_details'] ) ? json_decode( $order['plan_details'], true ) : array();
$post_content = isset( $order['post_content'] ) ? json_decode( wp_unslash( $order['post_content'] ), true ) : array();
$trial_status = isset( $order['trial_status'] ) ? $order['trial_status'] : 'off';

$order_repository = new OrdersRepository();
$order_meta_data  = $order_repository->get_order_meta_by_order_id_and_meta_key( $order_id, 'tax_data' );
$tax_data         = ! empty( $order_meta_data['meta_value'] ) ? json_decode( $order_meta_data['meta_value'], true ) : array();
$tax_amount       = ! empty( $tax_data['tax_amount'] ) ? $tax_data['tax_amount'] : 0;

if ( $is_form_payment ) {
	$order['post_id']         = isset( $order['post_id'] ) ? $order['post_id'] : 0;
	$order['subscription_id'] = isset( $order['subscription_id'] ) ? $order['subscription_id'] : 0;
	$order['transaction_id']  = isset( $order['transaction_id'] ) ? $order['transaction_id'] : '';
	$order['payment_method']  = isset( $order['payment_method'] ) ? $order['payment_method'] : '';
	$order['notes']           = isset( $order['notes'] ) ? $order['notes'] : get_user_meta( $user_id, 'ur_payment_notes', true );
	$trial_status             = 'off';
}

$plan_has_trial  = false;
$order_has_trial = false;
$supports_trial  = false;

if ( $team ) {
	$membership_type = ! empty( $team['meta']['urm_team_data']['team_plan_type'] ) ? $team['meta']['urm_team_data']['team_plan_type'] : '';
} else {
	$membership_type = isset( $post_content['type'] ) ? $post_content['type'] : '';
	$plan_has_trial  = isset( $plan_details['trial_status'] ) && 'on' === $plan_details['trial_status'];
	$order_has_trial = isset( $order['trial_status'] ) && 'on' === $order['trial_status'];
	$supports_trial  = ( 'subscription' === $membership_type ) && ( $plan_has_trial || $order_has_trial );
}

$currency   = get_option( 'user_registration_payment_currency', 'USD' );
$currencies = ur_payment_integration_get_currencies();
$symbol     = isset( $currencies[ $currency ]['symbol'] ) ? $currencies[ $currency ]['symbol'] : '$';

$local_currency = $order_repository->get_order_meta_by_order_id_and_meta_key( $order_id, 'local_currency' );

$currency = ! empty( $local_currency['meta_value'] ) ? $local_currency['meta_value'] : $currency;
$symbol   = ur_get_currency_symbol( $currency );

$status_options = array( 'completed', 'pending', 'failed', 'refunded' );

$product_amount = 0;
if ( $team ) {
	if ( isset( $order['billing_amount'] ) ) {
		$product_amount = (float) $order['billing_amount'];
	}
} elseif ( isset( $plan_details['amount'] ) ) {
	$product_amount = (float) $plan_details['amount'];
} elseif ( isset( $order['billing_amount'] ) ) {
	$product_amount = (float) $order['billing_amount'];
} elseif ( isset( $order['total_amount'] ) ) {
	$product_amount = (float) $order['total_amount'];
} elseif ( isset( $order['product_amount'] ) ) {
	$product_amount = (float) $order['product_amount'];
}

$local_currency_converted_amount = $order_repository->get_order_meta_by_order_id_and_meta_key( $order_id, 'local_currency_converted_amount' );

$product_amount = ! empty( $local_currency_converted_amount['meta_value'] ) ? $local_currency_converted_amount['meta_value'] : $product_amount;

$coupon                = ! empty( $order['coupon'] ) ? ur_get_coupon_details( $order['coupon'] ) : null;
$coupon_discount       = 0;
$coupon_discount_type  = 'fixed';
$coupon_discount_value = null;
$proration_discount    = 0;

// Actual paid amount from DB already reflects all discounts (proration + coupon + tax).
$raw_total       = isset( $order['total_amount'] ) ? (float) $order['total_amount'] : 0;
$invoice_pre_tax = max( $raw_total - (float) $tax_amount, 0 );

if ( ! empty( $coupon ) ) {
	if ( isset( $coupon['coupon_discount'] ) && isset( $coupon['coupon_discount_type'] ) ) {
		$coupon_discount_value = (float) $coupon['coupon_discount'];
		$coupon_discount_type  = $coupon['coupon_discount_type'];
	} elseif ( isset( $coupon['discount'] ) ) {
		$coupon_discount_value = (float) $coupon['discount'];
		$coupon_discount_type  = isset( $coupon['discount_type'] ) ? $coupon['discount_type'] : ( isset( $coupon['coupon_discount_type'] ) ? $coupon['coupon_discount_type'] : 'fixed' );
	}
}

if ( null === $coupon_discount_value && ! empty( $order['coupon'] ) && $user_id > 0 ) {
	$user_coupon_discount      = get_user_meta( $user_id, 'ur_coupon_discount', true );
	$user_coupon_discount_type = get_user_meta( $user_id, 'ur_coupon_discount_type', true );

	if ( ! empty( $user_coupon_discount ) ) {
		$coupon_discount_value = (float) $user_coupon_discount;
		$coupon_discount_type  = $user_coupon_discount_type;
	}
}

// Back-calculate proration and coupon separately from the actual paid amount.
// Coupon applies to the post-proration subtotal; proration = plan price − post-proration amount.
if ( null !== $coupon_discount_value && $invoice_pre_tax > 0 ) {
	if ( 'percent' === $coupon_discount_type ) {
		$divisor        = 1 - ( $coupon_discount_value / 100 );
		$post_proration = $divisor > 0 ? round( $invoice_pre_tax / $divisor, 2 ) : $product_amount;
	} else {
		$post_proration = round( $invoice_pre_tax + $coupon_discount_value, 2 );
	}
	$coupon_discount    = round( $post_proration - $invoice_pre_tax, 2 );
	$proration_discount = max( round( $product_amount - $post_proration, 2 ), 0 );
} elseif ( $invoice_pre_tax < $product_amount ) {
	// No coupon but proration exists.
	$proration_discount = round( $product_amount - $invoice_pre_tax, 2 );
}

$items_subtotal  = $product_amount;
$order_total     = $invoice_pre_tax;
$paid_amount     = ( 'on' === $trial_status ) ? 0 : $raw_total;
$paid_amount     = ! empty( $tax_data['total_after_tax'] ) ? (float) $tax_data['total_after_tax'] : $paid_amount;
$recurring_label = '-';
if ( 'subscription' === $membership_type ) {
	if ( $team ) {
		$recurring_label = ! empty( $team['meta']['urm_team_data']['team_duration_period'] ) ? ( 'day' === $team['meta']['urm_team_data']['team_duration_period'] ? __( 'Daily', 'user-registration' ) : ucfirst( $team['meta']['urm_team_data']['team_duration_period'] ) . 'ly' ) : '';
	} elseif ( isset( $plan_details['subscription']['duration'] ) ) {
		$recurring_label = 'day' === $plan_details['subscription']['duration'] ? __( 'Daily', 'user-registration' ) : ucfirst( $plan_details['subscription']['duration'] ) . 'ly';
	} else {
		$recurring_label = __( 'Recurring', 'user-registration' );
	}
}

$user            = get_userdata( $user_id );
$user_avatar_url = get_avatar_url( $user_id );
$first_name      = get_user_meta( $user_id, 'first_name', true );
$last_name       = get_user_meta( $user_id, 'last_name', true );

$user_display_name = __( 'Unknown User', 'user-registration' );
$user_email        = '';

if ( $user ) {
	$user_display_name = $user->user_login;
	$user_email        = $user->user_email;

	if ( $first_name || $last_name ) {
		$user_display_name = trim( $first_name . ' ' . $last_name );
	} elseif ( ! empty( $user->display_name ) ) {
		$user_display_name = $user->display_name;
	} elseif ( ! empty( $user->nickname ) ) {
		$user_display_name = $user->nickname;
	}
}

$order_status = ! empty( $order['status'] ) ? $order['status'] : 'pending';
?>
<div class="ur-admin-page-topnav" id="ur-lists-page-topnav">
	<div class="ur-page-title__wrapper">
		<div class="ur-page-title__wrapper--left">
			<a class="ur-text-muted ur-border-right ur-d-flex ur-mr-2 ur-pl-2 ur-pr-2"
				href="<?php echo esc_attr( admin_url( 'admin.php?page=member-payment-history' ) ); ?>">
				<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none"
					stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1">
					<line x1="19" y1="12" x2="5" y2="12"></line>
					<polyline points="12 19 5 12 12 5"></polyline>
				</svg>
			</a>
			<div class="ur-page-title__wrapper--left-menu">
				<div class="ur-page-title__wrapper--left-menu__items">
					<p>
						<?php
						printf(
							/* translators: %d Order id */
							esc_html__( 'Edit Payment #%d', 'user-registration' ),
							esc_html( $order_id )
						);
						?>
					</p>
				</div>
			</div>
		</div>
		<div class="ur-page-title__wrapper--right">
			<button type="submit" form="ur-payments-edit-form"
				class="button-primary ur-payment-update-btn"><?php esc_html_e( 'Update', 'user-registration' ); ?></button>
		</div>
	</div>
</div>

<div class="ur-membership">
	<div class="ur-membership-tab-contents-wrapper ur-payments">
		<form method="post" id="ur-payments-edit-form" class="ur-payments__form"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ur_membership_edit_order', 'ur_membership_edit_order_nonce' ); ?>
			<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ?: $user_id ); ?>">
			<input type="hidden" name="is_form_payment" value="<?php echo esc_attr( $is_form_payment ? 'true' : 'false' ); ?>">

			<div class="ur-payments__form--left">
				<div class="ur-payments__main-content">
					<div class="ur-payments__main-content-wrapper">
						<div class="ur-payments__item-info">
							<div class="ur-payments__section-header">
								<h3 class="ur-payments__section-title">
									<?php
									$display_id = $is_form_payment ? $user_id : ( $order_id ?: $user_id );
									printf(
										/* translators: %d Order id */
										esc_html__( 'Payment #%d', 'user-registration' ),
										esc_html( $display_id )
									);
									?>
								</h3>
								<div class="ur-payments__badges">
									<span
										class="ur-payments__badge ur-payments__badge--status <?php echo esc_attr( 'ur-payments__badge--status-' . strtolower( $order_status ) ); ?>">
										<?php echo esc_html( ucfirst( $order_status ) ); ?>
									</span>
									<?php if ( 'on' === $trial_status ) : ?>
									<span class="ur-payments__badge ur-payments__badge--trial">
										<?php esc_html_e( 'Trial', 'user-registration' ); ?>
									</span>
									<?php endif; ?>
									<?php if ( ! $is_form_payment && $order_id && ur_check_module_activation( 'pdf-invoice' ) ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ur_admin_download_invoice&order_id=' . $order_id ), 'ur_admin_download_invoice' ) ); ?>"
										class="button">
										<?php esc_html_e( 'Download Invoice', 'user-registration' ); ?>
									</a>
									<?php endif; ?>
								</div>
							</div>
							<div class="ur-payments__section-content">
								<div class="ur-payments__section-content--table-wrapper">
									<table class="ur-payments__table">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Item', 'user-registration' ); ?></th>
												<th><?php esc_html_e( 'Recurring', 'user-registration' ); ?></th>
												<th><?php esc_html_e( 'Price', 'user-registration' ); ?></th>
												<th><?php esc_html_e( 'Qty', 'user-registration' ); ?></th>
												<th><?php esc_html_e( 'Total', 'user-registration' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<tr>
												<td>
													<?php if ( ! $is_form_payment && ! empty( $order['post_id'] ) ) : ?>
													<a href="<?php echo esc_url( admin_url( "admin.php?post_id={$order['post_id']}&action=add_new_membership&page=user-registration-membership" ) ); ?>"
														class="ur-payments__table-item-link">
														<?php
														$item_title = isset( $order['post_title'] ) ? $order['post_title'] : __( 'N/A', 'user-registration' );
														echo esc_html( $item_title );
														?>
													</a>
													<?php else : ?>
														<?php
														$item_title = isset( $order['post_title'] ) ? $order['post_title'] : __( 'N/A', 'user-registration' );
														echo esc_html( $item_title );
														?>
													<?php endif; ?>
												</td>
												<td><?php echo esc_html( $recurring_label ); ?></td>
												<td class="ur-payments__table-price">
													<?php echo esc_html( $symbol . number_format( $product_amount, 2 ) ); ?>
												</td>
												<td><?php esc_html_e( 'x 1', 'user-registration' ); ?></td>
												<td>
													<div class="ur-payments__table-price">
														<?php echo esc_html( $symbol . number_format( $order_total, 2 ) ); ?>
													</div>
													<?php $total_discount = round( $items_subtotal - $order_total, 2 ); ?>
													<?php if ( $total_discount > 0 ) : ?>
													<div class="ur-payments__table-discount">
														<?php echo esc_html( $symbol . number_format( $total_discount, 2 ) . ' ' . __( 'discount', 'user-registration' ) ); ?>
													</div>
													<?php endif; ?>
												</td>
											</tr>
										</tbody>
									</table>
								</div>

								<div class="ur-payments__data">
									<!-- Coupons Section -->
									<?php
									$has_coupon = ! empty( $coupon ) || ! empty( $order['coupon'] );
									if ( $has_coupon ) :
										$coupon_code = isset( $order['coupon'] ) ? $order['coupon'] : '';
										$coupon_id   = isset( $coupon['coupon_id'] ) ? $coupon['coupon_id'] : 0;
										$coupon_url  = admin_url( "admin.php?post_id={$coupon_id}&action=add_new_coupon&page=user-registration-coupons" );
										?>
									<div class="ur-payments__coupon-section">
										<div class="ur-payments__coupon-title">
											<?php esc_html_e( 'Coupon(s)', 'user-registration' ); ?>
										</div>
										<a href="<?php echo esc_url( $coupon_url ); ?>"
											class="ur-payments__coupon-code">
											<?php echo esc_html( $coupon_code ); ?>
										</a>
									</div>
									<?php endif; ?>

									<!-- Summary and Payment Tables -->
									<div class="ur-payments__summary-payment-wrapper">
										<!-- Order Summary Table -->

										<?php
										if ( 'on' !== $trial_status ) {
											?>
										<table class="ur-payments__summary-table">
											<tbody>
												<tr>
													<td class="ur-payments__summary-label">
														<?php esc_html_e( 'Items Subtotal:', 'user-registration' ); ?>
													</td>
													<td width="1%"></td>
													<td class="ur-payments__summary-total">
														<?php echo esc_html( $symbol . number_format( $items_subtotal, 2 ) ); ?>
													</td>
												</tr>
												<?php if ( $proration_discount > 0 ) : ?>
												<tr>
													<td class="ur-payments__summary-label">
														<?php esc_html_e( 'Proration Discount:', 'user-registration' ); ?>
													</td>
													<td width="1%"></td>
													<td class="ur-payments__summary-total">-
														<?php echo esc_html( $symbol . number_format( $proration_discount, 2 ) ); ?>
													</td>
												</tr>
												<?php endif; ?>
												<?php if ( $coupon_discount > 0 ) : ?>
												<tr>
													<td class="ur-payments__summary-label">
														<?php esc_html_e( 'Coupon(s):', 'user-registration' ); ?>
													</td>
													<td width="1%"></td>
													<td class="ur-payments__summary-total">-
														<?php echo esc_html( $symbol . number_format( $coupon_discount, 2 ) ); ?>
													</td>
												</tr>
												<?php endif; ?>
												<tr>
													<td class="ur-payments__summary-label">
														<?php esc_html_e( 'Order Total:', 'user-registration' ); ?>
													</td>
													<td width="1%"></td>
													<td class="ur-payments__summary-total">
														<?php echo esc_html( $symbol . number_format( $order_total, 2 ) ); ?>
													</td>
												</tr>
												<tr>
													<td class="ur-payments__summary-label">
														<?php esc_html_e( 'Tax Amount:', 'user-registration' ); ?>
													</td>
													<td width="1%"></td>
													<td class="ur-payments__summary-total">
														<?php echo esc_html( $symbol . number_format( $tax_amount, 2 ) ); ?>
													</td>
												</tr>
											</tbody>
										</table>
										<?php } ?>

										<!-- Payment Information Table -->
										<table class="ur-payments__payment-table">
											<tbody>
												<tr>
													<td
														class="ur-payments__payment-label ur-payments__payment-label-highlight">
														<?php esc_html_e( 'Paid:', 'user-registration' ); ?><br>
													</td>
													<td width="1%"></td>
													<td class="ur-payments__payment-total">
														<?php
														if ( 'on' === $trial_status ) {

															$paid_amount = 0;
														}

														?>
														<?php echo esc_html( $symbol . number_format( $paid_amount, 2 ) ); ?>
													</td>
												</tr>
												<tr>
													<td>
														<span class="ur-payments__payment-description">
															<?php
															// Format payment date for display.
															$payment_date = '';
															if ( ! empty( $order['created_at'] ) ) {
																$date_format  = get_option( 'date_format' );
																$payment_date = date_i18n( $date_format, strtotime( $order['created_at'] ) );
															}
															echo esc_html( $payment_date );
															?>
														</span>
													</td>
													<td colspan="2"></td>
												</tr>
											</tbody>
										</table>
									</div>
								</div>
							</div>
						</div>
					</div>
					<?php
					$transaction_id  = isset( $order['transaction_id'] ) ? $order['transaction_id'] : '';
					$payment_method  = isset( $order['payment_method'] ) ? $order['payment_method'] : '';
					$subscription_id = isset( $order['subscription_id'] ) ? $order['subscription_id'] : '';
					$environment     = 'live';

					if ( ! empty( $payment_method ) ) {
						$payment_method_lower = strtolower( $payment_method );
						if ( 'stripe' === $payment_method_lower ) {
							$is_test_mode = get_option( 'user_registration_stripe_test_mode', false );
							$environment  = $is_test_mode ? 'sandbox' : 'live';
						} elseif ( 'paypal' === $payment_method_lower ) {
							$paypal_mode = get_option( 'user_registration_global_paypal_mode', 'test' );
							$environment = ( 'test' === $paypal_mode ) ? 'sandbox' : 'live';
						}
					}

					$has_gateway_info = ! empty( $transaction_id ) || ! empty( $payment_method ) || ! empty( $subscription_id );

					if ( $has_gateway_info ) :
						?>
					<div class="ur-payments__main-content-wrapper">
						<div class="ur-payments__section-header">
							<h3 class="ur-payments__section-title">
								<?php esc_html_e( 'Payment Gateway Information', 'user-registration' ); ?>
							</h3>
						</div>
						<div class="ur-payments__section-content">
							<div class="ur-payments__section-column">
								<?php if ( ! empty( $payment_method ) ) : ?>
								<div class="ur-payments__section-item">
									<div class="ur-payments__section-label">
										<?php esc_html_e( 'Gateway', 'user-registration' ); ?>
									</div>
									<div class="ur-payments__section-value">
										<?php echo esc_html( ur_get_payment_gateway_label( $payment_method ) ); ?>
									</div>
								</div>
								<?php endif; ?>
								<?php if ( ! empty( $transaction_id ) ) : ?>
								<div class="ur-payments__section-item">
									<div class="ur-payments__section-label">
										<?php esc_html_e( 'Transaction ID', 'user-registration' ); ?>
									</div>
									<div class="ur-payments__section-id">
										<?php echo esc_html( $transaction_id ); ?>
									</div>
								</div>
								<?php endif; ?>
							</div>
							<div class="ur-payments__section-column">
								<?php if ( ! empty( $payment_method ) ) : ?>
								<div class="ur-payments__section-item">
									<div class="ur-payments__section-label">
										<?php esc_html_e( 'Environment', 'user-registration' ); ?>
									</div>
									<div class="ur-payments__section-value">
										<?php echo esc_html( ucfirst( $environment ) ); ?>
									</div>
								</div>
								<?php endif; ?>
								<?php if ( ! empty( $subscription_id ) ) : ?>
								<div class="ur-payments__section-item">
									<div class="ur-payments__section-label">
										<?php esc_html_e( 'Subscription ID', 'user-registration' ); ?>
									</div>
									<div class="ur-payments__section-id">
										<?php
										// TODO: point to subscription edit page
										$subscription_url = '#';
										?>
										<a href="<?php echo esc_url( $subscription_url ); ?>"
											class="ur-payments__section-link" target="_blank">
											#<?php echo esc_html( $subscription_id ); ?>
										</a>
									</div>
								</div>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="ur-payments__form--right">
				<div class="ur-payments__fields">
					<div class="ur-payments__fields-content">
						<div class="ur-payments__field-row">
							<div class="ur-payments__user">
								<?php echo get_avatar( $user_id, 122, '', '', array( 'class' => 'ur-payments__user-avatar' ) ); ?>
								<div class="ur-payments__user-name">
									<?php echo esc_html( $user_display_name ); ?>
								</div>
								<div class="ur-payments__user-email"><?php echo esc_html( $user_email ); ?></div>
							</div>
						</div>
						<div class="ur-payments__payment-actions">
							<?php
							$member_edit_url = add_query_arg(
								array(
									'action'   => 'edit',
									'user_id'  => $user_id,
									'_wpnonce' => wp_create_nonce( 'bulk-users' ),
								),
								admin_url( 'admin.php?page=user-registration-users&view_user' ),
							);
							?>
							<a class="button action"
								href="<?php echo esc_url( $member_edit_url ); ?>"><?php esc_html_e( 'Edit Member', 'user-registration' ); ?></a>
							<a class="button action delete single-delete-order"
								data-user-id="<?php echo esc_attr( $user_id ); ?>"
								data-order-id="<?php echo esc_attr( $order_id ); ?>"
								href="#"><?php esc_html_e( 'Delete Payment', 'user-registration' ); ?></a>
						</div>
					</div>
				</div>
				<div class="ur-payments__fields">
					<div class="ur-payments__fields-content">
						<div class="ur-payments__field-row">
							<label class="ur-payments__field-label" for="ur-payment-status">
								<?php esc_html_e( 'Status', 'user-registration' ); ?>
							</label>
							<div class="ur-payments__field-input">
								<select name="status" id="ur-payment-status" class="ur-enhanced-select">
									<?php
									$current_status = isset( $order['status'] ) ? $order['status'] : '';
									foreach ( $status_options as $status_option ) :
										?>
									<option value="<?php echo esc_attr( $status_option ); ?>"
										<?php selected( $current_status, $status_option ); ?>>
										<?php echo esc_html( ucfirst( $status_option ) ); ?>
									</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="ur-payments__field-row">
							<label class="ur-payments__field-label" for="ur-payment-created-at">
								<?php esc_html_e( 'Created At', 'user-registration' ); ?>
							</label>
							<div class="ur-payments__field-input">
								<?php
								$created_at_value = '';
								if ( ! empty( $order['created_at'] ) ) {
									$created_at_value = gmdate( 'Y-m-d\TH:i', strtotime( $order['created_at'] ) );
								}
								?>
								<input type="datetime-local" name="created_at" id="ur-payment-created-at"
									value="<?php echo esc_attr( $created_at_value ); ?>" />
								<div class="ur-payments__field-description">
									<?php esc_html_e( 'The date and time when this payment was created.', 'user-registration' ); ?>
								</div>
							</div>
						</div>


					</div>
				</div>
				<?php
					$is_trial_active = $supports_trial && 'on' === $trial_status && ! $is_form_payment;
				if ( $is_trial_active ) :
					$trial_start_date = '';
					$trial_end_date   = '';
					if ( ! empty( $order['trial_start_date'] ) ) {
						$trial_start_date = gmdate( 'Y-m-d', strtotime( $order['trial_start_date'] ) );
					}
					if ( ! empty( $order['trial_end_date'] ) ) {
						$trial_end_date = gmdate( 'Y-m-d', strtotime( $order['trial_end_date'] ) );
					}

					$trial_period = 'N/A';
					if ( isset( $plan_details['trial_data']['value'] ) && isset( $plan_details['trial_data']['duration'] ) ) {
						$trial_value    = $plan_details['trial_data']['value'];
						$trial_duration = $plan_details['trial_data']['duration'];
						$trial_period   = $trial_value . ' ' . $trial_duration . ( $trial_value > 1 ? 's' : '' );
					}
					?>
				<div class="ur-payments__fields">

					<div class="ur-payments__fields-content">
						<div class="ur-payments__field-row">
							<label class="ur-payments__field-label" for="ur-payment-trial-start-date">
								<?php esc_html_e( 'Trial Start Date', 'user-registration' ); ?>
							</label>
							<div class="ur-payments__field-input">
								<input type="date" id="ur-payment-trial-start-date" name="trial_start_date"
									value="<?php echo esc_attr( $trial_start_date ); ?>">
							</div>
						</div>
						<div class="ur-payments__field-row">
							<label class="ur-payments__field-label" for="ur-payment-trial-end-date">
								<?php esc_html_e( 'Trial End Date', 'user-registration' ); ?>
							</label>
							<div class="ur-payments__field-input">
								<input type="date" id="ur-payment-trial-end-date" name="trial_end_date"
									value="<?php echo esc_attr( $trial_end_date ); ?>">
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>
				<div class="ur-payments__fields">
					<div class="ur-payments__field-row">
						<label class="ur-payments__field-label" for="ur-payment-notes">
							<?php esc_html_e( 'Notes', 'user-registration' ); ?>
						</label>
						<div class="ur-payments__field-input">
							<?php
							$order_notes = isset( $order['notes'] ) ? $order['notes'] : '';
							?>
							<textarea name="notes" id="ur-payment-notes"
								rows="4"><?php echo esc_textarea( $order_notes ); ?></textarea>
							<div class="ur-payments__field-description">
								<?php esc_html_e( 'Add any notes or comments about this payment.', 'user-registration' ); ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>
