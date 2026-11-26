# Login rate limiting

`UR_Login_Rate_Limiter` adds opt-in failed-login rate limiting to the plugin's
login form (`/login/`) and AJAX login (`user_registration_ajax_login_submit`).
It is **disabled by default** so existing sites see no behaviour change on
upgrade.

## Important: configure trusted proxies before enabling

If your site sits behind Cloudflare, a load balancer, or a reverse proxy (such
as nginx or AWS ALB), **configure your trusted proxy IPs before turning login
rate limiting on**. Without trusted proxies configured, every visitor resolves
to the proxy's `REMOTE_ADDR` and shares a single 50-failure per-IP bucket,
which allows one attacker to lock out all logins on the site for up to 24
hours.

Configure trusted proxies first (see `docs/security/trusted-proxies.md` once
`user_registration_trusted_proxies` is active, or
`user_registration_login_trusted_proxies` on this branch), verify the resolved
client IP, and then enable the limiter:

```php
add_filter( 'user_registration_login_rate_limit_enabled', '__return_true' );
```

## Defaults and limits

- **Per `(account, IP)` pair:** 5 failed attempts within 15 minutes.
- **Per IP:** 50 failed attempts within 15 minutes.
- **Lockout escalation:** 15 minutes, 30 minutes, 60 minutes, doubling up to
  24 hours.
- **Scope:** Covers `ur_process_login()` (plugin login form and AJAX login).
  `wp-login.php`, XML-RPC, lost password, and REST application passwords are
  out of scope.

Regression test: `php tests/security/login-rate-limit.php`.
