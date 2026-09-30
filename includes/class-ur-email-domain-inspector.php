<?php
/**
 * Email Domain Inspector.
 *
 * Looks up what a sending domain publishes about itself — MX, SPF and DMARC —
 * so the health checkup can say whether this site is actually allowed to send
 * mail as the configured "From" address, rather than only whether that address
 * is spelled correctly.
 *
 * Lookups are not cached: the OS resolver already caches by TTL (a repeat pass
 * measures well under a millisecond), and a second cache layer would only serve
 * stale records to someone who just fixed their DNS and re-ran the scan.
 *
 * @class    UR_Email_Domain_Inspector
 * @package  UserRegistration/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'UR_Email_Domain_Inspector' ) ) :

	/**
	 * UR_Email_Domain_Inspector Class.
	 */
	class UR_Email_Domain_Inspector {

		/**
		 * Per-request memo, keyed by domain. Every identity check needs the same
		 * three records, so they're resolved once and shared.
		 *
		 * @var array
		 */
		private static $lookups = array();

		/**
		 * TLDs reserved for local/development use (RFC 2606 plus the ".local"
		 * convention most local dev tools use). These never have public records,
		 * so their absence isn't a misconfiguration.
		 *
		 * @var array
		 */
		private static $local_tlds = array( 'local', 'test', 'example', 'invalid', 'localhost' );

		/**
		 * Matches SPF's own ten-lookup cap from RFC 7208.
		 */
		const SPF_LOOKUP_LIMIT = 10;

		/**
		 * Mailbox providers a site owner cannot publish DNS for. Sending "as" one
		 * of these from your own server can never be authenticated — no setting
		 * fixes it, because you don't control the domain.
		 *
		 * @var array
		 */
		private static $mailbox_providers = array(
			'gmail.com',
			'googlemail.com',
			'yahoo.com',
			'yahoo.co.uk',
			'yahoo.in',
			'ymail.com',
			'outlook.com',
			'hotmail.com',
			'hotmail.co.uk',
			'live.com',
			'msn.com',
			'aol.com',
			'icloud.com',
			'me.com',
			'mac.com',
			'gmx.com',
			'gmx.net',
			'gmx.de',
			'mail.com',
			'zoho.com',
			'yandex.com',
			'yandex.ru',
			'mail.ru',
			'proton.me',
			'protonmail.com',
			'pm.me',
			'tutanota.com',
			'fastmail.com',
			'qq.com',
			'163.com',
			'126.com',
			'naver.com',
			'rediffmail.com',
		);

		/**
		 * @param string $domain Domain to check.
		 * @return bool
		 */
		public static function is_local_domain( $domain ) {
			if ( empty( $domain ) ) {
				return false;
			}

			$labels = explode( '.', $domain );
			$tld    = end( $labels );

			return in_array( strtolower( $tld ), self::$local_tlds, true );
		}

		/**
		 * @param string $domain Domain to check.
		 * @return bool
		 */
		public static function is_mailbox_provider( $domain ) {
			return in_array( strtolower( (string) $domain ), self::$mailbox_providers, true );
		}

		/**
		 * The SMTP hosts each mailbox provider serves its own users from, where
		 * that host isn't simply a subdomain of the address (which the generic
		 * check below already covers). Microsoft and Apple both send several
		 * brands through one host, so they can't be inferred.
		 *
		 * @var array
		 */
		private static $provider_smtp_hosts = array(
			'outlook.com'    => array( 'smtp.office365.com', 'smtp-mail.outlook.com' ),
			'hotmail.com'    => array( 'smtp.office365.com', 'smtp-mail.outlook.com' ),
			'hotmail.co.uk'  => array( 'smtp.office365.com', 'smtp-mail.outlook.com' ),
			'live.com'       => array( 'smtp.office365.com', 'smtp-mail.outlook.com' ),
			'msn.com'        => array( 'smtp.office365.com', 'smtp-mail.outlook.com' ),
			'icloud.com'     => array( 'smtp.mail.me.com' ),
			'me.com'         => array( 'smtp.mail.me.com' ),
			'mac.com'        => array( 'smtp.mail.me.com' ),
			'gmail.com'      => array( 'smtp.gmail.com', 'smtp-relay.gmail.com', 'smtp.googlemail.com' ),
			'googlemail.com' => array( 'smtp.gmail.com', 'smtp.googlemail.com' ),
			'proton.me'      => array( 'smtp.protonmail.ch' ),
			'protonmail.com' => array( 'smtp.protonmail.ch' ),
			'pm.me'          => array( 'smtp.protonmail.ch' ),
		);

		/**
		 * Whether an SMTP host is the mailbox provider's own — i.e. the one
		 * party that *can* authenticate mail for that address.
		 *
		 * Sending as `you@gmail.com` through `smtp.gmail.com` is a fully
		 * authenticated, aligned send: Gmail signs it. Without this, the single
		 * most common working setup on the planet reads as a hard failure.
		 *
		 * @param string $domain Mailbox-provider domain, e.g. 'gmail.com'.
		 * @param string $host   SMTP host currently configured.
		 * @return bool
		 */
		public static function is_provider_smtp_host( $domain, $host ) {
			$domain = strtolower( trim( (string) $domain ) );
			$host   = strtolower( trim( (string) $host ) );

			if ( '' === $domain || '' === $host ) {
				return false;
			}

			if ( isset( self::$provider_smtp_hosts[ $domain ] )
				&& in_array( $host, self::$provider_smtp_hosts[ $domain ], true ) ) {
				return true;
			}

			// Covers the providers that do serve from their own domain, e.g.
			// smtp.mail.yahoo.com for yahoo.com.
			return self::domains_align( $host, $domain );
		}

		/**
		 * Everything published about a domain, in one pass.
		 *
		 * Returns:
		 *  - resolvable  bool   Whether DNS answered at all.
		 *  - has_mx      bool
		 *  - spf         array  { found, record, qualifier } qualifier is -all/~all/?all/+all/''.
		 *  - dmarc       array  { found, record, policy } policy is reject/quarantine/none/''.
		 *
		 * A failed lookup is reported as `resolvable => false` and never as
		 * "no record": telling someone their SPF is missing when the truth is
		 * that DNS is unreachable sends them off to fix the wrong thing.
		 *
		 * @param string $domain Domain to inspect.
		 * @return array
		 */
		public static function inspect( $domain ) {
			$domain = strtolower( trim( (string) $domain ) );

			if ( '' === $domain ) {
				return self::unresolvable();
			}

			if ( isset( self::$lookups[ $domain ] ) ) {
				return self::$lookups[ $domain ];
			}

			// RFC 2606 reserves these precisely so they never resolve. Asking
			// anyway just buys a timeout per lookup on every local dev site.
			if ( self::is_local_domain( $domain ) ) {
				self::$lookups[ $domain ] = self::unresolvable();

				return self::$lookups[ $domain ];
			}

			if ( ! function_exists( 'dns_get_record' ) || ! function_exists( 'checkdnsrr' ) ) {
				self::$lookups[ $domain ] = self::unresolvable();

				return self::$lookups[ $domain ];
			}

			// Establish the domain exists before asking it anything else: a name
			// that doesn't resolve answers every query with a full timeout, and
			// a mistyped domain is exactly when that happens. Asking for both
			// types in one call costs one timeout rather than two, and the A
			// record proves the resolver is working even for a domain that
			// publishes no mail records at all.
			$existence = @dns_get_record( $domain, DNS_MX | DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( empty( $existence ) ) {
				self::$lookups[ $domain ] = self::unresolvable();

				return self::$lookups[ $domain ];
			}

			$has_mx = false;

			foreach ( (array) $existence as $record ) {
				if ( isset( $record['type'] ) && 'MX' === $record['type'] ) {
					$has_mx = true;
					break;
				}
			}

			$resolvable = true;
			$root_txt   = @dns_get_record( $domain, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$dmarc_txt  = @dns_get_record( '_dmarc.' . $domain, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$result = array(
				'resolvable' => $resolvable,
				'has_mx'     => $has_mx,
				'spf'        => self::parse_spf( $root_txt ),
				'dmarc'      => self::parse_dmarc( $dmarc_txt ),
			);

			self::$lookups[ $domain ] = $result;

			return $result;
		}

		/**
		 * Shape returned when DNS can't be consulted.
		 *
		 * @return array
		 */
		private static function unresolvable() {
			return array(
				'resolvable' => false,
				'has_mx'     => false,
				'spf'        => array(
					'found'      => false,
					'record'     => '',
					'qualifier'  => '',
					'delegates'  => array(),
					'authorises' => false,
				),
				'dmarc'      => array(
					'found'  => false,
					'record' => '',
					'policy' => '',
				),
			);
		}

		/**
		 * Pull the SPF record out of a domain's TXT records and read how strict
		 * its `all` mechanism is. `-all` tells receivers to reject anything not
		 * listed; `~all` asks them to accept but mark it.
		 *
		 * @param mixed $txt_records Result of dns_get_record().
		 * @return array { found, record, qualifier }
		 */
		private static function parse_spf( $txt_records ) {
			$record = self::find_txt( $txt_records, 'v=spf1' );

			if ( '' === $record ) {
				return array(
					'found'      => false,
					'record'     => '',
					'qualifier'  => '',
					'delegates'  => array(),
					'authorises' => false,
				);
			}

			$qualifier = '';

			if ( preg_match( '/([-~?+])all\b/i', $record, $matches ) ) {
				$qualifier = $matches[1] . 'all';
			}

			preg_match_all( '/(?:include:|redirect=)([^\s]+)/i', $record, $delegates );

			// Whether the record names any sender at all. `v=spf1 -all` is a
			// valid, deliberate record that authorises *nobody* — so a domain
			// publishing it can be reported as unsendable with certainty, not
			// as a guess.
			$authorises = (bool) preg_match( '/\b(?:include:|redirect=|ip4:|ip6:|exists:|ptr\b|a[:\s]|a$|mx[:\s]|mx$)/i', $record );

			return array(
				'found'      => true,
				'record'     => $record,
				'qualifier'  => $qualifier,
				'delegates'  => array_map( 'strtolower', $delegates[1] ),
				'authorises' => $authorises,
			);
		}

		/**
		 * Read the published DMARC policy — what receivers should do when a
		 * message fails authentication.
		 *
		 * @param mixed $txt_records Result of dns_get_record().
		 * @return array { found, record, policy }
		 */
		private static function parse_dmarc( $txt_records ) {
			$record = self::find_txt( $txt_records, 'v=DMARC1' );

			if ( '' === $record ) {
				return array(
					'found'  => false,
					'record' => '',
					'policy' => '',
				);
			}

			$policy = '';

			if ( preg_match( '/\bp\s*=\s*(none|quarantine|reject)\b/i', $record, $matches ) ) {
				$policy = strtolower( $matches[1] );
			}

			return array(
				'found'  => true,
				'record' => $record,
				'policy' => $policy,
			);
		}

		/**
		 * First TXT record starting with a given prefix. Long records arrive
		 * split into 255-character chunks, so the `entries` array is joined
		 * before matching.
		 *
		 * @param mixed  $txt_records Result of dns_get_record().
		 * @param string $prefix      Prefix to match, e.g. 'v=spf1'.
		 * @return string
		 */
		private static function find_txt( $txt_records, $prefix ) {
			if ( ! is_array( $txt_records ) ) {
				return '';
			}

			foreach ( $txt_records as $record ) {
				$value = '';

				if ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
					$value = implode( '', $record['entries'] );
				} elseif ( isset( $record['txt'] ) ) {
					$value = $record['txt'];
				}

				if ( '' !== $value && 0 === stripos( trim( $value ), $prefix ) ) {
					return trim( $value );
				}
			}

			return '';
		}

		/**
		 * Domain part of an email address, lowercased.
		 *
		 * @param string $address Email address.
		 * @return string
		 */
		public static function domain_of( $address ) {
			$at_pos = strrpos( (string) $address, '@' );

			return false === $at_pos ? '' : strtolower( substr( $address, $at_pos + 1 ) );
		}

		/**
		 * Whether a domain's SPF record authorises a given sending host.
		 *
		 * Answers with certainty where certainty exists and says so otherwise:
		 *
		 *  - 'no'      the record names no sender at all (`v=spf1 -all`), so
		 *              nothing on earth passes SPF for it; or the host matches
		 *              nothing and the record delegates only to named services.
		 *  - 'yes'     the host belongs to one of the services it delegates to.
		 *  - 'unknown' no record to check, no host to check, or the record
		 *              authorises raw IP ranges we can't attribute to a host.
		 *
		 * Cheap despite the recursion: `include:` targets are real domains, so
		 * every lookup is a cache hit. Measured at ~0.1s for a two-level chain.
		 * Bounded anyway, since SPF itself caps evaluation at ten lookups.
		 *
		 * @param string $domain Sending domain.
		 * @param string $host   SMTP host in use, if known.
		 * @return string 'yes' | 'no' | 'unknown'
		 */
		public static function spf_covers_host( $domain, $host ) {
			$spf = self::inspect( $domain )['spf'];

			if ( empty( $spf['found'] ) ) {
				return 'unknown';
			}

			// A record that names nobody is decisive on its own — no host to
			// compare is needed, because none would match.
			if ( empty( $spf['authorises'] ) ) {
				return 'no';
			}

			$host = strtolower( trim( (string) $host ) );

			if ( '' === $host ) {
				return 'unknown';
			}

			$seen     = array();
			$pending  = $spf['delegates'];
			$budget   = self::SPF_LOOKUP_LIMIT;
			$has_ips  = self::spf_has_ip_mechanism( $spf['record'] );

			while ( $pending && $budget > 0 ) {
				$target = array_shift( $pending );

				if ( '' === $target || isset( $seen[ $target ] ) ) {
					continue;
				}

				$seen[ $target ] = true;
				--$budget;

				if ( self::domains_align( $host, $target ) ) {
					return 'yes';
				}

				// Only the SPF record — inspect() would also fire MX and
				// _dmarc lookups on every delegate, and those miss, at ten
				// seconds each.
				$nested = self::spf_record_of( $target );

				if ( '' === $nested ) {
					continue;
				}

				if ( self::spf_has_ip_mechanism( $nested ) ) {
					$has_ips = true;
				}

				if ( preg_match_all( '/(?:include:|redirect=)([^\s]+)/i', $nested, $more ) ) {
					$pending = array_merge( $pending, array_map( 'strtolower', $more[1] ) );
				}
			}

			// Raw IP ranges anywhere in the chain can't be matched against a
			// hostname — a provider rarely sends from the address it listens
			// on. Say so rather than report a failure we haven't earned.
			return $has_ips ? 'unknown' : 'no';
		}

		/**
		 * The SPF record of a domain, with no other lookups attached.
		 *
		 * @param string $domain Domain.
		 * @return string Empty when none is published.
		 */
		private static function spf_record_of( $domain ) {
			if ( ! function_exists( 'dns_get_record' ) ) {
				return '';
			}

			return self::find_txt( @dns_get_record( $domain, DNS_TXT ), 'v=spf1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		/**
		 * Whether an SPF record authorises senders by address rather than name.
		 *
		 * @param string $record SPF record.
		 * @return bool
		 */
		private static function spf_has_ip_mechanism( $record ) {
			return (bool) preg_match( '/\b(?:ip4:|ip6:|exists:|ptr\b|a[:\s]|mx[:\s])/i', (string) $record );
		}

		/**
		 * Registrable-ish comparison between two domains, ignoring a leading
		 * `www.` and matching a subdomain against its parent — `mail.site.com`
		 * and `site.com` are the same organisation for alignment purposes.
		 *
		 * @param string $one Domain.
		 * @param string $two Domain.
		 * @return bool
		 */
		public static function domains_align( $one, $two ) {
			$one = preg_replace( '/^www\./i', '', strtolower( (string) $one ) );
			$two = preg_replace( '/^www\./i', '', strtolower( (string) $two ) );

			if ( '' === $one || '' === $two ) {
				return false;
			}

			if ( $one === $two ) {
				return true;
			}

			return (bool) preg_match( '/\.' . preg_quote( $two, '/' ) . '$/i', $one )
				|| (bool) preg_match( '/\.' . preg_quote( $one, '/' ) . '$/i', $two );
		}
	}

endif;
