<?php
/**
 * User registration content restriction block.
 *
 * @since 4.0
 * @package user-registration
 */

defined( 'ABSPATH' ) || exit;
/**
 * Block registration form class.
 */
class UR_Block_Content_Restriction extends UR_Block_Abstract {
	/**
	 * Block name.
	 *
	 * @var string Block name.
	 */
	protected $block_name = 'content-restriction-enhanced';

	/**
	 * Inner content is authored by the site owner and may hold shortcodes.
	 *
	 * @var bool
	 */
	protected $escape_shortcodes = false;

	/**
	 * Build html.
	 *
	 * @param string $content
	 * @return string
	 */
	protected function build_html( $content ) {
		return $content;
	}
}
