# User Registration & Membership — QA knowledge

> **REVIEWED.** Accepted by the maintainer (iamprazol) on 2026-09-04.
> First drafted by `claudegrill:knowledge-init` from source, the ingested docs
> and git history, then corrected against `themegrill/UR-Automation` and against
> a live site while porting the Playwright suite. Every fact below carries its
> evidence.
>
> Reviewed does not mean complete. Two sections remain open and are marked
> **TODO** — *Known non-issues* and *Upgrade — what must survive*. They are open
> because they need product decisions nobody has made yet, not because nobody
> has looked at them.

- Product: User Registration & Membership 5.2.7 (plugin, `user-registration`)
- Text domain: `user-registration` · branch at draft time: `add/themegrill-qa`
- Docs: https://docs.wpuserregistration.com/ — 185 articles in 19 sections
  ingested into `.themegrill-qa/docs/`, index at `.themegrill-qa/docs-index.json`
- A pro edition exists (`has_pro: true`), and `user-registration-pro` 6.2.7 is
  installed alongside this checkout in the same site. **This file describes the
  free plugin.** Many docs articles are pro-only and are marked as such in their
  own text — do not treat a pro-only doc as a free-plugin regression.

## What the product is

A registration/login/membership plugin with four largely independent halves that
fail differently and should be kept separate in QA:

1. **Forms** — a drag-and-drop form builder writing to the `user_registration`
   CPT (`includes/class-ur-post-types.php:46`), rendered on the front end by
   shortcode. 22 core field types under `includes/form/`
   (`class-ur-form-field-*.php`): text, textarea, number, email, password,
   date, select, radio, checkbox, country, privacy-policy, description, plus
   the WordPress-mapped ones (`user_login`, `user_pass`, `user_email`,
   `user_confirm_email`, `user_confirm_password`, `user_url`, `first_name`,
   `last_name`, `nickname`, `display_name`).
2. **Registration/login flow** — the login option chosen per form decides what
   happens after submit. Five values, all present in source:
   `auto_login`, `email_confirmation`, `admin_approval`,
   `admin_approval_after_email_confirmation`, `payment`. The
   fourth is a compound of two others and, on the evidence of git history
   below, the one that breaks most.
3. **Membership** (`modules/membership/`) — plans, groups, subscriptions,
   orders, coupons and recurring billing over PayPal and Stripe. Two CPTs
   (`ur_membership`, `ur_membership_groups`, `modules/membership/includes/Admin.php:888`
   and `:938`) and four custom tables.
4. **Content restriction** (`modules/content-restriction/`) — rules CPT
   (`modules/content-restriction/class-urcr-post-types.php:48`) gating posts,
   pages and whole-site access by role or membership.

Modules are opt-in and stored in one option: `user_registration_enabled_features`
(`includes/functions-ur-core.php:10803`, toggled at
`includes/class-ur-ajax.php:2812`). Known slugs are
`user-registration-membership`, `user-registration-content-restriction` and
`user-registration-payment-history`. **A spec that assumes membership is on will
fail on a clean install** — enable the feature first, or assert the disabled
state deliberately.

## Admin surfaces

Top-level menu slug `user-registration`, registered at
`includes/admin/class-ur-admin-menus.php:507`. Unlike a React-SPA plugin these
are real page loads (`admin.php?page=…`), so ordinary navigation waits work.

| Page slug | Screen | Registered at |
|---|---|---|
| `user-registration` | All Forms (CPT list) | `class-ur-admin-menus.php:507` |
| `user-registration-dashboard` | Dashboard | `class-ur-admin-menus.php` |
| `user-registration-settings` | Settings (tabbed) | `class-ur-admin-menus.php` |
| `user-registration-users` | Users | `includes/admin/settings/class-ur-users-menu.php:326` |
| `user-registration-members` | Members | `includes/admin/settings/class-ur-members-menu.php:914` |
| `user-registration-membership` | Membership plans | `modules/membership/includes/Admin/Membership/Membership.php:366` |
| `user-registration-subscriptions` | Subscriptions | `modules/membership/includes/Admin/Subscriptions/Subscriptions.php:55` |
| `user-registration-content-restriction` | Content Rules | `modules/content-restriction/admin/class-urcr-admin.php:144` |
| `user-registration-login-forms` | Login Forms | `class-ur-admin-menus.php` |
| `user-registration-analytics` | Analytics | `includes/Analytics/Analytics.php:48` |
| `user-registration-status` | Status | `class-ur-admin-menus.php` |
| `user-registration-welcome` | Welcome/onboarding | `includes/admin/class-ur-admin-welcome.php:41` |

