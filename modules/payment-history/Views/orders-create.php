<?php
$return_url = admin_url( 'admin.php?page=member-payment-history' );
?>
<div class="ur-admin-page-topnav" id="ur-lists-page-topnav">
	<div class="ur-page-title__wrapper">
		<div class="ur-page-title__wrapper--left">
			<a class="ur-text-muted ur-border-right ur-d-flex ur-mr-2 ur-pl-2 ur-pr-2" href="<?php echo esc_attr( $return_url ); ?>">
				<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
			</a>
			<div class="ur-page-title__wrapper--left-menu">
				<div class="ur-page-title__wrapper--left-menu__items">
					<p>
						<?php echo isset( $_GET['post_id'] ) ? esc_html_e( 'Edit Payment', 'user-registration' ) : esc_html_e( 'Add New Payment', 'user-registration' ); ?>
					</p>
				</div>
			</div>
		</div>
	</div>
</div>
<div class="ur-membership">
	<div class="ur-membership-tab-contents-wrapper ur-registered-from ur-align-items-center ur-justify-content-center">
		<form method="post" id="ur-membership-order-create-form">
			<div class="user-registration-card">
				<div class="user_registration-card__body">
					<div class="ur-membership-main-fields">
						<div style="font-size:12px;margin-bottom:32px;background-color: #f7fbff; border: 1px solid #475bb2; padding: 12px 16px; border-radius: 4px; line-height: 150%; display: flex; flex-direction: row; gap: 12px; align-items: start;">
							<strong> <?php _e('Important Note:', 'user-registration' ) ?></strong>
							<?php _e('This form is intended only to record missed payments for tracking purposes. Adding a payment here does not renew the next billing cycle or assign any new plan to the user.' , 'user-registration' ) ?>
						</div>

						<!-- Member -->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label" style="max-width: 300px; width: 100%;">
								<label for="ur-input-type-member"><?php esc_html_e( 'Member', 'user-registration' ); ?>
									<span style="color:red">*</span>
								</label>
							</div>
							<div class="ur-input-type-member-name ur-admin-template" style="flex: 1; width: 100%">
								<div class="ur-field">
									<select
										data-key-name="<?php echo esc_html__( 'Member', 'user-registration' ); ?>"
										id="ur-input-type-member"
										name="ur_member"
										class="user-membership-member-enhanced-select2"
										style="width: 100%"
										required>
										<option value="" disabled selected><?php esc_html_e( 'Search by username or email...', 'user-registration' ); ?></option>
										<?php
										if ( ! empty( $users ) ) {
											foreach ( $users as $user ) :
												?>
												<option
													data-membership-plan-id="<?php echo esc_attr( $user[ 'item_id' ] ) ?>"
													value="<?php echo esc_attr( $user[ 'user_id' ] ) ?>">
													<?php echo esc_html( $user[ 'user_login' ] ) ?> (<?php echo esc_html( $user[ 'user_email' ] ) ?>)
												</option>
											<?php
											endforeach;
										}
										?>
									</select>
								</div>
							</div>
						</div>

						<!-- Membership Plan -->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label" style="max-width: 300px; width: 100%;">
								<label for="ur-input-type-membership-plan"><?php esc_html_e( 'Membership Plan', 'user-registration' ); ?>
								</label>
							</div>
							<div class="ur-text-muted ur-membership-plan-name ur-admin-template" style="flex: 1; width: 100%">
								<?php echo esc_html__( 'Select a member to view their membership plan.', 'user-registration' ); ?>

							</div>
							<select
								data-key-name="<?php echo esc_html__( 'Membership Plan', 'user-registration' ); ?>"
								id="ur-input-type-membership-plan"
								name="ur_membership_plan"
								class="user-membership-group-enhanced-select2 urmg-input"
								style="width: 100%;"
								hidden
								required>
								<option value="" disabled selected><?php esc_html_e( 'Select a membership plan', 'user-registration' ); ?></option>
								<?php
								if ( ! empty( $membership_plans ) ) {
									foreach ( $membership_plans as $plan ) :
										?>
										<option
											value="<?php echo esc_attr( $plan['ID'] ); ?>"
											data-amount="<?php echo esc_attr( $plan['amount'] ); ?>">
											<?php echo esc_html( $plan['title'] ); ?>
										</option>
									<?php
									endforeach;
								} ?>
							</select>
						</div>

						<!-- Amount -->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label" style="max-width: 300px; width: 100%;">
								<label for="ur-input-type-membership-amount"><?php esc_html_e( 'Amount', 'user-registration' ); ?>
									<span style="color:red">*</span>
								</label>
							</div>
							<div class="ur-input-type-membership-name ur-admin-template" style="flex: 1; width: 100%">
								<div class="ur-field">
									<input type="text"
										autocomplete="off"
										class="ur-membership-amount-input urmg-input"
										data-key-name="<?php echo esc_html__( 'Amount', 'user-registration' ); ?>"
										id="ur-input-type-membership-amount"
										name="ur_membership_amount"
										required>
								</div>
							</div>
						</div>
						<!-- Payment Method -->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label" style="max-width: 300px; width: 100%;">
								<label for="ur-input-type-transaction-status"><?php esc_html_e( 'Payment Method', 'user-registration' ); ?>
									<span style="color:red">*</span>
								</label>
							</div>
							<div class="ur-input-type-membership-name ur-admin-template" style="flex: 1; width: 100%">
								<div class="ur-field">
									<select
										data-key-name="<?php echo esc_html__( 'Payment Method', 'user-registration' ); ?>"
										id="ur-input-type-payment-method"
										name="ur_payment_method"
										class="urmg-input"
										style="width: 100%"
										required>
										<option value=""><?php esc_html_e( 'Select Payment Method', 'user-registration' ); ?></option>
										<?php foreach ( $payment_methods as $gateway_key => $gateway_label ) : ?>
											<option value="<?php echo esc_attr( $gateway_key ); ?>"><?php echo esc_html( $gateway_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
						</div>

						<!-- Payment Date -->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label" style="max-width: 300px; width: 100%;">
								<label for="ur-input-type-payment-date"><?php esc_html_e( 'Payment Date', 'user-registration' ); ?>
									<span style="color:red">*</span>
								</label>
							</div>
							<div class="ur-input-type-membership-name ur-admin-template" style="flex: 1; width: 100%">
								<div class="ur-field">
									<input type="date"
										autocomplete="off"
										class="ur-payment-date-input ur-date urmg-input"
										data-key-name="<?php echo esc_html__( 'Payment Date', 'user-registration' ); ?>"
										id="ur-input-type-payment-date"
										name="ur_payment_date"
										style="width: 100%; padding: 8px; border: 1px solid #e1e1e1; border-radius: 4px; height: 38px;"
										required>
								</div>
							</div>
						</div>

						<!-- Transaction Status -->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label" style="max-width: 300px; width: 100%;">
								<label for="ur-input-type-transaction-status"><?php esc_html_e( 'Transaction Status', 'user-registration' ); ?>
									<span style="color:red">*</span>
								</label>
							</div>
							<div class="ur-input-type-membership-name ur-admin-template" style="flex: 1; width: 100%">
								<div class="ur-field">
									<select
										data-key-name="<?php echo esc_html__( 'Transaction Status', 'user-registration' ); ?>"
										id="ur-input-type-transaction-status"
										name="ur_transaction_status"
										class="urmg-input"
										style="width: 100%"
										required>
										<option value="" disabled selected><?php esc_html_e( 'Select status', 'user-registration' ); ?></option>
										<?php
										foreach ( array( 'completed', 'pending', 'failed', 'refunded' ) as $id => $status ) {
											?>
											<option
												value='<?php echo esc_attr( $status ); ?>' ><?php echo esc_html( ucfirst( $status ) ); ?></option>
											<?php
										}
										?>
									</select>
								</div>
							</div>
						</div>
						<!-- Payment Notes -->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label" style="max-width: 300px; width: 100%;">
								<label for="ur-input-type-payment-notes"><?php esc_html_e( 'Payment Notes', 'user-registration' ); ?>
								</label>
							</div>
							<div class="ur-input-type-membership-name ur-admin-template" style="flex: 1; width: 100%">
								<div class="ur-field">
									<textarea
										class="ur-payment-notes"
										data-key-name="<?php echo esc_html__( 'Payment Notes', 'user-registration' ); ?>"
										id="ur-input-type-payment-notes"
										name="ur_payment_notes"
									>
									</textarea>
								</div>
							</div>
						</div>
					</div>
				</div>

				<hr>
				<?php
				$save_btn_class  = 'ur-add-new-payment';
				$create_btn_text = esc_html__( 'Add Payment', 'user-registration' );
				require __DIR__ . '/footer-actions.php';
				?>
				<?php wp_nonce_field( 'ur_membership_order', 'ur_membership_order_nonce' ); ?>

			</div>
		</form>
	</div>
</div>
