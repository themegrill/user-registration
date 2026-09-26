# Subsystems: forms, settings, emails, logging, front end, integrations

Everything here was read from the named files. Anything hedged ("appears", "not audited") was not traced fully.

## Forms and fields
- A form is a post of type `user_registration`. `post_content` is **JSON**: rows -> grids -> field objects; each field has `general_setting` (including `field_name`, label) and `advance_setting`, plus `field_key` (the field type). `UR()->form->get_form( $id, array( 'content_only' => true, 'publish' => true ) )` returns the decoded JSON and returns nothing for an unpublished or trashed form (this is why a draft form "is currently unavailable" on submit). `confirm_user_pass` is dropped from the field list before validation.
- Per-form settings are post meta named `user_registration_form_setting_*` on the form post (login option, default role, redirect, success message, captcha type, ...). Read them with `ur_get_single_post_meta( $form_id, $key, $default )` or `ur_get_form_setting_by_key()`.
- Field types are classes in `includes/form/` extending `UR_Form_Field` (`includes/abstracts/abstract-ur-form-field.php`). A subclass must implement `get_registered_admin_fields()` and `validation( $single_form_field, $form_data, $filter_hook, $form_id )`; settings come from `get_field_general_settings()` / `get_field_advance_settings()`; rendering goes through `frontend_includes()`. Add-ons and Pro register their own field types through the filters `user_registration_registered_form_fields`, `user_registration_field_keys` and `user_registration_one_time_draggable_form_fields`, so those filters and the class contract are public API.
- Field values live in usermeta `user_registration_<field_name>` (see `registration-and-auth.md`).

## Settings and options
- Admin settings pages are classes in `includes/admin/settings/` (`General`, `Registration-Login`, `Email`, `Security`, `Captcha`, `Payment`, `Membership`, `My Account`, `Advanced`, `Integration`, `Import-Export`, `Misc`, `License`), assembled by `UR_Admin_Settings` and extended through `user_registration_get_settings_pages` / `..._get_settings_<page>` / `..._settings_save_<page>` filters and actions (heavily used by add-ons). `UR_Config` (`includes/admin/class-ur-config.php`) holds shared config.
- Option keys: `user_registration_<section>_setting_*` (e.g. `user_registration_general_setting_login_options`, `user_registration_captcha_setting_*`, `user_registration_stripe_*`), plus `urm_*` for membership flags and `urcr_*` for restriction. Renaming a key needs a migration and a read fallback.
- `uninstall.php` deletes plugin data only when option `user_registration_general_setting_uninstall_option` is set or `UR_REMOVE_ALL_DATA` is defined; treat anything that widens what it deletes as data-loss-critical.

## Emails and smart tags
- Core emails go through `UR_Emailer` (`includes/class-ur-emailer.php`): registration to user and admin, approval link, status change, profile-changed to user and admin, lost/reset password, email-address change. Each email has a settings class in `includes/admin/settings/emails/` (registered through `user_registration_email_classes`); membership emails are separate (`modules/membership/includes/Emails/`).
- Smart tags (`{{...}}` placeholders, e.g. `{{force_logout_url}}`) are resolved by `UR_Smart_Tags::process()` (`includes/class-ur-smart-tags.php`). Extension filters: `user_registration_smart_tags`, `user_registration_add_smart_tags`, `user_registration_smart_tag_values`, `user_registration_smart_tag_content`. Tags that expose links or account data (resend, approval, login/logout URLs) are security-sensitive; check who can trigger the email that carries them.

## Logging
`ur_get_logger()` writes files under `wp-content/uploads/ur-logs/` (`UR_LOG_DIR`, file names include a hash). Sources seen: `form-submission`, `user-registration-membership`, `urm-missed-payment-backfill`. The registration log records the submitted form data with **only password-type fields masked**, and the payment logger records raw webhook data. Never log tokens, keys, card data or extra personal data.

## Templates
`templates/` holds front-end views (`form-registration.php`, `form-login-registration.php`, `myaccount/`, `notices/`, `modules/`). `ur_get_template()` locates a theme override first (`locate_template`), snapshots the trusted path before the `ur_get_template` filter can change it, and extracts `$args` only inside an isolated closure so `$args` cannot overwrite path variables. Keep template names and the `$args` keys they receive stable: themes override these files.

## Front-end JS and blocks
- `src/` is React/TS built by webpack: `dashboard`, `welcome` (getting started), `form-templates`, `content-restriction` (rule builder), `analytics`, `blocks`, `widgets/divi-builder`, `context`, `utils`. REST controllers for them are under `includes/RestApi/controllers/version1/` (for example `changelog`, `form-templates`, `getting-started`, `gutenberg-blocks`), namespace `user-registration/v1` (the routes checked, `changelog` and `form-templates`, use an admin `permission_callback`; the rest were not audited).
- Gutenberg blocks are server-rendered classes in `includes/blocks/block-types/`: registration form, login form, my account, edit profile, edit password, content restriction, membership listing, buy-now, thank-you, login/logout menu.
- `assets/js/**` is legacy jQuery (admin form builder, frontend form submit, membership frontend), with committed minified output.

## Integrations and other modules
- Page builders: `includes/3rd-party/` has `elementor`, `DiviBuilder`, `oxygen`. Multilingual: `includes/class-ur-wpml.php` and `wpml-config.xml` (strings via `ur_string_translation`). LMS: `modules/masteriyo` (only when its module and the Masteriyo plugin are active). WooCommerce restriction handlers live in `modules/content-restriction/class-urcr-frontend.php`.
- `includes/Analytics/` (namespace `WPEverest\URM\Analytics`, REST controller under `Controllers/V1`) backs the `src/analytics` UI, appearing to report registration and membership figures; not traced end to end.
- Telemetry: `UR_Stats` (`includes/stats/`) schedules a biweekly usage event and appears to be gated by the opt-in option `user_registration_allow_usage_tracking` (`ur_option_checked( ..., false )`, so default off); `class-ur-formbricks.php` handles surveys. Do not widen what is collected or add a call that runs without that opt-in.
- Privacy: `includes/class-ur-privacy.php` (WordPress export/erase integration) was not read; check it before adding new personal-data storage.
