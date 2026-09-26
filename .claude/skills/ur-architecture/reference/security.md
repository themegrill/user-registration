# Security reference

Follow OWASP ASVS and the WordPress plugin security handbook. This file records where this plugin's trust boundaries are and which mistakes have already shipped here (from the 5.2.8 entries in `CHANGELOG.txt`), so review starts from evidence rather than a generic checklist.

## Trust boundaries
| Boundary | Where | Requirement |
|---|---|---|
| Public (logged-out) AJAX | `UR_AJAX::$ajax_events` entries set to `true` (e.g. `user_form_submit`); membership `confirm_payment`, `create_stripe_subscription`, `validate_coupon`, `validate_stripe_card_mode` | Treat all input as hostile. Nonce is not authorization. Never return another user's data or accept a price, role or membership id you did not derive server-side. |
| Logged-in AJAX | remaining `$ajax_events` and membership events | Nonce **and** `current_user_can()` for anything touching other users, orders, subscriptions, settings. Used caps here: `manage_options`, `manage_user_registration`, `edit_users`, `edit_user`, `promote_users`. |
| Payment webhooks | REST `user-registration/stripe-webhook` and `/paypal-webhook` (`PaymentGatewaysWebhookActions.php`); legacy PayPal IPN via `?ur-membership-listener=IPN` | The `permission_callback` **is** the signature check (Stripe `Webhook::constructEvent`, PayPal `verify_webhook_signature`; the legacy IPN is checked for `VERIFIED` by posting it back to PayPal in `PaypalService`). Never move logic ahead of verification. Handlers must be idempotent: a replayed or duplicated event must not extend a membership or create a second order. |
| Redirects | login/registration/payment return flows | `wp_safe_redirect()` / `wp_validate_redirect()`; never redirect to a raw request parameter. |
| Tokens | email confirmation and approval/denial tokens, payment return tokens | `hash_equals()`, single use, expiry where the flow has one. |
| Role / membership assignment | membership create/upgrade, registration | Derive roles and plan from stored server-side config keyed by a validated id, never from request fields. |
| Restricted content | `modules/content-restriction/` | Enforce on every delivery channel (render, REST, feeds, search, AJAX, blocks). |
| Secrets | gateway keys and webhook secrets in options | Never log, echo, export or place in a response/PR/commit. `PaymentGatewayLogging` already logs request data: do not add secrets or card data to it. |
| File / import surfaces | form import/export, profile picture upload, user export | Validate type and size, sanitize decoded data, and treat imported JSON as untrusted. |

## Mistakes fixed in 5.2.8 (see CHANGELOG.txt)
- Content restriction bypassed through the core REST API (only template flow was enforced).
- Membership thank-you page exposed another member's account details.
- A Stripe payment could be replayed to renew a membership without paying.
- Privilege escalation via membership role, and an open redirect after login.
- Non-timing-safe comparison of email confirmation and approval tokens.
- Team edit-form nonce not scoped to the object being edited.

## Review checklist for a diff
1. New or changed entry point: who can call it (logged-out? which role?), what does it verify, and what does it return?
2. Is every `$_GET/$_POST/$_REQUEST/$_SERVER` read unslashed, sanitized and validated for the type it is used as?
3. Is every output escaped for its context, late?
4. Every SQL statement with a variable goes through `$wpdb->prepare()`.
5. Object-level authorization: does the current user own or manage the record being read or changed?
6. Money paths: amounts, currency and plan come from the server; state changes are idempotent; failure paths leave a consistent order/subscription state.
7. Nothing sensitive added to logs, responses, exceptions or `raw_get`-style dumps.
8. Would the changed behaviour surprise Pro or a customer snippet? See `extension-points.md`.

If you find a vulnerability while working on something else, report it separately to the maintainers; do not fold an undisclosed security fix into an unrelated public diff.
