<?php

/**
 * MembershipGroupService.php
 *
 * MembershipService.php
 *
 * @class    MembershipGroupService.php
 * @author   WPEverest
 */

namespace WPEverest\URMembership\Admin\Services;

use WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository;

class MembershipGroupService {
	protected $logger, $membership_group_repository;

	public function __construct() {
		$this->logger                      = ur_get_logger();
		$this->membership_group_repository = new MembershipGroupRepository();
	}

	/**
	 * get_defaut_group_id
	 *
	 * @return string|null
	 */
	public function get_default_group_id() {
		return $this->membership_group_repository->get_default_group_id();
	}

	public function remove_form_related_groups( $ids ) {

		return array_filter(
			$ids,
			function ( $item ) {
				return $this->check_if_group_used_in_form( $item );
			}
		);
	}

	public function check_if_group_used_in_form( $id ) {
		$form_id = $this->get_group_form_id( $id );
		return $form_id == '';
	}

	/**
	 * get_membership_groups
	 *
	 * @return array
	 */
	public function get_membership_groups() {
		$membership_groups = $this->membership_group_repository->get_all_membership_groups();

		return $this->structure_membership_group_data( $membership_groups );
	}

	/**
	 * get_group_form_id
	 *
	 * @param $group_id
	 *
	 * @return bool
	 */
	public function get_group_form_id( $group_id ) {
		return $this->membership_group_repository->get_group_form_id( $group_id );
	}

	/**
	 * get_membership_group_by_id
	 *
	 * @param $group_id
	 *
	 * @return array|object|\stdClass|null
	 */
	public function get_membership_group_by_id( $group_id ) {
		return $this->membership_group_repository->get_single_membership_group_by_ID( $group_id );
	}

	public function get_group_memberships( $group_id ) {
		$memberships = $this->membership_group_repository->get_group_memberships_by_id( $group_id );
		return apply_filters( 'build_membership_list_frontend', $memberships );
	}

	public function validate_membership_group_data( $data ) {

		$response = array(
			'status' => true,
		);
		if ( isset( $data['post_data']['name'] ) && empty( $data['post_data']['name'] ) ) {
			$response['status']  = false;
			$response['message'] = 'Field name is required.';
		} else {
			$group        = $this->membership_group_repository->get_single_membership_group_by_name( $data['post_data']['name'] );
			$unique_error = false;
			if ( ! isset( $data['post_data']['ID'] ) && ! empty( $group ) ) {
				$unique_error = true;
			} elseif ( isset( $data['post_data']['ID'] ) && ! empty( $group ) && $data['post_data']['ID'] !== $group['ID'] ) {
				$unique_error = true;
			}
			if ( $unique_error ) {
				$response['status']  = false;
				$response['message'] = 'Group name must be unique.';
			}
		}
		if ( isset( $data['post_meta_data']['name'] ) && empty( $data['post_meta_data']['memberships'] ) ) {
			$response['status']  = false;
			$response['message'] = 'Field memberships is required.';
		}

		$is_upgrade_mode = ! empty( $data['post_meta_data']['mode'] ) && 'upgrade' === $data['post_meta_data']['mode'];
		if ( $is_upgrade_mode && ! isset( $data['post_meta_data']['upgrade_type'] ) ) {
			$response['status']  = false;
			$response['message'] = esc_html__( 'Field upgrade type is required.', 'user-registration' );
		}

		return $response;
	}

	public function prepare_membership_group_data( $post_data ) {

		$membership_group_id = ! empty( $post_data['post_data']['ID'] )
		? absint( $post_data['post_data']['ID'] )
		: 0;

		$post_meta_data   = $post_data['post_meta_data'];
		$urmg_memberships = array_map( 'absint', $post_meta_data['memberships'] ?? array() );
		$membership_mode  = sanitize_text_field( $post_meta_data['mode'] ?? '' );

		$updated_post_meta_data = array(
			array(
				'meta_key'   => 'urmg_memberships',
				'meta_value' => wp_json_encode( $urmg_memberships ),
			),
			array(
				'meta_key'   => 'urmg_mode',
				'meta_value' => $membership_mode,
			),
		);

		if ( 'upgrade' === $membership_mode ) {

			$sanitized_upgrade_path          = $this->sanitize_upgrade_paths(
				$post_meta_data['upgrade_path'] ?? ''
			);
			$new_upgrade_path                = array();
			$post_meta_data['upgrade_order'] = json_decode( $post_meta_data['upgrade_order'], true );

			foreach ( $post_meta_data['upgrade_order'] as $order ) {
				$new_upgrade_path[ $order ] = $sanitized_upgrade_path[ $order ];
			}

			$updated_post_meta_data = array_merge(
				$updated_post_meta_data,
				array(
					array(
						'meta_key'   => 'urmg_upgrade_type',
						'meta_value' => sanitize_text_field( $post_meta_data['upgrade_type'] ?? '' ),
					),
					array(
						'meta_key'   => 'urmg_upgrade_path',
						'meta_value' => wp_json_encode( $new_upgrade_path ),
					),
				)
			);
		}

		return array(
			'post_data'      => array(
				'ID'           => $membership_group_id,
				'post_title'   => sanitize_text_field( $post_data['post_data']['name'] ),
				'post_content' => wp_json_encode(
					array(
						'description' => sanitize_text_field( $post_data['post_data']['description'] ),
						'status'      => ur_string_to_bool( $post_data['post_data']['status'] ),
					)
				),
				'post_type'    => 'ur_membership_groups',
				'post_status'  => 'publish',
			),
			'post_meta_data' => $updated_post_meta_data,
		);
	}

