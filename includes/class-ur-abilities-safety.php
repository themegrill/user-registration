<?php
/**
 * Safety steps for AI abilities that change data.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Pause switch, and a preview before a risky ability runs. */
class UR_Abilities_Safety {
	/** Option that holds the pause switch. */
	const PAUSE_OPTION = 'user_registration_pause_ai_changes';

	/** Prefix of the transient that holds a waiting confirmation. */
	const TRANSIENT_PREFIX = 'ur_ability_confirm_';

	/** Hook the pause switch and its setting, so neither needs code in the registration. */
	public static function init() {
		add_filter( 'user_registration_ability_enabled', array( __CLASS__, 'maybe_pause' ), 10, 4 );
		add_filter( 'user_registration_settings_advanced_others', array( __CLASS__, 'add_settings' ), 20 );
	}

	/**
	 * Whether the admin has paused every AI change.
	 *
	 * @since xx.xx.xx
	 *
	 * @return bool
	 */
	public static function is_paused() {
		return ur_option_checked( self::PAUSE_OPTION, false );
	}

	/**
	 * Hide abilities that change or delete data while the pause switch is on. Reading stays available.
	 *
	 * @since xx.xx.xx
	 *
	 * @param bool   $enabled Whether the ability is available.
	 * @param string $slug    Area slug.
	 * @param string $name    Ability name.
	 * @param string $level   Level of the ability.
	 * @return bool
	 */
	public static function maybe_pause( $enabled, $slug = '', $name = '', $level = 'read' ) {
		return $enabled && ( 'read' === $level || ! self::is_paused() );
	}

	/**
	 * Add the pause switch to the top of the AI abilities access card.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $settings Settings of the Others section.
	 * @return array
	 */
	public static function add_settings( $settings ) {
		if ( ! is_array( $settings ) || empty( $settings['sections']['ai_abilities_access']['settings'] ) ) {
			return $settings;
		}

		array_unshift(
			$settings['sections']['ai_abilities_access']['settings'],
			array(
				'title'   => __( 'Pause AI Changes', 'user-registration' ),
				'desc'    => __( 'Hide every ability that changes or deletes data from AI assistants until you turn this off. Reading stays available.', 'user-registration' ),
				'id'      => self::PAUSE_OPTION,
				'type'    => 'toggle',
				'css'     => '',
				'default' => 'no',
			)
		);

		return $settings;
	}

	/**
	 * Schema of the confirmation code an AI sends back to run a previewed ability.
	 *
	 * @since xx.xx.xx
	 *
	 * @return array
	 */
	public static function token_schema() {
		return array(
			'type'        => 'string',
			'pattern'     => '^[A-Za-z0-9]{32}$',
			'description' => 'Leave empty to see what would happen. Send the confirm_token from that preview to run it.',
		);
	}

	/**
	 * Allow a preview as an answer, next to the normal answer of the ability.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $output Output schema of the ability.
	 * @return array
	 */
	public static function output_schema( $output ) {
		return array(
			'type'  => 'object',
			'oneOf' => array(
				$output,
				UR_Abilities::object_schema(
					array(
						'needs_confirmation' => array( 'type' => 'boolean' ),
						'message'            => array( 'type' => 'string' ),
						'preview'            => array( 'type' => 'string' ),
						'confirm_token'      => array( 'type' => 'string' ),
						'expires_in'         => array( 'type' => 'integer' ),
					)
				),
			),
		);
	}

	/**
	 * Wrap an ability so it shows a preview first and only runs on a second, confirmed call.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $name    Ability name.
	 * @param array  $ability Ability definition, with `execute` and `preview` callables.
	 * @return callable
	 */
	public static function wrap( $name, $ability ) {
		return function ( $input = null ) use ( $name, $ability ) {
			$input = is_array( $input ) ? $input : array();
			$token = isset( $input['confirm_token'] ) && is_string( $input['confirm_token'] ) ? $input['confirm_token'] : '';

			unset( $input['confirm_token'] );

			return '' === $token ? self::preview( $name, $ability, $input ) : self::confirm( $name, $ability, $input, $token );
		};
	}

	/**
	 * Describe what would happen and hand out a single-use code for this exact request.
	 *
	 * @param string $name    Ability name.
	 * @param array  $ability Ability definition.
	 * @param array  $input   Input without the code.
	 * @return array|WP_Error
	 */
	private static function preview( $name, $ability, $input ) {
		$preview = call_user_func( $ability['preview'], $input );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		if ( ! is_string( $preview ) || '' === $preview ) {
			return new WP_Error( 'ur_ability_preview_failed', __( 'The preview could not be made, so nothing was run.', 'user-registration' ), array( 'status' => 500 ) );
		}

		/**
		 * Filters how many seconds a confirmation code stays valid.
		 *
		 * @since xx.xx.xx
		 *
		 * @param int $seconds Seconds, between 10 and 300.
		 */
		$ttl   = max( 10, min( 300, (int) apply_filters( 'user_registration_ability_confirm_ttl', 60 ) ) );
		$token = wp_generate_password( 32, false, false );

		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'request' => self::fingerprint( $name, $input ),
				'user'    => get_current_user_id(),
			),
			$ttl
		);

		return array(
			'needs_confirmation' => true,
			'message'            => __( 'Nothing has changed yet. To go ahead, run the same request again with this confirm_token.', 'user-registration' ),
			'preview'            => $preview,
			'confirm_token'      => $token,
			'expires_in'         => $ttl,
		);
	}

	/**
	 * Run the ability once, only for the request and the user the code was made for.
	 *
	 * @param string $name    Ability name.
	 * @param array  $ability Ability definition.
	 * @param array  $input   Input without the code.
	 * @param string $token   Confirmation code.
	 * @return mixed|WP_Error
	 */
	private static function confirm( $name, $ability, $input, $token ) {
		$key    = self::TRANSIENT_PREFIX . $token;
		$stored = get_transient( $key );

		// A code works once, even when the request below turns out to be a different one.
		delete_transient( $key );

		if ( ! is_array( $stored ) || (int) get_current_user_id() !== (int) $stored['user'] || ! hash_equals( (string) $stored['request'], self::fingerprint( $name, $input ) ) ) {
			return new WP_Error( 'ur_ability_confirm_invalid', __( 'The confirmation is missing, expired, already used or made for a different request. Ask again to get a new preview.', 'user-registration' ), array( 'status' => 400 ) );
		}

		return call_user_func( $ability['execute'], $input );
	}

	/**
	 * Fingerprint of an exact request, so a code cannot be reused for another one.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Input without the code.
	 * @return string
	 */
	private static function fingerprint( $name, $input ) {
		return hash( 'sha256', wp_json_encode( array( $name, self::sorted( $input ) ) ) );
	}

	/**
	 * Sort keys at every depth, so the same request always gives the same fingerprint.
	 *
	 * @param mixed $value Value to sort.
	 * @return mixed
	 */
	private static function sorted( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$value = array_map( array( __CLASS__, 'sorted' ), $value );

		ksort( $value );

		return $value;
	}
}
