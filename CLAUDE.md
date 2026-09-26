# User Registration & Membership (free plugin)

WordPress plugin `user-registration` (text domain `user-registration`): registration/login form builder, membership & subscriptions (Stripe, PayPal), content restriction. Written from this repo's code and config; not every statement has been exercised end to end. If the code disagrees, trust the code and fix this file.

**Ecosystem (read `.claude/skills/ur-architecture/reference/ecosystem.md` before touching hooks, helpers or shared behaviour).** This is only the free plugin. **Pro (`themegrill/user-registration-pro`) is a full standalone plugin, not an add-on:** it replaces free at runtime, sets `UR_PRO_ACTIVE` to `true`, and holds a synced copy of this repo's code, so free code also runs inside Pro. About 38 separate add-on plugins (`themegrill/user-registration-<name>`: advanced fields, conditional logic, 2FA, Stripe, Mailchimp, social connect, ...) attach to the core through hooks and `ur_*` helpers, and most require Pro. None of that code is in this checkout, so their use of what you change is invisible unless you check it or say you could not.

**This repo feeds Pro.** On every push to `develop`, `.github/workflows/sync.yml` copies `includes/`, `modules/`, `src/`, `templates/`, `assets/` (minus one Divi file), `tests/e2e`, `playwright.config.ts`, `phpcs.xml` and a few other root files (`.github/sync-file-list.yml`) into `themegrill/user-registration-pro`, branch `release/develop`. Treat every change there as also shipping to Pro.

## Compatibility floor
- **PHP 7.4** (`readme.txt`, phpcs `testVersion 7.4-`, CI sniffs on 7.4) and **WP 5.5** (`readme.txt`). No union types, `match`, nullsafe `?->`, named arguments, enums or constructor promotion.
- `CONTRIBUTING.md` ("PHP 5.4+") and `composer.json` (`php >=5.6.20`) disagree with that; 7.4 is the enforced value.

## Layout
- `user-registration.php` bootstraps. `includes/` is core: `class-ur-foo-bar.php` autoloads as `UR_Foo_Bar` (`includes/class-ur-autoloader.php`). Namespaced code (membership, analytics, content drip) autoloads through Composer PSR-4 (`composer.json`), so `vendor/` must exist.
- `modules/`: `membership/` (plans, subscriptions, orders, coupons, gateway services and webhooks), `content-restriction/`, `content-drip/`, `payment-history/`, `stripe/`, `paypal/`, `masteriyo/`. Feature toggles live in the `user_registration_enabled_features` option (`ur_check_module_activation()`); `user-registration.php` loads modules conditionally.
- `templates/` are theme-overridable front-end templates (`ur_get_template()`). `src/` is React/TS (webpack via `wp-scripts`). `assets/` is legacy jQuery JS + SCSS (grunt).
- `tests/e2e/` (Playwright) is the only automated test suite. **`tests/phpunit/` does not exist**, although `phpunit.xml.dist` and `pnpm test-php` reference it. Do not claim PHP unit tests passed.
- `.themegrill-qa/knowledge.md` is maintainer-reviewed, evidence-backed product knowledge (login flows, admin surfaces, fragile areas). Two sections ("Known non-issues", "Upgrade - what must survive") are still TODO. Read it before changing behaviour it describes.

## Commands
| Need | Command |
|---|---|
| Install | `composer install`, `pnpm install` (pnpm 10.30.3, Node 22) |
| PHP standards | `php vendor/bin/phpcs -s <files>` (`composer phpcs` = whole repo) |
| JS/TS/SCSS | `pnpm prettier`, `pnpm typecheck` |
| Regenerate legacy assets | `pnpm exec grunt js` / `pnpm exec grunt css` (outputs are committed) |
| Build for local testing | `pnpm build:no-makepot` (`pnpm build` runs makepot via WP-CLI `wp i18n make-pot` and rewrites the `.pot`) |
| e2e | `pnpm test:e2e` (needs a live site; reads `TGQA_BASE_URL`, `TGQA_ADMIN_USER`, `TGQA_ADMIN_PASS` from the environment or `.themegrill-qa/.env.local`, with local-only defaults in `tests/e2e/support/env.ts` when unset; it changes plugin settings, so ask first). Tiers are title tags, not projects: `pnpm test:e2e:fresh` (`--grep @fresh`, clean install only), `pnpm test:e2e:demo`. One spec: `pnpm exec playwright test tests/e2e/specs/<file>` (add `-g "<title>"` for one test) |
| Dev build / watch | `pnpm start` (`wp-scripts start`); `build:pro` / `start:pro` set `UR_PRO=true` |
| Pick checks for a diff | `/ur-check` |

## Git and PRs
- Branch from `develop` and open PRs against `develop`. `master` is the release branch (`draft-release.yml`). `CONTRIBUTING.md` says `master` for outside contributors.
- Reference the issue number in commits; follow `.github/PULL_REQUEST_TEMPLATE.md` (`Closes #N`, how to test).
- Do not edit `CHANGELOG.txt` or `languages/*.pot` (release team, per `CONTRIBUTING.md`).
- A bug fix should carry a `@fresh` Playwright spec in `tests/e2e/specs`; the header of `.github/workflows/qa-suite.yml` says the spec is committed with the fix. Whether CI blocks a PR without one is not verified.

## Where the rest lives
Path-scoped rules in `.claude/rules/` load automatically when Claude reads a matching file (PHP, front-end assets, e2e, membership/payments, content restriction). Deep reference is in the `ur-architecture` skill. Prefer adding to those over growing this file; keep it short.

## Rules that always apply
1. **Understand before editing.** For anything beyond a one-line change, load the `ur-architecture` skill and trace the real call path and every caller first.
2. **Public contracts are promises to Pro, the add-ons and third parties:** `user_registration_*`, `ur_membership_*`, `urcr_*`, `urm_*` hooks; `ur_*` functions and `UR_*` classes; option, post-meta and user-meta keys; DB tables; `user_registration_*` AJAX actions; REST namespaces `user-registration/v1` and `user-registration` (webhooks); shortcodes; template names and `$args`. Do not rename, remove or re-sign them, and do not delete a hook, function, option or table because free has no caller for it: Pro or an add-on may implement or use it. Add parameters at the end, and deprecate through `ur_deprecated_function/hook/argument()` and `ur_do_deprecated_action()`.
3. **Security-sensitive code needs extra care and a cold review:** payments and webhooks, membership roles and access, content restriction, login/registration flow, redirects, tokens, anything reading `$_GET/$_POST/$_REQUEST`. See `.claude/rules/php.md` and `ur-architecture/reference/security.md`, then run the `ur-reviewer` agent.
4. **Schema and stored data:** changes ship as a versioned migration registered in `includes/class-ur-install.php` (`$db_updates` or the option-migration list; see `ur-architecture/reference/architecture.md`), never destructive, never silently reinterpreting stored values.
5. **Generated, vendored and secret files are off limits** (an edit hook enforces it for the Edit and Write tools; shell commands are not covered): `vendor/`, `node_modules/`, `chunks/`, `build/`, `*.min.js`, compiled CSS and `-rtl.css`, `languages/`, `.themegrill-qa/.env.local`.
6. **Stay in scope**, and report checks you could not run (no PHPUnit, e2e needs a live site) instead of implying they passed.
