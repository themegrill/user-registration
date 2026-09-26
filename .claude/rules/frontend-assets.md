---
paths:
  - "assets/**"
  - "src/**"
  - "Gruntfile.js"
  - "webpack.config.js"
---

# Front-end code: two pipelines

- **`src/`** (React/TS): built by `wp-scripts` (webpack) into gitignored `build/` and `chunks/`. Nothing generated is committed. `pnpm dev` watches; `pnpm build:no-makepot` builds.
- **`assets/`** (legacy jQuery JS and SCSS): built by grunt. The outputs are **committed** next to the source: `*.min.js`, compiled `*.css`, `*-rtl.css`, `*.css.map`. After editing `assets/**/*.js` run `pnpm exec grunt js`; after editing `*.scss` run `pnpm exec grunt css`; commit the regenerated files with the source change.
- Some `assets/css/*.css` have no `.scss` (for example `ur-toast.css`, vendor styles). Those are hand-written and edited directly. Check for a sibling `.scss` first; the edit guard hook applies the same test.
- Prettier covers `src/**/*.{ts,tsx,js,jsx}` and `**/*.scss` only (`pnpm prettier`; config in `.prettierrc`). `tsconfig.json` is non-strict; run `pnpm typecheck` after TS changes.
- User-visible strings in JS go through `@wordpress/i18n` with the text domain `user-registration`.
- Script and style registration lives in `includes/class-ur-frontend-scripts.php` and `includes/admin/class-ur-admin-assets.php`; register there rather than printing tags from templates.
