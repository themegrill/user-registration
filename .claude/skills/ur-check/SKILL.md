---
name: ur-check
description: Work out and run the checks that apply to the current change (phpcs, prettier, typecheck, grunt, e2e area, review triggers) before reporting a task done. Use when finishing a change or before opening a PR.
disable-model-invocation: true
allowed-tools: Bash(node .claude/skills/ur-check/plan-checks.mjs*) Bash(git diff*) Bash(git status*) Read Grep
---

# Verify a change

1. Get the plan for what actually changed:

   ```
   node .claude/skills/ur-check/plan-checks.mjs
   ```

   The base defaults to `origin/develop`; pass another ref as the first argument. It covers commits, uncommitted edits and untracked files, and maps files to `area_paths` in `.themegrill-qa/suite.json`. It plans only and runs nothing.
2. Run each listed command that is safe locally (`phpcs`, `pnpm prettier`, `pnpm typecheck`, `pnpm exec grunt js|css`). Do not run `pnpm test:e2e` without asking: it needs a live site and changes plugin settings.
3. Read the output. Fix what your change introduced. Pre-existing violations in untouched lines are not yours; say so instead of fixing them in this diff.
4. Act on the notes: run the `ur-reviewer` agent for security-sensitive paths, and confirm any flagged removal of a hook, route, shortcode or AJAX action is intentional and shimmed (see the `ur-architecture` skill, `reference/extension-points.md`).
5. If the change fixes a bug, confirm a `@fresh` spec exists or say why not.

## Report in four parts
- **Changed**: files and behaviour.
- **Verified**: each command you actually ran with its real result.
- **Not verified**: everything you could not run, and why. There is no PHP unit suite here, e2e needs a live site, and Pro-side consumers are not in this checkout.
- **Remaining issues**: anything left open or deliberately out of scope.

Never write "should work" for something that was not run.
