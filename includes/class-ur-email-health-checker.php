<?php
/**
 * Email Health Checker.
 *
 * Runs read-only checks against the site's mail configuration and DNS records
 * to surface why registration emails fail to arrive.
 *
 * Results come back in two sections, because the two answer different
 * questions. "Mail Delivery" is why mail fails — the route it takes off the
 * server, and whether this site is authorised to send as the configured "From"
 * address. "Plugin Settings" is what the plugin will send and to whom; with the
 * single exception of "Disable Emails", nothing there can stop a message that
 * the delivery section says would otherwise arrive.
 *
 * @class    UR_Email_Health_Checker
 * @version  2.0.0
 * @package  UserRegistration/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/class-ur-email-transport-inspector.php';
require_once __DIR__ . '/class-ur-email-domain-inspector.php';

if ( ! class_exists( 'UR_Email_Health_Checker' ) ) :

	/**
	 * UR_Email_Health_Checker Class.
	 */
	class UR_Email_Health_Checker {

		/**
		 * Run every check and return them grouped, with an overall summary.
		 *
		 * @return array
		 */
		public static function run_checks() {
			$transport = UR_Email_Transport_Inspector::inspect();

			// The address that actually leaves the server is what determines
			// deliverability — analysing the saved setting when an SMTP plugin
			// is rewriting it would give a confident answer about the wrong
			// domain.
			$sender = UR_Email_Transport_Inspector::effective_sender( self::from_address(), self::from_name() );
			$from   = $sender['address'];
			$domain = UR_Email_Domain_Inspector::domain_of( $from );

			// Only look the domain up once the address is worth looking up. A
			// malformed one (a typo, or two addresses in the field) still yields
			// something that parses as a domain, and resolving a name that
			// doesn't exist costs a full timeout per lookup — the checks below
			// report the malformed address without ever needing DNS.
			$dns = ( '' === $domain || ! is_email( $from ) ) ? null : UR_Email_Domain_Inspector::inspect( $domain );

			// "Disable Emails" overrides every per-email setting, so the checks for
			// those settings need it — otherwise they report mail as going out.
			$sending_disabled = self::is_sending_disabled();

			$delivery = array_values(
				array_filter(
					array_merge(
						self::route_checks( $transport ),
						self::identity_checks( $transport, $sender, $domain, $dns ),
						array( self::check_recent_failures() )
					)
				)
			);

			$settings = array(
				self::check_sending_enabled(),
				self::check_admin_email_set(),
			);

			// "Disable Emails" already says nothing goes out; per-email rows restate it.
			if ( ! $sending_disabled ) {
				$settings[] = self::check_user_registration_email_enabled();
				$settings[] = self::check_admin_notification_enabled();
			}

			// No pending-admin-email-change row: it fires on a narrow WordPress state
			// most admins have never been in, and reads as noise beside the checks
			// that decide whether mail sends at all.

			// Reported to support rather than shown as findings: DNS the admin often
			// can't change, and on an opaque transport we can't tell if it applies.
			$dns_checks = self::dns_checks( $transport, $domain, $dns );

			// Excludes $dns_checks, so they never reach the issue counts.
			$all = array_merge( $delivery, $settings );

			// Written against each other: only one of these groups can stop an email.
			return array(
				'sections'         => array(
					array(
						'key'         => 'delivery',
						'title'       => __( 'Mail Delivery', 'user-registration' ),
						// Names the badge rather than saying "anything failing", which
						// covered both verdicts with one claim and so overstated the
						// milder one. Only "Will fail" is asserted, because only that
						// one is established; "May fail" speaks for itself.
						// Keep this label in step with the badge text in ScanScreen's
						// rowStyles.
						'description' => __( 'The route your mail takes, and whether receiving servers will accept who it\'s from. Anything here tagged "Will fail" really does stop mail.', 'user-registration' ),
						'checks'      => $delivery,
					),
					array(
						'key'         => 'settings',
						'title'       => __( 'Plugin Settings', 'user-registration' ),
						'description' => __( 'Which emails are switched on, and where they go. Only "Disable Emails" stops mail completely.', 'user-registration' ),
						'checks'      => $settings,
					),
				),
				'summary'          => self::build_summary( $transport, $sender, $domain, $dns, $sending_disabled ),
				'checks'           => $all,
				'dns_checks'       => $dns_checks,
				'smartsmtp_status' => self::smartsmtp_status(),
				'smtp_plugin'      => self::detected_smtp_plugin(),
			);
		}

		/**
		 * SPF and DMARC, or the merged finding when they share a cause. Report-only,
		 * and unlike the on-screen checks "unknown" results are kept: an agent wants
		 * to know the domain publishes `-all` and we couldn't match the sender.
		 *
		 * @param array      $transport Transport inspection.
		 * @param string     $domain    Effective From domain.
		 * @param array|null $dns       DNS inspection for that domain.
		 * @return array
		 */
		private static function dns_checks( $transport, $domain, $dns ) {
			if ( '' === $domain || null === $dns ) {
				return array();
			}

			// On a mailbox provider this DNS is Google's or Yahoo's, not the
			// admin's — reporting it as "your domain" would mislead support too.
			if ( UR_Email_Domain_Inspector::is_mailbox_provider( $domain ) ) {
				return array();
			}

			$combined = self::check_sender_rules( $domain, $dns, $transport );

			if ( $combined ) {
				return array( $combined );
			}

			return array_values(
				array_filter(
					array(
						self::check_spf( $domain, $dns, $transport ),
						self::check_dmarc( $domain, $dns, $transport ),
					)
				)
			);
		}

		/* --------------------------------------------------------------------
		 * Section 1a — the route mail takes off this server.
		 * ----------------------------------------------------------------- */

		/**
		 * @param array $transport Transport inspection.
		 * @return array
		 */
		private static function route_checks( $transport ) {
			$checks = array( self::check_sending_route( $transport ) );

			if ( ! empty( $transport['diverted_by'] ) && 'replaced' !== $transport['route'] ) {
				$checks[] = self::check_possible_diversion( $transport );
			}

			if ( 'smtp' === $transport['route'] ) {
				$checks[] = self::check_smtp_connection( $transport );
			}

			// No "install this" row: recommending a plugin is the result screen's job.
			return $checks;
		}

		/**
		 * A name for whatever owns the mail path that reads correctly mid-sentence.
		 *
		 * @param array|null $owner Owner descriptor { type, name }.
		 * @return string
		 */
		private static function owner_label( $owner ) {
			return empty( $owner['name'] ) ? __( 'something on your site', 'user-registration' ) : $owner['name'];
		}

		/**
		 * How to tell an admin to go and look at whatever owns their mail. A
		 * plugin has a settings screen to open; a theme or a snippet does not,
		 * so one sentence can't serve both.
		 *
		 * @param array|null $owner Owner descriptor { type, name }.
		 * @return string
		 */
		private static function owner_action_hint( $owner ) {
			if ( empty( $owner ) ) {
				return __( 'Check whatever handles outgoing mail on your site and confirm which sender it uses.', 'user-registration' );
			}

			switch ( $owner['type'] ) {
				case 'plugin':
					return sprintf(
						/* translators: %s: plugin name */
						__( 'Open %s and check which sender it is set to use.', 'user-registration' ),
						$owner['name']
					);
				case 'theme':
					return sprintf(
						/* translators: %s: theme name */
						__( 'The mail code lives in your active theme (%s). Check which sender it sets.', 'user-registration' ),
						$owner['name']
					);
				case 'mu_plugin':
					return __( 'The mail code is in a must-use plugin, under wp-content/mu-plugins. Check which sender it sets.', 'user-registration' );
				default:
					return __( 'The mail code is a custom snippet on your site. Check which sender it sets.', 'user-registration' );
			}
		}

		/**
		 * Something can short-circuit wp_mail(), but whether it actually does
		 * depends on its own connection state — a plugin that isn't finished
		 * being set up silently declines and lets mail fall back to the route
		 * below. Confirming it would mean sending a message, so this reports the
		 * possibility and names the fallback.
		 *
		 * @param array $transport Transport inspection.
		 * @return array
		 */
		private static function check_possible_diversion( $transport ) {
			$owner = $transport['diverted_by'];

			return array(
				'key'     => 'possible_diversion',
				'title'   => sprintf(
					/* translators: %s: plugin, theme or source name */
					__( '%s may be handling your mail', 'user-registration' ),
					self::owner_label( $owner )
				),
				'status'  => 'unknown',
				'message' => sprintf(
					/* translators: 1: owner name, 2: where to look at it */
					__( '`%1$s` can take outgoing mail over before it reaches the route below. Whether it does depends on its own connection, which can\'t be read from here. %2$s The delivery test in the next step will show what actually happens.', 'user-registration' ),
					self::owner_label( $owner ),
					self::owner_action_hint( $owner )
				),
				'fix'     => '',
			);
		}

		/**
		 * What actually carries mail off this site, and — when something has
		 * taken that over — what did it.
		 *
		 * @param array $transport Transport inspection.
		 * @return array
		 */
		private static function check_sending_route( $transport ) {
			$owner      = $transport['owner'];
			$owner_name = $owner ? $owner['name'] : '';

			if ( 'php_mail' === $transport['route'] ) {
				$php_mail = $transport['php_mail'];

				if ( empty( $php_mail['usable'] ) ) {
						// No technical second line naming the binary: it means nothing to a
					// site owner, and the report carries the same finding.
					if ( 'no_sendmail' === $php_mail['reason'] ) {
						$message = __( 'No mail service is connected, so WordPress hands each message to the server\'s own mail program, which isn\'t installed here. Every email fails silently, with no warning.', 'user-registration' );
					} else {
						$message = __( 'No mail service is connected, so WordPress tries to send through the server itself, and your host has switched that ability off. Every email fails the instant it\'s sent, with no bounce and no warning.', 'user-registration' );
					}

					return array(
						'key'     => 'sending_route',
						'title'   => __( 'This site cannot send email at all', 'user-registration' ),
						'status'  => 'error',
						'message' => $message,
						'fix'     => __( 'Connect an SMTP service. It sends over the network, so nothing needs installing on the server.', 'user-registration' ),
					);
				}

				return array(
					'key'     => 'sending_route',
					'title'   => __( 'Sending through your host\'s mail server', 'user-registration' ),
					'status'  => 'warning',
					'message' => __( 'No SMTP connection is set up, so mail goes out through PHP\'s built-in `mail()`. Most hosts send this unauthenticated and from a shared IP, which is why it so often lands in spam or is dropped without warning.', 'user-registration' ),
					'fix'     => __( 'Connect an SMTP service to send authenticated mail instead.', 'user-registration' ),
				);
			}

			if ( 'replaced' === $transport['route'] ) {
				// A recognised SMTP plugin owning wp_mail() is the arrangement
				// this whole screen recommends, so report it as such. That its
				// internals can't be read from out here is true but useless to
				// an admin — flagging it as unknown would cast doubt on exactly
				// the setup we told them to install.
				$smtp_plugin = self::detected_smtp_plugin();

				if ( $smtp_plugin ) {
					return array(
						'key'     => 'sending_route',
						'title'   => sprintf(
							/* translators: %s: SMTP plugin name */
							__( 'Sending through %s', 'user-registration' ),
							$smtp_plugin['name']
						),
						'status'  => 'pass',
						'message' => sprintf(
							/* translators: %s: SMTP plugin name */
							__( 'Mail is handed to `%s` rather than the server\'s own mail program, which is what you want. The delivery test in the next step confirms its connection is working.', 'user-registration' ),
							$smtp_plugin['name']
						),
						'fix'     => '',
					);
				}

				// Anything else taking over is worth flagging: we can't see what
				// it does, and the admin may not know it's there.
				return array(
					'key'     => 'sending_route',
					'title'   => sprintf(
						/* translators: %s: plugin, theme or source name */
						__( '%s controls how your mail is sent', 'user-registration' ),
						self::owner_label( $owner )
					),
					'status'  => 'unknown',
					'message' => sprintf(
						/* translators: 1: owner name, 2: where to look at it */
						__( '`%1$s` has taken over WordPress\'s mail function, so it decides how each message is delivered and nothing outside it can read that decision. %2$s The delivery test in the next step is what will show whether mail gets through.', 'user-registration' ),
						self::owner_label( $owner ),
						self::owner_action_hint( $owner )
					),
					'fix'     => '',
				);
			}

			return array(
				'key'     => 'sending_route',
				'title'   => __( 'Sending through an SMTP connection', 'user-registration' ),
				'status'  => 'pass',
				'message' => $owner_name
					? sprintf(
						/* translators: %s: plugin, theme or source name */
						__( 'Mail is sent over an authenticated SMTP connection, set up by `%s`.', 'user-registration' ),
						$owner_name
					)
					: __( 'Mail is sent over an authenticated SMTP connection rather than this server\'s mail program.', 'user-registration' ),
				'fix'     => '',
			);
		}

		/**
		 * The SMTP connection's facts, and whether this server can actually open
		 * it. Hosts that silently block outbound 587/465 are close to
		 * undiagnosable from wp-admin without this.
		 *
		 * @param array $transport Transport inspection.
		 * @return array
		 */
		private static function check_smtp_connection( $transport ) {
			$smtp = $transport['smtp'];

			if ( empty( $smtp['host'] ) ) {
				return array(
					'key'     => 'smtp_connection',
					'title'   => __( 'SMTP server is not named', 'user-registration' ),
					'status'  => 'warning',
					'message' => __( 'The SMTP transport is switched on but no server address is configured for it.', 'user-registration' ),
					'fix'     => __( 'Open your SMTP plugin and finish its connection setup.', 'user-registration' ),
				);
			}

			$summary = sprintf(
				/* translators: 1: SMTP host, 2: port, 3: encryption or "no encryption", 4: authenticated state */
				__( 'Sending via `%1$s` on port %2$d, %3$s, %4$s.', 'user-registration' ),
				$smtp['host'],
				$smtp['port'],
				$smtp['encryption'] ? $smtp['encryption'] : __( 'no encryption', 'user-registration' ),
				$smtp['auth'] ? __( 'with authentication', 'user-registration' ) : __( 'without authentication', 'user-registration' )
			);

			// Reserved TLDs never resolve, and a connection attempt spends a full
			// resolver timeout finding that out — PHP offers no way to bound one.
			// Nothing is learned by trying, so don't.
			if ( UR_Email_Domain_Inspector::is_local_domain( $smtp['host'] ) ) {
				return array(
					'key'     => 'smtp_connection',
					'title'   => __( 'SMTP server is a local address', 'user-registration' ),
					'status'  => 'unknown',
					'message' => $summary . ' ' . sprintf(
						/* translators: %s: SMTP host */
						__( '`%s` is a development address, so it can\'t be reached from the internet and there is nothing to test here.', 'user-registration' ),
						$smtp['host']
					),
					'fix'     => __( 'Point this at a real mail server before going live.', 'user-registration' ),
				);
			}

			$probe = UR_Email_Transport_Inspector::probe_smtp_port( $smtp['host'], $smtp['port'] );

			if ( false === $probe['reachable'] ) {
				return array(
					'key'     => 'smtp_connection',
					'title'   => __( 'SMTP server can\'t be reached from this site', 'user-registration' ),
					'status'  => 'error',
					'message' => $summary . ' ' . sprintf(
						/* translators: 1: port, 2: connection error */
						__( 'The connection was refused (%2$s), which usually means your host blocks outgoing traffic on port %1$d.', 'user-registration' ),
						$smtp['port'],
						$probe['error'] ? $probe['error'] : __( 'no response', 'user-registration' )
					),
					'fix'     => __( 'Ask your host to open outbound SMTP, or switch to a port they allow (often 587 or 2525).', 'user-registration' ),
				);
			}

			return array(
				'key'     => 'smtp_connection',
				'title'   => __( 'SMTP connection looks healthy', 'user-registration' ),
				'status'  => 'pass',
				'message' => $summary . ( true === $probe['reachable'] ? ' ' . __( 'The server accepted a connection.', 'user-registration' ) : '' ),
				'fix'     => '',
			);
		}

		/* --------------------------------------------------------------------
		 * Section 1b — is this site allowed to send as the "From" address?
		 * ----------------------------------------------------------------- */

		/**
		 * @param array      $transport Transport inspection.
		 * @param array      $sender    Resolved sender { address, name, resolved }.
		 * @param string     $domain    Effective From domain.
		 * @param array|null $dns       DNS inspection for that domain.
		 * @return array
		 */
		private static function identity_checks( $transport, $sender, $domain, $dns ) {
			$checks = array(
				self::check_from_address_valid( $sender ),
				self::check_from_effective( $transport, $sender ),
			);

			// Everything below reasons about the domain; without a usable one
			// they'd all just repeat the "address is invalid" finding.
			if ( '' === $domain || null === $dns ) {
				return $checks;
			}

			// On a mailbox provider this DNS is Google's or Yahoo's, so calling it "your
			// domain" is advice nobody can act on.
			if ( ! UR_Email_Domain_Inspector::is_mailbox_provider( $domain ) ) {
				$checks[] = self::check_from_domain( $domain, $dns );
			}

			// Only matters once something is actually sending. It returns on the next
			// scan once the route is fixed, unless the fix resolves it.
			if ( ! self::cannot_send( $transport ) ) {
				$checks[] = self::check_from_alignment( $domain, $transport );
			}

			// SPF and DMARC live in dns_checks(), which reports rather than presents.

			// A row that says "we couldn't tell" gives the admin doubt and no
			// action. The summary already reports when the scan as a whole
			// couldn't reach a conclusion.
			return array_filter(
				$checks,
				function ( $check ) {
					return $check && 'unknown' !== $check['status'];
				}
			);
		}

		/**
		 * Whether the server has no working way to send mail at all. Findings about
		 * who the site may send *as* are suppressed while this is true; see
		 * identity_checks(). Covers only the dead-PHP-mail case: an unreachable SMTP
		 * host is established by a socket probe in check_smtp_connection().
		 *
		 * @param array $transport Transport inspection.
		 * @return bool
		 */
		private static function cannot_send( $transport ) {
			return 'php_mail' === $transport['route'] && empty( $transport['php_mail']['usable'] );
		}

		/**
		 * What names the thing doing the sending, mid-sentence.
		 *
		 * @param array $transport Transport inspection.
		 * @return string
		 */
		private static function sender_phrase( $transport ) {
			if ( 'php_mail' === $transport['route'] ) {
				return __( 'your web host\'s shared mail server', 'user-registration' );
			}

			return empty( $transport['owner']['name'] )
				? __( 'whatever sends your mail', 'user-registration' )
				: $transport['owner']['name'];
		}

		/**
		 * SPF and DMARC as a single finding, in the case where they share a
		 * cause: the domain refuses senders it hasn't approved, and this site
		 * isn't one of them.
		 *
		 * Returns null when they don't share a cause, so each is reported on its
		 * own terms instead.
		 *
		 * @param string $domain    From domain.
		 * @param array  $dns       DNS inspection.
		 * @param array  $transport Transport inspection.
		 * @return array|null
		 */
		private static function check_sender_rules( $domain, $dns, $transport ) {
			if ( ! self::is_unauthenticated_for( $transport, $domain ) ) {
				return null;
			}

			$spf    = $dns['spf'];
			$strict = ! empty( $spf['found'] ) && '-all' === $spf['qualifier'];
			$policy = ! empty( $dns['dmarc']['found'] ) ? $dns['dmarc']['policy'] : '';

			// A record naming nobody is its own story: the domain isn't refusing
			// *this* site, it refuses everyone.
			if ( ! empty( $spf['found'] ) && empty( $spf['authorises'] ) ) {
				return array(
					'key'     => 'sender_rules',
					'title'   => sprintf(
						/* translators: %s: domain */
						__( '`%s` doesn\'t let anyone send email as it', 'user-registration' ),
						$domain
					),
					'status'  => 'error',
					'message' => __( 'This domain has approved nobody: not you, not anyone. Every message claiming to come from it is blocked on arrival. Domains that are never meant to send mail are set up this way on purpose.', 'user-registration' ),
					'fix'     => __( 'Send from a domain you own.', 'user-registration' ),
				);
			}

			if ( 'reject' === $policy || $strict ) {
				return array(
					'key'     => 'sender_rules',
					'title'   => __( 'Your emails are being blocked before anyone sees them', 'user-registration' ),
					'status'  => 'error',
					'message' => sprintf(
						/* translators: 1: sending domain, 2: what is doing the sending */
						__( 'You send as `%1$s`, but this site sends through %2$s, which `%1$s` has not approved. Your domain instructs mail servers to refuse anything from a sender it hasn\'t approved, so your emails are blocked on arrival. They never reach the spam folder, and you get no bounce.', 'user-registration' ),
						$domain,
						self::sender_phrase( $transport )
					),
					'fix'     => sprintf(
						/* translators: %s: domain */
						__( 'Connect an email service (SMTP) to this site, then list it as an approved sender for `%s`.', 'user-registration' ),
						$domain
					),
				);
			}

			if ( 'quarantine' === $policy ) {
				return array(
					'key'     => 'sender_rules',
					'title'   => __( 'Your emails are going straight to spam', 'user-registration' ),
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: 1: sending domain, 2: what is doing the sending */
						__( 'You send as `%1$s`, but this site sends through %2$s, which `%1$s` has not approved. Your domain tells mail servers to treat anything from an unapproved sender as spam, so that is where your emails are landing.', 'user-registration' ),
						$domain,
						self::sender_phrase( $transport )
					),
					'fix'     => sprintf(
						/* translators: %s: domain */
						__( 'Connect an email service (SMTP) to this site, then list it as an approved sender for `%s`.', 'user-registration' ),
						$domain
					),
				);
			}

			return null;
		}

		/**
		 * The stored "From" address is saved even when it's invalid — the
		 * settings validator raises a notice but still returns the value — and
		 * the field is marked multi-value, which invites a comma-separated list
		 * that would produce one malformed header.
		 *
		 * @param string $from Configured From address.
		 * @return array
		 */
		private static function check_from_address_valid( $sender ) {
			$from     = trim( (string) $sender['address'] );
			$overridden = ! self::sender_matches_setting( $sender );

			// Naming where the value came from matters here: under "Mail
			// Delivery" an unlabelled address reads as the one being sent, which
			// is wrong the moment an SMTP plugin rewrites it. Titles are rendered
			// as plain text, so no markdown emphasis here.
			$source = $overridden
				? __( 'set by your SMTP configuration', 'user-registration' )
				: __( 'from Emails → General', 'user-registration' );

			if ( '' === $from ) {
				return array(
					'key'     => 'from_address_valid',
					'title'   => __( 'There is no address to send from', 'user-registration' ),
					'status'  => 'error',
					// Reachable only when the admin email is empty too: a blank
					// "From" field falls back to it, so both have to be missing
					// before there is genuinely nothing to send as.
					'message' => __( 'The "From" address is blank and so is the site\'s admin email, so there is nothing to put in the sender field. Emails go out with a broken sender header, which mail servers reject.', 'user-registration' ),
					'fix'     => __( 'Set a "From" address under **Emails → General**, or an admin email under **Settings → General**.', 'user-registration' ),
				);
			}

			if ( false !== strpos( $from, '{{' ) ) {
				return array(
					'key'     => 'from_address_valid',
					'title'   => __( '"From" address contains a smart tag', 'user-registration' ),
					'status'  => 'error',
					'message' => sprintf(
						/* translators: %s: configured From address */
						__( '`%s` is only replaced with a real address on some email paths. On the rest it is sent exactly as written, producing an invalid sender.', 'user-registration' ),
						$from
					),
					'fix'     => __( 'Use a fixed address under **Emails → General**.', 'user-registration' ),
				);
			}

			if ( false !== strpos( $from, ',' ) || false !== strpos( $from, ';' ) ) {
				return array(
					'key'     => 'from_address_valid',
					'title'   => __( '"From" address holds more than one address', 'user-registration' ),
					'status'  => 'error',
					'message' => __( 'A message can only have one sender. The whole value is written into a single header, which mail servers reject as malformed.', 'user-registration' ),
					'fix'     => __( 'Leave a single address under **Emails → General**.', 'user-registration' ),
				);
			}

			if ( ! is_email( $from ) ) {
				return array(
					'key'     => 'from_address_valid',
					'title'   => __( '"From" address is not a valid email', 'user-registration' ),
					'status'  => 'error',
					'message' => sprintf(
						/* translators: %s: configured From address */
						__( '`%s` isn\'t a usable email address. It was saved anyway, because the settings screen only warns about this.', 'user-registration' ),
						$from
					),
					'fix'     => __( 'Correct it under **Emails → General**.', 'user-registration' ),
				);
			}

			// The name shares this check because it shares the header: both are
			// written into the same `From:` line, so a fault in either breaks
			// that one line. Split apart, the two rows described the same
			// string twice.
			$name = (string) $sender['name'];

			if ( preg_match( '/[\r\n]/', $name ) ) {
				return array(
					'key'     => 'from_address_valid',
					'title'   => __( '"From" name contains a line break', 'user-registration' ),
					'status'  => 'error',
					'message' => __( 'Line breaks split the message header in two. Mail servers reject this, and it is how header-injection attacks work.', 'user-registration' ),
					'fix'     => __( 'Remove the line break under **Emails → General**.', 'user-registration' ),
				);
			}

			if ( preg_match( '/[,;:<>@"]/', $name ) ) {
				return array(
					'key'     => 'from_address_valid',
					'title'   => __( '"From" name needs quoting', 'user-registration' ),
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: %s: configured From name */
						__( '`%s` contains a character that has a special meaning in a message header, and the name is written out unquoted. Some servers will read the sender incorrectly or reject the message.', 'user-registration' ),
						$name
					),
					'fix'     => __( 'Remove punctuation such as commas or colons from the name under **Emails → General**.', 'user-registration' ),
				);
			}

			$display = '' === trim( $name ) ? $from : $name . ' <' . $from . '>';

			return array(
				'key'     => 'from_address_valid',
				'title'   => sprintf(
					/* translators: %s: where the sender details come from */
					__( '"From" address and name are well formed (%s)', 'user-registration' ),
					$source
				),
				'status'  => 'pass',
				'message' => sprintf(
					// Claiming "mail is sent as" would contradict the note below
					// whenever the transport addresses messages out of sight.
					$sender['resolved']
						/* translators: %s: the sender mail actually goes out as */
						? __( 'Mail is sent as `%s`.', 'user-registration' )
						/* translators: %s: the best-known sender */
						: __( '`%s` is the sender we can see. Whatever handles your sending may replace it. See the note below.', 'user-registration' ),
					$display
				),
				'fix'     => '',
			);
		}

		/**
		 * Whether the configured sender and the sender a message really leaves
		 * with are the same value.
		 *
		 * @param array $sender Resolved sender.
		 * @return bool
		 */
		private static function sender_matches_setting( $sender ) {
			return $sender['address'] === (string) self::from_address()
				&& $sender['name'] === self::from_name();
		}

		/**
		 * Whether the "From" you configured is the "From" that actually goes
		 * out. SMTP plugins routinely force their own sender, which leaves the
		 * plugin's setting saying one thing while every email says another.
		 *
		 * Three outcomes, and the third is the important one: when a plugin
		 * replaces wp_mail() outright it addresses the message inside its own
		 * code, so neither of WordPress's sender seams reveals what it will use.
		 * Rather than reporting the saved setting as though it were in force,
		 * say plainly that we can't see it.
		 *
		 * @param array $transport Transport inspection.
		 * @param array $sender    Resolved sender.
		 * @return array
		 */
		private static function check_from_effective( $transport, $sender ) {
			$configured_address = (string) self::from_address();
			$configured_name    = self::from_name();

			if ( ! self::sender_matches_setting( $sender ) ) {
				$changed = array();

				if ( $sender['address'] !== $configured_address ) {
					$changed[] = sprintf(
						/* translators: 1: configured address, 2: address actually used */
						__( 'address `%1$s` is sent as `%2$s`', 'user-registration' ),
						'' === $configured_address ? __( '(empty)', 'user-registration' ) : $configured_address,
						$sender['address']
					);
				}

				if ( $sender['name'] !== $configured_name ) {
					$changed[] = sprintf(
						/* translators: 1: configured name, 2: name actually used */
						__( 'name `%1$s` is sent as `%2$s`', 'user-registration' ),
						'' === $configured_name ? __( '(empty)', 'user-registration' ) : $configured_name,
						$sender['name']
					);
				}

				return array(
					'key'     => 'from_effective',
					'title'   => __( 'Your SMTP setup is overriding the sender', 'user-registration' ),
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: %s: list of overridden values */
						__( 'Your mail plugin replaces the sender before the message goes out: %s. The checks below describe the address actually being used, not the one under **Emails → General**.', 'user-registration' ),
						implode( __( ', and ', 'user-registration' ), $changed )
					),
					'fix'     => __( 'That is fine if it is deliberate. Just make sure the address it forces is one you can authenticate. Otherwise turn off "force from address" in your SMTP plugin.', 'user-registration' ),
				);
			}

			if ( UR_Email_Transport_Inspector::is_opaque() ) {
				$owner = UR_Email_Transport_Inspector::diverter();
				$owner = $owner ? $owner : $transport['owner'];
				$label = self::owner_label( $owner );

				return array(
					// Its own key, not "from_effective": this finding is a statement
					// about what the scan can see rather than about the site, so the
					// result screens drop it once a test email has actually arrived
					// and settled the question. The override variant below is a fact
					// that survives delivery, and keeps the original key.
					'key'     => 'from_effective_unknown',
					'title'   => __( 'The sender can\'t be confirmed from here', 'user-registration' ),
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: 1: owner name, 2: configured From address */
						__( '`%1$s` can send mail through its own connection, addressing each message internally where nothing outside can read it. Your **Emails → General** setting is `%2$s`, but if it is set to force its own sender, that address is used instead.', 'user-registration' ),
						$label,
						'' === $configured_address ? __( '(empty)', 'user-registration' ) : $configured_address
					),
					'fix'     => self::owner_action_hint( $owner ) . ' ' . __( 'Whichever address it sends as is the one your domain has to be allowed to send for.', 'user-registration' ),
				);
			}

			return array(
				'key'     => 'from_effective',
				'title'   => __( 'Nothing is overriding your sender details', 'user-registration' ),
				'status'  => 'pass',
				'message' => __( 'The "From" name and address set under **Emails → General** are what recipients will see.', 'user-registration' ),
				'fix'     => '',
			);
		}

		/**
		 * Whether the sending domain can receive mail back. A domain with no
		 * mail servers loses every bounce and reply.
		 *
		 * @param string $domain From domain.
		 * @param array  $dns    DNS inspection.
		 * @return array
		 */
		private static function check_from_domain( $domain, $dns ) {
			if ( UR_Email_Domain_Inspector::is_local_domain( $domain ) ) {
				return array(
					'key'     => 'from_domain',
					'title'   => __( '"From" domain is a local address', 'user-registration' ),
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: %s: domain */
						__( '`%s` is a development domain, not a real one, and it was never meant to have mail servers.', 'user-registration' ),
						$domain
					),
					'fix'     => __( 'Fine while developing. Switch to a real domain before sending live email.', 'user-registration' ),
				);
			}

			if ( empty( $dns['resolvable'] ) ) {
				return array(
					'key'     => 'from_domain',
					'title'   => __( 'The "From" domain doesn\'t exist', 'user-registration' ),
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: %s: domain */
						__( '`%s` didn\'t answer any DNS lookup, so as far as the internet is concerned it isn\'t a real domain. Mail sent from an address there has nowhere to come back to, and receiving mail servers treat it as forged. Check the spelling.', 'user-registration' ),
						$domain
					),
					'fix'     => __( 'Correct the domain under **Emails → General**, or use one you own.', 'user-registration' ),
				);
			}

			if ( empty( $dns['has_mx'] ) ) {
				return array(
					'key'     => 'from_domain',
					'title'   => __( '"From" domain can\'t receive replies', 'user-registration' ),
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: %s: domain */
						__( 'No mail servers are published for `%s`, so bounces and replies are lost. Receiving servers also treat a sender domain with no mail servers as suspicious. Check the address for a typo.', 'user-registration' ),
						$domain
					),
					'fix'     => __( 'Use a domain that can receive mail.', 'user-registration' ),
				);
			}

			return array(
				'key'     => 'from_domain',
				'title'   => __( '"From" domain can receive replies', 'user-registration' ),
				'status'  => 'pass',
				'message' => sprintf(
					/* translators: %s: domain */
					__( '`%s` publishes mail servers, so replies and bounces reach you.', 'user-registration' ),
					$domain
				),
				'fix'     => '',
			);
		}

		/**
		 * How to refer to the domain the admin ought to be sending from. On a
		 * development site the site's own host is a local name that can't send
		 * real mail either, so it must not be offered as the answer.
		 *
		 * @param string $site_domain Site host.
		 * @return string
		 */
		private static function own_domain_phrase( $site_domain ) {
			return UR_Email_Domain_Inspector::is_local_domain( $site_domain )
				? __( 'the real domain this site will run on', 'user-registration' )
				: sprintf( '`%s`', $site_domain );
		}

		/**
		 * Whether the site is entitled to send as this domain at all. A personal
		 * mailbox address is the common case and can never be authenticated —
		 * you cannot publish DNS for gmail.com.
		 *
		 * @param string $domain From domain.
		 * @return array
		 */
		private static function check_from_alignment( $domain, $transport ) {
			$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );

			if ( UR_Email_Domain_Inspector::is_mailbox_provider( $domain ) ) {
				// Sending as the provider's address *through that provider* is the
				// one arrangement that works: they sign it themselves. Flagging it
				// would condemn one of the most common working setups there is.
				if ( self::is_authenticated_for( $transport, $domain ) ) {
					return array(
						'key'     => 'from_alignment',
						'title'   => __( 'Sending through the provider that owns this address', 'user-registration' ),
						'status'  => 'pass',
						'message' => sprintf(
							/* translators: %s: from domain */
							__( 'Mail is sent as `%1$s` through `%1$s`\'s own mail servers, so they vouch for it. That is exactly how a personal address should be used.', 'user-registration' ),
							$domain
						),
						'fix'     => '',
					);
				}

				return array(
					'key'     => 'from_alignment',
					'title'   => __( '"From" address belongs to a mailbox provider', 'user-registration' ),
					'status'  => 'error',
					'message' => sprintf(
						/* translators: %s: from domain */
						__( 'Mail is sent as `%s` but not through their servers. Only they can approve senders for their domain, and they won\'t approve yours, so receiving mail servers treat it as forged.', 'user-registration' ),
						$domain
					),
					'fix'     => sprintf(
						/* translators: %s: the domain to send from instead */
						__( 'Send from an address on %s, and put the personal one in Reply-To.', 'user-registration' ),
						self::own_domain_phrase( $site_domain )
					),
				);
			}

			if ( UR_Email_Domain_Inspector::domains_align( $domain, $site_domain ) ) {
				return array(
					'key'     => 'from_alignment',
					'title'   => __( '"From" address is on your own domain', 'user-registration' ),
					'status'  => 'pass',
					'message' => sprintf(
						/* translators: %s: domain */
						__( 'Sending as `%s` matches this site, which is what receiving servers expect.', 'user-registration' ),
						$domain
					),
					'fix'     => '',
				);
			}

			if ( UR_Email_Domain_Inspector::is_local_domain( $site_domain ) ) {
				return array(
					'key'     => 'from_alignment',
					'title'   => __( 'Site is running on a local domain', 'user-registration' ),
					'status'  => 'unknown',
					'message' => sprintf(
						/* translators: 1: from domain, 2: site domain */
						__( 'Mail is sent as `%1$s` while the site runs at `%2$s`. On a live site these should match; on a development site this can be ignored.', 'user-registration' ),
						$domain,
						$site_domain
					),
					'fix'     => '',
				);
			}

			return array(
				'key'     => 'from_alignment',
				'title'   => __( '"From" address is on a different domain', 'user-registration' ),
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: 1: from domain, 2: site domain */
					__( 'Mail claims to come from `%1$s` while the site is `%2$s`. That is allowed, but `%1$s` has to explicitly authorise this server to send for it. Otherwise receiving mail servers treat the message as forged.', 'user-registration' ),
					$domain,
					$site_domain
				),
				'fix'     => sprintf(
					/* translators: %s: the domain to send from instead */
					__( 'Either send from an address on %s, or make sure the other domain authorises whatever sends your mail.', 'user-registration' ),
					self::own_domain_phrase( $site_domain )
				),
			);
		}

		/**
		 * Whether mail can prove it is genuinely from this "From" domain.
		 *
		 * Three answers, because two would force a guess. Where the DNS settles
		 * it we say so; where it can't, we say that instead of assuming a pass:
		 *
		 *  - 'no'      PHP mail() (the envelope carries the host's domain, so
		 *              alignment fails whatever the records say); a provider
		 *              address sent through anyone but that provider; or a
		 *              record that authorises nobody at all.
		 *  - 'yes'     the provider's own servers, or a sending host the
		 *              domain's SPF actually delegates to.
		 *  - 'unknown' the transport addresses mail out of sight, or the record
		 *              authorises IP ranges we can't tie to a hostname.
		 *
		 * The SPF, DMARC and alignment checks and the overall summary all read
		 * this, so no row can disagree with the banner above it.
		 *
		 * @param array  $transport Transport inspection.
		 * @param string $domain    From domain.
		 * @return string 'yes' | 'no' | 'unknown'
		 */
		private static function authentication_state( $transport, $domain ) {
			if ( 'php_mail' === $transport['route'] ) {
				return 'no';
			}

			$host = isset( $transport['smtp']['host'] ) ? $transport['smtp']['host'] : '';

			if ( UR_Email_Domain_Inspector::is_mailbox_provider( $domain ) ) {
				return ( 'smtp' === $transport['route'] && UR_Email_Domain_Inspector::is_provider_smtp_host( $domain, $host ) )
					? 'yes'
					: 'no';
			}

			return UR_Email_Domain_Inspector::spf_covers_host( $domain, $host );
		}

		/**
		 * Only where authentication is actually established — an unknown must
		 * never be treated as a pass.
		 *
		 * @param array  $transport Transport inspection.
		 * @param string $domain    From domain.
		 * @return bool
		 */
		private static function is_authenticated_for( $transport, $domain ) {
			return 'yes' === self::authentication_state( $transport, $domain );
		}

		/**
		 * Only where failure is established, so a row never asserts a problem
		 * it hasn't proved.
		 *
		 * @param array  $transport Transport inspection.
		 * @param string $domain    From domain.
		 * @return bool
		 */
		private static function is_unauthenticated_for( $transport, $domain ) {
			return 'no' === self::authentication_state( $transport, $domain );
		}

		/**
		 * SPF lists which servers may send for a domain. Its final rule decides
		 * what happens to everything else — `-all` means reject.
		 *
		 * A strict record is only good news while mail actually leaves through a
		 * listed server; judged on its own it would report a green row on a site
		 * whose every message that record is rejecting.
		 *
		 * @param string $domain    From domain.
		 * @param array  $dns       DNS inspection.
		 * @param array  $transport Transport inspection.
		 * @return array|null
		 */
		private static function check_spf( $domain, $dns, $transport ) {
			if ( UR_Email_Domain_Inspector::is_local_domain( $domain ) || empty( $dns['resolvable'] ) ) {
				return null;
			}

			if ( empty( $dns['spf']['found'] ) ) {
				return array(
					'key'     => 'spf_record',
					'title'   => __( 'No SPF record, so receiving mail servers can\'t verify your mail', 'user-registration' ),
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: %s: domain */
						__( 'SPF is a DNS record listing which servers may send email as `%s`. Yours doesn\'t publish one, so receiving mail servers have nothing to check against and judge your mail on reputation alone, which usually means the spam folder.', 'user-registration' ),
						$domain
					),
					'fix'     => __( 'Add an SPF record at your domain\'s DNS provider. Whichever mail service you use will give you the exact line to publish.', 'user-registration' ),
				);
			}

			$strict = '-all' === $dns['spf']['qualifier'];

			if ( $strict && self::is_unauthenticated_for( $transport, $domain ) ) {
				// A record with no mechanisms at all authorises nobody, so the
				// cause isn't this site's route — no sender anywhere can pass.
				// Saying "your host's mail server" here would name the wrong
				// culprit, and the domain may not even be the admin's.
				if ( empty( $dns['spf']['authorises'] ) ) {
					return array(
						'key'     => 'spf_record',
						'title'   => sprintf(
							/* translators: %s: domain */
							__( '`%s` does not allow anything to send as it', 'user-registration' ),
							$domain
						),
						'status'  => 'error',
						'message' => sprintf(
							/* translators: %s: domain */
							__( 'The SPF record for `%s` is `v=spf1 -all`, which names no permitted sender at all, so every message claiming to come from it is rejected. That is deliberate on domains never meant to send mail, and it means this address can never deliver.', 'user-registration' ),
							$domain
						),
						'fix'     => __( 'Send from a domain you control and can publish DNS for.', 'user-registration' ),
					);
				}

				return array(
					'key'     => 'spf_record',
					'title'   => __( 'Your SPF record is rejecting this site\'s mail', 'user-registration' ),
					'status'  => 'error',
					// Deliberately not "your host isn't on that list" — we never
					// read the list, and it wouldn't matter if it were: mail
					// leaves under the host's own return address, so receiving mail servers
					// check the host's SPF record and never open this domain's.
					'message' => sprintf(
						/* translators: %s: domain */
						__( '`%s` tells receiving mail servers to reject mail it hasn\'t approved. This site sends through your host\'s shared mail server, which sends under its own return address rather than yours, so the message can\'t be matched to your domain, and receiving mail servers are told to throw it away.', 'user-registration' ),
						$domain
					),
					'fix'     => __( 'Connect an SMTP service authorised for this domain, so mail goes out under your name rather than your host\'s.', 'user-registration' ),
				);
			}

			if ( $strict ) {
				// Only claim the sending service is covered when the record was
				// actually followed and it matched.
				if ( self::is_authenticated_for( $transport, $domain ) ) {
					return array(
						'key'     => 'spf_record',
						'title'   => __( 'Your domain lists its approved mail servers, strictly (SPF)', 'user-registration' ),
						'status'  => 'pass',
						'message' => sprintf(
							/* translators: %s: domain */
							__( '`%s` tells receiving mail servers to reject anything sent from a server it hasn\'t listed, and the service you send through is one of them.', 'user-registration' ),
							$domain
						),
						'fix'     => '',
					);
				}

				return array(
					'key'     => 'spf_record',
					'title'   => __( 'Can\'t confirm your SPF record covers this site', 'user-registration' ),
					'status'  => 'unknown',
					'message' => sprintf(
						/* translators: %s: domain */
						__( '`%s` tells receiving mail servers to reject anything sent from a server it hasn\'t listed. We followed the record but couldn\'t match it to whatever sends your mail. It may authorise servers by address rather than by name.', 'user-registration' ),
						$domain
					),
					'fix'     => __( 'Confirm your mail service is listed in the SPF record for this domain.', 'user-registration' ),
				);
			}

			$qualifier = $dns['spf']['qualifier'];

			if ( '+all' === $qualifier ) {
				return array(
					'key'     => 'spf_record',
					'title'   => __( 'Your SPF record authorises the entire internet', 'user-registration' ),
					'status'  => 'warning',
					'message' => sprintf(
						/* translators: %s: domain */
						__( '`%s` ends its SPF record with `+all`, which tells receiving mail servers that any server anywhere may send as your domain. That defeats the point of publishing SPF, and many providers treat it as a spam signal in itself.', 'user-registration' ),
						$domain
					),
					'fix'     => __( 'Replace `+all` with `~all` or `-all` at your DNS provider, once the record lists every service you send through.', 'user-registration' ),
				);
			}

			// An empty qualifier means the record has no `all` mechanism of its
			// own — typically it hands off with `redirect=`. Naming a qualifier
			// here would be inventing one.
			if ( '' === $qualifier ) {
				return array(
					'key'     => 'spf_record',
					'title'   => __( 'Your domain lists its approved mail servers (SPF)', 'user-registration' ),
					'status'  => 'pass',
					'message' => sprintf(
						/* translators: %s: domain */
						__( '`%s` publishes an SPF record, but it sets no final rule of its own, so it defers to another record. Receivers can still check your mail; how strictly depends on the record it points at.', 'user-registration' ),
						$domain
					),
					'fix'     => '',
				);
			}

			return array(
				'key'     => 'spf_record',
				'title'   => __( 'Your domain lists its approved mail servers, loosely (SPF)', 'user-registration' ),
				'status'  => 'pass',
				'message' => sprintf(
					/* translators: 1: domain, 2: the record's final qualifier, e.g. "~all" */
					__( '`%1$s` lists its permitted senders but ends with `%2$s`, so receiving mail servers are asked to accept mail from elsewhere and merely treat it as suspicious. It helps, though it won\'t stop someone forging your address.', 'user-registration' ),
					$domain,
					$qualifier
				),
				'fix'     => '',
			);
		}

		/**
		 * DMARC decides what a receiver does when a message fails
		 * authentication. `p=reject` means it is discarded at the gateway — no
		 * spam folder, no useful bounce.
		 *
		 * Like SPF, the record is only half the story: `p=reject` protects a
		 * domain that sends authenticated and destroys the mail of one that
		 * doesn't, so the route has to be part of the reading.
		 *
		 * @param string $domain    From domain.
		 * @param array  $dns       DNS inspection.
		 * @param array  $transport Transport inspection.
		 * @return array|null
		 */
		private static function check_dmarc( $domain, $dns, $transport ) {
			if ( UR_Email_Domain_Inspector::is_local_domain( $domain ) || empty( $dns['resolvable'] ) ) {
				return null;
			}

			if ( empty( $dns['dmarc']['found'] ) ) {
				return array(
					'key'     => 'dmarc_policy',
					'title'   => __( 'No DMARC record, so each receiver decides for itself', 'user-registration' ),
					'status'  => 'pass',
					'message' => sprintf(
						/* translators: %s: domain */
						__( 'DMARC tells receiving mail servers what to do when a message fails the SPF and DKIM checks for `%s`. Without one, every provider applies its own judgement, usually the spam folder. Publishing `p=none` costs nothing and starts reporting who is sending as you.', 'user-registration' ),
						$domain
					),
					'fix'     => '',
				);
			}

			$policy        = $dns['dmarc']['policy'];
			$authenticated = self::is_authenticated_for( $transport, $domain );
			$unauthenticated = self::is_unauthenticated_for( $transport, $domain );

			if ( 'reject' === $policy ) {
				if ( $unauthenticated ) {
					return array(
						'key'     => 'dmarc_policy',
						'title'   => __( 'DMARC is discarding this site\'s mail', 'user-registration' ),
						'status'  => 'error',
						'message' => sprintf(
							/* translators: %s: domain */
							__( '`%s` tells receiving mail servers to throw away any mail it can\'t verify. This site can\'t be verified, so your emails are deleted on arrival: no spam folder, no bounce.', 'user-registration' ),
							$domain
						),
						'fix'     => __( 'Send from your own domain, or through a service authorised for this one.', 'user-registration' ),
					);
				}

				return array(
					'key'     => 'dmarc_policy',
					'title'   => __( 'DMARC is set to reject, the strictest protection', 'user-registration' ),
					'status'  => $authenticated ? 'pass' : 'unknown',
					'message' => sprintf(
						/* translators: %s: domain */
						$authenticated
							/* translators: %s: domain */
							? __( '`%s` tells receiving mail servers to discard anything that can\'t prove it came from an authorised server. Your own mail is unaffected, because its SPF record covers the service you send through.', 'user-registration' )
							/* translators: %s: domain */
							: __( '`%s` tells receiving mail servers to discard anything that can\'t prove it came from an authorised server. Whether your own mail clears that bar isn\'t something we can confirm from here. The delivery test in the next step will show.', 'user-registration' ),
						$domain
					),
					'fix'     => '',
				);
			}

			if ( 'quarantine' === $policy ) {
				if ( $unauthenticated ) {
					return array(
						'key'     => 'dmarc_policy',
						'title'   => __( 'DMARC is sending this site\'s mail to spam', 'user-registration' ),
						'status'  => 'warning',
						'message' => sprintf(
							/* translators: %s: domain */
							__( '`%s` publishes `p=quarantine`, so receiving mail servers file unauthenticated mail straight into spam. This site sends unauthenticated, so that\'s where your registration emails are landing.', 'user-registration' ),
							$domain
						),
						'fix'     => __( 'Send through a mail service authorised for this domain so your mail passes its checks.', 'user-registration' ),
					);
				}

				return array(
					'key'     => 'dmarc_policy',
					'title'   => __( 'DMARC is set to quarantine', 'user-registration' ),
					'status'  => $authenticated ? 'pass' : 'unknown',
					'message' => sprintf(
						/* translators: %s: domain */
						$authenticated
							/* translators: %s: domain */
							? __( '`%s` asks receiving mail servers to file unauthenticated mail as spam. Your own mail is unaffected, because its SPF record covers the service you send through.', 'user-registration' )
							/* translators: %s: domain */
							: __( '`%s` asks receiving mail servers to file unauthenticated mail as spam. Whether your own mail clears that bar isn\'t something we can confirm from here.', 'user-registration' ),
						$domain
					),
					'fix'     => '',
				);
			}

			return array(
				'key'     => 'dmarc_policy',
				'title'   => __( 'DMARC is monitoring only', 'user-registration' ),
				'status'  => 'pass',
				'message' => sprintf(
					/* translators: %s: domain */
					__( '`%s` publishes DMARC but asks receiving mail servers to take no action on failures, so it collects reports without protecting the domain. A fine first step; tighten to `quarantine` once the reports look clean.', 'user-registration' ),
					$domain
				),
				'fix'     => '',
			);
		}

		/* --------------------------------------------------------------------
		 * Section 1c — what actually happened on recent sends.
		 * ----------------------------------------------------------------- */

		/**
		 * The plugin already records genuine send failures — this surfaces them
		 * instead of leaving the admin to guess. The existing admin notice only
		 * appears after more than three failures, so mail can be failing with
		 * nothing anywhere saying so.
		 *
		 * @return array|null
		 */
		private static function check_recent_failures() {
			$failures = get_transient( 'user_registration_mail_send_failed_count' );

			if ( empty( $failures ) || empty( $failures['failed_count'] ) ) {
				return null;
			}

			$count = (int) $failures['failed_count'];
			$error = isset( $failures['error_message'] ) ? trim( wp_strip_all_tags( $failures['error_message'] ) ) : '';

			return array(
				'key'     => 'recent_failures',
				'title'   => sprintf(
					/* translators: %d: number of recent failures */
					_n( '%d email failed to send recently', '%d emails failed to send recently', $count, 'user-registration' ),
					$count
				),
				'status'  => 'error',
				'message' => $error
					? sprintf(
						/* translators: %s: error reported by the mail server */
						__( 'Your mail server reported: `%s`', 'user-registration' ),
						$error
					)
					: __( 'Sends were attempted and rejected, but no reason was reported.', 'user-registration' ),
				'fix'     => __( 'Full details are under **User Registration → Tools → Logs**, in the `ur_mail_logs` file.', 'user-registration' ),
			);
		}

		/* --------------------------------------------------------------------
		 * The overall summary — route and identity combined into one answer.
		 * ----------------------------------------------------------------- */

		/**
		 * Turn the individual findings into the one thing the admin wants to
		 * know: will these emails arrive?
		 *
		 * @param array      $transport        Transport inspection.
		 * @param array      $sender           Resolved sender.
		 * @param string     $domain           Effective From domain.
		 * @param array|null $dns              DNS inspection.
		 * @param bool       $sending_disabled Whether "Disable Emails" is on.
		 * @return array
		 */
		private static function build_summary( $transport, $sender, $domain, $dns, $sending_disabled ) {
			$from = $sender['address'];
			if ( $sending_disabled ) {
				return array(
					'level'   => 'error',
					'title'   => __( 'Registration emails are switched off', 'user-registration' ),
					'message' => __( 'Nobody gets an email when they register, whatever else is configured below. The test email in the next step still sends, so it can confirm delivery works. It just won\'t tell you anything about real registrations.', 'user-registration' ),
				);
			}

			if ( 'php_mail' === $transport['route'] && empty( $transport['php_mail']['usable'] ) ) {
				return array(
					'level'   => 'error',
					'title'   => __( 'Your emails are not being delivered', 'user-registration' ),
					'message' => __( 'This server has no working way to send mail, so every message is lost silently. The cause and the fix are below.', 'user-registration' ),
				);
			}

			if ( '' === $domain || ! is_email( $from ) ) {
				return array(
					'level'   => 'error',
					'title'   => __( 'Emails will be rejected', 'user-registration' ),
					'message' => __( 'Mail servers refuse messages that have no valid sender.', 'user-registration' ),
				);
			}

			if ( UR_Email_Transport_Inspector::is_opaque() && self::sender_matches_setting( $sender ) ) {
				$owner = UR_Email_Transport_Inspector::diverter();
				$owner = $owner ? $owner : $transport['owner'];

				return array(
					'level'   => 'warning',
					'title'   => __( 'We can\'t confirm how your mail is being sent', 'user-registration' ),
					'message' => sprintf(
						/* translators: %s: owner name */
						__( 'Send the test email in the next step. With `%s` handling delivery, that is the only way to find out what really happens.', 'user-registration' ),
						self::owner_label( $owner )
					),
				);
			}

			$authenticated = self::is_authenticated_for( $transport, $domain );
			$unauthenticated = self::is_unauthenticated_for( $transport, $domain );
			$local         = UR_Email_Domain_Inspector::is_local_domain( $domain );

			if ( $local ) {
				return array(
					'level'   => 'warning',
					'title'   => __( 'Sending from a development domain', 'user-registration' ),
					'message' => __( 'No real mail server will accept this. Expected while developing.', 'user-registration' ),
				);
			}

			// Only when the provider isn't the one doing the sending — through
			// their own servers this is a correct, fully authenticated setup.
			if ( UR_Email_Domain_Inspector::is_mailbox_provider( $domain ) && $unauthenticated ) {
				return array(
					'level'   => 'error',
					'title'   => __( 'Emails will be rejected or filtered', 'user-registration' ),
					'message' => sprintf(
						/* translators: %s: domain */
						__( 'Sending as `%s` from anywhere but their own servers can never be authenticated, so providers refuse or filter it. This is the most common reason registration emails vanish.', 'user-registration' ),
						$domain
					),
				);
			}

			$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
			$aligned     = UR_Email_Domain_Inspector::domains_align( $domain, $site_domain );
			$strict_spf  = ! empty( $dns['spf']['found'] ) && '-all' === $dns['spf']['qualifier'];
			$policy      = ! empty( $dns['dmarc']['found'] ) ? $dns['dmarc']['policy'] : '';

			if ( $unauthenticated && ( 'reject' === $policy || $strict_spf ) ) {
				return array(
					'level'   => 'error',
					'title'   => __( 'Emails will be rejected', 'user-registration' ),
					'message' => sprintf(
						/* translators: %s: domain */
						__( '`%s` tells receiving servers to discard unauthenticated mail, and yours is. Messages won\'t reach the spam folder; they will be dropped.', 'user-registration' ),
						$domain
					),
				);
			}

			if ( $unauthenticated && 'quarantine' === $policy ) {
				return array(
					'level'   => 'error',
					'title'   => __( 'Emails will land in spam', 'user-registration' ),
					'message' => sprintf(
						/* translators: %s: domain */
						__( '`%s` tells receiving servers to treat unauthenticated mail as spam, and yours is.', 'user-registration' ),
						$domain
					),
				);
			}

			if ( $unauthenticated ) {
				return array(
					'level'   => 'warning',
					'title'   => __( 'Emails may not be delivered', 'user-registration' ),
					'message' => __( 'Some will arrive, some will be filtered, and you won\'t be told which.', 'user-registration' ),
				);
			}

			if ( ! $aligned ) {
				return array(
					'level'   => 'warning',
					'title'   => __( 'Emails may be filtered', 'user-registration' ),
					'message' => sprintf(
						/* translators: 1: from domain, 2: site domain */
						__( 'Delivery depends on `%1$s` authorising whatever sends mail for `%2$s`.', 'user-registration' ),
						$domain,
						$site_domain
					),
				);
			}

			return array(
				'level'   => 'pass',
				'title'   => __( 'Your email setup looks deliverable', 'user-registration' ),
				'message' => __( 'Everything receiving servers look for is in place.', 'user-registration' ),
			);
		}

		/* --------------------------------------------------------------------
		 * Section 2 — saved plugin settings.
		 * ----------------------------------------------------------------- */

		/**
		 * Whether "Disable Emails" is on, stopping all outgoing mail.
		 *
		 * @return bool
		 */
		private static function is_sending_disabled() {
			return (bool) ur_option_checked( 'user_registration_email_setting_disable_email' );
		}

		/**
		 * A link to the screen that owns a setting. Opened in a new tab, and the
		 * wizard re-scans on return so the row settles green.
		 *
		 * @param string $label Link text.
		 * @param string $url   Destination.
		 * @return array
		 */
		private static function settings_action( $label, $url ) {
			return array(
				'type'  => 'link',
				'label' => $label,
				'url'   => $url,
			);
		}

		/**
		 * A section of the plugin's own email settings.
		 *
		 * @param string $section Section slug, e.g. 'general'.
		 * @return string
		 */
		private static function email_settings_url( $section ) {
			return admin_url( 'admin.php?page=user-registration-settings&tab=email&section=' . $section );
		}

		private static function check_sending_enabled() {
			$disabled = self::is_sending_disabled();

			return array(
				'key'     => 'sending_enabled',
				'title'   => $disabled
					? __( 'Email sending is disabled', 'user-registration' )
					: __( 'Email sending is enabled', 'user-registration' ),
				'status'  => $disabled ? 'error' : 'pass',
				'message' => $disabled
					? __( '"Disable Emails" is on. No registration emails will be sent.', 'user-registration' )
					: __( '"Disable Emails" is off. Emails will fire after registration.', 'user-registration' ),
				// No remedy sentence: the message says what is wrong and the link goes
				// to the switch that fixes it.
				'fix'     => '',
				'action'  => $disabled
					? self::settings_action(
						__( 'Open Emails → General', 'user-registration' ),
						self::email_settings_url( 'general' )
					)
					: null,
			);
		}

		private static function check_admin_email_set() {
			$admin_email = get_option( 'admin_email' );
			$is_valid    = ! empty( $admin_email ) && is_email( $admin_email );

			return array(
				'key'     => 'admin_email_set',
				'title'   => $is_valid
					? __( 'Admin email is set', 'user-registration' )
					: __( 'Admin email is not set', 'user-registration' ),
				'status'  => $is_valid ? 'pass' : 'error',
				'message' => $is_valid
					? sprintf(
						/* translators: %s: admin email */
						__( 'Notifications go to `%s`.', 'user-registration' ),
						$admin_email
					)
					: __( 'No valid admin email address is configured.', 'user-registration' ),
				'fix'     => '',
				'action'  => $is_valid
					? null
					: self::settings_action(
						__( 'Open Settings → General', 'user-registration' ),
						admin_url( 'options-general.php' )
					),
			);
		}

		private static function check_user_registration_email_enabled() {
			$enabled = ur_option_checked( 'user_registration_enable_successfully_registered_email', true );

			return array(
				'key'     => 'user_registration_email_enabled',
				'title'   => $enabled
					? __( 'User registration email is enabled', 'user-registration' )
					: __( 'User registration email is disabled', 'user-registration' ),
				'status'  => $enabled ? 'pass' : 'warning',
				'message' => $enabled
					? __( 'Users will receive an email when they register.', 'user-registration' )
					: __( 'Users will not receive a confirmation email when they register.', 'user-registration' ),
				'fix'     => '',
				'action'  => $enabled
					? null
					: self::settings_action(
						__( 'Open Emails → To User', 'user-registration' ),
						self::email_settings_url( 'to-user' )
					),
			);
		}

		private static function check_admin_notification_enabled() {
			$enabled = ur_option_checked( 'user_registration_enable_admin_email', true );

			return array(
				'key'     => 'admin_notification_enabled',
				'title'   => $enabled
					? __( 'Admin notification email is on', 'user-registration' )
					: __( 'Admin notification email is off', 'user-registration' ),
				'status'  => $enabled ? 'pass' : 'warning',
				'message' => $enabled
					? __( 'You will be notified of new registrations.', 'user-registration' )
					: __( 'You won\'t be notified of new registrations.', 'user-registration' ),
				'fix'     => '',
				'action'  => $enabled
					? null
					: self::settings_action(
						__( 'Open Emails → To Admin', 'user-registration' ),
						self::email_settings_url( 'to-admin' )
					),
			);
		}

		/* --------------------------------------------------------------------
		 * SMTP plugin helpers — also called from UR_AJAX.
		 * ----------------------------------------------------------------- */

		/**
		 * The "From" address currently in effect.
		 *
		 * @return string
		 */
		private static function from_address() {
			// Mirrors UR_Emailer::ur_sender_email(), including its empty-value
			// fallback — reading the raw option instead would report our own
			// fallback as though something were overriding the sender.
			return class_exists( 'UR_Emailer' )
				? UR_Emailer::ur_sender_email()
				: get_option( 'user_registration_email_from_address', get_option( 'admin_email' ) );
		}

		/**
		 * The "From" name currently configured.
		 *
		 * @return string
		 */
		private static function from_name() {
			return class_exists( 'UR_Emailer' )
				? (string) UR_Emailer::ur_sender_name()
				: (string) get_option( 'user_registration_email_from_name', get_bloginfo( 'name', 'display' ) );
		}

		/**
		 * Whether SmartSMTP is installed and/or active.
		 *
		 * @return string One of 'active', 'inactive', 'not_installed'.
		 */
		public static function smartsmtp_status() {
			$plugin_file = 'smart-smtp/smart-smtp.php';

			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) {
				return 'active';
			}

			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				return 'inactive';
			}

			return 'not_installed';
		}

		/**
		 * Common third-party SMTP plugins, used to name the active one in messaging.
		 *
		 * @var array
		 */
		private static $known_smtp_plugins = array(
			'smart-smtp/smart-smtp.php'     => 'SmartSMTP',
			'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP',
			'fluent-smtp/fluent-smtp.php'   => 'FluentSMTP',
			'post-smtp/postman-smtp.php'    => 'Post SMTP',
			'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP',
			'wp-smtp/wp-smtp.php'           => 'WP SMTP',
		);

		/**
		 * Which known SMTP plugin (if any) is currently active.
		 *
		 * @return array|null { slug, name, is_smartsmtp } or null if none of
		 *                     the known plugins are active.
		 */
		public static function detected_smtp_plugin() {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			foreach ( self::$known_smtp_plugins as $plugin_file => $name ) {
				if ( is_plugin_active( $plugin_file ) ) {
					return array(
						'slug'         => $plugin_file,
						'name'         => $name,
						'is_smartsmtp' => 'smart-smtp/smart-smtp.php' === $plugin_file,
					);
				}
			}

			return null;
		}

		/**
		 * Whether a plugin file is one of the SMTP plugins this feature knows
		 * about. The activate endpoint gates on this so a request can never
		 * make it activate an arbitrary plugin.
		 *
		 * @param string $plugin_file Plugin file, e.g. 'wp-mail-smtp/wp_mail_smtp.php'.
		 * @return bool
		 */
		public static function is_known_smtp_plugin( $plugin_file ) {
			return isset( self::$known_smtp_plugins[ $plugin_file ] );
		}

		/**
		 * Display name for a known SMTP plugin file.
		 *
		 * @param string $plugin_file Plugin file.
		 * @return string Empty string if the plugin isn't a known one.
		 */
		public static function known_smtp_plugin_name( $plugin_file ) {
			return self::is_known_smtp_plugin( $plugin_file ) ? self::$known_smtp_plugins[ $plugin_file ] : '';
		}
	}

endif;
