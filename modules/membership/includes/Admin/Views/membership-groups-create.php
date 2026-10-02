<?php

$return_url = admin_url( 'admin.php?page=user-registration-membership&action=list_groups' );

$membership_group_service = new WPEverest\URMembership\Admin\Services\UpgradeMembershipService();
$upgrade_path             = array();

if ( isset( $membership_group['upgrade_path'] ) ) {
	$upgrade_path = json_decode( $membership_group['upgrade_path'], true );
}

$upgrade_order      = array();
$upgrade_order_html = '';
if ( isset( $membership_group['upgrade_path'] ) ) {
	$upgrade_order      = array_map(
		function ( $value, $key ) {
			return $key;
		},
		$upgrade_path,
		array_keys( $upgrade_path )
	);
	$upgrade_order_html = $membership_group_service->build_upgrade_order( $upgrade_path );
}

$membership_description = '';

if ( ! empty( $membership_group['post_content'] ) ) {
	$membership_group_content = json_decode( wp_unslash( $membership_group['post_content'] ), true );
	$membership_description   = $membership_group_content['description'];
}
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
						<?php echo isset( $_GET['post_id'] ) ? esc_html_e( 'Edit Membership Group', 'user-registration' ) : esc_html_e( 'Create New Membership Group', 'user-registration' ); ?>
					</p>
				</div>
			</div>
		</div>
		<div class="ur-page-title__wrapper--right">
			<div class="ur-page-title__wrapper--right-menu">
				<div class="ur-page-title__wrapper--right-menu__item">
					<div class="ur-page-title__wrapper--actions">
						<div class="ur-page-title__wrapper--actions-status">
							<p><?php esc_html_e( 'Status', 'user-registration' ); ?></p>
							<span class="separator">|</span>
							<div class="visible ur-d-flex ur-align-items-center" style="gap: 5px">
								<div class="ur-toggle-section">
									<span class="user-registration-toggle-form">
										<input
										data-key-name="Membership Group Status"
										id="ur-membership-group-status" type="checkbox"
										class="ur-membership-change__status user-registration-switch__control hide-show-check enabled"
										value="1"
										<?php
										checked(
											! isset( $membership_group_content['status'] ) || ur_string_to_bool( $membership_group_content['status'] )
										);
										?>
										>
										<span class="slider round"></span>
									</span>
								</div>
							</div>
						</div>
						<div class="ur-page-title__wrapper--actions-publish">
							<button class="button-primary ur-membership-group-save-btn" type="submit">
								<?php ! empty( $membership_group['ID'] ) ? esc_html_e( 'Save', 'user-registration' ) : esc_html_e( 'Publish', 'user-registration' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<div class="ur-membership">
	<div
		class="ur-membership-tab-contents-wrapper ur-registered-from ur-align-items-center ur-justify-content-center">
		<form id="ur-membership-create-form" method="post">
			<div id="ur-membership-group-create-form" class="user-registration-card">
				<div class="user-registration-card__body">
					<div id="ur-membership-main-fields">
						<!--membership group name-->
						<div class="ur-membership-input-container ur-d-flex ur-p-1" style="gap:20px;">
							<div class="ur-label" style="width: 62%">
								<label
									for="ur-input-type-membership-group-name"><?php esc_html_e( 'Group Name', 'user-registration' ); ?>
									<span style="color:red">*</span>
									<span class="user-registration-help-tip tooltipstered"
											data-tip="<?php echo esc_attr__( 'Title for the group.', 'user-registration' ); ?>"></span>
								</label>
							</div>
							<div class="ur-input-type-membership-group-name ur-admin-template" style="width: 100%">
								<div class="ur-field" data-field-key="membership_group_name">
									<input type="text" data-key-name="Membership Group Name"
											id="ur-input-type-membership-group-name" name="ur_membership_group_name"
											style="width: 100%"
											autocomplete="off"
											value="<?php echo ! empty( $membership_group['post_title'] ) ? esc_attr( $membership_group['post_title'] ) : ''; ?>"
											class="urmg-input"
											required>
								</div>
							</div>

						</div>
						<!--					membership description-->
						<div class="ur-membership-input-container ur-input-type-textarea ur-d-flex ur-p-1 ur-mt-3"
							style="gap:20px;">
							<div class="ur-label" style="width: 62%">
								<label for="ur-input-type-membership-group-description">
									Group Description
									<span class="user-registration-help-tip tooltipstered"
											data-tip="<?php echo esc_attr__( 'Describe the group.', 'user-registration' ); ?>"></span>
								</label>
							</div>
							<div class="ur-field" data-field-key="textarea" style="width: 100%">
								<textarea data-key-name="Membership Description"
											id="ur-input-type-membership-group-description"
											name="ur_membership_description"
											style="width: 100%" rows="5"
											class="urmg-input"
											value=""><?php echo $membership_description; ?></textarea>
							</div>
						</div>
						<div class="ur-membership-input-container ur-d-flex ur-p-3" style="gap:20px;">
							<div class="ur-label" style="width: 62%">
								<label
									for="ur-input-type-membership-group-role"><?php esc_html_e( 'Select Memberships', 'user-registration' ); ?>
									<span style="color:red">*</span>
									<span class="user-registration-help-tip tooltipstered"
											data-tip="Select which membership fall under this group."></span>
								</label>
							</div>
							<div class="ur-input-type-membership-group-name ur-admin-template" style="width: 100%">
								<div class="ur-field">
									<select
										data-key-name="<?php echo esc_html__( 'Memberships', 'user-registration' ); ?>"
										id="ur-input-type-membership-group-memberships"
										class="user-membership-group-enhanced-select2 urmg-input"
										multiple="multiple"
										required>

										<?php
										$selected_memberships = array();
										if ( isset( $membership_group['memberships'] ) ) {
											$selected_memberships = json_decode( $membership_group['memberships'], true );
										}

										foreach ( $memberships as $membership ) :
											?>
											<option
												<?php echo isset( $membership_group['memberships'] ) && in_array( $membership['ID'], $selected_memberships ) ? 'selected="selected"' : ''; ?>
												value="<?php echo esc_attr( $membership['ID'] ); ?>"><?php echo esc_html( $membership['title'] ); ?></option>
											<?php
										endforeach;
										?>
									</select>
								</div>
							</div>
						</div>
						<div class="ur-membership-input-container ur-d-flex ur-p-1 ur-mt-3 <?php echo ( ! UR_PRO_ACTIVE || ! urm_check_if_plus_and_above_plan() ) ? 'upgradable-type' : ''; ?>" style="gap:20px" >
							<div class="ur-label" style="width: 62%;">
									<label class="ur-membership-group-enable-multiple-memberships"
										for="ur-membership-group-multiple-memberships">
										<?php esc_html_e( 'Allow multiple memberships', 'user-registration' ); ?>
										<span class="user-registration-help-tip tooltipstered"
										data-tip="<?php echo esc_attr__( 'When enabled, users can hold multiple memberships from this group simultaneously.', 'user-registration' ); ?>"></span>
									</label>
							</div>
							<div class="ur-toggle-section m1-auto " style="width:100%">
								<span class="user-registration-toggle-form">
									<input
									data-key-name="Allow multiple memberships"
									id="ur-membership-group-multiple-memberships" type="checkbox"
									class="user-registration-switch__control hide-show-check enabled urmg-input"
									<?php echo isset( $membership_group['mode'] ) && 'multiple' === $membership_group['mode'] ? 'checked' : ''; ?>
									name="ur_membership_enable_multiple_memberships"
									style="width: 100%; text-align: left"
									<?php echo UR_PRO_ACTIVE && urm_check_if_plus_and_above_plan() ? '' : 'disabled'; ?>
									>
									<span class="slider round"></span>
									<?php
									if ( ! UR_PRO_ACTIVE ) {
										ur_render_premium_feature_gate();
									} elseif ( ! urm_check_if_plus_and_above_plan() ) {
										ur_render_premium_feature_gate(
											array(
												'plan_gated' => true,
												'template_id' => 'ur-plan-feature-plus',
												'title' => esc_html__( 'This feature is available on the Plus plan and above. Upgrade your plan to unlock it.', 'user-registration' ),
												'button_text' => esc_html__( 'Upgrade Plan', 'user-registration' ),
											)
										);
									}
									?>
								</span>
							</div>
						</div>
						<?php
						$upgrade_style = ( ! isset( $membership_group['mode'] ) || ( isset( $membership_group['mode'] ) && ( empty( $membership_group['mode'] ) || 'upgrade' === $membership_group['mode'] ) ) ) ? '' : 'display:none;';
						?>
						<div class="ur-membership-enable-upgrade-container" style="<?php echo esc_attr( $upgrade_style ); ?>">
							<div class="ur-membership-input-container ur-d-flex ur-p-1 ur-mt-3  <?php echo ( ! UR_PRO_ACTIVE || ! urm_check_if_plus_and_above_plan() ) ? 'upgradable-type' : ''; ?>" style="gap:20px">
								<div class="ur-label" style="width: 62%;">
										<label class="ur-membership-group-enable-upgrade"
												for="ur-membership-group-upgrade"><?php esc_html_e( 'Upgrade action', 'user-registration' ); ?>
											<span class="user-registration-help-tip tooltipstered"
													data-tip="<?php echo esc_attr__( 'Enable automatic upgrade paths between memberships in this group.', 'user-registration' ); ?>"></span>
										</label>
								</div>
								<div class="ur-toggle-section m1-auto" style="width:100%">
									<span class="user-registration-toggle-form">
										<input
											data-key-name="Upgrade action"
											id="ur-membership-group-upgrade" type="checkbox"
											class="user-registration-switch__control hide-show-check enabled urmg-input"
											<?php echo isset( $membership_group ) && 'upgrade' === $membership_group['mode'] ? 'checked' : ''; ?>
											name="ur_membership_enable_upgrade"
											style="width: 100%; text-align: left"
											<?php echo UR_PRO_ACTIVE && urm_check_if_plus_and_above_plan() ? '' : 'disabled'; ?>
											>
										<span class="slider round"></span>
										<?php
										if ( ! UR_PRO_ACTIVE ) {
											ur_render_premium_feature_gate();
										} elseif ( ! urm_check_if_plus_and_above_plan() ) {
											ur_render_premium_feature_gate(
												array(
													'plan_gated'  => true,
													'template_id' => 'ur-plan-feature-plus',
													'title'       => esc_html__( 'This feature is available on the Plus plan and above. Upgrade your plan to unlock it.', 'user-registration' ),
													'button_text' => esc_html__( 'Upgrade Plan', 'user-registration' ),
												)
											);
										}
										?>
									</span>
								</div>
							</div>
						</div>
						<?php
						if ( UR_PRO_ACTIVE && urm_check_if_plus_and_above_plan() ) {
							$upgrade_style = isset( $membership_group['mode'] ) && 'upgrade' === $membership_group['mode'] ? '' : 'display:none;';
							?>
							<div class="ur-membership-upgrade-container" style="<?php echo esc_attr( $upgrade_style ); ?>">
								<!--						Membership Upgrade Path Type-->
								<div
									class="urm-upgrade-path-type-container ur-membership-selection-container ur-membership-input-container ur-d-flex ur-align-items-center ur-d-none"
									data-key-name="<?php echo __( 'Upgrade Type', 'user-registration' ); ?>"
									style="gap:20px;">
									<div class="ur-label" style="width: 62%">
										<label
											for="ur-membership-upgrade-type-full">
											<?php echo __( 'Upgrade Type', 'user-registration' ); ?>
											<span style="color:red">*</span>
											<span class="user-registration-help-tip tooltipstered"
												data-tip="<?php echo esc_attr__( 'Choose how upgrades are calculated.', 'user-registration' ); ?>"></span>
										</label>

									</div>
									<div class="ur-input-type-select ur-admin-template" style="width: 100%">
										<div class="ur-field ur-d-flex"
											data-field-key="radio">
											<label class="ur-membership-types"
													for="ur-membership-upgrade-type-full">
												<div
													class="ur-membership-type-title ur-d-flex ur-align-items-center ">
													<input
														data-key-name="<?php echo __( 'Upgrade Type', 'user-registration' ); ?>"
														id="ur-membership-upgrade-type-full"
														type="radio" value="full"
														name="ur_membership_upgrade_type"
														style="margin: 0"
														<?php echo ( ( isset( $membership_group['upgrade_type'] ) && $membership_group['upgrade_type'] == 'full' ) ) ? 'checked' : ''; ?>
														required>
													<label class="ur-membership-upgrade-type-full--label" for="ur-membership-upgrade-type-full">
														<b
															class="user-registration-image-label "><?php esc_html_e( 'Full Amount Upgrade', 'user-registration' ); ?>
														</b>
													</label>
												</div>
											</label>
											<!--								Pro rata type-->
											<label
												class="ur-membership-types  <?php echo isset( $membership_group['upgrade_type'] ) && $membership_group['upgrade_type'] == 'free' ? 'ur-d-none' : ''; ?>"
												for="ur-membership-upgrade-type-pro-rata">
												<div
													class="ur-membership-type-title ur-d-flex ur-align-items-center">
													<input
														data-key-name="Upgrade Type"
														id="ur-membership-upgrade-type-pro-rata"
														type="radio"
														value="pro-rata"
														name="ur_membership_upgrade_type"
														style="margin: 0"
														<?php echo ( ( isset( $membership_group['upgrade_type'] ) && $membership_group['upgrade_type'] == 'pro-rata' ) ) ? 'checked' : ''; ?>
														<?php echo ! UR_PRO_ACTIVE ? 'disabled' : ''; ?>
														required>
													<label class="ur-membership-upgrade-type-full--label" for="ur-membership-upgrade-type-pro-rata">
														<b
															class="user-registration-image-label "><?php esc_html_e( 'Proration Upgrade', 'user-registration' ); ?>
														</b>
													</label>
												</div>
											</label>
										</div>
									</div>
								</div>
								<div class="ur-membership-upgrade-path-container">
									<div class="urm-upgrade-path-type-container ur-membership-selection-container ur-membership-input-container ur-d-flex ur-mt-6 ur-align-items-center ur-d-none" data-key-name="Upgrade Type" style="gap:20px;">
										<div class="ur-label" style="width: 62%">
											<label for="ur-membership-upgrade-type-full">
												<?php esc_html_e( 'Upgrade Path Order', 'user-registration' ); ?>
												<span class="user-registration-help-tip tooltipstered"
												data-tip="<?php echo esc_attr__( 'Drag to reorder upgrade progression.', 'user-registration' ); ?>"></span>
											</label>
										</div>
										<div class="ur-input-type-select ur-admin-template" style="width: 100%" >
											<p class="ur-membership-upgrade-paths-info"  >
												<?php
												esc_html_e( 'Drag to arrange memberships, highest tier at the top and lowest tier at the bottom. Users can upgrade from any membership to higher tiers in this sequence.', 'user-registration' );
												?>
												</p>
											<div class="ur-field ur-d-flex" style="flex-wrap:nowrap;">
												<div style="flex:50%;">
													<span class="ur-upgrade-path-anchor"><?php esc_html_e( '↑ Highest tier', 'user-registration' ); ?></span>
													<div class="ur-sortable-box">
														<ul class="ur-sortable-list">
															<?php
															echo $upgrade_order_html;
															?>
														</ul>
													</div>
													<span class="ur-upgrade-path-anchor"><?php esc_html_e( '↓ Lowest tier', 'user-registration' ); ?></span>
												</div>
											</div>
										</div>
									</div>
									<input type="hidden" id="ur-membership-upgrade-order" name="ur_membership_upgrade_order" value="<?php echo esc_attr( wp_json_encode( $upgrade_order ) ); ?>">
								</div>
								<input type="hidden" name="ur_membership_upgrade_path" value="<?php echo esc_attr( wp_json_encode( $upgrade_path ) ); ?>"/>
							</div>
						<?php } ?>
					</div>
				</div>
				<hr>
			</div>
		</form>
	</div>
</div>
