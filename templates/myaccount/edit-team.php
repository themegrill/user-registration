<?php
/**
 * Edit Team page
 *
 * This template can be overridden by copying it to yourtheme/user-registration/myaccount/edit-team.php.
 *
 * HOWEVER, on occasion UserRegistration will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.wpuserregistration.com/docs/how-to-edit-user-registration-template-files-such-as-login-form/
 * @package UserRegistration/Templates
 * @version 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( empty( $team ) ) {
	esc_html_e( 'Team not found', 'user-registration' );
	return;
}

$team_id   = absint( $team['ID'] ?? 0 );
$team_name = $team['team_name'] ?? '';
$members   = $team['meta']['urm_member_emails'] ?? [];
$leader_id = (int) ( $team['meta']['urm_team_leader_id'] ?? 0 );

$leader_email = null;
$other_emails = [];

foreach ( $members as $email ) {
	$user = get_user_by( 'email', $email );

	if ( ! $user ) {
		continue;
	}

	if ( $user->ID === $leader_id ) {
		$leader_email = $email;
	} else {
		$other_emails[] = $email;
	}
}

/**
 * Final ordered email list
 */
$ordered_emails = [];

if ( $leader_email ) {
	$ordered_emails[] = $leader_email;
}

$ordered_emails = array_merge( $ordered_emails, $other_emails );
?>

<div class="user-registration-MyAccount-content__body">
	<div class="ur-frontend-form login ur-edit-team" id="ur-frontend-form">
		<div class="user-registration-message-container">
			<?php
			if ( function_exists( 'ur_print_notices' ) ) {
				ur_print_notices();
			}
			?>
		</div>
		<form class="user-registration-EditTeam ur-edit-team-form" method="post" action="" data-max-seats="<?php echo esc_attr( $team['meta']['urm_team_seats'] ?? 0 ); ?>">
			<div class="ur-form-row" style="display: block;">
				<div class="ur-form-grid">
					<?php wp_nonce_field( 'ur_edit_team_' . $team_id, 'ur_edit_team_nonce' ); ?>
					<input type="hidden" name="team_id" value="<?php esc_attr_e( $team_id ); ?>" required>
					<input type="hidden" name="invited_member_emails">
					<input type="hidden" name="existing_member_emails" value="<?php esc_attr_e( implode( ',', $members ) ); ?>">

					<div class="user-registration-form-row user-registration-form-row--wide form-row form-row-wide">
						<label for="team_name" class="ur-label">
							<?php esc_html_e( 'Team Name', 'user-registration' ); ?>
						</label>
						<input
							type="text"
							id="team_name"
							name="team_name"
							class="ur-form-input"
							value="<?php esc_attr_e( $team_name ); ?>"
							required
							placeholder="<?php esc_attr_e( 'Enter team name', 'user-registration' ); ?>"
						>
					</div>

					<div class="user-registration-form-row user-registration-form-row--wide form-row form-row-wide">
						<label for="team_member_email" class="ur-label">
							<?php
								printf(
									esc_html__( 'Members (Max: %s)', 'user-registration' ),
									esc_html( $team['meta']['urm_team_seats'] ?? 0 )
								);
								?>
								<span class="ur-max-seats-reached-error"></span>
						</label>
						<div class="ur-add-member-wrapper">
							<input type="email" class="team_member_email ur-form-input" placeholder="<?php esc_attr_e( 'Add new member', 'user-registration' ); ?>">
							<button disabled type="button" class="ur-add-member-btn-container" aria-label="Add Email">
								+
							</button>
						</div>
						<div class="ur-invited-email-wrapper" style="display: none;"></div>
					</div>
					<div class="ur-member-list-wrapper">
						<p>Members List</p>
						<div class="ur-account-table-container">
							<div class="ur-account-table-wrapper">
								<table class="ur-account-table">
									<thead class="ur-account-table__header">
										<tr class="ur-account-table__row">
											<th class="ur-account-table__cell ur-account-table__header-cell"><?php esc_html_e( 'Email', 'user-registration' ); ?></th>
											<th class="ur-account-table__cell ur-account-table__header-cell"><?php esc_html_e( 'Name', 'user-registration' ); ?></th>
											<th class="ur-account-table__cell ur-account-table__header-cell"><?php esc_html_e( 'Status', 'user-registration' ); ?></th>
											<th class="ur-account-table__cell ur-account-table__header-cell"><?php esc_html_e( 'Action', 'user-registration' ); ?></th>
										</tr>
									</thead>
									<tbody class="ur-account-table__body">
										<?php
										foreach ( $ordered_emails as $member_email ) :
											$member_user = get_user_by( 'email', $member_email );
											?>
											<tr class="ur-account-table__row">
												<td class="ur-account-table__cell ur-account-table__cell--email"><?php esc_html_e( $member_email ); ?></td>
												<td class="ur-account-table__cell ur-account-table__cell--name"><?php echo isset( $member_user->display_name ) ? esc_html( $member_user->display_name ) : __( 'N/A', 'user-registration' ); ?></td>
												<td class="ur-account-table__cell ur-account-table__cell--status">
													<?php
													if ( $team['team_leader']['email'] === $member_email ) {
														esc_html_e( 'Owner', 'user-registration' );
													} else {
														esc_html_e( 'Registered', 'user-registration' );
													}
													?>
												</td>
												<td class="ur-account-table__cell ur-account-table__cell--action">
													<?php if ( ! isset( $member_user->ID ) || (int) $team['meta']['urm_team_leader_id'] !== $member_user->ID ) : ?>
														<a class="ur-account-action-link remove-team-members-button" data-email="<?php esc_attr_e( $member_email ?? '' ); ?>" data-user-id="<?php echo isset( $member_user->ID ) ? esc_attr( $member_user->ID ) : ''; ?>">
															<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x-icon lucide-x">
																<path d="M18 6 6 18"/><path d="m6 6 12 12"/>
															</svg>
															<?php esc_html_e( 'Remove', 'user-registration' ); ?>
														</a>
													<?php else : ?>
														-
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					</div>

					<p>
						<input type="submit" class="user-registration-Button button " name="ur_edit_team_submit" value="<?php esc_attr_e( 'Update Group', 'user-registration' ); ?>">
					</p>
				</div>
			</div>
		</form>
	</div>
</div>
