# User Registration read-only abilities

The shared `UR_Abilities` module registers five native abilities on WordPress 6.9+.
Earlier versions keep running normally; no API or adapter dependency is bundled.
`functions-ur-core.php` initializes the module using the existing plugin autoloader.
All implementation files are under `includes`, covered by Free's existing sync to
Pro `release/develop`. Make shared changes in Free; no Pro-specific implementation
or root plugin/Composer changes are needed.

| Ability | Inputs | Result |
| --- | --- | --- |
| `user-registration/list-forms` | `page`, `per_page` | Editable forms: ID, title, status |
| `user-registration/get-form` | required `id` | Form identity and field name/type/label/required |
| `user-registration/get-registration-stats` | `date_from`, `date_to`, `form_id` | Form registrations grouped by UTC day, form and current approval status |
| `user-registration/list-members` | `page`, `per_page`, `form_id` | Registration/member identity, email, registration date, form and verification/approval status |
| `user-registration/get-member` | required `id` | Member identity plus membership availability and subscription IDs, plan names, status, start/expiry dates |

Calls to `list-forms`, `list-members`, and `get-registration-stats` may omit input
entirely; they use the same defaults as an explicit empty input object.

Pagination defaults to page 1 and 20 items, with at most 100 items per page and
page 10000. Responses contain `items`, `page`, `per_page`, `has_more`. Form pages
refer to scanned records; inaccessible forms are omitted, so a page can be empty
while `has_more` is true. Trash and automatic drafts are excluded.

Members are users on the current site with a positive `ur_form_id` or
`ur_registration_source=membership`. Historical accounts without either marker
are outside this first release. Membership-only accounts have form ID 0 and are
excluded from form registration statistics. `email_verified` is null when no
verification state exists. Registration dates use UTC; subscription dates retain
the stored membership date strings (empty when no date exists).

Statistics default to the last 30 UTC calendar days including today, accept valid
inclusive YYYY-MM-DD dates spanning at most 93 days, and reject matches exceeding
10000 registrations. Narrow the dates or specify a form when this limit is hit.
Counts describe **current** approval states of accounts registered in the range;
they do not reconstruct historical approval transitions or track conversions.

## Authorization and access

Every ability requires `manage_user_registration`. Member reads and statistics
also require `list_users`. Form detail and each listed form additionally require
`edit_post` for that form. Schemas reject unknown inputs. Responses use explicit
allowlists: no passwords, session tokens, arbitrary user metadata, raw form
settings/defaults, payment credentials or membership post content.

The native Abilities REST endpoint is available to authenticated, authorized
WordPress callers, for example:

```text
GET /wp-json/wp-abilities/v1/abilities/user-registration/get-form/run?input[id]=123
```

For MCP, separately install the official WordPress MCP Adapter. Tested with 0.6.1.
The default server exposes these through `mcp-adapter/discover-abilities`,
`mcp-adapter/get-ability-info`, and `mcp-adapter/execute-ability`. Execute parameters:

```json
{"ability_name":"user-registration/get-form","parameters":{"id":123}}
```

`meta.mcp.public=true` enables MCP discovery; it does **not** bypass authentication
or the target ability's permissions. All five are annotated read-only,
non-destructive and idempotent. There is no custom server or new authentication
model. Transport/client setup belongs to the official adapter.

Pro conversion analytics, funnel instrumentation and all write actions are deferred.
See `tests/e2e/support/ABILITIES.md` for the disposable integration proof.
