<?php
/**
 * UR_Setting_Membership Class.
 *
 * @package  UserRegistration/Form/Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Setting_Membership Class.
 *
 * @package  UserRegistration/Form/Settings
 */
class UR_Setting_Membership extends UR_Field_Settings {

	/**
	 * UR_Setting_Membership Class Constructor.
	 */
	public function __construct() {
		$this->field_id = 'membership_advance_setting';
	}

	/**
	 * Output.
	 *
	 * @param array $field_data field data.
	 * @return string $field_html Field HTML.
	 */
	public function output( $field_data = array() ) {

		$this->field_data = $field_data;
		$this->register_fields();
		$field_html = $this->fields_html;

		return $field_html;
	}

	/**
	 * Register Fields.
	 */
	public function register_fields() {
		$fields = array();

		// The toggle only has an effect on a lone free plan, so only offer it then.
		if ( $this->has_single_free_plan() ) {
			$fields['always_show_membership_field'] = array(
				'setting_id' => 'always-show-membership-field',
				'type'       => 'toggle',
				'label'      => __( 'Always Show This Field', 'user-registration' ),
				'class'      => $this->default_class . ' ur-settings-always-show-membership-field',
				'name'       => $this->field_id . '[always_show_membership_field]',
				'data-id'    => $this->field_id . '_always_show_membership_field',
				'required'   => false,
				'default'    => 'true',
				'tip'        => __( 'Disable this to hide the plan selection and auto-select the plan when only one active plan is available and it is free.', 'user-registration' ),
			);
		}

		/**
		 * Filter to modify the first name custom advance settings.
		 *
		 * @param string $fields Advance Settings Fields.
		 * @param int field_id Field ID.
		 * @param class default_class Field Default Class.
		 *
		 * @return string $fields.
		 */
		$fields = apply_filters( 'membership_custom_advance_settings', $fields, $this->field_id, $this->default_class );
		$this->render_html( $fields );
	}

	/**
	 * Whether this field resolves to exactly one active plan and that plan is free.
	 *
	 * @return bool
	 */
	private function has_single_free_plan() {
		if ( ! class_exists( 'UR_Form_Field_Membership' ) ) {
			return false;
		}

		$general_setting = isset( $this->field_data->general_setting ) ? (array) $this->field_data->general_setting : array();

		// Reuse the field's own listing resolution so all/group/selected stay in sync.
		$args = UR_Form_Field_Membership::get_instance()->set_args_for_membership(
			array_merge( $general_setting, array( 'field_key' => 'membership' ) ),
			'',
			''
		);

		$memberships = ( isset( $args['options'] ) && is_array( $args['options'] ) ) ? $args['options'] : array();

		if ( 1 !== count( $memberships ) ) {
			return false;
		}

		$plan = reset( $memberships );

		return 'free' === ( $plan['type'] ?? 'free' );
	}
}

return new UR_Setting_Membership();
