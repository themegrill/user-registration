<?php // phpcs:ignore;
/**
 * User registration form block.
 *
 * @since 3.1.5
 * @package user-registration
 */

defined( 'ABSPATH' ) || exit;
/**
 * Block registration form class.
 */
class UR_Block_Regstration_Form extends UR_Block_Abstract {
	/**
	 * Block name.
	 *
	 * @var string Block name.
	 */
	protected $block_name = 'registration-form';

	/**
	 * Build html.
	 *
	 * @param string $content Build html content.
	 * @return string
	 */
	protected function build_html( $content ) {
		$form_id    = isset( $this->attributes['formId'] ) ? $this->attributes['formId'] : '';
		$user_state = isset( $this->attributes['userState'] ) ? $this->attributes['userState'] : 'logged_out';
		if ( empty( $form_id ) ) {
			return $content;
		}

		return UR_Shortcodes::form(
			array(
				'id'        => $form_id,
				'userState' => $user_state,
			)
		);
	}
}
