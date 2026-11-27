<?php
/**
 * My Account page
 *
 * This template can be overridden by copying it to yourtheme/user-registration/myaccount/membership.php.
 *
 * HOWEVER, on occasion UserRegistration will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.wpuserregistration.com/docs/how-to-edit-user-registration-template-files-such-as-login-form/
 * @package UserRegistration/Templates
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( empty( $membership_data ) ) {
	esc_html_e( 'You do not have any records', 'user-registration' );
	return;
}

$current = $page;

$current_url = get_permalink( get_option( 'user_registration_myaccount_page_id' ) ) . 'ur-membership/';
?>
<div class="user-registration-MyAccount-content__body">
	<div class="ur-account-table-container">
		<div class="ur-account-table-wrapper">
			<table class="ur-account-table">
				<thead class="ur-account-table__header">
					<tr class="ur-account-table__row">
						<th class="ur-account-table__cell ur-account-table__header-cell"><?php esc_html_e( 'Membership', 'user-registration' ); ?></th>
						<th class="ur-account-table__cell ur-account-table__header-cell"><?php esc_html_e( 'Terms', 'user-registration' ); ?></th>
						<th class="ur-account-table__cell ur-account-table__header-cell"><?php esc_html_e( 'Status', 'user-registration' ); ?></th>
						<th class="ur-account-table__cell ur-account-table__header-cell"><?php esc_html_e( 'Start Date', 'user-registration' ); ?></th>
						<th class="ur-account-table__cell ur-account-table__header-cell"><?php esc_html_e( 'Next Billing Date', 'user-registration' ); ?></th>
						<th class="ur-account-table__cell ur-account-table__header-cell"><?php esc_html_e( 'Action', 'user-registration' ); ?></th>
					</tr>
				</thead>
				<tbody class="ur-account-table__body">
					<?php
					foreach ( $membership_data as $data ) :
						$subscription_service  = new \WPEverest\URMembership\Admin\Services\SubscriptionService();
						$membership            = isset( $data['membership'] ) ? $data['membership'] : array();
						$is_upgraded           = ! empty( $_GET['is_upgraded'] ) ? absint( ur_string_to_bool( $_GET['is_upgraded'] ) ) : false;
						$message               = ! empty( $_GET['message'] ) ? esc_html( $_GET['message'] ) : '';
						$membership_info       = ( isset( $_GET['info'] ) && ! empty( $_GET['info'] ) ) ? wp_kses_post_deep( $_GET['info'] ) : ( ! empty( $data['bank_data']['bank_data'] ) ? wp_kses_post_deep( $data['bank_data']['bank_data'] ) : '' );
						$is_delayed            = ! empty( $data['delayed_until'] );
						$user_id               = get_current_user_id();
						$is_renewing           = isset( $data['is_renewing'] ) ? $data['is_renewing'] : ur_string_to_bool( get_user_meta( $user_id, 'urm_is_member_renewing', true ) );
						$is_membership_expired = $subscription_service->is_user_membership_expired( $user_id, $membership['subscription_id'] );
						$pending_cancel_date   = get_user_meta( $user_id, 'urm_pending_cancel_' . $membership['subscription_id'], true );
						$team_id               = '';
						$is_user_team_member   = false;
						if ( ! empty( $data['team'] ) && ! empty( $data['team']['meta']['urm_team_data']['team_plan_type'] ) ) {
							$team_id      = $data['team']['ID'];
							$team_members = get_post_meta( $team_id, 'urm_member_ids', true );
							if ( is_array( $team_members ) && in_array( $user_id, $team_members, true ) ) {
								$is_user_team_member = true;
							}
							$membership_type = $data['team']['meta']['urm_team_data']['team_plan_type'];
						} else {
							$membership_type = $membership['post_content']['type'];
						}
						$can_renew     = ! $is_renewing && isset( $membership['post_content']['type'] ) && 'automatic' !== $data['renewal_behaviour'] && 'subscription' == $membership_type;
						$date_to_renew = '';

						if ( 'subscription' == $membership_type ) {
							$start_date    = $data['subscription_data']['start_date'];
							$expiry_date   = $data['subscription_data']['expiry_date'];
							$date_to_renew = urm_get_date_at_percent_interval( $start_date, $expiry_date, apply_filters( 'urm_show_membership_renewal_btn_in_percent', 80 ) ); // keeping this static for now can be changed to a setting in future
						}

						$currency = get_option( 'user_registration_payment_currency', 'USD' );

						$current_url = get_permalink( get_option( 'user_registration_myaccount_page_id' ) ) . 'ur-membership/';

						$orders_repository  = new \WPEverest\URMembership\Admin\Repositories\OrdersRepository();
						$subscription_order = $orders_repository->get_order_by_subscription( $data['subscription_data']['ID'] ?? 0 );
						$order_id           = ! empty( $subscription_order ) && isset( $subscription_order['ID'] ) ? $subscription_order['ID'] : '';

						$order_meta_data = $orders_repository->get_order_meta_by_order_id_and_meta_key( $order_id, 'tax_data' );
						$tax_data        = ! empty( $order_meta_data['meta_value'] ) ? json_decode( $order_meta_data['meta_value'], true ) : array();

						$local_currency = $orders_repository->get_order_meta_by_order_id_and_meta_key( $order_id, 'local_currency' );

						$currency = ! empty( $local_currency['meta_value'] ) ? $local_currency['meta_value'] : $currency;
						$symbol   = ur_get_currency_symbol( $currency );

						$is_proration_meta    = $orders_repository->get_order_meta_by_order_id_and_meta_key( $order_id, 'is_proration_upgrade' );
						$is_proration_upgrade = ! empty( $is_proration_meta['meta_value'] );

						?>
							<tr class="ur-account-table__row">
								<td class="ur-account-table__cell ur-account-table__cell--membership-type">
									<div style="display: flex; gap:4px;">
										<?php
										if ( ( isset( $data['is_upgrading'] ) && $data['is_upgrading'] ) || $is_renewing || ( isset( $data['is_purchasing_multiple'] ) && $data['is_purchasing_multiple'] ) ) {
											if ( ! empty( $data['bank_data']['notice_1'] ) ) {
												$notice = '';
												if ( $data['is_upgrading'] ) {
													$notice = isset( $data['bank_data']['notice_1'] ) ? $data['bank_data']['notice_1'] : '';
												} elseif ( $is_renewing ) {
													$notice = isset( $data['bank_data']['notice_2'] ) ? $data['bank_data']['notice_2'] : '';
												} elseif ( $data['is_purchasing_multiple'] ) {
													$notice = isset( $data['bank_data']['notice_3'] ) ? $data['bank_data']['notice_3'] : '';
												}

												if ( ! empty( $notice ) ) {
													$notice .= '</br><button class="view-bank-data">' . __( 'View Bank Info', 'user-registration' ) . '</button>';
													$notice .= '<div class="upgrade-info urm-d-none">' . $membership_info . '</div>';

													ob_start();
													?>
													<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ur_sanitize_tip_html() encodes the value for the attribute. ?>
													<span class="user-registration-help-tip" data-tip="<?php echo ur_sanitize_tip_html( $notice ); ?>">
														<svg xmlns="http://www.w3.org/2000/svg" fill="#FFC107" viewBox="0 0 24 24" height="18px" width="18px">
															<path fill-rule="evenodd" d="M9.491 4.44c1.115-1.92 3.903-1.92 5.017 0l7.1 12.24C22.722 18.6 21.328 21 19.1 21H4.9c-2.229 0-3.622-2.4-2.508-4.32L9.49 4.44h.001Zm2.51 5.038a.726.726 0 0 1 .723.72v3.6a.718.718 0 0 1-.724.72.726.726 0 0 1-.724-.72v-3.6a.718.718 0 0 1 .724-.72Zm0 7.92a.726.726 0 0 0 .723-.72.718.718 0 0 0-.724-.72.718.718 0 1 0 0 1.44Z" clip-rule="evenodd"/>
														</svg>
													</span>
													<?php
													echo ob_get_clean();
												}
											}
										}
										?>

										<?php echo isset( $membership['post_title'] ) && ! empty( $membership['post_title'] ) ? esc_html( $membership['post_title'] ) : __( 'N/A', 'user-registration' ); ?>
									</div>
								</td>
								<td class="ur-account-table__cell ur-account-table__cell--terms"><?php echo esc_html( $data['period'] ?? '-' ); ?></td>
								<td class="ur-account-table__cell ur-account-table__cell--status">
									<?php
									$status = '';

									if ( isset( $membership['status'] ) && ! empty( $membership ) ) {
										$status = 'inactive';
										$status = ( '' != $membership['status'] ) ? $membership['status'] : $status;
										if ( 'inactive' !== $status && 'free' !== $membership['post_content']['type'] && 'paid' !== $membership['post_content']['type'] && ! empty( $membership['billing_cycle'] ) ) {
											if ( $is_membership_expired ) {
												$status = 'expired';
											}
										}
									}
									?>
									<?php
									if ( ! empty( $status ) ) :
										$membership_statuses = array(
											'paid'     => __( 'Paid', 'user-registration' ),
											'free'     => __( 'Free', 'user-registration' ),
											'inactive' => __( 'In Active', 'user-registration' ),
											'expired'  => __( 'Expired', 'user-registration' ),
											'active'   => __( 'Active', 'user-registration' ),
										);

										$membership_status = isset( $membership_statuses[ strtolower( $status ) ] )
											? $membership_statuses[ strtolower( $status ) ]
											: ucfirst( $status );
										?>
										<?php if ( ! $pending_cancel_date ) : ?>
										<span id="ur-membership-status" class="btn-<?php echo esc_attr( $status ); ?>">
												<?php echo esc_html( $membership_status ); ?>
										</span>
										<?php endif; ?>
										<?php if ( $pending_cancel_date ) : ?>
										<span class="btn-pending-cancel">
											<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
											<?php
											echo esc_html(
												sprintf(
													/* translators: %s: cancellation date */
													__( 'Cancels %s', 'user-registration' ),
													date_i18n( get_option( 'date_format' ), strtotime( $pending_cancel_date ) )
												)
											);
											?>
										</span>
										<?php endif; ?>
										<?php
									else :
										echo __( 'N/A', 'user-registration' );
									endif;
									?>
								</td>
								<td class="ur-account-table__cell ur-account-table__cell--date"><?php echo ! empty( $membership['start_date'] ) ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $membership['start_date'] ) ) ) : __( 'N/A', 'user-registration' ); ?></td>
								<td class="ur-account-table__cell ur-account-table__cell--billing-date">
									<?php
									if ( isset( $membership['status'] ) && ( in_array( $membership['status'], array( 'canceled', 'expired' ) ) || $pending_cancel_date ) ) {
										echo esc_html__( 'N/A', 'user-registration' );
									} else {
										echo ! empty( $membership['next_billing_date'] ) && strtotime( $membership['next_billing_date'] ) > 0 ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $membership['next_billing_date'] ) ) ) : __( 'N/A', 'user-registration' );
									}
									?>
								</td>
								<td class="ur-account-table__cell ur-account-table__cell--action">
									<div class="membership-row-btn-container">
										<?php
										$buttons = array();

										if ( isset( $data['form_type'] ) && 'normal' === $data['form_type'] ) {
											if ( isset( $data['buttons'] ) && ! empty( $data['buttons'] ) ) {
												$buttons = $data['buttons'];
											}
										} else {

											if ( isset( $data['is_upgrading'] ) && ! $data['is_upgrading'] && ! empty( $membership ) ) :
												$membership_checkout_page_id = ur_get_translated_page_id( get_option( 'user_registration_member_registration_page_id', false ) );
												$redirect_page_url           = get_permalink( $membership_checkout_page_id );
												$thank_you_page_id           = ur_get_translated_page_id( get_option( 'user_registration_thank_you_page_id', false ) );
												$uuid                        = ur_generate_random_key();
												$subscription_id             = $membership['subscription_id'];
												$redirect_link_builder       = array(
													'action'  => 'upgrade',
													'current' => $membership['post_id'],
													'subscription_id' => $subscription_id,
													'thank_you' => $thank_you_page_id,
												);
												$concatenator                = strpos( $redirect_page_url, '?' ) === false ? '?' : '&';
												$upgrade_redirect_page_url   = $redirect_page_url . $concatenator . http_build_query(
													$redirect_link_builder
												);

												$upgradable_plans = $membership_service->get_upgradable_membership( $membership['post_id'] );
												?>
												<?php
												if ( 'canceled' !== $membership['status'] && ! $pending_cancel_date && ! empty( $upgradable_plans ) && ! $is_user_team_member ) :
													$buttons[] = '<a class="ur-account-action-link membership-tab-btn change-membership-button" href="' . esc_url_raw( $upgrade_redirect_page_url ) . '" data-id="' . esc_attr( $membership['post_id'] ?? '' ) . '">' . esc_html__( 'Change Plan', 'user-registration' ) . '</a>';
													?>
											<?php endif; ?>
												<?php
												$membership_type = isset( $membership['post_content'] ) && ! empty( $membership['post_content'] ) ? esc_html( ucfirst( wp_unslash( $membership['post_content']['type'] ) ) ) : 'NA';
												if ( ( 'canceled' === $membership['status'] || $pending_cancel_date ) && ( $membership_type !== 'subscription' || $date_to_renew > date( 'Y-m-d 00:00:00' ) ) ) {
													$buttons[] = '<a class="ur-account-action-link membership-tab-btn reactivate-membership-button" href="#" data-id="' . esc_attr( $membership['subscription_id'] ?? '' ) . '">' . esc_html__( 'Reactivate', 'user-registration' ) . '</a>';
												}
												?>

												<?php
												// Provide manual renew in case of failed payment attempts exhausted via payment retry engine.
												$can_renew = $can_renew || ( ur_string_to_bool( get_option( 'user_registration_payment_retry_enabled', false ) ) && intval( get_user_meta( $user_id, 'urm_is_payment_retrying', true ) ) >= intval( get_option( 'user_registration_payment_retry_count', 999 ) ) );

												if ( $can_renew && $date_to_renew <= date( 'Y-m-d 00:00:00' ) && 'canceled' !== $membership['status'] && ! $pending_cancel_date ) {
													$redirect_link_builder['action'] = 'renew';
													$redirect_page_url              .= $concatenator . http_build_query(
														$redirect_link_builder
													);
													$buttons[]                       = '<a class="ur-account-action-link membership-tab-btn renew-membership-button" href="' . esc_url( $redirect_page_url ) . '" data-pg-gateways="' . ( isset( $membership['active_gateways'] ) ? implode( ',', array_keys( $membership['active_gateways'] ) ) : '' ) . '" data-id="' . esc_attr( $membership['post_id'] ?? '' ) . '" data-team-id="' . esc_attr( $team_id ) . '"><svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
  <path d="M2 12A10 10 0 0 1 12 2h.004l.519.015a10.75 10.75 0 0 1 6.53 2.655l.394.363 2.26 2.26a1 1 0 1 1-1.414 1.414l-2.248-2.248-.31-.286A8.75 8.75 0 0 0 11.996 4 8 8 0 0 0 4 12a1 1 0 1 1-2 0Z"/>
  <path d="M20 3a1 1 0 1 1 2 0v5a1 1 0 0 1-1 1h-5a1 1 0 1 1 0-2h4V3Zm0 9a1 1 0 1 1 2 0 10 10 0 0 1-10 10h-.004a10.75 10.75 0 0 1-7.05-2.67l-.393-.363-2.26-2.26a1 1 0 1 1 1.414-1.414l2.248 2.248.31.286A8.749 8.749 0 0 0 12.003 20 7.999 7.999 0 0 0 20 12Z"/>
  <path d="M2 21v-5a1 1 0 0 1 1-1h5a1 1 0 1 1 0 2H4v4a1 1 0 1 1-2 0Z"/>
</svg>' . '<span class="urm-action-link--text">' . esc_html__( 'Renew Membership', 'user-registration' ) . '</span>' . '</a>';
												}
												?>
												<?php
											endif;
											?>
											<?php
											if ( 'canceled' !== $membership['status'] && ! $pending_cancel_date && ! $is_membership_expired ) {
												$buttons[] = '<a class="ur-account-action-link membership-tab-btn cancel-membership-button" data-id="' . esc_attr( $membership['subscription_id'] ?? '' ) . '"><svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
  <path d="M18.05 4.335a1.143 1.143 0 1 1 1.615 1.616L5.951 19.665a1.143 1.143 0 1 1-1.616-1.616L18.049 4.335Z"/>
  <path d="M4.335 4.335a1.143 1.143 0 0 1 1.616 0l13.714 13.714a1.143 1.143 0 1 1-1.616 1.616L4.335 5.951a1.143 1.143 0 0 1 0-1.616Z"/>
</svg>' . '<span class="urm-action-link--text">' . esc_html__( 'Cancel', 'user-registration' ) . '</span>' . '</a>';
											}
										}
										?>
										<div class="btn-div">
											<?php
											if ( ! empty( $buttons ) ) {
												if ( count( $buttons ) > 1 ) {
													?>
													<div class="action-menu">
														<button class="menu-trigger" type="button">
															<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
																<path d="M10 12a2.222 2.222 0 1 1 4.444 0A2.222 2.222 0 0 1 10 12Zm0-7.778a2.222 2.222 0 1 1 4.444 0 2.222 2.222 0 0 1-4.444 0Zm0 15.556a2.222 2.222 0 1 1 4.444 0 2.222 2.222 0 0 1-4.444 0Z"/>
															</svg>
														</button>
														<div class="hidden dropdown">
															<?php foreach ( $buttons as $button ) : ?>
																<?php echo $button; ?>
															<?php endforeach; ?>
														</div>
													</div>
													<?php
												} else {
													echo implode( ' | ', $buttons );
												}
											}
											?>
										</div>
									</div>
								</td>
							</tr>
						<?php
					endforeach;
					?>
				</tbody>
			</table>
		</div>
		<?php
		if ( $total_pages > 1 ) :
			?>
			<div class="ur-pagination">
				<?php
				echo paginate_links(
					array(
						'base'      => trailingslashit( $current_url ) . '%_%',
						'format'    => 'page/%#%/',
						'current'   => $current,
						'total'     => $total_pages,
						'prev_text' => '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" height="18px" width="18px">
											<path d="M14.653 2.418a1.339 1.339 0 0 1 1.944 0 1.468 1.468 0 0 1 0 2.02L9.32 12l7.278 7.561a1.468 1.468 0 0 1 0 2.02 1.339 1.339 0 0 1-1.944 0l-8.25-8.57a1.468 1.468 0 0 1 0-2.021l8.25-8.572Z"/>
										</svg>',
						'next_text' => '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" height="18px" width="18px">
											<path d="M7.403 2.418a1.339 1.339 0 0 1 1.944 0l8.25 8.572a1.468 1.468 0 0 1 0 2.02l-8.25 8.572a1.339 1.339 0 0 1-1.944 0 1.468 1.468 0 0 1 0-2.02L14.68 12 7.403 4.439a1.468 1.468 0 0 1 0-2.02Z"/>
										</svg>',
						'type'      => 'list',
					)
				);
				?>
				</div>

				<?php
				endif;
		?>
	</div>
</div>
