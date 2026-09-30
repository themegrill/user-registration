<?php
$return_url         = admin_url( 'admin.php?page=user-registration-users' );
$membership_details = ! empty( $member_membership ) && ! empty( $member_membership['meta_value'] ) ? json_decode( $member_membership['meta_value'], true ) : array();
$membership         = apply_filters( 'build_membership_list_frontend', array( (array) $membership_details ) );
$status_class       = ! empty( $member_subscription ) ? 'user-registration-badge user-registration-badge--' . $member_subscription['status'] : '';

?>
<div class="ur-admin-page-topnav" id="ur-lists-page-topnav">
	<div class="ur-page-title__wrapper">
		<div class="ur-page-title__wrapper--left">
			<a class="ur-text-muted ur-border-right ur-d-flex ur-mr-2 ur-pl-2 ur-pr-2"
				href="<?php echo esc_attr( $return_url ); ?>">
				<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none"
					stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1">
					<line x1="19" y1="12" x2="5" y2="12"></line>
					<polyline points="12 19 5 12 12 5"></polyline>
				</svg>
			</a>
			<div class="ur-page-title__wrapper--left-menu">
				<div class="ur-page-title__wrapper--left-menu__items">
					<p>
						<?php echo isset( $_GET['member_id'] ) ? esc_html_e( 'Editing Member @', 'user-registration' ) . ( ! empty( $member ) ? $member->user_login : '' ) : esc_html_e( 'Create New Member', 'user-registration' ); ?>
					</p>
				</div>
			</div>
		</div>
	</div>
