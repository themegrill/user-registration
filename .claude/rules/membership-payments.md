---
paths:
  - "modules/membership/**"
  - "modules/stripe/**"
  - "modules/paypal/**"
  - "modules/payment-history/**"
---

# Membership and payments (highest-risk area)

Read `ur-architecture/reference/membership-payments.md` (data model, flows, gateways, invariants) and `reference/security.md` before changing anything here, then run the `ur-reviewer` agent before a PR.

- **Verify before you change state.** Webhooks: the REST `permission_callback` in `Services/PaymentGatewaysWebhookActions.php` is the signature check. `confirm_payment`: the server re-retrieves the Stripe PaymentIntent and checks mode, status, order ownership and duplicates; the client-supplied `payment_status` is not trusted. Never move logic ahead of these or weaken a link.
- **Idempotent by design:** a replayed, duplicated or out-of-order gateway event must not extend a membership or create a second order (a Stripe replay renewal bug was fixed in 5.2.8). Check the duplicate path of every handler you touch.
- **Roles are deferred and re-checked:** paid plans store `urm_pending_role` and grant it only when the subscription is `active`/`trial` (`MembersService::maybe_grant_pending_role`); `ur_membership_get_safe_role()` downgrades privileged roles. Never assign a membership role directly or from request data.
- **Public (logged-out) AJAX here** is `confirm_payment`, `create_stripe_subscription`, `validate_coupon`, `validate_stripe_card_mode`. Everything else is logged-in only; do not flip a `false` to `true`.
- **PayPal has two generations** (`PaypalService.php` legacy IPN, `NewPaypalService.php` REST). Find out which one a path uses before changing behaviour, and keep both working.
- **Cron and backfill run with no user or nonce** (`Crons.php`, `urm_*` events, Stripe/PayPal missed-event backfill). A renewal, retry or backfill bug is usually in `SubscriptionService` or the gateway service, not in checkout.
- **Schema:** table names come from `TableList`; tables are created with `CREATE TABLE IF NOT EXISTS`, so any column or ENUM change needs an explicit migration. Order status is `pending|failed|completed|refunded`; subscription status is `active|canceled|expired|trial|pending`; keep code and stored values in step.
- **Free fires, Pro may implement.** `ur_membership_subscription_event_triggered` has no listener in free and the events table has no writer in free. Do not delete such hooks or tables as dead code.
- **Legacy and install-time flags** (for example `urm_is_new_installation`, `urm_is_legacy_paypal_user`, set in `UR_Install`) keep old behaviour alive on existing sites. Check the flags present on your branch before removing or bypassing a legacy path.
- **Logging** goes through `PaymentGatewayLogging`, which records raw webhook data; never add secrets, keys or card data.
- Amounts, currency and plan are derived server-side, never taken from the request.
