---
paths:
  - "**/*.php"
---

# PHP in this plugin

Standards come from `phpcs.xml` (`WordPress`, `PHPCompatibility` 7.4-, `WPEverest-Core`) and `.editorconfig` (tabs, LF). A hook reports violations on the lines you change; fix them by hand. Do not run `phpcbf` on a whole legacy file, it reformats untouched code. If the hook says phpcs aborted, the file was not checked.

- **Docblocks** on every function, method and class; the ruleset enforces it.
- **i18n:** literal text domain `user-registration`, never a variable.
- **Input:** sanitize at the boundary (`sanitize_text_field( wp_unslash( ... ) )`, `absint`, `ur_clean()` for arrays). **Output:** escape late (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- **AJAX:** in `UR_AJAX::add_ajax_events()` the boolean in `$ajax_events` decides whether a `wp_ajax_nopriv_` (logged-out) endpoint is registered, so adding an entry with `true` makes it public. A nonce only proves the request came from a page we rendered; also check `current_user_can()` for anything that reads or changes another user's data. The membership module registers its own actions in `modules/membership/includes/AJAX.php`.
- **REST:** every route needs a real `permission_callback`. Never `__return_true` on something that reads or writes user, order or subscription data.
- **SQL:** `$wpdb->prepare()` for anything with a variable. Membership table names come from `TableList` (`modules/membership/includes/TableList.php`).
- **Redirects:** `wp_safe_redirect()` / `wp_validate_redirect()`. Open redirects after login have shipped before (see `CHANGELOG.txt`).
- **Secrets, tokens, signatures:** compare with `hash_equals()`, never `==`/`===`.
- **New hooks:** search first for an existing hook that fits. Follow the module's prefix (`user_registration_`, `ur_membership_`, `urcr_`, `urm_`) and pass enough arguments for Pro to use it.
- **Before changing a signature, hook, option or meta key:** grep every caller in `includes/` and `modules/`, and remember Pro consumes this code (see `CLAUDE.md`).
