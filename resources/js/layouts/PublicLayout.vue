<script setup lang="ts">
/**
 * The wordmark, from `public` rather than through Vite.
 *
 * The same place the favicons and the card artwork sit - this application
 * keeps its static images there, and a design asset that changes once a decade
 * gains nothing from being fingerprinted. Downscaled from the 1917px original:
 * the bar draws it at 128px, so even a three-times display has more pixels
 * than it can use.
 */
const LOGO = '/images/sheffield-logo.png';
</script>

<!--
  The shell for the one page in the application that nobody signs in to reach.

  It exists because `app.ts` resolves anything that is not `auth/` or
  `settings/` to `AppLayout`, and `AppLayout` is a sidebar, a topbar and an
  account block - three things that need a logged-in user and none of which
  mean anything to a customer holding a phone at a counter. A page can only
  refuse that default by naming a layout of its own, and `layout: null` is not
  a refusal: Inertia falls through the nullish coalesce straight back to the
  default. So there has to be something here to name.

  The bar is white in both themes rather than `bg-background`. The wordmark is
  red over a navy "Since 2003", and on the near-black page a dark-mode customer
  would get, the navy half of it disappears - which is the same reason the
  sidebar and auth screens chip their mark onto white. A promotional page is
  also not the place to be clever about somebody's system theme.

  No navigation in it, and no link on the logo. There is nowhere for a customer
  to go: every other address in this application is behind a sign-in they do
  not have, and a mark that looked clickable would send them to a login screen.

  The `container` matches the bar above it, and the page inside decides its own
  columns - the shuffle screen runs three across on a monitor and one on a
  phone, and a layout that fixed a narrow column here would have to be argued
  with to allow that.

  `my-auto` rather than `justify-center`: a won reward with its terms under it
  can run taller than a phone, and a centred flex child that overflows its
  container is clipped at the top with no way to scroll back up to it.

  The footer's padding reads `env(safe-area-inset-bottom)` because this is the
  only screen in the application that is genuinely never on a desktop - on a
  notched phone the home indicator would otherwise sit on top of it.
-->
<template>
    <div class="flex min-h-svh flex-col bg-background">
        <!--
          The bar runs edge to edge; what is in it does not.

          The white and the rule underneath it have to reach both sides or the
          page reads as a floating card rather than as a screen with a header.
          The mark inside sits in a `container`, which Tailwind sizes to the
          current breakpoint - so it stops at a sensible width on a monitor
          instead of the logo hanging in the far corner of a 1920px window.

          `mx-auto` because Tailwind's `container` sets a width and nothing
          else; centring it is not part of the utility.
        -->
        <header class="sticky top-0 z-10 border-b border-black/10 bg-white">
            <div class="container mx-auto px-5 py-3">
                <img
                    :src="LOGO"
                    alt="Sheffield Africa"
                    class="h-8 w-auto"
                    width="480"
                    height="161"
                />
            </div>
        </header>

        <main class="flex flex-1 flex-col">
            <div class="container mx-auto my-auto w-full px-5 py-8">
                <slot />
            </div>
        </main>

        <footer
            class="px-5 pt-4 text-center text-xs text-muted-foreground"
            style="padding-bottom: calc(1rem + env(safe-area-inset-bottom))"
        >
            Sheffield Africa
        </footer>
    </div>
</template>