</div>
<div class="ur-membership-tab-contents-wrapper ur-align-items-center ur-justify-content-center">
	<form id="ur-membership-create-form" method="post">
		<div class="user-registration-card">
			<div id="ur-membership-form-container" class="ur-d-flex">
				<div id="ur-member-form-left">
					<div id="left-body" class="">
						<!--						first name-->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label">
								<label
									for="ur-input-type-membership-first-name"><?php esc_html_e( 'First Name', 'user-registration' ); ?>
								</label>
							</div>
							<div class="ur-input-type-membership-name ur-admin-template" style="width: 100%">
								<div class="ur-field" data-field-key="membership_name">
									<input
										class="ur-membership-members-input"
										type="text"
										data-key-name="<?php echo esc_html__( 'firstname', 'user-registration' ); ?>"
										id="ur-input-type-membership-first-name" name="ur_membership_first_name"
										style="width: 100%"
										value="<?php echo ! empty( $member->first_name ) ? esc_attr( $member->first_name ) : ''; ?>"
											<?php echo ( ! empty( $_GET['action'] ) && 'view' === $_GET['action'] ) ? 'disabled' : ''; ?>

									>
								</div>
							</div>
						</div>
						<!--						last name-->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label">
								<label
									for="ur-input-type-membership-last-name"><?php esc_html_e( 'Last Name', 'user-registration' ); ?>
								</label>
							</div>
							<div class="ur-input-type-membership-name ur-admin-template" style="width: 100%">
								<div class="ur-field">
									<input type="text"
											class="ur-membership-members-input"
											data-key-name="<?php echo esc_html__( 'lastname', 'user-registration' ); ?>"
											id="ur-input-type-membership-last-name" name="ur_membership_last_name"
											style="width: 100%"
											value="<?php echo ! empty( $member->last_name ) ? esc_attr( $member->last_name ) : ''; ?>"
											<?php echo ( ! empty( $_GET['action'] ) && 'view' === $_GET['action'] ) ? 'disabled' : ''; ?>

									>
								</div>
							</div>
						</div>
						<!--username-->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label">
								<label
									for="ur-input-type-membership-username"><?php esc_html_e( 'Username', 'user-registration' ); ?>
									<span style="color:red">*</span>
								</label>
							</div>
							<div class="ur-input-type-membership-name ur-admin-template" style="width: 100%">
								<div class="ur-field">
									<input type="text"
											autocomplete="off"
											class="ur-membership-members-input"
											data-key-name="<?php echo esc_html__( 'Username', 'user-registration' ); ?>"
											id="ur-input-type-membership-username" name="ur_membership_username"
											style="width: 100%"
											value="<?php echo ! empty( $member->user_login ) ? esc_attr( $member->user_login ) : ''; ?>"
											<?php echo ! empty( $_GET['action'] ) && 'view' === $_GET['action'] ? 'disabled' : ''; ?>

											required>
								</div>
							</div>

						</div>
						<!--						email-->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label">
								<label
									for="ur-input-type-membership-email"><?php esc_html_e( 'Email', 'user-registration' ); ?>
									<span style="color:red">*</span>
								</label>
							</div>
							<div class="ur-input-type-membership-email ur-admin-template" style="width: 100%">
								<div class="ur-field">
									<input type="email"
											class="ur-membership-members-input"
											data-key-name="<?php echo esc_html__( 'Email', 'user-registration' ); ?>"
											id="ur-input-type-membership-email" name="ur_membership_email"
											style="width: 100%"
											value="<?php echo ! empty( $member->user_email ) ? esc_attr( $member->user_email ) : ''; ?>"
											<?php echo ( ! empty( $_GET['action'] ) && 'view' === $_GET['action'] ) ? 'disabled' : ''; ?>

											required>
								</div>
							</div>
						</div>
						<?php
						if ( empty( $_GET['member_id'] ) ) :
							?>
							<!-- password -->
							<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
								<div class="ur-label">
									<label
										for="ur-input-type-membership-password"><?php esc_html_e( 'Password', 'user-registration' ); ?>
										<span style="color:red">*</span>
									</label>
								</div>
								<div class="ur-input-type-membership-password ur-admin-template" style="width: 100%">
									<div class="ur-field">
										<input
											autocomplete="off"
											data-key-name="<?php echo esc_html__( 'Password', 'user-registration' ); ?>"
											class="ur-membership-members-input"
											type="password"
											id="ur-input-type-membership-password" name="ur_membership_password"
												<?php echo ( ! empty( $_GET['action'] ) && 'view' === $_GET['action'] ) ? 'disabled' : ''; ?>

											style="width: 100%"
											required>
									</div>
								</div>
							</div>
							<!--						confirm password-->
							<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
								<div class="ur-label">
									<label
										for="ur-input-type-membership-confirm-password"><?php esc_html_e( 'Confirm Password', 'user-registration' ); ?>
										<span style="color:red">*</span>
									</label>
								</div>
								<div class="ur-input-type-membership-confirm-password ur-admin-template"
									style="width: 100%">
									<div class="ur-field">
										<input type="password"
												data-key-name="<?php echo esc_html__( 'confirm_password', 'user-registration' ); ?>"
												class="ur-membership-members-input"
												id="ur-input-type-membership-confirm-password"
												name="ur_membership_confirm_password"
												style="width: 100%"
												<?php echo ( ! empty( $_GET['action'] ) && 'view' === $_GET['action'] ) ? 'disabled' : ''; ?>

												required>
									</div>
								</div>
							</div>
							<?php
						endif;
						?>
						<!--						role-->
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label">
								<label
									for="ur-input-type-membership-member-role"><?php esc_html_e( 'Member Role', 'user-registration' ); ?>
									<span style="color:red">*</span>
								</label>
							</div>
							<div class="ur-input-type-membership-name ur-admin-template" style="width: 100%">
								<div class="ur-field">
									<select
										data-key-name="<?php echo esc_html__( 'Role', 'user-registration' ); ?>"
										id="ur-input-type-membership-member-role"
										class="user-membership-enhanced-select2 ur-membership-members-input ur-enhanced-select"
										<?php echo ( ! empty( $_GET['action'] ) && 'view' === $_GET['action'] ) ? 'disabled' : ''; ?>
									>
										<?php
										foreach ( $roles as $k => $role ) :
											?>
											<option
												value="<?php echo esc_attr( $k ); ?>"
												<?php echo ! empty( $member ) && ( in_array( $k, $member->roles ) ) ? 'selected="selected"' : ''; ?>
											><?php echo esc_html( $role ); ?></option>
											<?php
										endforeach;
										?>
									</select>
								</div>
							</div>
						</div>
					</div>
				</div>
				<!-- <div id="ur-member-form-right">
					<div id="select-plan-container" class="right-container">
						<div class="right-title">
							<h3 class="ur-mt-2">
								<?php esc_html_e( 'Select Plan', 'user-registration' ); ?>
							</h3>
						</div>
						<div class="right-body ur-d-flex ur-flex-column">
							<div class="form-row ur-enhanced-select ur-mt-3">
								<label for="ur-membership-select" class="ur-label">
									<?php echo esc_html__( 'Membership', 'user-registration' ); ?>
								</label>
								<select
									class="ur-membership-members-input ur-enhanced-select user-membership-enhanced-select2"
									data-key-name="<?php echo esc_html__( 'Membership', 'user-registration' ); ?>"
									name="ur-membership-select"
									id="ur-membership-select"
									style="width: 100%"
									<?php echo ( ! empty( $_GET['action'] ) && 'view' === $_GET['action'] ) ? 'disabled' : ''; ?>
								>
									<?php

									foreach ( $memberships as $k => $membership ) :
										?>
										<option
											value="<?php echo esc_attr( $membership['ID'] ); ?>"
											<?php echo ! empty( $member_membership ) && ( $membership['ID'] == $member_membership['ID'] ) ? 'selected="selected"' : ''; ?>

										><?php echo esc_html( $membership['title'] . ' (' . $membership['period'] ) . ')'; ?></option>
										<?php
									endforeach;
									?>
								</select>
							</div>
							<div class="form-row ur-mt-3">
								<label for="ur-membership-start-date" class="ur-label">
									<?php echo esc_html__( 'Start Date', 'user-registration' ); ?>
								</label>
								<input
									<?php echo ( ! empty( $_GET['action'] ) && 'view' === $_GET['action'] ) ? 'disabled' : ''; ?>
									class="ur-membership-members-input"
									data-key-name="<?php echo esc_html__( 'start_date', 'user-registration' ); ?>"
									id="ur-membership-start-date" type="date" style="width: 100%"
									value="<?php echo ( ! empty( $member_subscription['start_date'] ) ) ? date( 'Y-m-d', strtotime( $member_subscription['start_date'] ) ) : date( 'Y-m-d' ); ?>">

							</div>
						</div>
					</div>
					<?php
					if ( ! empty( $_GET['member_id'] ) ) :
						?>
						<div id="plan-detail-container" class="right-container">
							<div class="right-body ur-d-flex ur-flex-column">
								<div class="form-row ur-mt-3">
									<label class="ur-label">
										<?php echo esc_html__( 'Amount', 'user-registration' ); ?>
									</label>
									<span  class="urm-membership-plan-amount"><?php echo ! empty( $membership_price_details['period'] ) ? $membership_price_details['period'] : 'N/A'; ?></span>
								</div>
								<div class="form-row ur-mt-3">
									<label class="ur-label">
										<?php echo esc_html__( 'Expires On', 'user-registration' ); ?>
									</label>
									<span class="urm-membership-expiry-date">
									<?php
									$member_expiry = ! empty( $member_subscription['expiry_date'] ) ? $member_subscription['expiry_date'] : ( $member_subscription['next_billing_date'] ?? '' );
									echo ! empty( $member_expiry ) && strtotime( $member_expiry ) > 0 ? esc_html( date_i18n( 'F d, Y', strtotime( $member_expiry ) ) ) : esc_html__( 'N/A', 'user-registration' );
									?>
								</span>
								</div>
								<div class="form-row ur-mt-3">
									<label class="ur-label">
										<?php echo esc_html__( 'Subscription Status', 'user-registration' ); ?>
									</label>
									<span class="urm-membership-subscription-status">
										<span class="<?php echo $status_class; ?>">
										<?php
										echo ! empty( $member_subscription['status'] ) ? ucfirst( $member_subscription['status'] ) : 'N/A';
										?>
										</span>
								</span>
								</div>
							</div>
						</div>
						<?php
					endif;
					?>
				</div> -->
			</div>
			<?php
			if ( ! empty( $_GET['action'] ) && 'view' !== $_GET['action'] ) :
				$save_btn_class  = 'ur-member-save-btn';
				$create_btn_text = isset( $_GET['member_id'] ) ? esc_html__( 'Update Member', 'user-registration' ) : esc_html__( 'Create Member', 'user-registration' );
				require __DIR__ . '/./Partials/footer-actions.php';
			endif;
			?>
		</div>
	</form>
</div>
