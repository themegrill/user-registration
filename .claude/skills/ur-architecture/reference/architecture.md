# Architecture overview

Start here, then open the detailed file for your area. Anchors were read from this repo; line numbers drift, so search for the symbol. **Facts about flags, migrations and options depend on the branch you are on**: in-flight branches add their own (check `git branch -r` and the tree in front of you before trusting a name from memory or from another branch).

| Area | Detail |
|---|---|
| Registration, role assignment, login options, login gate, user state | [registration-and-auth.md](registration-and-auth.md) |
| Membership data model, orders and subscriptions, Stripe, PayPal, cron, events | [membership-payments.md](membership-payments.md) |
| Access rules, the authorisation function, enforcement channels | [content-restriction.md](content-restriction.md) |
| Forms, fields, settings, emails, logging, templates, blocks, integrations | [subsystems.md](subsystems.md) |
| Pro, add-ons, who consumes what | [ecosystem.md](ecosystem.md) |
| Hooks, options, tables and other public contracts | [extension-points.md](extension-points.md) |
| Trust boundaries and past incidents | [security.md](security.md) |

## Bootstrap and loading
- `user-registration.php` defines the final class `UserRegistration` (`UR()`), constants such as `UR_VERSION`, `UR_LOG_DIR` and `UR_PRO_ACTIVE` (free defines it `false`; Pro's own bootstrap defines it `true`), then includes core files and modules.
- `includes/class-ur-autoloader.php` maps `UR_Foo_Bar` to `class-ur-foo-bar.php` under `includes/` (with subfolder rules for some prefixes such as `UR_Shortcode_*`).
- Namespaced code loads through Composer PSR-4 (`composer.json`): `WPEverest\URMembership\` -> `modules/membership/includes/`, `...\Payment\` -> `modules/payment-history/`, `...\URM\Analytics\`, `...\URM\ContentDrip\`, `...\URM\Masteriyo\`, `...\URM\DiviBuilder\`. Without `vendor/`, these classes do not exist.
- Modules are toggled by the `user_registration_enabled_features` option and `ur_check_module_activation( '<slug>' )`, which checks for `user-registration-<slug>` in that array (`modules/functions-ur-modules.php`). `user-registration.php` includes membership only when its feature is on, payment-history when membership or payments is on, content-drip only when membership, content-restriction and content-drip are all on, and content restriction unconditionally. Slugs seen in code are listed in `membership-payments.md`.

## Stored data and migrations
- Options: `user_registration_*` (by far the most used), `urm_*`, `urcr_*`, `ur_*`. Sessions table: `{prefix}user_registration_sessions` (`includes/class-ur-session-handler.php`). Membership tables: see `membership-payments.md`.
- `UR_Install` (`includes/class-ur-install.php`) sets first-install flags on activation, including `urm_is_new_installation` (used to pick defaults, for example in `class-ur-settings-payment.php`) and `urm_is_legacy_paypal_user` (set when old global PayPal settings exist).
- Two upgrade mechanisms live in `class-ur-install.php`: `UR_Install::$db_updates` (version -> `ur_update_*` callbacks, implemented in `includes/functions-ur-update.php`) and a separate option-migration list compared against the `user_registration_migration_version` option (for example `urm_update_50_option_migrate`, `ur_update_515_redirect_thank_you_page_migrate`). Sites running Pro get a different migration list (`UR_PRO_ACTIVE` branch), so a migration you add can interact with Pro's. A new migration must be idempotent, must not destroy data, and must not reinterpret existing values silently. Register it in the right list; never gate it with an ad-hoc `if` in a request path. Membership tables are created with `CREATE TABLE IF NOT EXISTS` (not `dbDelta`), so altering them always needs an explicit migration.

## Things that surprise newcomers
- `includes/RestApi/` and `includes/log-handlers/RestApi/` both contain REST class trees. `user-registration.php` includes `includes/RestApi/class-ur-rest-api.php`; confirm which one your route loads through before editing.
- `includes/functions-ur-deprecated.php` is excluded from phpcs and its docblocks still say EverestForms. It is the deprecation toolbox, not dead code.
- Read how registration submit handles its nonce before changing it (see `registration-and-auth.md`).
- Content-restriction edge cases (non-post targets, non-evaluable rules, empty role lists) are deliberate behaviours; read `content-restriction.md` before changing them.
- A hook, function or table with no user in free may be implemented in Pro (see `ecosystem.md`).
