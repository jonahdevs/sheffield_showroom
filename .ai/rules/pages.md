---
paths:
  - 'resources/js/pages/**'
---

# Pages

## Page content measures @.../page:, not viewport breakpoints
`PageSurface` declares `@container/page`. Behind the sidebar rail the viewport is wider than the box a page actually gets, so `md:`/`lg:` promise room the content does not have and columns overflow when the rail is expanded.

Page descendants must use container queries (`@2xl/page:`, `@4xl/page:` etc.) for their own layout. `PageSurface` itself keeps viewport breakpoints for its gutter, because an element cannot query the container it declares.</note>
</invoke>

## Redirect-after-POST discards animation state unless preserveState is set
`resources/js/pages/rewards/Shuffle.vue` posts to `rewards/shuffle.store` and the server redirects back to the same page. Without `preserveState: true`, Inertia rebuilds the component on that redirect and takes `ShuffleCards`' dealt cards, phase and in-flight tween with it — the reward reveals with no shuffle animation in front of it.

Any page that animates across a redirect-to-self needs the same flag.</note>
</invoke>