Several of these are **conditionally registered** — recent fix commits
deliberately hide Payments, Subscriptions and Content Restriction when there is
nothing to show (`82aaaa0a`, `3d16f6cc`). A spec asserting a menu item exists
must first create the thing that makes it appear.

Settings tabs live in `includes/admin/settings/class-ur-settings-*.php`:
general, registration-login, my-account, email, captcha, payment, membership,
security, integration, import-export, advanced, license, misc.

## Front-end surfaces — shortcodes

Registered in a loop at `includes/class-ur-shortcodes.php:43`, each tag passing
through a `{$shortcode}_shortcode_tag` filter, so **the tag is themable and a
site may not use the default**:

`user_registration_form`, `user_registration_my_account`,
`user_registration_login`, `user_registration_lost_password`,
`user_registration_reset_password_form`, `user_registration_edit_profile`,
`user_registration_edit_password`.

Plus `urcr_restrict` (`modules/content-restriction/class-urcr-shortcodes.php:26`)
and `urm-content-restriction` for Divi
(`includes/3rd-party/DiviBuilder/Builder.php:108`).

My Account endpoints are options, not hardcoded paths —
`user_registration_myaccount_{edit_profile,edit_account,change_password,lost_password}_endpoint`.
**Never hardcode `/my-account/edit-profile/` in a spec**; read the option.

## Persistence — what to assert against after a save

Page-ID options, the ones most flows depend on:

| Option | Holds |
|---|---|
| `user_registration_myaccount_page_id` | My Account page |
| `user_registration_login_page_id` | Login page |
| `user_registration_registration_page_id` | Registration page |
| `user_registration_member_registration_page_id` | Membership registration page |
| `user_registration_lost_password_page_id` | Lost password page |
| `user_registration_thank_you_page_id` | Post-payment thank-you page |
| `user_registration_default_form_page_id` | Default form page |

Behaviour options worth asserting on:

| Option | Notes |
|---|---|
| `user_registration_enabled_features` | array of module slugs; gates whole menus |
| `user_registration_general_setting_login_options` | the five-value login option above |
| `user_registration_general_setting_redirect_options` | post-registration redirect |
| `user_registration_login_options_login_redirect_url` | post-login redirect |
| `user_registration_form_setting_default_user_role` | role assigned on registration |
| `user_registration_content_restriction_enable` | master switch for restriction |
| `user_registration_content_restriction_message` | fallback restriction message |
| `user_registration_content_restriction_allow_access_to` / `_allow_to_roles` / `_allow_to_memberships` / `_whole_site_access` | rule scope |
| `user_registration_captcha_setting_recaptcha_version` + the `_site_key` / `_site_secret` family (v2, invisible, v3, hCaptcha, Cloudflare) | captcha config |
| `user_registration_payment_currency`, `user_registration_paypal_enabled`, `user_registration_stripe_enabled`, `user_registration_global_paypal_*`, `user_registration_global_bank_details`, `ur_membership_payment_gateways` | payments |
| `user_registration_renewal_behaviour` | recurring behaviour; hidden when no plans exist (`89ae38ca`) |
| `user_registration_email_from_name`, `user_registration_email_from_address` | email identity |
| `user_registration_version`, `user_registration_db_version`, `user_registration_migration_version` | drive the upgrade routines below |
| `user-registration_license_key` | licence (note the **hyphen** — inconsistent with every other key) |
| `urm_is_new_installation`, `user_registration_onboarding_skipped`, `user_registration_first_time_activation_flag`, `urm_onboarding_membership_type`, `urm_onboarding_membership_ids` | onboarding state; **these change first-run behaviour, so a "fresh install" spec must control them** |

