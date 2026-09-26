---
paths:
  - "tests/e2e/**"
  - "playwright.config.ts"
---

# Playwright e2e suite (claudegrill)

- It is the only automated suite in this repo. `playwright.config.ts` runs one worker on purpose: specs change global plugin settings (login options, security roles), so parallel workers would race.
- Tags: `@fresh` runs on a clean install and is what CI runs; `@demo` needs seeded content. An untagged test counts as `@demo`.
- Site and admin credentials come from `.themegrill-qa/.env.local` or `TGQA_*` env vars. Never read, print or commit that file.
- Reuse the helpers in `tests/e2e/support/` (`env.ts`, `wp.ts`, `urm.ts`, `mail.ts`) instead of new login or setup code.
- On a clean install membership is off; enable the feature first or assert the disabled state on purpose (`.themegrill-qa/knowledge.md`).
- Which specs a change should run is decided by `area_paths` in `.themegrill-qa/suite.json`; `/ur-check` reads it.
- A bug fix should add a `@fresh` spec that fails before the fix and passes after (`.github/workflows/qa-suite.yml`).
