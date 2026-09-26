# Content restriction

Read from `modules/content-restriction/` (`functions-urcr-core.php`, `class-urcr-frontend.php`, `class-urcr-rest-restriction.php`, `class-urcr-post-types.php`). Loaded unconditionally by `user-registration.php`; membership-based conditions need the membership module on.

## Two restriction systems coexist
1. **Access rules (current).** Posts of type `urcr_access_rule`; the rule is **JSON in `post_content`**: `enabled`, `target_contents[]` (each with a `type`; `whole_site` is one), `logic_map` (a tree with `conditions`), `actions[]` (`actions[0].access_control` is `access` or `restrict`, plus the message/redirect/etc.). Only published rules count (`urcr_get_published_access_rules()`, cached per request in a static).
2. **Basic / legacy restriction.** Site options `user_registration_content_restriction_*` (`_enable`, `_allow_access_to`, `_allow_to_roles`, `_allow_to_memberships`, `_whole_site_access`) and per-post meta `urcr_meta_override_global_settings`, `urcr_allow_to`, `urcr_meta_roles`, `urcr_meta_memberships`. `urcr_is_basic_access_granted( $allow_access_to, ... )` interprets `0` = logged in, `1` = roles, `2` = logged out, `3` = memberships. A set of `urcr_migrate_*` functions converts old settings, post meta and memberships into access rules; both systems must keep working for un-migrated sites.

## The authorisation function
`urcr_is_content_access_granted( $target_post )` is the single decision point (the REST layer uses it too). In order:
1. Global switch `user_registration_content_restriction_enable` off -> **allow**.
2. Target not a `WP_Post` -> **allow**.
3. Super admin or a user who can `edit_post` that post -> allow.
4. Page excluded (`urcr_is_page_excluded`) -> allow.
5. Per-post override on: uses the per-post basic settings **instead of** everything global; a role restriction with no roles selected -> **allow**.
6. Otherwise resolve access rules (`urcr_resolve_access_rules`): each *evaluable* rule (`urcr_is_access_rule_evaluable`: has `logic_map.conditions`, `target_contents`, `actions`, and is enabled) is matched as `whole_site` or `post` target; `urcr_is_allow_access( logic_map, post )` evaluates the conditions; the rule's `access_control` decides whether a pass means grant or a pass means restrict.
7. A post-targeted rule that matched and did not grant -> **deny**; a whole-site rule that matched and did not grant, with no post rule granting -> **deny**.
8. If nothing granted and the basic "whole site members only" option is on and no whole-site access rule exists, the basic options decide.
9. Content drip pending (`urcr_is_content_drip_pending`) -> deny. Otherwise allow.

**Edge cases to preserve or consciously change:** a non-post target, a rule that is not evaluable, an empty role list, and the global switch being off. Changing how any of them resolves changes what visitors see on existing sites, so treat it as a product decision, not a cleanup, and confirm it with the maintainers. Do not add a new early return in this function without the same review.

## Condition types (`urcr_is_allow_access`)
`roles`, `capabilities`, `user_registered_date`, `access_period`, `ur_form_field` (compares user fields such as `user_login`, `user_email`, `display_name` or a form field), `user_state`, `profile_completeness`, `post_count`, `email_domain`, `registration_source`, `membership`. New condition types belong in that switch and in the React rule builder (`src/content-restriction`).

## Enforcement channels (each is separate code)
- Page render: `URCR_Frontend::run_content_restrictions` on `template_redirect`, plus a `the_content` filter at `PHP_INT_MAX` for message replacement, plus whole-site (`restrict_whole_site`) and blog-page handling.
- Core REST API: `URCR_REST_Restriction` filters `rest_prepare_<post_type>` at `PHP_INT_MAX` and calls `urcr_is_content_access_granted()`. It exists because the template flow does not run for REST (a bypass fixed in 5.2.8).
- WooCommerce: product visibility, purchasability and shop queries have their own handlers in `class-urcr-frontend.php`.
- Elementor: section-level restriction with the cache disabled for restricted elements.
- Shortcodes `urcr_restrict` and `urm-content-restriction`; the Gutenberg block `class-ur-block-content-restriction.php`; file restriction `ur_restrict_files`.
A new way of delivering content (feeds, search, oEmbed, AJAX, blocks, attachments) must be wired to `urcr_is_content_access_granted()` explicitly; whether the existing channels above already cover a given one was not audited.

## Related
Content drip (`modules/content-drip`) only loads when membership, content restriction and content drip are all on, and adds a delay layer on top of the decision above.