Custom tables (`includes/class-ur-install.php:588`,
`modules/membership/includes/Admin/Database/Database.php:70`ff):
`{prefix}user_registration_sessions`, `{prefix}ur_membership_subscriptions`,
`{prefix}ur_membership_orders`, `{prefix}ur_membership_ordermeta`,
`{prefix}ur_membership_subscription_events`.

## REST API — namespace `user-registration/v1`

Controllers under `includes/RestApi/controllers/version1/`:
`class-ur-gutenberg-blocks.php` (10 routes), `class-ur-getting-started.php` (8),
`class-ur-modules.php` (6), `class-ur-form-templates.php` (4),
`class-ur-plugin-status.php` (4), `class-ur-changelog.php` (1), plus
`includes/Analytics/Controllers/V1/AnalyticsController.php` (1),
`modules/content-restriction/.../class-urcr-content-access-rules.php` (7) and
two payment-webhook routes at
`modules/membership/includes/Admin/Services/PaymentGatewaysWebhookActions.php`.

## AJAX — the unauthenticated surface

All handlers are registered in one loop, `includes/class-ur-ajax.php:106`, as
`wp_ajax_user_registration_{$event}` with the map value deciding whether a
`nopriv_` twin is also added. **Seven handlers are reachable without login** —
these are the plugin's real attack surface and the place a capability or nonce
regression matters most (`includes/class-ur-ajax.php:56`ff):

`user_input_dropped`, `user_form_submit`, `get_recent_nonce`,
`update_profile_details`, `profile_pic_upload`, `ajax_login_submit`,
`update_state_field`.

`get_recent_nonce` being `nopriv` is worth a maintainer's eye: it hands a nonce
to an unauthenticated caller by design, so every other `nopriv` handler's nonce
check is a freshness check, not an authorisation one. TODO: confirm this is
intended.

Other module AJAX prefixes: `urcr_*` (content rules —
`urcr_create_content_rules`, `urcr_get_membership_rule`,
`urcr_update_rule_status`), `user_registration_membership_*`, `urm_*`.

## Capability boundaries

Counted over `includes/` and `modules/`:

| Capability | Uses | Guards |
|---|---|---|
| `manage_options` | 83 | settings, most admin screens |
| `edit_user` / `edit_users` | 20 / 12 | profile edits, Users/Members screens |
| `manage_user_registration` | 16 | the plugin's own core capability |
| `promote_users`, `list_users`, `delete_users`, `remove_users` | 7–12 | user management |
| `activate_plugin`, `install_plugins`, `update_plugins` | 3–6 | the addon/extension installers |

`manage_user_registration` plus the full `user_registration` post-type capability
set are added **to `administrator` only**, at install
(`includes/class-ur-install.php:647` and `:672`). No other role gets them, and nothing
grants them at runtime — so an editor cannot reach the plugin at all, by design.

28 files perform `wp_verify_nonce` / `check_admin_referer` / `check_ajax_referer`.

## Upgrades — what runs

Two independent version ladders, which is itself a hazard: a partial upgrade can
leave `user_registration_db_version` and `user_registration_migration_version`
disagreeing.

**DB updates** (`includes/class-ur-install.php:26`), run through a background
updater (`class-ur-background-updater.php`) — 1.0.0, 1.2.0, 1.3.0, 1.4.0, 1.4.2,
1.5.8.1, 1.6.0, 1.6.2. The meaningful ones touch user meta
(`ur_update_120_usermeta`, `ur_update_1581_meta_key`, `ur_update_162_meta_key`)
and options (`ur_update_140_option`, `ur_update_142_option`,
`ur_update_160_option_migrate`).

**Migrations** (`includes/class-ur-install.php:360`), run inline, defaulting to
`2.3.4` when the option is absent:
- `4.0` → `ur_update_30_option_migrate`, `ur_pro_update_40_option_migrate`
- `4.2.0` → `ur_pro_module_addons_migrate` — folds standalone pro addons into modules
- `6.0` → `urm_update_50_option_migrate` — the membership rework

