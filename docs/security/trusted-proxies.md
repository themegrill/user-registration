# Trusted reverse proxies for client IP detection

`ur_get_ip_address()` ignores `X-Forwarded-For` and `X-Real-IP` by default and
returns `REMOTE_ADDR`, so visitors cannot spoof their IP address by sending
forged headers.

If your site sits behind Cloudflare, a load balancer, or a reverse proxy (such
as nginx or AWS ALB), `REMOTE_ADDR` is the proxy's address rather than the
visitor's. Without configuring trusted proxies:

- `ur_user_ip` and the `{{user_ip_address}}` smart tag record the proxy's IP.
- IP-based rate limits (such as coupon validation and opt-in login rate
  limiting) treat all visitors as a single shared bucket.
- Add-on IP and country checks see the proxy instead of the visitor.

Configure your proxy IPs or CIDR ranges using the
`user_registration_trusted_proxies` filter (exact IPv4/IPv6 addresses and
IPv4/IPv6 CIDR ranges are supported):

```php
add_filter(
	'user_registration_trusted_proxies',
	static function () {
		// Example: Cloudflare IPv4/IPv6 ranges (check https://www.cloudflare.com/ips/ for current ranges).
		return array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);
	}
);
```

When `REMOTE_ADDR` matches an entry in `user_registration_trusted_proxies`,
`ur_get_ip_address()` walks `X-Forwarded-For` from right to left, skipping
trusted proxy hops, and returns the first untrusted valid IP. If every hop in
`X-Forwarded-For` is trusted or any hop is invalid, it falls back to
`REMOTE_ADDR`. `X-Real-IP` is only consulted when `X-Forwarded-For` is absent.

Regression test: `php tests/security/trusted-proxy-ip.php`.
