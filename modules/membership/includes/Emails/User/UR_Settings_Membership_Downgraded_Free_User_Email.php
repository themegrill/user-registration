<?php

namespace WPEverest\URMembership\Emails\User;

class UR_Settings_Membership_Downgraded_Free_User_Email {
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
		$this->id          = 'membership_downgraded_free_user_email';
		$this->title       = __( 'Membership Downgraded to Free', 'user-registration' );
		$this->description = __( 'Confirms to the user that their membership has changed to a free plan.', 'user-registration' );
		$this->receiver    = 'User';
	}

	/**
	 * Get settings
	 *
	 * @return array
	 */
	public function get_settings() {

		$settings = apply_filters(
			'user_registration_membership_downgraded_free_user_email',
			array(
				'title'    => __( 'Membership Downgraded to Free Email', 'user-registration' ),
				'sections' => array(
					'completion_email' => array(
						'title'        => __( 'Membership Downgraded to Free Email', 'user-registration' ),
						'type'         => 'card',
						'desc'         => '',
						'back_link'    => ur_back_link( __( 'Return to emails', 'user-registration' ), admin_url( 'admin.php?page=user-registration-settings&tab=email&section=to-user' ) ),
						'preview_link' => ur_email_preview_link(
							__( 'Preview', 'user-registration' ),
							$this->id
						),
						'settings'     => array(
							array(
								'title'    => __( 'Enable this email', 'user-registration' ),
								'desc'     => __( 'Enable this email to notify the user when their membership changes to a free plan.', 'user-registration' ),
								'id'       => 'user_registration_enable_membership_downgraded_free_user_email',
								'default'  => 'yes',
								'type'     => 'toggle',
								'autoload' => false,
							),
							array(
								'title'    => __( 'Email Subject', 'user-registration' ),
								'desc'     => __( 'Customize the email subject.', 'user-registration' ),
								'id'       => 'user_registration_membership_downgraded_free_user_email_subject',
								'type'     => 'text',
								'default'  => __( 'Your membership has changed to {{membership_plan_name}}', 'user-registration' ),
								'css'      => '',
								'desc_tip' => true,
							),
							array(
								'title'                             => __( 'Email Content', 'user-registration' ),
								'desc'                              => __( 'Customize the content of the membership downgraded email to the user.', 'user-registration' ),
								'id'                                => 'user_registration_membership_downgraded_free_user_email',
								'type'                              => 'tinymce',
								'default'                           => $this->user_registration_get_membership_downgraded_free_user_email(),
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
	 * Default email body sent to a member when their membership changes to a free plan.
	 */
	public function user_registration_get_membership_downgraded_free_user_email() {
		$body_content = __(
			'<p style="margin: 0 0 16px 0; color: #000000; font-size: 16px; line-height: 1.6;">
				Hi {{username}},</p>
				<p style="margin: 0 0 16px 0; color: #000000; font-size: 16px; line-height: 1.6;">
				Your membership has changed from {{previous_membership_plan_name}} to {{membership_plan_name}}.
				</p>
				<p style="margin: 0 0 16px 0; color: #000000; font-size: 16px; line-height: 1.6;">
				You can review your current plan and benefits anytime from your account dashboard.
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

		$message = apply_filters( 'user_registration_membership_downgraded_free_user_email_message', $body_content );

		return $message;
	}
}
