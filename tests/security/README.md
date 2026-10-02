# Isolated security regressions

Run a case from the plugin root with `php tests/security/<case>.php`.
The smart-tag case additionally needs an unpacked WordPress core directory:
`php tests/security/smart-tags.php /path/to/wordpress`.
No WordPress site or database is booted. Upload tests require SimpleXML and write
only their own temporary fixture directory. Tests fail on unexpected PHP warnings.

The matching `tests/e2e/specs/security-*.spec.ts` files execute the same assertions
through the existing Playwright runner. Run them with:

```sh
pnpm exec playwright test --grep 'isolated PHP'
```

PHP must be on PATH; `UR_SECURITY_PHP_BINARY` can select a specific executable.
Set `UR_SECURITY_WP_ROOT` to use a local WordPress core checkout. Without it, the
smart-tag spec downloads WordPress 6.8.3 from wordpress.org into a fresh temporary
directory, verifies its pinned SHA-256 before extraction, and removes the fixture
when finished. The suite exercises the real shortcode parser and formatting code.

These are handler regressions with explicit WordPress test doubles, not browser
journeys or full plugin integration coverage. Targeted PHPCS exceptions in these
fixtures cover those doubles, intentionally hostile inputs, and CLI/file I/O;
production code continues to use the repository coding standard.
