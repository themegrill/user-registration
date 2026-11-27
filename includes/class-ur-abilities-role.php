<?php
/**
 * The AI user: a role that holds no admin rights, and the rights lent to it while one ability runs.
 *
 * An application password carries every right of the user it belongs to. The AI user has the read right and one
 * custom right, so a leaked password reaches nothing outside the AI abilities. While an ability the admin switched on
 * runs for that user, the user is lent the rights the ability itself checks, and only those.
 *
 * Because of that, a permission callback or a per-object check such as edit_post() always passes for the AI user while
 * an ability runs. Safety rests on the two settings (Enabled Abilities, Allow Changes), the list of abilities and the
 * checks inside each ability, so no ability may declare promote_users or manage_options in its caps.
 *
 * @package UserRegistration
 */

defined( 'ABSPATH' ) || exit;

/** Role of the AI user, and the rights lent to it for the length of one ability. */
class UR_Abilities_Role {
	/** Role of the AI user. */
	const ROLE = 'ur_ai_assistant';

	/** The one right the role holds. A user needs it before any right is lent. */
	const CAP = 'ur_ai_abilities';

	/** Rights an ability may ask for. Nothing outside this list, or outside the post rights below, is ever lent. */
	const LENDABLE = array(
		'manage_user_registration',
		'manage_options',
		'list_users',
		'edit_users',
		'promote_users',
		'delete_users',
		'activate_plugins',
	);

	/** The rights to edit, delete, publish or read posts of the types abilities work with: posts and registration forms. */
	const POST_RIGHTS = '/^(edit|delete|publish|read)_(others_|published_|private_)?(posts|user_registrations)$/';

	/**
	 * Where the lending is in: empty when no ability runs, `learn` while an ability checks what it needs, `run` while it works.
	 *
	 * @var string
	 */
	private static $phase = '';

	/**
	 * Name of the ability that is running.
	 *
	 * @var string
	 */
	private static $name = '';

	/**
	 * Rights an ability asked for while it ran that were on the allowed list but not lent, keyed by right.
	 *
	 * @var array
	 */
	private static $missed = array();

	/**
	 * Rights each ability asked for in its permission check, keyed by ability name.
	 *
	 * @var array
	 */
	private static $lent = array();

	/** Hook the lending and the sign-in block. */
	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'lend' ), 10, 3 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'refuse_password_login' ), 10, 1 );
	}

	/**
	 * Make sure the role exists.
	 *
	 * @since xx.xx.xx
	 */
	public static function ensure_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				__( 'User Registration AI', 'user-registration' ),
				array(
					'read'    => true,
					self::CAP => true,
				)
			);
		}
	}

	/**
	 * Whether a user is the AI user.
	 *
	 * @since xx.xx.xx
	 *
	 * @param WP_User|int|null $user User, id, or null for the current user.
	 * @return bool
	 */
	public static function is_ai_user( $user = null ) {
		$user = is_numeric( $user ) ? get_userdata( (int) $user ) : ( $user ? $user : wp_get_current_user() );

		return $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * Wrap a permission check: for the AI user, note which rights it asks for and lend them while it runs.
	 *
	 * Every other user is checked exactly as before.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string   $name     Ability name.
	 * @param callable $callback Permission check.
	 * @param array    $extra    Rights the ability's own code asks for besides the ones its permission check asks for.
	 * @return callable
	 */
	public static function wrap_permission( $name, $callback, $extra = array() ) {
		return function ( $input = null ) use ( $name, $callback, $extra ) {
			if ( ! self::is_ai_user() ) {
				return call_user_func( $callback, $input );
			}

			self::$phase         = 'learn';
			self::$name          = $name;
			self::$lent[ $name ] = array();

			try {
				$result = call_user_func( $callback, $input );
			} finally {
				self::$phase = '';
				self::$name  = '';
			}

			if ( is_wp_error( $result ) || ! $result ) {
				// A refused check lends nothing.
				unset( self::$lent[ $name ] );
			} else {
				foreach ( (array) $extra as $cap ) {
					if ( self::is_lendable( $cap ) ) {
						self::$lent[ $name ][ $cap ] = true;
					}
				}
			}

			return $result;
		};
	}

	/**
	 * Wrap an ability: for the AI user, lend exactly the rights its permission check asked for while it works.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string   $name     Ability name.
	 * @param callable $callback What the ability runs.
	 * @return callable
	 */
	public static function wrap_execute( $name, $callback ) {
		return function ( $input = null ) use ( $name, $callback ) {
			if ( ! self::is_ai_user() || ! isset( self::$lent[ $name ] ) ) {
				return call_user_func( $callback, $input );
			}

			self::$phase  = 'run';
			self::$name   = $name;
			self::$missed = array();

			try {
				return call_user_func( $callback, $input );
			} finally {
				self::$phase = '';
				self::$name  = '';
				self::report_missed( $name );
			}
		};
	}

	/**
	 * Say in the plugin log when an ability asked for a right it was not given, so the gap is found and the right declared.
	 *
	 * @param string $name Ability name.
	 */
	private static function report_missed( $name ) {
		if ( ! empty( self::$missed ) && function_exists( 'ur_get_logger' ) ) {
			/* translators: 1: ability name, 2: rights. */
			ur_get_logger()->warning( sprintf( 'AI ability %1$s asked for rights it was not given: %2$s. Declare them with caps on the ability.', $name, implode( ', ', array_keys( self::$missed ) ) ), array( 'source' => 'user-registration-ai' ) );
		}

		self::$missed = array();
	}

	/**
	 * Whether a right may be lent to the AI user.
	 *
	 * @since xx.xx.xx
	 *
	 * @param string $cap Right.
	 * @return bool
	 */
	public static function is_lendable( $cap ) {
		return in_array( $cap, self::LENDABLE, true ) || 1 === preg_match( self::POST_RIGHTS, (string) $cap );
	}

	/**
	 * Lend a right to the AI user while an ability runs.
	 *
	 * @since xx.xx.xx
	 *
	 * @param array $allcaps Rights of the user.
	 * @param array $caps    Basic rights the check needs.
	 * @param array $args    Arguments of the check.
	 * @return array
	 */
	public static function lend( $allcaps, $caps, $args = array() ) {
		if ( '' === self::$phase || empty( $allcaps[ self::CAP ] ) ) {
			return $allcaps;
		}

		foreach ( (array) $caps as $cap ) {
			if ( ! self::is_lendable( $cap ) ) {
				continue;
			}

			if ( 'learn' === self::$phase ) {
				self::$lent[ self::$name ][ $cap ] = true;
				$allcaps[ $cap ]                   = true;
			} elseif ( isset( self::$lent[ self::$name ][ $cap ] ) ) {
				$allcaps[ $cap ] = true;
			} else {
				self::$missed[ $cap ] = true;
			}
		}

		return $allcaps;
	}

	/**
	 * Stop the AI user from signing in with a password. Its application password is its only way in.
	 *
	 * @since xx.xx.xx
	 *
	 * @param WP_User|WP_Error $user User trying to sign in.
	 * @return WP_User|WP_Error
	 */
	public static function refuse_password_login( $user ) {
		if ( $user instanceof WP_User && self::is_ai_user( $user ) ) {
			return new WP_Error( 'ur_ai_user_no_login', __( 'This account is for AI assistants. It signs in with its application password only.', 'user-registration' ) );
		}

		return $user;
	}
}