Every migration callback is wrapped in try/catch and a failure is only written to
`ur_get_logger()->debug()` (`class-ur-install.php:388`). **A migration can fail
silently and the version option still advances**, so an upgrade spec must assert
on the migrated data, never on the version number.

## Fragile areas — evidenced from git

File appearance count across the last 600 fix/bug/regression commits:

| File | Fix commits |
|---|---|
| `includes/functions-ur-core.php` | 81 |
| `modules/membership/includes/Admin/Services/Stripe/StripeService.php` | 68 |
| `assets/js/modules/membership/frontend/user-registration-membership-frontend.js` | 57 |
| `modules/membership/includes/AJAX.php` | 44 |
| `modules/membership/includes/Admin/Services/SubscriptionService.php` | 38 |
| `modules/membership/includes/Admin/Services/Paypal/PaypalService.php` | 33 |
| `includes/class-ur-smart-tags.php` | 26 |
| `includes/functions-ur-template.php` | 25 |
| `includes/class-ur-ajax.php` | 25 |
| `includes/admin/class-ur-admin-settings.php` | 25 |
| `modules/membership/includes/Admin.php` | 24 |

Membership payments dominate. Four clusters stand out in the commit subjects:

1. **Payment/currency correctness** — Stripe charging the global amount in local
   currency (`0fbffa10`), subscription tab showing base price instead of amount
   paid (`dc30d7cb`), 100%-coupon recurring price (`78d9eeef`), tax
   (`b65800f4`). Assert on the *amount actually charged and stored*, not the
   displayed plan price.
2. **Profile-update side effects** — profile picture silently wiped on save
   (`8e743b95`, `d1d35364`), false success message and missing fallback for
   users not created through a UR form (`f00556de`, `b3f07a36`). Every profile
   spec should assert that untouched fields survive the save.
3. **Email-confirmation / admin-approval interaction** — workflow bypassed on
   profile update (`dea594ea`), duplicate approval email (`e2761314`), welcome
   email ordering against payment (`9d55a416`, `ed4d34f5`). The
   `admin_approval_after_email_confirmation` option is the recurring culprit.
4. **Multilingual page resolution** — login/logout, lost-password and 2FA
   redirects resolving to the default language (`9bcd033b`, `a15208a6`,
   `6750dddb`), and `ur_get_my_account_url` appending a trailing slash after a
   query string on plain permalinks (`2cdbbded`). Permalink structure and
   WPML/Polylang are both real variables here.

`includes/functions-ur-core.php` is ~12,700 lines and touched by 81 fix commits;
treat any change to it as high blast radius.

## Critical flows

The product's functional areas, in the order they matter. These slugs are the
contract: a spec declares its area with a matching `@tag` in its title, and
`suite.json`'s `area_paths` maps the same slug to the source that can break it.
Keep the three in step — a rename here without the other two silently drops an
area out of CI narrowing.

- registration — submitting a form creates the right account in the right state
- my-account — profile edit, change password, and everything surviving a save
- membership — plan creation, membership registration, upgrade, cancellation
- security — dashboard access, capabilities, captcha, nonce handling
- login-forms — login options, redirects, lost password, validation messages
- email-notification — approval, confirmation, and payment emails
- content-restriction — who can see what, by role and by membership
- payments — Stripe, PayPal, amounts charged and recorded
- form-builder — building and saving a form with every field type
- admin — the settings screens and menus every other area depends on

The tier breakdown below says which individual tests gate CI today.

## Gate tiers — what must pass (evidenced)

Superseded the earlier proposal. These come from `themegrill/UR-Automation`, the
team's existing Robot Framework suite (310 tests, ingested into
`.themegrill-qa/testcases/`). That suite's `test_scopes.json` declares two gate
sets by tag, which is the team stating in its own words what matters:

- **`smoke`** = tag `ci_cd` — 16 tests, "fast CI gate set, run before anything else"
- **`regression`** = `ci_cd` + `core_feature` — 36 more, "full regression"

