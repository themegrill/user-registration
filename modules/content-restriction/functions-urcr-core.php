<?php
/**
 * UserRegistrationContentRestriction Functions.
 *
 * General core functions available on both the front-end and admin.
 *
 * @author   WPEverest
 * @category Core
 * @package  UserRegistrationContentRestriction/Functions
 * @version  1.0.0
 */

defined( 'ABSPATH' ) || exit;

function urcr_get_allow_options() {
	global $wp_roles;

	if ( ! class_exists( 'WP_Roles' ) ) {
		return array();
	}

	if ( ! isset( $wp_roles ) ) {
		$wp_roles = new WP_Roles();
	}

	$roles     = isset( $wp_roles->roles ) ? $wp_roles->roles : array();
	$all_roles = array();

	foreach ( $roles as $role_key => $role ) {
		$all_roles[ $role_key ] = $role['name'];
	}

	return apply_filters( 'user_registration_content_restriction_to_roles', $all_roles );
}

/**
 * Clean variables using sanitize_text_field. Arrays are cleaned recursively.
 * Non-scalar values are ignored.
 *
 * @param string|array $var Data to sanitize.
 *
 * @return string|array
 */
function urcr_clean( $var ) {
	if ( is_array( $var ) ) {
		return array_map( 'urcr_clean', $var );
	} else {
		return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
	}
}

/**
 * Get a list containing all available capabilities.
 *
 * @return array List of capabilities.
 * @since 2.0.0
 */
function urcr_get_all_capabilities() {
	global $wp_roles;

	$wp_capabilities = array();

	foreach ( $wp_roles->roles as $role ) {
		$role_cap_slugs    = array_keys( $role['capabilities'] );
		$role_capabilities = array_combine( $role_cap_slugs, $role_cap_slugs );
		$wp_capabilities   = array_merge( $wp_capabilities, $role_capabilities );
	}

	return apply_filters( 'urcr_capabilities_list', $wp_capabilities );
}

/**
 * Get list of page IDs that should be excluded from whole site content restriction.
 * This function retrieves page IDs from various options and allows filtering for extensibility.
 *
 * @return array Array of page IDs to exclude from restriction.
 * @since 4.0.0
 */
function urcr_get_excluded_page_ids() {
	$option_names = array(
		'user_registration_login_page_id',
		'user_registration_login_options_login_redirect_url',
		'user_registration_myaccount_page_id',
		'user_registration_member_registration_page_id',
		'user_registration_thank_you_page_id',
		'user_registration_lost_password_page_id',
		'user_registration_membership_pricing_page_id',
	);

	/**
	 * Filter the list of option names to check for excluded page IDs.
	 *
	 * @param array $option_names Array of option names to check.
	 *
	 * @since 4.0.0
	 */
	$option_names = apply_filters( 'urcr_excluded_page_ids_option_names', $option_names );

	$excluded_page_ids = array();

	foreach ( $option_names as $option_name ) {
		$page_id = get_option( $option_name );
		if ( ! empty( $page_id ) && is_numeric( $page_id ) ) {
			$excluded_page_ids[] = absint( $page_id );
		}
	}

	$excluded_page_ids = array_values( array_unique( $excluded_page_ids ) );

	/**
	 * Filter the list of excluded page IDs from whole site content restriction.
	 *
	 * @param array $excluded_page_ids Array of page IDs to exclude.
	 *
	 * @since 4.0.0
	 */
	return apply_filters( 'urcr_excluded_page_ids', $excluded_page_ids );
}

/**
 * Check if a page ID should be excluded from whole site content restriction.
 *
 * @param int $page_id The page ID to check.
 *
 * @return bool True if the page should be excluded, false otherwise.
 * @since 4.0.0
 */
function urcr_is_page_excluded( $page_id ) {
	if ( empty( $page_id ) ) {
		return false;
	}

	$excluded_page_ids = urcr_get_excluded_page_ids();

	return in_array( absint( $page_id ), $excluded_page_ids, true );
}

/**
 * See if the given access rule is enabled.
 *
 * @param array $access_rule Acess Rule.
 *
 * @return bool
 * @since 2.0.0
 */
function urcr_is_access_rule_enabled( $access_rule = array() ) {
	$access_rule = (array) $access_rule;

	if ( isset( $access_rule['enabled'] ) && true === $access_rule['enabled'] ) {
		return true;
	}

	return false;
}

/**
 * See if any action has been specified for a content acess rule.
 *
 * @param array $access_rule Access Rule.
 *
 * @return bool
 * @since 2.0.0
 */
