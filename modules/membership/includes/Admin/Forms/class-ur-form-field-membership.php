<?php
/**
 * UR_Form_Field_Membership.
 *
 * @package  URMembership
 */

use WPEverest\URMembership\Admin\Services\ {
	MembershipGroupService,
	MembershipService
};

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Form_Field_Membership Class
 */
class UR_Form_Field_Membership extends UR_Form_Field {

	/**
	 * Instance Variable.
	 *
	 * @var [mixed]
	 */
	private static $_instance;

	/**
	 * Get Instance of class.
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {

		$this->id                       = 'user_registration_membership';
		$this->form_id                  = 1;
		$this->registered_fields_config = array(
			'label' => __( 'Membership', 'user-registration' ),
			'icon'  => 'ur-icon ur-icon-membership-field',
		);
		$membership_group_service       = new MembershipGroupService();
		$default_group_id               = $membership_group_service->get_default_group_id();
		$default_membership_field_name  = get_option( 'ur_membership_default_membership_field_name', true );

		$this->field_defaults = array(
			'default_label'                     => __( 'Membership', 'user-registration' ),
			'default_field_name'                => ! empty( $default_membership_field_name ) && str_contains( $default_membership_field_name, 'membership_field_' ) ? $default_membership_field_name : 'membership_field_' . ur_get_random_number(),
			'default_group'                     => ! empty( $default_group_id ) ? $default_group_id : 0,
			'default_membership_listing_option' => 'all',
		);
		// override the general settings to add membership group setting.
		add_filter( 'user_registration_field_options_general_settings', array( $this, 'settings_override' ), 10, 2 );
		add_filter( 'user_registration_form_field_args', array( $this, 'set_args_for_membership' ), 10, 3 );
		add_filter( 'user_registration_form_field_membership', array( $this, 'set_membership_field' ), 10, 5 );
		add_filter( 'user_registration_membership_advance_class', array( $this, 'set_membership_advance_class' ) );
	}

	/**
	 * Point the advance settings loader to this module's settings file.
	 *
	 * @param array $file_data { 'file_name', 'file_path' }.
	 * @return array
	 */
	public function set_membership_advance_class( $file_data ) {
		$file_data['file_path'] = __DIR__ . '/Settings/class-ur-setting-membership.php';
		return $file_data;
	}

	public function settings_override( $settings, $id ) {
		if ( 'user_registration_membership' !== $id ) {
			return $settings;
		}
		$membership_group_service = new MembershipGroupService();
		$membership_service       = new MembershipService();

		$membership_settings = array(
			'user_registration_show_bank_details_on_form' => array(
				'setting_id' => 'user_registration_show_bank_details_on_form',
				'name'       => 'user_registration_show_bank_details_on_form',
				'type'       => 'toggle',
				'label'      => __( 'Show Bank Details in Form', 'user-registration' ),
				'required'   => 1,
				'tip'        => __( 'This option will show the bank details in registration form when bank transfer is selected.', 'user-registration' ),
				'default'    => false,
			),
			'membership_listing_option'                   => array(
				'setting_id'  => 'membership_listing_option',
				'name'        => 'membership_listing_option',
				'type'        => 'select',
				'label'       => __( 'Membership Display Options', 'user-registration' ),
				'placeholder' => __( 'Select an option', 'user-registration' ),
				'required'    => 1,
				'tip'         => __( 'Choose how you want the memberships to be listed in the form.', 'user-registration' ),
				'default'     => 'all',
				'options'     => array(
					'all'      => 'Show all Memberships.',
					'group'    => 'Select a Group',
					'selected' => 'Select Memberships',
				),
			),
			'membership_group'                            => array(
				'setting_id'  => 'membership_group',
				'name'        => 'membership_group',
				'type'        => 'select',
				'label'       => __( 'Select Membership Group', 'user-registration' ),
				'placeholder' => __( 'Select any membership group.', 'user-registration' ),
				'required'    => 1,
				'tip'         => __( "Choose an existing membership group from the dropdown, or create a new one <a href='?page=user-registration-membership&action=add_groups'>here</a>.", 'user-registration' ),
				'options'     => array( 0 => 'Select a Membership Group.' ) + $membership_group_service->get_membership_groups(),
			),
			'membership_active_memberships'               => array(
				'setting_id'  => 'membership_active_memberships',
				'name'        => 'membership_active_memberships',
				'type'        => 'multiselect',
				'label'       => __( 'Select Memberships To Display', 'user-registration' ),
				'placeholder' => __( 'Select memberships to display.', 'user-registration' ),
				'required'    => 1,
				'tip'         => __( 'Choose specific active memberships to display in the form.', 'user-registration' ),
				'options'     => $this->format_memberships_as_options( $membership_service->list_active_memberships() ),
			),

		);

		$settings = $settings + $membership_settings;
		unset( $settings['placeholder'] );
		unset( $settings['required'] );

		return $settings;
	}