**Tier 1 — the `ci_cd` gate (16 tests).** If one of these breaks, the build is
broken. In the suite's own file order:

1. Form builder: drag and drop of all fields (`01__form_builder`)
2. Registration with Auto Approval + Manual Login (`02__form_settings`)
3. My Account: edit profile (`03__my_account`)
4. `Prevent WP Dashboard Access` enabled (`04__setting_general_options`)
5. Login with username when both username and email are allowed (`05__setting_login_options`)
6. Lost-password link shown on the login page when enabled in General (`05`)
7. Login and edit profile over **AJAX** (`05`)
8. Admin approval **via the approval-link email**, approving through the email (`06__email_related_tests`)
9. Admin Email + Successfully Registered email both sent (`06`)
10. Membership admin navigation: Memberships, Add New, Create Group, Members (`09_ur_membership`)
11. Create a **Free** membership plan (`09`)
12. Create a **Paid** membership plan (`09`)
13. Create a **Subscription** membership plan (`09`)
14. Membership registration, end to end (`09`)
15. Membership plan with content-restriction checks (`09`)
16. Subscription plan blocked on the free version — upsell gating (`09`)
    — **but see the exclusion below: this one does not actually run.**

**Three tests carry a gate tag and are excluded anyway.** Both `smoke` and
`regression` exclude tag `needs_investigation`, and in Robot Framework exclude
beats include, so these never execute despite their `ci_cd` / `core_feature`
tags:

- `Verify Subscription Based Plan Is Blocked For Free Version >> Upsell Gating` (`ci_cd`)
- `Verify Multiple Membership Purchase Is Rejected For A Plan Outside Any Allow-Multiple Group` (`core_feature`)
- `PayPal Tampered Order Token Is Rejected On Capture`

**The effective CI gate is therefore 15 tests, not 16**, and the two negative
membership cases above — free-version upsell gating and allow-multiple policy
enforcement in the *rejection* direction — are unguarded today. Both are
policy-enforcement negatives, which is exactly the class that fails silently.
Worth a maintainer deciding whether they are quarantined for a good reason or
just never fixed. A port to Playwright should not inherit the exclusion
without that decision.

**Note the weighting: 7 of the 16 CI-gate tests are membership.** The earlier
draft of this file ranked membership late and lost-password/reset high; that was
wrong. Membership plan creation and the membership E2E registration are gate
tests, and password reset is not tagged `ci_cd` at all.

**Tier 2 — `core_feature` (36 tests total with the above).** Adds the rest of the
approval matrix (Auto Approval Auto Login, Admin approval), both change-password
paths, `Prevent WP Dashboard Access` in its *disabled* state, login+profile over
**Post** as well as AJAX, `Disable Default WordPress Login Screen`, the MaxMind
/ geolocation settings group, and the membership depth: restricted content
reachable by an authorised member, free→paid upgrade, upgrade-plan visibility
rules, Stripe payment intent succeeded, PayPal order completed, payment-success
emails for one-time and subscription, allow-multiple-group policy in both
directions, and registration succeeding with no frontend nonce present.

**Tier 3 — everything else**, 310 tests across 11 areas. Full breakdown in
`.themegrill-qa/testcase-index.json`; the two heaviest are `08--addons`
(174 tests) and `09-ur-membership` (58).

### Area map — use this for `area_paths`

The suite's directory numbering is a human-authored decomposition and maps
cleanly onto product areas:

| Area | Tests | Product surface |
|---|---|---|
| `00-plugin-installation` | 1 | activation from zip |
| `01--form-builder` | 3 | `includes/form/`, builder assets |
| `02--form-settings` | 13 | login options, password strength, domain allow/deny |
| `03--my-account` | 3 | `UR_Shortcode_My_Account`, endpoints |
| `04--setting-general-options` | 6 | `class-ur-settings-general.php` |
| `05--setting-login-options` | 33 | login/redirect, validation messages |
| `06--email-related-tests` | 11 | `includes/admin/settings/emails/` |
| `07--setting-misc` | 7 | popups, privacy, login/logout redirects |
| `08--addons` | 174 | pro addons — mostly out of scope for the free plugin |
| `09-ur-membership` | 58 | `modules/membership/`, payments, restriction |
| `ur-dbtest` | 1 | option persistence |