	/**
	 * Sanitize upgrade paths array.
	 *
	 * @param [type] $upgrade_path Upgrade Path.
	 */
	private function sanitize_upgrade_paths( $upgrade_path ) {

		if ( empty( $upgrade_path ) ) {
			return array();
		}

		if ( is_string( $upgrade_path ) ) {
			$upgrade_path = json_decode( wp_unslash( $upgrade_path ), true );
		}

		if ( ! is_array( $upgrade_path ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $upgrade_path as $from_membership_id => $paths ) {
			$from_id = absint( $from_membership_id );

			if ( empty( $paths ) || ! is_array( $paths ) ) {
				$sanitized[ $from_id ] = array();
				continue;
			}

			foreach ( $paths as $path ) {
				$sanitized[ $from_id ][] = array(
					'membership_id'     => absint( $path['membership_id'] ?? 0 ),
					'label'             => sanitize_text_field( $path['label'] ?? '' ),
					'chargeable_amount' => floatval( $path['chargeable_amount'] ?? 0 ),
					'target_amount'     => floatval( $path['target_amount'] ?? 0 ),
					'current_amount'    => floatval( $path['current_amount'] ?? 0 ),
				);
			}
		}

		return $sanitized;
	}


	/**
	 * create_membership_groups
	 *
	 * @param $post_data
	 *
	 * @return int|true[]|\WP_Error
	 */
	public function create_membership_groups( $post_data ) {
		$post_data = json_decode( wp_unslash( $post_data ), true );

		$data = $this->validate_membership_group_data( $post_data );

		if ( $data['status'] ) {
			$data = $this->prepare_membership_group_data( $post_data );

			$data                = apply_filters( 'ur_membership_after_create_membership_groups_data_before_save', $data );
			$membership_group_id = wp_insert_post( $data['post_data'] );

			if ( $membership_group_id ) {
				foreach ( $data['post_meta_data'] as $meta ) {
					if ( ! empty( $data['post_data']['ID'] ) ) {
						update_post_meta( $membership_group_id, $meta['meta_key'], $meta['meta_value'] );
					} else {
						add_post_meta( $membership_group_id, $meta['meta_key'], $meta['meta_value'] );
					}
				}

				return array(
					'status'              => true,
					'membership_group_id' => $membership_group_id,
				);
			}
		}

		return $data;
	}

	public function structure_membership_group_data( $membership_groups ) {
		$updated_array = array();
		if ( ! function_exists( 'post_exists' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		foreach ( $membership_groups as $key => $membership_group ) {
			$group_content = json_decode( wp_unslash( $membership_group['post_content'] ), true );

			if ( $group_content['status'] ) {
				$updated_array[ $membership_group['ID'] ] = $membership_group['post_title'];
			}
			$memberships = json_decode( wp_unslash( $membership_group['meta_value'] ), true );

			foreach ( $memberships as $k => $membership ) {
				if ( ! post_exists( get_the_title( $membership ) ) ) {
					unset( $memberships[ $k ] );
				}
			}

			update_post_meta( $membership_group['ID'], 'urmg_memberships', wp_json_encode( array_values( $memberships ) ) );
			if ( empty( $memberships ) ) {
				unset( $updated_array[ $membership_group['ID'] ] );
			}
		}

		return $updated_array;
	}

	/**
	 * Whether membership groups can be used: Pro, a Plus or higher plan, and the module turned on.
	 *
	 * Shared by the group features and the AI abilities, so the lock is the same everywhere.
	 *
	 * @since xx.xx.xx
	 *
	 * @return bool
	 */
	public static function is_available() {
		return UR_PRO_ACTIVE && urm_check_if_plus_and_above_plan() && ur_check_module_activation( 'membership-groups' );
	}

	public function check_if_multiple_memberships_allowed( $group_id ) {

		if ( self::is_available() ) {
			$group_mode = get_post_meta( $group_id, 'urmg_mode', true );

			return ur_string_to_bool( 'multiple' === $group_mode );
		}

		return false;
	}

	public function check_if_upgrade_allowed( $group_id ) {

		if ( self::is_available() ) {
			$group_mode = get_post_meta( $group_id, 'urmg_mode', true );

			return ur_string_to_bool( 'upgrade' === $group_mode );
		}

		return false;
	}
}
