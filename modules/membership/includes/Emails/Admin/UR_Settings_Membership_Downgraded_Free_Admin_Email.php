<?php

namespace WPEverest\URMembership\Emails\Admin;

class UR_Settings_Membership_Downgraded_Free_Admin_Email {
	/**
	 * @var string
	 */
	public $id = '';

	/**
	 * Email Title.
	 *
	 * @var string
	 */
	public $title = '';

	/**
	 * Email description.
	 *
	 * @var string
	 */
	public $description = '';

	/**
	 * Email receiver.
	 *
	 * @var string
	 */
	public $receiver = '';

	public function __construct() {
		$this->id          = 'membership_downgraded_free_admin_email';
		$this->title       = __( 'Membership Downgraded to Free Notification', 'user-registration' );
		$this->description = __( 'Notifies the admin when a member\'s membership changes to a free plan.', 'user-registration' );
		$this->receiver    = 'Admin';
	}

	/**
	 * Get settings
	 *
	 * @return array
	 */
	public function get_settings() {

		$settings = apply_filters(
			'user_registration_membership_downgraded_free_admin_email',
			array(
				'title'    => __( 'Membership Downgraded to Free Notification Email', 'user-registration' ),
				'sections' => array(
					'completion_email' => array(
						'title'        => __( 'Membership Downgraded to Free Notification Email', 'user-registration' ),
						'type'         => 'card',
						'desc'         => '',
						'back_link'    => ur_back_link( __( 'Return to emails', 'user-registration' ), admin_url( 'admin.php?page=user-registration-settings&tab=email&section=to-admin' ) ),
						'preview_link' => ur_email_preview_link(
							__( 'Preview', 'user-registration' ),
							$this->id
						),
						'settings'     => array(
							array(
								'title'    => __( 'Enable this email', 'user-registration' ),
								'desc'     => __( 'Enable this email to notify the admin when a member downgrades to a free plan.', 'user-registration' ),
								'id'       => 'user_registration_enable_membership_downgraded_free_admin_email',
								'default'  => 'yes',
								'type'     => 'toggle',
								'autoload' => false,
							),
							array(
								'title'    => __( 'Email Subject', 'user-registration' ),
								'desc'     => __( 'Customize the email subject.', 'user-registration' ),
								'id'       => 'user_registration_membership_downgraded_free_admin_email_subject',
								'type'     => 'text',
								'default'  => __( 'Membership Downgraded to Free: {{username}}', 'user-registration' ),
								'css'      => '',
								'desc_tip' => true,
							),
							array(
								'title'                             => __( 'Email Content', 'user-registration' ),
								'desc'                              => __( 'Customize the content of the membership downgraded email to admin.', 'user-registration' ),
								'id'                                => 'user_registration_membership_downgraded_free_admin_email',
								'type'                              => 'tinymce',
								'default'                           => $this->user_registration_get_membership_downgraded_free_admin_email(),
								'css'                               => '',
								'desc_tip'                          => true,
								'show-ur-registration-form-button'  => false,
								'show-smart-tags-button'            => true,
								'show-reset-content-button'         => true,
							),
						),
					),
				),
			)
		);

		return apply_filters( 'user_registration_get_settings_' . $this->id, $settings );
	}

	/**
	 * Default email body sent to admin when a member's membership changes to a free plan.
	 */
	public function user_registration_get_membership_downgraded_free_admin_email() {
		$body_content = __(
			'<p style="margin: 0 0 16px 0; color: #000000; font-size: 16px; line-height: 1.6;">
				Hi Admin,
			</p>
			<p style="margin: 0 0 16px 0; color: #000000; font-size: 16px; line-height: 1.6;">
				A member\'s membership has changed to a free plan.
			</p>
			<p style="margin: 0 0 16px 0; color: #000000; font-size: 16px; line-height: 1.6;">
				<strong>Member Details:</strong>
				<ul>
				<li style="margin-bottom: 10px;">
					<strong>Name</strong>: {{username}}
				</li>
				<li style="margin-bottom: 10px;">
					<strong>Email</strong>: {{email}}
				</li>
				<li style="margin-bottom: 10px;">
					<strong>Previous Plan</strong>: {{previous_membership_plan_name}}
				</li>
				<li style="margin-bottom: 10px;">
					<strong>New Plan</strong>: {{membership_plan_name}}
				</li>
				</ul>
			</p>
			<p style="margin: 0 0 16px 0; color: #000000; font-size: 16px; line-height: 1.6;">
			Thanks
			</p>
			',
			'user-registration'
		);

		$body_content = ur_wrap_email_body_content( $body_content );

		if ( UR_PRO_ACTIVE && function_exists( 'ur_get_email_template_wrapper' ) ) {
			$body_content = ur_get_email_template_wrapper( $body_content, false );
		}

		$message = apply_filters( 'user_registration_membership_downgraded_free_admin_email_message', $body_content );

		return $message;
	}
}