## Expected behaviour — now largely available

No longer a blank. The ingest extracted **880 assertions** from the Robot suite
into `.themegrill-qa/testcases/*.md`, and those are real, executed assertions
rather than doc prose — a stronger source than the 511 doc outcomes, because
they are what the team actually checks today.

Caveats a human still owns:

- **266 of the 310 tests carry no documentation**, so for those the test *name*
  is the entire statement of intent. Names like "Validate working of Edit
  profile" do not say which fields must survive the save. Where the name is
  thin, the assertion list in the ingested file is the better guide.
- The suite's shared keywords (1221 of them) map to helpers, not to specs. Do
  not port them one-to-one; the ingested files mark locators as
  "reference only, do not port".
- The suite runs against a site with **pro and addons active** for much of
  `08--addons` and parts of `09`. Check tag `free` (44 tests) versus `pro`
  (10 tests) before treating a failure here as a free-plugin regression.

## Observed while porting the suite (2026-09-04, unfiled)

Two things found by building the Playwright specs, both on `http://test-urm.local`
with URM free 5.2.7. Neither is filed; a maintainer should decide whether they
are bugs.

1. **The membership list removes a row on a failed delete.** Deleting a plan
   that still has Active/Pending/Trial members is correctly refused server-side
   — `admin-ajax.php?action=user_registration_membership_delete_membership`
   answers HTTP 200 with `{"success":false,...}` and a clear explanatory
   message — but the list table removes the row from the DOM anyway. The plan
   reappears on reload. So the UI reports success for a refusal, and no visible
   error was observed. This is why `membership.spec.ts` asserts on the list
   after a reload rather than on the row disappearing: the DOM is not evidence.
2. **Member counts survive user deletion.** After deleting ~30 test users
   through the REST API, four plans still reported 3–8 members and remained
   undeletable for that reason, while the `free` plan's count fell from 54 to
   18. That is consistent with rows in `{prefix}ur_membership_subscriptions`
   outliving the users they belong to, which would make an affected plan
   permanently undeletable through the UI. Not confirmed against the table —
   worth a maintainer checking whether user deletion is supposed to clean up
   subscription rows.

3. **Enabling Ajax Login appears to break the My Account Change Password
   link.** With `ur_login_ajax_submission` on, the "Change Password" action on
   `/my-account/edit-profile/` could not be found for 20s and both change-password
   specs failed; with it off, in the same run and against the same user, both
   pass. Reproduced twice by accident (the toggle was left on) rather than
   investigated deliberately, so treat this as a strong lead, not a diagnosis —
   it may be that the link moves rather than disappears. `ajax-login.spec.ts`
   restores the original value in a `finally` for exactly this reason: leaving
   it on changes unrelated specs.

## Known non-issues — TODO (mostly empty)

One is now evidenced rather than guessed: the Robot suite excludes tag
`needs_investigation` from both the `smoke` and `regression` scopes
(`test_scopes.json`), so whatever carries it is knowingly not gating. Read that
tag's members and fold the accepted ones in here.

Still unconfirmed, for a maintainer to accept or reject:
- `get_recent_nonce` served to unauthenticated callers (above)
- `user-registration_license_key` using a hyphen where every sibling uses `_`
- pro-only docs articles, and the `08--addons` tests, read against a free checkout

## Upgrade — what must survive: TODO

Unknown. The two version ladders and the silent-failure migration handling above
make this the highest-risk untested area. A human needs to state which data must
be intact after 4.x → 5.x and 5.x → 6.x: existing forms and their field
configuration, existing users' meta, active subscriptions and their next billing
date, content-restriction rules.

## Environment notes

- Free plugin; no licence gate for QA runs. `user-registration-pro` 6.2.7 is
  present in the same site — if it is active, behaviour under test is the pro
  behaviour, so deactivate it for a free-plugin run.
