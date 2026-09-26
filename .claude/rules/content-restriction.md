---
paths:
  - "modules/content-restriction/**"
  - "modules/content-drip/**"
---

# Content restriction

Read `ur-architecture/reference/content-restriction.md` first (two coexisting systems, the decision order, the enforcement channels).

- **One decision point:** `urcr_is_content_access_granted()`. Route new checks through it rather than re-implementing role or membership tests in a channel.
- **Every delivery channel needs its own enforcement:** page render (`URCR_Frontend`), the core REST API (`URCR_REST_Restriction`), shortcodes, WooCommerce, Elementor and anything new (feeds, search, AJAX, blocks, previews). Fixing one channel is not fixing the bug; a REST bypass was fixed in 5.2.8 because only the template flow was enforced.
- **Edge-case behaviour is deliberate** (restriction switch off, non-post target, a rule that is not evaluable, a role restriction with no roles). Do not silently change how these resolve in either direction, and call out any change to them as a behaviour change.
- **Two systems must both keep working:** access rules (`urcr_access_rule` posts, JSON in `post_content`) and the legacy basic options and per-post meta.
- **Membership-based conditions** need the membership module on (`ur_check_module_activation()`); content drip also needs content restriction on. Test the module-off case.
- Expected behaviour and fragile spots: `.themegrill-qa/knowledge.md`.
