---
name: ur-architecture
description: How the User Registration plugin is built and what other code depends on it. Load before any non-trivial change to forms, registration/login flow, membership, payments, webhooks or content restriction, or before changing any hook, option, meta key, table or AJAX/REST endpoint.
---

# User Registration architecture

Use this to find the real code path and the real blast radius before editing. It is a map, not a substitute for reading the code: every anchor below is a file or symbol you can open.

## Workflow

1. **Locate the area** in the table below and read that reference section.
2. **Trace end to end:** entry point (shortcode, AJAX action, REST route, cron, webhook, template) -> the function you will change -> every caller (`Grep` across `includes/`, `modules/`, `templates/`, `src/`).
3. **Check the contract surface** in [extension-points.md](reference/extension-points.md) if you touch anything named, stored or exposed, and [ecosystem.md](reference/ecosystem.md) for who consumes it. Pro and the add-ons are not in this checkout, so say in your report what you could not check there.
4. **Check the trust boundary** in [security.md](reference/security.md) if the code handles requests, money, roles, tokens, redirects or restricted content.

Reference files (open the one for your area; each was written from the code, and says where it hedges): [architecture.md](reference/architecture.md) (overview and index), [registration-and-auth.md](reference/registration-and-auth.md), [membership-payments.md](reference/membership-payments.md), [content-restriction.md](reference/content-restriction.md), [subsystems.md](reference/subsystems.md), [ecosystem.md](reference/ecosystem.md), [extension-points.md](reference/extension-points.md), [security.md](reference/security.md). Verify anything you rely on against the branch you are on.

## Where things are

| Area | Start at | Read |
|---|---|---|
| Form builder, form CPT, fields | `includes/class-ur-post-types.php`, `includes/form/`, `includes/abstracts/abstract-ur-form-field.php` | [architecture.md](reference/architecture.md#forms-and-registration) |
| Registration submit, login options, approval/confirmation | `UR_AJAX::user_form_submit`, `ur_process_registration` (`includes/functions-ur-core.php`), `includes/class-ur-email-*.php`, `class-ur-user-approval.php` | [architecture.md](reference/architecture.md#forms-and-registration) |
| Account pages, login, lost password | `UR_Form_Handler` (`includes/class-ur-form-handler.php`), `includes/shortcodes/`, `templates/myaccount/` | [architecture.md](reference/architecture.md#forms-and-registration) |
| Membership, subscriptions, orders, coupons | `modules/membership/`, tables via `TableList` | [architecture.md](reference/architecture.md#membership-and-payments) |
| Stripe / PayPal, webhooks, renewals | `modules/membership/includes/Admin/Services/` | [architecture.md](reference/architecture.md#membership-and-payments) |
| Content restriction | `modules/content-restriction/` | [architecture.md](reference/architecture.md#content-restriction) |
| Pro, add-ons, who consumes what, `UR_PRO_ACTIVE` | `.github/sync-file-list.yml`, `includes/functions-ur-core.php` | [ecosystem.md](reference/ecosystem.md) |
| Upgrades and stored-data migrations | `UR_Install::$db_updates`, `includes/functions-ur-update.php` | [architecture.md](reference/architecture.md#stored-data-and-migrations) |

Product behaviour (what users see, known fragile areas) is documented and evidence-backed in `.themegrill-qa/knowledge.md`.
