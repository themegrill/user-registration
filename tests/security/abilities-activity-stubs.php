<?php
/**
 * Shared WordPress doubles for the tests that load the AI abilities registration, which now writes a log line for
 * every AI action. A test that defines one of these itself keeps its own.
 *
 * @package UserRegistration/Tests
 */

// Test doubles intentionally bypass production-only conventions.
// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! class_exists( 'Activity_Test_Logger' ) ) {
	class Activity_Test_Logger {
		public $lines = array();

		public function __call( $level, $args ) {
			$this->lines[] = array( $level, $args[0], $args[1]['source'] ?? '' );
		}
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['user_id'] ?? 1;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['actions'][ $hook ][] = $args;
	}
}

if ( ! function_exists( 'ur_get_logger' ) ) {
	function ur_get_logger() {
		$GLOBALS['logger'] = $GLOBALS['logger'] ?? new Activity_Test_Logger();
		return $GLOBALS['logger'];
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return new stdClass();
	}
}

if ( ! class_exists( 'UR_Abilities_Role' ) ) {
	require dirname( __DIR__, 2 ) . '/includes/class-ur-abilities-role.php';
}
