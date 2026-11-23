<?php
/**
 * Record of what AI assistants did on the site, written to the plugin's own log.
 *
 * Each action becomes one line under the source `user-registration-ai`, shown in Tools, Logs. Only the facts needed
 * to answer who, what and how it went are kept: the user, the AI app, the ability, the outcome, the ids it touched
 * and how many items it returned. Names, emails and other member details, input values and failed sign-ins are never
 * written.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Writes one log line for every AI action and hands each entry to a hook. */
class UR_Abilities_Activity {
	/** Log source, as listed in Tools, Logs. Like every plugin log, it is written only while Enable Log is on. */
	const SOURCE = 'user-registration-ai';

	/**
	 * Wrap a permission check so a refused call is recorded.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string   $name     Ability name.
	 * @param string   $level    Ability level.
	 * @param callable $callback Permission check.
	 * @return callable
	 */
	public static function wrap_permission( $name, $level, $callback ) {
		return function ( $input = null ) use ( $name, $level, $callback ) {
			$result = call_user_func( $callback, $input );

			if ( is_wp_error( $result ) || ! $result ) {
				self::record(
					array(
						'ability' => $name,
						'level'   => $level,
						'outcome' => 'refused',
						'code'    => is_wp_error( $result ) ? $result->get_error_code() : 'forbidden',
						'targets' => self::targets( $input ),
					)
				);
			}

			return $result;
		};
	}

	/**
	 * Wrap an ability so every run is recorded, with how it went and how long it took.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string   $name     Ability name.
	 * @param string   $level    Ability level.
	 * @param callable $callback What the ability runs.
	 * @return callable
	 */
	public static function wrap_execute( $name, $level, $callback ) {
		return function ( $input = null ) use ( $name, $level, $callback ) {
			$start = microtime( true );

			try {
				$result = call_user_func( $callback, $input );
			} catch ( Throwable $error ) {
				self::record(
					array(
						'ability'     => $name,
						'level'       => $level,
						'outcome'     => 'error',
						'code'        => 'exception',
						'targets'     => self::targets( $input ),
						'duration_ms' => self::since( $start ),
					)
				);

				throw $error;
			}

			self::record(
				array(
					'ability'     => $name,
					'level'       => $level,
					'targets'     => self::targets( $input ),
					'duration_ms' => self::since( $start ),
				) + self::describe( $result )
			);

			return $result;
		};
	}

	/**
	 * Write one log line and pass the entry to the hook. Never stops the ability.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $entry ability, level, outcome, code, targets, items and duration_ms.
	 */
	public static function record( $entry ) {
		$row = array(
			'user_id'     => (int) get_current_user_id(),
			'app'         => self::app_name(),
			'ability'     => substr( sanitize_text_field( (string) ( $entry['ability'] ?? '' ) ), 0, 100 ),
			'level'       => in_array( $entry['level'] ?? '', array( 'write', 'delete' ), true ) ? $entry['level'] : 'read',
			'outcome'     => in_array( $entry['outcome'] ?? '', array( 'refused', 'error', 'preview' ), true ) ? $entry['outcome'] : 'success',
			'code'        => substr( sanitize_key( (string) ( $entry['code'] ?? '' ) ), 0, 100 ),
			'targets'     => substr( (string) ( $entry['targets'] ?? '' ), 0, 255 ),
			'items'       => isset( $entry['items'] ) ? (int) $entry['items'] : null,
			'duration_ms' => (int) ( $entry['duration_ms'] ?? 0 ),
		);

		if ( function_exists( 'ur_get_logger' ) ) {
			$logger = ur_get_logger();
			$method = array(
				'refused' => 'warning',
				'error'   => 'error',
			);

			$logger->{ $method[ $row['outcome'] ] ?? 'info' }( self::line( $row ), array( 'source' => self::SOURCE ) );
		}

		/**
		 * Fires after an AI action is recorded, so an activity log plugin can pick it up.
		 *
		 * @since xx.xx.xx
		 *
		 * @param array $row user_id, app, ability, level, outcome, code, targets (ids), items and duration_ms. It
		 *                   holds no member details.
		 */
		do_action( 'user_registration_ai_activity_logged', $row );
	}

	/**
	 * Build the log line for an entry.
	 *
	 * @param array $row The entry.
	 * @return string
	 */
	private static function line( $row ) {
		$details = array_filter(
			array(
				$row['outcome'],
				$row['code'],
				$row['targets'],
				null === $row['items'] ? '' : $row['items'] . ' items',
				$row['duration_ms'] . ' ms',
			),
			'strlen'
		);

		return sprintf(
			'AI %1$s %2$s by user %3$d%4$s: %5$s',
			$row['level'],
			$row['ability'],
			$row['user_id'],
			'' === $row['app'] ? '' : ' (' . $row['app'] . ')',
			implode( ', ', $details )
		);
	}

	/**
	 * Work out the outcome of a finished run.
	 *
	 * @param mixed $result What the ability returned.
	 * @return array outcome, code and items.
	 */
	private static function describe( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'outcome' => 'error',
				'code'    => $result->get_error_code(),
			);
		}

		if ( is_array( $result ) && ! empty( $result['needs_confirmation'] ) ) {
			return array( 'outcome' => 'preview' );
		}

		return is_array( $result ) && isset( $result['items'] ) && is_array( $result['items'] ) ? array( 'items' => count( $result['items'] ) ) : array();
	}

	/**
	 * Collect the record ids an ability was asked about. Names, emails and other values are left out.
	 *
	 * @param mixed $input Input of the ability.
	 * @return string Ids as `key:number`, separated by commas.
	 */
	private static function targets( $input ) {
		$ids = array();

		foreach ( is_array( $input ) ? $input : array() as $key => $value ) {
			if ( ( 'id' === $key || '_id' === substr( (string) $key, -3 ) ) && is_int( $value ) && $value > 0 ) {
				$ids[] = $key . ':' . $value;
			}
		}

		return implode( ',', $ids );
	}

	/**
	 * Milliseconds since a start time.
	 *
	 * @param float $start Result of microtime( true ).
	 * @return int
	 */
	private static function since( $start ) {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}

	/**
	 * Get the name of the AI app, which is the name the admin gave its application password.
	 *
	 * @return string Empty when the call did not come through an application password.
	 */
	private static function app_name() {
		if ( ! function_exists( 'rest_get_authenticated_app_password' ) || ! class_exists( 'WP_Application_Passwords' ) ) {
			return '';
		}

		$uuid = rest_get_authenticated_app_password();

		foreach ( $uuid ? WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) : array() as $item ) {
			if ( isset( $item['uuid'], $item['name'] ) && $uuid === $item['uuid'] ) {
				return substr( sanitize_text_field( $item['name'] ), 0, 100 );
			}
		}

		return '';
	}
}