- QA site state verified 2026-09-04 against `http://test-urm.local`: admin
  credentials in `.themegrill-qa/.env.local` authenticate and reach `wp-admin`;
  `user-registration/user-registration.php` is **active**;
  `user-registration-pro` and the `authorize-net`, `geolocation`,
  `import-users` and `mollie` add-ons are all **inactive**. That is the correct
  baseline for a free-plugin run — re-check it if anything starts behaving
  like pro.
- Running the suite locally: `pnpm test:e2e` (all), `test:e2e:fresh`,
  `test:e2e:demo`, `test:e2e:ui`, `test:e2e:report`, `test:e2e:install`
  (chromium). `playwright.config.ts` loads `.themegrill-qa/.env.local` itself,
  so these work without sourcing anything first; variables already in the
  environment win, which is what keeps `run-suite.mjs` authoritative under CI.
  No script uses shell quoting or lookahead grep patterns, so they behave the
  same in Git Bash, PowerShell and cmd.
- Package manager `pnpm`. A Playwright suite now exists: `playwright.config.ts`,
  specs under `tests/e2e/specs/`, helpers under `tests/e2e/support/`, and
  `@playwright/test` pinned to 1.57.0 — the version `@wordpress/scripts` already
  pulled in, so the lockfile change is a direct-dependency entry rather than a
  new download. `.themegrill-qa/suite.json` describes it, `area_paths` included.
- **`node_modules` in this checkout was installed by pnpm 10.30.3 while
  `package.json` pins `packageManager: pnpm@9.15.9`.** Any `pnpm install` here
  offers to wipe and rebuild the tree, and `pnpm exec` cannot resolve a binary.
  Nothing in the suite causes this and nothing in the suite fixes it — someone
  has to reconcile the two versions, after which
  `pnpm install && pnpm exec playwright test` works normally.
- A mail catcher is available and the email specs use it: Local runs Mailpit
  per site, and **test-urm's mailbox is http://127.0.0.1:10000** (API at
  `/api/v1/messages`). Override with `TGQA_MAILPIT_URL`; when nothing answers,
  `emails.spec.ts` skips rather than fails. The helper deliberately has no
  purge/delete: that mailbox holds hundreds of unrelated real messages, and
  isolation comes from the unique per-run address instead.
