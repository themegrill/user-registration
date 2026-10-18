<?php
/**
 * UserRegistrationContentRestriction URCR_AJAX
 *
 * AJAX Event Handler
 *
 * @class    URCR_AJAX
 * @version  1.0.0
 * @package  UserRegistrationContentRestriction/Classes
 * @category Class
 * @author   WPEverest
 */

defined( 'ABSPATH' ) || exit;

/**
 * URCR_AJAX Class
 */
class URCR_AJAX {
	/**
	 * Register ajax handlers.
	 */
	public static function init() {
		/**
		 * Register ajax handlers.
		 */
		add_action( 'wp_ajax_urcr_create_content_rules', array( __CLASS__, 'ajax_create_create_content_rules' ) );
		add_action( 'wp_ajax_urcr_update_rule_status', array( __CLASS__, 'ajax_update_rule_status' ) );
		add_action( 'wp_ajax_user_registration_check_advanced_logic_rules', array( __CLASS__, 'ajax_check_advanced_logic_rules' ) );
	}



	/**
	 * Ajax handler: Create create_content_rules.
	 *
	 * @since 1.2.5
	 */
	public static function ajax_create_create_content_rules() {

		if ( current_user_can( 'edit_posts' ) ) {
			check_ajax_referer( 'urcr_manage_content_access_rule', 'security' );
			if ( isset( $_POST ) ) {
				$data = $_POST;
			}

			$content_rule_name = isset( $data['contentName'] ) ? $data['contentName'] : '';
			$access_control    = isset( $data['accessControlValue'] ) ? $data['accessControlValue'] : '';
			$content_url       = isset( $data['url'] ) ? $data['url'] : '';
			$content_url       = add_query_arg(
				array(
					'name'           => $content_rule_name,
					'access_control' => $access_control,
				),
				$content_url
			);
			wp_send_json_success(
				array(
					'redirect' => $content_url,
				)
			);
		} else {
			wp_send_json_error(
				array(
					'error' => esc_html__( 'Something went wrong, please try again later', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Ajax handler: Create content access rule.
	 *
	 * @since 2.0.0
	 */
	public static function ajax_create_content_access_rule_handler() {
		if ( current_user_can( 'publish_posts' ) ) {
			$_nonce = isset( $_REQUEST['_wpnonce'] ) ? $_REQUEST['_wpnonce'] : '';

			if ( ! wp_verify_nonce( $_nonce, 'urcr_manage_content_access_rule' ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Nonce error. You might wanna refresh the page.', 'user-registration' ),
					)
				);
				return;
			}

			$access_rule_post = self::prepare_access_rule_as_wp_post( 'create-content-access-rule' );

			do_action( 'urcr_pre_create_content_access_rule', $access_rule_post );

			$rule_id = wp_insert_post( $access_rule_post );

			if ( $rule_id ) {
				do_action( 'urcr_post_create_content_access_rule', $access_rule_post, $rule_id );
				wp_send_json_success(
					array(
						'rule_id' => $rule_id,
						'message' => esc_html__( 'Successfully created an Access Rule.', 'user-registration' ),
					)
				);
			} else {
				do_action( 'urcr_create_content_access_rule_failure', $access_rule_post, $rule_id );
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Sorry! There was an unexpected error while creating the Content Access Rule.', 'user-registration' ),
					)
				);
			}
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry! You do not have permission to create Content Access Rule.', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Ajax handler: Save content access rule.
	 *
	 * @since 2.0.0
	 */
	public static function ajax_save_access_rule_handler() {
		if ( current_user_can( 'edit_posts' ) ) {
			$_nonce = isset( $_REQUEST['_wpnonce'] ) ? $_REQUEST['_wpnonce'] : '';

			if ( ! wp_verify_nonce( $_nonce, 'urcr_manage_content_access_rule' ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Nonce error. You might wanna refresh the page.', 'user-registration' ),
					)
				);
				return;
			}

			$access_rule_post = self::prepare_access_rule_as_wp_post( 'save-content-access-rule' );

			if ( ! empty( $access_rule_post['ID'] ) ) {
				do_action( 'urcr_pre_save_content_access_rule', $access_rule_post );

				$rule_id = wp_insert_post( $access_rule_post );

				if ( $rule_id ) {
					do_action( 'urcr_post_save_content_access_rule', $access_rule_post, $rule_id );
					wp_send_json_success(
						array(
							'rule_id' => $rule_id,
							'message' => esc_html__( 'Successfully saved the Access Rule.', 'user-registration' ),
						)
					);
				} else {
					do_action( 'urcr_save_content_access_rule_failure', $access_rule_post, $rule_id );
					wp_send_json_error(
						array(
							'message' => esc_html__( 'Sorry! There was an unexpected error while saveing the Content Access Rule.', 'user-registration' ),
						)
					);
				}
			} else {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Sorry! ID is required to update an Access Rule.', 'user-registration' ),
					)
				);
			}
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry! You do not have permission to edit Content Access Rules.', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Ajax handler: Save content access rule as a draft.
	 *
	 * @since 2.0.0
	 */
	public static function ajax_save_access_rule_as_draft_handler() {
		if ( current_user_can( 'edit_posts' ) ) {
			$_nonce = isset( $_REQUEST['_wpnonce'] ) ? $_REQUEST['_wpnonce'] : '';

			if ( ! wp_verify_nonce( $_nonce, 'urcr_manage_content_access_rule' ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Nonce error. You might wanna refresh the page.', 'user-registration' ),
					)
				);
				return;
			}

			$access_rule_post = self::prepare_access_rule_as_wp_post( 'save-content-access-rule-as-draft', null, 'draft' );

			do_action( 'urcr_pre_save_content_access_rule_as_daft', $access_rule_post );

			$rule_id = wp_insert_post( $access_rule_post );

			if ( $rule_id ) {
				do_action( 'urcr_post_save_content_access_rule_as_daft', $access_rule_post, $rule_id );
				wp_send_json_success(
					array(
						'rule_id' => $rule_id,
						'message' => esc_html__( 'Successfully saved the draft Access Rule.', 'user-registration' ),
					)
				);
			} else {
				do_action( 'urcr_save_content_access_rule_as_daft_failure', $access_rule_post, $rule_id );
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Sorry! There was an unexpected error while saving the Content Access Rule as draft.', 'user-registration' ),
					)
				);
			}
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry! You do not have permission to edit Content Access Rules.', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Prepare a content acess rule data as a WP post.
	 *
	 * @since 2.0.0
	 *
	 * @param array  $access_rule_data
	 * @param string $post_status
	 *
	 * @return array WP_Post data in array.
	 */
	public static function prepare_access_rule_as_wp_post( $context = '', $access_rule_data = null, $post_status = 'publish' ) {
		if ( ! $access_rule_data ) {
			$access_rule_data = json_decode( wp_unslash( $_POST['access_rule_data'] ), true );
		}

		// Unslash data before encoding to prevent double-escaping issues with quotes in HTML content
		$access_rule_data = wp_unslash( $access_rule_data );
		$access_rule_data = wp_json_encode( $access_rule_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		// Slash the JSON string so wp_insert_post() unslashing doesn't corrupt the JSON
		$access_rule_data = wp_slash( $access_rule_data );
		$rule_id          = ! empty( $_POST['rule_id'] ) ? $_POST['rule_id'] : '';

		return apply_filters(
			'urcr_prepared_access_rule_as_wp_post',
			array(
				'ID'             => $rule_id,
				'post_title'     => sanitize_text_field( $_POST['title'] ),
				'post_content'   => $access_rule_data,
				'post_type'      => 'urcr_access_rule',
				'post_status'    => $post_status,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			$context
		);
	}

	public static function ajax_enable_disable_access_rule_handler() {

		if ( current_user_can( 'edit_posts' ) ) {

			if ( ! isset( $_POST['rule_id'] )  || ! isset( $_POST['enabled'] ) ) {
				wp_send_json_error();
			}

			$_nonce = isset( $_POST['security'] ) ? $_POST['security']  : '' ;
			if ( ! wp_verify_nonce( $_nonce, 'urcr_manage_content_access_rule' ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Nonce error. You might wanna refresh the page.', 'user-registration' ),
					)
				);
				return;
			}

			$rule_id = $_POST['rule_id'];

			if ( ! empty( $rule_id ) ) {

				$content_rule = get_post( $rule_id );

				$content_rule_content  = json_decode( $content_rule->post_content, true );

				$content_rule_content['enabled'] = ( $_POST['enabled'] === "true" ) ? true : false;
				$enabled_text = ( $_POST['enabled'] === "true" ) ? "enabled" : "disabled";

				$content_rule->post_content = json_encode( $content_rule_content );

				$saved_post = wp_insert_post( $content_rule );

				if ( $saved_post ) {
					wp_send_json_success(
						array(
							'rule_id' => $saved_post,
							'message' => sprintf( esc_html__( 'Successfully %s the Access Rule.', 'user-registration' ), $enabled_text ),
						)
					);
				} else {
					wp_send_json_error(
						array(
							'message' => esc_html__( 'Sorry! There was an unexpected error while saving the Content Access Rule.', 'user-registration' ),
						)
					);
				}

			} else {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Sorry! ID is required to update an Access Rule.', 'user-registration' ),
					)
				);
			}
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry! You do not have permission to edit Content Access Rules.', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Ajax handler: Update rule status from viewer.
	 *
	 * @since 4.0
	 */
	public static function ajax_update_rule_status() {
		if ( current_user_can( 'edit_posts' ) ) {
			check_ajax_referer( 'urcr_manage_content_access_rule', 'security' );

			$rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
			$enabled = isset( $_POST['enabled'] ) ? absint( $_POST['enabled'] ) : 0;

			if ( empty( $rule_id ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Rule ID is required.', 'user-registration' ),
					)
				);
				return;
			}

			$content_rule = get_post( $rule_id );

			if ( ! $content_rule || 'urcr_access_rule' !== $content_rule->post_type ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Invalid rule ID.', 'user-registration' ),
					)
				);
				return;
			}

			$content_rule_content = json_decode( $content_rule->post_content, true );
			$content_rule_content['enabled'] = ( 1 === $enabled ) ? true : false;
			$enabled_text = ( 1 === $enabled ) ? 'enabled' : 'disabled';

			$content_rule->post_content = wp_json_encode( $content_rule_content );

			$saved_post = wp_insert_post( $content_rule );

			if ( $saved_post ) {
				wp_send_json_success(
					array(
						'rule_id' => $saved_post,
						'message' => sprintf( esc_html__( 'Successfully %s the Access Rule.', 'user-registration' ), $enabled_text ),
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Sorry! There was an unexpected error while saving the Content Access Rule.', 'user-registration' ),
					)
				);
			}
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry! You do not have permission to edit Content Access Rules.', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Ajax handler: Update rule from viewer.
	 *
	 * @since 4.0
	 */
	public static function ajax_update_rule_from_viewer() {
		if ( current_user_can( 'edit_posts' ) ) {
			check_ajax_referer( 'urcr_manage_content_access_rule', 'security' );

			$rule_id        = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
			$access_control = isset( $_POST['access_control'] ) ? sanitize_text_field( $_POST['access_control'] ) : 'access';
			$redirect_url   = isset( $_POST['redirect_url'] ) ? esc_url_raw( $_POST['redirect_url'] ) : '';

			if ( empty( $rule_id ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Rule ID is required.', 'user-registration' ),
					)
				);
				return;
			}

			$content_rule = get_post( $rule_id );

			if ( ! $content_rule || 'urcr_access_rule' !== $content_rule->post_type ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Invalid rule ID.', 'user-registration' ),
					)
				);
				return;
			}

			$content_rule_content = json_decode( $content_rule->post_content, true );

			if ( ! is_array( $content_rule_content ) ) {
				$content_rule_content = array();
			}

			if ( ! isset( $content_rule_content['actions'] ) || ! is_array( $content_rule_content['actions'] ) ) {
				$content_rule_content['actions'] = array();
			}

			if ( empty( $content_rule_content['actions'] ) ) {
				$content_rule_content['actions'][] = array();
			}

			$content_rule_content['actions'][0]['access_control'] = $access_control;
			if ( ! empty( $redirect_url ) ) {
				$content_rule_content['actions'][0]['type']         = 'redirect';
				$content_rule_content['actions'][0]['redirect_url'] = $redirect_url;
			}

			$content_rule->post_content = wp_json_encode( $content_rule_content );

			$saved_post = wp_insert_post( $content_rule );

			if ( $saved_post ) {
				wp_send_json_success(
					array(
						'rule_id' => $saved_post,
						'message' => esc_html__( 'Successfully saved the Access Rule.', 'user-registration' ),
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Sorry! There was an unexpected error while saving the Content Access Rule.', 'user-registration' ),
					)
				);
			}
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Sorry! You do not have permission to edit Content Access Rules.', 'user-registration' ),
				)
			);
		}
	}

	/**
	 * Ajax handler: Check if rules with advanced logic exist.
	 *
	 * @since 4.0
	 */
	public static function ajax_check_advanced_logic_rules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'You do not have permission to check advanced logic rules.', 'user-registration' ),
				)
			);
			return;
		}

		check_ajax_referer( 'user_registration_settings_nonce', 'security' );

		if ( function_exists( 'urcr_has_rules_with_advanced_logic' ) ) {
			$has_advanced_logic = urcr_has_rules_with_advanced_logic();

			wp_send_json_success(
				array(
					'has_advanced_logic' => $has_advanced_logic,
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Function not found.', 'user-registration' ),
				)
			);
		}
	}
}

URCR_AJAX::init();