	/**
	 * Format membership list for multiselect options (id => label).
	 *
	 * Matches the format expected by abstract-ur-form-field multiselect:
	 * foreach ( $setting_value['options'] as $key => $val ) → option value="$key", label $val.
	 *
	 * @param array $memberships List from list_active_memberships() (items with ID, title).
	 * @return array Associative array of membership_id => label.
	 */
	private function format_memberships_as_options( $memberships ) {
		$options = array();
		if ( ! is_array( $memberships ) ) {
			return $options;
		}
		foreach ( $memberships as $m ) {
			$id    = isset( $m['ID'] ) ? $m['ID'] : ( isset( $m['id'] ) ? $m['id'] : null );
			$label = isset( $m['title'] ) ? $m['title'] : ( isset( $m['post_title'] ) ? $m['post_title'] : (string) $id );
			if ( null !== $id && '' !== $label ) {
				$options[ $id ] = $label;
			}
		}
		return $options;
	}

	/**
	 * Get Registered admin fields.
	 */
	public function get_registered_admin_fields() {

		return '<li id="' . $this->id . '_list " class="ur-registered-item draggable" data-field-id="' . $this->id . '"><span class="' . $this->registered_fields_config['icon'] . '"></span>' . $this->registered_fields_config['label'] . '</li>';
	}

	/**
	 * Validate field.
	 *
	 * @param [object] $single_form_field Field Data.
	 * @param [object] $form_data Form Data.
	 * @param [string] $filter_hook Hook.
	 * @param [int]    $form_id Form id.
	 */
	public function validation( $single_form_field, $form_data, $filter_hook, $form_id ) {
	}

	public function set_args_for_membership( $args, $key, $value ) {
		if ( isset( $args['field_key'] ) && 'membership' == $args['field_key'] ) {
			$args['type'] = 'membership'; // maybe update this in future to be dynamic.
			if ( isset( $args['membership_listing_option'] ) && 'group' === $args['membership_listing_option'] ) {
				$membership_group_service = new MembershipGroupService();
				$memberships              = $membership_group_service->get_group_memberships( $args['membership_group'] );
			} elseif ( isset( $args['membership_listing_option'] ) && 'selected' === $args['membership_listing_option'] ) {
				$membership_service = new MembershipService();
				$all_memberships    = $membership_service->list_active_memberships();
				$selected_ids       = isset( $args['membership_active_memberships'] ) ? $args['membership_active_memberships'] : array();
				$selected_ids       = is_array( $selected_ids ) ? $selected_ids : (array) maybe_unserialize( $selected_ids );
				$selected_ids       = array_filter( array_map( 'absint', $selected_ids ) );
				$memberships        = array_values(
					array_filter(
						$all_memberships,
						function ( $m ) use ( $selected_ids ) {
							$id = isset( $m['ID'] ) ? (int) $m['ID'] : ( isset( $m['id'] ) ? (int) $m['id'] : 0 );
							return $id && in_array( $id, $selected_ids, true );
						}
					)
				);
			} else {
				$membership_service = new MembershipService();
				$memberships        = $membership_service->list_active_memberships();
			}
			$args['options'] = $memberships;
		}

		return $args;
	}

	/**
	 * set_membership_field
	 *
	 * @param $field
	 * @param $key
	 * @param $args
	 * @param $value
	 * @param $current_row
	 *
	 * @return string
	 */
	public function set_membership_field( $field, $key, $args, $value, $current_row ) {
		$attributes = array(
			'preview' => true,
			'key'     => $key,
			'value'   => $value,
		);
		return \WPEverest\URMembership\ShortCodes::member_registration_form( $attributes + $args );
	}
}

return UR_Form_Field_Membership::get_instance();
