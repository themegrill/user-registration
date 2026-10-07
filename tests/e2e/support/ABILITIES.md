# Read-only abilities integration test

Use a **disposable** WordPress 6.9+ installation with User Registration (Free or Pro)
and the official WordPress MCP Adapter 0.6.1 active. The PHP test creates and removes
its users/forms, creates membership tables if needed, and restores enabled modules.
It does not reset a database. Never point this test at a production installation.

```sh
UR_ABILITIES_WP_PATH=/path/to/disposable/wordpress npx playwright test abilities.spec.ts
```

Or run the same assertions without Playwright:

```sh
UR_ABILITIES_DISPOSABLE=1 wp eval-file tests/e2e/support/abilities-integration.php --path=/path/to/disposable/wordpress
```

The Playwright test skips unless the explicit disposable path is configured.
It uses real WP_Ability schemas/permissions, REST routing and MCP adapter abilities,
with real users, forms and subscription rows. The scan-limit assertion overrides
only WordPress's total-count query to avoid creating ten thousand accounts.
It does not test HTTP transport authentication or a particular external AI client.

For older WordPress, activate the plugin in a separate disposable installation
without MCP Adapter and verify that it boots without the native abilities API.
