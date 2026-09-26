# Public surface and change rules

Everything below is called by code you cannot see from this checkout: Pro (synced from here on every push to `develop`, and it also extends the plugin), add-ons and customer snippets. Changing it is a deliberate, called-out decision, never a side effect of a refactor.

## Inventory (and how to enumerate it yourself)
| Surface | Convention | Enumerate |
|---|---|---|
| Actions and filters | `user_registration_*` (600+ call sites), plus `ur_membership_*`, `urcr_*`, `urm_*`, and `ur_pro_*` (filters in free code named for Pro, e.g. `ur_pro_get_role_list`; their consumers are not in this checkout) | `Grep "(apply_filters\|do_action)\(\s*['\"]<prefix>" includes modules` |
| Functions and classes | global `ur_*` functions, `UR_*` classes, namespaced `WPEverest\URMembership\...` | `Grep "function ur_<name>"`, `Grep "<ClassName>"` |
| AJAX actions | `user_registration_<event>` (core, `UR_AJAX::add_ajax_events`) and `user_registration_membership_<event>` (`modules/membership/includes/AJAX.php`) | `Grep "wp_ajax_"` |
| REST | namespace `user-registration/v1` (`includes/RestApi/controllers/version1/`, analytics) and unversioned `user-registration/stripe-webhook`, `.../paypal-webhook` | `Grep "register_rest_route"` |
| Shortcodes | `includes/shortcodes/`, membership `ShortCodes.php`, `urcr_restrict`, `urm-content-restriction` | `Grep "add_shortcode"` |
| Templates | `templates/**` loaded by `ur_get_template()` (path is filterable; themes override) | file names and the `$args` keys passed in |
| Stored data | options `user_registration_*`, `urm_*`, `urcr_*`; post/user meta keys; CPTs `user_registration`, `ur_membership`, `ur_membership_groups`; the four `ur_membership_*` tables | `Grep "(get\|update)_option\("`, `TableList` |
| Pro switch | `UR_PRO_ACTIVE` gates behaviour in `includes/functions-ur-core.php` | `Grep UR_PRO_ACTIVE` |

## Dynamic hook names defeat literal grep
Some hooks are built at runtime, so searching for the literal name finds no caller. Example: `ur_user_approved` (hooked by Pro) is fired by `do_action( 'ur_user_' . $action_label, $user_id )` in `UR_Admin_User_Manager`. The settings hooks (`user_registration_settings_<page>`, `..._get_settings_<page>`, `..._get_sections_<page>`, `..._settings_save_<page>`) and `{$shortcode}_shortcode_tag` are dynamic too. Before concluding a hook is unused, grep its prefix and any string concatenation next to `do_action` / `apply_filters` / `add_action` / `add_filter`.

## Change rules
- **Additive by default.** New optional parameters go last; new array keys never replace old ones; add a hook rather than repurposing one.
- **Never change the meaning of a stored value** or rename an option/meta key without a versioned migration (see "Stored data and migrations" in `architecture.md`) and a read fallback for the old key.
- **Deprecate, do not delete.** Use `ur_deprecated_function()`, `ur_deprecated_hook()`, `ur_deprecated_argument()` and `ur_do_deprecated_action()` (`includes/functions-ur-deprecated.php`); about twenty call sites show the pattern. Removal is a major-version decision for the maintainers.
- **Hook argument lists are contracts**, including their order and the exact object types passed. Treat hooks named `ur_pro_*` and anything in the developer docs (`.themegrill-qa/docs/developer-s-doc.md`) as consumed externally; for the rest, assume the same until proven otherwise.
- **Template changes:** keep file names, and keep every variable a template used to receive available in `$args`, or theme overrides break silently.
- **Routes and AJAX actions:** do not rename; do not widen who may call them (see `security.md`).

## What to write in the PR when you touch a contract
List each changed surface, whether it is additive or breaking, the migration or deprecation shim, and that Pro-side consumers were **not** verifiable from this repo.