function urcr_is_action_specified( $access_rule = array() ) {
	$access_rule = (array) $access_rule;

	if ( ! empty( $access_rule['actions'] ) ) {
		$actions = (array) $access_rule['actions'];

		foreach ( $actions as $action ) {
			if ( ! empty( $action['type'] ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * See if the post is in the provided targets list.
 *
 * @param array       $targets Targets list.
 * @param object|null $target_post Post to check against.
 *
 * @return bool
 * @since 2.0.0
 */
function urcr_is_target_post( $targets = array(), $target_post = null ) {

	if ( is_array( $targets ) ) {
		foreach ( $targets as $target ) {
			if ( isset( $target['type'] ) && ! empty( $target['value'] ) ) {

				$result = apply_filters(
					'urcr_match_target_type',
					null,
					$target,
					$target_post
				);

				if ( true === $result ) {
					return true;
				}

				if ( false === $result ) {
					continue;
				}

				switch ( $target['type'] ) {
					case 'wp_posts':
						$post_id         = ( 'object' === gettype( $target_post ) && $target_post->ID ) ? strval( $target_post->ID ) : '0';
						$target_post_ids = (array) $target['value'];

						if ( in_array( $post_id, $target_post_ids, true ) ) {
							return true;
						}
						break;

					case 'wp_pages':
						$page_id         = ( 'object' === gettype( $target_post ) && $target_post->ID ) ? strval( $target_post->ID ) : '0';
						$target_page_ids = (array) $target['value'];

						if ( in_array( $page_id, $target_page_ids, true ) ) {
							return true;
						}
						break;

					case 'post_types':
						$post_type         = ( 'object' === gettype( $target_post ) && $target_post->post_type ) ? strval( $target_post->post_type ) : '';
						$post_type         = ( is_singular( 'product' ) && is_array( $target_post ) ) ? $target_post[0]->post_type : $post_type;
						$target_post_types = (array) $target['value'];

						if ( in_array( $post_type, $target_post_types, true ) ) {
							return true;
						}
						$products_page_id = intval( get_option( 'woocommerce_shop_page_id' ) );
						if ( is_object( $target_post ) && (int) $products_page_id === $target_post->ID ) {
							return true;
						}

						break;

					case 'taxonomy':
						if ( ! empty( $target['taxonomy'] ) && ! empty( $target['value'] ) ) {
							$products_page_id = intval( get_option( 'woocommerce_shop_page_id' ) );
							$post_taxonomies  = get_post_taxonomies( $target_post );
							if ( is_numeric( $target_post ) && $target_post == $products_page_id ) {
								return true;
							}

							if ( ! in_array( $target['taxonomy'], (array) $post_taxonomies ) ) {
								break;
							}

							/**
							 * Filter to modify the post taxonomy status.
							 *
							 * @since xx.xx.xx
							 */
							$post_status = apply_filters( 'user_registration_membership_post_taxonomy_status', '' );

							if ( ! empty( $post_status ) && isset( $target_post->post_status ) && $target_post->post_status === $post_status ) {
								break;
							}

							$terms = get_the_terms( $target_post, $target['taxonomy'] );

							if ( empty( $terms ) || is_wp_error( $terms ) ) {
								break;
							}

							foreach ( $terms as $term ) {
								if ( in_array( $term->term_id, $target['value'] ) ) {
									return true;
								}
							}
						}
						break;
					case 'whole_site':
						return true;
						break;

					default:
						break;
				}
			}
		}
	}

	return false;
}

/**
 * See if the required conditions are met by the given logic map to be resolved as true.
 *
 * @param array $targets Targets list.
 *
 * @return bool
 * @deprecated 1.1.2
 */
function urcr_is_current_target( $targets = array() ) {
	if ( function_exists( 'ur_deprecated_function' ) ) {
		ur_deprecated_function( 'urcr_is_current_target', '1.1.2', 'urcr_is_target_post' );
	}

	return urcr_is_target_post( $targets, null );
}

/**
 * See if the required conditions are met by the given logic map to be resolved as true.
 *
 * @param array       $logic_map Logic Map.
 * @param object|null $target_post Post to check against.
 *
 * @return bool
 * @since 2.0.0
 */
function urcr_is_allow_access( $logic_map = array(), $target_post = null ) {
	global $post;

	if ( ! is_object( $target_post ) ) {
		$target_post = $post;
	}

	$logic_map = (array) $logic_map;

	if ( ! empty( $logic_map ) ) {
		$type = $logic_map['type'];

		if ( 'group' === $type ) {
			$gate = ! empty( $logic_map['logic_gate'] ) ? $logic_map['logic_gate'] : 'OR';

			foreach ( $logic_map['conditions'] as $sub_logic_map ) {
				$is_allow_access = urcr_is_allow_access( $sub_logic_map, $target_post );

				if ( 'AND' === $gate && false === $is_allow_access ) {
					return false;
				} elseif ( 'NOT' === $gate && true === $is_allow_access ) {
					return false;
				} elseif ( 'OR' === $gate && true === $is_allow_access ) {
					return true;
				}
			}
			if ( 'AND' === $gate || 'NOT' === $gate ) {
				return true;
			}
			if ( 'OR' === $gate ) {
				return false;
			}
		} else {
			$user = wp_get_current_user();

			switch ( $type ) {
				case 'roles':
					if ( $user->ID && count( array_intersect( (array) $user->roles, $logic_map['value'] ) ) ) {
						return true;
					}
					break;

				case 'capabilities':
					if ( $user->ID ) {
						$allowed_caps = isset( $logic_map['value'] ) ? (array) $logic_map['value'] : array();

						foreach ( $allowed_caps as $cap ) {
							if ( current_user_can( $cap, $target_post->ID ) ) {
								return true;
							}
						}
					}
					break;

				case 'user_registered_date':
					if ( $user->ID ) {
						$registered_date = ! empty( $user->data->user_registered ) ? $user->data->user_registered : '';

						$date_value = '';
						$date_type  = 'range';

						if ( ! empty( $logic_map['value'] ) ) {
							if ( is_array( $logic_map['value'] ) && isset( $logic_map['value']['value'] ) && isset( $logic_map['value']['type'] ) ) {
								$date_value = $logic_map['value']['value'];
								$date_type  = $logic_map['value']['type'];
							} else {
								$date_value = (string) $logic_map['value'];
								$date_type  = 'range';
							}
						}

						if ( empty( $date_value ) || empty( $registered_date ) ) {
							break;
						}

						$registered_timestamp = strtotime( $registered_date );

						if ( 'before' === $date_type ) {
							$target_timestamp = strtotime( $date_value );
							if ( $registered_timestamp < $target_timestamp ) {
								return true;
							}
						} elseif ( 'after' === $date_type ) {
							$target_timestamp = strtotime( $date_value );
							if ( $registered_timestamp > $target_timestamp ) {
								return true;
							}
						} else {
							$date_range = explode( ' to ', $date_value );
							$start_date = ! empty( $date_range[0] ) ? trim( $date_range[0] ) : '';
							$end_date   = ! empty( $date_range[1] ) ? trim( $date_range[1] ) : $start_date;

							if ( ! empty( $start_date ) && ! empty( $end_date ) && ur_falls_in_date_range( $registered_date, $start_date, $end_date ) ) {
								return true;
							}
						}
					}
					break;

				case 'access_period':
					if ( $user->ID ) {
						$registered_date = ! empty( $user->data->user_registered ) ? $user->data->user_registered : '';
						$access_period   = ! empty( $logic_map['value'] ) ? $logic_map['value'] : '';

						$now             = time();
						$registered_time = strtotime( $registered_date );
						$datediff        = $now - $registered_time;

						$actual_days = round( $datediff / ( 60 * 60 * 24 ) );

						if ( ! empty( $access_period['input'] ) ) {
							if ( 'During' === $access_period['select'] && $actual_days <= $access_period['input'] ) {
								return true;
							} elseif ( 'After' === $access_period['select'] && $actual_days > 0 && $actual_days > $access_period['input'] ) {
								return true;
							}
						}
					}
					break;

				case 'ur_form_field':
					if ( $user->ID ) {
						$registered_date = ! empty( $user->data->user_registered ) ? $user->data->user_registered : '';
						$form_field_data = ! empty( $logic_map['value'] ) ? $logic_map['value'] : '';
						$user_form_id    = ur_get_form_id_by_userid( $user->ID );

						if ( isset( $form_field_data['form_id'] ) && $form_field_data['form_id'] == $user_form_id ) {
							$flag = array();

							foreach ( $form_field_data['form_fields'] as $key => $data ) {
								$field_name = str_replace( 'user_registration_', '', $data['field_name'] );
								$field_name = ur_get_field_name_with_prefix_usermeta( $field_name );

								switch ( $field_name ) {
									case 'user_login':
										$user_field_value = $user->user_login;
										break;
									case 'user_nicename':
										$user_field_value = $user->user_nicename;
										break;
									case 'user_email':
										$user_field_value = $user->user_email;
										break;
									case 'user_url':
										$user_field_value = $user->user_url;
										break;
									case 'display_name':
										$user_field_value = $user->display_name;
										break;

									default:
										$user_field_value = get_user_meta( $user->ID, $field_name, true );
										break;
								}

								$user_field_value = is_array( $user_field_value ) ? implode( ',', $user_field_value ) : strval( $user_field_value );

								if ( 'is' == $data['operator'] && $user_field_value === $data['value'] ) {
									array_push( $flag, true );
								} elseif ( 'is not' == $data['operator'] && $user_field_value !== $data['value'] ) {
									array_push( $flag, true );
								} elseif ( 'empty' === $data['operator'] && empty( $user_field_value ) ) {
									array_push( $flag, true );
								} elseif ( 'not empty' === $data['operator'] && ! empty( $user_field_value ) ) {
									array_push( $flag, true );
								}
							}

							if ( count( $flag ) === count( $form_field_data['form_fields'] ) ) {
								return true;
							}
						}
					}
					break;

				case 'user_state':
					$should_be_logged_in = ( isset( $logic_map['value'] ) && 'logged-in' === $logic_map['value'] ) ? true : false;

					if ( $should_be_logged_in ) {
						return is_user_logged_in();
					} else {
						return ! is_user_logged_in();
					}
					break;

				case 'profile_completeness':
					$completeness_level = get_user_meta( $user->ID, 'user_registration_profile_completeness_completed_profile_percentage', true );
					$completeness_level = ! empty( $completeness_level ) ? (int) $completeness_level : 100;

					$threshold = ! empty( $logic_map['value'] ) ? (int) $logic_map['value'] : 0;

					if ( $completeness_level > $threshold ) {
						return true;
					}

					break;

				case 'post_count':
					if ( $user->ID ) {
						$public_posts_by_user_count   = (int) count_user_posts( $user->ID, 'post', true );
						$minimum_required_posts_count = ! empty( $logic_map['value'] ) ? (int) $logic_map['value'] : 0;

						if ( $public_posts_by_user_count >= $minimum_required_posts_count ) {
							return true;
						}
					}
					break;

				case 'email_domain':
					if ( $user->ID ) {
						$domains           = ! empty( $logic_map['value'] ) ? explode( ',', (string) $logic_map['value'] ) : array();
						$domains           = array_map( 'trim', $domains );
						$user_email        = explode( '@', $user->data->user_email );
						$user_email_domain = isset( $user_email[1] ) ? trim( $user_email[1] ) : '';

						if ( in_array( $user_email_domain, $domains, true ) ) {
							return true;
						}
					}
					break;

				case 'registration_source':
					if ( $user->ID ) {
						$registered_source = ur_get_registration_source_id( $user->ID );
						$sources           = ! empty( $logic_map['value'] ) ? $logic_map['value'] : array();
						$sources           = is_array( $sources ) ? $sources : ( ! empty( $sources ) ? array( $sources ) : array() );

						if ( ! empty( $registered_source ) && in_array( $registered_source, $sources, true ) ) {
							return true;
						}
					}
					break;

				case 'membership':
					if ( ur_check_module_activation( 'membership' ) ) {
						static $urcr_active_plan_ids = null;
						if ( null === $urcr_active_plan_ids ) {
							$urcr_active_plan_ids = array_map( 'absint', array_keys( get_active_membership_id_name() ) );
						}

						$sources = ! empty( $logic_map['value'] ) ? $logic_map['value'] : array();
						$sources = is_array( $sources ) ? $sources : array( $sources );

						// Rule references only deactivated plan(s): stop enforcing so the page isn't a dead end.
						if ( ! array_intersect( array_map( 'absint', $sources ), $urcr_active_plan_ids ) ) {
							return true;
						}

						if ( ! $user->ID ) {
							break;
						}

						$members_repository = new \WPEverest\URMembership\Admin\Repositories\MembersRepository();
						$user_membership    = $members_repository->get_member_membership_by_id( $user->ID );

						$team_ids = get_user_meta( $user->ID, 'urm_team_ids', true );

						if ( ! empty( $team_ids ) ) {
							if ( ! is_array( $team_ids ) ) {
								$team_ids = array( $team_ids );
							}

							foreach ( $team_ids as $team_id ) {
								$team_membership_id = get_post_meta( $team_id, 'urm_membership_id', true );
								$team_members_ids   = get_post_meta( $team_id, 'urm_member_ids', true );

								if ( ! empty( $team_membership_id ) && ! empty( $team_members_ids ) && is_array( $team_members_ids ) && in_array( $user->ID, $team_members_ids, true ) ) {
									$lead_id = get_post_meta( $team_id, 'urm_team_leader_id', true );

									if ( ! empty( $lead_id ) ) {
										$leader_membership = $members_repository->get_member_membership_by_id( $lead_id );

										if ( ! empty( $leader_membership ) && is_array( $leader_membership ) ) {
											foreach ( $leader_membership as $membership ) {
												if (
													! empty( $membership['post_id'] ) &&
													(int) $membership['post_id'] === (int) $team_membership_id &&
													! empty( $membership['status'] ) &&
													in_array( $membership['status'], array( 'active', 'trial' ), true ) &&
													in_array( $membership['post_id'], $sources, true )
												) {
													return true;
												}
											}
										}
									}
								}
							}
						}

						if ( ! empty( $user_membership ) && is_array( $user_membership ) ) {
							foreach ( $user_membership as $membership ) {
								if ( ! empty( $membership['status'] ) && in_array( $membership['status'], array( 'active', 'trial' ), true ) ) {
									if ( ! empty( $membership['post_id'] ) && in_array( $membership['post_id'], $sources, true ) ) {
										return true;
									}
								}
							}
						}
					}
			}

			return false;
		}
	}

	return true;
}

/**
 * See if the required conditions are met by the given logic map to be resolved as true.
 *
 * @param array $logic_map Logic Map.
 *
 * @return bool
 * @deprecated 1.1.2
 */
function urcr_resolve_logic_map( $logic_map = array() ) {
	if ( function_exists( 'ur_deprecated_function' ) ) {
		ur_deprecated_function( 'urcr_resolve_logic_map', '1.1.2', 'urcr_is_allow_access' );
	}

	return urcr_is_allow_access( $logic_map, null );
}

/**
 * See if a elementor content have been restricted and shown a message.
 *
 * @return boolean
 * @since 1.1.3
 */
function urcr_is_elementor_content_restricted() {
	return isset( $GLOBALS['urcr_ecr_flag'] ) && $GLOBALS['urcr_ecr_flag'] === true;
}

/**
 * Set a flag to indicate that a elementor content have been restricted and shown a message.
 *
 * @since 1.1.3
 */
function urcr_set_elementor_content_restricted() {
	$GLOBALS['urcr_ecr_flag'] = true;
}

/**
 * Apply content restriction to the current content.
 *
 * @param array       $actions Sequence of actions to run.
 * @param object|null $target_post Post to check against.
 *
 * @return bool
 * @since 2.0.0
 */
function urcr_apply_content_restriction( $actions, &$target_post = null ) {
	global $post;

	if ( ! is_object( $target_post ) ) {
		$target_post = $post;
	}

	$actions = (array) $actions;
	$action  = $actions[0];

	$run_rule = apply_filters( 'urcr_pre_confirm_access_rules_implementation', true, $target_post );

	if ( false === $run_rule ) {
		return false;
	}

	if ( isset( $target_post->ID ) && $target_post->ID && ! empty( $action['type'] ) ) {
		if ( 'message' === $action['type'] ) {
			$message = ! empty( $action['message'] ) ? urldecode( $action['message'] ) : get_option( 'user_registration_content_restriction_message', '' );
			$message = apply_filters( 'user_registration_process_smart_tags', $message );
			if ( function_exists( 'apply_shortcodes' ) ) {
				$message = apply_shortcodes( $message );
			} else {
				$message = do_shortcode( $message );
			}

			$login_page_id        = get_option( 'user_registration_login_page_id' );
			$registration_page_id = get_option( 'user_registration_member_registration_page_id' );

			$login_url  = $login_page_id ? get_permalink( $login_page_id ) : wp_login_url();
			$signup_url = $registration_page_id ? get_permalink( $registration_page_id ) : ( $login_page_id ? get_permalink( $login_page_id ) : wp_registration_url() );

			if ( ! $registration_page_id ) {
				$default_form_page_id = get_option( 'user_registration_default_form_page_id' );
				if ( $default_form_page_id ) {
					$signup_url = get_permalink( $default_form_page_id );
				}
			}

			$is_whole_site_restriction    = false;
			$whole_site_access_restricted = ur_string_to_bool( get_option( 'user_registration_content_restriction_whole_site_access', false ) );

			if ( $whole_site_access_restricted ) {
				$is_whole_site_restriction = true;
			} else {
				$access_rule_posts = get_posts(
					array(
						'numberposts' => - 1,
						'post_status' => 'publish',
						'post_type'   => 'urcr_access_rule',
					)
				);

				foreach ( $access_rule_posts as $access_rule_post ) {
					$access_rule = json_decode( $access_rule_post->post_content, true );
					if ( ! empty( $access_rule['target_contents'] ) && is_array( $access_rule['target_contents'] ) ) {
						$types = wp_list_pluck( $access_rule['target_contents'], 'type' );
						if ( in_array( 'whole_site', $types, true ) ) {
							$is_whole_site_restriction = true;
							break;
						}
					}
				}
			}

			ob_start();
			urcr_get_template(
				'base-restriction-template.php',
				array(
					'message'    => $message,
					'login_url'  => $login_url,
					'signup_url' => $signup_url,
				)
			);
			$styled_content = ob_get_clean();

			$target_post->post_content = $styled_content;

			add_filter( 'comments_open', '__return_false', PHP_INT_MAX );
			add_filter( 'pings_open', '__return_false', PHP_INT_MAX );
			add_filter( 'get_comments_number', '__return_zero', PHP_INT_MAX );
			add_filter( 'the_post_navigation', '__return_empty_string', PHP_INT_MAX );
			add_filter( 'get_next_post', '__return_null', PHP_INT_MAX );
			add_filter( 'get_previous_post', '__return_null', PHP_INT_MAX );
			
			$is_elementor_page = class_exists( 'Elementor\Plugin' )
				&& 'builder' === get_post_meta( $target_post->ID, '_elementor_edit_mode', true );

			if ( $is_elementor_page ) {
				add_filter(
					'elementor/frontend/the_content',
					function () use ( $styled_content ) {
						if ( ! urcr_is_elementor_content_restricted() ) {
							urcr_set_elementor_content_restricted();

							return $styled_content;
						}

						return '';
					}
				);

				if ( $is_whole_site_restriction ) {
					$target_post->post_content = '';
				}
			}

			return true;
		} elseif ( 'redirect' === $action['type'] ) {
			$redirect_url = trim( ! empty( $action['redirect_url'] ) ? $action['redirect_url'] : admin_url() );
			$redirect_url = urldecode( $redirect_url );

			if ( strpos( $redirect_url, 'http' ) !== 0 ) {
				$redirect_url = 'http://' . $redirect_url;
			}
			wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		} elseif ( 'redirect_to_local_page' === $action['type'] ) {

			$page_id = ! empty( $action['local_page'] ) ? $action['local_page'] : null;

			if ( $target_post->ID && strval( $page_id ) === strval( $target_post->ID ) ) {
				wp_die( esc_html__( 'URCR: Cannot redirect to same page. The target page was selected as redirection target for content restriction.', 'user-registration' ) );
			}
			if ( $page_id ) {
				$page_url = get_page_link( $page_id );
				wp_redirect( $page_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
		} elseif ( 'ur-form' === $action['type'] ) {
			$ur_form_id                = ! empty( $action['ur_form'] ) ? $action['ur_form'] : '';
			$shortcode                 = sprintf( '[user_registration_form id="%s"]', $ur_form_id );
			$target_post->post_content = $shortcode;

			// Add filter for elementor content.
			add_filter(
				'elementor/frontend/the_content',
				function () use ( $shortcode ) {
					if ( ! urcr_is_elementor_content_restricted() ) {
						urcr_set_elementor_content_restricted();

						return $shortcode;
					}

					return '';
				}
			);

			return true;
		} elseif ( 'shortcode' === $action['type'] && ! empty( $action['shortcode'] ) ) {
			$shortcode_tag  = ! empty( $action['shortcode']['tag'] ) ? $action['shortcode']['tag'] : '';
			$shortcode_args = ! empty( $action['shortcode']['args'] ) ? urldecode( $action['shortcode']['args'] ) : '';

			if ( ! preg_match( '/id=["\']?(\d+)["\']?/', $shortcode_args, $matches ) ) {
				$shortcode_id = trim( $shortcode_args );

				if ( is_numeric( $shortcode_id ) ) {
					$shortcode_args = 'id="' . $shortcode_id . '"';
				} else {
					$shortcode_args = 'id=' . $shortcode_id;
				}
			}

			$shortcode = sprintf( '[%s %s]', $shortcode_tag, $shortcode_args );

			$target_post->post_content = $shortcode;

			// Add filter for elementor content.
			add_filter(
				'elementor/frontend/the_content',
				function () use ( $shortcode ) {
					if ( ! urcr_is_elementor_content_restricted() ) {
						urcr_set_elementor_content_restricted();

						return $shortcode;
					}

					return '';
				}
			);

			return true;
		}
	}

	return false;
}

/**
 * Get other templates (e.g. my account) passing attributes and including the file.
 *
 * @param string $template_name Template Name.
 * @param array  $args Extra arguments(default: array()).
 * @param string $template_path Path of template provided (default: '').
 * @param string $default_path Default path of template provided(default: '').
 */
function urcr_get_template( $template_name, $args = array(), $template_path = '', $default_path = '' ) {
	if ( ! empty( $args ) && is_array( $args ) ) {
		extract( $args ); // phpcs:ignore
	}

	$located = urcr_locate_template( $template_name, $template_path, $default_path );

	$located = apply_filters( 'urcr_get_template', $located, $template_name, $args, $template_path, $default_path );

	if ( ! file_exists( $located ) ) {
		_doing_it_wrong( __FUNCTION__, sprintf( '<code>%s</code> does not exist.', esc_html( $located ) ), '1.0' );

		return;
	}

	do_action( 'user_registration_content_restriction_before_template_part', $template_name, $template_path, $located, $args );

	include $located;

	do_action( 'user_registration_content_restriction_after_template_part', $template_name, $template_path, $located, $args );
}

/**
 * Locate a template and return the path for inclusion.
 *
 * This is the load order:
 *
 *        yourtheme        /    $template_path    /    $template_name
 *        yourtheme        /    $template_name
 *        $default_path    /    $template_name
 *
 * @param string $template_name Template Name.
 * @param string $template_path Path of template provided (default: '').
 * @param string $default_path Default path of template provided(default: '').
 *
 * @return string
 */
function urcr_locate_template( $template_name, $template_path = '', $default_path = '' ) {
	if ( ! $template_path ) {
		$template_path = UR()->plugin_path() . '/templates/modules/content-restriction/';
	}

	if ( ! $default_path ) {
		$default_path = UR()->plugin_path() . '/templates/modules/content-restriction/';
	}

	$template = locate_template(
		array(
			trailingslashit( $template_path ) . $template_name,
			$template_name,
		)
	);

	if ( ! $template || UR_TEMPLATE_DEBUG_MODE ) {
		$template = $default_path . $template_name;
	}

	return apply_filters( 'user_registration_content_restriction_locate_template', $template, $template_name, $template_path );
}

/**
 * Common function for template access actions
 *
 * @param mixed $target_post Post.
 * @param mixed $action Action.
 */
function urcr_advanced_access_actions( $target_post, $action ) {
	if ( $target_post->ID && ! empty( $action['type'] ) ) {
		if ( 'message' === $action['type'] ) {
			$message = ! empty( $action['message'] ) ? urldecode( $action['message'] ) : '';
			$message = apply_filters( 'user_registration_process_smart_tags', $message );
			if ( function_exists( 'apply_shortcodes' ) ) {
				echo apply_shortcodes( $message );
			} else {
				echo do_shortcode( $message );
			}
		} elseif ( 'redirect' === $action['type'] ) {
			$redirect_url = trim( ! empty( $action['redirect_url'] ) ? $action['redirect_url'] : admin_url() );
			$redirect_url = urldecode( $redirect_url );

			if ( strpos( $redirect_url, 'http' ) !== 0 ) {
				$redirect_url = 'http://' . $redirect_url;
			}
			echo "<script>window.location.href = '" . esc_url( $redirect_url ) . "';</script>";

			wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		} elseif ( 'redirect_to_local_page' === $action['type'] ) {
			$page_id = ! empty( $action['local_page'] ) ? $action['local_page'] : null;

			if ( $target_post->ID && strval( $page_id ) === strval( $target_post->ID ) ) {
				wp_die( esc_html__( 'URCR: Cannot redirect to same page. The target page was selected as redirection target for content restriction.', 'user-registration' ) );
			}
			if ( $page_id ) {
				$page_url = get_page_link( $page_id );
				echo "<script>window.location.href = '" . esc_url( $page_url ) . "';</script>";
				wp_redirect( $page_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
		} elseif ( 'ur-form' === $action['type'] ) {
			$ur_form_id = ! empty( $action['ur_form'] ) ? $action['ur_form'] : '';
			$shortcode  = sprintf( '[user_registration_form id="%s"]', $ur_form_id );
			if ( function_exists( 'apply_shortcodes' ) ) {
				echo apply_shortcodes( $shortcode );
			} else {
				echo do_shortcode( $shortcode );
			}
		} elseif ( 'shortcode' === $action['type'] && ! empty( $action['shortcode'] ) ) {
			$shortcode_tag  = ! empty( $action['shortcode']['tag'] ) ? $action['shortcode']['tag'] : '';
			$shortcode_args = ! empty( $action['shortcode']['args'] ) ? urldecode( $action['shortcode']['args'] ) : '';
			$shortcode      = sprintf( '[%s %s]', $shortcode_tag, $shortcode_args );
			if ( function_exists( 'apply_shortcodes' ) ) {
				echo apply_shortcodes( $shortcode );
			} else {
				echo do_shortcode( $shortcode );
			}
		}
	}
}

function ur_restrict_files( $file, $restriction_conditions, $restricted_files ) {
	$restriction_conditions  = json_decode( $restriction_conditions, true );
	$restrict_logout_user    = isset( $restriction_conditions['is_logout'] ) ? $restriction_conditions['is_logout'] : false;
	$restrict_based_on_role  = isset( $restriction_conditions['role'] ) ? $restriction_conditions['role'] : array();
	$restriction_redirection = isset( $restriction_conditions['redirect'] ) ? $restriction_conditions['redirect'] : false;
	$restriction_message     = isset( $restriction_conditions['message'] ) ? $restriction_conditions['message'] : false;
	$default_message         = __( "You don't have permission to access this file.", 'user-registration' );

	$pattern   = '/' . basename( WP_CONTENT_DIR ) . '\/(.+)/';
	$file_name = preg_match( $pattern, $file, $matches ) ? $matches[1] : '';
	if ( in_array( $file_name, $restricted_files ) ) {

		$is_user_logged_in = is_user_logged_in();
		if ( $restrict_logout_user && ! $is_user_logged_in ) {
			if ( $restriction_redirection ) {
				wp_redirect( $restriction_redirection );
				exit;
			} else {
				echo $restriction_message ? $restriction_message : $default_message;
				exit;
			}
		} else {
			$fp = fopen( $file, 'r' );
			header( 'Content-type: ' . mime_content_type( $file ) );
			fpassthru( $fp );
			fclose( $fp );
			exit;
		}

		if ( is_array( $restrict_based_on_role ) && ! empty( $restrict_based_on_role ) ) {
			$user = wp_get_current_user();
			foreach ( $restrict_based_on_role as $role ) {
				if ( in_array( $role, $user->roles ) ) {
					if ( $restriction_redirection ) {
						wp_redirect( $restriction_redirection );
						exit;
					} else {
						echo $restriction_message ? $restriction_message : $default_message;
						exit;
					}
				}
			}
		} else {
			$fp = fopen( $file, 'r' );
			header( 'Content-type: ' . mime_content_type( $file ) );
			fpassthru( $fp );
			fclose( $fp );
			exit;
		}

		$current_user = wp_get_current_user();
		if ( ! empty( $current_user->roles ) && in_array( 'administrator', $current_user->roles ) ) {
			$fp = fopen( $file, 'r' );
			header( 'Content-type: ' . mime_content_type( $file ) );
			fpassthru( $fp );
			fclose( $fp );
			exit;
		}
		header( 'HTTP/1.0 403 Forbidden' );
		exit;

	} else {
		$fp = fopen( $file, 'r' );
		header( 'Content-type: ' . mime_content_type( $file ) );
		fpassthru( $fp );
		fclose( $fp );
		exit;
	}
}

/**
 * Build conditions array based on allow_to option value.
 * This is a reusable function for migration.
 *
 * @param int $allow_to_value The allow_to option value (0, 1, 2, or 3).
 *
 * @return array Array of conditions.
 */
function urcr_build_migration_conditions( $allow_to_value ) {
	$conditions = array();
	$timestamp  = time() * 1000; // JavaScript timestamp format

	switch ( $allow_to_value ) {
		case 0: // All logged in users
			$conditions[] = array(
				'type'  => 'user_state',
				'id'    => 'x' . ( $timestamp + 1 ),
				'value' => 'logged-in',
			);
			break;

		case 1: // Choose specific roles
			$allowed_roles = get_option( 'user_registration_content_restriction_allow_to_roles', 'administrator' );
			$roles_array   = array();

			if ( ! empty( $allowed_roles ) ) {
				if ( is_string( $allowed_roles ) ) {
					$decoded     = json_decode( $allowed_roles, true );
					$roles_array = is_array( $decoded ) ? $decoded : array( $allowed_roles );
				} elseif ( is_array( $allowed_roles ) ) {
					$roles_array = $allowed_roles;
				}
			}

			if ( ! empty( $roles_array ) ) {
				$conditions[] = array(
					'type'  => 'roles',
					'id'    => 'x' . ( $timestamp + 2 ),
					'value' => $roles_array,
				);
			}
			break;

		case 2: // Guest users
			$conditions[] = array(
				'type'  => 'user_state',
				'id'    => 'x' . ( $timestamp + 3 ),
				'value' => 'logged-out',
			);
			break;

		case 3: // Memberships
			$allowed_memberships = get_option( 'user_registration_content_restriction_allow_to_memberships', '' );
			$memberships_array   = array();

			if ( ! empty( $allowed_memberships ) ) {
				if ( is_string( $allowed_memberships ) ) {
					$decoded           = json_decode( $allowed_memberships, true );
					$memberships_array = is_array( $decoded ) ? $decoded : array( $allowed_memberships );
				} elseif ( is_array( $allowed_memberships ) ) {
					$memberships_array = $allowed_memberships;
				}
			}

			if ( ! empty( $memberships_array ) ) {
				$conditions[] = array(
					'type'  => 'membership',
					'id'    => 'x' . ( $timestamp + 4 ),
					'value' => array_map( 'strval', $memberships_array ), // Ensure string values
				);
			}
			break;
	}

	return $conditions;
}

/**
 * Create a migrated rule post.
 *
 * @param string $title Rule title.
 * @param array  $rule_data Rule data array.
 *
 * @return int|false Rule ID on success, false on failure.
 */
function urcr_create_migrated_rule( $title, $rule_data ) {
	$rule_data    = wp_unslash( $rule_data );
	$rule_content = wp_json_encode( $rule_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$rule_content = wp_slash( $rule_content );

	$rule_post = array(
		'post_title'   => $title,
		'post_content' => $rule_content,
		'post_type'    => 'urcr_access_rule',
		'post_status'  => 'publish',
	);

	$rule_id = wp_insert_post( $rule_post );

	if ( $rule_id && ! is_wp_error( $rule_id ) ) {
		update_post_meta( $rule_id, 'urcr_is_migrated', true );

		return $rule_id;
	}

	return false;
}

/**
 * Migrate global restriction settings to content access rules.
 *
 * @return int|false Rule ID on success, false on failure.
 */
function urcr_migrate_global_restriction_settings() {
	$migration_done = get_option( 'urcr_global_restriction_migrated', false );
	if ( $migration_done ) {
		return false;
	}

	$whole_site_access = get_option( 'user_registration_content_restriction_whole_site_access', false );

	$allow_to = get_option( 'user_registration_content_restriction_allow_access_to', 0 );
	$allow_to = absint( $allow_to );

	$conditions = urcr_build_migration_conditions( $allow_to );

	$timestamp = time() * 1000;

	$logic_map = array(
		'type'       => 'group',
		'id'         => 'x' . $timestamp,
		'conditions' => $conditions,
		'logic_gate' => 'AND',
	);

	$target_contents = array(
		array(
			'id'   => 'x' . ( $timestamp + 100 ),
			'type' => 'whole_site',
		),
	);

	$rule_data = array(
		'enabled'         => true,
		'access_control'  => 'access',
		'logic_map'       => $logic_map,
		'target_contents' => ur_string_to_bool( $whole_site_access ) ? $target_contents : array(),
		'actions'         => urcr_build_migration_actions( 'content', $timestamp ),
	);

	$rule_id = urcr_create_migrated_rule( __( 'Legacy: Global Site Rule', 'user-registration' ), $rule_data );

	if ( $rule_id ) {
		update_option( 'urcr_global_restriction_migrated', true );
		// To track it is global.
		update_post_meta( $rule_id, 'urcr_is_global', true );
		update_option( 'urcr_is_global', $rule_id );
		delete_option( 'user_registration_content_restriction_whole_site_access' );

		return $rule_id;
	}

	return false;
}

/**
 * Migrate post/page specific restrictions to content access rules.
 *
 * @return array Array of migrated rule IDs and post/page IDs.
 */
function urcr_migrate_post_page_restrictions() {
	$migration_done = get_option( 'urcr_post_page_restrictions_migrated', false );
	if ( $migration_done ) {
		return array(); // Already migrated
	}

	global $wpdb;

	$query = $wpdb->prepare(
		"SELECT DISTINCT p.ID, p.post_type
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
		LEFT JOIN {$wpdb->postmeta} pm_override
			ON pm_override.post_id = p.ID
				AND pm_override.meta_key = %s
		WHERE pm.meta_key = %s
			AND pm.meta_value = %s
			AND p.post_type IN ('post', 'page')
			AND p.post_status = 'publish'
			AND (
				pm_override.post_id IS NULL
				OR pm_override.meta_value = ''
			)",
		'urcr_meta_override_global_settings',
		'urcr_meta_checkbox',
		'on'
	);

	$results = $wpdb->get_results( $query );

	$posts = array();
	foreach ( $results as $result ) {
		$post            = new stdClass();
		$post->ID        = (int) $result->ID;
		$post->post_type = $result->post_type;
		$posts[]         = $post;
	}

	$total_posts_count = count( $posts );

	if ( empty( $posts ) ) {
		update_option( 'urcr_post_page_restrictions_migrated', true );

		return array();
	}

	// Get already migrated IDs
	$migrated_ids = get_option( 'urcr_migrated_post_page_ids', array() );

	if ( ! is_array( $migrated_ids ) ) {
		$migrated_ids = array();
	}

	// Filter out already migrated posts
	$posts_to_migrate = array();
	foreach ( $posts as $post ) {
		if ( ! in_array( $post->ID, $migrated_ids, true ) ) {
			$posts_to_migrate[] = $post;
		}
	}

	if ( empty( $posts_to_migrate ) ) {
		// All posts migrated, mark as done
		update_option( 'urcr_post_page_restrictions_migrated', true );

		return array();
	}

	// Group posts by post_type
	$posts_by_type = array(
		'wp_posts' => array(),
		'wp_pages' => array(),
	);

	foreach ( $posts_to_migrate as $post ) {
		if ( 'post' === $post->post_type ) {
			$posts_by_type['wp_posts'][] = $post->ID;
		} elseif ( 'page' === $post->post_type ) {
			$posts_by_type['wp_pages'][] = $post->ID;
		}
	}

	// Get allow_to option value (use global setting)
	$allow_to = get_option( 'user_registration_content_restriction_allow_access_to', 0 );
	$allow_to = absint( $allow_to );

	// Build conditions
	$conditions = urcr_build_migration_conditions( $allow_to );

	if ( empty( $conditions ) ) {
		return array(); // No conditions to migrate
	}

	$timestamp = time() * 1001;

	// Build logic_map
	$logic_map = array(
		'type'       => 'group',
		'id'         => 'x' . $timestamp,
		'conditions' => $conditions,
		'logic_gate' => 'AND',
	);

	// Build target_contents
	$target_contents   = array();
	$target_id_counter = $timestamp + 101;
	$new_migrated_ids  = array();

	if ( ! empty( $posts_by_type['wp_posts'] ) ) {
		$target_contents[] = array(
			'id'    => 'x' . $target_id_counter++,
			'type'  => 'wp_posts',
			'value' => array_map( 'strval', $posts_by_type['wp_posts'] ),
		);
		$new_migrated_ids  = array_merge( $new_migrated_ids, $posts_by_type['wp_posts'] );
	}

	if ( ! empty( $posts_by_type['wp_pages'] ) ) {
		$target_contents[] = array(
			'id'    => 'x' . $target_id_counter++,
			'type'  => 'wp_pages',
			'value' => array_map( 'strval', $posts_by_type['wp_pages'] ),
		);
		$new_migrated_ids  = array_merge( $new_migrated_ids, $posts_by_type['wp_pages'] );
	}

	if ( empty( $target_contents ) ) {
		return array(); // No posts/pages to migrate
	}

	// Build rule data
	$rule_data = array(
		'enabled'         => true,
		'access_control'  => 'access',
		'logic_map'       => $logic_map,
		'target_contents' => $target_contents,
		'actions'         => urcr_build_migration_actions( 'content', $timestamp ),
	);

	// Create the rule post
	$rule_id = urcr_create_migrated_rule( __( 'Legacy: Post/Page Rule', 'user-registration' ), $rule_data );

	if ( $rule_id ) {
		// Delete urcr_meta_checkbox meta for each migrated post/page
		foreach ( $new_migrated_ids as $post_id ) {
			delete_post_meta( $post_id, 'urcr_meta_checkbox' );
			update_post_meta( $post_id, 'urcr_migrated_rule_id', $rule_id );
		}

		// Update migrated IDs
		$all_migrated_ids = array_unique( array_merge( $migrated_ids, $new_migrated_ids ) );
		update_option( 'urcr_migrated_post_page_ids', $all_migrated_ids );

		// Mark migration as done only if all posts are migrated
		if ( count( $all_migrated_ids ) >= $total_posts_count ) {
			update_option( 'urcr_post_page_restrictions_migrated', true );
		}
		update_option( 'urcr_is_global', $rule_id );
		return array(
			'rule_id'  => $rule_id,
			'post_ids' => $new_migrated_ids,
		);
	}

	return array();
}

/**
 * Recursively check if a logic map has advanced logic (nested groups or logic gates other than AND).
 *
 * @param array $logic_map Logic map to check.
 *
 * @return bool True if logic map has advanced logic (nested groups or non-AND gates), false otherwise.
 */
function urcr_logic_map_has_advanced_logic( $logic_map ) {

	if ( empty( $logic_map ) || ! is_array( $logic_map ) ) {
		return false;
	}

	if ( isset( $logic_map['type'] ) ) {
		// Check for nested groups in conditions recursively
		if ( ! empty( $logic_map['conditions'] ) && is_array( $logic_map['conditions'] ) ) {
			$conditions_count = count( $logic_map['conditions'] );

			if ( 1 === $conditions_count ) {
				$condition = $logic_map['conditions'][0];
				if ( ! isset( $condition['type'] ) || 'group' !== $condition['type'] ) {
					return false;
				}

				// If it is a group, recursively check it for advanced logic
				return urcr_logic_map_has_advanced_logic( $condition );
			}

			// If multiple conditions exist, check logic gate
			if ( $conditions_count > 1 ) {
				$logic_gate = isset( $logic_map['logic_gate'] ) ? $logic_map['logic_gate'] : 'AND';

				// If logic gate is OR or NOT, it's advanced logic
				if ( 'AND' !== $logic_gate ) {
					return true;
				}
			}

			// Check for nested groups in any condition
			foreach ( $logic_map['conditions'] as $condition ) {
				// Check if any condition is itself a group (nested group)
				if ( isset( $condition['type'] ) && 'group' === $condition['type'] ) {
					return true;
				}
				// Recursively check nested conditions (will detect nested groups)
				if ( urcr_logic_map_has_advanced_logic( $condition ) ) {
					return true;
				}
			}
		}
	}

	return false;
}

/**
 * Check if any existing rules have advanced logic.
 *
 * @return bool True if any rule has advanced logic.
 */
function urcr_has_rules_with_advanced_logic() {
	$access_rule_posts = get_posts(
		array(
			'numberposts' => - 1,
			'post_status' => 'publish',
			'post_type'   => 'urcr_access_rule',
		)
	);

	$has_advanced_logic = false;

	foreach ( $access_rule_posts as $rule_post ) {
		$rule_content = json_decode( $rule_post->post_content, true );

		if ( empty( $rule_content ) || ! is_array( $rule_content ) ) {
			continue;
		}

		$logic_map = isset( $rule_content['logic_map'] ) ? $rule_content['logic_map'] : array();

		if ( urcr_logic_map_has_advanced_logic( $logic_map ) ) {
			$has_advanced_logic                        = true;
			$rule_content['is_advanced_logic_enabled'] = true;

			$updated_content = wp_json_encode( $rule_content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$updated_content = wp_slash( $updated_content );

			wp_update_post(
				array(
					'ID'           => $rule_post->ID,
					'post_content' => $updated_content,
				)
			);
		}
	}

	return $has_advanced_logic;
}




/**
 * Migrate memberships to individual content access rules.
 * Creates one rule per active membership.
 *
 * @return array Array of migrated rule IDs.
 */
function urcr_migrate_memberships() {
	// Check if migration already done
	$migration_done = get_option( 'urcr_memberships_migrated', false );

	if ( $migration_done ) {
		return array(); // Already migrated
	}

	// Check if membership module is active
	if ( ! function_exists( 'ur_check_module_activation' ) || ! ur_check_module_activation( 'membership' ) ) {
		// Mark as done even if module not active, so we don't check again
		update_option( 'urcr_memberships_migrated', true );

		return array();
	}

	// Get active memberships
	$membership_service = new \WPEverest\URMembership\Admin\Services\MembershipService();
	$memberships        = $membership_service->list_active_memberships();

	if ( empty( $memberships ) || ! is_array( $memberships ) ) {
		// No memberships to migrate, mark as done
		update_option( 'urcr_memberships_migrated', true );

		return array();
	}

	// Get already migrated membership IDs
	$migrated_membership_ids = get_option( 'urcr_migrated_membership_ids', array() );

	if ( ! is_array( $migrated_membership_ids ) ) {
		$migrated_membership_ids = array();
	}

	$migrated_rule_ids = array();
	$base_timestamp    = time() * 1000;
	$timestamp_counter = 0;

	foreach ( $memberships as $membership ) {

		$membership_id = isset( $membership['ID'] ) ? $membership['ID'] : 0;

		$membership_title = isset( $membership['title'] ) ? $membership['title'] : '';

		if ( empty( $membership_id ) || empty( $membership_title ) ) {
			continue;
		}

		// Skip if already migrated
		if ( in_array( $membership_id, $migrated_membership_ids, true ) ) {
			continue;
		}

		// Use the reusable function to create the rule
		$rule_id = urcr_create_membership_rule( $membership_id, $membership_title );

		if ( $rule_id ) {
			$migrated_rule_ids[]       = $rule_id;
			$migrated_membership_ids[] = $membership_id;
		}
	}

	// Update migrated membership IDs
	if ( ! empty( $migrated_membership_ids ) ) {
		update_option( 'urcr_migrated_membership_ids', $migrated_membership_ids );
	}

	// Mark migration as done only if all memberships are migrated
	if ( count( $migrated_membership_ids ) >= count( $memberships ) ) {
		update_option( 'urcr_memberships_migrated', true );
	}

	return $migrated_rule_ids;
}

/**
 * Check if there are unmigrated memberships.
 *
 * @return bool True if there are unmigrated memberships, false otherwise.
 */
function urcr_has_unmigrated_memberships() {
	// Check if membership module is active
	if ( ! function_exists( 'ur_check_module_activation' ) || ! ur_check_module_activation( 'membership' ) ) {
		return false;
	}

	// Check if there are active memberships that haven't been migrated
	$migrated_membership_ids = get_option( 'urcr_migrated_membership_ids', array() );
	if ( ! is_array( $migrated_membership_ids ) ) {
		$migrated_membership_ids = array();
	}

	if ( ! class_exists( '\WPEverest\URMembership\Admin\Services\MembershipService' ) ) {
		return false;
	}

	$membership_service = new \WPEverest\URMembership\Admin\Services\MembershipService();
	$memberships        = $membership_service->list_active_memberships();

	if ( empty( $memberships ) || ! is_array( $memberships ) ) {
		return false;
	}

	// Check if any membership hasn't been migrated
	foreach ( $memberships as $membership ) {
		$membership_id = isset( $membership['ID'] ) ? $membership['ID'] : 0;
		if ( ! empty( $membership_id ) && ! in_array( $membership_id, $migrated_membership_ids, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Run the migration script.
 * This function should be called from admin class.
 * Each step only runs if not already completed.
 *
 * @return array Migration results.
 */
function urcr_run_migration() {

	$results = array(
		'global_rule_id'      => false,
		'post_page_rule_id'   => false,
		'migrated_post_ids'   => array(),
		'membership_rule_ids' => array(),
	);

	// Step 1: Migrate global restriction settings
	$global_rule_id = urcr_migrate_global_restriction_settings();
	if ( $global_rule_id ) {
		$results['global_rule_id'] = $global_rule_id;
	}

	// Step 2: Migrate post/page specific restrictions
	$post_page_migration = urcr_migrate_post_page_restrictions();
	if ( ! empty( $post_page_migration ) && isset( $post_page_migration['rule_id'] ) ) {
		$results['post_page_rule_id'] = $post_page_migration['rule_id'];
		$results['migrated_post_ids'] = $post_page_migration['post_ids'];
	}

	// Step 3: Migrate memberships (one rule per membership)
	$membership_rule_ids = urcr_migrate_memberships();

	if ( ! empty( $membership_rule_ids ) ) {
		$results['membership_rule_ids'] = $membership_rule_ids;
	}

	// Check if any existing rules have advanced logic and add advance logic enabled flag.
	urcr_has_rules_with_advanced_logic();

	return $results;
}

/**
 * Get membership rule data for a given membership ID.
 *
 * @param int $membership_id The membership ID.
 *
 * @return array|null Rule data array with id, title, enabled, and content, or null if not found.
 * @since 1.0.0
 */
function urcr_get_membership_rule_data( $membership_id ) {
	if ( ! $membership_id || ! is_numeric( $membership_id ) ) {
		return null;
	}

	// Check if content restriction module is active
	if ( ! function_exists( 'ur_check_module_activation' ) ) {
		return null;
	}

	// Find existing rule for this membership
	$existing_rules = get_posts(
		array(
			'post_type'      => 'urcr_access_rule',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => 'urcr_membership_id',
					'value' => $membership_id,
				),
			),
		)
	);

	if ( empty( $existing_rules ) ) {
		return null;
	}

	$rule_post    = $existing_rules[0];
	$rule_content = json_decode( $rule_post->post_content, true );

	if ( ! $rule_content ) {
		return null;
	}

	// Add rule ID and other metadata
	$rule_content['id']    = $rule_post->ID;
	$rule_content['title'] = $rule_post->post_title;

	// Default to true if not set (matches default for new rules)
	if ( ! isset( $rule_content['enabled'] ) ) {
		$rule_content['enabled'] = true;
	}

	return $rule_content;
}

function urcr_migrated_global_rule() {
	$posts = get_posts(
		array(
			'post_type'      => 'urcr_access_rule',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => 'urcr_is_global',
					'value' => '1',
				),
			),
		)
	);

	return ! empty( $posts ) ? json_decode( $posts[0]->post_content, true ) : array();
}

/**
 * Get every published access rule.
 *
 * @return array List of access rule posts.
 * @since 5.2.8
 */
function urcr_get_published_access_rules() {
	global $wpdb;

	static $access_rules = null;

	if ( null === $access_rules ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mirrors URCR_Frontend::get_all_access_rules(); the result is cached in $access_rules for the rest of the request.
		$access_rules = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				'urcr_access_rule',
				'publish'
			)
		);
	}

	return $access_rules;
}

/**
 * See if the given access rule holds the minimum data required to be evaluated.
 *
 * @param mixed $access_rule Decoded access rule.
 *
 * @return bool
 * @since 5.2.8
 */
function urcr_is_access_rule_evaluable( $access_rule ) {
	if ( ! is_array( $access_rule ) ) {
		return false;
	}

	if ( empty( $access_rule['logic_map'] ) || empty( $access_rule['target_contents'] ) || empty( $access_rule['actions'] ) ) {
		return false;
	}

	if ( ! is_array( $access_rule['logic_map'] ) || empty( $access_rule['logic_map']['conditions'] ) ) {
		return false;
	}

	return urcr_is_access_rule_enabled( $access_rule ) && urcr_is_action_specified( $access_rule );
}

/**
 * See if any published access rule targets the whole site.
 *
 * @return bool
 * @since 5.2.8
 */
function urcr_has_whole_site_access_rule() {
	static $has_whole_site_rule = null;

	if ( null !== $has_whole_site_rule ) {
		return $has_whole_site_rule;
	}

	$has_whole_site_rule = false;

	foreach ( urcr_get_published_access_rules() as $access_rule_post ) {
		$access_rule = json_decode( $access_rule_post->post_content, true );

		if ( ! is_array( $access_rule ) || empty( $access_rule['enabled'] ) || empty( $access_rule['target_contents'] ) ) {
			continue;
		}

		if ( ! ur_string_to_bool( $access_rule['enabled'] ) || ! is_array( $access_rule['target_contents'] ) ) {
			continue;
		}

		if ( in_array( 'whole_site', wp_list_pluck( $access_rule['target_contents'], 'type' ), true ) ) {
			$has_whole_site_rule = true;
			break;
		}
	}

	return $has_whole_site_rule;
}

/**
 * Resolve the access rules that apply to the given post.
 *
 * Whole site rules and post targeted rules are resolved as two independent passes,
 * because the front end enforces them through two independent hooks:
 * URCR_Frontend::advanced_restriction_with_access_rules() only ever sees post targeted
 * rules, while URCR_Frontend::restrict_whole_site() handles the whole site ones. A
 * restriction from either pass wins, so a grant in one pass must never cancel a
 * restriction found by the other. Nothing is applied here, this only decides.
 *
 * @param object $target_post Post to check against.
 *
 * @return array {
 *     Resolution of each pass, keyed 'whole_site' and 'post'.
 *
 *     @type array $... {
 *         @type bool       $granted Whether a rule in this pass explicitly granted access.
 *         @type array|null $rule    The rule that restricts access, when one matched.
 *         @type bool       $matched Whether any rule in this pass targeted the post at all.
 *     }
 * }
 * @since 5.2.8
 */
function urcr_resolve_access_rules( $target_post ) {
	$resolved = array(
		'whole_site' => array(
			'granted' => false,
			'rule'    => null,
			'matched' => false,
		),
		'post'       => array(
			'granted' => false,
			'rule'    => null,
			'matched' => false,
		),
	);

	foreach ( urcr_get_published_access_rules() as $access_rule_post ) {
		$access_rule = json_decode( $access_rule_post->post_content, true );

		if ( ! urcr_is_access_rule_evaluable( $access_rule ) ) {
			continue;
		}

		/*
		 * Whole site targets are matched by type, exactly as
		 * URCR_Frontend::restrict_whole_site() does. urcr_is_target_post() skips any target
		 * without a non-empty 'value', and both the legacy migration and the membership rule
		 * save path build whole site targets with no 'value' at all.
		 */
		if ( in_array( 'whole_site', wp_list_pluck( $access_rule['target_contents'], 'type' ), true ) ) {
			$pass = 'whole_site';
		} elseif ( true === urcr_is_target_post( $access_rule['target_contents'], $target_post ) ) {
			$pass = 'post';
		} else {
			continue;
		}

		$resolved[ $pass ]['matched'] = true;

		$should_allow_access = urcr_is_allow_access( $access_rule['logic_map'], $target_post );
		$access_control      = ! empty( $access_rule['actions'][0]['access_control'] ) ? $access_rule['actions'][0]['access_control'] : 'access';

		if ( ( $should_allow_access && 'access' === $access_control ) || ( ! $should_allow_access && 'restrict' === $access_control ) ) {
			$resolved[ $pass ]['granted'] = true;
		} else {
			$resolved[ $pass ]['rule'] = $access_rule;
		}
	}

	return $resolved;
}

/**
 * See if the current user satisfies a basic restriction mode.
 *
 * @param string $allow_access_to      Allow access mode. 0: logged in users, 1: roles, 2: guests, 3: memberships.
 * @param array  $allowed_roles        Roles allowed by the mode.
 * @param array  $allowed_memberships  Memberships allowed by the mode.
 *
 * @return bool
 * @since 5.2.8
 */
function urcr_is_basic_access_granted( $allow_access_to, $allowed_roles, $allowed_memberships ) {
	$current_user_roles = ( is_user_logged_in() && ! empty( wp_get_current_user()->roles ) ) ? (array) wp_get_current_user()->roles : array();

	switch ( (string) $allow_access_to ) {
		case '0':
			return is_user_logged_in();

		case '1':
			if ( ! is_array( $allowed_roles ) ) {
				return false;
			}

			return ! empty( array_intersect( $current_user_roles, $allowed_roles ) );

		case '2':
			return ! is_user_logged_in();

		case '3':
			if ( ! ur_check_module_activation( 'membership' ) || ! is_array( $allowed_memberships ) ) {
				return false;
			}

			return urm_check_user_membership_has_access( $allowed_memberships );
	}

	return true;
}

/**
 * See if the current user is allowed to read the given post.
 *
 * Authorization gate for the WordPress core REST API. It mirrors the decisions taken by
 * the template based restriction flow, which still evaluates access inline in
 * URCR_Frontend, and it only decides, it never applies a restriction. Keep both in step
 * until the template flow is consolidated onto this function.
 *
 * @param int|object $target_post Post ID or post object to check against.
 *
 * @return bool True when the content may be read, false when it is restricted.
 * @since 5.2.8
 */
function urcr_is_content_access_granted( $target_post ) {
	if ( ! ur_string_to_bool( get_option( 'user_registration_content_restriction_enable', true ) ) ) {
		return true;
	}

	$target_post = is_object( $target_post ) ? $target_post : get_post( $target_post );

	if ( ! $target_post instanceof WP_Post ) {
		return true;
	}

	$post_id = absint( $target_post->ID );

	if ( ! $post_id || is_super_admin() || current_user_can( 'edit_post', $post_id ) ) {
		return true;
	}

	if ( urcr_is_page_excluded( $post_id ) ) {
		return true;
	}

	$override_global_settings = get_post_meta( $post_id, 'urcr_meta_override_global_settings', true );

	// Per post restriction replaces the global settings entirely.
	if ( ur_string_to_bool( $override_global_settings ) ) {
		$allow_to = get_post_meta( $post_id, 'urcr_allow_to', true );

		// A role restriction without any selected role never restricts on the front end.
		if ( '1' === (string) $allow_to && empty( get_post_meta( $post_id, 'urcr_meta_roles', true ) ) ) {
			return true;
		}

		return urcr_is_basic_access_granted(
			$allow_to,
			get_post_meta( $post_id, 'urcr_meta_roles', true ),
			get_post_meta( $post_id, 'urcr_meta_memberships', true )
		);
	}

	$resolved_rules = urcr_resolve_access_rules( $target_post );
	$whole_site     = $resolved_rules['whole_site'];
	$post_targeted  = $resolved_rules['post'];

	/*
	 * Post targeted rules, as enforced by
	 * URCR_Frontend::advanced_restriction_with_access_rules(). Within this pass a granting
	 * rule wins over a restricting one, but a whole site grant does not reach it.
	 */
	if ( ! $post_targeted['granted'] && null !== $post_targeted['rule'] ) {
		return false;
	}

	/*
	 * Whole site rules, as enforced separately by URCR_Frontend::restrict_whole_site().
	 * That method returns early for a whole site grant and then, before applying the
	 * restriction, walks the post targeted rules once more and bails out on a grant there
	 * too, so either grant lifts a whole site restriction.
	 */
	if ( ! $whole_site['granted'] && ! $post_targeted['granted'] && null !== $whole_site['rule'] ) {
		return false;
	}

	$rules_granted_access = $whole_site['granted'] || $post_targeted['granted'];

	// Whole site members only, applied when no access rule covers the whole site.
	if ( ! $rules_granted_access
		&& ur_string_to_bool( get_option( 'user_registration_content_restriction_whole_site_access', false ) )
		&& ! urcr_has_whole_site_access_rule() ) {

		$basic_access_granted = urcr_is_basic_access_granted(
			get_option( 'user_registration_content_restriction_allow_access_to', '0' ),
			get_option( 'user_registration_content_restriction_allow_to_roles', 'administrator' ),
			get_option( 'user_registration_content_restriction_allow_to_memberships' )
		);

		if ( ! $basic_access_granted ) {
			return false;
		}
	}

	// Content drip delays content that no rule has restricted outright.
	if ( urcr_is_content_drip_pending( $target_post ) ) {
		return false;
	}

	return true;
}

/**
 * See if the given post is still waiting for its content drip to release.
 *
 * @param object $target_post Post to check against.
 *
 * @return bool
 * @since 5.2.8
 */
function urcr_is_content_drip_pending( $target_post ) {
	if ( ! defined( 'UR_PRO_ACTIVE' ) || ! UR_PRO_ACTIVE || ! ur_check_module_activation( 'content-drip' ) ) {
		return false;
	}

	if ( ! class_exists( '\WPEverest\URM\ContentDrip\Frontend' ) ) {
		return false;
	}

	return \WPEverest\URM\ContentDrip\Frontend::is_drip_pending( $target_post );
}
