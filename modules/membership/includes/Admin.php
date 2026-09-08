<?php
/**
 * User_Registration_Membership setup
 *
 * @package User_Registration_Membership
 * @since  1.0.0
 */

namespace WPEverest\URMembership;

use WPEverest\URMembership\Admin\Database\Database;
use WPEverest\URMembership\Admin\Services\SubscriptionService;
use WPEverest\URMembership\Emails\EmailSettings;
use WPEverest\URMembership\Admin\Forms\FormFields;
use WPEverest\URMembership\Admin\Members\Members;
use WPEverest\URMembership\Admin\Membership\Membership;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\MembersRepository;
use WPEverest\URMembership\Admin\Services\EmailService;
use WPEverest\URMembership\Admin\Services\MembershipService;
use WPEverest\URMembership\Admin\Services\MembershipGroupService;
use WPEverest\URMembership\Admin\Services\MembersService;
use WPEverest\URMembership\Admin\Services\PaymentGatewayLogging;
use WPEverest\URMembership\Admin\Services\PaymentGatewaysWebhookActions;
use WPEverest\URMembership\Admin\Services\PaymentService;
use WPEverest\URMembership\Admin\Services\Stripe\StripeService;
use WPEverest\URMembership\Admin\Subscriptions\Subscriptions;
use WPEverest\URMembership\Frontend\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Admin' ) ) :

	/**
	 * Main Membership Class
	 *
	 * @class Membership
	 */
	final class Admin {


		/**
		 * Instance of this class.
		 *
		 * @var object
		 */
		protected static $instance = null;

		/**
		 * Plugin Version
		 *
		 * @var string
		 */
		const VERSION = UR_VERSION;

		/**
		 * Admin class instance
		 *
		 * @var \Admin
		 * @since 1.0.0
		 */
		public $admin = null;

		/**
		 * Admin class instance
		 *
		 * @var use WPEverest\URMembership\Admin\Members\Members;
		 * @since 1.0.0
		 */
		public $members = null;

		/**
		 * Frontend class instance
		 *
		 * @var \Frontend
		 * @since 1.0.0
		 */
		public $frontend = null;

		/**
		 * Ajax.
		 *
		 * @since 1.0.0
		 *
		 * @var use WPEverest\URMembership\AJAX;
		 */
		public $ajax = null;

		/**
		 * Shortcodes.
		 *
		 * @since 1.0.0
		 *
		 * @var use WPEverest\URMembership\Admin\Shortcodes;
		 */
		public $shortcodes = null;

		/**
		 * Return an instance of this class.
		 *
		 * @return object A single instance of this class.
		 */
		public static function get_instance() {
			// If the single instance hasn't been set, set it now.
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor
		 *
		 * @since 1.0.0
		 */
		private function __construct() {
			// Actions and Filters.

			add_filter(
				'plugin_action_links_' . plugin_basename( UR_MEMBERSHIP_PLUGIN_FILE ),
				array(
					$this,
					'plugin_action_links',
				)
			);
			add_action( 'init', array( $this, 'includes' ) );
			add_action( 'init', array( $this, 'create_membership_post_type' ), 0 );
			add_action( 'init', array( $this, 'create_membership_groups_post_type' ), 0 );
			add_action( 'init', array( $this, 'protect_membership_post_meta' ), 0 );
			add_action( 'init', array( 'WPEverest\URMembership\ShortCodes', 'init' ) );
			add_action( 'init', array( $this, 'add_membership_options' ) );
			add_action( 'plugins_loaded', array( $this, 'include_membership_payment_files' ) );
			// add_filter( 'user_registration_get_settings_pages', array( $this, 'add_membership_settings_page' ), 10, 1 );

			add_filter(
				'user_registration_form_redirect_url',
				array(
					$this,
					'update_redirect_url_for_membership',
				),
				10,
				2
			);
			add_filter(
				'user_registration_success_params',
				array(
					$this,
					'set_payment_process_for_membership',
				),
				10,
				4
			);
			add_filter(
				'user_registration_success_params_before_send_json',
				array(
					$this,
					'update_success_params_for_membership',
				),
				10,
				4
			);
			add_filter(
				'user_registration_success_params_before_send_json',
				array(
					$this,
					'process_membership_after_registration',
				),
				20,
				4
			);

			register_deactivation_hook( UR_MEMBERSHIP_PLUGIN_FILE, array( $this, 'on_deactivation' ) );
			register_activation_hook( UR_MEMBERSHIP_PLUGIN_FILE, array( $this, 'on_activation' ) );
			add_filter(
				'user_registration_content_restriction_settings',
				array(
					$this,
					'add_memberships_in_urcr_settings',
				),
				10,
				1
			);
			add_action( 'admin_enqueue_scripts', array( $this, 'register_membership_admin_scripts' ) );
			add_action( 'plugins_loaded', array( __CLASS__, 'ur_membership_maybe_run_migrations' ), 20 );

			add_action( 'user_registration_single_user_details_content', array( $this, 'render_user_membership_details' ), 10, 2 );

			add_action( 'urm_member_registered', array( $this, 'maybe_fire_deferred_after_register' ), 5, 2 );
			add_action( 'urm_member_registered', array( $this, 'send_registration_emails' ), 10, 2 );
			add_action( 'user_registration_check_token_complete', array( $this, 'send_membership_welcome_on_email_confirmation' ), 10, 2 );
		}

		/**
		 * Send the membership welcome email after the user confirms their email address.
		 *
		 * For membership forms the welcome email comes from EmailService (the core UR_Emailer welcome is
		 * `! is_membership_form`-gated). With the `email_confirmation` login option that EmailService
		 * welcome runs via urm_member_registered at registration, while `ur_confirm_email` is still 0, so
		 * its "email confirmed" gate skips it — and nothing re-sends it once the user verifies. Send it here,
		 * now that the email is confirmed. `admin_approval_after_email_confirmation` is excluded: those
		 * members still await admin approval, so their welcome belongs to the approval step. UR-4681.
		 *
		 * @param int  $user_id             Confirmed user ID.
		 * @param bool $user_reg_successful Whether the token check succeeded.
		 */
		public function send_membership_welcome_on_email_confirmation( $user_id, $user_reg_successful ) {
			if ( empty( $user_reg_successful ) || 'email_confirmation' !== ur_get_user_login_option( $user_id ) ) {
				return;
			}

			// Only membership forms need this — non-membership welcome is sent by core UR_Emailer on confirmation.
			if ( ! check_membership_field_in_form( ur_get_form_id_by_userid( $user_id ) ) ) {
				return;
			}

			// Send once.
			if ( ur_string_to_bool( get_user_meta( $user_id, 'ur_membership_confirm_welcome_sent', true ) ) ) {
				return;
			}
			update_user_meta( $user_id, 'ur_membership_confirm_welcome_sent', true );

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return;
			}

			( new EmailService() )->send_email(
				array(
					'member_id' => $user_id,
					'email'     => $user->user_email,
				),
				'user_register_user'
			);
		}

		/**
		 * Replay the deferred `user_registration_after_register_user_action` hook once paid payment
		 * is confirmed, so paid memberships get the approval / email-confirmation emails (and the
		 * confirmation token) that free memberships fire at submission — the hook is suppressed for
		 * paid (see set_payment_process_for_membership()). set_login_gate_for_pending_member() only
		 * seeds the login gate; this sends the emails. Welcome / admin emails are `! is_membership_form`-
		 * gated in UR_Emailer, so this does not duplicate them. UR-4681.
		 *
		 * @param array $data      Member data prepared during registration.
		 * @param int   $member_id Newly created WP user ID.
		 */
		public function maybe_fire_deferred_after_register( $data, $member_id ) {
			$deferred = get_user_meta( $member_id, 'ur_deferred_after_register_args', true );

			if ( empty( $deferred ) || ! is_array( $deferred ) || ! isset( $deferred['form_data'] ) ) {
				return;
			}

			// Fire once — gateways may signal completion more than once (e.g. webhook + return URL).
			if ( ur_string_to_bool( get_user_meta( $member_id, 'ur_deferred_after_register_fired', true ) ) ) {
				return;
			}
			update_user_meta( $member_id, 'ur_deferred_after_register_fired', true );

			$form_id = isset( $deferred['form_id'] ) ? absint( $deferred['form_id'] ) : ur_get_form_id_by_userid( $member_id );

			do_action( 'user_registration_after_register_user_action', $deferred['form_data'], $form_id, $member_id );

			delete_user_meta( $member_id, 'ur_deferred_after_register_args' );
		}

		/**
		 * Sends the member and admin registration emails.
		 *
		 * @param array $data      Member data prepared during registration.
		 * @param int   $member_id Newly created WP user ID.
		 */
		public function send_registration_emails( $data, $member_id ) {
			static $queued = array();
			if ( isset( $queued[ $member_id ] ) ) {
				return;
			}
			$queued[ $member_id ] = true;
			add_action(
				'shutdown',
				function () use ( $data, $member_id ) {
					// Flush the response to the client and close the connection.
					// PHP keeps running so emails can be sent without the user waiting.
					if ( function_exists( 'fastcgi_finish_request' ) ) {
						fastcgi_finish_request();
					} elseif ( function_exists( 'litespeed_finish_request' ) ) {
						litespeed_finish_request();
					}

					try {
						$email_service = new EmailService();
						$email_service->send_email( $data, 'user_register_user' );
						$email_service->send_email( $data, 'user_register_admin' );
					} catch ( \Throwable $e ) {
						// Registrant already received their success response — log and move on.
						if ( function_exists( 'ur_get_logger' ) ) {
							ur_get_logger()->error(
								sprintf( 'urm_member_registered email send failed for member %d: %s', $member_id, $e->getMessage() ),
								array( 'source' => 'user-registration-membership' )
							);
						}
					}
				}
			);
		}

		public function register_membership_admin_scripts() {
			if ( isset( $_GET['post'] ) && isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) {
				// Enqueue frontend scripts here.
				$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
				wp_register_script( 'user-registration-membership-frontend-script', UR()->plugin_url() . '/assets/js/modules/membership/frontend/user-registration-membership-frontend' . $suffix . '.js', array( 'jquery' ), UR_VERSION, true );
				wp_enqueue_script( 'user-registration-membership-frontend-script' );
				// Enqueue frontend styles here.
				wp_register_style( 'user-registration-membership-frontend-style', UR()->plugin_url() . '/assets/css/modules/membership/user-registration-membership-frontend.css', array(), UR_VERSION );
				wp_enqueue_style( 'user-registration-membership-frontend-style' );
			}
		}

		public function add_memberships_in_urcr_settings( $settings ) {
			if ( empty( $settings['sections']['user_registration_content_restriction_settings']['settings'] ) ) {
				return $settings;
			}

			$options             = get_active_membership_id_name();
			$additional_settings = array(
				array(
					'row_class' => 'urcr_content_restriction_allow_access_to_memberships',
					'title'     => __( 'Select Memberships', 'user-registration' ),
					'desc'      => __( 'The memberships selected here will have access to restricted content.', 'user-registration' ),
					'id'        => 'user_registration_content_restriction_allow_to_memberships',
					'type'      => 'multiselect',
					'class'     => 'ur-enhanced-select',
					'css'       => 'min-width: 350px; ' . ( '3' != get_option( 'user_registration_content_restriction_allow_access_to', '0' ) ) ? 'display:none;' : '',
					'desc_tip'  => true,
					'options'   => $options,
				),
			);
			$just_settings       = $settings['sections']['user_registration_content_restriction_settings']['settings'];

			array_splice( $just_settings, 2, 0, $additional_settings );

			$settings['sections']['user_registration_content_restriction_settings']['settings'] = $just_settings;

			return $settings;
		}

		/**
		 * Set payment_process = true for paid membership registrations so the welcome email
		 * is not sent during form submission — it should only fire after payment is confirmed.
		 */
		public function set_payment_process_for_membership( $success_params, $valid_form_data, $form_id, $user_id ) {
			$selected_membership = 0;
			foreach ( (array) $valid_form_data as $field_data ) {
				if ( isset( $field_data->extra_params['field_key'] ) && 'membership' === $field_data->extra_params['field_key'] ) {
					$selected_membership = isset( $field_data->value ) ? absint( $field_data->value ) : 0;
					break;
				}
			}
			if ( empty( $selected_membership ) ) {
				return $success_params;
			}

			// Determine paid vs free from the server-side membership record, not client members_data.
			$membership_repository = new MembershipRepository();
			$membership_data       = $membership_repository->get_single_membership_by_ID( $selected_membership );
			$membership_meta       = ! empty( $membership_data['meta_value'] ) ? json_decode( wp_unslash( $membership_data['meta_value'] ), true ) : array();
			if ( empty( $membership_meta['type'] ) || 'free' === $membership_meta['type'] ) {
				return $success_params;
			}

			$success_params['payment_process'] = true;

			// payment_process suppresses the core after_register hook for paid memberships; stash its
			// args so maybe_fire_deferred_after_register() can replay it once payment completes. UR-4681.
			update_user_meta(
				$user_id,
				'ur_deferred_after_register_args',
				array(
					'form_data' => $valid_form_data,
					'form_id'   => $form_id,
				)
			);

			return $success_params;
		}

		public function update_success_params_for_membership( $success_params, $valid_form_data, $form_id, $user_id ) {
			$keyFound = false;

			foreach ( $valid_form_data as $key => $value ) {
				if ( 'membership' === $value->extra_params['field_key'] ) {
					$keyFound = true;
					break;
				}
			}

			if ( ! $keyFound ) {
				return $success_params;
			}
			$success_params['registration_type'] = 'membership';

			return $success_params;
		}

		public function process_membership_after_registration( $success_params, $valid_form_data, $form_id, $user_id ) {

			// module active
			if ( ! ur_check_module_activation( 'membership' ) ) {
				return $success_params;
			}
			// Membership processing requirement is derived from the server-side selected value of the
			// membership field, never from client POST signals (is_membership_active / membership_type)
			// or members_data — those are trivially omitted to skip enrollment and the payment gate.
			$membership_field_data = null;
			foreach ( $valid_form_data as $field_data ) {
				if ( isset( $field_data->extra_params['field_key'] ) && 'membership' === $field_data->extra_params['field_key'] ) {
					$membership_field_data = $field_data;
					break;
				}
			}
			if ( ! $membership_field_data ) {
				return $success_params;
			}

			$selected_membership = isset( $membership_field_data->value ) ? absint( $membership_field_data->value ) : 0;
			if ( empty( $selected_membership ) ) {
				// No plan selected server-side: no enrollment intended. Leave a plain account.
				return $success_params;
			}

			$member    = get_userdata( $user_id );
			$member_id = $user_id;
			if ( ! $member ) {
				wp_delete_user( absint( $user_id ) );
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid membership selection.', 'user-registration' ) ) );
			}

			$data = apply_filters(
				'user_registration_membership_before_register_member',
				isset( $_POST['members_data'] ) ? (array) json_decode( wp_unslash( $_POST['members_data'] ), true ) : array()
			);
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			// Authoritative membership id from the server-side field value, not client members_data.
			$data['membership'] = $selected_membership;

			// Client-submitted tax_rate must never be negative (would reduce the charge below base).
			if ( isset( $data['tax_rate'] ) ) {
				$data['tax_rate'] = max( 0, floatval( $data['tax_rate'] ) );
			}

			// Validate that the submitted membership ID is one the form is actually configured to offer.
			// Read field config from the stored form definition (post_content), not the runtime
			// submission object — submission objects do not carry general_setting at this point.
			$stored_membership_config = null;
			$form_post                = get_post( absint( $form_id ) );
			if ( $form_post && ! empty( $form_post->post_content ) ) {
				$form_rows = json_decode( $form_post->post_content );
				foreach ( (array) $form_rows as $row ) {
					foreach ( (array) $row as $grid ) {
						foreach ( (array) $grid as $field ) {
							if ( isset( $field->field_key ) && 'membership' === $field->field_key ) {
								$stored_membership_config = $field;
								break 3;
							}
						}
					}
				}
			}

			if ( ! $stored_membership_config ) {
				wp_delete_user( absint( $member_id ) );
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid membership selection.', 'user-registration' ) ) );
			}

			$listing_option         = isset( $stored_membership_config->general_setting->membership_listing_option )
				? $stored_membership_config->general_setting->membership_listing_option
				: 'all';
			$allowed_membership_ids = array();

			if ( 'group' === $listing_option ) {
				$group_id = isset( $stored_membership_config->general_setting->membership_group )
					? absint( $stored_membership_config->general_setting->membership_group )
					: 0;
				if ( $group_id ) {
					$group_service     = new MembershipGroupService();
					$group_memberships = $group_service->get_group_memberships( $group_id );
					foreach ( $group_memberships as $m ) {
						$id = isset( $m['ID'] ) ? (int) $m['ID'] : ( isset( $m['id'] ) ? (int) $m['id'] : 0 );
						if ( $id ) {
							$allowed_membership_ids[] = $id;
						}
					}
				}
			} elseif ( 'selected' === $listing_option ) {
				$selected_ids           = isset( $stored_membership_config->general_setting->membership_active_memberships )
					? $stored_membership_config->general_setting->membership_active_memberships
					: array();
				$selected_ids           = is_array( $selected_ids ) ? $selected_ids : (array) maybe_unserialize( $selected_ids );
				$allowed_membership_ids = array_values( array_filter( array_map( 'absint', $selected_ids ) ) );
			} else {
				$membership_service_temp = new MembershipService();
				foreach ( $membership_service_temp->list_active_memberships() as $m ) {
					$id = isset( $m['ID'] ) ? (int) $m['ID'] : ( isset( $m['id'] ) ? (int) $m['id'] : 0 );
					if ( $id ) {
						$allowed_membership_ids[] = $id;
					}
				}
			}

			if ( empty( $allowed_membership_ids ) || ! in_array( absint( $data['membership'] ), $allowed_membership_ids, true ) ) {
				wp_delete_user( absint( $member_id ) );
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid membership selection.', 'user-registration' ) ) );
			}
			$data['username'] = $member->user_login;
			$data['email']    = $member->user_email;

			// Stripe validation
			if ( 'stripe' === $data['payment_method'] ) {
				if ( ! empty( $data['stripe_pm_error'] ) ) {
					wp_delete_user( absint( $member_id ) );
					wp_send_json_error( array( 'message' => sanitize_text_field( $data['stripe_pm_error'] ) ) );
				}
				if ( ! empty( $data['payment_method_id'] ) ) {
					$stripe_service = new StripeService();
					$mode_result    = $stripe_service->validate_card_mode( sanitize_text_field( $data['payment_method_id'] ) );
					if ( ! $mode_result['valid'] ) {
						wp_delete_user( absint( $member_id ) );
						wp_send_json_error( array( 'message' => $mode_result['message'] ) );
					}
				}
			}

			// Resolve membership type from the server-side membership record.
			$membership_repository = new MembershipRepository();
			$membership_data       = $membership_repository->get_single_membership_by_ID( $data['membership'] );
			if ( empty( $membership_data ) || empty( $membership_data['meta_value'] ) ) {
				wp_delete_user( absint( $member_id ) );
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid membership selection.', 'user-registration' ) ) );
			}
			$membership_meta = json_decode( wp_unslash( $membership_data['meta_value'] ), true );
			$membership_type = $membership_meta['type'] ?? 'unknown';

			// A paid plan needs a payment method to produce a payable order. Missing method (forged or
			// omitted members_data) must fail closed, not create an unpaid payment-gated account.
			if ( 'free' === $membership_type ) {
				$data['payment_method'] = 'free';
			} elseif ( empty( $data['payment_method'] ) ) {
				wp_delete_user( absint( $member_id ) );
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid membership selection.', 'user-registration' ) ) );
			}
			$payment_gateway = $data['payment_method'] ?? 'unknown';

			// Reject attacker-supplied payment_method values that don't match the membership.
			if ( ! ( new MembershipService() )->is_valid_payment_method_for_membership( $membership_meta, $data['payment_method'], $data ) ) {
				wp_delete_user( absint( $member_id ) );
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid payment method for this membership.', 'user-registration' ) ) );
			}

			// PaymentGatewayLogging — session start + form submission
			if ( class_exists( 'WPEverest\URMembership\Admin\Services\PaymentGatewayLogging' ) ) {
				PaymentGatewayLogging::log_general(
					$payment_gateway,
					sprintf( ' [Member ID #%s] ========== ***NEW PAYMENT SESSION*** ==========', $member_id ) . "\n" . wp_json_encode(
						array(
							'timestamp'       => current_time( 'mysql' ),
							'membership_type' => $membership_type,
							'username'        => $member->user_login,
						),
						JSON_PRETTY_PRINT
					),
					'notice'
				);
				PaymentGatewayLogging::log_general(
					$payment_gateway,
					sprintf( ' [Member ID #%s] Membership registration form submitted.', $member_id ) . "\n" . wp_json_encode(
						array(
							'event_type'      => 'form_submission',
							'member_id'       => $member_id,
							'username'        => $member->user_login,
							'email'           => $member->user_email,
							'membership_id'   => $data['membership'] ?? 'N/A',
							'payment_method'  => $payment_gateway,
							'membership_type' => $membership_type,
						),
						JSON_PRETTY_PRINT
					),
					'info'
				);
			}

			// Create order + subscription
			// UR-4573: This is a fresh membership registration, so the membership role should
			// replace the default role the registration assigned (not stack on top of it).
			$data['is_initial_registration'] = true;
			$membership_service              = new MembershipService();
			$response                        = $membership_service->create_membership_order_and_subscription( $data );

			// PaymentGatewayLogging — order creation + free activation
			if ( $response['status'] && class_exists( 'WPEverest\URMembership\Admin\Services\PaymentGatewayLogging' ) ) {
				$initial_status = ( 'free' === $payment_gateway ) ? 'active' : 'pending';
				PaymentGatewayLogging::log_general(
					$payment_gateway,
					sprintf( ' [Member ID #%s] Order and subscription created - Status: %s', $member_id, $initial_status ) . "\n" . wp_json_encode(
						array(
							'event_type'      => 'status_change',
							'member_id'       => $member_id,
							'subscription_id' => $response['subscription_id'] ?? 'N/A',
							'transaction_id'  => $response['transaction_id'] ?? 'N/A',
							'status'          => $initial_status,
							'membership_id'   => $data['membership'] ?? 'N/A',
							'membership_type' => $membership_type,
						),
						JSON_PRETTY_PRINT
					),
					'info'
				);
				if ( 'free' === $payment_gateway ) {
					PaymentGatewayLogging::log_general(
						$payment_gateway,
						sprintf( ' [Member ID #%s] Subscription activated successfully.', $member_id ) . "\n" . wp_json_encode(
							array(
								'member_id'       => $member_id,
								'subscription_id' => $response['subscription_id'] ?? 'N/A',
								'status'          => 'active',
								'payment_method'  => $payment_gateway,
								'membership_type' => $membership_type,
								'auto_activated'  => true,
							),
							JSON_PRETTY_PRINT
						) . "\n  ",
						'info'
					);
				}
			}

			// Set data fields from response
			$transaction_id          = isset( $response['transaction_id'] ) ? $response['transaction_id'] : 0;
			$data['member_id']       = $member_id;
			$data['subscription_id'] = isset( $response['subscription_id'] ) ? $response['subscription_id'] : 0;
			if ( ur_check_module_activation( 'team' ) ) {
				$data['team_id'] = ! empty( $response['team_id'] ) ? $response['team_id'] : 0;
			}
			$data['email']    = $response['member_email'];
			$data['order_id'] = $response['order_id'];

			// Build payment gateway data
			$pg_data = array();
			if ( 'free' !== $data['payment_method'] && $response['status'] ) {
				$payment_service  = new PaymentService( $data['payment_method'], $data['membership'], $data['email'] );
				$ur_authorize_net = array( 'ur_authorize_net' => ! empty( $_POST['ur_authorize_net'] ) ? (array) $_POST['ur_authorize_net'] : array() );
				$data             = array_merge( $data, $ur_authorize_net );

				$pg_data = $payment_service->build_response( $data );
				if ( is_wp_error( $pg_data['payment_url'] ?? null ) ) {
					$message = isset( $response['message'] ) ? $response['message'] : esc_html__( 'Sorry! There was an unexpected error while registering the user.', 'user-registration' );
					wp_send_json_error( array( 'message' => $message ) );
				}
			}

			if ( $response['status'] ) {
				// Auto-login for free memberships
				if ( ! empty( $success_params['auto_login'] ) && 'free' === $data['payment_method'] ) {
					$members_service = new MembersService();
					$password        = isset( $data['password'] ) ? $data['password'] : '';
					$logged_in       = $members_service->login_member( $member_id, true, $password );
					if ( ! $logged_in ) {
						wp_send_json_error( array( 'message' => __( 'Invalid User', 'user-registration' ) ) );
					}
				}

				// Free and bank settle synchronously at registration — no gateway redirect/webhook fires
				// urm_member_registered later — so fire it now (drives send_registration_emails and the
				// deferred after_register replay). Async gateways fire it on payment completion. UR-4681.
				//
				// Exception: bank + 'payment' (payment before login). The bank order is still pending until an
				// admin confirms it, so the welcome must wait for that confirmation — defer it like an async
				// gateway. approve_payment() replays urm_member_registered once the order is marked 'completed',
				// so the welcome is delivered exactly once, after payment, instead of prematurely at submission.
				$defer_bank_payment = ( 'bank' === $data['payment_method'] && 'payment' === ur_get_user_login_option( $member_id ) );

				if ( in_array( $data['payment_method'], array( 'free', 'bank' ), true ) && ! $defer_bank_payment ) {
					do_action( 'urm_member_registered', $data, $member_id );
				} else {
					// Paid async path (and deferred bank+payment) skips user_registration_after_register_user_action
					// (payment_process=true), so the core login-gate setters never run before payment. Seed the gate
					// now; the emails follow on completion via maybe_fire_deferred_after_register(). UR-4681.
					$this->set_login_gate_for_pending_member( $member_id );
					update_user_meta( $member_id, 'ur_membership_registration_data', $data );
				}

				$response_data = apply_filters(
					'user_registration_membership_after_register_member',
					array(
						'member_id'      => absint( $member_id ),
						'transaction_id' => esc_html( $transaction_id ),
						'order_id'       => esc_html( $data['order_id'] ),
						'message'        => get_option( 'user_registration_successful_membership_creation_message', esc_html__( 'New member has been successfully created.', 'user-registration' ) ),
					)
				);
				if ( ur_check_module_activation( 'team' ) ) {
					$response_data['team_id'] = absint( $data['team_id'] );
				}
				if ( 'free' !== $data['payment_method'] ) {
					$response_data['pg_data'] = $pg_data;
				}
				return array_merge( $success_params, $response_data );

			} else {
				$message = isset( $response['message'] ) ? $response['message'] : esc_html__( 'Sorry! There was an unexpected error while registering the user.', 'user-registration' );
				wp_delete_user( absint( $member_id ) );
				wp_send_json_error( array( 'message' => $message ) );
			}
		}

		/**
		 * Seed the admin-approval / email-confirmation login gate for a paid member, since the
		 * core setters on user_registration_after_register_user_action are skipped for paid
		 * registrations. Writes only the gate meta (no emails), so email order is unchanged.
		 * The 'payment' option is gated separately in UR_User_Approval::check_status_on_login().
		 * UR-4681.
		 *
		 * @param int $member_id New member user ID.
		 */
		private function set_login_gate_for_pending_member( $member_id ) {
			$login_option = ur_get_user_login_option( $member_id );

			// Only the approval / email-confirmation gates need seeding here.
			if ( ! in_array( $login_option, array( 'admin_approval', 'email_confirmation', 'admin_approval_after_email_confirmation' ), true ) ) {
				return;
			}

			// Never override a status that has somehow already been written (e.g. the core hook ran).
			if ( metadata_exists( 'user', $member_id, 'ur_user_status' ) || metadata_exists( 'user', $member_id, 'ur_confirm_email' ) ) {
				return;
			}

			if ( 'admin_approval' === $login_option ) {
				$user_manager = new \UR_Admin_User_Manager( $member_id );
				$user_manager->save_status( \UR_Admin_User_Manager::PENDING, false );
			} elseif ( 'email_confirmation' === $login_option ) {
				update_user_meta( $member_id, 'ur_confirm_email', 0 );
			} elseif ( 'admin_approval_after_email_confirmation' === $login_option ) {
				update_user_meta( $member_id, 'ur_confirm_email', 0 );
				update_user_meta( $member_id, 'ur_admin_approval_after_email_confirmation', 'false' );
				update_user_meta( $member_id, 'ur_user_status', \UR_Admin_User_Manager::PENDING );
			}
		}
		public function update_redirect_url_for_membership( $redirect_url, $form_id ) {
			$thank_you_page_id           = get_option( 'user_registration_thank_you_page_id' );
			$login_option                = ur_get_form_setting_by_key( $form_id, 'user_registration_form_setting_login_options' );
			$redirect_after_registration = ur_get_form_setting_by_key( $form_id, 'user_registration_form_setting_redirect_after_registration' );

			$form_data = ur_get_form_field_keys( $form_id );

			$keyFound = false;
			foreach ( $form_data as $value ) {
				if ( preg_match( '/^membership_field_.*/', $value ) ) {
					$keyFound = true;
					break;
				}
			}
			if ( ! $keyFound ) {
				return $redirect_url;
			}

			if ( in_array( $redirect_after_registration, array( 'external-url', 'internal-page', 'previous-page' ) ) ) {
				return $redirect_url;
			}
		}

		/**
		 * Includes.
		 */
		public function includes() {
			$this->ajax = new AJAX();
			if ( $this->is_admin() ) {
				$this->admin = new Membership();
				// $this->members = new Members();
			} else {
				// require file.
				$this->frontend = new Frontend();
			}
			new FormFields();
			new EmailSettings();
			new Crons();
		}

		/**
		 * Check if is admin or not and load the correct class
		 *
		 * @return bool
		 * @since 1.0.0
		 */
		public function is_admin() {
			$check_ajax    = defined( 'DOING_AJAX' ) && DOING_AJAX;
			$check_context = isset( $_REQUEST['context'] ) && 'frontend' === $_REQUEST['context'];

			return is_admin() && ! ( $check_ajax && $check_context );
		}

		/**
		 * Display action links in the Plugins list table.
		 *
		 * @param array $actions Add plugin action link.
		 *
		 * @return array
		 */
		public function plugin_action_links( $actions ) {
			$new_actions = array(
				'settings' => '<a href="' . admin_url( 'admin.php?page=user-registration-membership' ) . '" title="' . esc_attr( __( 'View User Registration & Membership Settings', 'user-registration' ) ) . '">' . __( 'Settings', 'user-registration' ) . '</a>',
			);

			return array_merge( $new_actions, $actions );
		}

		/**
		 * Get the plugin url.
		 *
		 * @return string
		 */
		public function plugin_url() {
			return untrailingslashit( plugins_url( '/', __FILE__ ) );
		}


		/**
		 * Rgister Custom Post Type.
		 */
		public function create_membership_post_type() {
			$raw_referer = wp_parse_args( wp_parse_url( wp_get_raw_referer(), PHP_URL_QUERY ) );

			register_post_type(
				'ur_membership',
				apply_filters(
					'user_registration_membership_post_type',
					array(
						'labels'            => array(
							'name'                  => __( 'Memberships', 'user-registration' ),
							'singular_name'         => __( 'Membership', 'user-registration' ),
							'all_items'             => __( 'All Memberships', 'user-registration' ),
							'menu_name'             => _x( 'Memberships', 'Admin menu name', 'user-registration' ),
							'add_new'               => __( 'Add New', 'user-registration' ),
							'add_new_item'          => __( 'Add new', 'user-registration' ),
							'edit'                  => __( 'Edit', 'user-registration' ),
							'edit_item'             => __( 'Edit membership', 'user-registration' ),
							'new_item'              => __( 'New membership', 'user-registration' ),
							'view'                  => __( 'View membership', 'user-registration' ),
							'view_item'             => __( 'View memberships', 'user-registration' ),
							'search_items'          => __( 'Search memberships', 'user-registration' ),
							'not_found'             => __( 'No memberships found', 'user-registration' ),
							'not_found_in_trash'    => __( 'No memberships found in trash', 'user-registration' ),
							'parent'                => __( 'Parent membership', 'user-registration' ),
							'featured_image'        => __( 'Membership image', 'user-registration' ),
							'set_featured_image'    => __( 'Set membership image', 'user-registration' ),
							'remove_featured_image' => __( 'Remove membership image', 'user-registration' ),
							'use_featured_image'    => __( 'Use as membership image', 'user-registration' ),
							'insert_into_item'      => __( 'Insert into membership', 'user-registration' ),
							'uploaded_to_this_item' => __( 'Uploaded to this membership', 'user-registration' ),
							'filter_items_list'     => __( 'Filter membership', 'user-registration' ),
							'items_list_navigation' => __( 'Membership navigation', 'user-registration' ),
							'items_list'            => __( 'Membership list', 'user-registration' ),

						),
						'show_ui'           => true,
						'capability_type'   => 'post',
						'map_meta_cap'      => true,
						'capabilities'      => $this->get_membership_post_type_capabilities(),
						'show_in_menu'      => false,
						'hierarchical'      => false,
						'rewrite'           => false,
						'query_var'         => false,
						'show_in_nav_menus' => false,
						'show_in_admin_bar' => false,
						'supports'          => array( 'title' ),
					)
				)
			);
		}

		public function create_membership_groups_post_type() {
			$raw_referer = wp_parse_args( wp_parse_url( wp_get_raw_referer(), PHP_URL_QUERY ) );

			register_post_type(
				'ur_membership_groups',
				apply_filters(
					'user_registration_membership_groups_post_type',
					array(
						'labels'            => array(
							'name'                  => __( 'Membership Groups', 'user-registration' ),
							'singular_name'         => __( 'Membership Group', 'user-registration' ),
							'all_items'             => __( 'All Membership Groups', 'user-registration' ),
							'menu_name'             => _x( 'Membership Groups', 'Admin menu name', 'user-registration' ),
							'add_new'               => __( 'Add New', 'user-registration' ),
							'add_new_item'          => __( 'Add new', 'user-registration' ),
							'edit'                  => __( 'Edit', 'user-registration' ),
							'edit_item'             => __( 'Edit membership group', 'user-registration' ),
							'new_item'              => __( 'New membership group', 'user-registration' ),
							'view'                  => __( 'View membership group', 'user-registration' ),
							'view_item'             => __( 'View memberships group', 'user-registration' ),
							'search_items'          => __( 'Search membership groups', 'user-registration' ),
							'not_found'             => __( 'No membership groups found', 'user-registration' ),
							'not_found_in_trash'    => __( 'No membership groups found in trash', 'user-registration' ),
							'parent'                => __( 'Parent membership group', 'user-registration' ),
							'featured_image'        => __( 'Membership group image', 'user-registration' ),
							'set_featured_image'    => __( 'Set membership group image', 'user-registration' ),
							'remove_featured_image' => __( 'Remove membership group image', 'user-registration' ),
							'use_featured_image'    => __( 'Use as membership group image', 'user-registration' ),
							'insert_into_item'      => __( 'Insert into membership group', 'user-registration' ),
							'uploaded_to_this_item' => __( 'Uploaded to this membership group', 'user-registration' ),
							'filter_items_list'     => __( 'Filter membership group', 'user-registration' ),
							'items_list_navigation' => __( 'Membership group navigation', 'user-registration' ),
							'items_list'            => __( 'Membership group list', 'user-registration' ),

						),
						'show_ui'           => true,
						'capability_type'   => 'post',
						'map_meta_cap'      => true,
						'capabilities'      => $this->get_membership_post_type_capabilities(),
						'show_in_menu'      => false,
						'hierarchical'      => false,
						'rewrite'           => false,
						'query_var'         => false,
						'show_in_nav_menus' => false,
						'show_in_admin_bar' => false,
						'supports'          => array( 'title' ),
					)
				)
			);
		}

		/**
		 * Capability map for the membership post types.
		 *
		 * A membership plan stores the role a member is granted on purchase, so authoring one is an
		 * administrative act. Mapping every primitive post capability to manage_options keeps lower
		 * roles out of post-new.php and post.php for these types, and because add_post_meta maps
		 * through edit_post it also keeps them out of core's custom-fields write path.
		 *
		 * @since 5.2.8
		 *
		 * @return array Capability map for register_post_type().
		 */
		private function get_membership_post_type_capabilities() {
			return array(
				'edit_posts'             => 'manage_options',
				'edit_others_posts'      => 'manage_options',
				'edit_published_posts'   => 'manage_options',
				'edit_private_posts'     => 'manage_options',
				'publish_posts'          => 'manage_options',
				'read_private_posts'     => 'manage_options',
				'create_posts'           => 'manage_options',
				'delete_posts'           => 'manage_options',
				'delete_private_posts'   => 'manage_options',
				'delete_published_posts' => 'manage_options',
				'delete_others_posts'    => 'manage_options',
			);
		}

		/**
		 * Protect the membership post meta from core's custom-fields write path.
		 *
		 * These keys hold the plan configuration, including the granted role, and are not prefixed
		 * with an underscore, so is_protected_meta() does not cover them. An auth_callback keeps
		 * core's add-meta handler out even if a site widens the post type capabilities through the
		 * registration filters. The plugin's own update_post_meta() calls are unaffected.
		 *
		 * @since 5.2.8
		 */
		public function protect_membership_post_meta() {
			$protected_meta = array(
				'ur_membership'        => array( 'ur_membership', 'ur_membership_description' ),
				'ur_membership_groups' => array( 'urmg_memberships', 'urmg_mode', 'urmg_upgrade_type', 'urmg_upgrade_path', 'urmg_default_group' ),
			);

			foreach ( $protected_meta as $post_type => $meta_keys ) {
				foreach ( $meta_keys as $meta_key ) {
					register_post_meta(
						$post_type,
						$meta_key,
						array(
							'single'        => true,
							'show_in_rest'  => false,
							'auth_callback' => array( $this, 'can_manage_membership_meta' ),
						)
					);
				}
			}
		}

		/**
		 * Whether the current user may write membership post meta.
		 *
		 * Registered as a register_post_meta() auth_callback, which WordPress invokes with the
		 * filter arguments below. AND-ing with $allowed means this can only ever narrow a core
		 * decision, never widen one.
		 *
		 * @since 5.2.8
		 *
		 * @param bool   $allowed   Whether the write is allowed so far.
		 * @param string $meta_key  Meta key being written.
		 * @param int    $object_id Post ID.
		 * @param int    $user_id   User attempting the write.
		 * @return bool True when the user can manage the site's options.
		 */
		public function can_manage_membership_meta( $allowed = false, $meta_key = '', $object_id = 0, $user_id = 0 ) {
			return $allowed && current_user_can( 'manage_options' );
		}

		/**
		 * Adds the membership options to the database.
		 *
		 * This function adds the payment gateways for the membership plugin to the
		 * WordPress options table. The payment gateways are stored in the 'ur_membership_payment_gateways'
		 * option and are an array containing the strings 'Paypal', 'Stripe', and 'Bank'.
		 *
		 * @return void
		 */
		public function add_membership_options() {
			/**
			 * Filters that holds the list of payment gateways to be stored in ur_membership_payment_gateways option.
			 */
			$membership_payment_gateways = apply_filters(
				'user_registration_membership_payment_gateways',
				array(
					'paypal' => __( 'PayPal', 'user-registration' ),
					'stripe' => __( 'Stripe', 'user-registration' ),
					'bank'   => __( 'Bank', 'user-registration' ),
				)
			);
			update_option(
				'ur_membership_payment_gateways',
				$membership_payment_gateways
			);
		}

		/**
		 * Includes the necessary payment files for the membership plugin if PayPal is activated.
		 *
		 * This function checks if PayPal is activated by calling the `ur_check_module_activation()` function.
		 * If PayPal is activated, it instantiates a new `PaypalActions` object.
		 *
		 * @return void
		 */
		public function include_membership_payment_files() {
			new PaymentGatewaysWebhookActions();
		}

		/**
		 * Creates the necessary database tables for the plugin.
		 *
		 * This function calls the `create_tables` method of the `Database` class to create the necessary tables for the plugin.
		 *
		 * @return void
		 */
		public static function on_activation() {
			Database::create_tables();
		}

		/**
		 * Deactivates the plugin by dropping the database tables.
		 *
		 * @return void
		 */
		public static function on_deactivation() {
			if ( get_option( 'user_registration_general_setting_uninstall_option' ) ) {
				Database::drop_tables();
			}
		}

		/**
		 * add_membership_settings_page
		 *
		 * @param $settings
		 *
		 * @return mixed
		 */
		public function add_membership_settings_page( $settings ) {
			if ( class_exists( 'UR_Settings_Page' ) ) {
				$settings[] = include 'Admin/Settings/class-ur-settings-membership.php';
			}

			return $settings;
		}

		/**
		 * Maybe run database migrations.
		 *
		 * @return void
		 */
		public static function ur_membership_maybe_run_migrations() {
			if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
				return;
			}

			$installed_version = get_option( 'ur_membership_db_version', '0.0.0' );

			if ( version_compare( $installed_version, '1.0.0', '<' ) || ! Database::tables_exist() ) {
				self::on_activation();
				if ( Database::tables_exist() ) {
					update_option( 'ur_membership_db_version', '1.0.0' );
				}
			}

			if ( version_compare( $installed_version, '1.0.1', '<' ) ) {
				self::on_activation();
				update_option( 'ur_membership_db_version', '1.0.1' );
			}
		}

		public function render_user_membership_details( $user_id, $form_id ) {

			if ( ur_check_module_activation( 'membership' ) === false ) {
				return;
			}

			$members_repository = new MembersRepository();
			$memberships        = $members_repository->get_member_memberships_by_id( $user_id );

			if ( empty( $memberships ) ) {
				return;
			}

			ob_start();
			?>
			<div class="urm-admin-user-content-container">
				<div id="urm-admin-user-content-header" >
					<h3>
						<?php
						if ( count( $memberships ) > 1 ) {
							esc_html_e( 'Membership Details', 'user-registration' );
						} else {
							esc_html_e( 'Membership Detail', 'user-registration' );
						}
						?>
					</h3>
				</div>
				<div class="user-registration-user-form-details">
					<table class="wp-list-table widefat fixed striped users">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Plan Type', 'user-registration' ); ?></th>
								<th><?php esc_html_e( 'Amount', 'user-registration' ); ?></th>
								<th><?php esc_html_e( 'Status', 'user-registration' ); ?></th>
								<th><?php esc_html_e( 'Starts On', 'user-registration' ); ?></th>
								<th><?php esc_html_e( 'Expires On', 'user-registration' ); ?></th>
								<th><?php esc_html_e( 'Action', 'user-registration' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $memberships as $membership ) {
								$plan_details = json_decode( $membership['post_content'], true );
								$amount       = $membership['billing_amount'];
								$currencies   = ur_payment_integration_get_currencies();
								$currency     = get_option( 'user_registration_payment_currency', 'USD' );

								$symbol = $currencies[ $currency ]['symbol'];
								$amount = ( ! empty( $currencies[ $currency ]['symbol_pos'] ) && 'left' === $currencies[ $currency ]['symbol_pos'] ) ? $symbol . number_format( $amount, 2 ) : number_format( $amount, 2 ) . $symbol;

								if ( isset( $plan_details['type'] ) && 'subscription' === $plan_details['type'] ) {
									$amount = $amount . ' / ' . $membership['billing_cycle'];
								}
								$expiry_date = 'subscription' === $plan_details['type'] && ! empty( $membership['expiry_date'] ) ? date_i18n( 'Y-m-d', strtotime( $membership['expiry_date'] ) ) : __( 'N/A', 'user-registration' );

								?>
								<tr>
									<td><?php echo esc_html( $membership['post_title'] ); ?></td>
									<td><?php echo esc_html( $amount ); ?></td>
									<?php $pending_cancel_date = get_user_meta( $user_id, 'urm_pending_cancel_' . ( $membership['subscription_id'] ?? '' ), true ); ?>
								<td class="<?php echo $pending_cancel_date ? 'status-pending' : 'status-' . esc_attr( $membership['status'] ); ?>">
										<?php
										if ( $pending_cancel_date ) {
											echo '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;vertical-align:middle;margin-right:4px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'; // phpcs:ignore WordPress.Security.EscapeOutput
											echo esc_html(
												sprintf(
													/* translators: %s: cancellation date */
													__( 'Cancels %s', 'user-registration' ),
													date_i18n( get_option( 'date_format' ), strtotime( $pending_cancel_date ) )
												)
											);
										} else {
											echo esc_html( ucfirst( $membership['status'] ) );
										}
										?>
									</td>
									<td><?php echo ! empty( $membership['start_date'] ) ? esc_html( date_i18n( 'Y-m-d', strtotime( $membership['start_date'] ) ) ) : __( 'N/A', 'user-registration' ); ?></td>
									<td><?php echo esc_html( $expiry_date ); ?></td>
									<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=user-registration-subscriptions&action=edit&id=' . ( $membership['subscription_id'] ?? 0 ) ) ); ?>"><?php esc_html_e( 'View', 'user-registration' ); ?></a></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
				</div>
			</div>
			<?php

			echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
endif;