- **Activating the plugin does not set the site up, and a `@fresh` spec cannot
  assume it did.** `UR_Install::install()` creates options, tables and roles and
  records `user_registration_first_time_activation_flag`; it calls neither
  `create_form()` nor `create_pages()`. So a clean install has no registration
  form, no `/my-account/`, and `users_can_register` still off — the admin screen
  says so itself ("Please choose a My Account page", "Anyone can register option
  is turned off"). Those are the setup wizard's job. Provisioning happens in the
  handler for **opening** the wizard: an authenticated GET to
  `/wp-json/user-registration/v1/getting-started` runs
  `install_initial_pages()` and `ensure_default_form()`, which is what
  `ensureFirstRun()` in `tests/e2e/support/urm.ts` calls. It bails out when a
  form already exists, because the bootstrap also writes `users_can_register`
  and `user_registration_login_options_prevent_core_login` and must not edit a
  developer's real site.
- **The form a clean install ends up with has four fields** — username, email,
  password, confirm password — so its Edit Profile form offers only a readonly
  `user_registration_user_login` and `user_registration_user_email`. Any spec
  that mutates a profile field other than email needs demo content and belongs
  in `@demo`; `test-urm.local` has a Country field on its form, and one spec
  read that site configuration as product behaviour for a while.
- **Playground's auto-login makes `browser.newContext()` an admin session, not a
  visitor.** `boot-wp.mjs` starts the CLI with `--login`, which authenticates
  every new context as admin on its first request. A "visitor" context therefore
  lands on the My Account dashboard instead of the login form — and, worse than
  a red test, `settings-security.spec.ts`'s "a subscriber keeps the admin bar"
  passed for the wrong reason. `newVisitor()` in `tests/e2e/support/wp.ts` lets
  the auto-login happen once, then drops the `wordpress*` cookies while keeping
  `playground_auto_login_already_happened` — keeping that marker is what stops
  the next request logging the context back in. `loginAsAdmin()` checks
  `/wp-admin/` before driving wp-login.php for the same reason: going through the
  form on top of the auto-login races its redirect, which is how one CI run
  failed on `#wpadminbar` and passed on retry.
- **Playground sends no mail at all**, so the email specs cannot pass there
  whatever is listening. `mailAvailable()` returns false when
  `TGQA_ENV=playground` rather than probing: Local runs a Mailpit per site, so a
  suite pointed at a Playground site from a machine where Local is up finds a
  healthy catcher on :10000 and then fails waiting 30s for mail that never left.
  Set `TGQA_ENV=playground` when running the suite by hand against a Playground
  site — `run-suite.mjs` and the CI workflow both export it already.
- **These specs are whole user journeys, and CI's shared 45s per-test default
  does not fit them.** Provision a form, publish a page, register on the front
  end, log in as that account, delete it — a dozen page loads against WASM PHP.
  Measured worst case on a reset Playground is ~25s locally, and the GitHub
  runner is slower still: at 45s the three login specs passed only on retry and
  the ajax spec timed out. `qa-suite.yml` sets `test_timeout_ms: 90000`, about
  3x the measured worst case, so a hung spec is still capped.
  **Do not read a local pass as a CI pass** — build in headroom for a runner
  that is roughly 1.5x slower than a developer's machine. Real CI durations for
  reference, measured on run 33857705477: login specs 35-37s, change-password
  42s and 47s, registration 36s, membership 18s.
- **The first test of a run pays a cold start.** Playground reports settled
  while WASM PHP is still cold, so `loginAsAdmin()` asserts `#wpadminbar` with a
  45s timeout rather than the 15s `expect` default — that assertion is where the
  run's first test failed and its retry passed, which costs a retry to learn
  nothing.
- **`restNonce()` costs a full admin page load, so it is cached per browser
  context** — four helpers want it and one test's worth of redundant loads was
  most of the overshoot above. It must NOT be cached for the whole run: a
  WordPress nonce embeds the session token, every test gets a fresh context and
  therefore a fresh login, and a nonce from a previous test fails as
  "Cookie check failed". `ensureFirstRun()` and the form id ARE cached run-wide,
  because those are site state and do not expire with a session.
- **`boot-wp.mjs` reuses a persisted site keyed on the checkout path; `--reset`
  is opt-in.** A local boot without it can be a site that has already been
  through onboarding, which is the opposite of the state CI gets. Reproducing a
  CI-only failure means `--reset`, or the run proves nothing.
- Two settings that look like they belong on a settings tab do not:
  **Login Methods** (`user_registration_general_setting_login_options_with`) and
  **Enable Ajax Login** (`ur_login_ajax_submission`) both live in the Login Forms
  builder, `admin.php?page=user-registration-login-forms`, and save with its
  "Update Form" button (`[name=save_login_form]`). Both render as hidden
  checkboxes behind styled toggles.
- Settings tab slugs use underscores, not hyphens: `registration_login`,
  `my_account`. A hyphenated slug silently redirects to General, so a spec that
  navigates to `&tab=registration-login` asserts against the wrong screen.
- The QA automation that also exists is **`themegrill/UR-Automation`** (private),
  a Robot Framework / Python suite, not Playwright — it lives in its own repo
  and runs via `run_tests.py --scope <name>` against `test_scopes.json`.
  claudegrill's runner cannot execute it. It was ingested here as a
  specification (`.themegrill-qa/testcases/`, `testcase-index.json`) for porting
  to Playwright, per the ingest tool's own header note.
- PHP linting is `pnpm run phpcs` (composer script); unit tests run through
  `wp-env` (`pnpm run test-unit-php`).
- Permalink structure and an active multilingual plugin are both live variables
  for the redirect flows — pin them in any spec that asserts on a URL.
