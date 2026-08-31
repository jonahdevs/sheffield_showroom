---
paths:
  - 'resources/js/**'
---

# Js

## Run wayfinder:generate with --with-form, or you break 16 files
`vite.config.ts` configures the Wayfinder plugin with `formVariants: true`, so the generated route helpers carry `.form` alongside `.url`/`.post`. Sixteen files use it — every auth page, the settings pages, `DeleteUser`, `ManageTwoFactor`, `TwoFactorRecoveryCodes`, `TwoFactorSetupModal`.

Running `php artisan wayfinder:generate` by hand regenerates without those variants and silently breaks all of them: `Property 'form' does not exist on type ...`, 16 errors from `vue-tsc`, nothing at runtime until the page is opened. Always pass `--with-form`.

`resources/js/routes` and `resources/js/actions` are gitignored, so `git status` shows nothing and the damage is invisible until you typecheck. Normally you do not need to run this at all — `npm run dev` and `npm run build` regenerate correctly through the vite plugin.
