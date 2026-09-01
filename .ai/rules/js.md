---
paths:
  - 'resources/js/**'
---

# Js

## Run wayfinder:generate with --with-form, or you break 16 files
`vite.config.ts` configures the Wayfinder plugin with `formVariants: true`, so the generated route helpers carry `.form` alongside `.url`/`.post`. Sixteen files use it — every auth page, the settings pages, `DeleteUser`, `ManageTwoFactor`, `TwoFactorRecoveryCodes`, `TwoFactorSetupModal`.

Running `php artisan wayfinder:generate` by hand regenerates without those variants and silently breaks all of them: `Property 'form' does not exist on type ...`, 16 errors from `vue-tsc`, nothing at runtime until the page is opened. Always pass `--with-form`.

`resources/js/routes` and `resources/js/actions` are gitignored, so `git status` shows nothing and the damage is invisible until you typecheck. Normally you do not need to run this at all — `npm run dev` and `npm run build` regenerate correctly through the vite plugin.

## Parse Laravel datetime strings with the space replaced by T
Laravel datetime props arrive as `Y-m-d H:i:s`. Passing that straight to `new Date()` is not a format every engine parses, and engines that guess treat it as UTC — which slides an evening value onto the following day.

Always `new Date(value.replace(' ', 'T'))`. Used in `pages/admin/rewards/Index.vue` (`readableDay`, `dormantReason`).</note>
</invoke>

## shadcn Empty ships flex-1 and a borderless border-dashed
`components/ui/empty` carries `flex-1` and `border-dashed` with no border *width*.

Inside a `Card` (a flex column with no height of its own) `flex-1` resolves to zero and the panel collapses — pass `class="flex-none"`. If you want the dashed outline to show you must add `border` by hand. Seen in `pages/admin/rewards/Overview.vue`, `Form.vue` and `catalogue/Index.vue`.</note>
</invoke>

## InfiniteScroll items must be its own direct children
Inertia's `InfiniteScroll` tags each direct child with the page it arrived on. Put the grid/list layout classes on the component itself — wrapping the items in a `div` inside it leaves the component holding a single child forever and paging silently stops.

Its footer belongs in `#next`, not `#loading`, which only renders mid-request. Seen in `pages/admin/products/Index.vue`.</note>
</invoke>

## reka-ui Combobox: set ignore-filter and narrow the list yourself
`Combobox`'s built-in filter keeps every item mounted and merely hides the misses, so a catalogue of a thousand builds a thousand rows every time the box opens.

All three comboboxes (`OptionCombobox`, `OptionMultiCombobox`, `OptionOrNewCombobox`) set `ignore-filter` on the root and narrow via `matchOptions`/`countMatches` from `@/lib/options`, rendering a screenful and reporting the remainder. Do the same in any new combobox over a long list.</note>
</invoke>

## ApexCharts needs hex, never hsl()/oklch()/var()
Apex derives tints, shades and gradient stops arithmetically from hex digits, so an `hsl(...)` token has its letters read as hex — the fill goes teal while the SVG-rendered stroke looks right.

`useChartTheme` converts every design token through a 1x1 canvas (`toHex`), reading the pixel back rather than `fillStyle`, which returns wide-gamut values unconverted. Never hand a chart a raw CSS custom property.</note>
</invoke>

## GSAP animations must tolerate a hidden page
A background tab throttles `requestAnimationFrame` — which GSAP runs on — to roughly one frame per second. Any awaited GSAP tween in a sequence can hang for minutes and strand the UI in its intermediate state (e.g. stuck on "Shuffling").

Treat `document.hidden` as a reduced-motion signal alongside `prefers-reduced-motion` and skip to the end state. Also `gsap.killTweensOf(...)` in `onBeforeUnmount`. See `components/ShuffleCards.vue`.</note>
</invoke>

## A faded element flattens its own 3D transform context
Any element at less than full opacity creates a new stacking/3D context, so descendants stop honouring `backface-visibility` and card backs show the mirrored face.

Keep opacity/scale/position on an outer element and the `rotateY` on a separate inner one with `[transform-style:preserve-3d]`. See `components/ShuffleCards.vue`.</note>
</invoke>
